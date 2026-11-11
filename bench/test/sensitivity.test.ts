import { existsSync, mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { buildReport, ReportSchema, Run3Rules, writeReport, type ReportMeta, type Result } from '../src/report.ts';
import { BENCH_DIR, repetitionsFor, runBench } from '../src/run.ts';
import { checkReference, renderBestBaseline, turnCapSensitivity } from '../src/sensitivity.ts';

afterEach(() => vi.unstubAllGlobals());

const HAIKU = 'claude-haiku-4-5-20251001';
const SONNET = 'claude-sonnet-5';
const RULES = Run3Rules.parse(JSON.parse(readFileSync(join(BENCH_DIR, 'config.json'), 'utf8')).run3_hypotheses);

const META: ReportMeta = {
  format: 'wayfinder-bench-report/1', site: 'fixture', date: '2026-10-03', run_label: 'run3', fixture: 'block', hypothesis_set: 'run3',
  conversation_caching: true, dry_run: false, runner: { where: 'local', concurrency: 6 },
  question_set: { file: 'questions/fixture.yaml', sha256: 'abc', count: 10 }, pricing: { source: 'test', fetched: '2026-10-01' },
  models: [{ id: HAIKU, params: { temperature: 0 }, cache_min_tokens: 4096 }, { id: SONNET, params: {}, cache_min_tokens: 1024 }],
  manual: { brief_chars: 1790, index_chars: 8681, brief_est_tokens: 448, index_est_tokens: 2171 },
};

/** One question run. A run that hit the cap is wrong, as the harness scores it. */
function row(question: string, condition: string, model: string, rep: number, correct: boolean, capped = false): Result {
  return {
    question, category: 'content_model', condition, model, rep, correct: correct && !capped, reason: capped ? 'turn cap reached with no answer' : correct ? 'matches' : 'expected "6"',
    answer: '', usage: { input_tokens: 1000, cache_creation_input_tokens: 0, cache_read_input_tokens: 0, output_tokens: 10 }, first_call_input_tokens: 1000,
    tool_calls: 1, turns: 2, hit_turn_cap: capped, wall_ms: 1000, cost_usd: 0.01, judge_cost_usd: 0,
  };
}

/** `correct` of 10 questions right in each of `reps` passes. */
const cell = (condition: string, model: string, correct: number, reps = 1): Result[] =>
  Array.from({ length: reps }, (_, r) => Array.from({ length: 10 }, (_, i) => row(`q-${i + 1}`, condition, model, r + 1, i < correct))).flat();

describe('repetitions', () => {
  it('repeat every cell R1 to R7 read by default, and every cell once with --repetitions 1', () => {
    const reps = repetitionsFor(RULES, [HAIKU, SONNET]);
    expect([reps('adapter', SONNET), reps('manual', HAIKU), reps('manual-preloaded', HAIKU), reps('raw', SONNET), reps('manual-preloaded', SONNET)]).toEqual([3, 3, 3, 1, 1]);
    const once = repetitionsFor(RULES, [HAIKU, SONNET], 1);
    expect([once('adapter', SONNET), once('manual', HAIKU), once('raw', HAIKU)]).toEqual([1, 1, 1]);
  });
});

describe('turn cap sensitivity', () => {
  // Reference adapter Haiku, three passes over q1 to q4: q1 always right; q2 always at the cap; q3 always wrong
  // with an answer; q4 right, then at the cap, then wrong. At 24 turns q1 reaches the cap and q2 to q4 are right.
  const reference = buildReport([
    ...[1, 2, 3].flatMap((rep) => [row('q1', 'adapter', HAIKU, rep, true), row('q2', 'adapter', HAIKU, rep, false, true), row('q3', 'adapter', HAIKU, rep, false)]),
    row('q4', 'adapter', HAIKU, 1, true), row('q4', 'adapter', HAIKU, 2, false, true), row('q4', 'adapter', HAIKU, 3, false),
    row('q1', 'raw', HAIKU, 1, true), row('q2', 'raw', HAIKU, 1, false, true),
  ], META, RULES);
  const run = buildReport([
    row('q1', 'adapter', HAIKU, 1, false, true), row('q2', 'adapter', HAIKU, 1, true), row('q3', 'adapter', HAIKU, 1, true), row('q4', 'adapter', HAIKU, 1, true),
  ], { ...META, run_label: 'run3-sensitivity', max_turns: 24, runner: { where: 'github-actions', concurrency: 6 } }, RULES);

  it('counts cap-hit misses, conversions and every other movement in reference question runs', () => {
    const x = turnCapSensitivity({ file: 'reports/fixture-2026-10-03-run3.json', report: reference }, run);
    expect(x).toMatchObject({ reference: { file: 'reports/fixture-2026-10-03-run3.json', max_turns: 12, runner: 'local' }, max_turns: 24, runner: 'github-actions' });
    expect(x.cells).toEqual([{
      condition: 'adapter', model: HAIKU,
      reference: { reps: 3, question_runs: 12, correct: 4, accuracy: 0.3333, cap_hits: 4, cost_usd: 0.12, cost_per_correct_usd: 0.03 },
      this_run: { reps: 1, question_runs: 4, correct: 3, accuracy: 0.75, cap_hits: 1, cost_usd: 0.04, cost_per_correct_usd: 0.013333 },
      cap_hit_misses: 4, converted: 4, other_misses_now_correct: 4, correct_now_wrong: 3,
    }]);
    // The movement adds up: 4 correct + 4 converted + 4 other - 3 lost = 9 = 3 passes x 3 correct now.
    const c = x.cells[0];
    expect(c.reference.correct + c.converted + c.other_misses_now_correct - c.correct_now_wrong).toBe(c.reference.reps * c.this_run.correct);
  });

  it('refuses a reference from another fixture, question set or the same cap, and a run with two answers per question', () => {
    const ref = { file: 'r.json', report: reference };
    expect(() => checkReference(ref, { fixture: 'classic', sha256: 'abc', maxTurns: 24 })).toThrow('is the block fixture, this run is classic');
    expect(() => checkReference(ref, { fixture: 'block', sha256: 'def', maxTurns: 24 })).toThrow('asked question set abc, this run def');
    expect(() => checkReference(ref, { fixture: 'block', sha256: 'abc', maxTurns: 12 })).toThrow('already ran at 12 turns');
    expect(checkReference(ref, { fixture: 'block', sha256: 'abc', maxTurns: 24 })).toBe(12);
    const twice = buildReport([...run.results, { ...run.results[1], rep: 2 }], { ...META, max_turns: 24 }, RULES);
    expect(() => turnCapSensitivity(ref, twice)).toThrow('answered q2 more than once');
    const unasked = buildReport([row('q1', 'manual', HAIKU, 1, true)], { ...META, max_turns: 24 }, RULES);
    expect(() => turnCapSensitivity(ref, unasked)).toThrow('r.json has no manual claude-haiku-4-5-20251001 results to compare with');
  });

  it('writes only the dated report, leaving <site>-latest as the last full run', () => {
    const out = mkdtempSync(join(tmpdir(), 'vwf-sens-'));
    const report = ReportSchema.parse({ ...run, sensitivity: turnCapSensitivity({ file: 'r.json', report: reference }, run) });
    expect(writeReport(report, out).map((p) => p.slice(out.length + 1))).toEqual(['fixture-2026-10-03-run3-sensitivity.json', 'fixture-2026-10-03-run3-sensitivity.md']);
    expect(existsSync(join(out, 'fixture-latest.json'))).toBe(false);
    const md = readFileSync(join(out, 'fixture-2026-10-03-run3-sensitivity.md'), 'utf8');
    expect(md.indexOf('## Turn cap sensitivity')).toBeLessThan(md.indexOf('## Hypotheses'));
    expect(md).toContain('| adapter | claude-haiku-4-5-20251001 | 3 | 33.3% (4/12) | 75.0% (3/4) | 4 of 8 | 4 | 4 | 3 | 1 of 4 (25.0%) |');
    expect(md).toContain('Where each ran: this run on GitHub Actions runner, wp-env; the reference on local wp-env.');
    expect(md).toContain('Runs that still reached the 24-turn cap: adapter claude-haiku-4-5-20251001 1 of 4 (25.0%).');
    expect(md).toContain('At most 24 model calls per question.');
  });
});

describe('best baseline per cell', () => {
  // Sonnet raw beats Sonnet adapter at 12 turns (90% against 80%) and adapter leads at 24 (90% against 80%), so
  // manual's 93.3% clears the best baseline by 3.3 points at both caps. Haiku's raw and adapter tie at 24.
  const reference = buildReport([
    ...cell('raw', HAIKU, 5), ...cell('adapter', HAIKU, 6, 3), ...cell('manual', HAIKU, 10, 3), ...cell('manual-preloaded', HAIKU, 9, 3),
    ...cell('raw', SONNET, 9), ...cell('adapter', SONNET, 8, 3), ...cell('manual', SONNET, 9, 2), ...cell('manual', SONNET, 10).map((r) => ({ ...r, rep: 3 })),
    ...cell('manual-preloaded', SONNET, 9),
  ], META, RULES);
  const at24 = buildReport([
    ...cell('raw', HAIKU, 6), ...cell('adapter', HAIKU, 6),
    ...cell('raw', SONNET, 8), ...cell('adapter', SONNET, 8).map((r) => (r.question === 'q-10' ? { ...r, correct: true } : r)),
  ], { ...META, run_label: 'run3-sensitivity', max_turns: 24 }, RULES);
  const report = ReportSchema.parse({ ...at24, sensitivity: turnCapSensitivity({ file: 'reports/fixture-2026-10-03-run3.json', report: reference }, at24) });
  const md = renderBestBaseline([{ file: 'reports/fixture-2026-10-03-run3-sensitivity.json', report, reference }], RULES);

  it('takes the higher of raw and adapter at each cap, beside the Wayfinder cells', () => {
    expect(md.startsWith('## Best baseline per cell\n')).toBe(true);
    expect(md).toContain(`| block | ${SONNET} | 90.0% | 80.0% | raw 90.0% | 80.0% | 90.0% | adapter 90.0% | 93.3% | 90.0% |`);
    expect(md).toContain(`| block | ${HAIKU} | 50.0% | 60.0% | adapter 60.0% | 60.0% | 60.0% | raw and adapter 60.0% | 100.0% | 90.0% |`);
    expect(md).toContain('- block: the Wayfinder cells are run 3\'s, at 12 turns on local wp-env, and reached the cap in 0 of 100 runs.');
    expect(md).toContain(`The baseline cells at 24 turns ran on local wp-env and still reached the cap in: adapter ${HAIKU} 0 of 10 (0.0%), adapter ${SONNET} 0 of 10 (0.0%), raw ${HAIKU} 0 of 10 (0.0%), raw ${SONNET} 0 of 10 (0.0%).`);
  });

  it('restates R2 and R3 against that best baseline at both caps', () => {
    expect(md).toContain(`| block | ${SONNET} | 93.3% | raw 90.0% | +3.3 | adapter 90.0% | +3.3 |`);
    expect(md).toContain('Against the best baseline at 12 turns: **fail**. At 24 turns: **fail**.');
    expect(md).toContain(`| block | 90.0% | raw 90.0% | pass | adapter 90.0% | pass |`);
    expect(md).toContain('Against the best baseline at 12 turns: **pass**. At 24 turns: **pass**.');
  });

  it('refuses a report with no sensitivity block', () => {
    expect(() => renderBestBaseline([{ file: 'x.json', report: at24, reference }], RULES)).toThrow('x.json carries no turn cap sensitivity');
  });
});

describe('a sensitivity dry run against the run 3 report', () => {
  it('runs the baseline cells at 24 turns, sets them beside run 3 and leaves the latest report alone', async () => {
    vi.stubGlobal('fetch', () => Promise.reject(new Error('network call in a dry run')));
    const out = mkdtempSync(join(tmpdir(), 'vwf-sens-'));
    const { report, paths } = await runBench({
      dryRun: true, out, date: '2026-10-03', conditions: ['raw', 'adapter'], maxTurns: 24, repetitions: 1,
      runLabel: 'run3-sensitivity', sensitivityOf: 'reports/fixture-2026-10-03-run3.json',
    });
    expect(paths.map((p) => p.slice(out.length + 1))).toEqual(['fixture-2026-10-03-run3-sensitivity.json', 'fixture-2026-10-03-run3-sensitivity.md']);
    expect(report.max_turns).toBe(24);
    expect(report.sensitivity?.cells.map((c) => [c.condition, c.model, c.this_run.question_runs, c.reference.reps])).toEqual([
      ['adapter', HAIKU, 1, 3], ['raw', HAIKU, 2, 1], ['raw', SONNET, 1, 1],
    ]);
  });

  it('caps a replay at the turns it is given', async () => {
    vi.stubGlobal('fetch', () => Promise.reject(new Error('network call in a dry run')));
    const { report } = await runBench({ dryRun: true, out: mkdtempSync(join(tmpdir(), 'vwf-sens-')), date: '2026-10-03', maxTurns: 1 });
    expect(report.max_turns).toBe(1);
    const capped = report.results.filter((r) => r.hit_turn_cap);
    expect(capped.length).toBeGreaterThan(0);
    expect(capped.every((r) => r.turns === 1 && !r.correct && r.reason === 'turn cap reached with no answer')).toBe(true);
  });

  it('stops before any call when the cap or the repetitions are not whole numbers', async () => {
    await expect(runBench({ dryRun: true, maxTurns: 0 })).rejects.toThrow('--max-turns must be a whole number of at least 1, not 0');
    await expect(runBench({ dryRun: true, repetitions: 1.5 })).rejects.toThrow('--repetitions must be a whole number of at least 1, not 1.5');
  });
});
