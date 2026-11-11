import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { renderComparison } from '../src/compare.ts';
import { finalText, transcriptName } from '../src/regrade.ts';
import { runBench, type Transcript } from '../src/run.ts';

describe('re-grading from transcripts', () => {
  it('names a transcript the way the bench wrote it', () => {
    expect(transcriptName({ condition: 'raw', model: 'claude-sonnet-5', question: 'el-06', rep: 1 })).toBe('raw.claude-sonnet-5.el-06.json');
    expect(transcriptName({ condition: 'raw', model: 'claude-sonnet-5', question: 'el-06', rep: 3 })).toBe('raw.claude-sonnet-5.el-06.r3.json');
  });

  it('reads the final text from the last turn only, text blocks joined', () => {
    const turn = (texts: string[]) => ({ response: { content: texts.map((text) => ({ type: 'text', text })) }, tool_results: [] });
    const t = { turns: [turn(['Looking.']), turn(['The menu is here.', 'ANSWER: site-editor.php'])] } as unknown as Transcript;
    expect(finalText(t)).toBe('The menu is here.\nANSWER: site-editor.php');
    expect(finalText({ turns: [] } as unknown as Transcript)).toBe('');
  });
});

describe('comparison', () => {
  it('puts each report in a column with hypotheses in spec order and cells pooled', async () => {
    vi.stubGlobal('fetch', () => Promise.reject(new Error('network call in a dry run')));
    const { report } = await runBench({ dryRun: true, out: mkdtempSync(join(tmpdir(), 'vwf-compare-')), date: '2026-10-01' });
    const md = renderComparison([{ label: 'Run 1', report }, { label: 'Run 2', report: { ...report, hypotheses: report.hypotheses.slice(0, 4) } }]);

    expect(md).toContain('| Hypothesis | Run 1 | Run 2 |');
    expect(md).toContain('| R1 | pass | pass |');
    expect(md).toContain('| R5 | not measured | - |');
    expect(md.indexOf('| R2 |')).toBeLessThan(md.indexOf('| R3 |'));
    expect(md).toContain('| raw claude-haiku-4-5-20251001 | 50.0% (1/2) | 50.0% (1/2) |');
  });
});
