import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { MAX_TURNS, runAgent, systemPrompt, withCacheBreakpoint } from '../src/agent.ts';
import { BENCH_DIR, Config, loadConfig } from '../src/run.ts';
import { cap, liveTools, MAX_CHARS, stripHtml, toolsFor } from '../src/tools.ts';
import { addUsage, costUsd, emptyUsage, inputTotal } from '../src/usage.ts';
import { fakeClient, message, text, toolUse } from './helpers.ts';

const base = { model: 'm', params: {}, system: systemPrompt('http://localhost:8888'), tools: toolsFor('raw'), prompt: 'Q?' };

describe('agent loop', () => {
  it('runs tools until the model answers, returning results and counting calls', async () => {
    const { client, calls } = fakeClient(toolUse('rest_get', { path: '/' }), text('ANSWER: done'));
    const run = await runAgent({ ...base, client, execute: async () => 'HTTP 200\n{}' });
    expect(run).toMatchObject({ final_text: 'ANSWER: done', tool_calls: 1, hit_turn_cap: false });
    expect(calls).toHaveLength(2);
    expect(calls[1].messages.at(-1)).toEqual({ role: 'user', content: [{ type: 'tool_result', tool_use_id: 'toolu_1', content: 'HTTP 200\n{}' }] });
  });

  it('returns a failing tool as an error result instead of stopping', async () => {
    const { client, calls } = fakeClient(toolUse('http_get', { path: '/' }), text('ANSWER: x'));
    await runAgent({ ...base, client, execute: async () => Promise.reject(new Error('boom')) });
    expect(calls[1].messages.at(-1)?.content).toEqual([{ type: 'tool_result', tool_use_id: 'toolu_1', content: 'boom', is_error: true }]);
  });

  it(`stops at the ${MAX_TURNS}-turn cap with no answer`, async () => {
    const { client, calls } = fakeClient(toolUse('http_get', { path: '/' }));
    const run = await runAgent({ ...base, client, execute: async () => 'ok' });
    expect(run).toMatchObject({ hit_turn_cap: true, final_text: '', tool_calls: MAX_TURNS });
    expect(calls).toHaveLength(MAX_TURNS);
  });

  it('stops at the cap it is given, and still answers on the last call it allows', async () => {
    const capped = fakeClient(toolUse('http_get', { path: '/' }));
    const run = await runAgent({ ...base, client: capped.client, execute: async () => 'ok', maxTurns: 24 });
    expect(run).toMatchObject({ hit_turn_cap: true, tool_calls: 24 });
    expect(capped.calls).toHaveLength(24);
    const late = fakeClient(...Array.from({ length: 23 }, () => toolUse('http_get', { path: '/' })), text('ANSWER: done'));
    expect(await runAgent({ ...base, client: late.client, execute: async () => 'ok', maxTurns: 24 })).toMatchObject({ hit_turn_cap: false, final_text: 'ANSWER: done', tool_calls: 23 });
    const short = fakeClient(...Array.from({ length: 23 }, () => toolUse('http_get', { path: '/' })), text('ANSWER: done'));
    expect(await runAgent({ ...base, client: short.client, execute: async () => 'ok' })).toMatchObject({ hit_turn_cap: true, tool_calls: MAX_TURNS });
  });

  it('sends no agent model a temperature, keeps the judge at 0, and caches only the preloaded manual', async () => {
    // From run 6: run 5 sent Haiku temperature 0 and Sonnet nothing, so its cells differed in more than the model.
    const { config } = loadConfig();
    expect(config.models.map((m) => [m.id, m.params])).toEqual([['claude-haiku-4-5-20251001', {}], ['claude-sonnet-5', {}]]);
    expect(config.judge_params).toEqual({ temperature: 0 });
    const { client, calls } = fakeClient(text('ANSWER: x'));
    for (const m of config.models) await runAgent({ ...base, client, execute: async () => '', params: m.params });
    expect(calls.map((c) => 'temperature' in c)).toEqual([false, false]);
    expect(base.system).toHaveLength(1);
    expect(systemPrompt('u', 'brief')[1]).toMatchObject({ cache_control: { type: 'ephemeral' } });
  });

  it('refuses a config that gives one model a temperature', () => {
    const raw = JSON.parse(readFileSync(join(BENCH_DIR, 'config.json'), 'utf8'));
    expect(Config.safeParse(raw).success).toBe(true);
    raw.models[0].params = { temperature: 0 };
    const parsed = Config.safeParse(raw);
    expect(parsed.success).toBe(false);
    expect(parsed.error?.issues[0].message).toMatch(/provider's default temperature .*remove temperature/);
  });
});

describe('conversation caching', () => {
  const marked = (params: { messages: { content: unknown }[] }) =>
    params.messages.flatMap((m, i) => (Array.isArray(m.content) ? m.content : []).flatMap((b: { cache_control?: unknown }, j: number) => (b.cache_control ? [`${i}.${j}`] : [])));

  it('marks only the last block of the latest message on every call, and never the stored conversation', async () => {
    const { client, calls } = fakeClient(toolUse('rest_get', { path: '/' }), toolUse('http_get', { path: '/' }, 'toolu_2'), text('ANSWER: done'));
    await runAgent({ ...base, client, execute: async () => 'ok', cacheConversation: true });
    expect(calls.map(marked)).toEqual([['0.0'], ['2.0'], ['4.0']]);
    expect(calls[0].messages[0]).toEqual({ role: 'user', content: [{ type: 'text', text: 'Q?', cache_control: { type: 'ephemeral' } }] });
    expect(calls[2].messages[2]).toEqual({ role: 'user', content: [{ type: 'tool_result', tool_use_id: 'toolu_1', content: 'ok' }] });
  });

  it('sends no breakpoint in the conversation when it is off, as in runs 1 and 2', async () => {
    const { client, calls } = fakeClient(toolUse('rest_get', { path: '/' }), text('ANSWER: done'));
    await runAgent({ ...base, client, execute: async () => 'ok' });
    expect(calls.map(marked)).toEqual([[], []]);
    expect(calls[0].messages[0]).toEqual({ role: 'user', content: 'Q?' });
  });

  it('keeps every request within the four breakpoints the API allows when the manual is preloaded', () => {
    const messages = withCacheBreakpoint([{ role: 'user', content: [{ type: 'text', text: 'a' }, { type: 'text', text: 'b' }] }]);
    expect(messages[0].content).toEqual([{ type: 'text', text: 'a' }, { type: 'text', text: 'b', cache_control: { type: 'ephemeral' } }]);
    const system = systemPrompt('u', 'manual').filter((b) => b.cache_control).length;
    expect(system + marked({ messages: messages as { content: unknown }[] }).length).toBe(2);
  });
});

describe('tools', () => {
  it('gives each condition its tool set', () => {
    const names = (c: Parameters<typeof toolsFor>[0]) => toolsFor(c).map((t) => t.name);
    expect(names('raw')).toEqual(['http_get', 'rest_get']);
    expect(names('manual')).toHaveLength(6);
    expect(names('manual-preloaded')).toEqual(['http_get', 'rest_get', 'manual_section', 'find_edit_location']);
  });

  it('strips HTML to text and caps output at 20,000 characters', () => {
    expect(stripHtml('<style>p{}</style><p>Call&nbsp;us</p><script>x()</script><p>&#8217;now &amp; then</p>')).toBe('Call us\n’now & then');
    const capped = cap('x'.repeat(MAX_CHARS + 50));
    expect(capped).toHaveLength(MAX_CHARS);
    expect(capped).toMatch(/truncated/);
  });

  it('authenticates REST and manual calls, not front-end pages, and stays on the site origin', async () => {
    const seen: { url: string; auth?: string }[] = [];
    const fetchStub = (async (url: URL, init: RequestInit) => {
      seen.push({ url: url.toString(), auth: (init.headers as Record<string, string>).authorization });
      return new Response('<p>ok</p>', { status: 200 });
    }) as unknown as typeof fetch;
    const routes = { brief: '/b', index: '/i', section: '/s?id={id}', find: '/f?url={url}&text={text}' };
    const exec = liveTools({ url: 'http://localhost:8888', credentials: 'admin:abc', routes }, fetchStub);
    expect(await exec('http_get', { path: '/about/' })).toBe('HTTP 200\nok');
    await exec('rest_get', { path: '/wp/v2/types' });
    await exec('manual_section', { id: 'part:footer' });
    expect(seen.map((s) => s.url)).toEqual(['http://localhost:8888/about/', 'http://localhost:8888/wp-json/wp/v2/types', 'http://localhost:8888/s?id=part%3Afooter']);
    expect(seen.map((s) => s.auth)).toEqual([undefined, `Basic ${btoa('admin:abc')}`, `Basic ${btoa('admin:abc')}`]);
    await expect(exec('http_get', { path: 'https://example.com/' })).rejects.toThrow(/only paths on/);
  });
});

describe('usage accounting', () => {
  it('sums every usage field, treating null cache counts as zero', () => {
    let u = addUsage(emptyUsage(), message([], 'end_turn', { input_tokens: 100, output_tokens: 20 }).usage);
    u = addUsage(u, message([], 'end_turn', { input_tokens: 7, output_tokens: 3, cache_creation_input_tokens: 4000, cache_read_input_tokens: 600 }).usage);
    expect(u).toEqual({ input_tokens: 107, cache_creation_input_tokens: 4000, cache_read_input_tokens: 600, output_tokens: 23 });
    expect(inputTotal(u)).toBe(4707);
  });

  it('prices each token type from pricing.json', () => {
    const pricing = JSON.parse(readFileSync(join(BENCH_DIR, 'pricing.json'), 'utf8'));
    expect(pricing.fetched).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    const haiku = pricing.models['claude-haiku-4-5-20251001'];
    const u = { input_tokens: 1_000_000, cache_creation_input_tokens: 1_000_000, cache_read_input_tokens: 1_000_000, output_tokens: 1_000_000 };
    expect(costUsd(u, haiku)).toBeCloseTo(haiku.input + haiku.cache_write_5m + haiku.cache_read + haiku.output, 10);
    expect(costUsd({ ...emptyUsage(), input_tokens: 812, output_tokens: 52 }, haiku)).toBeCloseTo(0.001072, 10);
  });
});
