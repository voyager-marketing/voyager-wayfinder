import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { computeIntervals, INTERVALS_HEADING, interval95, mcnemarExact, nonInferior, renderIntervals, resample, seeded } from '../src/intervals.ts';
import { BASELINE_CONDITIONS, buildReport, intervalsFor, renderMarkdown, reportMeta, ReportSchema, WAYFINDER_CONDITIONS, type Result } from '../src/report.ts';
import { BENCH_DIR, loadConfig } from '../src/run.ts';

const HAIKU = 'claude-haiku-4-5-20251001';
const SONNET = 'claude-sonnet-5';
const SIDES = { wayfinder: WAYFINDER_CONDITIONS, baseline: BASELINE_CONDITIONS };
const { run3_hypotheses: RUN3, hypotheses: RUN2 } = loadConfig().config;

function row(question: string, condition: string, model: string, rep: number, correct: boolean, cost = 0.01): Result {
  return {
    question, category: 'content_model', condition, model, rep, correct, reason: '', answer: '',
    usage: { input_tokens: 100, cache_creation_input_tokens: 0, cache_read_input_tokens: 0, output_tokens: 10 },
    first_call_input_tokens: 100, tool_calls: 1, turns: 1, hit_turn_cap: false, wall_ms: 1, cost_usd: cost, judge_cost_usd: 0,
  };
}

/** One row per question and repetition: `outcomes[q][rep - 1]`. */
const cell = (condition: string, model: string, outcomes: boolean[][], cost = 0.01) =>
  outcomes.flatMap((reps, q) => reps.map((correct, i) => row(`q${q + 1}`, condition, model, i + 1, correct, cost)));

/** Every bootstrap mean of `values` with its exact probability, by enumerating all n^n resamples. */
function exactBootstrap(values: number[]): [number, number][] {
  const n = values.length;
  const probability = new Map<number, number>();
  for (let code = 0; code < n ** n; code++) {
    let [total, rest] = [0, code];
    for (let j = 0; j < n; j++) {
      total += values[rest % n];
      rest = Math.floor(rest / n);
    }
    const mean = Math.round((total / n) * 1e9) / 1e9;
    probability.set(mean, (probability.get(mean) ?? 0) + 1 / n ** n);
  }
  return [...probability.entries()].sort(([a], [b]) => a - b);
}

/** The exact distribution's 2.5th and 97.5th percentiles: the first value whose cumulative probability reaches each. */
function exactInterval(values: number[]): [number, number] {
  const dist = exactBootstrap(values);
  const at = (p: number) => {
    let cumulative = 0;
    for (const [mean, prob] of dist) if ((cumulative += prob) >= p - 1e-12) return mean;
    return dist[dist.length - 1][0];
  };
  return [at(0.025), at(0.975)];
}

const T = true;
const F = false;

describe('seeded generator', () => {
  it('repeats for a seed, stays in [0, 1), and differs between seeds', () => {
    const draw = (seed: number) => Array.from({ length: 1000 }, seeded(seed));
    expect(draw(1215)).toEqual(draw(1215));
    expect(draw(1215)).not.toEqual(draw(1216));
    expect(draw(1215).every((x) => x >= 0 && x < 1)).toBe(true);
  });
});

describe('McNemar exact test', () => {
  it('matches hand computations of the binomial tail', () => {
    // n = 6: P(X <= 0) = 1/64, so p = 2/64. P(X <= 1) = 7/64, so p = 14/64.
    expect(mcnemarExact(0, 6)).toBeCloseTo(0.03125, 12);
    expect(mcnemarExact(1, 5)).toBeCloseTo(0.21875, 12);
    expect(mcnemarExact(5, 1)).toBeCloseTo(0.21875, 12);
    // n = 11: P(X <= 2) = (1 + 11 + 55) / 2048, so p = 134/2048.
    expect(mcnemarExact(2, 9)).toBeCloseTo(134 / 2048, 12);
    // Equal discordant counts and no discordant pairs both give 1, never more.
    expect(mcnemarExact(3, 3)).toBe(1);
    expect(mcnemarExact(0, 0)).toBe(1);
  });
});

describe('interval95', () => {
  it('takes the 250th and 9,750th of 10,000 sorted values, leaves out NaN and keeps Infinity', () => {
    const values = Array.from({ length: 10_000 }, (_, i) => 10_000 - i);
    expect(interval95(values)).toEqual({ lower: 250, upper: 9750, undefined_resamples: 0 });
    const ratios = [...Array.from({ length: 9_000 }, () => 2), ...Array.from({ length: 1_000 }, () => Number.POSITIVE_INFINITY), Number.NaN];
    expect(interval95(ratios)).toEqual({ lower: 2, upper: Number.POSITIVE_INFINITY, undefined_resamples: 1 });
  });
});

describe('resampling questions', () => {
  it('draws every resample uniformly: two questions scoring 0 and 1 give means 0, 0.5 and 1 at 1/4, 1/2 and 1/4', () => {
    const means = resample(2, (picks) => (picks[0] + picks[1]) / 2);
    const share = (m: number) => means.filter((x) => x === m).length / means.length;
    expect(means).toHaveLength(10_000);
    expect(share(0)).toBeCloseTo(0.25, 1);
    expect(share(0.5)).toBeCloseTo(0.5, 1);
    expect(share(1)).toBeCloseTo(0.25, 1);
    // Hand: P(0) = 1/4 already passes 2.5%, and P(1) = 1/4 passes it from the top.
    expect(interval95(means)).toMatchObject({ lower: 0, upper: 1 });
    expect(resample(2, (picks) => picks[0] * 2 + picks[1])).toEqual(resample(2, (picks) => picks[0] * 2 + picks[1]));
  });

  it('agrees with the exact bootstrap distribution, enumerated', () => {
    // Five questions, two right: the number right in a resample is Binomial(5, 0.4). P(5) = 0.01 is under
    // 2.5% and P(4 or 5) = 0.087 is over it, so the upper bound is 4/5; P(0) = 0.078 makes the lower bound 0.
    for (const values of [[0, 0, 0, 1, 1], [0.5, 0.5, -0.5, 0], [1, 0, 1, 1, 0, 1]]) {
      const [lower, upper] = exactInterval(values);
      const got = interval95(resample(values.length, (picks) => picks.reduce((total, i) => total + values[i], 0) / values.length));
      expect(got.lower).toBeCloseTo(lower, 9);
      expect(got.upper).toBeCloseTo(upper, 9);
    }
    expect(exactInterval([0, 0, 0, 1, 1])).toEqual([0, 0.8]);
  });
});

describe('a comparison checked by hand', () => {
  // Four questions, two repetitions each. Challenger shares: 1, 1/2, 0, 1 (mean 0.625, 5 of 8 right).
  // Baseline shares: 1/2, 0, 1/2, 1 (mean 0.5, 4 of 8). Per-question differences 1/2, 1/2, -1/2, 0, mean 0.125.
  const challenger = cell('manual', HAIKU, [[T, T], [T, F], [F, F], [T, T]], 0.01);
  const baseline = cell('adapter', HAIKU, [[T, F], [F, F], [F, T], [T, T]], 0.05);
  const [c] = computeIntervals([...challenger, ...baseline], [HAIKU], undefined, SIDES).comparisons;

  it('pairs accuracy by question', () => {
    expect(c.questions).toBe(4);
    expect(c.accuracy).toMatchObject({ challenger: 0.625, baseline: 0.5, difference: 0.125, read_by: [] });
  });

  it('bounds the difference where the exact bootstrap does', () => {
    // Exact, from 4^4 = 256 resamples: mean -0.5 has 1/256 and -0.375 has 4/256, together under 2.5%, and
    // -0.25 brings it to 19/256. Means of 0.5 (every pick from q1 or q2) have 16/256, over 2.5% from the top.
    expect(exactInterval([0.5, 0.5, -0.5, 0])).toEqual([-0.25, 0.5]);
    expect(c.accuracy).toMatchObject({ lower: -0.25, upper: 0.5 });
  });

  it('counts McNemar pairs repetition by repetition', () => {
    // Discordant: q1 rep 2 and q2 rep 1 only the challenger got; q3 rep 2 only the baseline. P(X <= 1 | n = 3) = 4/8, doubled is 1.
    expect(c.mcnemar).toEqual({ reps: [1, 2], pairs: 8, challenger_only: 2, baseline_only: 1, p: 1 });
  });

  it('takes the cost ratio as R7 does', () => {
    // Challenger $0.08 over 5 right = $0.016. Baseline $0.40 over 4 right = $0.10. $0.10 / $0.016 = 6.25.
    expect(c.cost_per_correct).toMatchObject({ challenger_usd: 0.016, baseline_usd: 0.1, ratio: 6.25, undefined_resamples: 0 });
    expect(c.cost_per_correct.lower!).toBeLessThanOrEqual(6.25);
    expect(c.cost_per_correct.upper!).toBeGreaterThanOrEqual(6.25);
  });
});

describe('computeIntervals', () => {
  it('pairs a one-repetition cell with a three-repetition cell by share, and matches only repetition 1 for McNemar', () => {
    const manual = cell('manual', SONNET, [[T, T, T], [T, F, T], [F, F, F], [T, T, F]]);
    const raw = cell('raw', SONNET, [[T], [F], [T], [F]]);
    const [c] = computeIntervals([...manual, ...raw], [SONNET], undefined, SIDES).comparisons;
    // Pooled: manual 7/12, raw 2/4. The per-question shares average to the same figures.
    expect(c.accuracy.challenger).toBeCloseTo(7 / 12, 6);
    expect(c.accuracy.difference).toBeCloseTo(7 / 12 - 0.5, 6);
    expect(c.mcnemar).toMatchObject({ reps: [1], pairs: 4, challenger_only: 2, baseline_only: 1 });
  });

  it('names the rules that read each figure', () => {
    const outcomes = [[T, T, T], [T, F, T], [F, T, T]];
    const results = [HAIKU, SONNET].flatMap((m) => ['raw', 'adapter', 'manual', 'manual-preloaded'].flatMap((c) => cell(c, m, outcomes)));
    const iv = computeIntervals(results, [HAIKU, SONNET], RUN3, SIDES);
    const readBy = (c: string, cm: string, b: string, bm: string) =>
      iv.comparisons.find((x) => x.challenger.condition === c && x.challenger.model === cm && x.baseline.condition === b && x.baseline.model === bm);
    expect(iv.cells.filter((x) => x.read_by.includes('R1')).map((x) => `${x.model} ${x.condition}`)).toEqual([`${HAIKU} manual`, `${SONNET} manual`]);
    expect(readBy('manual', HAIKU, 'adapter', HAIKU)?.accuracy.read_by).toEqual(['R2']);
    expect(readBy('manual', SONNET, 'adapter', SONNET)?.accuracy.read_by).toEqual(['R2']);
    expect(readBy('manual', SONNET, 'raw', SONNET)?.accuracy.read_by).toEqual([]);
    const pair = readBy('manual-preloaded', HAIKU, 'adapter', SONNET)!;
    expect([pair.accuracy.read_by, pair.cost_per_correct.read_by]).toEqual([['R3'], ['R7']]);
    // The cross-model pair against the other baseline is there too, for the best-baseline restatement and S3.
    expect(readBy('manual-preloaded', HAIKU, 'raw', SONNET)?.accuracy.read_by).toEqual([]);
    expect(iv.comparisons).toHaveLength(2 * 2 * 2 + 2);

    const old = computeIntervals(results.filter((r) => r.condition !== 'adapter'), [HAIKU, SONNET], RUN2, SIDES);
    expect(old.comparisons.find((x) => x.cost_per_correct.read_by.length)).toMatchObject({
      challenger: { condition: 'manual-preloaded', model: HAIKU }, baseline: { condition: 'raw', model: SONNET },
      accuracy: { read_by: ['H3'] }, cost_per_correct: { read_by: ['H6'] },
    });
  });

  it('leaves an unbounded or undefined cost ratio null and counts the undefined resamples', () => {
    const results = [...cell('manual', HAIKU, [[T], [F]]), ...cell('raw', HAIKU, [[F], [F]])];
    const [c] = computeIntervals(results, [HAIKU], undefined, SIDES).comparisons;
    expect(c.cost_per_correct).toMatchObject({ baseline_usd: null, ratio: null, upper: null });
    // A resample of q2 twice has nothing right on either side: 0/0, left out of the bounds. Expect about 1 in 4.
    expect(c.cost_per_correct.undefined_resamples).toBeGreaterThan(2_000);
    expect(c.cost_per_correct.undefined_resamples).toBeLessThan(3_000);
  });

  it('reproduces run 4\'s R2 and R7 figures on the block set, inside their intervals, the same way twice', () => {
    const report = ReportSchema.parse(JSON.parse(readFileSync(join(BENCH_DIR, 'reports/fixture-2026-10-04-run4.json'), 'utf8')));
    const iv = intervalsFor(report.results, report.models.map((m) => m.id), RUN3);
    expect(intervalsFor(report.results, report.models.map((m) => m.id), RUN3)).toEqual(iv);
    const r2 = iv.comparisons.find((c) => c.accuracy.read_by.includes('R2') && c.challenger.model === SONNET)!;
    expect(report.hypotheses.find((h) => h.id === 'R2')!.numbers).toContain(`${SONNET} manual 95.0% vs adapter 87.5% (+7.5 points)`);
    expect(r2.accuracy.difference).toBeCloseTo(0.075, 6);
    expect(r2.accuracy.lower).toBeLessThanOrEqual(0.075);
    expect(r2.accuracy.upper).toBeGreaterThanOrEqual(0.075);
    const r7 = iv.comparisons.find((c) => c.cost_per_correct.read_by.includes('R7'))!;
    expect(report.hypotheses.find((h) => h.id === 'R7')!.numbers).toContain(`(${r7.cost_per_correct.ratio!.toFixed(2)}x)`);
    expect(r7.cost_per_correct.lower!).toBeLessThan(r7.cost_per_correct.ratio!);
    expect(r7.cost_per_correct.upper!).toBeGreaterThan(r7.cost_per_correct.ratio!);
    expect(renderIntervals(iv)).toContain('| 95% interval |');
  });
});

describe('nonInferior', () => {
  it('passes a lower bound at or above minus the margin, compared to a tenth of a point', () => {
    expect(nonInferior(-0.04, 5)).toBe(true);
    expect(nonInferior(-0.05, 5)).toBe(true);
    expect(nonInferior(-0.0500000001, 5)).toBe(true);
    expect(nonInferior(-0.051, 5)).toBe(false);
    expect(nonInferior(0, 0)).toBe(true);
  });
});

describe('report fields', () => {
  const meta = {
    format: 'wayfinder-bench-report/1' as const, site: 'fixture', date: '2026-10-05', fixture: 'block', hypothesis_set: 'run3' as const,
    dry_run: true, runner: { where: 'dry-run' as const, concurrency: 1 }, question_set: { file: 'questions/fixture.yaml', sha256: 'x', count: 3 },
    pricing: { source: 'test', fetched: '2026-10-01' }, models: [{ id: HAIKU, params: {}, cache_min_tokens: 4096 }, { id: SONNET, params: {}, cache_min_tokens: 1024 }], manual: null,
  };
  const results = [HAIKU, SONNET].flatMap((m) => ['adapter', 'manual', 'manual-preloaded'].flatMap((c) => cell(c, m, [[T, T, T], [T, F, T], [F, F, T]])));
  const report = buildReport(results, meta, RUN3);

  it('carries the intervals in the report and the section in its Markdown', () => {
    expect(report.intervals).toEqual(intervalsFor(results, [HAIKU, SONNET], RUN3));
    const md = renderMarkdown(report);
    expect(md).toContain(`${INTERVALS_HEADING}\n`);
    expect(md.indexOf(INTERVALS_HEADING)).toBeGreaterThan(md.indexOf('## Hypotheses'));
    expect(md.indexOf(INTERVALS_HEADING)).toBeLessThan(md.indexOf('## Losses'));
  });

  it('is computed again when a report is rebuilt, never carried over', () => {
    expect(reportMeta(report)).not.toHaveProperty('intervals');
  });
});
