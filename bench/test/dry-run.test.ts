import { existsSync, mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { median, percentile, ReportSchema } from '../src/report.ts';
import { runBench } from '../src/run.ts';

afterEach(() => vi.unstubAllGlobals());

describe('report math', () => {
  it('takes the median and the nearest-rank p90', () => {
    expect(median([5, 1, 3])).toBe(3);
    expect(median([4, 1, 3, 2])).toBe(2.5);
    expect(percentile([10, 20, 30, 40, 50, 60, 70, 80, 90, 100], 90)).toBe(90);
    expect(percentile([7], 90)).toBe(7);
  });
});

describe('dry run', () => {
  it('replays recordings end to end with no network and writes the report in the documented shape', async () => {
    vi.stubGlobal('fetch', () => Promise.reject(new Error('network call in a dry run')));
    const out = mkdtempSync(join(tmpdir(), 'vwf-bench-'));
    const { report, paths } = await runBench({ dryRun: true, out, date: '2026-10-01' });

    const names = ['fixture-2026-10-01.json', 'fixture-2026-10-01.md', 'fixture-latest.json', 'fixture-latest.md'];
    expect(paths.map((p) => p.slice(out.length + 1))).toEqual(names);
    for (const name of names) expect(existsSync(join(out, name))).toBe(true);
    const written = ReportSchema.parse(JSON.parse(readFileSync(join(out, 'fixture-latest.json'), 'utf8')));
    expect(written).toEqual(report);

    expect(report).toMatchObject({ dry_run: true, runner: { where: 'dry-run', concurrency: 1 }, manual: null, question_set: { count: 40 }, totals: { question_runs: 10, correct: 8 } });
    // The dry run's first acceptance check read `.runs | length`; the adapter recording adds the seventh, the cli and cli-manual ones the eighth and ninth
    expect(report.runs).toHaveLength(9);
    expect(report.runs.every((s) => s.rep === 1)).toBe(true);
    // config.json judges new runs against R1 to R7. The recordings have no Sonnet adapter run, so every rule that reads it is unmeasured.
    expect(report).toMatchObject({ fixture: 'block', hypothesis_set: 'run3', conversation_caching: true });
    expect(report.commit).toBeUndefined();
    // From run 6 the header records the temperature policy. No agent model carries a temperature; the judge does.
    expect(report.models.map((m) => m.params)).toEqual([{}, {}]);
    expect(report.temperature_policy).toEqual({ agents: 'provider-default', judge: { model: 'claude-haiku-4-5-20251001', params: { temperature: 0 } } });
    expect(report.hypotheses.map((h) => [h.id, h.verdict])).toEqual([
      ['R1', 'pass'], ['R2', 'not measured'], ['R3', 'not measured'], ['R4', 'not measured'], ['R5', 'not measured'], ['R6', 'not measured'], ['R7', 'not measured'],
    ]);
    expect(report.losses?.length).toBeGreaterThan(0);
    expect(report.cache_by_cell).toHaveLength(9);
    const row = (c: string, m: string) => report.runs.find((s) => s.condition === c && s.model === m);
    // raw haiku: si-01 (1770 in, 73 out) and cm-03 (1819 in, 73 out) at $1 / $5 per MTok.
    expect(row('raw', 'claude-haiku-4-5-20251001')).toMatchObject({ correct: 1, questions: 2, accuracy: 0.5, cost_usd: 0.004319 });
    // manual-preloaded sonnet: 48 in, 4630 cache write, 30 out at $2 / $2.50 / $10.
    expect(row('manual-preloaded', 'claude-sonnet-5')).toMatchObject({ correct: 1, cost_usd: 0.011971, input_tokens: { median: 4678 } });
    // Judge calls: df-01 (412 in, 31 out) and ci-01 (455 in, 27 out) on Haiku.
    expect(report.totals.judge_cost_usd).toBe(0.001157);
    expect(report.by_category.find((c) => c.category === 'change_impact')).toMatchObject({ correct: 0, questions: 1 });

    const md = readFileSync(join(out, 'fixture-latest.md'), 'utf8');
    expect(md).toContain('> Dry run.');
    expect(md).toContain('Temperature: none sent to any agent model, so each ran at its provider\'s default. Judge claude-haiku-4-5-20251001 {"temperature":0}.');
    for (const heading of ['## Hypotheses', '## Losses', '## Metrics without a threshold', '## Most-missed questions', '## Prompt caching', '## Conversation caching', '## Accuracy', '## Accuracy by category', '## Tokens and tool calls per question', '## Cost']) {
      expect(md).toContain(heading);
    }
  });
});
