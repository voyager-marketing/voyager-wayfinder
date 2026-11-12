import type Anthropic from '@anthropic-ai/sdk';
import type { Turn } from './agent.ts';
import { MAX_CHARS } from './tools.ts';

// What find cost a run: how many characters each find_edit_location result handed the model, and how many were cut at
// the tool cap. Run 5's real-site find results averaged 9,663 characters and 158 of 884 were cut mid-JSON; until run 6
// only the transcripts held that.

/** The find tool's name in every condition that offers it (src/tools.ts). */
export const FIND_TOOL = 'find_edit_location';

/** One question run's find calls: how many, their characters together, and how many reached the cap. */
export interface FindSize {
  calls: number;
  chars: number;
  at_cap: number;
}

const text = (content: Anthropic.ToolResultBlockParam['content']): string =>
  typeof content === 'string' ? content : (content ?? []).map((b) => (b.type === 'text' ? b.text : '')).join('');

/**
 * Every find result in one question run, error results too, as the model read them. A result counts as at the cap
 * when it is as long as the cap allows: `cap` cuts a longer one to exactly MAX_CHARS.
 */
export function findSize(turns: Turn[]): FindSize {
  const size: FindSize = { calls: 0, chars: 0, at_cap: 0 };
  for (const turn of turns) {
    for (const result of turn.tool_results) {
      const use = turn.response.content.find((b) => b.type === 'tool_use' && b.id === result.tool_use_id);
      if (use?.type !== 'tool_use' || use.name !== FIND_TOOL) continue;
      const chars = text(result.content).length;
      size.calls++;
      size.chars += chars;
      if (chars >= MAX_CHARS) size.at_cap++;
    }
  }
  return size;
}
