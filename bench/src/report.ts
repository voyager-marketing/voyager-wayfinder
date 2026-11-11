import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { z } from 'zod';
import { computeIntervals, Intervals, renderIntervals } from './intervals.ts';
import { Sensitivity, sensitivitySection } from './sensitivity.ts';
import { addTallies, breaches, TOOL_ERROR_LIMIT, toolErrors } from './toolErrors.ts';
import { inputTotal } from './usage.ts';

// The report shape, documented in bench/README.md. Writing the report parses it through this schema.
const n = z.number();
const s = z.string();
const Usage = z.object({ input_tokens: n, cache_creation_input_tokens: n, cache_read_input_tokens: n, output_tokens: n });
/** Calls and error results per tool name, from run 6. Reports before it lack them. */
const ToolTally = z.record(s, z.object({ calls: n, errors: n }));

export const ResultSchema = z.object({
  question: s, category: s, condition: s, model: s, rep: n, correct: z.boolean(), reason: s, answer: s,
  usage: Usage, first_call_input_tokens: n, tool_calls: n,
  tool_errors: n.optional(), tools: ToolTally.optional(),
  turns: n, hit_turn_cap: z.boolean(), wall_ms: n, cost_usd: n, judge_cost_usd: n,
});
export type Result = z.infer<typeof ResultSchema>;

const Spread = z.object({ median: n, p90: n });
const Group = { condition: s, model: s, questions: n, correct: n, accuracy: n };
const Verdict = z.enum(['pass', 'fail', 'not measured']);
const Cell = z.object({ condition: s, model: s });

/**
 * The hypothesis thresholds from config.json. H1 to H4 were fixed before run 1; H2b, H5 and H6 were
 * added before run 2 (the spec's "Run 2: hypotheses set 2026-10-02, before run 2").
 */
export const HypothesisRules = z.object({
  h1_min_accuracy: n,
  h2_min_input_ratio: n,
  h2b_min_total_input_ratio: n,
  h3: z.object({ challenger: Cell, baseline: Cell, repetitions: n }),
  h4_max_est_tokens: z.object({ brief: n, index: n }),
  h5_min_p90_input_ratio: n,
  h6_min_cost_per_correct_ratio: n,
});
export type HypothesisRules = z.infer<typeof HypothesisRules>;

/**
 * The run 3 thresholds from config.json (the spec's "Run 3: hypotheses set 2026-10-02, before run 3").
 * `baseline` is the condition R2, R5 and R6 compare manual against, and R3 and R7 name. Every cell a
 * rule names runs `repetitions` times.
 */
export const Run3Rules = z.object({
  r1_min_accuracy: z.record(s, n),
  r2_min_gain_points: n,
  r3: z.object({ challenger: Cell, baseline: Cell }),
  r4_max_est_tokens: z.object({ brief: n, index: n }),
  r5_min_total_input_ratio: n,
  r6_min_p90_input_ratio: n,
  r7_min_cost_per_correct_ratio: n,
  baseline: s,
  repetitions: n,
});
export type Run3Rules = z.infer<typeof Run3Rules>;
export type Rules = HypothesisRules | Run3Rules;
export const isRun3 = (rules: Rules): rules is Run3Rules => 'r1_min_accuracy' in rules;

/** The conditions that carry Wayfinder, and the ones that do not. A loss is a baseline doing better. */
export const WAYFINDER_CONDITIONS = ['manual', 'manual-preloaded', 'cli-manual'];
export const BASELINE_CONDITIONS = ['raw', 'adapter', 'cli'];

/** The cells a run 3 rule reads, each of which repeats `repetitions` times. */
export function run3Cells(rules: Run3Rules, models: string[]): { condition: string; model: string }[] {
  const named = [
    ...models.flatMap((model) => [{ condition: 'manual', model }, { condition: rules.baseline, model }]),
    rules.r3.challenger, rules.r3.baseline,
  ];
  return named.filter((c, i) => named.findIndex((d) => d.condition === c.condition && d.model === c.model) === i);
}

/** Misses re-graded by hand after a run, with the rule applied and the issue that tracks the grader fix. */
export const CorrectionsFile = z.object({
  issue: s,
  rule: s,
  corrections: z.array(z.object({ question: s, condition: s, model: s, rep: n, why: s })).min(1),
});
export type CorrectionsFile = z.infer<typeof CorrectionsFile>;

const Loss = z.object({ model: s, metric: s, baseline: s, wayfinder: s, baseline_value: s, wayfinder_value: s });

export const ReportSchema = z.object({
  format: z.literal('wayfinder-bench-report/1'),
  site: s,
  date: s.regex(/^\d{4}-\d{2}-\d{2}$/),
  /** Added for run 3; reports before it lack them. `run_label` names the files, `fixture` picks R1's threshold. */
  run_label: s.optional(),
  fixture: s.optional(),
  hypothesis_set: z.enum(['run2', 'run3']).optional(),
  /** The commit the harness and plugin ran at, with `-dirty` when tracked files had changes. */
  commit: s.optional(),
  conversation_caching: z.boolean().optional(),
  /** Model calls per question before a run counts as wrong. Absent before `--max-turns`, which means 12. */
  max_turns: n.optional(),
  /** The MCP server route the adapter condition used, from run 5. Absent before it, which means the default server. */
  adapter_endpoint: s.optional(),
  /** The tools that server listed, by name, sorted: what every adapter question was offered beside http_get and rest_get. */
  adapter_tools: z.array(s).optional(),
  dry_run: z.boolean(),
  runner: z.object({ where: z.enum(['local', 'github-actions', 'dry-run']), concurrency: n }),
  question_set: z.object({ file: s, sha256: s, count: n }),
  pricing: z.object({ source: s, fetched: s }),
  models: z.array(z.object({ id: s, params: z.record(s, z.unknown()), cache_min_tokens: n })),
  /**
   * From run 6 no agent model is sent a temperature, so each runs at its provider's default; the judge's model and
   * params beside it. Absent before run 6, whose reports carry each model's params in `models` instead.
   */
  temperature_policy: z.object({ agents: z.literal('provider-default'), judge: z.object({ model: s, params: z.record(s, z.unknown()) }) }).optional(),
  /** Brief and index sizes as served to the manual tools. Null when no site was read (dry run). */
  manual: z.object({
    brief_chars: n, index_chars: n, brief_est_tokens: n, index_est_tokens: n,
    /** Items the index left out to fit its budget, from run 5. Absent before it. */
    index_dropped: n.optional(),
  }).nullable(),
  hypotheses: z.array(z.object({ id: s, claim: s, verdict: Verdict, numbers: s })),
  /** Run 3: figures the spec reports without a threshold, such as the median input ratio. */
  metrics: z.array(z.object({ id: s, claim: s, numbers: s })).optional(),
  /** Run 3: every same-model comparison where a baseline cell did better than a Wayfinder cell. */
  losses: z.array(Loss).optional(),
  /** Run 3, with conversation caching on: what every cell read from and wrote to the cache. */
  cache_by_cell: z.array(
    z.object({
      condition: s, model: s, question_runs: n, input_total: n, uncached_input_tokens: n,
      cache_creation_input_tokens: n, cache_read_input_tokens: n, runs_reading_cache: n,
    }),
  ).optional(),
  caching: z.array(
    z.object({
      model: s, cache_min_tokens: n, question_runs: n, first_call_input_tokens: Spread,
      cache_creation_input_tokens: n, cache_read_input_tokens: n, runs_reading_cache: n, engaged: z.boolean(),
    }),
  ),
  most_missed: z.array(z.object({ condition: s, question: s, category: s, missed: n, runs: n, reason: s })),
  runs: z.array(
    z.object({
      ...Group,
      rep: n,
      input_tokens: Spread, output_tokens: Spread, total_tokens: Spread, tool_calls: Spread, wall_ms: Spread,
      cost_per_question_usd: n, cost_usd: n,
      /** Every tool call and error result in the cell's repetition, per tool, when its results carry them (from run 6). */
      tools: ToolTally.optional(),
    }),
  ),
  by_category: z.array(z.object({ ...Group, category: s })),
  totals: z.object({ question_runs: n, correct: n, cost_usd: n, judge_cost_usd: n }),
  results: z.array(ResultSchema),
  /** The same run with hand re-graded misses counted correct. Every other field keeps the graders' verdicts. */
  corrected: CorrectionsFile.extend({
    hypotheses: z.array(z.object({ id: s, claim: s, verdict: Verdict, numbers: s })),
    runs: z.array(z.object({ ...Group, rep: n })),
  }).optional(),
  /** A run at another turn cap, cell by cell beside the reference run it names (`--sensitivity-of`). */
  sensitivity: Sensitivity.optional(),
  /** From run 5: 95% intervals per cell and per paired comparison, and McNemar. Reports before it lack them; `--intervals` prints them. */
  intervals: Intervals.optional(),
});
export type Report = z.infer<typeof ReportSchema>;
export type ReportMeta = Omit<Report, 'hypotheses' | 'metrics' | 'losses' | 'cache_by_cell' | 'caching' | 'most_missed' | 'runs' | 'by_category' | 'totals' | 'results' | 'corrected' | 'sensitivity' | 'intervals'>;

/** A written report with everything buildReport computes taken off, ready to build again. */
export function reportMeta(report: Report): ReportMeta {
  const { hypotheses, metrics, losses, cache_by_cell, caching, most_missed, runs, by_category, totals, results, corrected, sensitivity, intervals, ...meta } = report;
  return meta;
}

const ascending = (values: number[]) => [...values].sort((a, b) => a - b);

/** Nearest-rank percentile. */
export function percentile(values: number[], p: number): number {
  return values.length ? ascending(values)[Math.max(0, Math.ceil((p / 100) * values.length) - 1)] : 0;
}

export function median(values: number[]): number {
  const sorted = ascending(values);
  const mid = Math.floor(sorted.length / 2);
  return !sorted.length ? 0 : sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
}

const spread = (values: number[]) => ({ median: median(values), p90: percentile(values, 90) });
const round = (x: number, places: number) => Math.round(x * 10 ** places) / 10 ** places;
const sum = (values: number[]) => values.reduce((a, b) => a + b, 0);
const mean = (values: number[]) => (values.length ? sum(values) / values.length : 0);

function groupBy(results: Result[], key: (r: Result) => string): [string, Result[]][] {
  const groups = new Map<string, Result[]>();
  for (const r of results) groups.set(key(r), [...(groups.get(key(r)) ?? []), r]);
  return [...groups.entries()].sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0));
}

const tally = (rs: Result[]) => {
  const correct = rs.filter((r) => r.correct).length;
  return { condition: rs[0].condition, model: rs[0].model, questions: rs.length, correct, accuracy: round(correct / rs.length, 4) };
};

const pct = (x: number) => `${(x * 100).toFixed(1)}%`;
const usd = (x: number) => `$${x.toFixed(4)}`;
const int = (x: number) => Math.round(x).toLocaleString('en-US');
const oneLine = (text: string, max: number) => {
  const flat = text.replace(/\s+/g, ' ').trim();
  return flat.length > max ? `${flat.slice(0, max - 1)}…` : flat;
};

type Hypothesis = Report['hypotheses'][number];

/**
 * H1 to H6 from the spec, judged by the rules in config.json. Every model must clear H1, H2,
 * H2b and H5. Medians and p90s pool repetitions. H2b compares total input per pass over the question
 * set, so a cell with three repetitions counts once. H3 compares mean accuracy over the repetitions of
 * its two cells, and H6 compares the same two cells on cost per correct answer, pooled.
 */
export function judgeHypotheses(results: Result[], meta: Pick<ReportMeta, 'models' | 'manual'>, rules: HypothesisRules): Hypothesis[] {
  const cell = (condition: string, model: string) => results.filter((r) => r.condition === condition && r.model === model);
  const models = meta.models.map((m) => m.id);
  const unmeasured = (id: string, claim: string, missing: string): Hypothesis => ({ id, claim, verdict: 'not measured', numbers: `no results for ${missing}` });
  const accuracyOf = (rs: Result[]) => rs.filter((r) => r.correct).length / rs.length;
  const scored = (rs: Result[]) => `${pct(accuracyOf(rs))} (${rs.filter((r) => r.correct).length}/${rs.length})`;
  const medianInput = (rs: Result[]) => median(rs.map((r) => inputTotal(r.usage)));
  const verdict = (ok: boolean) => (ok ? 'pass' : 'fail');
  const out: Hypothesis[] = [];

  const h1 = `The manual condition reaches ${pct(rules.h1_min_accuracy)} accuracy or better, on every model`;
  const h1Missing = models.find((m) => !cell('manual', m).length);
  if (h1Missing) out.push(unmeasured('H1', h1, `manual ${h1Missing}`));
  else {
    const preloaded = models.filter((m) => cell('manual-preloaded', m).length).map((m) => `${m} ${scored(cell('manual-preloaded', m))}`);
    out.push({
      id: 'H1', claim: h1,
      verdict: verdict(models.every((m) => accuracyOf(cell('manual', m)) >= rules.h1_min_accuracy)),
      numbers: `manual: ${models.map((m) => `${m} ${scored(cell('manual', m))}`).join('; ')}` +
        (preloaded.length ? `. For reference, manual-preloaded: ${preloaded.join('; ')}` : ''),
    });
  }

  const h2 = `The manual condition reads at least ${rules.h2_min_input_ratio}x fewer input tokens per question than raw (median), on every model`;
  const h2Missing = models.find((m) => !cell('manual', m).length || !cell('raw', m).length);
  if (h2Missing) out.push(unmeasured('H2', h2, `raw or manual ${h2Missing}`));
  else {
    const ratio = (m: string, condition: string) => medianInput(cell('raw', m)) / medianInput(cell(condition, m));
    const line = (m: string, condition: string) =>
      `${m} raw ${int(medianInput(cell('raw', m)))} vs ${condition} ${int(medianInput(cell(condition, m)))} (${ratio(m, condition).toFixed(2)}x)`;
    const preloaded = models.filter((m) => cell('manual-preloaded', m).length).map((m) => line(m, 'manual-preloaded'));
    out.push({
      id: 'H2', claim: h2,
      verdict: verdict(models.every((m) => ratio(m, 'manual') >= rules.h2_min_input_ratio)),
      numbers: `median input tokens per question: ${models.map((m) => line(m, 'manual')).join('; ')}` +
        (preloaded.length ? `. For reference: ${preloaded.join('; ')}` : ''),
    });
  }

  // Total input over one pass of the question set: the cell's total divided by its repetitions.
  const passes = (rs: Result[]) => new Set(rs.map((r) => r.rep)).size;
  const totalPerPass = (rs: Result[]) => sum(rs.map((r) => inputTotal(r.usage))) / passes(rs);
  const h2b = `Across all questions, total input tokens in the manual condition are at least ${rules.h2b_min_total_input_ratio}x lower than raw, on every model`;
  if (h2Missing) out.push(unmeasured('H2b', h2b, `raw or manual ${h2Missing}`));
  else {
    const ratio = (m: string) => totalPerPass(cell('raw', m)) / totalPerPass(cell('manual', m));
    out.push({
      id: 'H2b', claim: h2b,
      verdict: verdict(models.every((m) => ratio(m) >= rules.h2b_min_total_input_ratio)),
      numbers: `total input tokens per pass: ${models.map((m) => `${m} raw ${int(totalPerPass(cell('raw', m)))} vs manual ${int(totalPerPass(cell('manual', m)))} (${ratio(m).toFixed(2)}x)`).join('; ')}`,
    });
  }

  const { challenger, baseline } = rules.h3;
  const h3 = `${challenger.model} ${challenger.condition} matches or beats ${baseline.model} ${baseline.condition} on accuracy (mean over repetitions)`;
  const sides = [challenger, baseline].map((c) => ({ c, rs: cell(c.condition, c.model) }));
  const h3Missing = sides.find((side) => !side.rs.length);
  if (h3Missing) out.push(unmeasured('H3', h3, `${h3Missing.c.condition} ${h3Missing.c.model}`));
  else {
    const reps = sides.map(({ c, rs }) => {
      const perRep = groupBy(rs, (r) => String(r.rep).padStart(3, '0')).map(([, group]) => accuracyOf(group));
      return { c, perRep, mean: mean(perRep) };
    });
    out.push({
      id: 'H3', claim: h3,
      verdict: verdict(reps[0].mean >= reps[1].mean),
      numbers: reps.map(({ c, perRep, mean: m }) => `${c.model} ${c.condition} ${perRep.map(pct).join(', ')} (mean ${pct(m)}, ${perRep.length} rep${perRep.length === 1 ? '' : 's'})`).join(' vs '),
    });
  }

  const limits = rules.h4_max_est_tokens;
  const h4 = `On the fixture the brief is at most ${int(limits.brief)} estimated tokens and the index at most ${int(limits.index)}`;
  if (!meta.manual) out.push({ id: 'H4', claim: h4, verdict: 'not measured', numbers: 'no site was read' });
  else {
    const m = meta.manual;
    out.push({
      id: 'H4', claim: h4,
      verdict: verdict(m.brief_est_tokens <= limits.brief && m.index_est_tokens <= limits.index),
      numbers: `brief ${int(m.brief_chars)} chars = ${int(m.brief_est_tokens)} est. tokens; index ${int(m.index_chars)} chars = ${int(m.index_est_tokens)} est. tokens (chars / 4, rounded up)`,
    });
  }

  const p90Input = (rs: Result[]) => percentile(rs.map((r) => inputTotal(r.usage)), 90);
  const h5 = `p90 input tokens per question in the manual condition are at least ${rules.h5_min_p90_input_ratio}x lower than the raw p90, on every model`;
  if (h2Missing) out.push(unmeasured('H5', h5, `raw or manual ${h2Missing}`));
  else {
    const ratio = (m: string) => p90Input(cell('raw', m)) / p90Input(cell('manual', m));
    out.push({
      id: 'H5', claim: h5,
      verdict: verdict(models.every((m) => ratio(m) >= rules.h5_min_p90_input_ratio)),
      numbers: `p90 input tokens per question: ${models.map((m) => `${m} raw ${int(p90Input(cell('raw', m)))} vs manual ${int(p90Input(cell('manual', m)))} (${ratio(m).toFixed(2)}x)`).join('; ')}`,
    });
  }

  const h6 = `${challenger.model} ${challenger.condition} costs at least ${rules.h6_min_cost_per_correct_ratio}x less per correct answer than ${baseline.model} ${baseline.condition}`;
  if (h3Missing) out.push(unmeasured('H6', h6, `${h3Missing.c.condition} ${h3Missing.c.model}`));
  else {
    const perCorrect = (rs: Result[]) => {
      const correct = rs.filter((r) => r.correct).length;
      return correct ? sum(rs.map((r) => r.cost_usd)) / correct : Number.POSITIVE_INFINITY;
    };
    const [c, b] = sides.map(({ rs }) => perCorrect(rs));
    const ratio = b / c;
    const shown = (x: number) => (Number.isFinite(x) ? usd(x) : 'no correct answers');
    out.push({
      id: 'H6', claim: h6,
      verdict: verdict(Number.isFinite(ratio) && ratio >= rules.h6_min_cost_per_correct_ratio),
      numbers: `cost per correct answer, pooled over repetitions: ${challenger.model} ${challenger.condition} ${shown(c)} vs ${baseline.model} ${baseline.condition} ${shown(b)} (${Number.isFinite(ratio) ? `${ratio.toFixed(2)}x` : 'not comparable'})`,
    });
  }

  const order = ['H1', 'H2', 'H2b', 'H3', 'H4', 'H5', 'H6'];
  return out.sort((a, b) => order.indexOf(a.id) - order.indexOf(b.id));
}

const passes = (rs: Result[]) => new Set(rs.map((r) => r.rep)).size;
const accuracyOf = (rs: Result[]) => (rs.length ? rs.filter((r) => r.correct).length / rs.length : 0);
const inputs = (rs: Result[]) => rs.map((r) => inputTotal(r.usage));
const totalPerPass = (rs: Result[]) => sum(inputs(rs)) / passes(rs);
const costPerCorrect = (rs: Result[]) => {
  const correct = rs.filter((r) => r.correct).length;
  return correct ? sum(rs.map((r) => r.cost_usd)) / correct : Number.POSITIVE_INFINITY;
};
const perRepAccuracy = (rs: Result[]) => groupBy(rs, (r) => String(r.rep).padStart(3, '0')).map(([, group]) => accuracyOf(group));
const shownUsd = (x: number) => (Number.isFinite(x) ? usd(x) : 'no correct answers');
/** Accuracy pooled over repetitions, with each repetition's figure when there are several. */
const scoredReps = (rs: Result[]) => {
  const reps = perRepAccuracy(rs);
  const head = `${pct(accuracyOf(rs))} (${rs.filter((r) => r.correct).length}/${rs.length})`;
  return reps.length > 1 ? `${head}, per rep ${reps.map(pct).join(', ')}` : head;
};

/**
 * R1 to R7 from the spec (run 3), judged on one fixture by the rules in config.json. The spec
 * claims each on both fixtures, so the run 3 summary passes a rule only where every fixture's report
 * does. Accuracy, medians and p90s pool repetitions; R3 compares mean accuracy over repetitions; R5
 * compares total input per pass over the question set, so a repeated cell counts once.
 */
export function judgeRun3(results: Result[], meta: Pick<ReportMeta, 'models' | 'manual' | 'fixture'>, rules: Run3Rules): Hypothesis[] {
  const cell = (condition: string, model: string) => results.filter((r) => r.condition === condition && r.model === model);
  const models = meta.models.map((m) => m.id);
  const every = models.length === 2 ? 'on both models' : 'on every model';
  const base = rules.baseline;
  const verdict = (ok: boolean) => (ok ? 'pass' : 'fail');
  const unmeasured = (id: string, claim: string, missing: string): Hypothesis => ({ id, claim, verdict: 'not measured', numbers: `no results for ${missing}` });
  const missingFor = (...conditions: string[]) => {
    for (const m of models) for (const c of conditions) if (!cell(c, m).length) return `${c} ${m}`;
    return undefined;
  };
  const out: Hypothesis[] = [];

  const threshold = meta.fixture === undefined ? undefined : rules.r1_min_accuracy[meta.fixture];
  const r1 = `The manual condition reaches at least ${threshold === undefined ? '(no threshold)' : pct(threshold)} accuracy on the ${meta.fixture ?? 'unnamed'} fixture, ${every}`;
  const r1Missing = missingFor('manual');
  if (threshold === undefined) out.push({ id: 'R1', claim: r1, verdict: 'not measured', numbers: `config.json sets no R1 threshold for the ${meta.fixture ?? 'unnamed'} fixture` });
  else if (r1Missing) out.push(unmeasured('R1', r1, r1Missing));
  else {
    out.push({
      id: 'R1', claim: r1,
      verdict: verdict(models.every((m) => accuracyOf(cell('manual', m)) >= threshold)),
      numbers: `manual: ${models.map((m) => `${m} ${scoredReps(cell('manual', m))}`).join('; ')}`,
    });
  }

  // Percentage points, compared on a tenth of a point so floating error cannot decide a tie.
  const gain = (m: string) => Math.round((accuracyOf(cell('manual', m)) - accuracyOf(cell(base, m))) * 1000) / 10;
  const r2 = `The manual condition beats ${base} by at least ${rules.r2_min_gain_points} percentage points of accuracy, ${every}`;
  const pairMissing = missingFor('manual', base);
  if (pairMissing) out.push(unmeasured('R2', r2, pairMissing));
  else {
    out.push({
      id: 'R2', claim: r2,
      verdict: verdict(models.every((m) => gain(m) >= rules.r2_min_gain_points)),
      numbers: models.map((m) => `${m} manual ${pct(accuracyOf(cell('manual', m)))} vs ${base} ${pct(accuracyOf(cell(base, m)))} (${gain(m) >= 0 ? '+' : ''}${gain(m).toFixed(1)} points)`).join('; '),
    });
  }

  const { challenger, baseline } = rules.r3;
  const sides = [challenger, baseline].map((c) => ({ c, rs: cell(c.condition, c.model) }));
  const sideMissing = sides.find((side) => !side.rs.length);
  const r3 = `${challenger.model} ${challenger.condition} matches or beats ${baseline.model} ${baseline.condition} on accuracy (mean over repetitions)`;
  if (sideMissing) out.push(unmeasured('R3', r3, `${sideMissing.c.condition} ${sideMissing.c.model}`));
  else {
    const reps = sides.map(({ c, rs }) => ({ c, perRep: perRepAccuracy(rs), mean: mean(perRepAccuracy(rs)) }));
    out.push({
      id: 'R3', claim: r3,
      verdict: verdict(reps[0].mean >= reps[1].mean),
      numbers: reps.map(({ c, perRep, mean: m }) => `${c.model} ${c.condition} ${perRep.map(pct).join(', ')} (mean ${pct(m)}, ${perRep.length} rep${perRep.length === 1 ? '' : 's'})`).join(' vs '),
    });
  }

  const limits = rules.r4_max_est_tokens;
  const r4 = `The brief is at most ${int(limits.brief)} and the index at most ${int(limits.index)} estimated tokens`;
  if (!meta.manual) out.push({ id: 'R4', claim: r4, verdict: 'not measured', numbers: 'no site was read' });
  else {
    const m = meta.manual;
    out.push({
      id: 'R4', claim: r4,
      verdict: verdict(m.brief_est_tokens <= limits.brief && m.index_est_tokens <= limits.index),
      numbers: `brief ${int(m.brief_chars)} chars = ${int(m.brief_est_tokens)} est. tokens; index ${int(m.index_chars)} chars = ${int(m.index_est_tokens)} est. tokens (chars / 4, rounded up)`,
    });
  }

  const r5 = `Total input tokens across all questions in the manual condition are at least ${rules.r5_min_total_input_ratio}x lower than ${base}, ${every}`;
  if (pairMissing) out.push(unmeasured('R5', r5, pairMissing));
  else {
    const ratio = (m: string) => totalPerPass(cell(base, m)) / totalPerPass(cell('manual', m));
    out.push({
      id: 'R5', claim: r5,
      verdict: verdict(models.every((m) => ratio(m) >= rules.r5_min_total_input_ratio)),
      numbers: `total input tokens per pass: ${models.map((m) => `${m} ${base} ${int(totalPerPass(cell(base, m)))} vs manual ${int(totalPerPass(cell('manual', m)))} (${ratio(m).toFixed(2)}x)`).join('; ')}`,
    });
  }

  const p90 = (rs: Result[]) => percentile(inputs(rs), 90);
  const r6 = `p90 input tokens per question in the manual condition are at least ${rules.r6_min_p90_input_ratio}x lower than the ${base} p90, ${every}`;
  if (pairMissing) out.push(unmeasured('R6', r6, pairMissing));
  else {
    const ratio = (m: string) => p90(cell(base, m)) / p90(cell('manual', m));
    out.push({
      id: 'R6', claim: r6,
      verdict: verdict(models.every((m) => ratio(m) >= rules.r6_min_p90_input_ratio)),
      numbers: `p90 input tokens per question: ${models.map((m) => `${m} ${base} ${int(p90(cell(base, m)))} vs manual ${int(p90(cell('manual', m)))} (${ratio(m).toFixed(2)}x)`).join('; ')}`,
    });
  }

  const r7 = `${challenger.model} ${challenger.condition} costs at least ${rules.r7_min_cost_per_correct_ratio}x less per correct answer than ${baseline.model} ${baseline.condition}`;
  if (sideMissing) out.push(unmeasured('R7', r7, `${sideMissing.c.condition} ${sideMissing.c.model}`));
  else {
    const [c, b] = sides.map(({ rs }) => costPerCorrect(rs));
    const ratio = b / c;
    out.push({
      id: 'R7', claim: r7,
      verdict: verdict(Number.isFinite(ratio) && ratio >= rules.r7_min_cost_per_correct_ratio),
      numbers: `cost per correct answer, pooled over repetitions: ${challenger.model} ${challenger.condition} ${shownUsd(c)} vs ${baseline.model} ${baseline.condition} ${shownUsd(b)} (${Number.isFinite(ratio) ? `${ratio.toFixed(2)}x` : 'not comparable'})`,
    });
  }
  return out;
}

/**
 * The median input ratio, which the spec retired as a hypothesis after runs 1 and 2 and reports as a
 * metric: on easy questions a baseline agent finishes cheaply, and the manual's advantage sits in the
 * tail, which R6 measures.
 */
export function run3Metrics(results: Result[], meta: Pick<ReportMeta, 'models'>, rules: Run3Rules): NonNullable<Report['metrics']> {
  const cell = (condition: string, model: string) => results.filter((r) => r.condition === condition && r.model === model);
  const line = (m: string, other: string) => {
    const [o, w] = [median(inputs(cell(other, m))), median(inputs(cell('manual', m)))];
    return `${m} ${other} ${int(o)} vs manual ${int(w)} (${(o / w).toFixed(2)}x)`;
  };
  const lines = meta.models.flatMap(({ id }) =>
    [rules.baseline, ...BASELINE_CONDITIONS.filter((c) => c !== rules.baseline)].filter((c) => cell(c, id).length && cell('manual', id).length).map((c) => line(id, c)));
  return lines.length ? [{
    id: 'median-input',
    claim: 'Median input tokens per question, baseline against manual. Retired as a hypothesis after runs 1 and 2: on easy questions a baseline agent finishes cheaply, and the manual\'s advantage sits in the long tail, which R6 measures',
    numbers: lines.join('; '),
  }] : [];
}

/**
 * Every comparison on the same model where a baseline cell (raw, adapter or cli) did better than a Wayfinder
 * cell (manual, manual-preloaded or cli-manual): on accuracy, accuracy per category, median, p90 and total input
 * tokens, and cost per correct answer. Strictly better only; a tie is not a loss. Figures pool repetitions.
 */
export function findLosses(results: Result[], models: string[]): NonNullable<Report['losses']> {
  const cell = (condition: string, model: string) => results.filter((r) => r.condition === condition && r.model === model);
  const categories = [...new Set(results.map((r) => r.category))].sort();
  // `within` narrows a cell to the rows a metric reads; a side with none of them is not compared.
  type Metric = { metric: string; within?: (rs: Result[]) => Result[]; value: (rs: Result[]) => number; show: (x: number, rs: Result[]) => string; higherIsBetter: boolean };
  const accuracy = (rs: Result[]) => `${pct(accuracyOf(rs))} (${rs.filter((r) => r.correct).length}/${rs.length})`;
  const metrics: Metric[] = [
    { metric: 'accuracy', value: accuracyOf, show: (_, rs) => accuracy(rs), higherIsBetter: true },
    ...categories.map((category): Metric => ({
      metric: `accuracy, ${category}`, within: (rs) => rs.filter((r) => r.category === category),
      value: accuracyOf, show: (_, rs) => accuracy(rs), higherIsBetter: true,
    })),
    { metric: 'median input tokens', value: (rs) => median(inputs(rs)), show: (x) => int(x), higherIsBetter: false },
    { metric: 'p90 input tokens', value: (rs) => percentile(inputs(rs), 90), show: (x) => int(x), higherIsBetter: false },
    { metric: 'total input tokens per pass', value: totalPerPass, show: (x) => int(x), higherIsBetter: false },
    { metric: 'cost per correct answer', value: costPerCorrect, show: (x) => shownUsd(x), higherIsBetter: false },
  ];
  const out: NonNullable<Report['losses']> = [];
  for (const model of models) {
    for (const wayfinder of WAYFINDER_CONDITIONS) {
      for (const baseline of BASELINE_CONDITIONS) {
        const [wCell, bCell] = [cell(wayfinder, model), cell(baseline, model)];
        if (!wCell.length || !bCell.length) continue;
        for (const m of metrics) {
          const [w, b] = m.within ? [m.within(wCell), m.within(bCell)] : [wCell, bCell];
          if (!w.length || !b.length) continue;
          const [wv, bv] = [m.value(w), m.value(b)];
          if (m.higherIsBetter ? bv > wv : bv < wv) {
            out.push({ model, metric: m.metric, baseline, wayfinder, baseline_value: m.show(bv, b), wayfinder_value: m.show(wv, w) });
          }
        }
      }
    }
  }
  return out;
}

/** Per cell, what conversation caching read and wrote, pooled over repetitions. */
function cacheByCell(results: Result[]): NonNullable<Report['cache_by_cell']> {
  return groupBy(results, (r) => `${r.condition} ${r.model}`).map(([, rs]) => ({
    condition: rs[0].condition, model: rs[0].model, question_runs: rs.length,
    input_total: sum(inputs(rs)),
    uncached_input_tokens: sum(rs.map((r) => r.usage.input_tokens)),
    cache_creation_input_tokens: sum(rs.map((r) => r.usage.cache_creation_input_tokens)),
    cache_read_input_tokens: sum(rs.map((r) => r.usage.cache_read_input_tokens)),
    runs_reading_cache: rs.filter((r) => r.usage.cache_read_input_tokens > 0).length,
  }));
}

/** The intervals for a report's results, pairing each Wayfinder condition with each baseline condition. */
export function intervalsFor(results: Result[], models: string[], rules?: Rules): Intervals {
  return computeIntervals(results, models, rules, { wayfinder: WAYFINDER_CONDITIONS, baseline: BASELINE_CONDITIONS });
}

/** H1 to H6, or R1 to R7, by the rules the report was judged with. */
export function judge(results: Result[], meta: Pick<ReportMeta, 'models' | 'manual' | 'fixture'>, rules: Rules): Hypothesis[] {
  return isRun3(rules) ? judgeRun3(results, meta, rules) : judgeHypotheses(results, meta, rules);
}

/** Per model, whether the cached manual block in manual-preloaded was written to or read from the cache. */
function cachingByModel(results: Result[], models: ReportMeta['models']): Report['caching'] {
  return models.flatMap(({ id, cache_min_tokens }) => {
    const rs = results.filter((r) => r.condition === 'manual-preloaded' && r.model === id);
    if (!rs.length) return [];
    const reads = sum(rs.map((r) => r.usage.cache_read_input_tokens));
    return [{
      model: id, cache_min_tokens, question_runs: rs.length,
      first_call_input_tokens: spread(rs.map((r) => r.first_call_input_tokens)),
      cache_creation_input_tokens: sum(rs.map((r) => r.usage.cache_creation_input_tokens)),
      cache_read_input_tokens: reads,
      runs_reading_cache: rs.filter((r) => r.usage.cache_read_input_tokens > 0).length,
      engaged: reads > 0,
    }];
  });
}

/** The three questions missed most often per condition, over every model and repetition. Ties go to the lower id. */
function mostMissed(results: Result[]): Report['most_missed'] {
  return groupBy(results, (r) => r.condition).flatMap(([condition, rs]) =>
    groupBy(rs, (r) => r.question)
      .map(([question, qs]) => ({ question, qs, missed: qs.filter((r) => !r.correct).length }))
      .filter((q) => q.missed > 0)
      .sort((a, b) => b.missed - a.missed || (a.question < b.question ? -1 : 1))
      .slice(0, 3)
      .map(({ question, qs, missed }) => {
        const wrong = qs.find((r) => !r.correct)!;
        const said = wrong.hit_turn_cap ? '' : `; answered: ${wrong.answer ? oneLine(wrong.answer, 120) : 'no ANSWER line'}`;
        return { condition, question, category: wrong.category, missed, runs: qs.length, reason: oneLine(`${wrong.model}: ${wrong.reason}${said}`, 300) };
      }),
  );
}

const runKey = (r: { condition: string; model: string; rep: number }) => `${r.condition} ${r.model} ${String(r.rep).padStart(3, '0')}`;

/** Counts the named misses as correct. Each must match exactly one wrong result, so a typo cannot pass silently. */
export function applyCorrections(results: Result[], file: CorrectionsFile): Result[] {
  const key = (r: { question: string; condition: string; model: string; rep: number }) => `${r.question} ${runKey(r)}`;
  const wanted = new Map(file.corrections.map((c) => [key(c), c]));
  if (wanted.size !== file.corrections.length) throw new Error('corrections name the same result twice');
  const fixed = results.map((r) => {
    const c = wanted.get(key(r));
    if (!c) return r;
    if (r.correct) throw new Error(`correction ${key(r)} names a result the grader already marked correct`);
    wanted.delete(key(r));
    return { ...r, correct: true, reason: `corrected: ${c.why}` };
  });
  if (wanted.size) throw new Error(`corrections match no result: ${[...wanted.keys()].join('; ')}`);
  return fixed;
}

export function buildReport(results: Result[], meta: ReportMeta, rules: Rules, corrections?: CorrectionsFile): Report {
  // A space sorts before '-', so "manual" groups list ahead of "manual-preloaded".
  const runs = groupBy(results, runKey).map(([, rs]) => {
    const cost = sum(rs.map((r) => r.cost_usd));
    return {
      ...tally(rs),
      rep: rs[0].rep,
      input_tokens: spread(rs.map((r) => inputTotal(r.usage))),
      output_tokens: spread(rs.map((r) => r.usage.output_tokens)),
      total_tokens: spread(rs.map((r) => inputTotal(r.usage) + r.usage.output_tokens)),
      tool_calls: spread(rs.map((r) => r.tool_calls)),
      wall_ms: spread(rs.map((r) => r.wall_ms)),
      cost_per_question_usd: round(cost / rs.length, 6),
      cost_usd: round(cost, 6),
      ...(rs.every((r) => r.tools) && { tools: rs.reduce((t, r) => addTallies(t, r.tools!), {}) }),
    };
  });
  const by_category = groupBy(results, (r) => `${r.condition} ${r.model} ${r.category}`).map(([, rs]) => ({ ...tally(rs), category: rs[0].category }));
  const total = (key: 'cost_usd' | 'judge_cost_usd') => round(sum(results.map((r) => r[key])), 6);
  const totals = { question_runs: results.length, correct: tally(results).correct, cost_usd: total('cost_usd'), judge_cost_usd: total('judge_cost_usd') };
  const fixed = corrections && applyCorrections(results, corrections);
  return ReportSchema.parse({
    ...meta,
    hypotheses: judge(results, meta, rules),
    ...(isRun3(rules) && {
      metrics: run3Metrics(results, meta, rules),
      losses: findLosses(results, meta.models.map((m) => m.id)),
    }),
    ...(meta.conversation_caching && { cache_by_cell: cacheByCell(results) }),
    caching: cachingByModel(results, meta.models),
    most_missed: mostMissed(results),
    runs, by_category, totals, results,
    intervals: intervalsFor(results, meta.models.map((m) => m.id), rules),
    ...(fixed && {
      corrected: {
        ...corrections,
        hypotheses: judge(fixed, meta, rules),
        runs: groupBy(fixed, runKey).map(([, rs]) => ({ ...tally(rs), rep: rs[0].rep })),
      },
    }),
  });
}

const table = (head: string[], rows: (string | number)[][]) =>
  [`| ${head.join(' | ')} |`, `|${head.map(() => '---').join('|')}|`, ...rows.map((r) => `| ${r.join(' | ')} |`)].join('\n');
const escapeCell = (text: string) => text.replace(/\|/g, '\\|');

/** What caching did to a model's manual-preloaded bill, in one sentence. */
function cachingNote(c: Report['caching'][number]): string {
  const first = `the first call read ${int(c.first_call_input_tokens.median)} tokens (median) against a ${int(c.cache_min_tokens)}-token minimum`;
  if (c.engaged) {
    return `${c.model}: cached. ${int(c.cache_read_input_tokens)} tokens came from the cache at the cache-read rate in ${c.runs_reading_cache} of ${c.question_runs} runs, and ${int(c.cache_creation_input_tokens)} were written at the cache-write rate.`;
  }
  const why = c.first_call_input_tokens.median < c.cache_min_tokens ? `The prefix is under the minimum: ${first}.` : `Nothing was read from the cache, although ${first}.`;
  const wrote = c.cache_creation_input_tokens ? ` ${int(c.cache_creation_input_tokens)} tokens were written to the cache and never read.` : '';
  return `${c.model}: not cached. ${why}${wrote} Every call paid the full input rate for the manual, so its manual-preloaded cost is not a cached cost.`;
}

/** Original and corrected numbers side by side, then every re-graded result and why. */
function correctionsSection(r: Report, c: NonNullable<Report['corrected']>): string[] {
  const original = new Map(r.runs.map((s) => [runKey(s), s]));
  return [
    '## Grader corrections',
    `${c.rule} Tracked in ${c.issue}.`,
    table(['', 'Corrected result', 'Numbers'], c.hypotheses.map((h) => [h.id, `**${h.verdict}**`, escapeCell(h.numbers)])),
    table(['Condition', 'Model', 'Rep', 'Graded', 'Corrected'], c.runs.map((s) => {
      const o = original.get(runKey(s))!;
      return [s.condition, s.model, s.rep, `${o.correct}/${o.questions} (${pct(o.accuracy)})`, `${s.correct}/${s.questions} (${pct(s.accuracy)})`];
    })),
    table(['Question', 'Condition', 'Model', 'Rep', 'Why it is correct'], c.corrections.map((x) => [x.question, x.condition, x.model, x.rep, escapeCell(x.why)])),
  ];
}

const RUNNER = {
  local: 'Run locally against a freshly seeded wp-env fixture site.',
  'github-actions': 'Run in GitHub Actions (bench.yml) against a fixture site the workflow built and seeded.',
  'dry-run': 'Replayed from test/recordings/.',
};

/** The losses as a table, or a sentence saying there were none. Shared with the run 3 summary. */
export function lossesSection(losses: NonNullable<Report['losses']>): string {
  const head = ['Model', 'Metric', 'Baseline', 'Wayfinder cell'];
  const rows = losses.map((l) => [l.model, l.metric, `${l.baseline} ${escapeCell(l.baseline_value)}`, `${l.wayfinder} ${escapeCell(l.wayfinder_value)}`]);
  return losses.length ? table(head, rows) : 'None. No baseline cell did better than a Wayfinder cell on any metric listed.';
}

/** Tool calls and error results per cell, with each cell's worst tool. A live run stops on a cell over a limit. */
function toolErrorsSection(runs: Report['runs']): string[] {
  const { min_calls, cell_share, tool_share } = TOOL_ERROR_LIMIT;
  const rows = runs.filter((s) => s.tools).map((s) => {
    const tools = s.tools!;
    const calls = Object.values(tools).reduce((n, t) => n + t.calls, 0);
    const worst = Object.entries(tools).filter(([, t]) => t.errors).sort(([, a], [, b]) => b.errors / b.calls - a.errors / a.calls)[0];
    return [s.condition, s.model, s.rep, calls, toolErrors(tools), worst ? `${worst[0]} ${worst[1].errors}/${worst[1].calls}` : 'none', breaches(tools).length ? '**over**' : 'within'];
  });
  return [
    '## Tool errors',
    `Error results per cell, over every question run in it. A live run checks the cli container before each cli cell and after the last one, and stops on a cell whose tool calls error on more than ${cell_share * 100}% of at least ${min_calls}, or whose one tool errors on more than ${tool_share * 100}% of at least ${min_calls} of its calls.`,
    table(['Condition', 'Model', 'Rep', 'Tool calls', 'Tool errors', 'Worst tool', 'Limits'], rows),
  ];
}

const LOSSES_INTRO ='Every comparison on the same model where a baseline cell (raw, adapter or cli) did better than a Wayfinder cell (manual, manual-preloaded or cli-manual): accuracy, accuracy per category, median, p90 and total input tokens, and cost per correct answer, pooled over repetitions. The list is computed by `findLosses` in `src/report.ts`, which was committed before the run, so nothing is chosen after the fact.';

export function renderMarkdown(r: Report): string {
  const conditions = [...new Set(r.most_missed.map((m) => m.condition))];
  return [
    `# Wayfinder benchmark: ${r.site}, ${r.date}${r.run_label ? ` (${r.run_label})` : ''}`,
    r.dry_run ? '> Dry run. These numbers replay recorded transcripts and measure nothing.' : '',
    ...(r.sensitivity
      ? [
        sensitivitySection(r.sensitivity),
        `The sections below are this run's own report. It ran only the cells listed above, so a rule that reads another cell is not measured here; \`${r.sensitivity.reference.file}\` carries the verdicts.`,
      ]
      : []),
    '## Hypotheses',
    table(['', 'Claim', 'Result', 'Numbers'], r.hypotheses.map((h) => [h.id, h.claim, `**${h.verdict}**`, escapeCell(h.numbers)])),
    r.hypothesis_set === 'run3' ? 'The spec claims R1 to R7 on both fixtures. This report judges them on this fixture; `reports/run3-summary.md` combines the two.' : '',
    r.corrected
      ? `These are the graders' verdicts. With ${r.corrected.corrections.length} misses re-graded by hand (${r.corrected.issue}, see Grader corrections): ` +
        r.corrected.hypotheses.map((h) => `${h.id} ${h.verdict}`).join(', ') + '.'
      : '',
    ...(r.intervals ? [renderIntervals(r.intervals)] : []),
    ...(r.losses ? ['## Losses', LOSSES_INTRO, lossesSection(r.losses)] : []),
    ...(r.metrics?.length ? ['## Metrics without a threshold', table(['', 'What', 'Numbers'], r.metrics.map((m) => [m.id, m.claim, escapeCell(m.numbers)]))] : []),
    '## Most-missed questions',
    'Per condition, over every model and repetition, as the graders scored them. The reason is the grader\'s, from the first miss.',
    ...conditions.map((c) =>
      `### ${c}\n\n` +
      table(['Question', 'Category', 'Missed', 'Reason'], r.most_missed.filter((m) => m.condition === c).map((m) => [m.question, m.category, `${m.missed}/${m.runs}`, escapeCell(m.reason)])),
    ),
    ...(r.corrected ? correctionsSection(r, r.corrected) : []),
    '## Prompt caching (manual-preloaded)',
    'The brief and index sit in a system block marked `cache_control: ephemeral`. The first-call input is everything the first model call read: tools, system prompt, manual and question.',
    r.caching.length
      ? table(
        ['Model', 'Minimum cacheable', 'First-call input median', 'Cache writes', 'Cache reads', 'Runs reading cache', 'Engaged'],
        r.caching.map((c) => [c.model, int(c.cache_min_tokens), int(c.first_call_input_tokens.median), int(c.cache_creation_input_tokens), int(c.cache_read_input_tokens), `${c.runs_reading_cache}/${c.question_runs}`, c.engaged ? 'yes' : 'no']),
      ) + '\n\n' + r.caching.map((c) => `- ${cachingNote(c)}`).join('\n') +
        '\n\nCosts below are what the API billed: uncached input at the input rate, cache writes at 1.25x and cache reads at 0.1x of it (pricing.json). Prompts were not padded to reach a minimum.'
      : 'No manual-preloaded runs.',
    ...(r.cache_by_cell
      ? [
        '## Conversation caching',
        'Every model call put a cache breakpoint on its latest message, so each call read the conversation before its newest turn from the cache once the prefix cleared the model\'s minimum. Input total counts uncached input, cache writes and cache reads; the read share is the part billed at the cache-read rate. Costs in this report are on that basis and are not comparable with runs 1 and 2, which cached only the preloaded manual.',
        table(
          ['Condition', 'Model', 'Runs', 'Input total', 'Uncached', 'Cache writes', 'Cache reads', 'Read share', 'Runs reading cache'],
          r.cache_by_cell.map((c) => [c.condition, c.model, c.question_runs, int(c.input_total), int(c.uncached_input_tokens), int(c.cache_creation_input_tokens), int(c.cache_read_input_tokens), pct(c.input_total ? c.cache_read_input_tokens / c.input_total : 0), `${c.runs_reading_cache}/${c.question_runs}`]),
        ),
      ]
      : []),
    '## Run',
    `${RUNNER[r.runner.where]} ${r.runner.concurrency} concurrent question run${r.runner.concurrency === 1 ? '' : 's'}` +
      (r.runner.concurrency > 1 ? ', so wall times include queueing at the site.' : '.') +
      (r.commit ? ` Harness and plugin at commit \`${r.commit}\`.` : '') +
      (r.max_turns !== undefined ? ` At most ${r.max_turns} model calls per question.` : '') +
      ` Question set ${r.question_set.file}, ${r.question_set.count} questions, sha256 \`${r.question_set.sha256}\`. ` +
      `Pricing from ${r.pricing.source}, fetched ${r.pricing.fetched}. ` +
      `Models: ${r.models.map((m) => `${m.id} ${JSON.stringify(m.params)}`).join(', ')}.` +
      (r.temperature_policy
        ? ` Temperature: none sent to any agent model, so each ran at its provider's default. Judge ${r.temperature_policy.judge.model} ${JSON.stringify(r.temperature_policy.judge.params)}.`
        : ''),
    '## Accuracy',
    table(['Condition', 'Model', 'Rep', 'Correct', 'Accuracy'], r.runs.map((s) => [s.condition, s.model, s.rep, `${s.correct}/${s.questions}`, pct(s.accuracy)])),
    '## Accuracy by category',
    'Pooled over repetitions.',
    table(['Condition', 'Model', 'Category', 'Correct', 'Accuracy'], r.by_category.map((c) => [c.condition, c.model, c.category, `${c.correct}/${c.questions}`, pct(c.accuracy)])),
    '## Tokens and tool calls per question',
    'Input counts uncached input, cache writes and cache reads together.',
    table(
      ['Condition', 'Model', 'Rep', 'Input median', 'Input p90', 'Total median', 'Total p90', 'Tool calls median', 'Tool calls p90', 'Wall median (s)'],
      r.runs.map((s) => [s.condition, s.model, s.rep, s.input_tokens.median, s.input_tokens.p90, s.total_tokens.median, s.total_tokens.p90, s.tool_calls.median, s.tool_calls.p90, (s.wall_ms.median / 1000).toFixed(1)]),
    ),
    ...(r.runs.some((s) => s.tools) ? toolErrorsSection(r.runs) : []),
    '## Cost',
    table(['Condition', 'Model', 'Rep', 'Per question', 'Total'], r.runs.map((s) => [s.condition, s.model, s.rep, usd(s.cost_per_question_usd), usd(s.cost_usd)])),
    `Agent total ${usd(r.totals.cost_usd)}. Judge total ${usd(r.totals.judge_cost_usd)}. ${r.totals.correct}/${r.totals.question_runs} correct.`,
  ].filter(Boolean).join('\n\n').concat('\n');
}

/**
 * Writes `<site>-<date>[-<run_label>]` and `<site>-latest` as JSON and Markdown. Returns the paths written.
 * A sensitivity report leaves `<site>-latest` alone: it covers a few cells, and latest stays the last full run.
 */
export function writeReport(report: Report, dir: string): string[] {
  mkdirSync(dir, { recursive: true });
  const files = { json: `${JSON.stringify(report, null, 2)}\n`, md: renderMarkdown(report) };
  const dated = `${report.site}-${report.date}${report.run_label ? `-${report.run_label}` : ''}`;
  const stems = report.sensitivity ? [dated] : [dated, `${report.site}-latest`];
  const paths = stems.flatMap((stem) => [join(dir, `${stem}.json`), join(dir, `${stem}.md`)]);
  for (const path of paths) writeFileSync(path, path.endsWith('.json') ? files.json : files.md);
  return paths;
}
