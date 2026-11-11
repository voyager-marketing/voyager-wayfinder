import { spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readdirSync, readFileSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BENCH_DIR, runBench, type Transcript } from '../src/run.ts';
import { cliTools, MAX_CHARS, TOOLS, toolsFor, usesContainer } from '../src/tools.ts';
import { checkContainer, OPTION_NAMES, readablePath, readerArgv, splitArgs, WP_COMMANDS, wpArgv, wpEnvRunner, type ContainerRun } from '../src/wpcli.ts';

afterEach(() => vi.unstubAllGlobals());

/** A cli container that records every command it is given and answers with `reply`. */
function fakeContainer(reply: (command: string[]) => { status: number | null; stdout?: string; stderr?: string } = () => ({ status: 0, stdout: 'ok\n' })) {
  const seen: string[][] = [];
  const run: ContainerRun = async (command) => {
    seen.push(command);
    return { stdout: '', stderr: '', ...reply(command) };
  };
  return { run, seen };
}
const site = async (name: string) => `site ${name}`;
const names = (c: Parameters<typeof toolsFor>[0]) => toolsFor(c).map((t) => t.name);

describe('the cli conditions', () => {
  it('are in config.json after the earlier four', () => {
    const config = JSON.parse(readFileSync(join(BENCH_DIR, 'config.json'), 'utf8'));
    expect(config.conditions).toEqual(['raw', 'manual', 'manual-preloaded', 'adapter', 'cli', 'cli-manual']);
  });

  it("offer raw's tools with wp and read_file, and cli-manual adds the manual's four", () => {
    expect(names('cli')).toEqual(['http_get', 'rest_get', 'wp', 'read_file']);
    expect(names('cli-manual')).toEqual([...names('cli'), 'manual_brief', 'manual_index', 'manual_section', 'find_edit_location']);
    expect(names('manual').filter((n) => !names('raw').includes(n))).toEqual(names('cli-manual').filter((n) => !names('cli').includes(n)));
    expect((['raw', 'manual', 'manual-preloaded', 'adapter'] as const).some(usesContainer)).toBe(false);
  });

  it('send wp and read_file to the container and every other tool to the site', async () => {
    const { run, seen } = fakeContainer();
    const execute = cliTools(site, run);
    expect(await execute('http_get', { path: '/' })).toBe('site http_get');
    expect(await execute('manual_index', {})).toBe('site manual_index');
    expect(await execute('wp', { args: 'post list' })).toBe('ok');
    expect(await execute('read_file', { path: 'themes' })).toBe('ok');
    expect(seen.map((c) => c[0])).toEqual(['wp', 'php']);
  });
});

describe('wp refuses a command that is not read-only, before the container sees it', () => {
  const refused = [
    'post delete 90001 --force',
    'post update 90001 --post_title=Changed',
    'post create --post_type=page --post_title=New',
    'post meta update 90020 price_from 99',
    'post meta delete 90020 price_from',
    'option update blogname Changed',
    'option delete vwf_phone',
    'term create service_area Downtown',
    'menu item delete 5',
    'menu location assign primary header',
    'theme activate twentytwentyfive',
    'plugin deactivate voyager-wayfinder',
    'plugin install hello-dolly --activate',
    'eval "update_option(\'blogname\', \'x\');"',
    'eval-file /tmp/x.php',
    'shell',
    'db query "DELETE FROM wp_posts"',
    'search-replace Riverbend Changed',
    'rewrite flush',
    'cache flush',
    'user create bob bob@example.com --role=administrator',
    'post list --exec="update_option(\'blogname\', \'x\');"',
    'post list --require=/tmp/x.php',
    'post list --ssh=user@host',
    '--url=other.example post list',
    'post list --http=https://example.com',
    'wayfinder brief',
    'option get vwf_phone',
    'option get siteurl',
    'option list',
    'post list | xargs wp post delete',
    'post list ; wp post delete 1',
    'post list -- --exec=x',
    'post list --post_type="page',
    '--format=json',
    '',
  ];
  it.each(refused)('refuses wp %s', async (args) => {
    const { run, seen } = fakeContainer();
    await expect(cliTools(site, run)('wp', { args })).rejects.toThrow(/^refused: /);
    expect(seen).toEqual([]);
  });

  it('names the command it refused, and not the code after it', () => {
    expect(() => wpArgv('eval "echo DB_PASSWORD;"')).toThrow('refused: wp eval is not a read-only command this tool runs');
    expect(() => wpArgv('post delete 90001')).toThrow('refused: wp post delete is not a read-only command');
    expect(() => wpArgv('option get vwf_phone')).toThrow(`refused: option get reads only ${OPTION_NAMES.join(', ')}`);
  });
});

describe('wp runs a read-only command', () => {
  it('as one argument array: quotes removed, a leading wp dropped, nothing expanded', async () => {
    const { run, seen } = fakeContainer();
    const execute = cliTools(site, run);
    for (const args of [
      'wp post list --post_type=page --fields=ID,post_title',
      `post list --s="Same day" --post_status='publish'`,
      'option get blogname',
      'post meta list 90020 --format=json',
      'post list --post_type=wp_block --fields=ID,post_title',
      'help post list',
      'post list --s=$(whoami)',
    ]) await execute('wp', { args });
    expect(seen).toEqual([
      ['wp', 'post', 'list', '--post_type=page', '--fields=ID,post_title'],
      ['wp', 'post', 'list', '--s=Same day', '--post_status=publish'],
      ['wp', 'option', 'get', 'blogname'],
      ['wp', 'post', 'meta', 'list', '90020', '--format=json'],
      ['wp', 'post', 'list', '--post_type=wp_block', '--fields=ID,post_title'],
      ['wp', 'help', 'post', 'list'],
      ['wp', 'post', 'list', '--s=$(whoami)'],
    ]);
  });

  it('splits words the way a shell does', () => {
    expect(splitArgs(`a "b c" 'd "e"' f\\ g "h \\"i\\"" ''`)).toEqual(['a', 'b c', 'd "e"', 'f g', 'h "i"', '']);
    expect(() => splitArgs(`a 'b`)).toThrow("refused: the arguments have an unterminated ' quote");
  });

  it('lists every command it runs and every option it reads in the tool description', () => {
    for (const c of [...WP_COMMANDS, ...OPTION_NAMES]) expect(TOOLS.wp.description).toContain(c);
    for (const c of WP_COMMANDS) expect(() => wpArgv(c === 'option get' ? 'option get blogname' : c)).not.toThrow();
  });

  it("reads exactly the options on the scanner's allowlist (src/Scanners/OptionAllowlist.php)", () => {
    const php = readFileSync(join(BENCH_DIR, '..', 'src', 'Scanners', 'OptionAllowlist.php'), 'utf8');
    const options = /const OPTIONS = \[([\s\S]*?)\];/.exec(php)?.[1] ?? '';
    const scanner = [...options.matchAll(/^\s*'([^']+)'\s*=>/gm)].map((m) => m[1]);
    expect(scanner.length).toBeGreaterThan(5);
    expect([...OPTION_NAMES]).toEqual(scanner);
  });

  it("returns stdout without wp-env's own lines, with the command's warnings after it", async () => {
    const stderr = "ℹ Starting 'wp post list' on the cli container. \n\nWarning: a plugin notice\n✔ Ran `wp post list` in 'cli'. (in 1s 20ms)\n";
    const { run } = fakeContainer(() => ({ status: 0, stdout: 'ID\tpost_title\n90001\tHome\n', stderr }));
    expect(await cliTools(site, run)('wp', { args: 'post list' })).toBe('ID\tpost_title\n90001\tHome\nWarning: a plugin notice');
    const { run: quiet } = fakeContainer(() => ({ status: 0, stdout: '' }));
    expect(await cliTools(site, quiet)('wp', { args: 'menu list' })).toBe('(no output)');
  });

  it("turns a failed command into an error carrying WP-CLI's message", async () => {
    const stderr = "ℹ Starting 'wp post get 5' on the cli container. \nError: Could not find the post with ID 5.\n✖ Command failed with exit code 1\nCommand failed with exit code 1\n";
    const { run } = fakeContainer(() => ({ status: 1, stderr }));
    await expect(cliTools(site, run)('wp', { args: 'post get 5' })).rejects.toThrow(/^wp post get 5: Error: Could not find the post with ID 5\.$/);
    const { run: silent } = fakeContainer(() => ({ status: 1 }));
    await expect(cliTools(site, silent)('wp', { args: 'post get 5' })).rejects.toThrow('wp post get 5 failed with exit code 1');
  });

  it('caps its output at 20,000 characters, like every tool', async () => {
    const { run } = fakeContainer(() => ({ status: 0, stdout: 'x'.repeat(MAX_CHARS * 2) }));
    const out = await cliTools(site, run)('wp', { args: 'post list --field=post_content' });
    expect(out).toHaveLength(MAX_CHARS);
    expect(out).toMatch(/truncated at 20,000 characters/);
  });
});

describe('read_file', () => {
  it('reads under themes, plugins and mu-plugins, in any of the path forms', async () => {
    expect(readablePath('themes/vwf-fixture/theme.json')).toBe('themes/vwf-fixture/theme.json');
    expect(readablePath('wp-content/mu-plugins/')).toBe('mu-plugins');
    expect(readablePath('/var/www/html/wp-content/plugins/advanced-custom-fields/acf.php')).toBe('plugins/advanced-custom-fields/acf.php');
    expect(readablePath('themes/vwf-fixture/../twentytwentyfive/theme.json')).toBe('themes/twentytwentyfive/theme.json');
    const { run, seen } = fakeContainer();
    await cliTools(site, run)('read_file', { path: 'themes/vwf-fixture/templates/single-service.html' });
    expect(seen).toEqual([readerArgv('themes/vwf-fixture/templates/single-service.html')]);
    expect(seen[0].slice(3)).toEqual(['/var/www/html/wp-content', 'themes/vwf-fixture/templates/single-service.html', 'themes,plugins,mu-plugins', 'plugins/voyager-wayfinder']);
  });

  it.each([
    '../wp-config.php',
    'wp-config.php',
    '/etc/passwd',
    '/var/www/html/wp-config.php',
    'uploads/2026/10/report.pdf',
    'themes/../../wp-config.php',
    'wp-content/../wp-config.php',
    'wp-content',
    // The bench's own checkout is mapped there: it holds truth.json, the questions and the bench's password.
    'plugins/voyager-wayfinder',
    'plugins/voyager-wayfinder/fixtures/truth.json',
    'plugins/voyager-wayfinder/fixtures/.credentials',
    'plugins/advanced-custom-fields/../voyager-wayfinder/bench/questions/fixture.yaml',
  ])('refuses %s before the container sees it', async (path) => {
    const { run, seen } = fakeContainer();
    await expect(cliTools(site, run)('read_file', { path })).rejects.toThrow(/^refused: /);
    expect(seen).toEqual([]);
  });

  // The container's half, run here with the same PHP: symlinks resolved, then the same roots checked.
  const php = spawnSync('php', ['-v']).status === 0;
  describe.skipIf(!php)('in the container, with PHP', () => {
    const root = mkdtempSync(join(tmpdir(), 'vwf-wp-content-'));
    for (const dir of ['themes/t1/parts', 'plugins/voyager-wayfinder/fixtures', 'plugins/other', 'mu-plugins', 'uploads']) mkdirSync(join(root, dir), { recursive: true });
    writeFileSync(join(root, 'themes/t1/theme.json'), '{"version":3}\n');
    writeFileSync(join(root, 'themes/t1/parts/footer.html'), '<!-- wp:paragraph -->');
    writeFileSync(join(root, 'themes/t1/logo.png'), Buffer.from([0x89, 0x50, 0x4e, 0x47, 0, 1, 2]));
    writeFileSync(join(root, 'plugins/voyager-wayfinder/fixtures/truth.json'), '{}');
    writeFileSync(join(root, 'uploads/secret.txt'), 'secret');
    writeFileSync(join(root, '..', `${root.split(/[\\/]/).pop()}-config.php`), 'define');
    const read = (rel: string) => {
      const [, ...args] = readerArgv(rel, root);
      const r = spawnSync('php', args, { encoding: 'utf8' });
      return { status: r.status, stdout: r.stdout, stderr: r.stderr.trim() };
    };

    it('reads a file, lists a folder and describes a binary file', () => {
      expect(read('themes/t1/theme.json')).toEqual({ status: 0, stdout: '{"version":3}\n', stderr: '' });
      expect(read('themes/t1').stdout).toBe('logo.png\nparts/\ntheme.json\n');
      expect(read('themes/t1/logo.png').stdout).toBe('[binary file, 7 bytes]\n');
      expect(read('themes/missing')).toMatchObject({ status: 2, stderr: 'not found' });
    });

    it('refuses what the path check would also refuse, so either check alone holds', () => {
      expect(read('plugins/voyager-wayfinder/fixtures/truth.json')).toMatchObject({ status: 3, stdout: '', stderr: 'outside the readable folders' });
      expect(read('plugins/voyager-wayfinder')).toMatchObject({ status: 3, stdout: '' });
      expect(read('uploads/secret.txt')).toMatchObject({ status: 3, stdout: '' });
      expect(read(`themes/../../${root.split(/[\\/]/).pop()}-config.php`)).toMatchObject({ status: 3, stdout: '' });
    });

    // Creating a symlink needs extra rights on Windows.
    it.skipIf(process.platform === 'win32')('refuses a symlink that leads out of the readable folders', () => {
      symlinkSync(join(root, 'uploads/secret.txt'), join(root, 'themes/t1/link.txt'));
      symlinkSync(join(root, 'plugins/voyager-wayfinder'), join(root, 'plugins/alias'));
      expect(read('themes/t1/link.txt')).toMatchObject({ status: 3, stdout: '' });
      expect(read('plugins/alias/fixtures/truth.json')).toMatchObject({ status: 3, stdout: '' });
    });
  });
});

describe('a live run', () => {
  it('names the fix when @wordpress/env is not installed where the run looks for it', () => {
    expect(() => wpEnvRunner(mkdtempSync(join(tmpdir(), 'vwf-no-wp-env-')))).toThrow('the cli conditions need the pinned @wordpress/env: run npm ci in the repository root');
  });

  it('stops before its first question when the container cannot run WP-CLI', async () => {
    const { run } = fakeContainer(() => ({ status: 1, stderr: 'Error: The environment is not initialized.\n' }));
    await expect(checkContainer(run)).rejects.toThrow('the cli conditions cannot use the wp-env cli container: wp option get blogname: Error: The environment is not initialized.');
    const { run: up, seen } = fakeContainer();
    await checkContainer(up);
    expect(seen).toEqual([['wp', 'option', 'get', 'blogname'], readerArgv('themes')]);
  });
});

describe('the dry run', () => {
  it('replays a cli and a cli-manual recording with no network', async () => {
    vi.stubGlobal('fetch', () => Promise.reject(new Error('network call in a dry run')));
    const out = mkdtempSync(join(tmpdir(), 'vwf-bench-'));
    const { report } = await runBench({ dryRun: true, conditions: ['cli', 'cli-manual'], out, date: '2026-10-06' });

    expect(report.runs.map((s) => [s.condition, s.model, `${s.correct}/${s.questions}`])).toEqual([
      ['cli', 'claude-haiku-4-5-20251001', '1/1'],
      ['cli-manual', 'claude-sonnet-5', '1/1'],
    ]);
    // Recordings replay in file name order, and `cli-manual.` sorts before `cli.`.
    expect(report.results.map((r) => [r.condition, r.question, r.answer, r.tool_calls])).toEqual([
      ['cli-manual', 'el-10', 'themes/vwf-fixture/templates/single-service.html', 2],
      ['cli', 'df-04', 'vwf-fixture-core.php', 3],
    ]);
    expect(readFileSync(join(out, 'fixture-latest.md'), 'utf8')).toContain('| cli-manual | claude-sonnet-5 | 1 | 1/1 | 100.0% |');
  });

  it("calls only what the guard lets through, and a recorded refusal is the guard's own words", () => {
    const dir = join(BENCH_DIR, 'test', 'recordings');
    const recordings = readdirSync(dir).map((f) => JSON.parse(readFileSync(join(dir, f), 'utf8')) as Transcript).filter((t) => usesContainer(t.condition));
    expect(recordings.map((t) => t.condition).sort()).toEqual(['cli', 'cli-manual']);
    const calls = recordings.flatMap((t) => t.turns.flatMap((turn) => turn.tool_results.map((r) => ({
      use: turn.response.content.find((b) => b.type === 'tool_use' && b.id === r.tool_use_id) as { name: string; input: Record<string, string> },
      result: r,
    }))));
    const guard = (name: string, input: Record<string, string>) => (name === 'wp' ? wpArgv(input.args) : name === 'read_file' ? readablePath(input.path) : undefined);
    const refusals = calls.filter((c) => c.result.is_error);
    expect(refusals).toHaveLength(1);
    for (const { use, result } of calls) {
      if (result.is_error) expect(() => guard(use.name, use.input)).toThrow(result.content as string);
      else expect(() => guard(use.name, use.input)).not.toThrow();
    }
  });
});
