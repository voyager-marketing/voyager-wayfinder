import type Anthropic from '@anthropic-ai/sdk';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import type { MessagesClient } from '../src/agent.ts';
import { BENCH_DIR } from '../src/run.ts';

/**
 * `src/grade.ts` as it was at `commit`, or as `source` gives it, loaded as a module: written to runs/graders/ (ignored
 * by git) with its relative imports pointed back at src/. For replaying an earlier grader, or a mutated one.
 */
export async function graderModule(name: string, opts: { commit?: string; source?: string }): Promise<typeof import('../src/grade.ts')> {
  const source = opts.source ?? execFileSync('git', ['show', `${opts.commit}:bench/src/grade.ts`], { cwd: BENCH_DIR, encoding: 'utf8' });
  const dir = join(BENCH_DIR, 'runs', 'graders');
  mkdirSync(dir, { recursive: true });
  const file = join(dir, `${name}.ts`);
  writeFileSync(file, source.replace(/from '\.\/([a-z]+\.ts)'/g, "from '../../src/$1'"));
  return import(pathToFileURL(file).href);
}

/** What decides a judge call's reply at temperature 0: the prompt, the facts and answer it is given, and its limits. */
export const requestSha256 = (params: Anthropic.MessageCreateParamsNonStreaming) =>
  createHash('sha256').update(JSON.stringify({ model: params.model, system: params.system, messages: params.messages, max_tokens: params.max_tokens, temperature: params.temperature })).digest('hex');

type Usage = Partial<Anthropic.Usage>;

export function message(content: unknown[], stop_reason: string, usage: Usage = {}): Anthropic.Message {
  return {
    id: 'msg_test',
    type: 'message',
    role: 'assistant',
    model: 'test',
    content,
    stop_reason,
    stop_sequence: null,
    usage: { input_tokens: 10, output_tokens: 5, cache_creation_input_tokens: null, cache_read_input_tokens: null, ...usage },
  } as unknown as Anthropic.Message;
}

export const text = (t: string) => message([{ type: 'text', text: t }], 'end_turn');
export const toolUse = (name: string, input: unknown, id = 'toolu_1') =>
  message([{ type: 'tool_use', id, name, input }], 'tool_use');

/** A client that returns `replies` in order (repeating the last) and records every request. */
export function fakeClient(...replies: Anthropic.Message[]) {
  const calls: Anthropic.MessageCreateParamsNonStreaming[] = [];
  const client: MessagesClient = {
    messages: {
      create: async (params) => {
        calls.push(structuredClone(params));
        return replies[Math.min(calls.length - 1, replies.length - 1)];
      },
    },
  };
  return { client, calls };
}
