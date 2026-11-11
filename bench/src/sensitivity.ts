import { z } from 'zod';
import { MAX_TURNS } from './agent.ts';
import type { Report, Result, Run3Rules } from './report.ts';

// Turn cap sensitivity: a run of some cells at another cap, set beside the same cells of a reference run.
// Run 3 ran every condition at 12 model calls, and most baseline misses were runs that reached the cap.
const n = z.number();
const s = z.string();

/** One cell of one run: its repetitions, accuracy pooled over them, cap hits and agent cost. */
const Side = z.object({
  reps: n, question_runs: n, correct: n, accuracy: n, cap_hits: n, cost_usd: n,
  /** Agent cost over correct answers, as R7 counts it. Null when nothing was answered correctly. */
  cost_per_correct_usd: n.nullable(),
});
type Side = z.infer<typeof Side>;

const Where = z.enum(['local', 'github-actions', 'dry-run']);
type Where = z.infer<typeof Where>;

export const Sensitivity = z.object({
  /** The run this one is compared against, as a path from bench/, with its cap and where it ran. */
  reference: z.object({ file: s, max_turns: n, commit: s.optional(), runner: Where }),
  max_turns: n,
  /** Where this run ran. Hosts differ in speed, which moves wall times and not what a capped run can answer. */
  runner: Where,
  cells: z.array(z.object({
    condition: s, model: s,
    reference: Side,
    this_run: Side,
    // Each reference question run is paired with this run's answer to the same question, so these count
    // reference question runs. Together they account for the change in accuracy.
    /** Reference misses that reached the cap, and how many of those this run answered correctly. */
    cap_hit_misses: n,
    converted: n,
    /** Reference misses that did not reach the cap and are correct here, and reference correct runs wrong here. */
    other_misses_now_correct: n,
    correct_now_wrong: n,
  })),
});
export type Sensitivity = z.infer<typeof Sensitivity>;

const sum = (values: number[]) => values.reduce((a, b) => a + b, 0);
const round = (x: number, places: number) => Math.round(x * 10 ** places) / 10 ** places;
const pct = (x: number) => `${(x * 100).toFixed(1)}%`;
const usd = (x: number | null) => (x === null ? 'no correct answers' : `$${x.toFixed(4)}`);
const table = (head: string[], rows: (string | number)[][]) =>
  [`| ${head.join(' | ')} |`, `|${head.map(() => '---').join('|')}|`, ...rows.map((r) => `| ${r.join(' | ')} |`)].join('\n');

function side(rs: Result[]): Side {
  const correct = rs.filter((r) => r.correct).length;
  const cost = sum(rs.map((r) => r.cost_usd));
  return {
    reps: new Set(rs.map((r) => r.rep)).size, question_runs: rs.length, correct, accuracy: round(correct / rs.length, 4),
    cap_hits: rs.filter((r) => r.hit_turn_cap).length, cost_usd: round(cost, 6), cost_per_correct_usd: correct ? round(cost / correct, 6) : null,
  };
}

/**
 * Refuses a reference that cannot be compared with the run: another fixture, another question set, or
 * the same cap. The run checks it before its first API call. Returns the reference's cap.
 */
export function checkReference(reference: { file: string; report: Report }, run: { fixture?: string; sha256: string; maxTurns: number }): number {
  const ref = reference.report;
  const refTurns = ref.max_turns ?? MAX_TURNS;
  if (ref.fixture !== run.fixture) throw new Error(`${reference.file} is the ${ref.fixture ?? 'unnamed'} fixture, this run is ${run.fixture ?? 'unnamed'}`);
  if (ref.question_set.sha256 !== run.sha256) throw new Error(`${reference.file} asked question set ${ref.question_set.sha256}, this run ${run.sha256}`);
  if (refTurns === run.maxTurns) throw new Error(`${reference.file} already ran at ${refTurns} turns, so there is nothing to compare`);
  return refTurns;
}

/**
 * Sets every cell of `report` beside the same cell of the reference report. The two must share a fixture
 * and a question set, and differ in their cap. This run gives each question one answer per cell; the
 * reference may have several repetitions, each paired with that answer. Reference rows for questions this
 * run did not ask are left out, so a `--question` filter narrows both sides alike.
 */
export function turnCapSensitivity(reference: { file: string; report: Report }, report: Report): Sensitivity {
  const ref = reference.report;
  const turns = report.max_turns ?? MAX_TURNS;
  const refTurns = checkReference(reference, { fixture: report.fixture, sha256: report.question_set.sha256, maxTurns: turns });

  const keys = [...new Set(report.results.map((r) => `${r.condition} ${r.model}`))].sort();
  const cells = keys.map((key) => {
    const [condition, model] = key.split(' ');
    const here = report.results.filter((r) => r.condition === condition && r.model === model);
    const answer = new Map<string, boolean>();
    for (const r of here) {
      if (answer.has(r.question)) throw new Error(`${condition} ${model} answered ${r.question} more than once; a sensitivity run compares one repetition`);
      answer.set(r.question, r.correct);
    }
    const there = ref.results.filter((r) => r.condition === condition && r.model === model && answer.has(r.question));
    if (!there.length) throw new Error(`${reference.file} has no ${condition} ${model} results to compare with`);
    const nowCorrect = (r: Result) => answer.get(r.question) === true;
    const capped = there.filter((r) => r.hit_turn_cap);
    return {
      condition, model,
      reference: side(there),
      this_run: side(here),
      cap_hit_misses: capped.length,
      converted: capped.filter(nowCorrect).length,
      other_misses_now_correct: there.filter((r) => !r.correct && !r.hit_turn_cap && nowCorrect(r)).length,
      correct_now_wrong: there.filter((r) => r.correct && !nowCorrect(r)).length,
    };
  });
  return Sensitivity.parse({
    reference: { file: reference.file, max_turns: refTurns, ...(ref.commit && { commit: ref.commit }), runner: ref.runner.where },
    max_turns: turns, runner: report.runner.where, cells,
  });
}

const HOST: Record<Where, string> = { local: 'local wp-env', 'github-actions': 'GitHub Actions runner, wp-env', 'dry-run': 'dry run, replayed from recordings' };
const share = (part: number, whole: number) => `${part} of ${whole} (${pct(whole ? part / whole : 0)})`;

/** The sensitivity block as Markdown, for the top of the report that carries it. */
export function sensitivitySection(x: Sensitivity): string {
  const [a, b] = [x.reference.max_turns, x.max_turns];
  const scored = (v: Side) => `${pct(v.accuracy)} (${v.correct}/${v.question_runs})`;
  return [
    '## Turn cap sensitivity',
    `This run repeats cells of \`${x.reference.file}\`, which ran at ${a} model calls per question, at ${b}. Questions, answer keys and graders are the same; a run that reaches the cap with no answer counts as wrong at either cap. ` +
      `Accuracy at ${a} pools the reference run's repetitions. Every reference question run is paired with this run's answer to the same question, so the movement columns count reference question runs and add up to the change in correct answers. ` +
      'Costs are agent calls only, priced as in the reference, and cost per correct answer is agent cost over correct answers, as R7 counts it.',
    `Where each ran: this run on ${HOST[x.runner]}; the reference on ${HOST[x.reference.runner]}. A run is capped by model calls, not time, so host speed moves wall times and not what a run can answer.`,
    `Runs that still reached the ${b}-turn cap: ${x.cells.map((c) => `${c.condition} ${c.model} ${share(c.this_run.cap_hits, c.this_run.question_runs)}`).join('; ')}.`,
    table(
      ['Condition', 'Model', `Reps at ${a}`, `Accuracy at ${a}`, `Accuracy at ${b}`, `Cap-hit misses at ${a}`, `Converted at ${b}`, 'Other misses now correct', `Correct at ${a}, wrong at ${b}`, `Cap hits at ${b}`],
      x.cells.map((c) => [
        c.condition, c.model, c.reference.reps, scored(c.reference), scored(c.this_run),
        `${c.cap_hit_misses} of ${c.reference.question_runs - c.reference.correct}`, c.converted, c.other_misses_now_correct, c.correct_now_wrong,
        share(c.this_run.cap_hits, c.this_run.question_runs),
      ]),
    ),
    table(
      ['Condition', 'Model', `Cost at ${a}, all reps`, `Cost per correct at ${a}`, `Cost at ${b}`, `Cost per correct at ${b}`],
      x.cells.map((c) => [c.condition, c.model, usd(c.reference.cost_usd), usd(c.reference.cost_per_correct_usd), usd(c.this_run.cost_usd), usd(c.this_run.cost_per_correct_usd)]),
    ),
  ].join('\n\n');
}

export const BEST_BASELINE_HEADING = '## Best baseline per cell';

/**
 * For each fixture and model, the higher of raw and adapter at the reference cap and at the sensitivity
 * cap, beside the Wayfinder cells, with R2 and R3 restated against that best baseline. Reads the
 * sensitivity reports and the reference reports they name; no API calls.
 */
export function renderBestBaseline(pairs: { file: string; report: Report; reference: Report }[], rules: Run3Rules): string {
  const lines: string[] = [];
  const r2Rows: string[][] = [];
  const r3Rows: string[][] = [];
  const r2Pass = { low: true, high: true };
  const r3Pass = { low: true, high: true };
  let low = MAX_TURNS;
  let high = MAX_TURNS;
  const rows = pairs.flatMap(({ file, report, reference }) => {
    if (!report.sensitivity) throw new Error(`${file} carries no turn cap sensitivity`);
    low = report.sensitivity.reference.max_turns;
    high = report.sensitivity.max_turns;
    const fixture = reference.fixture ?? reference.site;
    const cell = (r: Report, condition: string, model: string) => r.results.filter((x) => x.condition === condition && x.model === model);
    const acc = (rs: Result[]) => (rs.length ? rs.filter((x) => x.correct).length / rs.length : undefined);
    const best = (r: Report, model: string) => {
      const scores = ['raw', 'adapter'].map((c) => ({ c, a: acc(cell(r, c, model)) })).filter((x) => x.a !== undefined) as { c: string; a: number }[];
      if (!scores.length) throw new Error(`no raw or adapter results for ${model} in the ${fixture} reports`);
      const top = Math.max(...scores.map((x) => x.a));
      return { a: top, name: scores.filter((x) => x.a === top).map((x) => x.c).join(' and ') };
    };
    const shown = (a: number | undefined) => (a === undefined ? 'not run' : pct(a));
    const gain = (w: number, b: number) => Math.round((w - b) * 1000) / 10;
    const signed = (g: number) => `${g >= 0 ? '+' : ''}${g.toFixed(1)}`;
    const capped = reference.results.filter((x) => (x.condition === 'manual' || x.condition === 'manual-preloaded') && x.hit_turn_cap).length;
    const x = report.sensitivity;
    lines.push(
      `${fixture}: the Wayfinder cells are run 3's, at ${low} turns on ${HOST[x.reference.runner]}, and reached the cap in ${capped} of ${reference.results.filter((r) => r.condition === 'manual' || r.condition === 'manual-preloaded').length} runs. ` +
        `The baseline cells at ${high} turns ran on ${HOST[x.runner]} and still reached the cap in: ${x.cells.map((c) => `${c.condition} ${c.model} ${share(c.this_run.cap_hits, c.this_run.question_runs)}`).join(', ')}.`,
    );

    const models = reference.models.map((m) => m.id);
    for (const model of models) {
      const manual = acc(cell(reference, 'manual', model));
      const [b12, b24] = [best(reference, model), best(report, model)];
      if (manual !== undefined) {
        const [g12, g24] = [gain(manual, b12.a), gain(manual, b24.a)];
        r2Pass.low &&= g12 >= rules.r2_min_gain_points;
        r2Pass.high &&= g24 >= rules.r2_min_gain_points;
        r2Rows.push([fixture, model, pct(manual), `${b12.name} ${pct(b12.a)}`, signed(g12), `${b24.name} ${pct(b24.a)}`, signed(g24)]);
      }
    }
    const { challenger, baseline } = rules.r3;
    const c = acc(cell(reference, challenger.condition, challenger.model));
    if (c !== undefined) {
      const [b12, b24] = [best(reference, baseline.model), best(report, baseline.model)];
      r3Pass.low &&= c >= b12.a;
      r3Pass.high &&= c >= b24.a;
      r3Rows.push([fixture, pct(c), `${b12.name} ${pct(b12.a)}`, c >= b12.a ? 'pass' : 'fail', `${b24.name} ${pct(b24.a)}`, c >= b24.a ? 'pass' : 'fail']);
    }
    return models.map((model) => {
      const [b12, b24] = [best(reference, model), best(report, model)];
      return [
        fixture, model,
        shown(acc(cell(reference, 'raw', model))), shown(acc(cell(reference, 'adapter', model))), `${b12.name} ${pct(b12.a)}`,
        shown(acc(cell(report, 'raw', model))), shown(acc(cell(report, 'adapter', model))), `${b24.name} ${pct(b24.a)}`,
        shown(acc(cell(reference, 'manual', model))), shown(acc(cell(reference, 'manual-preloaded', model))),
      ];
    });
  });
  const verdict = (ok: boolean) => `**${ok ? 'pass' : 'fail'}**`;
  const { challenger, baseline } = rules.r3;
  return [
    BEST_BASELINE_HEADING,
    `Added after the turn cap sensitivity run. It changes no number above. For every fixture and model it takes the higher of raw and adapter, at ${low} turns from the run 3 reports and at ${high} turns from the sensitivity reports, and sets the Wayfinder cells beside it. ` +
      'Choosing the stronger baseline per cell after both were seen gives the baseline every advantage, so a rule that still passes does not depend on which baseline was named before the run. ' +
      `Accuracy pools repetitions, which equals the mean over repetitions because every repetition asks the whole question set: 3 for adapter, manual and Haiku manual-preloaded at ${low} turns, 1 for raw, Sonnet manual-preloaded and every cell at ${high}. ` +
      'Computed by `renderBestBaseline` in `src/sensitivity.ts`.',
    lines.map((l) => `- ${l}`).join('\n'),
    table(['Fixture', 'Model', `Raw at ${low}`, `Adapter at ${low}`, `Best at ${low}`, `Raw at ${high}`, `Adapter at ${high}`, `Best at ${high}`, 'Manual', 'Manual-preloaded'], rows),
    `### R2 against the best baseline`,
    `R2 asks the manual condition to beat the baseline by at least ${rules.r2_min_gain_points} percentage points of accuracy on both fixtures and both models.`,
    table(['Fixture', 'Model', 'Manual', `Best at ${low}`, 'Gain', `Best at ${high}`, 'Gain'], r2Rows),
    `Against the best baseline at ${low} turns: ${verdict(r2Pass.low)}. At ${high} turns: ${verdict(r2Pass.high)}.`,
    `### R3 against the best baseline`,
    `R3 asks ${challenger.model} ${challenger.condition} to match or beat ${baseline.model} on the baseline, on both fixtures.`,
    table(['Fixture', `${challenger.model} ${challenger.condition}`, `${baseline.model} best at ${low}`, 'Result', `${baseline.model} best at ${high}`, 'Result'], r3Rows),
    `Against the best baseline at ${low} turns: ${verdict(r3Pass.low)}. At ${high} turns: ${verdict(r3Pass.high)}.`,
  ].join('\n\n').concat('\n');
}
