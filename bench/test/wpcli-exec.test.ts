import { chmodSync, existsSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { execParity, PARITY_COMMANDS, parityHolds, renderParity } from '../src/execParity.ts';
import { BENCH_DIR } from '../src/run.ts';
import { cellGuard, type CellJob } from '../src/toolErrors.ts';
import {
  checkContainer, CLI_CONTAINERS, composeProject, dockerCli, dockerExecRunner, execArgv, findCliContainer, limit, readerArgv,
  readFile, runWp, serialize, wpEnvRunner, wpEnvSite, wpEnvWorkDir, type ContainerResult, type ContainerRun, type Docker, type WpEnvSite,
} from '../src/wpcli.ts';

// From run 6 the cli conditions run WP-CLI with `docker exec` in the site's cli container, with no wp-env process per call.

afterEach(() => vi.unstubAllEnvs());

const WORK = '/home/user/.wp-env/wp-env-voyager-wayfinder-1a2b3c4d';
const OURS = 'wp-env-voyager-wayfinder-1a2b3c4d';
const site = (workDir = WORK): WpEnvSite => ({ workDir: async () => workDir, user: '1000:1000' });
/** `docker ps` output for the cli containers of three wp-env sites: a worktree's, this checkout's and a snapshot fixture's. */
const THREE_SITES = ['aaa111 running wp-env-feature-branch-11111111', `bbb222 running ${OURS}`, 'ccc333 running wp-env-snapshot-22222222'].join('\n');

/** A docker CLI that records every call. `ps` answers with `listing()`; `exec` answers with `reply`, after `ms`. */
function fakeDocker(listing: () => string = () => THREE_SITES, reply: (argv: string[]) => Partial<ContainerResult> = () => ({ status: 0, stdout: 'ok\n' }), ms = 0) {
  const calls: string[][] = [];
  let inFlight = 0;
  let most = 0;
  const docker: Docker = async (args) => {
    calls.push(args);
    if (args[0] === 'ps') return { status: 0, stdout: `${listing()}\n`, stderr: '' };
    most = Math.max(most, ++inFlight);
    await new Promise((done) => setTimeout(done, ms));
    inFlight--;
    return { status: 0, stdout: '', stderr: '', ...reply(args) };
  };
  return { docker, calls, execs: () => calls.filter((c) => c[0] === 'exec'), most: () => most };
}

describe('the exec runner', () => {
  it("runs wp in the site's cli container with docker exec, as wp-env run's user, in /var/www/html", async () => {
    const { docker, calls } = fakeDocker();
    const run = dockerExecRunner('/repo', { docker, site: site() });
    expect(await runWp(run, 'option get blogname')).toBe('ok');
    expect(calls).toEqual([
      CLI_CONTAINERS,
      ['exec', '--interactive', '--user', '1000:1000', '--workdir', '/var/www/html', 'bbb222', 'wp', 'option', 'get', 'blogname'],
    ]);
    // Every cli container on the machine is listed, and the runner keeps its own project's.
    expect(CLI_CONTAINERS).toContain('label=com.docker.compose.service=cli');
  });

  it('runs read_file the same way', async () => {
    const { docker, execs } = fakeDocker();
    await readFile(dockerExecRunner('/repo', { docker, site: site() }), 'themes/vwf-fixture');
    expect(execs()).toEqual([execArgv('bbb222', '1000:1000', readerArgv('themes/vwf-fixture'))]);
  });

  it("picks the site's own project with two others on the machine, whichever is listed first", async () => {
    for (const [work, id] of [[WORK, 'bbb222'], ['/home/user/.wp-env/wp-env-snapshot-22222222', 'ccc333'], ['/home/user/.wp-env/wp-env-feature-branch-11111111', 'aaa111']]) {
      const { docker, execs } = fakeDocker();
      await runWp(dockerExecRunner('/repo', { docker, site: site(work) }), 'option get blogname');
      expect(execs()[0][6]).toBe(id);
    }
    // The same answer whatever order docker lists them in.
    const reversed = THREE_SITES.split('\n').reverse().join('\n');
    expect(await findCliContainer(fakeDocker(() => reversed).docker, OURS)).toBe('bbb222');
  });

  it("fails the cell's check when the site has no cli container, naming the project and what it found", async () => {
    const { docker, execs } = fakeDocker(() => 'ccc333 running wp-env-snapshot-22222222');
    await expect(checkContainer(dockerExecRunner('/repo', { docker, site: site() }))).rejects.toThrow(
      `the cli conditions cannot use the wp-env cli container: no cli container in compose project ${OURS} (found: wp-env-snapshot-22222222). Start the site: npx @wordpress/env start`,
    );
    expect(execs()).toEqual([]);
  });

  it('fails a cell whose container is stopped, through the guard, and runs nothing in it', async () => {
    const { docker, execs } = fakeDocker(() => `bbb222 exited ${OURS}\nccc333 running wp-env-snapshot-22222222`);
    const jobs: CellJob[] = [{ condition: 'cli', model: 'claude-haiku-4-5-20251001', rep: 1 }, { condition: 'cli', model: 'claude-haiku-4-5-20251001', rep: 1 }];
    const guard = cellGuard(jobs, dockerExecRunner('/repo', { docker, site: site() }));
    const expected = `cli claude-haiku-4-5-20251001 rep 1: the cli conditions cannot use the wp-env cli container: the cli container of compose project ${OURS} is exited, not running. Start the site: npx @wordpress/env start`;
    await expect(guard.before(jobs[0])).rejects.toThrow(expected);
    await expect(guard.before(jobs[1])).rejects.toThrow(expected);
    expect(execs()).toEqual([]);
  });

  it('names the fix when the pinned @wordpress/env is not installed, as the wp-env runner did', () => {
    expect(() => dockerExecRunner(mkdtempSync(join(tmpdir(), 'vwf-no-wp-env-')))).toThrow('the cli conditions need the pinned @wordpress/env: run npm ci in the repository root');
  });

  it('fails the check when docker itself cannot answer', async () => {
    const docker: Docker = async () => ({ status: 1, stdout: '', stderr: 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock.\n' });
    await expect(checkContainer(dockerExecRunner('/repo', { docker, site: site() }))).rejects.toThrow('docker ps failed: Cannot connect to the Docker daemon');
  });

  it('looks the container up again before every cli cell, so a recreated container is found', async () => {
    let id = 'bbb222';
    const { docker, calls, execs } = fakeDocker(() => `${id} running ${OURS}`);
    const jobs: CellJob[] = [{ condition: 'cli', model: 'claude-haiku-4-5-20251001', rep: 1 }, { condition: 'cli-manual', model: 'claude-haiku-4-5-20251001', rep: 1 }];
    const run = dockerExecRunner('/repo', { docker, site: site() });
    const guard = cellGuard(jobs, run);
    await guard.before(jobs[0]);
    await runWp(run, 'post list');
    id = 'ddd444';
    await guard.before(jobs[1]);
    await runWp(run, 'post list');
    expect(calls.filter((c) => c[0] === 'ps')).toHaveLength(2);
    expect(execs().map((c) => c[6])).toEqual(['bbb222', 'bbb222', 'bbb222', 'ddd444', 'ddd444', 'ddd444']);
  });

  it("reads a failed command the way wp-env run's exit status 1 reads, whatever docker exec's own code", async () => {
    const wpEnvStyle: ContainerRun = async () => ({ status: 1, stdout: '', stderr: "ℹ Starting 'wp post get 5' on the cli container. \nError: Could not find the post with ID 5.\n✖ Command failed with exit code 1\nCommand failed with exit code 1\n" });
    const { docker } = fakeDocker(undefined, () => ({ status: 1, stderr: 'Error: Could not find the post with ID 5.\n' }));
    const exec = dockerExecRunner('/repo', { docker, site: site() });
    const message = (run: ContainerRun) => runWp(run, 'post get 5').catch((e: Error) => e.message);
    expect(await message(exec)).toBe(await message(wpEnvStyle));
    // A silent failure with another code: wp-env run exits 1, so the message says 1 either way.
    const silent = dockerExecRunner('/repo', { docker: fakeDocker(undefined, () => ({ status: 255 })).docker, site: site() });
    expect(await message(silent)).toBe('wp post get 5 failed with exit code 1');
    expect((await silent(['wp', 'post', 'get', '5'])).status).toBe(1);
  });
});

describe('the compose project', () => {
  it("is the work folder's name, as docker compose normalizes it", () => {
    expect(composeProject(WORK, {})).toBe(OURS);
    expect(composeProject('/home/user/.wp-env/wp-env-My.Branch-e79a1a5a', {})).toBe('wp-env-mybranch-e79a1a5a');
    expect(composeProject('/home/user/.wp-env/_-Odd Name', {})).toBe('oddname');
    // compose reads COMPOSE_PROJECT_NAME from the environment wp-env passes on, before the folder name.
    expect(composeProject(WORK, { COMPOSE_PROJECT_NAME: 'mine' })).toBe('mine');
  });

  it('lives in the work folder wp-env names after the config file, as load-config.js does', () => {
    const md5 = (s: string) => (s === '/repo/voyager-wayfinder/.wp-env.json' ? '1a2b3c4d5e6f77889900aabbccddeeff' : 'ffeeddccbbaa00998877665544332211');
    const none = () => false;
    expect(wpEnvWorkDir('/repo/voyager-wayfinder/.wp-env.json', '/home/user/.wp-env', md5, none)).toBe(WORK);
    expect(wpEnvWorkDir('/repo/x/.wp-env.test.json', '/c', md5, none)).toBe('/c/wp-env-x-test-ffeeddcc');
    expect(wpEnvWorkDir('/repo/x/staging site.json', '/c', md5, none)).toBe('/c/wp-env-x-staging-site-ffeeddcc');
    // A work folder from before descriptive names is kept, as wp-env keeps it.
    expect(wpEnvWorkDir('/repo/voyager-wayfinder/.wp-env.json', '/c', md5, (p) => p === '/c/1a2b3c4d5e6f77889900aabbccddeeff')).toBe('/c/1a2b3c4d5e6f77889900aabbccddeeff');
  });
});

describe('container calls run side by side', () => {
  const six = ['a', 'b', 'c', 'd', 'e', 'f'];

  it('six at once through the exec runner, where the wp-env runner ran one', async () => {
    const fast = fakeDocker(undefined, (argv) => ({ status: 0, stdout: argv.at(-1) }), 20);
    const run = dockerExecRunner('/repo', { docker: fast.docker, site: site() });
    expect((await Promise.all(six.map((c) => run(['wp', c])))).map((r) => r.stdout)).toEqual(six);
    expect(fast.most()).toBe(6);
    // The control: the same calls queued as the wp-env runner queues them.
    const queued = fakeDocker(undefined, () => ({ status: 0 }), 20);
    const one = serialize(dockerExecRunner('/repo', { docker: queued.docker, site: site() }));
    await Promise.all(six.map((c) => one(['wp', c])));
    expect(queued.most()).toBe(1);
  });

  it('no more than the cap in config.json', async () => {
    const capped = fakeDocker(undefined, () => ({ status: 0 }), 20);
    const run = dockerExecRunner('/repo', { docker: capped.docker, site: site(), limit: 3 });
    await Promise.all([...six, ...six].map((c) => run(['wp', c])));
    expect(capped.most()).toBe(3);
    expect(capped.execs()).toHaveLength(12);
    const config = JSON.parse(readFileSync(join(BENCH_DIR, 'config.json'), 'utf8'));
    expect(config.cli_exec_limit).toBe(8);
    expect(() => limit(async () => ({ status: 0, stdout: '', stderr: '' }), 0)).toThrow('a container command limit is a whole number of 1 or more, not 0');
  });

  it('a capped call that throws frees its slot for the next', async () => {
    const run = limit(async ([c]) => {
      if (c === 'bad') throw new Error('spawn failed');
      return { status: 0, stdout: c, stderr: '' };
    }, 2);
    const settled = await Promise.allSettled(['bad', 'bad', 'a', 'b'].map((c) => run([c])));
    expect(settled.map((s) => s.status)).toEqual(['rejected', 'rejected', 'fulfilled', 'fulfilled']);
  });
});

/** A stand-in docker CLI: a Node script that logs each call's arguments and its start and end, then answers like WP-CLI. */
function stubDocker(dir: string, ms: number) {
  const log = join(dir, 'calls.jsonl');
  const script = join(dir, 'stub-docker.mjs');
  writeFileSync(script, `
import { appendFileSync } from 'node:fs';
const args = process.argv.slice(2);
if (args[0] === 'ps') { process.stdout.write(process.env.VWF_STUB_PS ?? ''); process.exit(0); }
const start = Date.now();
await new Promise((done) => setTimeout(done, ${ms}));
// docker exec <7 words> <command>, or docker compose -f <file> exec ... cli <command> from wp-env run.
const command = args[0] === 'exec' ? args.slice(7) : args.slice(args.indexOf('cli') + 1);
appendFileSync(${JSON.stringify(log)}, JSON.stringify({ args, start, end: Date.now() }) + '\\n');
if (command.includes('999999')) { process.stderr.write('Error: Could not find the post with ID 999999.\\n'); process.exit(1); }
if (command.includes('999998')) process.exit(3);
process.stdout.write('out: ' + command.join(' ') + '\\n');
`);
  const calls = (): { args: string[]; start: number; end: number }[] =>
    existsSync(log) ? readFileSync(log, 'utf8').trim().split('\n').map((l) => JSON.parse(l)) : [];
  /** The most calls whose start-to-end spans overlapped. */
  const overlap = () => {
    const edges = calls().flatMap((c) => [[c.start, 1], [c.end, -1]]).sort((a, b) => a[0] - b[0] || a[1] - b[1]);
    let [now, most] = [0, 0];
    for (const [, d] of edges) most = Math.max(most, (now += d));
    return most;
  };
  return { script, calls, overlap };
}

describe('through real processes', () => {
  it('spawns the docker CLI once per call, six at once, with the arguments the fakes above expect', async () => {
    const dir = mkdtempSync(join(tmpdir(), 'vwf-exec-'));
    const stub = stubDocker(dir, 600);
    vi.stubEnv('VWF_STUB_PS', `stub0001 running ${OURS}\n`);
    const run = dockerExecRunner('/repo', { docker: dockerCli(process.execPath, [stub.script]), site: site() });
    const lines = PARITY_COMMANDS.slice(0, 6) as readonly string[];
    const out = await Promise.all(lines.map((l) => runWp(run, l)));
    expect(out[0]).toBe('out: wp option get blogname');
    expect(stub.calls().map((c) => c.args.slice(0, 7))).toEqual(lines.map(() => ['exec', '--interactive', '--user', '1000:1000', '--workdir', '/var/www/html', 'stub0001']));
    expect(stub.overlap()).toBeGreaterThan(1);
  }, 30_000);

  it('runs one at a time through the same CLI when queued as the wp-env runner queues', async () => {
    const dir = mkdtempSync(join(tmpdir(), 'vwf-exec-'));
    const stub = stubDocker(dir, 100);
    vi.stubEnv('VWF_STUB_PS', `stub0001 running ${OURS}\n`);
    const run = serialize(dockerExecRunner('/repo', { docker: dockerCli(process.execPath, [stub.script]), site: site() }));
    await Promise.all(PARITY_COMMANDS.slice(0, 6).map((l) => runWp(run, l)));
    expect(stub.calls()).toHaveLength(6);
    expect(stub.overlap()).toBe(1);
  }, 30_000);
});

/** The pinned @wordpress/env at the repository root, which the live runners load. CI's bench job does not install it. */
const REPO = join(BENCH_DIR, '..');
const hasWpEnv = existsSync(join(REPO, 'node_modules', '@wordpress', 'env', 'package.json'));

// The real wp-env 11.16.0 with a stub docker first on PATH, so no Docker is needed. Linux and macOS only: on Windows
// wp-env's spawn finds only docker.exe.
describe.skipIf(!hasWpEnv || process.platform === 'win32')('against the pinned wp-env, with a stub docker on PATH', () => {
  /** A scratch WP_ENV_HOME holding only the cache `wp-env start` leaves in the work folder, and a stub `docker` on PATH. */
  async function scratchSite(ms: number) {
    const dir = mkdtempSync(join(tmpdir(), 'vwf-wpenv-'));
    vi.stubEnv('WP_ENV_HOME', join(dir, 'home'));
    const stub = stubDocker(dir, ms);
    const bin = join(dir, 'bin');
    mkdirSync(bin);
    writeFileSync(join(bin, 'docker'), `#!/bin/sh\nexec ${JSON.stringify(process.execPath)} ${JSON.stringify(stub.script)} "$@"\n`);
    chmodSync(join(bin, 'docker'), 0o755);
    vi.stubEnv('PATH', `${bin}:${process.env.PATH}`);
    const real = wpEnvSite(REPO);
    const workDir = await real.workDir();
    mkdirSync(workDir, { recursive: true });
    // latestWordPressVersion keeps wp-env offline; runtime is what `wp-env start` saves.
    writeFileSync(join(workDir, 'wp-env-cache.json'), JSON.stringify({ runtime: 'docker', latestWordPressVersion: '7.1.2' }));
    vi.stubEnv('VWF_STUB_PS', `stub0001 running ${composeProject(workDir)}\n`);
    return { stub, real, workDir };
  }

  it("execs as the user, in the folder and in the compose file wp-env run uses, and wp-env's own config finds our work folder", async () => {
    const { stub, real, workDir } = await scratchSite(0);
    await runWp(wpEnvRunner(REPO), 'option get blogname');
    await runWp(dockerExecRunner(REPO, { docker: dockerCli(), site: real }), 'option get blogname');
    const [viaWpEnv, viaExec] = stub.calls().map((c) => c.args);
    // wp-env: docker compose -f <work folder>/docker-compose.yml exec -w <dir> --user <user> -T cli <command>.
    expect(viaWpEnv.slice(0, 4)).toEqual(['compose', '-f', join(workDir, 'docker-compose.yml'), 'exec']);
    const flag = (argv: string[], ...names: string[]) => argv[argv.findIndex((a) => names.includes(a)) + 1];
    expect(flag(viaExec, '--user')).toBe(flag(viaWpEnv, '--user'));
    expect(flag(viaExec, '--workdir')).toBe(flag(viaWpEnv, '-w'));
    expect(viaExec.slice(7)).toEqual(viaWpEnv.slice(viaWpEnv.indexOf('cli') + 1));
  }, 60_000);

  it('gives the agent the same text as wp-env run for each parity command, and wp-env ran one at a time', async () => {
    const { stub, real } = await scratchSite(50);
    const exec = dockerExecRunner(REPO, { docker: dockerCli(), site: real });
    const lines = [...PARITY_COMMANDS, 'post get 999998 --field=ID'];
    const report = await execParity({ exec, wpEnv: wpEnvRunner(REPO), calls: lines.length - 1, concurrency: 6 });
    expect(report.mismatches).toEqual([]);
    expect(report.exec.outputs.at(-1)).toBe('error: wp post get 999999 --field=ID: Error: Could not find the post with ID 999999.');
    // The silent failure with exit code 3, which wp-env reports as 1.
    const silent = async (run: ContainerRun) => runWp(run, lines.at(-1)!).catch((e: Error) => e.message);
    expect(await silent(exec)).toBe(await silent(wpEnvRunner(REPO)));
    const wpEnvCalls = stub.calls().filter((c) => c.args[0] === 'compose');
    const edges = wpEnvCalls.flatMap((c) => [[c.start, 1], [c.end, -1]]).sort((a, b) => a[0] - b[0] || a[1] - b[1]);
    let [now, most] = [0, 0];
    for (const [, d] of edges) most = Math.max(most, (now += d));
    expect(most).toBe(1);
  }, 120_000);
});

describe('the parity check', () => {
  const answer = (text: string): ContainerRun => async ([, ...args]) => ({ status: 0, stdout: `${text} ${args.join(' ')}\n`, stderr: '' });

  it('passes when every call matches and none says "Environment not initialized"', async () => {
    const report = await execParity({ exec: answer('same'), wpEnv: answer('same'), calls: 24, concurrency: 6 });
    expect(report.calls).toBe(24);
    expect(report.mismatches).toEqual([]);
    expect(parityHolds(report)).toBe(true);
    expect(renderParity(report)).toMatch(/Outputs: all 24 identical\.\n\nParity holds\.$/);
  });

  it('fails on one differing output, and on the error run 5 died of', async () => {
    let n = 0;
    const flaky: ContainerRun = async (command) => (++n === 5 ? { status: 0, stdout: 'other\n', stderr: '' } : answer('same')(command));
    const differs = await execParity({ exec: flaky, wpEnv: answer('same'), calls: 12, concurrency: 1 });
    // The container check takes the first two calls.
    expect(differs.mismatches).toEqual([2]);
    expect(renderParity(differs)).toContain('Outputs: 1 of 12 differ.\n\nCall 3, wp post list');
    // Outputs that match because both runners broke: the exec runner passed its check, then said what run 5's runner said.
    const INIT = { status: 1, stdout: '', stderr: 'Environment not initialized. Run `wp-env start` first.\n' };
    let m = 0;
    const breaksAfterCheck: ContainerRun = async (command) => (++m <= 2 ? answer('same')(command) : INIT);
    const broken = await execParity({ exec: breaksAfterCheck, wpEnv: async () => INIT, calls: 12, concurrency: 6 });
    expect(broken.mismatches).toEqual([]);
    expect(broken.exec.init_errors).toBe(12);
    expect(parityHolds(broken)).toBe(false);
    expect(renderParity(broken)).toMatch(/Parity FAILS\.$/);
  });
});
