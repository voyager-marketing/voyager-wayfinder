import type Anthropic from '@anthropic-ai/sdk';

/** Token counts summed from the API usage fields of every call in a run. */
export interface Usage {
  input_tokens: number;
  cache_creation_input_tokens: number;
  cache_read_input_tokens: number;
  output_tokens: number;
}

/** USD per million tokens, from bench/pricing.json. */
export interface ModelPrice {
  input: number;
  cache_write_5m: number;
  cache_read: number;
  output: number;
}

export const emptyUsage = (): Usage => ({
  input_tokens: 0,
  cache_creation_input_tokens: 0,
  cache_read_input_tokens: 0,
  output_tokens: 0,
});

export function addUsage(total: Usage, usage: Anthropic.Usage): Usage {
  return {
    input_tokens: total.input_tokens + usage.input_tokens,
    cache_creation_input_tokens: total.cache_creation_input_tokens + (usage.cache_creation_input_tokens ?? 0),
    cache_read_input_tokens: total.cache_read_input_tokens + (usage.cache_read_input_tokens ?? 0),
    output_tokens: total.output_tokens + usage.output_tokens,
  };
}

/** Everything the model read: uncached input plus cache writes and cache reads. */
export const inputTotal = (u: Usage): number =>
  u.input_tokens + u.cache_creation_input_tokens + u.cache_read_input_tokens;

export function costUsd(u: Usage, price: ModelPrice): number {
  const micro =
    u.input_tokens * price.input +
    u.cache_creation_input_tokens * price.cache_write_5m +
    u.cache_read_input_tokens * price.cache_read +
    u.output_tokens * price.output;
  return micro / 1_000_000;
}
