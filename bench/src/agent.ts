import type Anthropic from '@anthropic-ai/sdk';
import type { ToolExecutor } from './tools.ts';
import { addUsage, emptyUsage, type Usage } from './usage.ts';

/** Model calls per question before the run counts as wrong, as in runs 1 to 3. `--max-turns` overrides it. */
export const MAX_TURNS = 12;
const MAX_TOKENS = 8192;

/** The one call the loop and the judge make. The SDK client satisfies it; dry-run replays recordings through it. */
export interface MessagesClient {
  messages: { create(params: Anthropic.MessageCreateParamsNonStreaming): Promise<Anthropic.Message> };
}

/** Sampling parameters from config.json, per model. Empty for models that reject them. */
export type ModelParams = { temperature?: number };

export interface Turn {
  response: Anthropic.Message;
  tool_results: Anthropic.ToolResultBlockParam[];
}

export interface AgentRun {
  final_text: string;
  turns: Turn[];
  usage: Usage;
  tool_calls: number;
  hit_turn_cap: boolean;
  wall_ms: number;
}

// One skeleton for every condition. The only difference between conditions is the tool
// list and, for manual-preloaded, the cached manual block appended after it.
const SKELETON = `You answer questions about how a WordPress site is built and where things are edited. The site is at {site_url}.
Use the tools to look things up. Do not guess. If the tools cannot tell you, say so.
Keep the answer short and end with one final line in this form:
ANSWER: <answer>
Separate list items with commas. Give numbers as digits. Give wp-admin locations as admin URL paths, for example edit.php?post_type=page.`;

export function systemPrompt(siteUrl: string, manual?: string): Anthropic.TextBlockParam[] {
  const blocks: Anthropic.TextBlockParam[] = [{ type: 'text', text: SKELETON.replace('{site_url}', siteUrl) }];
  if (manual) {
    blocks.push({ type: 'text', text: `Wayfinder manual for this site:\n\n${manual}`, cache_control: { type: 'ephemeral' } });
  }
  return blocks;
}

/**
 * Conversation caching: the messages with a cache breakpoint on the last block of the latest
 * message, the way production agents cache their loop. Each call then reads everything before its newest
 * turn from the cache. The stored conversation is never marked, so a request carries one breakpoint here
 * plus, in manual-preloaded, the one on the manual: two of the four the API allows.
 */
export function withCacheBreakpoint(messages: Anthropic.MessageParam[]): Anthropic.MessageParam[] {
  const last = messages.at(-1);
  if (!last) return messages;
  const blocks: Anthropic.ContentBlockParam[] = typeof last.content === 'string' ? [{ type: 'text', text: last.content }] : [...last.content];
  const tail = blocks.at(-1);
  if (!tail || tail.type === 'thinking' || tail.type === 'redacted_thinking') return messages;
  blocks[blocks.length - 1] = { ...tail, cache_control: { type: 'ephemeral' } };
  return [...messages.slice(0, -1), { ...last, content: blocks }];
}

export async function runAgent(opts: {
  client: MessagesClient;
  model: string;
  params: ModelParams;
  system: Anthropic.TextBlockParam[];
  tools: Anthropic.Tool[];
  execute: ToolExecutor;
  prompt: string;
  /** Put a cache breakpoint on the latest message of every call (config.json `conversation_caching`). */
  cacheConversation?: boolean;
  /** Model calls before the run stops with no answer. Default MAX_TURNS. */
  maxTurns?: number;
}): Promise<AgentRun> {
  const { client, model, params, system, tools, execute, maxTurns = MAX_TURNS } = opts;
  const started = performance.now();
  const messages: Anthropic.MessageParam[] = [{ role: 'user', content: opts.prompt }];
  const run: AgentRun = { final_text: '', turns: [], usage: emptyUsage(), tool_calls: 0, hit_turn_cap: true, wall_ms: 0 };

  while (run.turns.length < maxTurns) {
    const sent = opts.cacheConversation ? withCacheBreakpoint(messages) : messages;
    const response = await client.messages.create({ model, max_tokens: MAX_TOKENS, system, tools, messages: sent, ...params });
    run.usage = addUsage(run.usage, response.usage);
    const uses = response.content.filter((b): b is Anthropic.ToolUseBlock => b.type === 'tool_use');
    if (response.stop_reason !== 'tool_use' || !uses.length) {
      run.turns.push({ response, tool_results: [] });
      run.final_text = response.content.flatMap((b) => (b.type === 'text' ? [b.text] : [])).join('\n');
      run.hit_turn_cap = false;
      break;
    }
    const tool_results = await Promise.all(
      uses.map(async (use): Promise<Anthropic.ToolResultBlockParam> => {
        try {
          return { type: 'tool_result', tool_use_id: use.id, content: await execute(use.name, use.input) };
        } catch (error) {
          return { type: 'tool_result', tool_use_id: use.id, content: (error as Error).message, is_error: true };
        }
      }),
    );
    run.tool_calls += uses.length;
    run.turns.push({ response, tool_results });
    messages.push({ role: 'assistant', content: response.content }, { role: 'user', content: tool_results });
  }
  run.wall_ms = Math.round(performance.now() - started);
  return run;
}
