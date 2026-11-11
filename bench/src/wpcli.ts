import { spawn } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { basename, dirname, join, posix, resolve } from 'node:path';

// The cli conditions: the agent an agency gives a shell in Claude Code, with WP-CLI and the theme and
// plugin files, but read-only. Every command runs in the wp-env cli container, so the tools reach
// whichever fixture the site was seeded as. Nothing here goes through a shell: an allowed command is
// an argument array handed to `docker exec` in that container, and everything else is refused before any
// process starts.

/**
 * The read-only WP-CLI commands `wp` runs, as the words that start the command line. A command matches
 * when its leading positional arguments are one of these. WP-CLI 2.12 has no pattern command: synced
 * patterns, templates and template parts are posts (`post list --post_type=wp_block`).
 */
export const WP_COMMANDS = [
  'post list', 'post get', 'post meta list', 'post meta get', 'post term list', 'post url-to-id',
  'post-type list', 'post-type get',
  'term list', 'term get', 'term meta list', 'term meta get',
  'taxonomy list', 'taxonomy get',
  'menu list', 'menu item list', 'menu location list',
  'theme list', 'theme get', 'theme status',
  'plugin list', 'plugin get', 'plugin status',
  'sidebar list', 'widget list',
  'role list', 'cap list',
  'rewrite list',
  'option get',
  'help',
] as const;

/**
 * The options `option get` reads: the scanner's allowlist, `OptionAllowlist::OPTIONS` in
 * src/Scanners/OptionAllowlist.php. test/cli.condition.test.ts fails when the two differ.
 */
export const OPTION_NAMES = [
  'blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts',
  'permalink_structure', 'timezone_string', 'WPLANG', 'stylesheet', 'template',
] as const;

/** WP-CLI global parameters that run code, load a file, or move the command to another site or host. */
const DENIED_FLAGS = ['exec', 'require', 'ssh', 'http', 'path', 'url', 'context', 'prompt'];

/** Shell operators. There is no shell, so they would reach WP-CLI as arguments; refusing says why. */
const SHELL_OPERATORS = ['|', '||', '&', '&&', ';', '>', '>>', '<', '2>', '2>&1'];

/** The wp-content folders `read_file` reads, and what it never reads inside them. */
export const READ_ROOTS = ['themes', 'plugins', 'mu-plugins'] as const;
/** This checkout, mapped into every fixture: it holds the answer keys, the questions and the bench's password. */
export const UNREADABLE = ['plugins/voyager-wayfinder'] as const;

/** wp-content inside the wp-env containers. */
const WP_CONTENT = '/var/www/html/wp-content';

/** A command or path the tools will not run. The message is what the agent reads. */
export class Refused extends Error {
  constructor(reason: string) {
    super(`refused: ${reason}`);
  }
}

/**
 * Splits a command line the way a shell would split words: whitespace separates, single quotes are
 * literal, double quotes allow \" and \\, and a backslash outside quotes escapes the next character.
 * Nothing is expanded.
 */
export function splitArgs(line: string): string[] {
  const out: string[] = [];
  let [word, started, quote] = ['', false, ''];
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (ch === '\0') throw new Refused('the arguments contain a NUL character');
    if (quote) {
      if (ch === quote) quote = '';
      else if (quote === '"' && ch === '\\' && (line[i + 1] === '"' || line[i + 1] === '\\')) word += line[++i];
      else word += ch;
    } else if (ch === '"' || ch === "'") [quote, started] = [ch, true];
    else if (/\s/.test(ch)) {
      if (started) out.push(word);
      [word, started] = ['', false];
    } else if (ch === '\\' && i + 1 < line.length) [word, started] = [word + line[++i], true];
    else [word, started] = [word + ch, true];
  }
  if (quote) throw new Refused(`the arguments have an unterminated ${quote} quote`);
  if (started) out.push(word);
  return out;
}

/**
 * The argument array `wp` runs for a command line, or a Refused error. The line is what follows `wp`;
 * a leading `wp` is dropped. Every token is checked, so a denied flag cannot hide after the command.
 */
export function wpArgv(line: string): string[] {
  const tokens = splitArgs(line);
  if (tokens[0] === 'wp') tokens.shift();
  if (!tokens.length) throw new Refused('give a WP-CLI command, for example post list --post_type=page');
  for (const t of tokens) {
    if (SHELL_OPERATORS.includes(t)) throw new Refused(`${t}: there is no shell, so pipes, redirects and chaining do not work. Run one command per call`);
    if (t === '--' || (t.startsWith('-') && !t.startsWith('--'))) throw new Refused(`${t} is not an argument this tool passes; WP-CLI flags take the form --name or --name=value`);
    const flag = /^--(?:no-)?([^=]+)/.exec(t)?.[1];
    if (flag && DENIED_FLAGS.includes(flag)) throw new Refused(`--${flag} runs code, loads a file or reaches another site, so it is not allowed`);
  }
  const positional = tokens.filter((t) => !t.startsWith('--'));
  const command = WP_COMMANDS.map((c) => c.split(' '))
    .filter((words) => words.every((w, i) => positional[i] === w))
    .sort((a, b) => b.length - a.length)[0];
  if (!command) {
    if (!positional.length) throw new Refused('give a WP-CLI command; flags alone run nothing');
    // Name the command words only, not the arguments after them (a refused eval would echo its code).
    const end = positional.findIndex((w) => !/^[a-z][a-z0-9-]*$/i.test(w));
    const named = positional.slice(0, Math.min(3, end === -1 ? positional.length : Math.max(1, end)));
    throw new Refused(`wp ${named.join(' ')} is not a read-only command this tool runs. The tool description lists the ones it does`);
  }
  if (command.join(' ') === 'option get') {
    const names = positional.slice(2);
    if (names.length !== 1 || !(OPTION_NAMES as readonly string[]).includes(names[0])) {
      throw new Refused(`option get reads only ${OPTION_NAMES.join(', ')}`);
    }
  }
  return tokens;
}

/**
 * The path under wp-content that `read_file` reads, or a Refused error. Takes `themes/x`, `wp-content/themes/x`
 * or `/var/www/html/wp-content/themes/x`. This check is on the words of the path; the container checks the
 * resolved path again (READER), so a symlink cannot lead out either.
 */
export function readablePath(path: string): string {
  if (path.includes('\0')) throw new Refused('the path contains a NUL character');
  const trimmed = path.trim().replace(/\\/g, '/').replace(/^\/?var\/www\/html\//, '').replace(/^\/+/, '').replace(/^wp-content(\/|$)/, '');
  const rel = posix.normalize(trimmed || '.').replace(/\/+$/, '');
  const root = rel.split('/')[0];
  if (!(READ_ROOTS as readonly string[]).includes(root)) throw new Refused(`${path} is not under wp-content/themes, plugins or mu-plugins`);
  if (UNREADABLE.some((u) => rel === u || rel.startsWith(`${u}/`))) throw new Refused(`${path} is outside what this tool reads`);
  return rel;
}

/**
 * The container's side of `read_file`, run as `php -r READER <wp-content> <path> <roots> <unreadable>`. It
 * resolves symlinks before checking the roots, lists a folder (sorted, `/` after subfolders), and reads at
 * most 64 KB of a file. Written without double quotes or backslashes, so the argument reaches PHP unchanged
 * through wp-env and docker on every platform.
 */
export const READER = [
  "$root = rtrim(str_replace(chr(92), '/', (string) realpath($argv[1])), '/');",
  "$p = $root === '' ? false : realpath($argv[1] . '/' . $argv[2]);",
  "if ($p === false) { fwrite(STDERR, 'not found' . chr(10)); exit(2); }",
  "$p = str_replace(chr(92), '/', $p);",
  "$in = function ($d) use ($root, $p) { return $p === $root . '/' . $d || strpos($p, $root . '/' . $d . '/') === 0; };",
  "if (!array_filter(explode(',', $argv[3]), $in) || array_filter(explode(',', $argv[4]), $in)) { fwrite(STDERR, 'outside the readable folders' . chr(10)); exit(3); }",
  "if (is_dir($p)) { $n = array_values(array_diff(scandir($p), ['.', '..'])); sort($n, SORT_STRING); foreach ($n as $e) { echo $e, is_dir($p . '/' . $e) ? '/' : '', chr(10); } exit(0); }",
  "$c = file_get_contents($p, false, null, 0, 65536);",
  "if ($c === false) { fwrite(STDERR, 'cannot read' . chr(10)); exit(4); }",
  "echo strpos($c, chr(0)) === false ? $c : '[binary file, ' . filesize($p) . ' bytes]' . chr(10);",
].join(' ');

/** The argument array `read_file` runs for a path already passed by readablePath. */
export const readerArgv = (rel: string, wpContent = WP_CONTENT): string[] =>
  ['php', '-r', READER, wpContent, rel, READ_ROOTS.join(','), UNREADABLE.join(',')];

export interface ContainerResult {
  status: number | null;
  stdout: string;
  stderr: string;
}
/**
 * Runs one argument array in the wp-env cli container. Tests pass a fake. A runner that finds the container itself
 * (`dockerExecRunner`) also has `locate`, which `checkContainer` calls first, so every cell looks the container up
 * again instead of trusting the answer an earlier cell got.
 */
export interface ContainerRun {
  (command: string[]): Promise<ContainerResult>;
  /** Finds the container the next commands run in, and returns its id. Throws when it is missing or not running. */
  locate?: () => Promise<string>;
}

/** wp-env's own lines on stderr, around the command's: the start notice, the timing, and its failure line. */
const WP_ENV_LINE = /Starting '.*' on the cli container\.|Ran `.*` in 'cli'\.|Command failed with exit code \d+/;
const ERROR_CHARS = 2_000;

/** The command's stderr without wp-env's lines. */
const commandStderr = (stderr: string) =>
  stderr.split(/\r?\n/).filter((l) => l.trim() && !WP_ENV_LINE.test(l)).join('\n').slice(0, ERROR_CHARS);

/** Output on success, with any warnings after it. A failure throws, so the agent sees an error result. */
function outcome(result: ContainerResult, label: string): string {
  const stderr = commandStderr(result.stderr);
  if (result.status !== 0) throw new Error(stderr ? `${label}: ${stderr}` : `${label} failed with exit code ${result.status}`);
  return [result.stdout.trimEnd() || '(no output)', stderr].filter(Boolean).join('\n');
}

/** The `wp` tool: a refused command never reaches the container. Not capped; the executor caps it. */
export async function runWp(run: ContainerRun, line: string): Promise<string> {
  const argv = wpArgv(line);
  return outcome(await run(['wp', ...argv]), `wp ${argv.join(' ')}`);
}

/** The `read_file` tool. Not capped; the executor caps it. */
export async function readFile(run: ContainerRun, path: string): Promise<string> {
  const rel = readablePath(path);
  return outcome(await run(readerArgv(rel)), `read_file ${rel}`);
}

/**
 * Throws when the cli container cannot be found, cannot run WP-CLI or cannot read wp-content. A live run calls it
 * before its first question, before every cli cell (`cellGuard` in src/toolErrors.ts) and after the last.
 */
export async function checkContainer(run: ContainerRun): Promise<void> {
  try {
    await run.locate?.();
    await runWp(run, 'option get blogname');
    await readFile(run, 'themes');
  } catch (error) {
    throw new Error(`the cli conditions cannot use the wp-env cli container: ${(error as Error).message}`);
  }
}

const OUTPUT_LIMIT = 4_000_000;
const TIMEOUT_MS = 120_000;

/** Runs a program with an argument array, no shell, and collects its output. Killed after TIMEOUT_MS. */
function collect(command: string, args: string[], cwd?: string): Promise<ContainerResult> {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, { cwd, stdio: ['ignore', 'pipe', 'pipe'] });
    const out = { stdout: '', stderr: '' };
    const gather = (key: keyof typeof out) => (chunk: Buffer) => {
      if (out[key].length < OUTPUT_LIMIT) out[key] += chunk.toString('utf8');
    };
    child.stdout.on('data', gather('stdout'));
    child.stderr.on('data', gather('stderr'));
    const timer = setTimeout(() => child.kill(), TIMEOUT_MS);
    child.on('error', (error) => (clearTimeout(timer), reject(error)));
    child.on('close', (status, signal) => {
      clearTimeout(timer);
      resolve({ status: signal ? null : status, ...out, stderr: signal ? `timed out after ${TIMEOUT_MS / 1000}s\n${out.stderr}` : out.stderr });
    });
  });
}

/**
 * `run` with at most `max` commands in flight. The rest wait their turn, first come first served, and each starts when
 * one before it has settled, whatever its outcome.
 */
export function limit(run: ContainerRun, max: number): ContainerRun {
  if (!Number.isInteger(max) || max < 1) throw new Error(`a container command limit is a whole number of 1 or more, not ${max}`);
  let active = 0;
  const waiting: (() => void)[] = [];
  return async (command) => {
    // A finished command hands its slot straight to the next in line, so `active` counts slots, not starts.
    if (active < max) active++;
    else await new Promise<void>((go) => waiting.push(go));
    try {
      return await run(command);
    } finally {
      const next = waiting.shift();
      if (next) next();
      else active--;
    }
  };
}

/**
 * `run` with one command at a time: each call starts when the one before it has settled, whatever its outcome.
 *
 * wp-env needs this. Every `wp-env run` parses the default config, whose `core: null` makes it fetch the
 * latest WordPress version and rewrite the work directory's wp-env-cache.json with a plain writeFile, no lock. Another
 * wp-env reading the file mid-write gets `{}` and says "Environment not initialized. Run `wp-env start` first."
 * When that reader writes its own fetch back, the cache loses the `runtime` that `wp-env start` saved, and every later
 * call fails the same way. Run 5 ran at `--concurrency 6`; its cli cells broke for good a few minutes in.
 */
export const serialize = (run: ContainerRun): ContainerRun => limit(run, 1);

/** The pinned @wordpress/env's folder. Its package exports only lib/config and lib/cli.js, so the runners read its files by path. */
function wpEnvDir(repoRoot: string): string {
  try {
    return dirname(createRequire(join(repoRoot, 'package.json')).resolve('@wordpress/env/package.json'));
  } catch {
    throw new Error('the cli conditions need the pinned @wordpress/env: run npm ci in the repository root');
  }
}

/**
 * Runs commands in the cli container of the wp-env site configured at `repoRoot`, with the pinned
 * @wordpress/env (`wp-env run cli <command> -- <args>`), one at a time (`serialize`). `--` hands every argument
 * to the container untouched, and wp-env passes them to `docker compose exec` as an array, so no shell parses them.
 *
 * A live run no longer uses it: `dockerExecRunner` runs the same commands without wp-env. It stays for
 * `--exec-parity`, which checks the two give the same output.
 */
export function wpEnvRunner(root: string): ContainerRun {
  const repoRoot = resolve(root);
  const dir = wpEnvDir(repoRoot);
  const bin = join(dir, (JSON.parse(readFileSync(join(dir, 'package.json'), 'utf8')) as { bin: Record<string, string> }).bin['wp-env']);
  return serialize(([command, ...args]) => collect(process.execPath, [bin, 'run', 'cli', command, '--', ...args], repoRoot));
}

/** Runs the docker CLI with an argument array, no shell. Tests pass a fake, or a stand-in CLI as `command` with `prefix`. */
export type Docker = (args: string[]) => Promise<ContainerResult>;
export const dockerCli = (command = 'docker', prefix: string[] = []): Docker => (args) => collect(command, [...prefix, ...args]);

/** What the exec runner needs to know about a wp-env site. */
export interface WpEnvSite {
  /** The folder `wp-env start` wrote this site's docker-compose.yml to. */
  workDir: () => Promise<string>;
  /** The user `wp-env run` execs as: the host's uid:gid, or 1000:1000 on Windows. */
  user: string;
}

/**
 * wp-env's work folder for the config file `configFile`, as `loadConfig` names it (lib/config/load-config.js in
 * @wordpress/env 11.16.0): the legacy `<md5 of the config path>` folder when one exists, else
 * `wp-env-<config folder>[-<variant>]-<first 8 of the md5>`. Ported, because wp-env does not export it and its
 * loadConfig reads, and may rewrite, wp-env-cache.json: the file whose unlocked rewrite broke run 5.
 */
export function wpEnvWorkDir(configFile: string, cacheDir: string, md5: (s: string) => string, exists: (path: string) => boolean = existsSync): string {
  const legacy = resolve(cacheDir, md5(configFile));
  if (exists(legacy)) return legacy;
  const name = basename(configFile, '.json');
  const variant = (name === '.wp-env' ? '' : name.startsWith('.wp-env.') ? name.slice('.wp-env.'.length) : name)
    .replace(/[^a-zA-Z0-9._]+/g, '-').replace(/^-+|-+$/g, '');
  return resolve(cacheDir, ['wp-env', basename(dirname(configFile)), ...(variant ? [variant] : []), md5(configFile).slice(0, 8)].join('-'));
}

/**
 * The wp-env site configured at `root` (its .wp-env.json), read with the pinned @wordpress/env's own functions for
 * the config path, the cache folder and the host user. Nothing here reads or writes wp-env-cache.json.
 */
export function wpEnvSite(root: string): WpEnvSite {
  const repoRoot = resolve(root);
  const dir = wpEnvDir(repoRoot);
  const load = createRequire(join(dir, 'package.json'));
  const lib = (file: string): unknown => load(join(dir, 'lib', file));
  const { getConfigFilePath } = lib('config/parse-config.js') as { getConfigFilePath: (dir: string, type: string, custom: null) => string };
  const getCacheDirectory = lib('config/get-cache-directory.js') as () => Promise<string>;
  const md5 = lib('md5.js') as (s: string) => string;
  const getHostUser = lib('runtime/docker/get-host-user.js') as () => { fullUser: string };
  return {
    workDir: async () => wpEnvWorkDir(getConfigFilePath(repoRoot, 'local', null), await getCacheDirectory(), md5),
    user: getHostUser().fullUser,
  };
}

/**
 * The compose project docker compose gives the work folder, so the one wp-env's containers carry: COMPOSE_PROJECT_NAME
 * when it is set, as compose reads it from the environment wp-env passes on, else the folder name lowercased with
 * everything but a to z, 0 to 9, `_` and `-` dropped and leading `_` and `-` trimmed (compose-go's NormalizeProjectName).
 * wp-env's docker-compose.yml sets no `name`.
 */
export function composeProject(workDir: string, env: Record<string, string | undefined> = process.env): string {
  return env.COMPOSE_PROJECT_NAME || basename(workDir).toLowerCase().replace(/[^a-z0-9_-]/g, '').replace(/^[_-]+/, '');
}

/** One line per cli service container, from every compose project on the machine: id, state, project. */
export const CLI_CONTAINERS = ['ps', '--all', '--no-trunc', '--filter', 'label=com.docker.compose.service=cli', '--format', '{{.ID}} {{.State}} {{.Label `com.docker.compose.project`}}'];

/**
 * The id of the running cli container of compose project `project`. The lookup lists every project's cli container and
 * keeps this project's, so another wp-env site on the machine, a worktree's or a snapshot fixture's, is never picked.
 * Throws, naming the project and what it found, when the project has no cli container or none is running.
 */
export async function findCliContainer(docker: Docker, project: string): Promise<string> {
  const listed = await docker(CLI_CONTAINERS);
  if (listed.status !== 0) throw new Error(`docker ps failed: ${listed.stderr.trim() || `exit code ${listed.status}`}`);
  const all = listed.stdout.split(/\r?\n/).filter((l) => l.trim()).map((l) => {
    const [id, state, name = ''] = l.trim().split(/\s+/);
    return { id, state, project: name };
  });
  const ours = all.filter((c) => c.project === project);
  const running = ours.filter((c) => c.state === 'running');
  if (running.length === 1) return running[0].id;
  if (running.length > 1) throw new Error(`compose project ${project} has ${running.length} running cli containers; expected one`);
  if (ours.length) throw new Error(`the cli container of compose project ${project} is ${ours.map((c) => c.state).join(', ')}, not running. Start the site: npx @wordpress/env start`);
  const others = [...new Set(all.map((c) => c.project))].sort();
  throw new Error(`no cli container in compose project ${project}${others.length ? ` (found: ${others.join(', ')})` : ''}. Start the site: npx @wordpress/env start`);
}

/** Where `wp-env run cli` runs a command when no `--env-cwd` is given. */
export const CLI_WORKDIR = '/var/www/html';

/**
 * The `docker exec` arguments for one command, as `wp-env run cli` asks docker compose for them (`_spawnCommandDirectly`
 * in lib/runtime/docker/index.js): the same user and working directory, stdin attached as compose's default `--interactive`
 * does, no TTY (`-T`). The environment is the container's own, set from the compose file when it was created, which
 * both inherit.
 */
export const execArgv = (container: string, user: string, command: string[]): string[] =>
  ['exec', '--interactive', '--user', user, '--workdir', CLI_WORKDIR, container, ...command];

/**
 * Runs commands in the cli container of the wp-env site configured at `root` with `docker exec`, with no wp-env process
 * per call, so wp-env-cache.json is never touched and calls can run side by side. At most `opts.limit` run at
 * once (`cli_exec_limit` in config.json). `locate` finds the container from the site's own compose project; a live run
 * calls it before every cli cell through `checkContainer`, and the first command calls it when nothing has yet.
 * A failed command reports exit status 1, as `wp-env run` does whatever the command's own code, so the agent reads the
 * same error either way.
 */
export function dockerExecRunner(root: string, opts: { limit?: number; docker?: Docker; site?: WpEnvSite } = {}): ContainerRun {
  const site = opts.site ?? wpEnvSite(root);
  const docker = opts.docker ?? dockerCli();
  let found: Promise<string> | undefined;
  const locate = (): Promise<string> => {
    const lookup = site.workDir().then((workDir) => findCliContainer(docker, composeProject(workDir)));
    lookup.catch(() => undefined); // awaited by checkContainer or the commands; this only marks it handled
    found = lookup;
    return lookup;
  };
  const exec: ContainerRun = async (command) => {
    const result = await docker(execArgv(await (found ?? locate()), site.user, command));
    return { ...result, status: result.status === null || result.status === 0 ? result.status : 1 };
  };
  return Object.assign(opts.limit === undefined ? exec : limit(exec, opts.limit), { locate });
}
