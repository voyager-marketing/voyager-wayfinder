import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { afterAll, describe, expect, it } from 'vitest';
import { parseRows, REDACTED_EMAIL, isSecretName, sanitizeDump, serialize, unserialize } from '../src/sanitize.ts';
import { BENCH_DIR, loadConfig, wpEnvRoot } from '../src/run.ts';
import { ensureSnapshot, filesNaming, insidePath, sha256File, verifySnapshot, type Pin } from '../src/snapshot.ts';

const dump = readFileSync(join(import.meta.dirname, 'snapshot-synthetic.sql'), 'latin1');
const { sql, report } = sanitizeDump(dump, { prefix: 'syn_', publicOptions: ['voyager_site_email'] });

/** Every row the sanitized dump inserts into `table`, as unescaped strings by column. */
function rowsOf(table: string): Record<string, string | null>[] {
  const create = new RegExp(`CREATE TABLE \`${table}\` \\(([\\s\\S]*?)\\n\\) `).exec(sql);
  const cols = [...(create?.[1] ?? '').matchAll(/^\s+`([^`]+)` /gm)].map((m) => m[1]);
  const out: Record<string, string | null>[] = [];
  for (const line of sql.split('\n')) {
    const prefix = `INSERT INTO \`${table}\` VALUES `;
    if (!line.startsWith(prefix)) continue;
    for (const row of parseRows(line, prefix.length)) out.push(Object.fromEntries(cols.map((c, i) => [c, row[i].value ?? row[i].raw])));
  }
  return out;
}
const option = (name: string) => rowsOf('wp_options').find((r) => r.option_name === name)?.option_value;
const usermeta = (user: string, key: string) => rowsOf('wp_usermeta').find((r) => r.user_id === user && r.meta_key === key)?.meta_value;

describe('the snapshot sanitizer, on a synthetic dump', () => {
  it('removes every email, password hash, application password, session and secret the dump holds', () => {
    for (const secret of [
      'owner@synthetic-agency.test', 'sam.editor@synthetic-agency.test', 'visitor@synthetic-mail.test', 'lead.owner@synthetic-mail.test',
      '$wp$2y$10$SyntheticHashOne', '$P$BSyntheticHashTwo', 'SyntheticResetKey', 'SyntheticAppPasswordHash',
      '0123456789abcdef0123456789abcdef', 'sk-ant-api03-', '00000000-aaaa-bbbb-cccc-dddddddddddd', 'ya29.Synthetic',
      'secret_SyntheticNotionToken', 'whsec_Synthetic', '203.0.113', '198.51.100', 'staff@synthetic-agency.test', 'SyntheticSignature',
    ]) {
      expect(sql, secret).not.toContain(secret);
    }
  });

  it('was given a dump that holds them: the same checks fail on the input', () => {
    for (const secret of ['owner@synthetic-agency.test', '$wp$2y$10$SyntheticHashOne', 'SyntheticAppPasswordHash', 'sk-ant-api03-', '203.0.113', 'whsec_Synthetic']) {
      expect(dump, secret).toContain(secret);
    }
  });

  it('blanks password hashes and activation keys and gives each user a placeholder email', () => {
    expect(rowsOf('wp_users').map((u) => [u.ID, u.user_pass, u.user_activation_key, u.user_email])).toEqual([
      ['1', '', '', 'user-1@example.invalid'],
      ['3', '', '', 'user-3@example.invalid'],
    ]);
    // Logins and display names are content an editor sees, and stay.
    expect(rowsOf('wp_users')[1].display_name).toBe("Sam O'Neil");
  });

  it('drops sessions, application passwords and the community events location', () => {
    expect(usermeta('1', 'session_tokens')).toBeUndefined();
    expect(usermeta('1', '_application_passwords')).toBeUndefined();
    expect(usermeta('1', 'community-events-location')).toBeUndefined();
    expect(usermeta('1', '_voyager_portal_clerk_email')).toBe(REDACTED_EMAIL);
    expect(report.dropped.usermeta).toEqual({ '_application_passwords': 1, 'community-events-location': 1, session_tokens: 1 });
  });

  it('keeps roles and capabilities, renamed to the wp_ prefix', () => {
    const roles = option('wp_user_roles') as string;
    expect(roles).toBe(/'syn_user_roles','([^']+)'/.exec(dump)?.[1].replaceAll('\\"', '"'));
    expect(serialize(unserialize(roles)!)).toBe(roles);
    expect(roles).toContain('s:18:"edit_theme_options";b:1;');
    expect(usermeta('1', 'wp_capabilities')).toBe('a:1:{s:13:"administrator";b:1;}');
    expect(usermeta('3', 'wp_capabilities')).toBe('a:1:{s:6:"editor";b:1;}');
    expect(usermeta('3', 'wp_user_level')).toBe('7');
    expect(sql).not.toMatch(/[`']syn_/);
    expect(report.renamed).toContain('wp_options syn_user_roles -> wp_user_roles');
  });

  it('blanks key-like options, and key-like entries inside structured ones, keeping their shape', () => {
    expect(option('connectors_ai_anthropic_api_key')).toBe('');
    expect(option('voyager_notion_api_token')).toBe('');
    expect(JSON.parse(option('postmark_settings') as string)).toEqual({ api_key: '', sender_address: REDACTED_EMAIL, force_html: 1 });
    // A key-like name blanks every string in the value, keeping its shape so plugins still read an array.
    expect(option('wp_ai_client_provider_credentials')).toBe('a:1:{s:9:"anthropic";a:2:{s:7:"api_key";s:0:"";s:5:"label";s:0:"";}}');
    const analytics = unserialize(option('rank_math_analytics_all_services') as string);
    expect(analytics && serialize(analytics)).toBe(`a:2:{s:7:"account";s:${REDACTED_EMAIL.length}:"${REDACTED_EMAIL}";s:12:"access_token";s:0:"";}`);
    expect(report.blanked).toContain('wp_options connectors_ai_anthropic_api_key');
    expect(report.redacted).toContain('wp_options postmark_settings: api_key');
    expect(report.blanked).toContain('wp_options wp_ai_client_provider_credentials');
    expect(report.redacted).toContain('wp_options rank_math_analytics_all_services: access_token');
  });

  it('drops transients and Wayfinder state, and empties logs, but keeps the site\'s public details', () => {
    expect(option('_transient_feed_abc')).toBeUndefined();
    expect(option('_site_transient_update_core')).toBeUndefined();
    expect(report.dropped.transients).toBe(2);
    expect(option('vwf_mcp_enabled')).toBeUndefined();
    expect(report.dropped.options).toEqual(['vwf_mcp_enabled']);
    expect(option('limit_login_logged')).toBe('a:0:{}');
    expect(option('admin_email')).toBe(REDACTED_EMAIL);
    expect(option('voyager_site_email')).toBe('hello@synthetic-agency.test');
    expect(option('voyager_site_phone')).toBe('(555) 010-0199');
    expect(option('theme_mods_voyagermark')).toBe('a:2:{s:18:"nav_menu_locations";a:0:{}s:18:"custom_css_post_id";i:-1;}');
    expect(option('blogdescription')).toBe("It's a test, with a \\ backslash and a\nnewline");
  });

  it('leaves hook names, URL patterns and on or off flags alone, though their names look key-like', () => {
    for (const name of ['cron', 'rewrite_rules', 'rank-math-options-titles']) {
      expect(option(name), name).toBe(/'NAME','([^']+)'/.exec(dump.replace(`'${name}'`, "'NAME'"))?.[1].replaceAll('\\"', '"'));
    }
    expect(option('rewrite_rules')).toContain('index.php?oauth_endpoint=token');
    expect(option('rank-math-options-titles')).toContain('s:26:"noindex_password_protected";s:2:"on";');
  });

  it('removes form entries, logs, activity logs and job queues, and drops trash, auto-drafts and log post types with their meta and revisions', () => {
    expect(report.emptied).toEqual(['wp_actionscheduler_logs', 'wp_gf_entry', 'wp_voyager_activities', 'wp_voyager_jobs']);
    expect(report.rows.wp_gf_entry).toEqual({ before: 1, after: 0 });
    expect(sql).toContain('CREATE TABLE `wp_gf_entry`');
    expect(rowsOf('wp_posts').map((p) => p.ID)).toEqual(['10']);
    expect(report.dropped.posts).toEqual({ 'status auto-draft': 1, 'status draft': 2, 'status pending': 1, 'status trash': 1, 'type llar_digest_day': 1, revision: 3 });
    expect(rowsOf('wp_postmeta').map((m) => m.meta_key)).toEqual(['service_price', 'team_email', '_webhook_secret']);
    expect(report.dropped.postmeta).toBe(5);
  });

  it('drops unpublished posts, so a draft naming a client never reaches a published transcript', () => {
    expect(dump).toContain('Unpublished case study for a named client');
    expect(sql).not.toContain('Unpublished case study for a named client');
    expect(sql).not.toContain('Draft about pricing');
    expect(sql).not.toContain('Awaiting review');
    // An earlier version of a published post is not published either.
    expect(dump).toContain("(32,1,'Old','Services'");
    expect(sql).not.toContain("(32,1,'Old','Services'");
    expect(sql).not.toContain('client_name_meta');
    // Orbit's census of theme patterns names the draft's pattern; it is a cache, emptied.
    expect(dump).toContain('case-a-named-client');
    expect(option('voyager_block_census_theme')).toBe('{}');
    expect(sql).not.toContain('A named client');
  });

  it('keeps content as it is, except a secret-shaped string', () => {
    expect(rowsOf('wp_postmeta').find((m) => m.meta_key === 'team_email')?.meta_value).toBe('press@synthetic-agency.test');
    expect(rowsOf('wp_postmeta').find((m) => m.meta_key === '_webhook_secret')?.meta_value).toBe('');
    expect(rowsOf('wp_postmeta').find((m) => m.meta_key === 'service_price')?.meta_value).toBe('$1,500/mo');
    expect(rowsOf('wp_posts')[0].post_content).toContain('hello@synthetic-agency.test');
  });

  it('blanks comment author emails, IPs and user agents', () => {
    expect(rowsOf('wp_comments')).toEqual([expect.objectContaining({ comment_author: 'A visitor', comment_author_email: '', comment_author_IP: '', comment_agent: '' })]);
  });

  it('makes the dump importable into MariaDB: no GTID lines and no MySQL 8 collation', () => {
    expect(sql).not.toMatch(/GTID_PURGED|SQL_LOG_BIN|utf8mb4_0900/);
    expect(report.collations).toEqual({ utf8mb4_0900_ai_ci: 1 });
  });

  it('re-emits untouched rows byte for byte', () => {
    expect(sql).toContain("(1,'siteurl','https://staging.synthetic.test','on')");
    expect(sql).toContain("(1,1,'nickname','Voyager')");
    expect(sql).toContain("'It\\'s a test, with a \\\\ backslash and a\\nnewline'");
  });

  it('records what it removed by name, never by value', () => {
    const text = JSON.stringify(report);
    for (const value of ['owner@synthetic', 'sk-ant', 'Synthetic', '203.0.113']) expect(text, value).not.toContain(value);
    expect(report.blanked).toContain('wp_users.user_pass (2 rows)');
    expect(report.blanked).toContain('wp_comments.comment_author_email');
  });
});

describe('secret names', () => {
  it('flags keys, tokens, secrets and passwords, and leaves ordinary names', () => {
    for (const name of ['connectors_ai_anthropic_api_key', 'mcp_jwt_secret', 'rank_math_google_oauth_tokens', 'recovery_keys', 'voyagermark_indexnow_key', 'acf_pro_license', 'mailserver_pass', 'voyager_leads_user_hash_salt', 'clientSecret', 'session_tokens']) {
      expect(isSecretName(name), name).toBe(true);
    }
    for (const name of ['siteurl', 'blogname', 'key', 'keyword', 'rank_math_focus_keyword', 'voyager_site_phone', 'theme_mods_voyagermark', 'bypass_cache', 'passage']) {
      expect(isSecretName(name), name).toBe(false);
    }
  });
});

describe('PHP serialization', () => {
  it('round-trips nested arrays, objects, custom classes and multi-byte strings byte for byte', () => {
    const latin1 = (s: string) => Buffer.from(s, 'utf8').toString('latin1');
    for (const s of ['a:0:{}', 'N;', 'b:1;', 'i:-3;', 'd:0.5;', latin1('s:2:"é";'), 'a:1:{i:0;O:8:"stdClass":1:{s:1:"a";a:1:{s:1:"b";N;}}}', 'C:3:"Foo":4:{abcd}']) {
      expect(serialize(unserialize(s)!), s).toBe(s);
    }
    expect(unserialize('s:5:"abc";')).toBeUndefined();
    expect(unserialize('not serialized')).toBeUndefined();
  });
});

describe('a pinned snapshot', () => {
  const dir = mkdtempSync(join(tmpdir(), 'vwf-snapshot-'));
  afterAll(() => rmSync(dir, { recursive: true, force: true }));

  // A pin in the shape a snapshot fixture's snapshot.json has, for a tarball that is not the one on disk.
  const pin: Pin = {
    repo: 'example/private-bench-data', tag: 'snapshot-test', asset: 'snapshot.tar.gz', sha256: 'a'.repeat(64), bytes: 1,
    source: 'https://staging.example.com', exported: '2026-10-05', wordpress: '7.1', php: '8.3', theme: { stylesheet: 'site', template: 'parent' },
    plugins: [], mu_plugins: [], left_out: {},
  };

  it('refuses a snapshot whose hash differs from the pin, and accepts one that matches', () => {
    const file = join(dir, 'snapshot.tar.gz');
    writeFileSync(file, 'not the pinned snapshot');
    expect(() => verifySnapshot(file, pin.sha256)).toThrow(/has sha256 [0-9a-f]{64}, but the fixture's snapshot\.json pins a{64}; refusing to start it/);
    expect(() => verifySnapshot(file, sha256File(file))).not.toThrow();
  });

  it('checks the hash before it extracts anything, and never extracts a tarball that fails', async () => {
    writeFileSync(join(dir, pin.asset), 'not the pinned snapshot');
    await expect(ensureSnapshot(pin, dir, async () => { throw new Error('no download expected: the tarball is on disk'); })).rejects.toThrow(/refusing to start it/);
    expect(existsSync(join(dir, 'snapshot'))).toBe(false);
  });

  it('downloads the pinned asset when no tarball is on disk, and still refuses it when the hash differs', async () => {
    const empty = mkdtempSync(join(tmpdir(), 'vwf-snapshot-dl-'));
    const asked: string[] = [];
    await expect(ensureSnapshot(pin, empty, async (p, dest) => { asked.push(`${p.repo}@${p.tag}/${p.asset}`); writeFileSync(dest, 'a different tarball'); })).rejects.toThrow(/refusing to start it/);
    expect(asked).toEqual(['example/private-bench-data@snapshot-test/snapshot.tar.gz']);
    expect(existsSync(join(empty, 'snapshot'))).toBe(false);
    rmSync(empty, { recursive: true, force: true });
  });
});

describe('files naming an unpublished case study', () => {
  it('finds every text file that mentions one of its slugs, and nothing else', () => {
    const dir = mkdtempSync(join(tmpdir(), 'vwf-snapshot-case-'));
    mkdirSync(join(dir, 'themes', 'site', 'patterns'), { recursive: true });
    mkdirSync(join(dir, 'themes', 'site', 'assets'), { recursive: true });
    writeFileSync(join(dir, 'themes', 'site', 'patterns', 'case-a-named-client.php'), "<?php // Slug: site/case-a-named-client\n");
    writeFileSync(join(dir, 'themes', 'site', 'assets', 'custom.css'), '.vy-case--a-named-client { color: red; }\n');
    writeFileSync(join(dir, 'themes', 'site', 'assets', 'NOTES.md'), 'Photos for a-named-client are still to come.\n');
    writeFileSync(join(dir, 'themes', 'site', 'patterns', 'case-published.php'), "<?php // Slug: site/case-published\n");
    expect(filesNaming(dir, ['a-named-client'])).toEqual(['themes/site/assets/NOTES.md', 'themes/site/assets/custom.css', 'themes/site/patterns/case-a-named-client.php']);
    expect(filesNaming(dir, [])).toEqual([]);
    rmSync(dir, { recursive: true, force: true });
  });
});

describe('a snapshot fixture from bench/private/config.json', () => {
  // A made-up private config, in a folder of its own, so the test reads the same with or without bench/private/.
  const dir = mkdtempSync(join(tmpdir(), 'vwf-private-'));
  afterAll(() => rmSync(dir, { recursive: true, force: true }));
  writeFileSync(join(dir, 'config.json'), JSON.stringify({
    fixtures: {
      site: {
        site: 'real-site', truth: 'private/fixtures/site/truth.json', questions: 'private/questions/real-site.yaml',
        snapshot: 'private/fixtures/site/snapshot.json', credentials: 'private/fixtures/site/.credentials',
        question_sets: { 'real-site-2': { site: 'real-site-2', questions: 'private/questions/real-site-2.yaml' } },
      },
    },
  }));

  it('starts from its pinned snapshot instead of a seed, with its own answer key, credentials and reports folder', () => {
    const { fixture } = loadConfig('site', undefined, dir);
    expect(fixture).toMatchObject({ name: 'site', site: 'real-site', snapshot: 'private/fixtures/site/snapshot.json', credentials: 'private/fixtures/site/.credentials', reports: 'private/reports' });
    expect(fixture.seed).toBeUndefined();
    // The published fixtures are still there, and still write to reports/.
    expect(loadConfig('block', undefined, dir).fixture.reports).toBeUndefined();
  });

  it('runs the cli conditions in the snapshot site\'s own wp-env, not the default fixture site\'s', () => {
    const root = wpEnvRoot(loadConfig('site', undefined, dir).fixture);
    expect(root).toBe(resolve(BENCH_DIR, 'private', 'fixtures', 'site'));
    expect(insidePath(root)).toBe('wp-content/plugins/voyager-wayfinder/bench/private/fixtures/site');
    expect(wpEnvRoot(loadConfig('block', undefined, dir).fixture)).toBe(join(BENCH_DIR, '..'));
  });

  it('finds a private set on its fixture when no fixture is named', () => {
    expect(loadConfig(undefined, 'real-site-2', dir).fixture).toMatchObject({ name: 'site', question_set: 'real-site-2', questions: 'private/questions/real-site-2.yaml' });
    expect(() => loadConfig('block', 'real-site-2', dir)).toThrow(/block fixture has no question set "real-site-2"/);
    expect(() => loadConfig(undefined, 'nope', dir)).toThrow(/no question set "nope"/);
  });

  it('is not there without the private config, and may not reuse a published fixture\'s name', () => {
    const none = mkdtempSync(join(tmpdir(), 'vwf-private-none-'));
    expect(() => loadConfig('site', undefined, none)).toThrow(/no fixture "site"/);
    const clash = mkdtempSync(join(tmpdir(), 'vwf-private-clash-'));
    writeFileSync(join(clash, 'config.json'), JSON.stringify({ fixtures: { block: { site: 'x', truth: 't', questions: 'q', seed: 's' } } }));
    expect(() => loadConfig('block', undefined, clash)).toThrow(/fixture "block" is already in config.json/);
    rmSync(none, { recursive: true, force: true });
    rmSync(clash, { recursive: true, force: true });
  });
});
