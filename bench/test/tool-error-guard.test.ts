import type Anthropic from '@anthropic-ai/sdk';
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Turn } from '../src/agent.ts';
import { BENCH_DIR, pool, runBench } from '../src/run.ts';
import { breaches, cellGuard, TOOL_ERROR_LIMIT, toolTally, type CellJob } from '../src/toolErrors.ts';
import { serialize, type ContainerRun } from '../src/wpcli.ts';
import { fakeClient, message, text, toolUse } from './helpers.ts';

// A live run writes its transcripts to runs/<timestamp>; the live tests below remove the folders they add.
const RUNS = join(BENCH_DIR, 'runs');
const runFolders = () => (existsSync(RUNS) ? readdirSync(RUNS) : []);
let before: string[] = [];
beforeEach(() => {
  before = runFolders();
});
afterEach(() => {
  vi.unstubAllGlobals();
  for (const added of runFolders().filter((f) => !before.includes(f))) rmSync(join(RUNS, added), { recursive: true, force: true });
});

const INIT = 'Environment not initialized. Run `wp-env start` first.';

/** One question run's turns: each call a tool_use and its result, `error` text making it an error result. */
function turns(calls: { name: string; error?: string }[]): Turn[] {
  return calls.map((call, i) => ({
    response: message([{ type: 'tool_use', id: `toolu_${i}`, name: call.name, input: {} }], 'tool_use'),
    tool_results: [{ type: 'tool_result', tool_use_id: `toolu_${i}`, content: call.error ?? 'ok', ...(call.error && { is_error: true }) } as Anthropic.ToolResultBlockParam],
  }));
}
const times = (n: number, call: { name: string; error?: string }) => Array.from({ length: n }, () => call);
const job = (condition: CellJob['condition'], model = 'claude-sonnet-5', rep = 1): CellJob => ({ condition, model, rep });

describe('the tool error guard', () => {
  it('fails a cell whose transcripts carry "Environment not initialized" on 30 of 30 calls', () => {
    const cell = [job('cli'), job('cli'), job('cli')];
    const guard = cellGuard(cell);
    const broken = turns(times(10, { name: 'wp', error: INIT }));
    // The cell is judged when its last question run is in.
    guard.after(cell[0], broken);
    guard.after(cell[1], broken);
    expect(() => guard.after(cell[2], broken)).toThrow(
      'tool error guard: the cli claude-sonnet-5 rep 1 cell failed.\n'
      + 'all tools errored on 30 of 30 calls (limit: more than 20% of at least 10).\n'
      + `wp errored on 30 of 30 calls (limit: more than 50% of at least 10). First error: ${INIT}`,
    );
  });

  it('names the cell as a whole first, then every broken tool, worst first', () => {
    const cell = [job('cli')];
    const guard = cellGuard(cell);
    expect(() => guard.after(cell[0], turns([...times(10, { name: 'read_file', error: INIT }), ...times(20, { name: 'wp', error: INIT }), ...times(10, { name: 'http_get' })])))
      .toThrow(/\nall tools errored on 30 of 40 calls[^\n]*\nread_file errored on 10 of 10 calls[^\n]*First error: Environment not initialized[^\n]*\nwp errored on 20 of 20 calls/);
  });

  it('passes a working cell, refusals and WP-CLI errors included', () => {
    const cell = [job('cli'), job('cli'), job('cli')];
    const guard = cellGuard(cell);
    const working = turns([...times(8, { name: 'wp' }), { name: 'wp', error: 'refused: option get reads only blogname' }, { name: 'read_file' }]);
    for (const j of cell) expect(() => guard.after(j, working)).not.toThrow();
    expect(toolTally(working)).toEqual({ read_file: { calls: 1, errors: 0 }, wp: { calls: 9, errors: 1 } });
  });

  it("counts per tool, so a cell's working tools cannot hide a dead one", () => {
    // Run 5's edit-safety-2 cli-manual Sonnet: 14% of the cell's calls erred, and every one of its container calls.
    const cell = [job('cli-manual')];
    const run = turns([...times(10, { name: 'wp', error: INIT }), ...times(60, { name: 'manual_section' })]);
    const tally = toolTally(run);
    expect(tally.wp.errors / 70).toBeLessThan(TOOL_ERROR_LIMIT.cell_share);
    expect(breaches(tally)).toEqual([{ scope: 'wp', calls: 10, errors: 10 }]);
    expect(() => cellGuard(cell).after(cell[0], run)).toThrow('wp errored on 10 of 10 calls');
  });

  it('fails a cell over 20% of at least 10 calls, or a tool over half of at least 10 of its own', () => {
    const wp = (calls: number, errors: number) => ({ wp: { calls, errors } });
    expect(breaches(wp(9, 9))).toEqual([]);
    expect(breaches(wp(10, 2))).toEqual([]);
    expect(breaches(wp(10, 3))).toEqual([{ scope: 'all tools', calls: 10, errors: 3 }]);
    expect(breaches(wp(10, 6))).toEqual([{ scope: 'all tools', calls: 10, errors: 6 }, { scope: 'wp', calls: 10, errors: 6 }]);
    // Haiku's healthy wp in run 5: 15 of 67 calls erred (bad cap list arguments, refused options), 22%, in a cell under 10%.
    expect(breaches({ http_get: { calls: 50, errors: 0 }, read_file: { calls: 40, errors: 0 }, wp: { calls: 67, errors: 15 } })).toEqual([]);
  });

  it('applies to every condition, not only the cli ones', () => {
    const cell = [job('adapter')];
    expect(() => cellGuard(cell).after(cell[0], turns(times(12, { name: 'mcp-adapter-execute-ability', error: 'HTTP 500' })))).toThrow('the adapter claude-sonnet-5 rep 1 cell failed');
  });
});

/** A cli container that records every command and answers with `reply`. */
function fakeContainer(reply: () => { status: number; stdout?: string; stderr?: string } = () => ({ status: 0, stdout: 'ok\n' })) {
  const seen: string[][] = [];
  const run: ContainerRun = async (command) => {
    seen.push(command);
    return { stdout: '', stderr: '', ...reply() };
  };
  return { run, seen };
}

describe('the container check before every cell', () => {
  it('checks once per cli cell, before its first question run, and never for a cell without the container', async () => {
    const jobs = [
      job('raw'), job('raw'),
      job('cli', 'claude-haiku-4-5-20251001', 1), job('cli', 'claude-haiku-4-5-20251001', 1), job('cli', 'claude-haiku-4-5-20251001', 1),
      job('cli', 'claude-haiku-4-5-20251001', 2), job('cli-manual'), job('cli-manual'),
    ];
    const { run, seen } = fakeContainer();
    const guard = cellGuard(jobs, run);
    await Promise.all(jobs.map((j) => guard.before(j)));
    // Three cells, two commands each: `wp option get blogname` and a `themes` listing.
    expect(seen.filter((c) => c[0] === 'wp')).toHaveLength(3);
    expect(seen).toHaveLength(6);
  });

  it('fails every question run of a cell whose container is down, naming the cell', async () => {
    const jobs = [job('cli'), job('cli')];
    const { run, seen } = fakeContainer(() => ({ status: 1, stderr: `${INIT}\n` }));
    const guard = cellGuard(jobs, run);
    const expected = `cli claude-sonnet-5 rep 1: the cli conditions cannot use the wp-env cli container: wp option get blogname: ${INIT}`;
    await expect(guard.before(jobs[0])).rejects.toThrow(expected);
    await expect(guard.before(jobs[1])).rejects.toThrow(expected);
    expect(seen).toHaveLength(1);
  });

  it('checks nothing in a dry run, which has no container', async () => {
    const jobs = [job('cli')];
    await expect(cellGuard(jobs).before(jobs[0])).resolves.toBeUndefined();
  });
});

describe('the wp-env runner', () => {
  it('runs one command at a time, because concurrent wp-env runs corrupt its cache', async () => {
    let inFlight = 0;
    let most = 0;
    const order: string[] = [];
    const slow: ContainerRun = async ([command]) => {
      most = Math.max(most, ++inFlight);
      await new Promise((done) => setTimeout(done, 5));
      inFlight--;
      order.push(command);
      return { status: 0, stdout: command, stderr: '' };
    };
    const run = serialize(slow);
    const out = await Promise.all(['a', 'b', 'c', 'd', 'e', 'f'].map((c) => run([c])));
    expect(most).toBe(1);
    expect(order).toEqual(['a', 'b', 'c', 'd', 'e', 'f']);
    expect(out.map((r) => r.stdout)).toEqual(['a', 'b', 'c', 'd', 'e', 'f']);
    // The positive control: unserialized, the same six run at once.
    most = 0;
    await Promise.all(['a', 'b', 'c', 'd', 'e', 'f'].map((c) => slow([c])));
    expect(most).toBe(6);
  });

  it('keeps going after a command that throws', async () => {
    const run = serialize(async ([command]) => {
      if (command === 'bad') throw new Error('spawn failed');
      return { status: 0, stdout: command, stderr: '' };
    });
    const [bad, good] = await Promise.allSettled([run(['bad']), run(['good'])]);
    expect(bad).toMatchObject({ status: 'rejected', reason: new Error('spawn failed') });
    expect(good).toMatchObject({ status: 'fulfilled', value: { stdout: 'good' } });
  });
});

describe('a live run', () => {
  /** A site that serves the manual, a container whose first `healthy` commands work, and one cli question. */
  function live(healthy: number) {
    vi.stubGlobal('fetch', async () => new Response('ok', { status: 200 }));
    const seen: string[][] = [];
    const container: ContainerRun = async (command) => {
      seen.push(command);
      return seen.length <= healthy ? { status: 0, stdout: 'ok\n', stderr: '' } : { status: 1, stdout: '', stderr: `${INIT}\n` };
    };
    // Eleven wp calls, then an answer.
    const { client, calls } = fakeClient(...Array.from({ length: 11 }, () => toolUse('wp', { args: 'post list' })), text('ANSWER: service'));
    const run = () => runBench({
      conditions: ['cli'], models: ['claude-haiku-4-5-20251001'], questions: ['cm-01'], repetitions: 1, credentials: 'admin:abc',
      container, client, out: mkdtempSync(join(tmpdir(), 'vwf-guard-live-')), date: '2026-10-07',
    });
    return { run, seen, calls };
  }

  it('checks the container again before the cell, after the run-wide check', async () => {
    // The run-wide check takes two commands and passes; the cell's check is the next two, and fails.
    const { run, seen, calls } = live(2);
    await expect(run()).rejects.toThrow(`cli claude-haiku-4-5-20251001 rep 1: the cli conditions cannot use the wp-env cli container: wp option get blogname: ${INIT}`);
    expect(seen).toHaveLength(3);
    expect(calls).toHaveLength(0);
  });

  it('fails the run when the shell breaks inside a cell', async () => {
    // Both checks pass, then every wp call returns the run 5 error.
    const { run, seen, calls } = live(4);
    await expect(run()).rejects.toThrow(
      'tool error guard: the cli claude-haiku-4-5-20251001 rep 1 cell failed.\n'
      + 'all tools errored on 11 of 11 calls (limit: more than 20% of at least 10).\n'
      + `wp errored on 11 of 11 calls (limit: more than 50% of at least 10). First error: wp post list: ${INIT}`,
    );
    expect(seen).toHaveLength(4 + 11);
    expect(calls).toHaveLength(12);
  });

  it('checks the container once more after the last cell', async () => {
    // Every question's calls work; the shell is gone by the end.
    const { run, seen } = live(4 + 11);
    await expect(run()).rejects.toThrow(`after the last cell: the cli conditions cannot use the wp-env cli container: wp option get blogname: ${INIT}`);
    expect(seen).toHaveLength(4 + 11 + 1);
  });

  it('passes a working cell and records its tool calls', async () => {
    const { run, seen } = live(Infinity);
    const { report } = await run();
    expect(report.runs.map((s) => [s.condition, s.tools])).toEqual([['cli', { wp: { calls: 11, errors: 0 } }]]);
    // Checks before the run, before the cell and after it, two commands each, around the eleven calls.
    expect(seen).toHaveLength(2 + 2 + 11 + 2);
  });
});

describe('a run that fails a cell', () => {
  it('starts no new question run after the failure', async () => {
    const started: number[] = [];
    const run = pool([1, 2, 3, 4, 5, 6, 7, 8], 2, async (i) => {
      started.push(i);
      await new Promise((done) => setTimeout(done, 1));
      if (i === 3) throw new Error('cell failed');
      return i;
    });
    await expect(run).rejects.toThrow('cell failed');
    await new Promise((done) => setTimeout(done, 20));
    expect(started).toEqual([1, 2, 3, 4]);
  });

  it('records tool calls and tool errors per question run and per cell in the report', async () => {
    vi.stubGlobal('fetch', () => Promise.reject(new Error('network call in a dry run')));
    const out = mkdtempSync(join(tmpdir(), 'vwf-guard-'));
    const { report } = await runBench({ dryRun: true, conditions: ['cli', 'cli-manual'], out, date: '2026-10-07' });
    // The cli recording has one refused wp call (test/cli.condition.test.ts checks it is the guard's own words).
    expect(report.results.map((r) => [r.condition, r.tool_calls, r.tool_errors, r.tools])).toEqual([
      ['cli-manual', 2, 0, { manual_index: { calls: 1, errors: 0 }, read_file: { calls: 1, errors: 0 } }],
      ['cli', 3, 1, { read_file: { calls: 1, errors: 0 }, wp: { calls: 2, errors: 1 } }],
    ]);
    expect(report.runs.map((s) => [s.condition, s.tools])).toEqual([
      ['cli', { read_file: { calls: 1, errors: 0 }, wp: { calls: 2, errors: 1 } }],
      ['cli-manual', { manual_index: { calls: 1, errors: 0 }, read_file: { calls: 1, errors: 0 } }],
    ]);
    const md = readFileSync(join(out, 'fixture-latest.md'), 'utf8');
    expect(md).toContain('## Tool errors');
    expect(md).toContain('| cli | claude-haiku-4-5-20251001 | 1 | 3 | 1 | wp 1/2 | within |');
    expect(md).toContain('| cli-manual | claude-sonnet-5 | 1 | 2 | 0 | none | within |');
  });
});
