import { lossesSection, type Report } from './report.ts';

/** R1 to R7 as the spec words them ("Run 3: hypotheses set 2026-10-02, before run 3"). */
export const RUN3_CLAIMS: Record<string, string> = {
  R1: 'The manual condition reaches at least 90% accuracy on the block fixture and at least 85% on the classic fixture, on both models',
  R2: 'The manual condition beats adapter-only by at least 10 percentage points of accuracy, on both fixtures and both models',
  R3: 'Haiku with the preloaded manual matches or beats Sonnet adapter-only on accuracy (mean over repetitions), on both fixtures',
  R4: 'The brief is at most 600 and the index at most 4,000 estimated tokens, on both fixtures',
  R5: 'Total input tokens across all questions in the manual condition are at least 3x lower than adapter-only, on both fixtures and both models',
  R6: 'p90 input tokens per question in the manual condition are at least 3x lower than adapter-only p90, on both fixtures and both models',
  R7: 'Haiku with the preloaded manual costs at least 10x less per correct answer than Sonnet adapter-only, on both fixtures',
};

type Verdict = Report['hypotheses'][number]['verdict'];

/** A rule holds only where every fixture's report passes it. Any fail fails it; otherwise a gap leaves it unmeasured. */
export function combine(verdicts: (Verdict | undefined)[]): Verdict {
  if (verdicts.some((v) => v === 'fail')) return 'fail';
  return verdicts.every((v) => v === 'pass') ? 'pass' : 'not measured';
}

const usd = (x: number) => `$${x.toFixed(2)}`;
const table = (head: string[], rows: string[][]) =>
  [`| ${head.join(' | ')} |`, `|${head.map(() => '---').join('|')}|`, ...rows.map((r) => `| ${r.join(' | ')} |`)].join('\n');
const escapeCell = (text: string) => text.replace(/\|/g, '\\|');

/**
 * The run 3 verdicts across fixtures, from each fixture's own report: per rule, each fixture's verdict
 * and the combined one, then every fixture's numbers and losses. Reads the reports only; no API calls.
 */
export function renderRun3Summary(reports: { file: string; report: Report }[]): string {
  for (const { file, report } of reports) {
    if (report.hypothesis_set !== 'run3') throw new Error(`${file} was not judged against R1 to R7`);
  }
  const fixtures = reports.map(({ report }) => report.fixture ?? report.site);
  const verdictOf = (report: Report, id: string) => report.hypotheses.find((h) => h.id === id)?.verdict;
  const ids = Object.keys(RUN3_CLAIMS);
  const runs = reports.reduce((n, { report }) => n + report.totals.question_runs, 0);
  const cost = reports.reduce((n, { report }) => n + report.totals.cost_usd, 0);
  const judgeCost = reports.reduce((n, { report }) => n + report.totals.judge_cost_usd, 0);
  return [
    '# Wayfinder benchmark run 3: summary',
    `Run 3 judges R1 to R7 from the spec on ${reports.length} fixtures (${fixtures.join(', ')}), against the adapter-only baseline. Each claim reads "on both fixtures", so a rule passes only where it passes on every fixture. Per-fixture verdicts and numbers come from each fixture's own report; this summary computes nothing else. ${runs} question runs, ${usd(cost)} in agent calls and ${usd(judgeCost)} for the judge. Conversation caching was on in every condition, so these costs are not comparable with runs 1 and 2.`,
    '## Hypotheses',
    table(['', 'Claim', ...fixtures, 'Result'], ids.map((id) => {
      const per = reports.map(({ report }) => verdictOf(report, id));
      return [id, RUN3_CLAIMS[id], ...per.map((v) => v ?? 'missing'), `**${combine(per)}**`];
    })),
    '## Numbers',
    ...reports.map(({ report }, i) =>
      `### ${fixtures[i]}\n\n` +
      table(['', 'Result', 'Numbers'], report.hypotheses.map((h) => [h.id, h.verdict, escapeCell(h.numbers)])) +
      (report.metrics?.length ? '\n\n' + report.metrics.map((m) => `- ${m.id}: ${escapeCell(m.numbers)}. ${m.claim}.`).join('\n') : ''),
    ),
    '## Losses',
    'Every comparison on the same model where a baseline cell (raw or adapter) did better than a Wayfinder cell (manual or manual-preloaded), on either fixture. Computed by `findLosses` in `src/report.ts`, committed before the run.',
    ...reports.map(({ report }, i) => `### ${fixtures[i]}\n\n${lossesSection(report.losses ?? [])}`),
    '## Reports',
    table(['Fixture', 'Report', 'Question runs', 'Correct', 'Agent cost', 'Commit', 'Question set sha256'], reports.map(({ file, report }, i) => [
      fixtures[i], `\`${file}\``, String(report.totals.question_runs), String(report.totals.correct), usd(report.totals.cost_usd),
      report.commit ? `\`${report.commit.slice(0, 12)}\`` : 'unknown', `\`${report.question_set.sha256}\``,
    ])),
  ].join('\n\n').concat('\n');
}
