import { pool } from './run.ts';
import { checkContainer, runWp, type ContainerRun } from './wpcli.ts';

// `--exec-parity`: the same read-only `wp` calls through the docker exec runner a live run uses and through
// the queued `wp-env run` runner it replaced, on the running site. It checks the two return the same text for every
// call, counts "Environment not initialized", and times both. No model and no API key.

/**
 * Read-only commands whose output depends only on the site, on any fixture. The last fails on purpose, so a failed
 * command's text is compared too.
 */
export const PARITY_COMMANDS = [
  'option get blogname',
  'option get stylesheet',
  'post list --post_type=page --fields=ID,post_name --orderby=ID --order=ASC',
  'post-type list --fields=name --format=csv',
  'taxonomy list --fields=name --format=csv',
  'menu list --fields=term_id,name',
  'menu location list',
  'sidebar list --fields=id',
  'role list --fields=role',
  'cap list administrator',
  'rewrite list --format=count',
  'post get 999999 --field=ID',
] as const;

const INIT = 'Environment not initialized';

export interface ParityRun {
  runner: string;
  wall_ms: number;
  /** Each call's own time, in call order. */
  call_ms: number[];
  /** What the agent would read for each call: the tool's text, or `error: <message>`. */
  outputs: string[];
  errors: number;
  init_errors: number;
}

/** `calls` through `run` with `concurrency` in flight, as the `wp` tool runs them. */
export async function timeCalls(runner: string, run: ContainerRun, calls: string[], concurrency: number): Promise<ParityRun> {
  const call_ms: number[] = new Array(calls.length);
  const start = performance.now();
  const outputs = await pool(calls.map((line, i) => ({ line, i })), concurrency, async ({ line, i }) => {
    const t = performance.now();
    try {
      return await runWp(run, line);
    } catch (error) {
      return `error: ${(error as Error).message}`;
    } finally {
      call_ms[i] = performance.now() - t;
    }
  });
  const errors = outputs.filter((o) => o.startsWith('error: '));
  return { runner, wall_ms: performance.now() - start, call_ms, outputs, errors: errors.length, init_errors: errors.filter((o) => o.includes(INIT)).length };
}

export interface ParityReport {
  calls: number;
  concurrency: number;
  exec: ParityRun;
  wp_env: ParityRun;
  /** Call indexes whose two outputs differ. */
  mismatches: number[];
}

/**
 * Checks the exec runner's container, then runs `calls` commands (PARITY_COMMANDS in turn) through each runner. The
 * wp-env runner queues its calls one at a time whatever `concurrency` is, as it has since run 6.
 */
export async function execParity(opts: { exec: ContainerRun; wpEnv: ContainerRun; calls?: number; concurrency?: number }): Promise<ParityReport> {
  const calls = Array.from({ length: opts.calls ?? 72 }, (_, i) => PARITY_COMMANDS[i % PARITY_COMMANDS.length]);
  const concurrency = opts.concurrency ?? 6;
  await checkContainer(opts.exec);
  const exec = await timeCalls('docker exec', opts.exec, calls, concurrency);
  const wp_env = await timeCalls('wp-env run (queued)', opts.wpEnv, calls, concurrency);
  const mismatches = calls.map((_, i) => i).filter((i) => exec.outputs[i] !== wp_env.outputs[i]);
  return { calls: calls.length, concurrency, exec, wp_env, mismatches };
}

/** True when the exec runner matched wp-env on every call and never said "Environment not initialized". */
export const parityHolds = (r: ParityReport): boolean => r.mismatches.length === 0 && r.exec.init_errors === 0;

const seconds = (ms: number) => `${(ms / 1000).toFixed(2)} s`;
const mean = (xs: number[]) => xs.reduce((a, b) => a + b, 0) / Math.max(1, xs.length);

export function renderParity(r: ParityReport): string {
  const row = (x: ParityRun) =>
    `| ${x.runner} | ${seconds(x.wall_ms)} | ${seconds(x.wall_ms / r.calls)} | ${seconds(mean(x.call_ms))} | ${x.errors} | ${x.init_errors} |`;
  const lines = [
    `Exec parity: ${r.calls} wp calls, ${r.concurrency} in flight.`,
    '',
    '| Runner | Wall time | Wall time per call | Mean call, queueing included | Error results | "Environment not initialized" |',
    '|---|---|---|---|---|---|',
    row(r.exec),
    row(r.wp_env),
    '',
    r.mismatches.length ? `Outputs: ${r.mismatches.length} of ${r.calls} differ.` : `Outputs: all ${r.calls} identical.`,
  ];
  for (const i of r.mismatches.slice(0, 5)) {
    lines.push('', `Call ${i + 1}, wp ${PARITY_COMMANDS[i % PARITY_COMMANDS.length]}:`, `docker exec: ${JSON.stringify(r.exec.outputs[i])}`, `wp-env run:  ${JSON.stringify(r.wp_env.outputs[i])}`);
  }
  lines.push('', parityHolds(r) ? 'Parity holds.' : 'Parity FAILS.');
  return lines.join('\n');
}
