import type { Turn } from './agent.ts';
import { usesContainer, type Condition } from './tools.ts';
import { checkContainer, type ContainerRun } from './wpcli.ts';

// The tool error guard, from run 6. Run 5's cli cells ran without a working shell: most `wp` and `read_file` calls
// came back "Environment not initialized" and the run carried on, so the cells measured a broken tool. A live run
// now checks the container before every cell and fails a cell, and the run, when one of its tools mostly errors.

/** Calls and error results per tool name. */
export type ToolTally = Record<string, { calls: number; errors: number }>;

/**
 * When a cell's tools count as broken. Each rule needs at least `min_calls` calls.
 *
 * - `cell_share`: the cell's tool calls together errored on more than 20% (the rule run 6 set). No healthy cell
 *   in run 5 went past 9%.
 * - `tool_share`: one tool errored on more than half its calls. A cell's working tools hide a dead one: run 5's
 *   edit-safety-2 cli-manual cells stayed at 14% to 18% as a whole while every container call failed. The bar sits
 *   higher than the cell's because one tool errs more often than a cell does: Haiku's `wp` reached 22% on healthy
 *   calls (`cap list --role=editor`, refused options), the adapter's get-block-tree 17%. A dead tool's run 5 calls
 *   failed at or near 100%.
 */
export const TOOL_ERROR_LIMIT = { min_calls: 10, cell_share: 0.2, tool_share: 0.5 };

const FIRST_ERROR_CHARS = 300;

/** Each tool's calls and error results in one question run, by tool name. */
export function toolTally(turns: Turn[]): ToolTally {
  const tally: ToolTally = {};
  for (const turn of turns) {
    for (const result of turn.tool_results) {
      const use = turn.response.content.find((b) => b.type === 'tool_use' && b.id === result.tool_use_id);
      const name = use?.type === 'tool_use' ? use.name : '(unknown)';
      tally[name] ??= { calls: 0, errors: 0 };
      tally[name].calls++;
      if (result.is_error) tally[name].errors++;
    }
  }
  return Object.fromEntries(Object.entries(tally).sort(([a], [b]) => a.localeCompare(b)));
}

/** Two tallies summed, names sorted. */
export function addTallies(a: ToolTally, b: ToolTally): ToolTally {
  const sum: ToolTally = {};
  for (const t of [a, b]) {
    for (const [name, { calls, errors }] of Object.entries(t)) {
      sum[name] = { calls: (sum[name]?.calls ?? 0) + calls, errors: (sum[name]?.errors ?? 0) + errors };
    }
  }
  return Object.fromEntries(Object.entries(sum).sort(([x], [y]) => x.localeCompare(y)));
}

export const toolErrors = (tally: ToolTally): number => Object.values(tally).reduce((n, t) => n + t.errors, 0);

/** A rule a cell's tools broke: `scope` is 'all tools' for the cell as a whole, or the one tool's name. */
export interface Breach {
  scope: string;
  calls: number;
  errors: number;
}

const over = (t: { calls: number; errors: number }, share: number, limit: typeof TOOL_ERROR_LIMIT) => t.calls >= limit.min_calls && t.errors / t.calls > share;

/** Every rule the tally breaks: the cell as a whole first, then each tool, worst share first. Empty when it breaks none. */
export function breaches(tally: ToolTally, limit = TOOL_ERROR_LIMIT): Breach[] {
  const all = { calls: 0, errors: 0 };
  for (const t of Object.values(tally)) [all.calls, all.errors] = [all.calls + t.calls, all.errors + t.errors];
  const tools = Object.entries(tally)
    .filter(([, t]) => over(t, limit.tool_share, limit))
    .map(([scope, t]) => ({ scope, ...t }))
    .sort((a, b) => b.errors / b.calls - a.errors / a.calls || a.scope.localeCompare(b.scope));
  return [...(over(all, limit.cell_share, limit) ? [{ scope: 'all tools', ...all }] : []), ...tools];
}

const pctOf = (share: number) => `${share * 100}%`;
/** The limit a breach broke, in words. */
const limitText = (b: Breach, limit = TOOL_ERROR_LIMIT) =>
  `more than ${pctOf(b.scope === 'all tools' ? limit.cell_share : limit.tool_share)} of at least ${limit.min_calls}`;

/** The first error result's text for each tool in one question run. */
function firstErrors(turns: Turn[]): Record<string, string> {
  const first: Record<string, string> = {};
  for (const turn of turns) {
    for (const result of turn.tool_results) {
      if (!result.is_error) continue;
      const use = turn.response.content.find((b) => b.type === 'tool_use' && b.id === result.tool_use_id);
      const name = use?.type === 'tool_use' ? use.name : '(unknown)';
      const text = typeof result.content === 'string' ? result.content : JSON.stringify(result.content);
      first[name] ??= text.slice(0, FIRST_ERROR_CHARS);
    }
  }
  return first;
}

export interface CellJob {
  condition: Condition;
  model: string;
  rep: number;
}
export const cellLabel = (job: CellJob): string => `${job.condition} ${job.model} rep ${job.rep}`;

/**
 * The guard a run puts around every question run, for the cells in `jobs`.
 *
 * - `before(job)`: the first question run of a cell that uses the cli container checks the container
 *   (`checkContainer`), and the cell's other question runs wait for that check. A failed check rejects every one.
 * - `after(job, turns)`: adds the run's tool calls to its cell. When the cell's last question run is in, it throws
 *   if the cell broke a rule in `TOOL_ERROR_LIMIT`, naming the cell, each breach and each broken tool's first error.
 *
 * Without a container (a dry run) `before` checks nothing and `after` still applies the limit.
 */
export function cellGuard(jobs: CellJob[], container?: ContainerRun) {
  const sizes = new Map<string, number>();
  for (const job of jobs) sizes.set(cellLabel(job), (sizes.get(cellLabel(job)) ?? 0) + 1);
  const checks = new Map<string, Promise<void>>();
  const cells = new Map<string, { runs: number; tally: ToolTally; first: Record<string, string> }>();

  return {
    before(job: CellJob): Promise<void> {
      if (!container || !usesContainer(job.condition)) return Promise.resolve();
      const key = cellLabel(job);
      let check = checks.get(key);
      if (!check) {
        check = checkContainer(container).catch((error: Error) => {
          throw new Error(`${key}: ${error.message}`);
        });
        checks.set(key, check);
      }
      return check;
    },
    after(job: CellJob, turns: Turn[]): void {
      const key = cellLabel(job);
      const cell = cells.get(key) ?? { runs: 0, tally: {}, first: {} };
      cell.runs++;
      cell.tally = addTallies(cell.tally, toolTally(turns));
      cell.first = { ...firstErrors(turns), ...cell.first };
      cells.set(key, cell);
      if (cell.runs < (sizes.get(key) ?? 0)) return;
      const broken = breaches(cell.tally);
      if (!broken.length) return;
      const lines = broken.map((b) =>
        `${b.scope} errored on ${b.errors} of ${b.calls} calls (limit: ${limitText(b)}).` + (b.scope in cell.first ? ` First error: ${cell.first[b.scope]}` : ''));
      throw new Error(`tool error guard: the ${key} cell failed.\n${lines.join('\n')}`);
    },
  };
}
