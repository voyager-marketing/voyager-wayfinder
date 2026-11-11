import { BASELINE_CONDITIONS, judgeRun3, lossesSection, percentile, type Report, type Result, type Run3Rules } from './report.ts';
import { combine, RUN3_CLAIMS } from './summary.ts';

/** The best-baseline heading the run 4 summary carries, which the issue's fitness metric greps for. */
export const RUN4_BEST_BASELINE_HEADING = '## Best baseline per cell';

/** The condition name the best baseline per cell is judged under, so `judgeRun3` reads it like any baseline. */
export const BEST = 'best-baseline';

type Named = { file: string; report: Report };
type Verdict = Report['hypotheses'][number]['verdict'];

const pct = (x: number) => `${(x * 100).toFixed(1)}%`;
const int = (x: number) => Math.round(x).toLocaleString('en-US');
const usd2 = (x: number) => `$${x.toFixed(2)}`;
const usd4 = (x: number) => (Number.isFinite(x) ? `$${x.toFixed(4)}` : 'n/a');
const sum = (xs: number[]) => xs.reduce((a, b) => a + b, 0);
const table = (head: string[], rows: string[][]) =>
  [`| ${head.join(' | ')} |`, `|${head.map(() => '---').join('|')}|`, ...rows.map((r) => `| ${r.join(' | ')} |`)].join('\n');
const escapeCell = (text: string) => text.replace(/\|/g, '\\|');
const signed = (points: number) => `${points >= 0 ? '+' : ''}${points.toFixed(1)}`;
/** Percentage points between two accuracies, on a tenth of a point so floating error cannot decide a tie. */
const points = (a: number, b: number) => Math.round((a - b) * 1000) / 10;

const cellOf = (rs: Result[], condition: string, model: string) => rs.filter((r) => r.condition === condition && r.model === model);
const accuracy = (rs: Result[]) => (rs.length ? rs.filter((r) => r.correct).length / rs.length : NaN);
const inputOf = (r: Result) => r.usage.input_tokens + r.usage.cache_creation_input_tokens + r.usage.cache_read_input_tokens;
const passes = (rs: Result[]) => new Set(rs.map((r) => r.rep)).size || 1;
const totalPerPass = (rs: Result[]) => sum(rs.map(inputOf)) / passes(rs);
const costPerCorrect = (rs: Result[]) => sum(rs.map((r) => r.cost_usd)) / rs.filter((r) => r.correct).length;
const scored = (rs: Result[]) => `${pct(accuracy(rs))} (${rs.filter((r) => r.correct).length}/${rs.length})`;

/** A report's set name: the fixture for the two frozen sets, the question set for the edit-safety one. */
export function setName(report: Report): string {
  return report.site === 'fixture-edit-safety' ? 'edit-safety' : report.fixture ?? report.site;
}

/**
 * The stronger of raw and adapter for one model: higher accuracy, pooled over repetitions. On a tie the
 * one with fewer total input tokens per pass wins, which is the harder of the two for the token rules.
 */
export function bestBaseline(results: Result[], model: string): { condition: string; accuracy: number; tied: boolean } {
  const scores = BASELINE_CONDITIONS.map((condition) => ({ condition, rs: cellOf(results, condition, model) }))
    .filter((x) => x.rs.length)
    .map((x) => ({ ...x, accuracy: accuracy(x.rs), input: totalPerPass(x.rs) }));
  if (!scores.length) throw new Error(`no raw or adapter results for ${model}`);
  scores.sort((a, b) => b.accuracy - a.accuracy || a.input - b.input);
  return { condition: scores[0].condition, accuracy: scores[0].accuracy, tied: scores.length > 1 && scores[1].accuracy === scores[0].accuracy };
}

/**
 * R1 to R7 on one report with the baseline swapped, model by model, for the best baseline per cell. The
 * best cell's results are relabelled `best-baseline` and judged by `judgeRun3` with that as the baseline
 * and as R3's and R7's Sonnet side, so every rule is computed exactly as in the report itself.
 */
export function judgeAgainstBest(report: Report, rules: Run3Rules): { hypotheses: Report['hypotheses']; best: Record<string, ReturnType<typeof bestBaseline>> } {
  const models = report.models.map((m) => m.id);
  const best = Object.fromEntries(models.map((m) => [m, bestBaseline(report.results, m)]));
  const relabelled = [
    ...report.results.filter((r) => !BASELINE_CONDITIONS.includes(r.condition)),
    ...models.flatMap((m) => cellOf(report.results, best[m].condition, m).map((r) => ({ ...r, condition: BEST }))),
  ];
  const swapped: Run3Rules = { ...rules, baseline: BEST, r3: { ...rules.r3, baseline: { ...rules.r3.baseline, condition: BEST } } };
  const hypotheses = judgeRun3(relabelled, { models: report.models, manual: report.manual, fixture: report.fixture }, swapped).map((h) => ({
    ...h,
    claim: h.claim.replaceAll(BEST, 'the best baseline'),
    numbers: models
      .reduce((text, m) => text.replaceAll(`${m} ${BEST}`, `${m} best (${best[m].condition})`), h.numbers)
      // R2 to R6 put one model per `; ` segment, with the baseline unprefixed inside it.
      .split('; ')
      .map((part) => {
        const m = models.find((id) => part.startsWith(id) || part.includes(`: ${id} `));
        return m ? part.replaceAll(BEST, `best (${best[m].condition})`) : part.replaceAll(BEST, 'best');
      })
      .join('; '),
  }));
  return { hypotheses, best };
}

/** Per cell, run 3 against run 4 on the same question set: accuracy, p90 input and cost per correct answer. */
function comparisonRows(run3: Report, run4: Report): string[][] {
  const cells = [...new Map(run4.results.map((r) => [`${r.condition} ${r.model}`, { condition: r.condition, model: r.model }])).values()];
  const order = ['raw', 'adapter', 'manual', 'manual-preloaded'];
  cells.sort((a, b) => a.model.localeCompare(b.model) || order.indexOf(a.condition) - order.indexOf(b.condition));
  return cells.map(({ condition, model }) => {
    const [a, b] = [cellOf(run3.results, condition, model), cellOf(run4.results, condition, model)];
    const p90 = (rs: Result[]) => percentile(rs.map(inputOf), 90);
    return [
      model, condition, String(passes(b)),
      a.length ? scored(a) : 'not run', scored(b), a.length ? signed(points(accuracy(b), accuracy(a))) : '',
      a.length ? int(p90(a)) : '', int(p90(b)),
      a.length ? usd4(costPerCorrect(a)) : '', usd4(costPerCorrect(b)),
    ];
  });
}

/**
 * The run 4 summary: R1 to R7 on the two frozen sets as each report judged them, every cell beside run 3's
 * on the same set, the best baseline per cell with R1 to R7 restated against it, and the edit-safety set on
 * its own. Reads the reports only; no API calls. A run 4 report is paired with the run 3 report whose
 * question set sha256 it shares; one with no partner is a new set.
 */
export function renderRun4Summary(run4: Named[], run3: Named[], rules: Run3Rules): string {
  for (const { file, report } of [...run4, ...run3]) {
    if (report.hypothesis_set !== 'run3') throw new Error(`${file} was not judged against R1 to R7`);
  }
  const paired = run4.map((r) => ({ ...r, run3: run3.find((p) => p.report.question_set.sha256 === r.report.question_set.sha256) }));
  const frozen = paired.filter((p) => p.run3);
  const fresh = paired.filter((p) => !p.run3);
  if (!frozen.length) throw new Error('no run 4 report shares a question set with a run 3 report');
  const names = paired.map((p) => setName(p.report));
  const frozenNames = frozen.map((p) => setName(p.report));
  const verdictOf = (report: Report, id: string): Verdict | undefined => report.hypotheses.find((h) => h.id === id)?.verdict;
  const ids = Object.keys(RUN3_CLAIMS);
  const runs = sum(run4.map(({ report }) => report.totals.question_runs));
  const cost = sum(run4.map(({ report }) => report.totals.cost_usd));
  const judgeCost = sum(run4.map(({ report }) => report.totals.judge_cost_usd));
  const against = paired.map((p) => ({ ...p, judged: judgeAgainstBest(p.report, rules) }));
  const commits = [...new Set(run4.map(({ report }) => report.commit ?? 'unknown'))];

  const bestRows = against.flatMap(({ report, judged }) => report.models.map(({ id }) => [
    setName(report), id,
    ...['raw', 'adapter'].map((c) => (cellOf(report.results, c, id).length ? pct(accuracy(cellOf(report.results, c, id))) : 'not run')),
    `${judged.best[id].tied ? 'raw and adapter tied, ' : ''}${judged.best[id].condition} ${pct(judged.best[id].accuracy)}`,
    ...['manual', 'manual-preloaded'].map((c) => (cellOf(report.results, c, id).length ? pct(accuracy(cellOf(report.results, c, id))) : 'not run')),
  ]));

  return [
    '# Wayfinder benchmark run 4: summary',
    `Run 4 asks the two frozen question sets of run 3 again, on plugin ${commits.map((c) => `\`${c.slice(0, 12)}\``).join(', ')}, and adds the edit-safety set. ` +
      `Same conditions, models, repetitions, 12-turn cap and conversation caching as run 3. ${runs} question runs, ${usd2(cost)} in agent calls and ${usd2(judgeCost)} for the judge. ` +
      'Per-set verdicts and numbers come from each set\'s own report; the comparison and best-baseline sections below recompute from those reports\' results with `src/run4.ts`.',
    '## Hypotheses, frozen sets, against adapter',
    `R1 to R7 as the spec words them, against the adapter-only baseline named before run 3, on the ${frozenNames.join(' and ')} sets. A rule passes only where it passes on every set.`,
    table(['', 'Claim', ...frozenNames.map((n) => `${n} run 3`), ...frozenNames.map((n) => `${n} run 4`), 'Run 3', 'Run 4'], ids.map((id) => {
      const r3 = frozen.map((p) => verdictOf(p.run3!.report, id));
      const r4 = frozen.map((p) => verdictOf(p.report, id));
      return [id, RUN3_CLAIMS[id], ...r3.map((v) => v ?? 'missing'), ...r4.map((v) => v ?? 'missing'), combine(r3), `**${combine(r4)}**`];
    })),
    '## Numbers',
    ...paired.map(({ report }, i) =>
      `### ${names[i]}\n\n` +
      table(['', 'Result', 'Numbers'], report.hypotheses.map((h) => [h.id, h.verdict, escapeCell(h.numbers)])) +
      (report.metrics?.length ? '\n\n' + report.metrics.map((m) => `- ${m.id}: ${escapeCell(m.numbers)}. ${m.claim}.`).join('\n') : ''),
    ),
    '## Run 3 against run 4, cell by cell',
    'Same question set, same cell. Accuracy pools repetitions. p90 is input tokens per question, cost is agent cost per correct answer. The edit-safety set has no run 3 counterpart and is not in this section.',
    ...frozen.map((p) =>
      `### ${setName(p.report)}\n\n` +
      table(['Model', 'Condition', 'Passes', 'Run 3', 'Run 4', 'Change (points)', 'p90 input, run 3', 'p90 input, run 4', 'Cost per correct, run 3', 'Cost per correct, run 4'], comparisonRows(p.run3!.report, p.report)),
    ),
    RUN4_BEST_BASELINE_HEADING,
    'For every set and model the best baseline is the higher of raw and adapter on accuracy, pooled over repetitions (3 passes for adapter, 1 for raw); a tie goes to the one with fewer total input tokens per pass. ' +
      'Choosing it after both were seen gives the baseline every advantage, so a rule that still passes does not depend on which baseline was named before the run. All cells are run 4\'s, at 12 turns. Computed by `judgeAgainstBest` in `src/run4.ts`.',
    table(['Set', 'Model', 'Raw', 'Adapter', 'Best baseline', 'Manual', 'Manual-preloaded'], bestRows),
    '### R1 to R7 against the best baseline',
    'Each rule is judged by `judgeRun3`, unchanged, with the best baseline per model in place of adapter-only: R2, R5 and R6 compare each model\'s manual cell with that model\'s best baseline, and R3 and R7 compare Haiku manual-preloaded with Sonnet\'s. R1 and R4 read no baseline and repeat the verdicts above.',
    table(['', ...names, `Frozen sets (${frozenNames.join(', ')})`], ids.map((id) => {
      const per = against.map((a) => a.judged.hypotheses.find((h) => h.id === id)?.verdict);
      const frozenPer = against.filter((a) => a.run3).map((a) => a.judged.hypotheses.find((h) => h.id === id)?.verdict);
      return [id, ...per.map((v) => v ?? 'missing'), `**${combine(frozenPer)}**`];
    })),
    ...against.map(({ report, judged }) =>
      `#### ${setName(report)}\n\n` + table(['', 'Result', 'Numbers'], judged.hypotheses.map((h) => [h.id, h.verdict, escapeCell(h.numbers)])),
    ),
    ...fresh.map(({ report }) => [
      `## The ${setName(report)} set`,
      `${report.question_set.count} questions, frozen before any run saw them (sha256 \`${report.question_set.sha256}\`), on the ${report.fixture ?? report.site} fixture. ` +
        `It has no run 3 counterpart. R1 uses the ${report.fixture ?? report.site} threshold. Its R1 to R7 verdicts are in "Numbers" above and against the best baseline in the section before this one.`,
      '### Accuracy by category',
      table(['Model', 'Condition', ...[...new Set(report.results.map((r) => r.category))].sort(), 'All'], (() => {
        const categories = [...new Set(report.results.map((r) => r.category))].sort();
        const cells = [...new Map(report.results.map((r) => [`${r.condition} ${r.model}`, { condition: r.condition, model: r.model }])).values()];
        const order = ['raw', 'adapter', 'manual', 'manual-preloaded'];
        cells.sort((a, b) => a.model.localeCompare(b.model) || order.indexOf(a.condition) - order.indexOf(b.condition));
        return cells.map(({ condition, model }) => {
          const rs = cellOf(report.results, condition, model);
          return [model, condition, ...categories.map((c) => scored(rs.filter((r) => r.category === c))), scored(rs)];
        });
      })()),
      '### Most missed',
      report.most_missed.length
        ? table(['Condition', 'Question', 'Category', 'Missed', 'Reason'], report.most_missed.slice(0, 10).map((m) => [m.condition, m.question, m.category, `${m.missed}/${m.runs}`, escapeCell(m.reason)]))
        : 'None.',
    ].join('\n\n')),
    '## Losses',
    'Every comparison on the same model where a baseline cell (raw or adapter) did better than a Wayfinder cell (manual or manual-preloaded). Computed by `findLosses` in `src/report.ts` and stored in each report; nothing here is picked by hand.',
    ...paired.map(({ report }) => `### ${setName(report)}\n\n${lossesSection(report.losses ?? [])}`),
    '## Reports',
    table(['Set', 'Report', 'Question runs', 'Correct', 'Agent cost', 'Runner', 'Commit', 'Question set sha256'], paired.map(({ file, report }) => [
      setName(report), `\`${file}\``, String(report.totals.question_runs), String(report.totals.correct), usd2(report.totals.cost_usd),
      report.runner.where, report.commit ? `\`${report.commit.slice(0, 12)}\`` : 'unknown', `\`${report.question_set.sha256}\``,
    ])),
  ].join('\n\n').concat('\n');
}
