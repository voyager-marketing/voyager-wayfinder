import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { ReportSchema, type Report } from '../src/report.ts';
import { BENCH_DIR, loadConfig } from '../src/run.ts';
import { bestBaseline, judgeAgainstBest, renderRun4Summary, RUN4_BEST_BASELINE_HEADING } from '../src/run4.ts';

const HAIKU = 'claude-haiku-4-5-20251001';
const SONNET = 'claude-sonnet-5';
const read = (file: string) => ReportSchema.parse(JSON.parse(readFileSync(join(BENCH_DIR, file), 'utf8')));
const BLOCK = 'reports/fixture-2026-10-03-run3.json';
const CLASSIC = 'reports/fixture-classic-2026-10-03-run3.json';
const rules = loadConfig().config.run3_hypotheses;

describe('run 4 summary', () => {
  const block = read(BLOCK);
  const classic = read(CLASSIC);

  it('picks the best baseline per cell as run 3\'s best-baseline table does', () => {
    expect(bestBaseline(block.results, HAIKU)).toMatchObject({ condition: 'raw', tied: false });
    expect(bestBaseline(block.results, SONNET)).toMatchObject({ condition: 'adapter' });
    expect(bestBaseline(classic.results, HAIKU)).toMatchObject({ condition: 'adapter' });
    expect(bestBaseline(classic.results, SONNET)).toMatchObject({ condition: 'raw' });
  });

  it('breaks an accuracy tie toward fewer input tokens', () => {
    const tied = block.results.filter((r) => r.model === HAIKU && (r.condition === 'raw' || (r.condition === 'adapter' && r.rep === 1)))
      .map((r) => ({ ...r, correct: r.question < 'el', usage: r.condition === 'raw' ? { ...r.usage, input_tokens: r.usage.input_tokens + 1e6 } : r.usage }));
    expect(bestBaseline(tied, HAIKU)).toEqual(expect.objectContaining({ condition: 'adapter', tied: true }));
  });

  it('judges R1 to R7 with judgeRun3 against the best baseline, and names it in the numbers', () => {
    const { hypotheses } = judgeAgainstBest(block, rules);
    const r2 = hypotheses.find((h) => h.id === 'R2')!;
    expect(r2.verdict).toBe('fail');
    expect(r2.numbers).toContain(`${HAIKU} manual 97.5% vs best (raw) 60.0% (+37.5 points)`);
    expect(r2.numbers).toContain(`${SONNET} manual 94.2% vs best (adapter) 88.3% (+5.8 points)`);
    expect(hypotheses.map((h) => h.numbers).join(' ')).not.toContain('best-baseline');
    // R1 and R4 read no baseline, so they match the report's own verdicts.
    for (const id of ['R1', 'R4']) expect(hypotheses.find((h) => h.id === id)).toEqual(block.hypotheses.find((h) => h.id === id));
  });

  it('pairs each run 4 report with the run 3 report on the same question set, and gives a new set its own section', () => {
    const fresh: Report = { ...block, site: 'fixture-edit-safety', question_set: { ...block.question_set, file: 'questions/fixture-edit-safety.yaml', sha256: 'f'.repeat(64), count: 40 } };
    const md = renderRun4Summary(
      [{ file: 'a-run4.json', report: block }, { file: 'b-run4.json', report: classic }, { file: 'e-run4.json', report: fresh }],
      [{ file: BLOCK, report: block }, { file: CLASSIC, report: classic }],
      rules,
    );
    expect(md).toContain(RUN4_BEST_BASELINE_HEADING);
    expect(md).toContain('## The edit-safety set');
    expect(md).toContain('### block\n\n| Model | Condition | Passes | Run 3 | Run 4 |');
    expect(md).toContain(`| ${HAIKU} | raw | 1 | 60.0% (24/40) | 60.0% (24/40) | +0.0 |`);
    expect(md).toMatch(/\| R2 \| [^\n]+\| fail \| pass \| fail \| pass \| fail \| \*\*fail\*\* \|/);
    expect(md).not.toContain('missing');
  });

  it('refuses when no run 4 report shares a question set with run 3', () => {
    const other: Report = { ...block, question_set: { ...block.question_set, sha256: '0'.repeat(64) } };
    expect(() => renderRun4Summary([{ file: 'x', report: other }], [{ file: BLOCK, report: block }], rules)).toThrow('no run 4 report shares');
  });
});
