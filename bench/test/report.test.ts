import { describe, expect, it } from 'vitest';
import { applyCorrections, buildReport, judgeHypotheses, renderMarkdown, type CorrectionsFile, type HypothesisRules, type ReportMeta, type Result } from '../src/report.ts';

const HAIKU = 'claude-haiku-4-5-20251001';
const SONNET = 'claude-sonnet-5';

const RULES: HypothesisRules = {
  h1_min_accuracy: 0.9,
  h2_min_input_ratio: 5,
  h2b_min_total_input_ratio: 3,
  h3: { challenger: { condition: 'manual-preloaded', model: HAIKU }, baseline: { condition: 'raw', model: SONNET }, repetitions: 3 },
  h4_max_est_tokens: { brief: 600, index: 4000 },
  h5_min_p90_input_ratio: 3,
  h6_min_cost_per_correct_ratio: 5,
};

const META: ReportMeta = {
  format: 'wayfinder-bench-report/1',
  site: 'fixture',
  date: '2026-10-02',
  dry_run: false,
  runner: { where: 'local', concurrency: 4 },
  question_set: { file: 'questions/fixture.yaml', sha256: 'x', count: 10 },
  pricing: { source: 'test', fetched: '2026-10-01' },
  models: [
    { id: HAIKU, params: { temperature: 0 }, cache_min_tokens: 4096 },
    { id: SONNET, params: {}, cache_min_tokens: 1024 },
  ],
  manual: { brief_chars: 1649, index_chars: 7996, brief_est_tokens: 413, index_est_tokens: 1999 },
};

/** `correct` of 10 questions right, each reading `input` tokens. */
function cell(condition: string, model: string, correct: number, input: number, rep = 1, cache = { write: 0, read: 0 }): Result[] {
  return Array.from({ length: 10 }, (_, i) => ({
    question: `cm-${String(i + 1).padStart(2, '0')}`, category: 'content_model', condition, model, rep,
    correct: i < correct, reason: i < correct ? 'matches' : 'expected "6"', answer: i < correct ? '6' : '8',
    usage: { input_tokens: input - cache.write - cache.read, cache_creation_input_tokens: cache.write, cache_read_input_tokens: cache.read, output_tokens: 50 },
    // Cost follows input at $1 per million tokens, so cost per correct answer tracks the token gap.
    first_call_input_tokens: 3000, tool_calls: 1, turns: 2, hit_turn_cap: false, wall_ms: 1000, cost_usd: input / 1_000_000, judge_cost_usd: 0,
  }));
}

/** Every cell the bench runs, with the H3 cells repeated. */
function matrix(o: { manualCorrect?: number; manualInput?: number; challenger?: number[]; baseline?: number[] } = {}): Result[] {
  const { manualCorrect = 9, manualInput = 4000, challenger = [9, 9, 9], baseline = [8, 9, 8] } = o;
  return [
    ...[HAIKU, SONNET].flatMap((m) => [...cell('raw', m, 8, 40000), ...cell('manual', m, manualCorrect, manualInput)]),
    ...challenger.flatMap((c, i) => cell('manual-preloaded', HAIKU, c, 3500, i + 1)),
    ...cell('manual-preloaded', SONNET, 9, 3500, 1, { write: 300, read: 2900 }),
    ...baseline.slice(1).flatMap((c, i) => cell('raw', SONNET, c, 40000, i + 2)),
  ].map((r) => (r.condition === 'raw' && r.model === SONNET && r.rep === 1 ? { ...r, correct: Number(r.question.slice(3)) <= baseline[0] } : r));
}

const verdicts = (results: Result[], meta: Pick<ReportMeta, 'models' | 'manual'> = META) =>
  Object.fromEntries(judgeHypotheses(results, meta, RULES).map((h) => [h.id, h.verdict]));

describe('hypotheses', () => {
  it('passes all seven when every threshold is met, listed in spec order', () => {
    expect(verdicts(matrix())).toEqual({ H1: 'pass', H2: 'pass', H2b: 'pass', H3: 'pass', H4: 'pass', H5: 'pass', H6: 'pass' });
    expect(judgeHypotheses(matrix(), META, RULES).map((h) => h.id)).toEqual(['H1', 'H2', 'H2b', 'H3', 'H4', 'H5', 'H6']);
  });

  it('judges H2b on total input per pass, so a repeated raw cell counts once', () => {
    // Sonnet raw runs three passes; per pass it reads 400,000 tokens against manual's 40,000.
    const h2b = judgeHypotheses(matrix(), META, RULES).find((h) => h.id === 'H2b');
    expect(h2b?.numbers).toContain(`${SONNET} raw 400,000 vs manual 40,000 (10.00x)`);
    expect(verdicts(matrix({ manualInput: 14000 })).H2b).toBe('fail');
  });

  it('judges H5 on the p90, which a few long raw questions decide', () => {
    // Nearest-rank p90 of ten questions is the ninth, so two long manual questions set it.
    const tail = (rs: Result[]) => rs.map((r) => (r.condition === 'manual' && ['cm-09', 'cm-10'].includes(r.question) ? { ...r, usage: { ...r.usage, input_tokens: 20000 } } : r));
    expect(verdicts(matrix()).H5).toBe('pass');
    expect(verdicts(tail(matrix())).H5).toBe('fail');
    expect(verdicts(tail(matrix())).H2).toBe('pass'); // the median does not see one long question
  });

  it('judges H6 on cost per correct answer over every repetition of the H3 cells', () => {
    const h6 = judgeHypotheses(matrix(), META, RULES).find((h) => h.id === 'H6');
    // Haiku preloaded: 30 runs x $0.0035 / 27 correct. Sonnet raw: 30 x $0.04 / 25 correct.
    expect(h6?.numbers).toContain('$0.0039 vs');
    expect(h6?.numbers).toContain('$0.0480 (12.34x)');
    const dear = matrix().map((r) => (r.condition === 'manual-preloaded' && r.model === HAIKU ? { ...r, cost_usd: 0.01 } : r));
    expect(verdicts(dear).H6).toBe('fail');
    const none = matrix().map((r) => (r.condition === 'manual-preloaded' && r.model === HAIKU ? { ...r, correct: false } : r));
    expect(judgeHypotheses(none, META, RULES).find((h) => h.id === 'H6')).toMatchObject({ verdict: 'fail', numbers: expect.stringContaining('no correct answers') });
  });

  it('fails H1 when one model misses 90% on the manual condition', () => {
    expect(verdicts(matrix({ manualCorrect: 8 })).H1).toBe('fail');
  });

  it('fails H2 below a 5x median input ratio', () => {
    expect(verdicts(matrix({ manualInput: 9000 })).H2).toBe('fail');
  });

  it('decides H3 on mean accuracy over repetitions, so one good rep cannot carry it', () => {
    expect(verdicts(matrix({ challenger: [10, 7, 7], baseline: [8, 8, 8] })).H3).toBe('fail');
    expect(verdicts(matrix({ challenger: [8, 8, 8], baseline: [8, 8, 8] })).H3).toBe('pass');
    const h3 = judgeHypotheses(matrix(), META, RULES).find((h) => h.id === 'H3');
    expect(h3?.numbers).toContain('90.0%, 90.0%, 90.0% (mean 90.0%, 3 reps)');
    expect(h3?.numbers).toContain('80.0%, 90.0%, 80.0% (mean 83.3%, 3 reps)');
  });

  it('fails H4 over budget and reports it unmeasured when no site was read', () => {
    expect(verdicts(matrix(), { ...META, manual: { ...META.manual!, brief_est_tokens: 601 } }).H4).toBe('fail');
    expect(verdicts(matrix(), { ...META, manual: null }).H4).toBe('not measured');
  });

  it('reports a hypothesis unmeasured when its cells were not run', () => {
    expect(verdicts(matrix().filter((r) => r.condition !== 'manual'))).toMatchObject({ H1: 'not measured', H2: 'not measured', H3: 'pass' });
  });
});

describe('report', () => {
  const report = buildReport(matrix(), META, RULES);

  it('gives each repetition its own run row', () => {
    expect(report.runs).toHaveLength(10);
    expect(report.runs.filter((r) => r.condition === 'raw' && r.model === SONNET).map((r) => r.rep)).toEqual([1, 2, 3]);
  });

  it('says per model whether the preloaded manual was cached', () => {
    expect(report.caching).toEqual([
      expect.objectContaining({ model: HAIKU, engaged: false, cache_read_input_tokens: 0, runs_reading_cache: 0, question_runs: 30 }),
      expect.objectContaining({ model: SONNET, engaged: true, cache_creation_input_tokens: 3000, cache_read_input_tokens: 29000, runs_reading_cache: 10 }),
    ]);
  });

  it('lists the three most-missed questions per condition with the answer given', () => {
    const raw = report.most_missed.filter((m) => m.condition === 'raw');
    // raw: cm-10 wrong in Haiku rep 1 and all three Sonnet reps, cm-09 in three of the four. Nothing else is missed.
    expect(raw.map((m) => [m.question, m.missed, m.runs])).toEqual([['cm-10', 4, 4], ['cm-09', 3, 4]]);
    expect(raw[0].reason).toContain('answered: 8');
    // manual-preloaded misses only cm-10, in every Haiku rep and the Sonnet run, so the list is shorter than three.
    expect(report.most_missed.filter((m) => m.condition === 'manual-preloaded').map((m) => m.question)).toEqual(['cm-10']);
  });

  it('opens the Markdown with the hypotheses, then the misses', () => {
    const md = renderMarkdown(report);
    expect(md.indexOf('## Hypotheses')).toBeLessThan(md.indexOf('## Most-missed questions'));
    expect(md.indexOf('## Most-missed questions')).toBeLessThan(md.indexOf('## Accuracy'));
    expect(md).toContain('| H3 |');
    expect(md).toContain('Run locally');
    expect(md).toContain(`${HAIKU}: not cached. The prefix is under the minimum: the first call read 3,000 tokens (median) against a 4,096-token minimum.`);
    expect(md).toContain(`${SONNET}: cached. 29,000 tokens came from the cache`);
  });
});

describe('grader corrections', () => {
  const fix = (question: string, condition: string, model: string, rep = 1) => ({ question, condition, model, rep, why: 'states the value' });
  const file = (...corrections: CorrectionsFile['corrections']): CorrectionsFile => ({ issue: 'the grader fix', rule: 'Re-graded by hand.', corrections });

  it('adds corrected numbers beside the graded ones and leaves every graded field alone', () => {
    const results = matrix({ manualCorrect: 8 });
    const graded = buildReport(results, META, RULES);
    const both = buildReport(results, META, RULES, file(fix('cm-09', 'manual', HAIKU), fix('cm-09', 'manual', SONNET)));
    const { corrected, ...rest } = both;
    expect(rest).toEqual(graded);
    expect(graded.hypotheses.find((h) => h.id === 'H1')?.verdict).toBe('fail');
    expect(corrected?.hypotheses.find((h) => h.id === 'H1')?.verdict).toBe('pass');
    expect(corrected?.runs.find((s) => s.condition === 'manual' && s.model === HAIKU)).toMatchObject({ correct: 9, accuracy: 0.9 });
    const md = renderMarkdown(both);
    expect(md).toContain('With 2 misses re-graded by hand (the grader fix, see Grader corrections): H1 pass');
    expect(md).toContain('| manual | claude-haiku-4-5-20251001 | 1 | 8/10 (80.0%) | 9/10 (90.0%) |');
  });

  it('refuses a correction that matches no result, or one the grader already passed', () => {
    const results = matrix();
    expect(() => applyCorrections(results, file(fix('cm-99', 'manual', HAIKU)))).toThrow('match no result');
    expect(() => applyCorrections(results, file(fix('cm-01', 'manual', HAIKU)))).toThrow('already marked correct');
  });
});
