import { inputTotal } from './usage.ts';
import { median, percentile, type Report } from './report.ts';

const pct = (x: number) => `${(x * 100).toFixed(1)}%`;
const int = (x: number) => Math.round(x).toLocaleString('en-US');
const table = (head: string[], rows: string[][]) =>
  [`| ${head.join(' | ')} |`, `|${head.map(() => '---').join('|')}|`, ...rows.map((r) => `| ${r.join(' | ')} |`)].join('\n');

/**
 * Reports side by side: each hypothesis's verdict, then accuracy and input tokens per cell, pooled
 * over repetitions. A hypothesis or cell a report lacks shows a dash. Order follows the first report
 * that has the row.
 */
export function renderComparison(columns: { label: string; report: Report }[]): string {
  const labels = columns.map((c) => c.label);
  const ids = [...new Set(columns.flatMap((c) => c.report.hypotheses.map((h) => h.id)))];
  const order = ['H1', 'H2', 'H2b', 'H3', 'H4', 'H5', 'H6', 'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7'];
  ids.sort((a, b) => (order.indexOf(a) + 1 || 99) - (order.indexOf(b) + 1 || 99));

  const verdicts = ids.map((id) => [id, ...columns.map((c) => c.report.hypotheses.find((h) => h.id === id)?.verdict ?? '-')]);

  const cells = [...new Set(columns.flatMap((c) => c.report.results.map((r) => `${r.condition} ${r.model}`)))];
  const stat = (report: Report, cell: string, f: (rs: Report['results']) => string) => {
    const rs = report.results.filter((r) => `${r.condition} ${r.model}` === cell);
    return rs.length ? f(rs) : '-';
  };
  const accuracy = (rs: Report['results']) => {
    const correct = rs.filter((r) => r.correct).length;
    return `${pct(correct / rs.length)} (${correct}/${rs.length})`;
  };
  const input = (rs: Report['results']) => {
    const values = rs.map((r) => inputTotal(r.usage));
    return `${int(median(values))} / ${int(percentile(values, 90))}`;
  };

  return [
    '## Run 1 and run 2 side by side',
    '',
    table(['Hypothesis', ...labels], verdicts),
    '',
    'Accuracy per cell, pooled over repetitions:',
    '',
    table(['Cell', ...labels], cells.map((cell) => [cell, ...columns.map((c) => stat(c.report, cell, accuracy))])),
    '',
    'Input tokens per question, median / p90, pooled over repetitions:',
    '',
    table(['Cell', ...labels], cells.map((cell) => [cell, ...columns.map((c) => stat(c.report, cell, input))])),
    '',
  ].join('\n');
}
