import type Anthropic from '@anthropic-ai/sdk';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { probeCache, PROBE_TURN, type ProbeClient } from '../src/probe.ts';
import { message } from './helpers.ts';

const HAIKU = 'claude-haiku-4-5-20251001';
const SONNET = 'claude-sonnet-5';

/** count_tokens answers from `request` (with tools) or 8 (the turn alone); each model's calls return `usage` in order. */
function probeClient(request: Record<string, number>, usage: Record<string, Partial<Anthropic.Usage>[]>) {
  const counts: Anthropic.MessageCountTokensParams[] = [];
  const creates: Anthropic.MessageCreateParamsNonStreaming[] = [];
  const client: ProbeClient = {
    messages: {
      countTokens: async (params) => {
        counts.push(structuredClone(params));
        return { input_tokens: params.tools ? request[params.model] : 8 } as Anthropic.MessageTokensCount;
      },
      create: async (params) => {
        creates.push(structuredClone(params));
        return message([{ type: 'text', text: 'Hi.' }], 'end_turn', usage[params.model].shift());
      },
    },
  };
  return { client, counts, creates };
}

function fixture() {
  const dir = mkdtempSync(join(tmpdir(), 'vwf-probe-'));
  const transcript = join(dir, 'manual-preloaded.json');
  writeFileSync(transcript, JSON.stringify({ question: 'cm-01', condition: 'manual-preloaded', model: HAIKU, preload: 'BRIEF\n\nINDEX', turns: [], wall_ms: 0 }));
  return { dir, transcript };
}

const write = (n: number, tail = 9) => ({ input_tokens: tail, cache_creation_input_tokens: n, cache_read_input_tokens: 0 });
const read = (n: number, tail = 9) => ({ input_tokens: tail, cache_creation_input_tokens: 0, cache_read_input_tokens: n });
const none = (n: number) => ({ input_tokens: n, cache_creation_input_tokens: 0, cache_read_input_tokens: 0 });
const noWait = async () => {};

describe('cache probe', () => {
  it('sends the manual-preloaded request twice, then a calibration call, and writes the probe file', async () => {
    const { dir, transcript } = fixture();
    const { client, counts, creates } = probeClient({ [HAIKU]: 4243, [SONNET]: 5066 }, {
      [HAIKU]: [none(4243), none(4243), write(7127, 323)],
      [SONNET]: [write(4990, 76), read(4990, 76), write(8400, 76)],
    });
    const waits: number[] = [];
    const { probe, path } = await probeCache({ client, preloadFrom: transcript, out: dir, date: '2026-10-02', siteUrl: 'http://localhost:12086', wait: async (ms) => void waits.push(ms) });

    expect(path).toBe(join(dir, 'caching-probe.json'));
    expect(JSON.parse(readFileSync(path, 'utf8'))).toEqual(probe);
    expect(probe).toMatchObject({ date: '2026-10-02', condition: 'manual-preloaded', site_url: 'http://localhost:12086', probe_turn: PROBE_TURN, manual: { source: expect.stringMatching(/^transcript /), chars: 12 } });
    expect(waits).toEqual([5000, 5000]);

    // Per model: count the request, count the one-word turn alone, two identical calls, one calibration call.
    expect(counts.map((c) => [c.model, Boolean(c.tools)])).toEqual([[HAIKU, true], [HAIKU, false], [SONNET, true], [SONNET, false]]);
    expect(creates.map((c) => c.model)).toEqual([HAIKU, HAIKU, HAIKU, SONNET, SONNET, SONNET]);
    expect(creates[1]).toEqual(creates[0]);
    const system = creates[0].system as Anthropic.TextBlockParam[];
    expect(system.at(-1)).toMatchObject({ text: expect.stringContaining('BRIEF\n\nINDEX'), cache_control: { type: 'ephemeral' } });
    expect((creates[2].system as Anthropic.TextBlockParam[]).at(-1)?.text).toContain('BRIEF\n\nINDEX\n\nBRIEF\n\nINDEX');
    expect(creates[2]).toEqual({ ...creates[0], system: creates[2].system });
    expect(counts[0]).toMatchObject({ system, tools: creates[0].tools, messages: [{ role: 'user', content: PROBE_TURN }] });
    expect((creates[0].tools as Anthropic.Tool[]).map((t) => t.name)).toEqual(['http_get', 'rest_get', 'manual_section', 'find_edit_location']);
    // No model is sent a temperature, from run 6.
    expect(creates.map((c) => 'temperature' in c)).toEqual([false, false, false, false, false, false]);

    // The prefix is the request less what follows the breakpoint. Where a model caches, it is the span it wrote.
    const [haiku, sonnet] = probe.models;
    expect(haiku).toMatchObject({ prefix_tokens: 3920, request_tokens: 4243, tail_tokens: 323, probe_turn_tokens: 8, minimum_documented: 4096, cached: false });
    expect(haiku.finding).toMatch(/under the minimum: 3920 tokens against the documented 4096.*reads 4243 for the request, but 323 of those come after the breakpoint/);
    expect(sonnet).toMatchObject({ prefix_tokens: 4990, first_call_cache_creation_tokens: 4990, second_call_cache_read_tokens: 4990, cached: true, finding: 'Cached. The second call read 4990 tokens from the cache.' });
  });

  it('blames the request shape when a prefix at or above the minimum does not cache', async () => {
    const { dir, transcript } = fixture();
    const { client } = probeClient({ [SONNET]: 5066 }, { [SONNET]: [none(5066), none(5066), write(8400, 76)] });
    const { probe } = await probeCache({ client, models: [SONNET], preloadFrom: transcript, out: dir, wait: noWait });

    expect(probe.models).toHaveLength(1);
    expect(probe.models[0]).toMatchObject({ prefix_tokens: 4990, minimum_documented: 1024, cached: false });
    expect(probe.models[0].finding).toMatch(/request shape is at fault/);
  });

  it('stops when the calibration call does not cache, since the tail is then unknown', async () => {
    const { dir, transcript } = fixture();
    const { client } = probeClient({ [HAIKU]: 4243 }, { [HAIKU]: [none(4243), none(4243), none(7450)] });
    await expect(probeCache({ client, models: [HAIKU], preloadFrom: transcript, out: dir, wait: noWait })).rejects.toThrow(/calibration call did not cache/);
  });

  it('refuses a transcript with no preloaded manual', async () => {
    const { dir } = fixture();
    const raw = join(dir, 'raw.json');
    writeFileSync(raw, JSON.stringify({ question: 'cm-01', condition: 'raw', model: HAIKU, turns: [], wall_ms: 0 }));
    const { client } = probeClient({}, {});
    await expect(probeCache({ client, preloadFrom: raw, out: dir })).rejects.toThrow(/has no preload/);
  });
});
