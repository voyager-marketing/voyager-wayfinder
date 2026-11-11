import { z } from 'zod';
import type { Result, Rules } from './report.ts';

// Paired 95% intervals for the comparisons the rules read (from run 5). Runs 3 and 4 decided every rule on a
// point estimate, and at 30 to 40 questions one accuracy figure carries a margin about the size of R2's
// threshold. Every interval resamples questions, not question runs, because the repetitions of one question
// are not independent samples. Plain TypeScript and a seeded generator, so a report is the same on every run.

/** Resamples per interval, as the run 5 plan fixes it. */
export const RESAMPLES = 10_000;
/** The generator's seed. Any fixed value makes the report deterministic; this one is the issue number. */
export const SEED = 1215;
/** S3's margin (spec, "Run 5: plan and hypotheses"): Haiku manual-preloaded may trail by up to 5 points. */
export const NON_INFERIORITY_MARGIN_POINTS = 5;

export const INTERVALS_HEADING = '## Intervals';

const n = z.number();
const s = z.string();
const CellId = z.object({ condition: s, model: s });

/** One cell's accuracy with its interval, and the rules that read it. */
const CellInterval = CellId.extend({ questions: n, reps: n, accuracy: n, lower: n, upper: n, read_by: z.array(s) });

/**
 * Challenger against baseline over the questions both ran. Accuracy is challenger minus baseline, as a
 * fraction. The cost ratio is the baseline's cost per correct answer over the challenger's, as R7 reads it,
 * and is null where it is undefined or unbounded (a side with no correct answers).
 */
const Comparison = z.object({
  challenger: CellId,
  baseline: CellId,
  questions: n,
  accuracy: z.object({ challenger: n, baseline: n, difference: n, lower: n, upper: n, read_by: z.array(s) }),
  /** Repetition k of one cell against repetition k of the other, over `reps`, the repetitions both ran. */
  mcnemar: z.object({ reps: z.array(n), pairs: n, challenger_only: n, baseline_only: n, p: n }),
  cost_per_correct: z.object({
    challenger_usd: n.nullable(), baseline_usd: n.nullable(), ratio: n.nullable(), lower: n.nullable(), upper: n.nullable(),
    /** Resamples where the ratio was 0/0 and is left out of the bounds. */
    undefined_resamples: n,
    read_by: z.array(s),
  }),
});

export const Intervals = z.object({
  resamples: n,
  seed: n,
  cells: z.array(CellInterval),
  comparisons: z.array(Comparison),
});
export type Intervals = z.infer<typeof Intervals>;
type Cell = z.infer<typeof CellId>;

/** mulberry32: a 32-bit seeded generator, uniform on [0, 1). */
export function seeded(seed: number): () => number {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/**
 * `stat` of `resamples` samples of `n` indices drawn with replacement. Each call starts a fresh generator, so
 * the same `n` and seed draw the same samples: one comparison's accuracy and cost intervals share their
 * resamples, and no interval depends on how many others the report computes before it.
 */
export function resample(n: number, stat: (picks: Int32Array) => number, resamples = RESAMPLES, seed = SEED): number[] {
  const next = seeded(seed);
  const picks = new Int32Array(n);
  const out: number[] = [];
  for (let i = 0; i < resamples; i++) {
    for (let j = 0; j < n; j++) picks[j] = Math.floor(next() * n);
    out.push(stat(picks));
  }
  return out;
}

/**
 * The 95% percentile interval, by nearest rank: of 10,000 sorted values, the 250th and the 9,750th. A NaN
 * (a 0/0 ratio) is left out and counted; Infinity sorts last and stays.
 */
export function interval95(values: number[]): { lower: number; upper: number; undefined_resamples: number } {
  const defined = values.filter((v) => !Number.isNaN(v)).sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
  const rank = (k: number) => defined[Math.max(0, Math.ceil(k) - 1)] ?? Number.NaN;
  return { lower: rank(defined.length / 40), upper: rank((defined.length * 39) / 40), undefined_resamples: values.length - defined.length };
}

/** Two-sided exact McNemar p: twice the Binomial(b + c, 1/2) tail at the smaller discordant count, at most 1. */
export function mcnemarExact(b: number, c: number): number {
  const total = b + c;
  let log = -total * Math.LN2; // log P(X = 0)
  let tail = 0;
  for (let i = 0; i <= Math.min(b, c); i++) {
    tail += Math.exp(log);
    log += Math.log(total - i) - Math.log(i + 1);
  }
  return Math.min(1, 2 * tail);
}

/**
 * Non-inferiority, as S3 words it: the challenger trails the baseline by no more than `marginPoints` when the
 * lower bound of challenger minus baseline is at least -marginPoints. Compared to a tenth of a point, as R2
 * compares, so floating error cannot decide a tie.
 */
export function nonInferior(lower: number, marginPoints: number): boolean {
  return Math.round(lower * 1000) / 10 >= -marginPoints;
}

type Tally = { runs: number; correct: number; cost: number };

function byQuestion(rs: Result[]): Map<string, Tally> {
  const out = new Map<string, Tally>();
  for (const r of rs) {
    const t = out.get(r.question) ?? { runs: 0, correct: 0, cost: 0 };
    out.set(r.question, { runs: t.runs + 1, correct: t.correct + (r.correct ? 1 : 0), cost: t.cost + r.cost_usd });
  }
  return out;
}

const round = (x: number) => Math.round(x * 1e6) / 1e6;
const finite = (x: number) => (Number.isFinite(x) ? round(x) : null);
const meanOver = (values: number[], picks: Int32Array) => {
  let total = 0;
  for (const i of picks) total += values[i];
  return total / picks.length;
};
const all = (count: number) => Int32Array.from({ length: count }, (_, i) => i);
const key = (c: Cell) => `${c.condition} ${c.model}`;
/** Whether two cells asked at least one question in common, which a comparison needs. */
const shared = (a: Result[], b: Result[]) => a.some((r) => b.some((x) => x.question === r.question));

/** The cell a rule reads one figure of, the same-model gain it reads, and the cross-model pair it reads. */
function namedBy(rules: Rules | undefined) {
  if (!rules) return undefined;
  if ('r1_min_accuracy' in rules) return { cell: 'R1', gain: { id: 'R2', baseline: rules.baseline }, pair: rules.r3, pairAccuracy: 'R3', pairCost: 'R7' };
  return { cell: 'H1', gain: undefined, pair: rules.h3, pairAccuracy: 'H3', pairCost: 'H6' };
}

function cellInterval(rs: Result[], cell: Cell, readBy: string[]): z.infer<typeof CellInterval> {
  const tallies = [...byQuestion(rs).entries()].sort(([a], [b]) => (a < b ? -1 : 1)).map(([, t]) => t);
  const shares = tallies.map((t) => t.correct / t.runs);
  const { lower, upper } = interval95(resample(shares.length, (picks) => meanOver(shares, picks)));
  return {
    ...cell, questions: shares.length, reps: new Set(rs.map((r) => r.rep)).size,
    accuracy: round(meanOver(shares, all(shares.length))), lower: round(lower), upper: round(upper), read_by: readBy,
  };
}

function compare(c: Result[], b: Result[], challenger: Cell, baseline: Cell, readBy: { accuracy: string[]; cost: string[] }): z.infer<typeof Comparison> {
  const [tc, tb] = [byQuestion(c), byQuestion(b)];
  const questions = [...tc.keys()].filter((q) => tb.has(q)).sort();
  const side = (t: Map<string, Tally>) => questions.map((q) => t.get(q)!);
  const [sc, sb] = [side(tc), side(tb)];
  const shareC = sc.map((t) => t.correct / t.runs);
  const shareB = sb.map((t) => t.correct / t.runs);
  const diffs = shareC.map((x, i) => x - shareB[i]);

  // Cost per correct answer on each side, summed over the picked questions and every repetition.
  const ratio = (picks: Int32Array) => {
    let [costC, correctC, costB, correctB] = [0, 0, 0, 0];
    for (const i of picks) {
      costC += sc[i].cost; correctC += sc[i].correct;
      costB += sb[i].cost; correctB += sb[i].correct;
    }
    return (costB / correctB) / (costC / correctC);
  };
  const perCorrect = (t: Tally[]) => t.reduce((x, y) => x + y.cost, 0) / t.reduce((x, y) => x + y.correct, 0);

  const acc = interval95(resample(questions.length, (picks) => meanOver(diffs, picks)));
  const cost = interval95(resample(questions.length, ratio));

  const reps = [...new Set(c.map((r) => r.rep))].filter((rep) => b.some((r) => r.rep === rep)).sort((x, y) => x - y);
  const asked = new Set(questions);
  const outcome = (rs: Result[]) => new Map(rs.filter((r) => asked.has(r.question) && reps.includes(r.rep)).map((r) => [`${r.question} ${r.rep}`, r.correct]));
  const [oc, ob] = [outcome(c), outcome(b)];
  let [challengerOnly, baselineOnly, pairs] = [0, 0, 0];
  for (const [k, right] of oc) {
    const other = ob.get(k);
    if (other === undefined) continue;
    pairs++;
    if (right && !other) challengerOnly++;
    if (!right && other) baselineOnly++;
  }

  return {
    challenger, baseline, questions: questions.length,
    accuracy: {
      challenger: round(meanOver(shareC, all(questions.length))), baseline: round(meanOver(shareB, all(questions.length))),
      difference: round(meanOver(diffs, all(questions.length))), lower: round(acc.lower), upper: round(acc.upper), read_by: readBy.accuracy,
    },
    mcnemar: { reps, pairs, challenger_only: challengerOnly, baseline_only: baselineOnly, p: round(mcnemarExact(challengerOnly, baselineOnly)) },
    cost_per_correct: {
      challenger_usd: finite(perCorrect(sc)), baseline_usd: finite(perCorrect(sb)), ratio: finite(ratio(all(questions.length))),
      lower: finite(cost.lower), upper: finite(cost.upper), undefined_resamples: cost.undefined_resamples, read_by: readBy.cost,
    },
  };
}

/**
 * Intervals for one report's results. Every cell gets an accuracy interval. The comparisons are every
 * same-model pair of a Wayfinder condition and a baseline condition, and the cross-model pair R3 and R7 (H3
 * and H6) name, against every baseline condition on the baseline's model, so the best-baseline restatement
 * and S3 and S4 have their row whichever baseline wins. `read_by` names the rules of `rules` that read a figure
 * against the named baseline. A cell or comparison with no results on either side is left out.
 */
export function computeIntervals(results: Result[], models: string[], rules: Rules | undefined, sides: { wayfinder: string[]; baseline: string[] }): Intervals {
  const cellOf = (c: Cell) => results.filter((r) => r.condition === c.condition && r.model === c.model);
  const named = namedBy(rules);
  const order = [...sides.baseline, ...sides.wayfinder];
  const conditions = [...new Set(results.map((r) => r.condition))].sort((a, b) => {
    const [i, j] = [order.indexOf(a), order.indexOf(b)];
    return (i < 0 ? order.length : i) - (j < 0 ? order.length : j) || (a < b ? -1 : a > b ? 1 : 0);
  });

  const cells = models.flatMap((model) => conditions.map((condition) => ({ condition, model }))).filter((c) => cellOf(c).length)
    .map((c) => cellInterval(cellOf(c), c, named && c.condition === 'manual' ? [named.cell] : []));

  const wanted: { challenger: Cell; baseline: Cell }[] = models.flatMap((model) =>
    sides.wayfinder.flatMap((w) => sides.baseline.map((b) => ({ challenger: { condition: w, model }, baseline: { condition: b, model } }))));
  if (named && named.pair.challenger.model !== named.pair.baseline.model) {
    const { challenger, baseline } = named.pair;
    for (const b of new Set([...sides.baseline, baseline.condition])) wanted.push({ challenger, baseline: { condition: b, model: baseline.model } });
  }
  const seen = new Set<string>();
  const comparisons = wanted.filter((p) => {
    const k = `${key(p.challenger)} | ${key(p.baseline)}`;
    if (seen.has(k) || !shared(cellOf(p.challenger), cellOf(p.baseline))) return false;
    seen.add(k);
    return true;
  }).map(({ challenger, baseline }) => {
    const readBy = { accuracy: [] as string[], cost: [] as string[] };
    if (named?.gain && challenger.condition === 'manual' && baseline.condition === named.gain.baseline && challenger.model === baseline.model) readBy.accuracy.push(named.gain.id);
    if (named && key(challenger) === key(named.pair.challenger) && key(baseline) === key(named.pair.baseline)) {
      readBy.accuracy.push(named.pairAccuracy);
      readBy.cost.push(named.pairCost);
    }
    return compare(cellOf(challenger), cellOf(baseline), challenger, baseline, readBy);
  });

  return { resamples: RESAMPLES, seed: SEED, cells, comparisons };
}

const table = (head: string[], rows: string[][]) =>
  [`| ${head.join(' | ')} |`, `|${head.map(() => '---').join('|')}|`, ...rows.map((r) => `| ${r.join(' | ')} |`)].join('\n');
const pct = (x: number) => `${(x * 100).toFixed(1)}%`;
const points = (x: number) => {
  const p = Math.round(x * 1000) / 10;
  return `${p > 0 ? '+' : ''}${p.toFixed(1)}`;
};
const times = (x: number | null) => (x === null ? 'n/a' : `${x.toFixed(2)}x`);
const pValue = (p: number) => (p < 0.001 ? '<0.001' : p.toFixed(3));
const usd = (x: number | null) => (x === null ? 'no correct answers' : `$${x.toFixed(4)}`);
const label = (c: Cell) => `${c.model} ${c.condition}`;

/** The Markdown section a report carries and `--intervals` prints. */
export function renderIntervals(iv: Intervals, marginPoints = NON_INFERIORITY_MARGIN_POINTS): string {
  const accuracyRows = iv.comparisons.map((c) => [
    label(c.challenger), label(c.baseline), c.accuracy.read_by.join(', '),
    pct(c.accuracy.challenger), pct(c.accuracy.baseline), points(c.accuracy.difference),
    `${points(c.accuracy.lower)} to ${points(c.accuracy.upper)}`, nonInferior(c.accuracy.lower, marginPoints) ? 'yes' : 'no',
    `${c.mcnemar.challenger_only} / ${c.mcnemar.baseline_only} of ${c.mcnemar.pairs}`, pValue(c.mcnemar.p),
  ]);
  const costRows = iv.comparisons.map((c) => {
    const x = c.cost_per_correct;
    return [
      label(c.challenger), label(c.baseline), x.read_by.join(', '), usd(x.challenger_usd), usd(x.baseline_usd), times(x.ratio),
      `${times(x.lower)} to ${times(x.upper)}${x.undefined_resamples ? ` (${x.undefined_resamples} resamples undefined)` : ''}`,
    ];
  });
  return [
    INTERVALS_HEADING,
    `Every figure below has a 95% interval from resampling questions with replacement: ${iv.resamples.toLocaleString('en-US')} resamples from seed ${iv.seed}, so the numbers are the same on every run, with the 250th and 9,750th sorted values as bounds. ` +
      'A question counts once, scored as the share of its repetitions answered correctly, because the repetitions of one question are not independent samples. ' +
      'Each comparison pairs its two cells by question. McNemar\'s exact test pairs repetition k of one cell with repetition k of the other, over the repetitions both ran; it treats those pairs as independent, so its p is optimistic and the interval is the figure to read. ' +
      '"Read by" names the rules that read a figure against the named baseline; the best-baseline restatement reads whichever baseline row it names. ' +
      'Computed by `src/intervals.ts` from the graders\' verdicts in this report, with no API calls. Hand re-grades in a `corrected` block are not resampled.',
    '### Cell accuracy',
    table(['Model', 'Condition', 'Questions', 'Reps', 'Accuracy', '95% interval', 'Read by'],
      iv.cells.map((c) => [c.model, c.condition, String(c.questions), String(c.reps), pct(c.accuracy), `${pct(c.lower)} to ${pct(c.upper)}`, c.read_by.join(', ')])),
    '### Paired accuracy',
    `Challenger minus baseline, in percentage points. "Within ${marginPoints}" is the non-inferiority check S3 uses: the lower bound is at least -${marginPoints} points. McNemar counts the repetition-matched pairs only the challenger answered correctly, and only the baseline.`,
    iv.comparisons.length
      ? table(['Challenger', 'Baseline', 'Read by', 'Challenger accuracy', 'Baseline accuracy', 'Difference', '95% interval', `Within ${marginPoints}`, 'McNemar', 'p'], accuracyRows)
      : 'No comparisons: the report has no Wayfinder cell beside a baseline cell.',
    '### Paired cost per correct answer',
    'The baseline\'s cost per correct answer over the challenger\'s, as R7 reads it: agent cost over correct answers on each side, summed over the resampled questions and every repetition. Above 1x the challenger is cheaper per correct answer.',
    iv.comparisons.length
      ? table(['Challenger', 'Baseline', 'Read by', 'Challenger', 'Baseline', 'Ratio', '95% interval'], costRows)
      : 'No comparisons.',
  ].join('\n\n');
}
