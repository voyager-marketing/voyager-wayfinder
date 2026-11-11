import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { buildReport, findLosses, judgeRun3, renderMarkdown, run3Cells, Run3Rules, type ReportMeta, type Result } from '../src/report.ts';
import { BENCH_DIR } from '../src/run.ts';
import { combine, renderRun3Summary } from '../src/summary.ts';

const HAIKU = 'claude-haiku-4-5-20251001';
const SONNET = 'claude-sonnet-5';

const RULES: Run3Rules = {
  r1_min_accuracy: { block: 0.9, classic: 0.85 },
  r2_min_gain_points: 10,
  r3: { challenger: { condition: 'manual-preloaded', model: HAIKU }, baseline: { condition: 'adapter', model: SONNET } },
  r4_max_est_tokens: { brief: 600, index: 4000 },
  r5_min_total_input_ratio: 3,
  r6_min_p90_input_ratio: 3,
  r7_min_cost_per_correct_ratio: 10,
  baseline: 'adapter',
  repetitions: 3,
};

const META: ReportMeta = {
  format: 'wayfinder-bench-report/1',
  site: 'fixture',
  date: '2026-10-03',
  run_label: 'run3',
  fixture: 'block',
  hypothesis_set: 'run3',
  conversation_caching: true,
  dry_run: false,
  runner: { where: 'local', concurrency: 6 },
  question_set: { file: 'questions/fixture.yaml', sha256: 'x', count: 10 },
  pricing: { source: 'test', fetched: '2026-10-01' },
  models: [
    { id: HAIKU, params: { temperature: 0 }, cache_min_tokens: 4096 },
    { id: SONNET, params: {}, cache_min_tokens: 1024 },
  ],
  manual: { brief_chars: 1790, index_chars: 8681, brief_est_tokens: 448, index_est_tokens: 2171 },
};

/** `correct` of 10 questions right (the first five content_model, the rest edit_location), each reading `input` tokens at $1 per million. */
function cell(condition: string, model: string, correct: number, input: number, rep = 1): Result[] {
  return Array.from({ length: 10 }, (_, i) => ({
    question: `q-${String(i + 1).padStart(2, '0')}`, category: i < 5 ? 'content_model' : 'edit_location', condition, model, rep,
    correct: i < correct, reason: i < correct ? 'matches' : 'expected "6"', answer: i < correct ? '6' : '8',
    usage: { input_tokens: input / 2, cache_creation_input_tokens: input / 4, cache_read_input_tokens: input / 4, output_tokens: 50 },
    first_call_input_tokens: 3000, tool_calls: 1, turns: 2, hit_turn_cap: false, wall_ms: 1000, cost_usd: input / 1_000_000, judge_cost_usd: 0,
  }));
}

const reps = (condition: string, model: string, correct: number[], input: number) => correct.flatMap((c, i) => cell(condition, model, c, input, i + 1));

/** Run 3's matrix: every cell a rule reads three times, raw and Sonnet preloaded once. */
function matrix(o: { manual?: number[]; adapter?: number[]; manualInput?: number; challenger?: number[]; challengerCost?: number } = {}): Result[] {
  const { manual = [10, 9, 10], adapter = [7, 8, 7], manualInput = 4000, challenger = [9, 9, 10] } = o;
  return [
    ...[HAIKU, SONNET].flatMap((m) => [...cell('raw', m, 7, 40000), ...reps('manual', m, manual, manualInput), ...reps('adapter', m, adapter, 45000)]),
    ...reps('manual-preloaded', HAIKU, challenger, o.challengerCost ?? 3500),
    ...cell('manual-preloaded', SONNET, 9, 3500),
  ];
}

const verdicts = (results: Result[], meta: Pick<ReportMeta, 'models' | 'manual' | 'fixture'> = META) =>
  Object.fromEntries(judgeRun3(results, meta, RULES).map((h) => [h.id, h.verdict]));

describe('run 3 rules in config.json', () => {
  it('carry the thresholds the spec approved, and select R1 to R7 with conversation caching on', () => {
    const config = JSON.parse(readFileSync(join(BENCH_DIR, 'config.json'), 'utf8'));
    expect(config.hypothesis_set).toBe('run3');
    expect(config.conversation_caching).toBe(true);
    expect(Run3Rules.parse(config.run3_hypotheses)).toEqual(RULES);
  });

  it('repeat every cell a rule reads: manual and adapter on each model, and the R3 challenger', () => {
    expect(run3Cells(RULES, [HAIKU, SONNET])).toEqual([
      { condition: 'manual', model: HAIKU }, { condition: 'adapter', model: HAIKU },
      { condition: 'manual', model: SONNET }, { condition: 'adapter', model: SONNET },
      { condition: 'manual-preloaded', model: HAIKU },
    ]);
  });
});

describe('R1 to R7', () => {
  it('pass when every threshold is met, in spec order', () => {
    expect(verdicts(matrix())).toEqual({ R1: 'pass', R2: 'pass', R3: 'pass', R4: 'pass', R5: 'pass', R6: 'pass', R7: 'pass' });
  });

  it('take R1\'s threshold from the fixture: 26 of 30 fails the block bar and clears the classic one', () => {
    const results = matrix({ manual: [9, 9, 8] });
    expect(verdicts(results).R1).toBe('fail');
    expect(verdicts(results, { ...META, fixture: 'classic' }).R1).toBe('pass');
    expect(judgeRun3(results, META, RULES)[0].numbers).toContain('86.7% (26/30), per rep 90.0%, 90.0%, 80.0%');
    expect(verdicts(results, { ...META, fixture: 'other' }).R1).toBe('not measured');
  });

  it('pass R2 at exactly 10 points and fail it below', () => {
    expect(verdicts(matrix({ manual: [9, 9, 9], adapter: [8, 8, 8] })).R2).toBe('pass');
    expect(verdicts(matrix({ manual: [9, 9, 9], adapter: [8, 8, 9] })).R2).toBe('fail');
    expect(judgeRun3(matrix(), META, RULES)[1].numbers).toContain(`${HAIKU} manual 96.7% vs adapter 73.3% (+23.3 points)`);
  });

  it('decide R3 on mean accuracy against Sonnet adapter', () => {
    expect(verdicts(matrix({ challenger: [7, 8, 7], adapter: [7, 8, 7] })).R3).toBe('pass');
    expect(verdicts(matrix({ challenger: [7, 7, 7], adapter: [7, 8, 7] })).R3).toBe('fail');
  });

  it('fail R4 over budget', () => {
    expect(verdicts(matrix(), { ...META, manual: { ...META.manual!, index_est_tokens: 4001 } }).R4).toBe('fail');
  });

  it('judge R5 on total input per pass and R6 on the p90, both against adapter', () => {
    expect(judgeRun3(matrix(), META, RULES)[4].numbers).toContain(`${HAIKU} adapter 450,000 vs manual 40,000 (11.25x)`);
    expect(verdicts(matrix({ manualInput: 16000 }))).toMatchObject({ R5: 'fail', R6: 'fail' });
    expect(verdicts(matrix({ manualInput: 15000 }))).toMatchObject({ R5: 'pass', R6: 'pass' });
  });

  it('judge R7 on cost per correct answer at a 10x bar', () => {
    // Sonnet adapter: 30 x $0.045 / 22 correct = $0.0614. Haiku preloaded at $0.006 a question: 30 x $0.006 / 28 = $0.0064, 9.5x.
    expect(verdicts(matrix({ challengerCost: 6000 })).R7).toBe('fail');
    expect(verdicts(matrix()).R7).toBe('pass');
  });

  it('report a rule unmeasured when its baseline cells were not run', () => {
    expect(verdicts(matrix().filter((r) => r.condition !== 'adapter'))).toMatchObject({ R1: 'pass', R2: 'not measured', R3: 'not measured', R5: 'not measured', R7: 'not measured' });
  });
});

describe('losses', () => {
  it('lists every metric where a baseline cell did strictly better than a Wayfinder cell on the same model', () => {
    // Raw reads 40,000 a question against Sonnet preloaded's 3,500, so it loses on tokens; raw's 7/10 does not beat 9/10.
    // Sonnet raw now answers every edit_location question and no content_model one: 50% overall, but ahead on edit_location.
    const results = matrix().map((r) => (r.condition === 'raw' && r.model === SONNET ? { ...r, correct: r.category === 'edit_location' } : r));
    expect(findLosses(results, [HAIKU, SONNET])).toEqual([
      { model: SONNET, metric: 'accuracy, edit_location', baseline: 'raw', wayfinder: 'manual', baseline_value: '100.0% (5/5)', wayfinder_value: '93.3% (14/15)' },
      { model: SONNET, metric: 'accuracy, edit_location', baseline: 'raw', wayfinder: 'manual-preloaded', baseline_value: '100.0% (5/5)', wayfinder_value: '80.0% (4/5)' },
    ]);
    expect(findLosses(matrix(), [HAIKU, SONNET])).toEqual([]);
  });

  it('counts a cheaper baseline as a loss, and a tie as none', () => {
    const cheap = matrix().map((r) => (r.condition === 'adapter' && r.model === HAIKU ? { ...r, usage: { ...r.usage, input_tokens: 0, cache_creation_input_tokens: 0, cache_read_input_tokens: 1000 }, cost_usd: 0.0001 } : r));
    const metrics = findLosses(cheap, [HAIKU, SONNET]).filter((l) => l.baseline === 'adapter').map((l) => `${l.wayfinder}: ${l.metric}`);
    expect(metrics).toEqual([
      'manual: median input tokens', 'manual: p90 input tokens', 'manual: total input tokens per pass', 'manual: cost per correct answer',
      'manual-preloaded: median input tokens', 'manual-preloaded: p90 input tokens', 'manual-preloaded: total input tokens per pass', 'manual-preloaded: cost per correct answer',
    ]);
    const tie = matrix({ manual: [7, 8, 7] });
    expect(findLosses(tie, [HAIKU, SONNET]).filter((l) => l.metric === 'accuracy')).toEqual([]);
  });
});

describe('run 3 report', () => {
  const report = buildReport(matrix(), META, RULES);

  it('carries R1 to R7, the median metric, losses and conversation caching per cell', () => {
    expect(report.hypotheses.map((h) => h.id)).toEqual(['R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7']);
    expect(report.metrics?.[0].numbers).toContain(`${HAIKU} adapter 45,000 vs manual 4,000 (11.25x)`);
    expect(report.losses).toEqual([]);
    expect(report.cache_by_cell?.find((c) => c.condition === 'manual' && c.model === HAIKU)).toEqual({
      condition: 'manual', model: HAIKU, question_runs: 30, input_total: 120000, uncached_input_tokens: 60000,
      cache_creation_input_tokens: 30000, cache_read_input_tokens: 30000, runs_reading_cache: 30,
    });
    const md = renderMarkdown(report);
    expect(md).toContain('# Wayfinder benchmark: fixture, 2026-10-03 (run3)');
    expect(md.indexOf('## Hypotheses')).toBeLessThan(md.indexOf('## Losses'));
    expect(md.indexOf('## Losses')).toBeLessThan(md.indexOf('## Most-missed questions'));
    expect(md).toContain('None. No baseline cell did better');
    expect(md).toContain('| manual | claude-haiku-4-5-20251001 | 30 | 120,000 | 60,000 | 30,000 | 30,000 | 25.0% | 30/30 |');
  });

  it('leaves reports judged by H1 to H6 without the run 3 fields', () => {
    const { hypotheses } = JSON.parse(readFileSync(join(BENCH_DIR, 'config.json'), 'utf8'));
    const old = buildReport(matrix(), { ...META, hypothesis_set: 'run2', conversation_caching: false }, hypotheses);
    expect(old.hypotheses[0].id).toBe('H1');
    expect(old).not.toHaveProperty('losses');
    expect(old).not.toHaveProperty('cache_by_cell');
  });
});

describe('run 3 summary', () => {
  it('passes a rule only where every fixture passes it', () => {
    expect(combine(['pass', 'pass'])).toBe('pass');
    expect(combine(['pass', 'fail'])).toBe('fail');
    expect(combine(['not measured', 'fail'])).toBe('fail');
    expect(combine(['pass', 'not measured'])).toBe('not measured');
    expect(combine(['pass', undefined])).toBe('not measured');
  });

  it('puts each fixture\'s verdict beside the combined one, with each fixture\'s losses', () => {
    const block = buildReport(matrix(), META, RULES);
    const classic = buildReport(matrix({ manual: [9, 9, 8] }).map((r) => ({ ...r, cost_usd: r.cost_usd * 2 })), { ...META, site: 'fixture-classic', fixture: 'classic' }, RULES);
    const md = renderRun3Summary([{ file: 'reports/fixture-2026-10-03-run3.json', report: block }, { file: 'reports/fixture-classic-2026-10-03-run3.json', report: classic }]);
    expect(md).toContain('| R1 | The manual condition reaches at least 90% accuracy on the block fixture and at least 85% on the classic fixture, on both models | pass | pass | **pass** |');
    expect(md).toContain('### classic');
    expect(md).not.toContain('missing');
    const failing = buildReport(matrix({ adapter: [9, 9, 9] }), META, RULES);
    expect(renderRun3Summary([{ file: 'a', report: failing }, { file: 'b', report: classic }])).toContain('| R2 |');
    expect(renderRun3Summary([{ file: 'a', report: failing }, { file: 'b', report: classic }])).toMatch(/\| R2 \| [^\n]+\| fail \| pass \| \*\*fail\*\* \|/);
  });

  it('refuses a report that was not judged against R1 to R7', () => {
    const old = buildReport(matrix(), { ...META, hypothesis_set: 'run2' }, JSON.parse(readFileSync(join(BENCH_DIR, 'config.json'), 'utf8')).hypotheses);
    expect(() => renderRun3Summary([{ file: 'old.json', report: old }])).toThrow('was not judged against R1 to R7');
  });
});
