import { spawnSync, type SpawnSyncOptions } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, posix, relative, resolve } from 'node:path';
import { z } from 'zod';
import { SECRET_VALUES, sanitizeDump } from './sanitize.ts';
import type { Spawn } from './seed.ts';

/**
 * A snapshot fixture: a sanitized copy of a real site, run in wp-env. Its pin, answer key and questions are
 * private bench data (src/private.ts), so none of them is in this repository.
 *
 * `buildSnapshot` exports the site read-only over SSH (`wp db export` and `tar` to stdout, nothing written on the
 * server), sanitizes the dump (src/sanitize.ts) and packs it with the active theme, its parent and the plugins.
 * The tarball is published by hand as a release asset on a private repository, never in this one, and the
 * fixture's `snapshot.json` pins its sha256. `startSnapshot` downloads it, refuses it unless the hash matches,
 * starts it from the `.wp-env.json` beside the pin and imports it. No seed runs: the snapshot is the site.
 */

/** The repository root, which wp-env maps into the snapshot's site as the voyager-wayfinder plugin. */
const REPO_ROOT = resolve(import.meta.dirname, '..', '..');

const str = z.string();
const Pin = z.object({
  repo: str,
  tag: str,
  asset: str,
  sha256: str.regex(/^[0-9a-f]{64}$/),
  bytes: z.number(),
  source: str,
  exported: str,
  wordpress: str,
  php: str,
  theme: z.object({ stylesheet: str, template: str }),
  plugins: z.array(str),
  mu_plugins: z.array(str),
  left_out: z.record(str, str),
});
export type Pin = z.infer<typeof Pin>;

export const readPin = (file: string): Pin => Pin.parse(JSON.parse(readFileSync(file, 'utf8')));

export const sha256File = (file: string) => createHash('sha256').update(readFileSync(file)).digest('hex');

/** Throws unless `file` hashes to `expected`, so a snapshot that is not the pinned one never starts. */
export function verifySnapshot(file: string, expected: string): void {
  const actual = sha256File(file);
  if (actual !== expected) {
    throw new Error(`${file} has sha256 ${actual}, but the fixture's snapshot.json pins ${expected}; refusing to start it. Delete the file to download the pinned one again.`);
  }
}

/** A token that can read the private bench data repo: the GitHub Actions secret in CI, gh's own login locally. */
function readToken(): string {
  const env = process.env.WAYFINDER_BENCH_DATA_TOKEN || process.env.GH_TOKEN || process.env.GITHUB_TOKEN;
  if (env) return env;
  const gh = spawnSync('gh', ['auth', 'token'], { encoding: 'utf8', shell: process.platform === 'win32' });
  if (gh.status === 0 && gh.stdout.trim()) return gh.stdout.trim();
  throw new Error('no token to download the snapshot: set WAYFINDER_BENCH_DATA_TOKEN, or sign in with gh auth login');
}

/** Downloads the pinned release asset from the private repo through the GitHub API. */
export async function downloadSnapshot(pin: Pin, dest: string, token = readToken()): Promise<void> {
  const headers = { Authorization: `Bearer ${token}`, 'X-GitHub-Api-Version': '2022-11-28' };
  const release = await fetch(`https://api.github.com/repos/${pin.repo}/releases/tags/${pin.tag}`, { headers: { ...headers, Accept: 'application/vnd.github+json' } });
  if (!release.ok) throw new Error(`release ${pin.tag} on ${pin.repo}: HTTP ${release.status}. The token needs Contents read on that repo.`);
  const { assets } = (await release.json()) as { assets: { name: string; url: string }[] };
  const asset = assets.find((a) => a.name === pin.asset);
  if (!asset) throw new Error(`release ${pin.tag} on ${pin.repo} has no asset ${pin.asset}`);
  // fetch drops the Authorization header on the redirect to the storage host, which is what the storage host wants.
  const body = await fetch(asset.url, { headers: { ...headers, Accept: 'application/octet-stream' } });
  if (!body.ok) throw new Error(`asset ${pin.asset}: HTTP ${body.status}`);
  writeFileSync(dest, Buffer.from(await body.arrayBuffer()));
}

function run(command: string, args: string[], options: SpawnSyncOptions, spawn: Spawn = spawnSync): void {
  const result = spawn(command, args, { stdio: 'inherit', shell: process.platform === 'win32', ...options });
  if (result.error) throw new Error(`could not run ${command} ${args.join(' ')}: ${result.error.message}`);
  if (result.status !== 0) throw new Error(`${command} ${args.join(' ')} exited ${result.status}`);
}

/**
 * Makes `snapshot/` beside the pin hold the pinned snapshot. A tarball already on disk is used when it matches the
 * pin, and refused when it does not; otherwise the pinned asset is downloaded and checked before anything is extracted.
 */
export async function ensureSnapshot(pin: Pin, dir: string, download = downloadSnapshot): Promise<string> {
  const target = join(dir, 'snapshot');
  const marker = join(target, '.sha256');
  if (existsSync(marker) && readFileSync(marker, 'utf8').trim() === pin.sha256) return target;
  const tarball = join(dir, pin.asset);
  if (!existsSync(tarball)) {
    console.log(`Downloading ${pin.asset} from ${pin.repo} (${pin.tag})`);
    await download(pin, tarball);
  }
  verifySnapshot(tarball, pin.sha256);
  rmSync(target, { recursive: true, force: true });
  mkdirSync(target, { recursive: true });
  // Relative paths and a cwd, because GNU tar reads a drive letter's colon as a remote host.
  run('tar', ['-xzf', relative(target, tarball).replaceAll('\\', '/'), '-C', '.'], { cwd: target });
  writeFileSync(marker, `${pin.sha256}\n`);
  return target;
}

/** The pin's folder as the wp-env containers see it, where this checkout is the voyager-wayfinder plugin. */
export const insidePath = (dir: string): string => posix.join('wp-content/plugins/voyager-wayfinder', relative(REPO_ROOT, dir).replaceAll('\\', '/'));

/**
 * Starts the pinned snapshot in its own wp-env site and imports it. wp-env reads the port from WP_ENV_PORT
 * (8888 when unset), so the site URL follows it. The `load.php` beside the pin then points the snapshot's own URLs
 * at the local site, writes `.credentials` beside it and checks the site is the one the answer key describes.
 */
export async function startSnapshot(pinFile: string, spawn?: Spawn): Promise<{ siteUrl: string }> {
  const pin = readPin(pinFile);
  const dir = dirname(pinFile);
  await ensureSnapshot(pin, dir);
  const env = (...args: string[]) => run('npx', ['@wordpress/env', ...args], { cwd: dir }, spawn);
  env('start');
  const inside = insidePath(dir);
  env('run', 'cli', 'wp', 'db', 'import', `${inside}/snapshot/db.sql`);
  env('run', 'cli', 'wp', 'eval-file', `${inside}/load.php`, pin.source);
  return { siteUrl: `http://localhost:${process.env.WP_ENV_PORT || 8888}` };
}

// ---------------------------------------------------------------------------------------------------------------
// Building a snapshot: a local session with SSH access to the site. Never writes to it.

export interface BuildOptions {
  /** `user@host` for the site's user. */
  ssh: string;
  /** Private key for that user. */
  key: string;
  /** The WordPress root on the server. */
  path: string;
  /** Where the tarball goes. The raw dump is deleted once sanitized. */
  out: string;
  /** `owner/name` of the private repository the tarball is published on, which the pin names. */
  repo: string;
  date?: string;
}

/** Plugins and mu-plugins left out of the snapshot, and why. The fixture maps its own Wayfinder and MCP Adapter. */
export const LEFT_OUT: Record<string, string> = {
  'plugins/voyager-wayfinder': 'mapped from this checkout, so the bench tests the plugin under review',
  'plugins/mcp-adapter': 'mapped at the version .wp-env.json pins (0.7.0, the same as the site ran)',
  'mu-plugins/voyager-monitoring.php': 'reports PHP errors to Sentry; a local copy would report the bench site as production',
  'mu-plugins/spinupwp-debug-log-path.php': 'points the debug log at a path on the SpinupWP server',
  'wp-content/uploads': 'media files: 484 MB, and no edit location lives in them',
  'wp-content/object-cache.php': 'the server\'s Redis object cache drop-in',
  'plugins/*/tests': 'plugin test suites: never loaded by the site, and voyager-orbit\'s hold fake keys that the leak scan rightly stops on',
};

/** Options the sanitizer leaves alone although they hold an email: the business details the site shows visitors. */
export const PUBLIC_OPTIONS = ['voyager_site_email'];

const TEXT_FILE = /\.(?:php|js|mjs|cjs|ts|json|css|scss|html|txt|md|xml|yml|yaml|ini|env|csv|svg)$/i;

/** Every text file under `dir` holding a string shaped like a credential. */
export function scanForSecrets(dir: string): string[] {
  const hits: string[] = [];
  const walk = (d: string) => {
    for (const name of readdirSync(d)) {
      const file = join(d, name);
      const stat = statSync(file);
      if (stat.isDirectory()) walk(file);
      else if (TEXT_FILE.test(name) && stat.size < 5_000_000) {
        const text = readFileSync(file, 'latin1');
        const re = SECRET_VALUES.find((r) => r.test(text));
        if (re) hits.push(`${relative(dir, file).replaceAll('\\', '/')} (${re.source.slice(0, 24)})`);
      }
    }
  };
  walk(dir);
  return hits;
}

/** Every text file under `dir` that mentions one of `needles`, as paths relative to `dir`. */
export function filesNaming(dir: string, needles: string[]): string[] {
  if (!needles.length) return [];
  const hits: string[] = [];
  const walk = (d: string) => {
    for (const name of readdirSync(d)) {
      const file = join(d, name);
      const stat = statSync(file);
      if (stat.isDirectory()) walk(file);
      else if ((TEXT_FILE.test(name) || name.endsWith('.md')) && stat.size < 5_000_000) {
        const text = readFileSync(file, 'latin1');
        if (needles.some((n) => text.includes(n))) hits.push(relative(dir, file).replaceAll('\\', '/'));
      }
    }
  };
  walk(dir);
  return hits.sort();
}

export function buildSnapshot(opts: BuildOptions): { tarball: string; sha256: string; pin: Pin } {
  const date = opts.date ?? new Date().toISOString().slice(0, 10);
  const name = `snapshot-${date}`;
  const stage = join(opts.out, name);
  rmSync(stage, { recursive: true, force: true });
  mkdirSync(join(stage, 'wp-content'), { recursive: true });
  const ssh = (command: string): Buffer => {
    const result = spawnSync('ssh', ['-i', opts.key, '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes', opts.ssh, `cd ${opts.path} && ${command}`], { maxBuffer: 1 << 30 });
    if (result.status !== 0) throw new Error(`ssh ${command}: exited ${result.status}: ${String(result.stderr).slice(0, 400)}`);
    return result.stdout;
  };

  // Facts first, so a failure costs nothing.
  const fact = (cmd: string) => ssh(cmd).toString('utf8').trim();
  const home = fact('wp option get home');
  const wordpress = fact('wp core version');
  const php = fact("php -r 'echo PHP_VERSION;'");
  const prefix = fact('wp config get table_prefix');
  const stylesheet = fact('wp option get stylesheet');
  const template = fact('wp option get template');
  const plugins = fact('ls wp-content/plugins').split('\n').filter((p) => p && !p.endsWith('.php') && !LEFT_OUT[`plugins/${p}`]);
  const muPlugins = fact('ls wp-content/mu-plugins').split('\n').filter((p) => p && !LEFT_OUT[`mu-plugins/${p}`]);

  // The database, to stdout: mysqldump in one transaction, so no table is locked on the live site. No `--no-*`
  // flags: WP-CLI passes `--no-tablespaces` on as `--tablespaces=`, which mysqldump rejects.
  const raw = ssh('wp db export - --single-transaction --set-gtid-purged=OFF').toString('latin1');
  const { sql, report } = sanitizeDump(raw, { prefix, publicOptions: PUBLIC_OPTIONS });
  writeFileSync(join(stage, 'db.sql'), sql, 'latin1');
  writeFileSync(join(stage, 'sanitize-report.json'), `${JSON.stringify(report, null, 2)}\n`);

  // The theme, its parent and the plugins, to stdout.
  const paths = [...new Set([`themes/${stylesheet}`, `themes/${template}`])].concat(plugins.map((p) => `plugins/${p}`), muPlugins.map((p) => `mu-plugins/${p}`));
  const files = ssh(`cd wp-content && tar -czf - --exclude=.git --exclude='*.log' --exclude='plugins/*/tests' ${paths.join(' ')}`);
  writeFileSync(join(stage, 'files.tar.gz'), files);
  run('tar', ['-xzf', 'files.tar.gz', '-C', 'wp-content'], { cwd: stage });
  rmSync(join(stage, 'files.tar.gz'));
  const leaks = scanForSecrets(join(stage, 'wp-content')).concat(SECRET_VALUES.some((re) => re.test(sql)) ? ['db.sql'] : []);
  if (leaks.length) throw new Error(`refusing to pack: credential-shaped strings in ${leaks.join(', ')}`);

  // A case study still in draft can already be in the theme as code (its pattern, the script that seeds it, a note).
  // Its post is dropped, so those files go too, CSS aside, which only styles it. The slugs are read from the site here
  // and never written to this repo, so a client named in an unpublished case study is named nowhere in it.
  const unpublished = fact('wp post list --post_type=portfolio --post_status=draft,pending,private,future --field=post_name --format=csv')
    .split('\n').map((s) => s.trim()).filter((s) => s.split('-').length >= 2 && s.length >= 8);
  const caseFiles = filesNaming(join(stage, 'wp-content'), unpublished).filter((f) => !f.endsWith('.css'));
  for (const f of caseFiles) rmSync(join(stage, 'wp-content', f));
  const leftOut = {
    ...LEFT_OUT,
    'unpublished case studies': `${caseFiles.length} theme or plugin files naming a case study that is not published (its pattern, its seed script, notes); CSS that only styles it is kept`,
  };

  const meta = { source: home, exported: date, wordpress, php, theme: { stylesheet, template }, plugins, mu_plugins: muPlugins, left_out: leftOut };
  writeFileSync(join(stage, 'meta.json'), `${JSON.stringify(meta, null, 2)}\n`);
  const tarball = join(opts.out, `${name}.tar.gz`);
  run('tar', ['-czf', `${name}.tar.gz`, '-C', name, 'db.sql', 'meta.json', 'sanitize-report.json', 'wp-content'], { cwd: opts.out });
  const sha256 = sha256File(tarball);
  const pin: Pin = {
    repo: opts.repo, tag: name, asset: `${name}.tar.gz`, sha256, bytes: statSync(tarball).size, ...meta,
  };
  return { tarball, sha256, pin };
}
