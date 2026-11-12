import type Anthropic from '@anthropic-ai/sdk';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { Turn } from '../src/agent.ts';
import { findSize, FIND_TOOL } from '../src/findSize.ts';
import { buildReport, findSizeByCondition, renderMarkdown, type ReportMeta, type Result } from '../src/report.ts';
import { runBench } from '../src/run.ts';
import { cap, MAX_CHARS } from '../src/tools.ts';
import { message } from './helpers.ts';

// The report's find results: how many characters each find call handed the model, and how many were cut at the cap.

afterEach(() => vi.unstubAllGlobals());

/** One question run: each call a tool_use and its result, as the model read it. */
function turns(calls: { name: string; result: string | Anthropic.TextBlockParam[]; error?: boolean }[]): Turn[] {
  return calls.map((call, i) => ({
    response: message([{ type: 'tool_use', id: `toolu_${i}`, name: call.name, input: {} }], 'tool_use'),
    tool_results: [{ type: 'tool_result', tool_use_id: `toolu_${i}`, content: call.result, ...(call.error && { is_error: true }) } as Anthropic.ToolResultBlockParam],
  }));
}

const META: ReportMeta = {
  format: 'wayfinder-bench-report/1',
  site: 'fixture',
  date: '2026-11-12',
  dry_run: false,
  runner: { where: 'local', concurrency: 1 },
  question_set: { file: 'questions/fixture.yaml', sha256: 'x', count: 2 },
  pricing: { source: 'test', fetched: '2026-11-12' },
  models: [{ id: 'claude-haiku-4-5-20251001', params: {}, cache_min_tokens: 4096 }],
  manual: null,
};

const result = (condition: string, find?: Result['find'], question = 'el-01'): Result => ({
  question, category: 'edit_location', condition, model: 'claude-haiku-4-5-20251001', rep: 1, correct: true, reason: 'matches', answer: 'x',
  usage: { input_tokens: 100, cache_creation_input_tokens: 0, cache_read_input_tokens: 0, output_tokens: 10 },
  first_call_input_tokens: 100, tool_calls: find?.calls ?? 0, turns: 2, hit_turn_cap: false, wall_ms: 1000, cost_usd: 0.001, judge_cost_usd: 0,
  ...(find && { find }),
});

describe('find size per question run', () => {
  it('counts every find result, its characters and the ones the cap cut, and nothing else', () => {
    const long = cap(`HTTP 200\n${'x'.repeat(30_000)}`);
    expect(long).toHaveLength(MAX_CHARS);
    const small = 'HTTP 200\n{"chain":[]}';
    const missing = 'HTTP 404\n{"code":"vwf_unknown_url"}';
    const blocks = 'HTTP 200\n{}';
    const size = findSize(turns([
      { name: FIND_TOOL, result: small },
      { name: FIND_TOOL, result: long },
      { name: FIND_TOOL, result: missing, error: true },
      { name: FIND_TOOL, result: [{ type: 'text', text: blocks }] },
      { name: 'manual_index', result: 'y'.repeat(25_000) },
    ]));
    expect(size).toEqual({ calls: 4, chars: small.length + MAX_CHARS + missing.length + blocks.length, at_cap: 1 });
  });

  it('reads a run with no find calls as none', () => {
    expect(findSize(turns([{ name: 'http_get', result: 'HTTP 200\n' }]))).toEqual({ calls: 0, chars: 0, at_cap: 0 });
    expect(findSize([])).toEqual({ calls: 0, chars: 0, at_cap: 0 });
  });

  it('counts a result one character under the cap as under it', () => {
    expect(findSize(turns([{ name: FIND_TOOL, result: 'z'.repeat(MAX_CHARS - 1) }])).at_cap).toBe(0);
    expect(findSize(turns([{ name: FIND_TOOL, result: 'z'.repeat(MAX_CHARS) }])).at_cap).toBe(1);
  });
});

describe('find results in the report', () => {
  it('pools each condition that offers find over its models and repetitions', () => {
    const results = [
      result('manual', { calls: 2, chars: 30_000, at_cap: 1 }),
      result('manual', { calls: 1, chars: 3_000, at_cap: 0 }, 'el-02'),
      result('cli-manual', { calls: 0, chars: 0, at_cap: 0 }),
      result('raw', { calls: 0, chars: 0, at_cap: 0 }),
    ];
    expect(findSizeByCondition(results)).toEqual([
      { condition: 'cli-manual', calls: 0, mean_chars: 0, at_cap: 0 },
      { condition: 'manual', calls: 3, mean_chars: 11_000, at_cap: 1 },
    ]);

    const report = buildReport(results, META, { ...RULES });
    expect(report.find_size).toEqual(findSizeByCondition(results));
    const section = renderMarkdown(report).split('## Find results')[1]?.split('\n## ')[0] ?? '';
    expect(section).toContain('| manual | 3 | 11,000 | 1 (33.3%) |');
    expect(section).toContain('| cli-manual | 0 | none | none |');
    expect(section, 'raw offers no find tool').not.toContain('| raw |');
  });

  it('leaves the section out of a report whose results predate the count', () => {
    const old = [result('manual'), result('raw')];
    expect(findSizeByCondition(old)).toBeUndefined();
    const report = buildReport(old, META, { ...RULES });
    expect(report).not.toHaveProperty('find_size');
    expect(renderMarkdown(report)).not.toContain('## Find results');
  });

  it('records the find call a dry run replays', async () => {
    vi.stubGlobal('fetch', () => Promise.reject(new Error('network call in a dry run')));
    const out = mkdtempSync(join(tmpdir(), 'vwf-find-size-'));
    try {
      const { report } = await runBench({ dryRun: true, conditions: ['manual'], out, date: '2026-11-12' });
      // test/recordings/manual.claude-haiku-4-5-20251001.el-01.json makes the one find call in the recordings.
      const recorded = JSON.parse(readFileSync(join(import.meta.dirname, 'recordings', 'manual.claude-haiku-4-5-20251001.el-01.json'), 'utf8')) as { turns: Turn[] };
      const expected = findSize(recorded.turns);
      expect(expected.calls).toBe(1);
      expect(report.find_size).toEqual([{ condition: 'manual', calls: 1, mean_chars: expected.chars, at_cap: expected.at_cap }]);
      expect(readFileSync(join(out, 'fixture-latest.md'), 'utf8')).toContain('## Find results');
    } finally {
      rmSync(out, { recursive: true, force: true });
    }
  });
});

const RULES = {
  h1_min_accuracy: 0.9,
  h2_min_input_ratio: 5,
  h2b_min_total_input_ratio: 3,
  h3: { challenger: { condition: 'manual-preloaded', model: 'claude-haiku-4-5-20251001' }, baseline: { condition: 'raw', model: 'claude-sonnet-5' }, repetitions: 3 },
  h4_max_est_tokens: { brief: 600, index: 4000 },
  h5_min_p90_input_ratio: 3,
  h6_min_cost_per_correct_ratio: 5,
};
