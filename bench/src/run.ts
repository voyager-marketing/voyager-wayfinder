import Anthropic from '@anthropic-ai/sdk';
import { spawnSync } from 'node:child_process';
import { mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { z } from 'zod';
import { MAX_TURNS, runAgent, systemPrompt, type MessagesClient, type Turn } from './agent.ts';
import { extractAnswer, grade } from './grade.ts';
import { adapterTools, discoverAdapterTools, mcpClient } from './mcp.ts';
import { loadQuestions, type Question } from './questions.ts';
import { buildReport, CorrectionsFile, HypothesisRules, reportMeta, ReportSchema, run3Cells, Run3Rules, writeReport, type Report, type Result, type Rules } from './report.ts';
import { seedFixture, type Spawn } from './seed.ts';
import { loadPrivate, PRIVATE_DIR } from './private.ts';
import { startSnapshot } from './snapshot.ts';
import { checkReference, turnCapSensitivity } from './sensitivity.ts';
import { cliTools, liveTools, toolsFor, usesContainer, type Condition, type ToolExecutor } from './tools.ts';
import { cellGuard, toolErrors, toolTally } from './toolErrors.ts';
import type { Json } from './truth.ts';
import { addUsage, costUsd, emptyUsage, inputTotal } from './usage.ts';
import { checkContainer, dockerExecRunner, type ContainerRun } from './wpcli.ts';

export const BENCH_DIR = fileURLToPath(new URL('..', import.meta.url));

const str = z.string();
/**
 * Another question set on the same seeded site and answer key, picked with `--question-set <name>`. Its
 * `site` names the reports, so they never overwrite the fixture's own.
 */
const QuestionSet = z.object({ site: str, questions: str });
/**
 * One seeded site and its answer key. `seed` is the seed script's path inside wp-env, `recordings` the
 * dry-run transcripts recorded against its question set, when there are any. A fixture taken from a real site
 * names a `snapshot` pin file instead of a seed, and its own `credentials` file. `adapter_endpoint` replaces
 * config.json's for a site whose MCP server is not the adapter's default one, as on a site where a plugin turns
 * the default server off and serves its own read-only one. `reports` is the folder its reports go to, when it is
 * not `reports/`: every private fixture has one (src/private.ts).
 */
const Fixture = z.object({
  site: str, truth: str, questions: str, seed: str.optional(), snapshot: str.optional(), credentials: str.optional(),
  adapter_endpoint: str.optional(), recordings: str.optional(), reports: str.optional(), question_sets: z.record(str, QuestionSet).optional(),
}).refine((f) => (f.seed === undefined) !== (f.snapshot === undefined), { message: 'a fixture names either a seed or a snapshot' });
export const Config = z.object({
  default_fixture: str,
  fixtures: z.record(str, Fixture),
  site_url: str,
  credentials: str,
  conditions: z.array(z.enum(['raw', 'manual', 'manual-preloaded', 'adapter', 'cli', 'cli-manual'])),
  /**
   * From run 6 every agent model runs at its provider's default temperature, so `params` stays empty for all of them
   * and two cells differ only in the model. The judge keeps temperature 0 (`judge_params`): one judge grades every cell.
   */
  models: z.array(z.object({
    id: str,
    params: z.strictObject({}, { error: (issue) => (issue.code === 'unrecognized_keys' ? `models run at the provider's default temperature (temperature_policy), so params stays empty; remove ${issue.keys.join(', ')}` : undefined) }),
    cache_min_tokens: z.number(),
  })),
  temperature_policy: str,
  judge_model: str,
  judge_params: z.object({ temperature: z.number().optional() }),
  manual_routes: z.object({ brief: str, index: str, section: str, find: str }),
  adapter_endpoint: str,
  /**
   * From run 6: the most `wp` and `read_file` calls in flight at once in the cli container, across every question run.
   * They run with `docker exec`, so the one-at-a-time queue wp-env needed is gone; this cap keeps a run at
   * high `--concurrency` from booting WordPress in the container dozens of times at once. Unset means no cap.
   */
  cli_exec_limit: z.number().int().positive().optional(),
  /** A cache breakpoint on the latest message of every agent call. Off reproduces runs 1 and 2. */
  conversation_caching: z.boolean().default(false),
  /** Which hypotheses a new run is judged against: H1 to H6 (`hypotheses`) or R1 to R7 (`run3_hypotheses`). */
  hypothesis_set: z.enum(['run2', 'run3']).default('run2'),
  hypotheses: HypothesisRules,
  run3_hypotheses: Run3Rules,
});
type Config = z.infer<typeof Config>;

/**
 * The folder whose .wp-env.json runs the fixture's site, for the cli conditions' container: the repository root,
 * or for a snapshot fixture the folder of its pin, which holds its own .wp-env.json.
 */
export const wpEnvRoot = (fixture: { snapshot?: string }): string => (fixture.snapshot ? dirname(resolve(BENCH_DIR, fixture.snapshot)) : join(BENCH_DIR, '..'));

/**
 * Items the index left out to fit its character budget: the sum of its `+N more in section <name>` lines
 * (`IndexRenderer::truncate`). 0 on the fixtures; on a large real site a budget-sized index is a cut one, not a compact one.
 */
export const indexDropped = (index: string): number =>
  [...index.matchAll(/^\+(\d+) more in section \S+$/gm)].reduce((total, m) => total + Number(m[1]), 0);

/** The MCP server route the adapter condition talks to on this fixture's site. */
export const adapterEndpointFor = (config: Pick<Config, 'adapter_endpoint'>, fixture: { adapter_endpoint?: string }): string =>
  fixture.adapter_endpoint ?? config.adapter_endpoint;

/** The rules a run or a report is judged by. A report written before run 3 has no set, and was judged by H1 to H6. */
export const rulesFor = (config: Config, set: 'run2' | 'run3' = 'run2'): Rules => (set === 'run3' ? config.run3_hypotheses : config.hypotheses);

/** HEAD of the checkout the harness runs from, with `-dirty` when a tracked file differs from it. */
export function headCommit(): string | undefined {
  const git = (...args: string[]) => spawnSync('git', args, { cwd: BENCH_DIR, encoding: 'utf8' });
  const head = git('rev-parse', 'HEAD');
  if (head.status !== 0) return undefined;
  const dirty = git('status', '--porcelain', '--untracked-files=no').stdout.trim() !== '';
  return `${head.stdout.trim()}${dirty ? '-dirty' : ''}`;
}
const Price = z.object({ input: z.number(), cache_write_5m: z.number(), cache_read: z.number(), output: z.number() });
const Pricing = z.object({ source: str, fetched: str, models: z.record(str, Price) });

/** One question under one condition and model. Written to runs/ live; replayed from test/recordings/ in dry-run. */
export interface Transcript {
  question: string;
  condition: Condition;
  model: string;
  /** Which repetition of the cell this is, from 1. Absent in recordings, which are all rep 1. */
  rep?: number;
  /** Hand-written rather than recorded from the API. Dry-run fixtures only. */
  synthetic?: boolean;
  /** The brief and index the manual-preloaded condition put in its system prompt. */
  preload?: string;
  /** The MCP tools the adapter condition listed on the site and offered beside http_get. */
  mcp_tools?: Anthropic.Tool[];
  turns: Turn[];
  judge?: Anthropic.Message;
  wall_ms: number;
}

export interface BenchOptions {
  /** A key of `fixtures` in config.json. Default `default_fixture`. */
  fixture?: string;
  /** A key of the fixture's `question_sets`, asked in place of its own question set. */
  questionSet?: string;
  /** Seed the fixture on the local wp-env site before a live run. The CLI sets it when `--fixture` is given. */
  seed?: boolean;
  /** Runs the seed command. Tests pass a fake. */
  spawn?: Spawn;
  /** Runs the cli conditions' commands in the wp-env cli container. Default the pinned wp-env at the repository root. */
  container?: ContainerRun;
  /** `user:application-password` for the site. Default the fixture's credentials file, which its seed writes. Tests pass one. */
  credentials?: string;
  dryRun?: boolean;
  recordings?: string;
  out?: string;
  date?: string;
  siteUrl?: string;
  conditions?: string[];
  models?: string[];
  questions?: string[];
  /** Question runs in flight at once. Default 1. */
  concurrency?: number;
  /** Appended to the dated report's name, for example `run3` for `fixture-2026-10-03-run3.json`. */
  runLabel?: string;
  /** Model calls per question before the run counts as wrong. Default MAX_TURNS (12), as in runs 1 to 3. */
  maxTurns?: number;
  /** Passes of every cell, in place of the repetitions the hypothesis set gives the cells its rules read. */
  repetitions?: number;
  /** A report path from bench/, run at another cap: the report then sets each cell beside that report's. */
  sensitivityOf?: string;
  client?: MessagesClient;
}

/**
 * How many passes each cell gets. Under H1 to H6 only the two cells that decide H3 repeat; under R1 to R7
 * every cell a rule reads repeats. `override` gives every cell the same number.
 */
export function repetitionsFor(rules: Rules, models: string[], override?: number): (condition: string, model: string) => number {
  if (override !== undefined) return () => override;
  const repeated = 'r1_min_accuracy' in rules
    ? { cells: run3Cells(rules, models), times: rules.repetitions }
    : { cells: [rules.h3.challenger, rules.h3.baseline], times: rules.h3.repetitions };
  return (condition, model) => (repeated.cells.some((c) => c.condition === condition && c.model === model) ? repeated.times : 1);
}

const positiveInt = (name: string, value: number | undefined) => {
  if (value !== undefined && !(Number.isInteger(value) && value >= 1)) throw new Error(`${name} must be a whole number of at least 1, not ${value}`);
  return value;
};

interface Job {
  condition: Condition;
  model: string;
  question: Question;
  rep: number;
  preload?: string;
  mcp_tools?: Anthropic.Tool[];
  client: MessagesClient;
  execute: ToolExecutor;
  wall_ms?: number; // dry-run only: the recorded wall time, since replay time measures nothing
}

const readJson = (file: string): unknown => JSON.parse(readFileSync(file, 'utf8'));
const pick = <T>(items: T[], wanted: string[] | undefined, id: (t: T) => string) =>
  wanted?.length ? items.filter((t) => wanted.includes(id(t))) : items;

/**
 * config.json's fixtures plus any `bench/private/config.json` adds (src/private.ts). A private fixture may not
 * reuse a public fixture's name, and its reports go to `private/reports/` unless it names another folder.
 */
export function withPrivateFixtures(config: Config, privateDir = PRIVATE_DIR): Config {
  const extra = loadPrivate(privateDir);
  if (!extra) return config;
  const fixtures = { ...config.fixtures };
  for (const [key, raw] of Object.entries(extra.fixtures)) {
    if (fixtures[key]) throw new Error(`${join(privateDir, 'config.json')}: fixture "${key}" is already in config.json`);
    const fixture = Fixture.parse(raw);
    fixtures[key] = { ...fixture, reports: fixture.reports ?? join('private', 'reports') };
  }
  return { ...config, fixtures };
}

/**
 * config.json and the fixture `name` names, `default_fixture` when it is absent. With `questionSet`, the
 * fixture asks that set's questions and names its reports by the set's `site`, on the same seed and truth.
 * The fixture's recordings answer its own questions, so a question set has none. `privateDir` is for tests.
 */
export function loadConfig(name?: string, questionSet?: string, privateDir?: string) {
  const config = withPrivateFixtures(Config.parse(readJson(join(BENCH_DIR, 'config.json'))), privateDir);
  // With no fixture named, a set the default fixture lacks is looked up on the others.
  const owner = name === undefined && questionSet !== undefined && !config.fixtures[config.default_fixture]?.question_sets?.[questionSet]
    ? Object.keys(config.fixtures).find((k) => config.fixtures[k].question_sets?.[questionSet])
    : undefined;
  const key = name ?? owner ?? config.default_fixture;
  const fixture = config.fixtures[key];
  if (!fixture) throw new Error(`no fixture "${key}" in config.json or bench/private/config.json; the fixtures are ${Object.keys(config.fixtures).join(', ')}`);
  if (questionSet === undefined) return { config, fixture: { name: key, ...fixture, question_set: undefined as string | undefined } };
  const set = fixture.question_sets?.[questionSet];
  if (!set) throw new Error(`the ${key} fixture has no question set "${questionSet}" in config.json; its sets are ${Object.keys(fixture.question_sets ?? {}).join(', ') || 'none'}`);
  return { config, fixture: { name: key, ...fixture, ...set, recordings: undefined, question_set: questionSet as string | undefined } };
}

export function loadContext(name?: string, questionSet?: string) {
  const { config, fixture } = loadConfig(name, questionSet);
  const pricing = Pricing.parse(readJson(join(BENCH_DIR, 'pricing.json')));
  const qs = loadQuestions(resolve(BENCH_DIR, fixture.questions));
  if (qs.questions_sha256 !== qs.computed_sha256) {
    throw new Error(`${fixture.questions}: the header says ${qs.questions_sha256} but the questions hash to ${qs.computed_sha256}. Update the header so the edit is visible.`);
  }
  for (const id of [...config.models.map((m) => m.id), config.judge_model]) {
    if (!pricing.models[id]) throw new Error(`pricing.json has no entry for ${id}`);
  }
  return { config, fixture, pricing, qs, truth: readJson(resolve(BENCH_DIR, fixture.truth)) as Json };
}

/** Recorded responses and tool results, served back in order with no network. */
function replay(t: Transcript): { client: MessagesClient; execute: ToolExecutor } {
  const where = `${t.condition}.${t.model}.${t.question}`;
  const responses = [...t.turns.map((turn) => turn.response), ...(t.judge ? [t.judge] : [])];
  const tools = t.turns.flatMap((turn) =>
    turn.tool_results.map((result) => {
      const use = turn.response.content.find((b) => b.type === 'tool_use' && b.id === result.tool_use_id);
      return { name: use?.type === 'tool_use' ? use.name : '', result };
    }),
  );
  // A recorded tool call must name a tool the condition offers, so a recording cannot drift from its tool list.
  const create = async (params: Anthropic.MessageCreateParamsNonStreaming) => {
    const next = responses.shift();
    if (!next) throw new Error(`${where}: recording has no more responses`);
    for (const b of next.content) {
      if (b.type === 'tool_use' && !params.tools?.some((t) => 'name' in t && t.name === b.name)) throw new Error(`${where}: recording calls ${b.name}, which the condition does not offer`);
    }
    return next;
  };
  const execute = async (name: string) => {
    const next = tools.shift();
    if (next?.name !== name) throw new Error(`${where}: recording expected ${next?.name ?? 'no tool call'}, got ${name}`);
    const content = typeof next.result.content === 'string' ? next.result.content : JSON.stringify(next.result.content);
    if (next.result.is_error) throw new Error(content);
    return content;
  };
  return { client: { messages: { create } }, execute };
}

/** Fetches the brief and index once per run, so every question sees identical cached bytes. */
export async function fetchManual(execute: ToolExecutor): Promise<{ brief: string; index: string }> {
  const parts: string[] = [];
  for (const tool of ['manual_brief', 'manual_index']) {
    const body = await execute(tool, {});
    if (!body.startsWith('HTTP 200\n')) throw new Error(`${tool} did not return 200: ${body.slice(0, 120)}`);
    parts.push(body.slice('HTTP 200\n'.length));
  }
  return { brief: parts[0], index: parts[1] };
}

/** Characters (code points) and chars / 4 rounded up, the way `wp wayfinder stats` counts them. */
const chars = (text: string) => [...text].length;
const estTokens = (text: string) => Math.ceil(chars(text) / 4);

/** Runs `fn` over `items` with at most `limit` in flight. Results keep the input order. After a failure no new item starts. */
export async function pool<T, R>(items: T[], limit: number, fn: (item: T) => Promise<R>): Promise<R[]> {
  const out: R[] = new Array(items.length);
  let next = 0;
  let failed = false;
  const worker = async () => {
    while (!failed && next < items.length) {
      const i = next++;
      try {
        out[i] = await fn(items[i]);
      } catch (error) {
        failed = true;
        throw error;
      }
    }
  };
  await Promise.all(Array.from({ length: Math.max(1, Math.min(limit, items.length)) }, worker));
  return out;
}

/** The SDK already retries 429, 5xx and connection errors. This covers an outage that outlasts its backoff. */
async function withRetries<T>(label: string, fn: () => Promise<T>, waits: number[]): Promise<T> {
  for (let attempt = 0; ; attempt++) {
    try {
      return await fn();
    } catch (error) {
      if (attempt >= waits.length) throw error;
      console.warn(`${label}: ${(error as Error).message}. Retrying in ${waits[attempt] / 1000}s.`);
      await new Promise((done) => setTimeout(done, waits[attempt]));
    }
  }
}

export async function runBench(opts: BenchOptions = {}): Promise<{ report: Report; paths: string[] }> {
  const { config, fixture, pricing, qs, truth } = loadContext(opts.fixture, opts.questionSet);
  let siteUrl = opts.siteUrl ?? config.site_url;
  const questions = pick(qs.questions, opts.questions, (q) => q.id);
  const models = pick(config.models, opts.models, (m) => m.id);
  const conditions = pick(config.conditions, opts.conditions, (c) => c);
  const paramsFor = (id: string) => config.models.find((m) => m.id === id)?.params ?? {};
  const rules = rulesFor(config, config.hypothesis_set);
  const repsFor = repetitionsFor(rules, config.models.map((m) => m.id), positiveInt('--repetitions', opts.repetitions));
  const maxTurns = positiveInt('--max-turns', opts.maxTurns) ?? MAX_TURNS;
  // Read before any API call, so a bad reference stops the run while it is still free.
  const reference = opts.sensitivityOf ? { file: opts.sensitivityOf, report: ReportSchema.parse(readJson(resolve(BENCH_DIR, opts.sensitivityOf))) } : undefined;
  if (reference) checkReference(reference, { fixture: fixture.name, sha256: qs.questions_sha256, maxTurns });
  let commit: string | undefined;
  const jobs: Job[] = [];
  let runDir: string | undefined;
  let manual: Report['manual'] = null;
  let adapterToolNames: string[] | undefined;
  let container: ContainerRun | undefined;

  if (opts.dryRun) {
    // Recordings answer one question set, and question ids repeat across sets, so another set's recordings never stand in.
    const dir = opts.recordings ?? (fixture.recordings && join(BENCH_DIR, fixture.recordings));
    if (!dir) throw new Error(`no recordings for the ${fixture.question_set ? `${fixture.question_set} question set` : `${fixture.name} fixture's questions`}; pass --recordings <dir>`);
    for (const file of readdirSync(dir).filter((f) => f.endsWith('.json')).sort()) {
      const t = readJson(join(dir, file)) as Transcript;
      const question = questions.find((q) => q.id === t.question);
      if (question && models.some((m) => m.id === t.model) && conditions.includes(t.condition)) {
        jobs.push({ ...t, ...replay(t), question, rep: t.rep ?? 1 });
      }
    }
  } else {
    // Seeding first means the site matches this fixture's answer key, and it rewrites the credentials read below.
    // A snapshot fixture is started and imported instead, on its own wp-env site, whose URL the run then uses.
    if (opts.seed && fixture.snapshot) siteUrl = opts.siteUrl ?? (await startSnapshot(resolve(BENCH_DIR, fixture.snapshot), opts.spawn)).siteUrl;
    else if (opts.seed && fixture.seed) seedFixture(fixture.seed, join(BENCH_DIR, '..'), opts.spawn);
    // Read after seeding, so a seed that rewrote a tracked file such as truth.json shows as -dirty.
    commit = headCommit();
    const credentials = opts.credentials ?? readFileSync(resolve(BENCH_DIR, fixture.credentials ?? config.credentials), 'utf8').trim();
    const execute = liveTools({ url: siteUrl, credentials, routes: config.manual_routes });
    const client = opts.client ?? new Anthropic({ maxRetries: 8 });
    const { brief, index } = await fetchManual(execute);
    manual = { brief_chars: chars(brief), index_chars: chars(index), brief_est_tokens: estTokens(brief), index_est_tokens: estTokens(index), index_dropped: indexDropped(index) };
    const mcp = conditions.includes('adapter') ? mcpClient({ url: siteUrl, credentials, endpoint: adapterEndpointFor(config, fixture) }) : undefined;
    const mcpTools = mcp && (await discoverAdapterTools(mcp));
    adapterToolNames = mcpTools?.map((t) => t.name).sort();
    // Checked before any question, as the adapter is, so an unreachable container stops the run while it is free,
    // and again before each cli cell (cellGuard below), since run 5's broke partway through. Each check finds the
    // container again from the site's compose project.
    container = conditions.some(usesContainer) ? (opts.container ?? dockerExecRunner(wpEnvRoot(fixture), { limit: config.cli_exec_limit })) : undefined;
    if (container) await checkContainer(container);
    for (const condition of conditions) {
      const preload = condition === 'manual-preloaded' ? `${brief}\n\n${index}` : undefined;
      const tools = condition === 'adapter' && mcp ? { mcp_tools: mcpTools, execute: adapterTools(execute, mcp) }
        : container && usesContainer(condition) ? { execute: cliTools(execute, container) }
        : { execute };
      for (const { id } of models) {
        for (let rep = 1; rep <= repsFor(condition, id); rep++) {
          for (const question of questions) jobs.push({ condition, model: id, question, rep, preload, client, ...tools });
        }
      }
    }
    runDir = join(BENCH_DIR, 'runs', new Date().toISOString().replace(/[:.]/g, '-'));
    mkdirSync(runDir, { recursive: true });
  }
  if (!jobs.length) throw new Error('nothing to run: no recordings or questions matched the filters');

  const concurrency = opts.dryRun ? 1 : Math.max(1, opts.concurrency ?? 1);
  // The container is checked before each cli cell, and a cell with a broken tool stops the run.
  const guard = cellGuard(jobs, container);
  const results: Result[] = await pool(jobs, concurrency, async (job) => {
    const { client, condition, model, question, rep, preload, mcp_tools } = job;
    const label = `${condition} ${model} ${question.id}${rep > 1 ? ` rep ${rep}` : ''}`;
    await guard.before(job);
    // A retry reruns the whole question, so a failed attempt never leaves half a transcript behind.
    const { run, verdict } = await withRetries(label, async () => {
      const run = await runAgent({
        client, model, params: paramsFor(model), system: systemPrompt(siteUrl, preload), tools: toolsFor(condition, mcp_tools),
        execute: job.execute, prompt: question.prompt, cacheConversation: config.conversation_caching, maxTurns,
      });
      const verdict = run.hit_turn_cap
        ? { correct: false, reason: 'turn cap reached with no answer', judge: undefined }
        : await grade(question, run.final_text, truth, { client, model: config.judge_model, params: config.judge_params });
      return { run, verdict };
    }, opts.dryRun ? [] : [30_000, 90_000]);
    const judgeCost = verdict.judge ? costUsd(addUsage(emptyUsage(), verdict.judge.usage), pricing.models[config.judge_model]) : 0;
    const wall_ms = job.wall_ms ?? run.wall_ms;
    const firstCall = run.turns.length ? addUsage(emptyUsage(), run.turns[0].response.usage) : emptyUsage();
    if (runDir) {
      const transcript: Transcript = { question: question.id, condition, model, rep, preload, mcp_tools, turns: run.turns, judge: verdict.judge, wall_ms };
      writeFileSync(join(runDir, `${condition}.${model}.${question.id}${rep > 1 ? `.r${rep}` : ''}.json`), `${JSON.stringify(transcript, null, 2)}\n`);
      console.log(`${label}: ${verdict.correct ? 'correct' : 'wrong'} (${verdict.reason})`);
    }
    // After the transcript is written, so a cell that fails the guard keeps its evidence.
    guard.after(job, run.turns);
    const tools = toolTally(run.turns);
    return {
      question: question.id, category: question.category, condition, model, rep, correct: verdict.correct, reason: verdict.reason,
      answer: /^\s*ANSWER:/im.test(run.final_text) ? extractAnswer(run.final_text).trim() : '',
      usage: run.usage, first_call_input_tokens: inputTotal(firstCall), tool_calls: run.tool_calls, tool_errors: toolErrors(tools), tools, turns: run.turns.length,
      hit_turn_cap: run.hit_turn_cap, wall_ms, cost_usd: costUsd(run.usage, pricing.models[model]), judge_cost_usd: judgeCost,
    };
  });
  // Each cli cell was checked before it started. Once more after the last, so a shell lost late in the run fails it too.
  if (container) await checkContainer(container).catch((error: Error) => {
    throw new Error(`after the last cell: ${error.message}`);
  });

  const built = buildReport(results, {
    format: 'wayfinder-bench-report/1',
    site: fixture.site,
    date: opts.date ?? new Date().toISOString().slice(0, 10),
    ...(opts.runLabel && { run_label: opts.runLabel }),
    fixture: fixture.name,
    hypothesis_set: config.hypothesis_set,
    ...(commit && { commit }),
    conversation_caching: config.conversation_caching,
    max_turns: maxTurns,
    ...(conditions.includes('adapter') && { adapter_endpoint: adapterEndpointFor(config, fixture) }),
    ...(adapterToolNames && { adapter_tools: adapterToolNames }),
    dry_run: Boolean(opts.dryRun),
    runner: { where: opts.dryRun ? 'dry-run' : process.env.GITHUB_ACTIONS === 'true' ? 'github-actions' : 'local', concurrency },
    question_set: { file: fixture.questions, sha256: qs.questions_sha256, count: qs.questions.length },
    pricing: { source: pricing.source, fetched: pricing.fetched },
    models: models.map((m) => ({ id: m.id, params: m.params, cache_min_tokens: m.cache_min_tokens })),
    temperature_policy: { agents: 'provider-default', judge: { model: config.judge_model, params: config.judge_params } },
    manual,
  }, rules);
  const report = reference ? ReportSchema.parse({ ...built, sensitivity: turnCapSensitivity(reference, built) }) : built;
  const out = opts.out ?? (opts.dryRun ? join(BENCH_DIR, 'runs', 'dry-run') : resolve(BENCH_DIR, fixture.reports ?? 'reports'));
  return { report, paths: writeReport(report, out) };
}

/**
 * Rebuilds a written report from its own results with hand re-graded misses added as `corrected`.
 * No API calls. Every field the graders produced comes out unchanged, and the report is rewritten in place.
 */
export function regradeReport(reportFile: string, correctionsFile: string): { report: Report; paths: string[] } {
  const { config } = loadContext();
  const written = ReportSchema.parse(readJson(reportFile));
  const meta = reportMeta(written);
  const report = buildReport(written.results, meta, rulesFor(config, meta.hypothesis_set), CorrectionsFile.parse(readJson(correctionsFile)));
  return { report, paths: writeReport(report, dirname(reportFile)) };
}
