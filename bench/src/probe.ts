import Anthropic from '@anthropic-ai/sdk';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import { systemPrompt, type MessagesClient } from './agent.ts';
import { BENCH_DIR, fetchManual, loadContext, type Transcript } from './run.ts';
import { liveTools, toolsFor } from './tools.ts';

/** The message calls plus token counting. The SDK client satisfies it. */
export interface ProbeClient extends MessagesClient {
  messages: MessagesClient['messages'] & {
    countTokens(params: Anthropic.MessageCountTokensParams): Promise<Anthropic.MessageTokensCount>;
  };
}

/** The one-word user turn every probe request carries. It sits after the breakpoint, so it is never cached. */
export const PROBE_TURN = 'Hello';
const PROBE_GAP_MS = 5_000;
const PROBE_MAX_TOKENS = 256;

export interface ModelProbe {
  model: string;
  /** The tokens before the breakpoint, the span the minimum applies to: request_tokens less tail_tokens. */
  prefix_tokens: number;
  /** count_tokens over the manual-preloaded tools and system prompt plus PROBE_TURN. */
  request_tokens: number;
  /** Uncached input of the calibration call: everything after the breakpoint. */
  tail_tokens: number;
  /** count_tokens over PROBE_TURN alone, so a reader can see how little of the tail it is. */
  probe_turn_tokens: number;
  minimum_documented: number;
  first_call_input_tokens: number;
  first_call_cache_creation_tokens: number;
  first_call_cache_read_tokens: number;
  second_call_input_tokens: number;
  second_call_cache_creation_tokens: number;
  second_call_cache_read_tokens: number;
  cached: boolean;
  finding: string;
}

export interface CacheProbe {
  format: 'wayfinder-cache-probe/1';
  date: string;
  condition: 'manual-preloaded';
  site_url: string;
  manual: { source: string; chars: number; sha256: string };
  probe_turn: string;
  gap_ms: number;
  models: ModelProbe[];
}

export interface ProbeOptions {
  models?: string[];
  siteUrl?: string;
  /** A run transcript whose `preload` is the manual to probe. Without it the manual is fetched from the site. */
  preloadFrom?: string;
  out?: string;
  date?: string;
  client?: ProbeClient;
  wait?: (ms: number) => Promise<void>;
}

function finding(p: Omit<ModelProbe, 'cached' | 'finding'>): string {
  const under = p.prefix_tokens < p.minimum_documented;
  const read = p.second_call_cache_read_tokens;
  if (read > 0 && under) return `Cached although the prefix measures ${p.prefix_tokens} tokens, under the documented ${p.minimum_documented}. The second call read ${read} tokens from the cache.`;
  if (read > 0) return `Cached. The second call read ${read} tokens from the cache.`;
  if (under) return `Not cached because the prefix is under the minimum: ${p.prefix_tokens} tokens against the documented ${p.minimum_documented}, so the API writes nothing. count_tokens reads ${p.request_tokens} for the request, but ${p.tail_tokens} of those come after the breakpoint. Preloaded cost for this model is uncached for that reason. The prompts are not padded.`;
  return `Not cached although the prefix is ${p.prefix_tokens} tokens, at or above the documented ${p.minimum_documented}. The request shape is at fault.`;
}

/**
 * Measures whether the manual-preloaded prefix (tools, then the system blocks up to the breakpoint)
 * caches for each model: its size, then two identical calls a few seconds apart.
 *
 * count_tokens cannot see the breakpoint. With tools present the API renders tokens of its own after the
 * system prompt (317 on Haiku 4.5 when the probe was written), so the count overstates the cached span. A calibration call
 * carries the manual twice, which clears every model's minimum, and its uncached input is exactly what
 * follows the breakpoint. The bench never sends that prompt.
 */
export async function probeCache(opts: ProbeOptions = {}): Promise<{ probe: CacheProbe; path: string }> {
  const { config } = loadContext();
  const siteUrl = opts.siteUrl ?? config.site_url;
  const models = opts.models?.length ? config.models.filter((m) => opts.models?.includes(m.id)) : config.models;
  if (!models.length) throw new Error(`no configured model matches ${opts.models?.join(', ')}`);

  let preload: string;
  let source: string;
  if (opts.preloadFrom) {
    const transcript = JSON.parse(readFileSync(opts.preloadFrom, 'utf8')) as Transcript;
    if (!transcript.preload) throw new Error(`${opts.preloadFrom} has no preload. Use a manual-preloaded transcript.`);
    preload = transcript.preload;
    source = `transcript ${relative(BENCH_DIR, resolve(opts.preloadFrom)).replaceAll('\\', '/')}`;
  } else {
    const credentials = readFileSync(resolve(BENCH_DIR, config.credentials), 'utf8').trim();
    const { brief, index } = await fetchManual(liveTools({ url: siteUrl, credentials, routes: config.manual_routes }));
    preload = `${brief}\n\n${index}`;
    source = `live ${siteUrl}`;
  }

  const client = opts.client ?? new Anthropic({ maxRetries: 8 });
  const wait = opts.wait ?? ((ms: number) => new Promise<void>((done) => setTimeout(done, ms)));
  // The same tools and system blocks a manual-preloaded bench run sends.
  const system = systemPrompt(siteUrl, preload);
  const calibrationSystem = systemPrompt(siteUrl, `${preload}\n\n${preload}`);
  const tools = toolsFor('manual-preloaded');
  const messages: Anthropic.MessageParam[] = [{ role: 'user', content: PROBE_TURN }];

  const results: ModelProbe[] = [];
  for (const { id, params, cache_min_tokens } of models) {
    const request = await client.messages.countTokens({ model: id, system, tools, messages });
    const turn = await client.messages.countTokens({ model: id, messages });
    const call = (sys = system) => client.messages.create({ model: id, max_tokens: PROBE_MAX_TOKENS, system: sys, tools, messages, ...params });
    const first = (await call()).usage;
    await wait(PROBE_GAP_MS);
    const second = (await call()).usage;
    const calibration = (await call(calibrationSystem)).usage;
    if (!calibration.cache_creation_input_tokens && !calibration.cache_read_input_tokens) {
      throw new Error(`${id}: the calibration call did not cache either, so the tail after the breakpoint is unknown`);
    }
    const p = {
      model: id,
      prefix_tokens: request.input_tokens - calibration.input_tokens,
      request_tokens: request.input_tokens,
      tail_tokens: calibration.input_tokens,
      probe_turn_tokens: turn.input_tokens,
      minimum_documented: cache_min_tokens,
      first_call_input_tokens: first.input_tokens,
      first_call_cache_creation_tokens: first.cache_creation_input_tokens ?? 0,
      first_call_cache_read_tokens: first.cache_read_input_tokens ?? 0,
      second_call_input_tokens: second.input_tokens,
      second_call_cache_creation_tokens: second.cache_creation_input_tokens ?? 0,
      second_call_cache_read_tokens: second.cache_read_input_tokens ?? 0,
    };
    results.push({ ...p, cached: p.second_call_cache_read_tokens > 0, finding: finding(p) });
  }

  const probe: CacheProbe = {
    format: 'wayfinder-cache-probe/1',
    date: opts.date ?? new Date().toISOString().slice(0, 10),
    condition: 'manual-preloaded',
    site_url: siteUrl,
    manual: { source, chars: [...preload].length, sha256: createHash('sha256').update(preload).digest('hex') },
    probe_turn: PROBE_TURN,
    gap_ms: PROBE_GAP_MS,
    models: results,
  };
  const out = opts.out ?? join(BENCH_DIR, 'reports');
  mkdirSync(out, { recursive: true });
  const path = join(out, 'caching-probe.json');
  writeFileSync(path, `${JSON.stringify(probe, null, 2)}\n`);
  return { probe, path };
}
