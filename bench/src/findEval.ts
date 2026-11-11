import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, posix, resolve } from 'node:path';
import { parse } from 'yaml';
import { z } from 'zod';
import type { Question } from './questions.ts';
import { BENCH_DIR, headCommit, loadContext } from './run.ts';
import { seedFixture, type Spawn } from './seed.ts';
import { startSnapshot } from './snapshot.ts';
import { expectedValues, resolveRef, type Json } from './truth.ts';

/**
 * The find eval: each edit-location question's find inputs go to the find route, and the answer key's
 * location is checked against hit 1 and against every hit. No model is called, so the result has no sampling
 * noise and costs nothing, and it can run on every change to find.
 */

/**
 * An answer that names an edit screen or a block path is an edit location. The same rule decides which
 * edit-safety-2 questions carry find inputs (test/questions.edit-safety-2.test.ts).
 */
export const LOCATION = /\.(?:edit_path|edit_paths|paths_now)$/;
export const isLocation = (q: Question): boolean => q.answer.refs.some((r) => LOCATION.test(r));

export type FindInput = NonNullable<Question['find']>;
const FindSpec = z.object({ url: z.string(), text: z.string().optional(), class: z.string().optional() }).strict();

/**
 * Find inputs for a set frozen before it carried them, in `questions/find-inputs-<question file>.yaml`, so the
 * set's own hash is untouched. `questions_sha256` is the set's hash the inputs were written against, and the
 * eval stops when the set has moved since. `location` replaces the answer's location references for one
 * question, when the key names a list screen and the question one post on it. `none` says why a question gets
 * no inputs: no page shows what it asks about.
 */
const FindInputsFile = z.object({
  questions: z.string(),
  questions_sha256: z.string().regex(/^[0-9a-f]{64}$/),
  inputs: z.array(z.union([
    z.object({ id: z.string(), find: FindSpec, location: z.array(z.string()).min(1).optional() }).strict(),
    z.object({ id: z.string(), none: z.string().min(1) }).strict(),
  ])),
});
export type FindInputsFile = z.infer<typeof FindInputsFile>;

/** `questions/fixture.yaml` keeps its find inputs in `questions/find-inputs-fixture.yaml`. */
export const findInputsPath = (questionsFile: string): string =>
  posix.join(posix.dirname(questionsFile), `find-inputs-${posix.basename(questionsFile, '.yaml')}.yaml`);

export function loadFindInputs(file: string): FindInputsFile | undefined {
  return existsSync(file) ? FindInputsFile.parse(parse(readFileSync(file, 'utf8'))) : undefined;
}

/** An admin screen, or a block by its post and block path. */
export type Location = { edit: string } | { post: number; path: string };

/** `/wp-admin/post.php?post=1&amp;action=edit` and `post.php?post=1&action=edit` are the same screen. */
export function normalizeEdit(edit: string): string {
  let out = edit.trim().replace(/&amp;/g, '&');
  try {
    out = decodeURIComponent(out);
  } catch {
    // Not percent-encoded as a whole; compare as given.
  }
  return out.replace(/^[a-z]+:\/\/[^/]+/i, '').replace(/^\/+/, '').replace(/^wp-admin\//, '');
}

/**
 * The locations a question's answer key names. A `paths_now` reference is a block's current paths in the post
 * its record names; any other location reference is an admin screen.
 */
export function expectedLocations(refs: string[], truth: Json): Location[] {
  const out: Location[] = [];
  for (const ref of refs) {
    if (ref.endsWith('.paths_now')) {
      const post = resolveRef(truth, `${ref.slice(0, -'paths_now'.length)}post`);
      if (typeof post !== 'number') throw new Error(`${ref}: its record has no post id in truth.json`);
      for (const path of expectedValues(truth, [ref])) out.push({ post, path });
    } else {
      for (const edit of expectedValues(truth, [ref])) out.push({ edit: normalizeEdit(edit) });
    }
  }
  return out.filter((loc, i) => out.findIndex((other) => sameLocation(loc, other)) === i);
}

const sameLocation = (a: Location, b: Location): boolean =>
  'edit' in a ? 'edit' in b && a.edit === b.edit : !('edit' in b) && a.post === b.post && a.path === b.path;

export interface Planned {
  question: Question;
  find: FindInput;
  /** The truth.json references the location comes from. */
  refs: string[];
  expected: Location[];
}

export interface Skipped {
  question: string;
  category: string;
  reason: string;
}

/**
 * Which questions the eval asks, with their inputs and expected locations. A question's own `find` comes first;
 * a frozen set's come from its inputs file. An edit-location question with no inputs, and one with inputs whose
 * answer names no location, are skipped with the reason. An inputs file that names an unknown question, a
 * question that has its own, or a question twice, or that was written against another version of the set, stops.
 */
export function planFindEval(questions: Question[], sha256: string, truth: Json, inputs?: FindInputsFile): { planned: Planned[]; skipped: Skipped[] } {
  const extra = new Map<string, FindInputsFile['inputs'][number]>();
  if (inputs) {
    if (inputs.questions_sha256 !== sha256) {
      throw new Error(`the find inputs for ${inputs.questions} were written against questions_sha256 ${inputs.questions_sha256}, but the set is now ${sha256}. Check every input against the changed set, then update the header.`);
    }
    for (const entry of inputs.inputs) {
      const q = questions.find((x) => x.id === entry.id);
      if (!q) throw new Error(`the find inputs name ${entry.id}, which is not in ${inputs.questions}`);
      if (q.find) throw new Error(`${entry.id} carries its own find inputs; the inputs file must not repeat them`);
      if (extra.has(entry.id)) throw new Error(`the find inputs name ${entry.id} twice`);
      extra.set(entry.id, entry);
    }
  }
  const planned: Planned[] = [];
  const skipped: Skipped[] = [];
  for (const question of questions) {
    const entry = extra.get(question.id);
    const given = entry && 'find' in entry ? entry : undefined;
    const find = question.find ?? given?.find;
    const refs = given?.location ?? question.answer.refs.filter((r) => LOCATION.test(r));
    const skip = (reason: string) => skipped.push({ question: question.id, category: question.category, reason });
    if (entry && 'none' in entry) {
      skip(entry.none);
    } else if (!find) {
      if (isLocation(question)) skip('no find inputs');
    } else if (!refs.length) {
      skip('its answer names no edit screen or block path');
    } else {
      planned.push({ question, find, refs, expected: expectedLocations(refs, truth) });
    }
  }
  return { planned, skipped };
}

const Anchor = z.object({ entity: z.string(), post: z.number().nullable(), path: z.string() }).loose();
const Hit = z.object({ rank: z.number(), id: z.string(), block_path: z.string().nullable(), snippet: z.string(), on_route: z.boolean(), anchor: Anchor.nullable() }).loose();
const ChainEntry = z.object({ id: z.string(), label: z.string(), edit: z.string().nullable() }).loose();
/** The find route's answer, as much of it as the eval reads (src/Edit/EditLocator.php). */
export const FindResult = z.object({
  route: z.string().nullable(),
  matched: z.boolean().nullable(),
  ambiguous: z.boolean(),
  message: z.string().nullable(),
  chain: z.array(ChainEntry),
  hits: z.array(Hit),
  verify: z.object({ changes_since: z.string().nullable() }).loose(),
}).loose();
export type FindResult = z.infer<typeof FindResult>;

/** One entry of what find ranked: a hit, or for a URL alone a chain entry, with the location it resolves to. */
export interface Ranked {
  rank: number;
  id: string;
  edit: string | null;
  post: number | null;
  block_path: string | null;
  snippet?: string;
}

/**
 * The admin screen and block each hit names. A hit carries an item id and, on a block, an anchor; its screen is
 * the one find itself gives that id: the result's chain, then the chain of the URL alone (everything the page is
 * built from, bindings included, with the page's own post), then the anchor's post for content off this route,
 * then the manifest item's `edit`. With no text and no class find returns no hits, so the chain is the ranking.
 */
export async function rankedEntries(result: FindResult, routeChain: () => Promise<FindResult['chain']>, itemEdit: (id: string) => Promise<string | null>): Promise<{ from: 'hits' | 'chain'; ranked: Ranked[] }> {
  if (!result.hits.length && result.matched === null) {
    return { from: 'chain', ranked: result.chain.map((e, i) => ({ rank: i + 1, id: e.id, edit: e.edit && normalizeEdit(e.edit), post: null, block_path: null })) };
  }
  const inChain = (chain: FindResult['chain'], id: string) => chain.find((e) => e.id === id && e.edit !== null)?.edit ?? undefined;
  const ranked: Ranked[] = [];
  for (const hit of result.hits) {
    const fromChain = inChain(result.chain, hit.id) ?? inChain(await routeChain(), hit.id);
    const post = hit.anchor?.post ?? null;
    const edit = fromChain ?? (post !== null && !hit.id.startsWith('bind:') ? `post.php?post=${post}&action=edit` : await itemEdit(hit.id));
    ranked.push({ rank: hit.rank, id: hit.id, edit: edit && normalizeEdit(edit), post, block_path: hit.block_path, snippet: hit.snippet });
  }
  return { from: 'hits', ranked };
}

export const matchesLocation = (loc: Location, entry: Ranked): boolean =>
  'edit' in loc ? entry.edit === loc.edit : entry.post === loc.post && entry.block_path === loc.path;

export interface Score {
  /** Hit 1 is at one of the expected locations. */
  top1: boolean;
  /** Every expected location is at some hit. One location: it is among the hits. */
  any_hit: boolean;
  /** Expected locations found among the hits. */
  found: number;
  /** The rank of the first hit at an expected location, or null. */
  first_match: number | null;
}

export function score(expected: Location[], ranked: Ranked[]): Score {
  const found = expected.filter((loc) => ranked.some((e) => matchesLocation(loc, e))).length;
  const first = ranked.find((e) => expected.some((loc) => matchesLocation(loc, e)));
  return { top1: ranked.length > 0 && expected.some((loc) => matchesLocation(loc, ranked[0])), any_hit: found === expected.length, found, first_match: first?.rank ?? null };
}

const LocationSchema = z.union([z.object({ edit: z.string() }).strict(), z.object({ post: z.number(), path: z.string() }).strict()]);
const RankedSchema = z.object({ rank: z.number(), id: z.string(), edit: z.string().nullable(), post: z.number().nullable(), block_path: z.string().nullable(), snippet: z.string().optional() });
const Tally = z.object({ questions: z.number(), top1: z.number(), any_hit: z.number() });

export const FindEvalReport = z.object({
  format: z.literal('wayfinder-find-eval/1'),
  site: z.string(),
  date: z.string(),
  fixture: z.string(),
  commit: z.string().optional(),
  question_set: z.object({ file: z.string(), sha256: z.string(), count: z.number(), find_inputs: z.string().nullable() }),
  /** The stored manifest hash find answered with, or null when none was stored and hits carry no route counts. */
  snapshot: z.string().nullable(),
  totals: Tally.extend({ from_hits: Tally, from_chain: Tally }),
  by_category: z.array(Tally.extend({ category: z.string() })),
  questions: z.array(z.object({
    question: z.string(),
    category: z.string(),
    find: FindSpec,
    refs: z.array(z.string()),
    expected: z.array(LocationSchema),
    status: z.number(),
    route: z.string().nullable(),
    matched: z.boolean().nullable(),
    ambiguous: z.boolean(),
    message: z.string().nullable(),
    ranked_from: z.enum(['hits', 'chain']),
    top1: z.boolean(),
    any_hit: z.boolean(),
    found: z.number(),
    first_match: z.number().nullable(),
    ranked: z.array(RankedSchema),
  })),
  skipped: z.array(z.object({ question: z.string(), category: z.string(), reason: z.string() })),
});
export type FindEvalReport = z.infer<typeof FindEvalReport>;
type Row = FindEvalReport['questions'][number];

const tally = (rows: Row[]) => ({ questions: rows.length, top1: rows.filter((r) => r.top1).length, any_hit: rows.filter((r) => r.any_hit).length });

export function summarize(rows: Row[]): Pick<FindEvalReport, 'totals' | 'by_category'> {
  const categories = [...new Set(rows.map((r) => r.category))].sort();
  return {
    totals: { ...tally(rows), from_hits: tally(rows.filter((r) => r.ranked_from === 'hits')), from_chain: tally(rows.filter((r) => r.ranked_from === 'chain')) },
    by_category: categories.map((category) => ({ category, ...tally(rows.filter((r) => r.category === category)) })),
  };
}

const share = (n: number, of: number) => (of ? `${n} (${((100 * n) / of).toFixed(1)}%)` : '0');
const inputs = (f: FindInput) => [`\`${f.url}\``, f.text !== undefined && `text "${f.text}"`, f.class !== undefined && `class \`${f.class}\``].filter(Boolean).join(', ');
const where = (loc: Location) => ('edit' in loc ? `\`${loc.edit}\`` : `post ${loc.post} block \`${loc.path}\``);
const named = (e: Ranked | undefined) => (e ? `${e.id}${e.block_path !== null ? ` \`${e.block_path}\`` : ''}${e.edit ? ` (\`${e.edit}\`)` : ''}` : 'none');

export function renderFindEval(report: FindEvalReport): string {
  const { totals: t } = report;
  const lines = [
    `# Find eval: ${report.site}, ${report.date}`,
    '',
    `\`${report.question_set.file}\` (sha256 \`${report.question_set.sha256.slice(0, 12)}\`), find inputs from ${report.question_set.find_inputs ? `\`${report.question_set.find_inputs}\` and the questions` : 'the questions'}, on the ${report.fixture} fixture${report.commit ? ` at \`${report.commit}\`` : ''}. No model is called: each edit-location question's find inputs go to the find route, and the answer key's location is checked against hit 1 and against every hit. ${report.snapshot ? `Find read the stored manifest \`${report.snapshot.slice(0, 12)}\`.` : 'No manifest was stored, so hits carry no route counts.'}`,
    '',
    '| | Questions | Top-1 | Any hit |',
    '|---|---|---|---|',
    `| All | ${t.questions} | ${share(t.top1, t.questions)} | ${share(t.any_hit, t.questions)} |`,
    `| Ranked hits (text or class given) | ${t.from_hits.questions} | ${share(t.from_hits.top1, t.from_hits.questions)} | ${share(t.from_hits.any_hit, t.from_hits.questions)} |`,
    `| Chain (URL only, find returns no hits) | ${t.from_chain.questions} | ${share(t.from_chain.top1, t.from_chain.questions)} | ${share(t.from_chain.any_hit, t.from_chain.questions)} |`,
    '',
    'Any hit means every location the key names is at some hit, so a question with three homes needs all three.',
    '',
    '## By category',
    '',
    '| Category | Questions | Top-1 | Any hit |',
    '|---|---|---|---|',
    ...report.by_category.map((c) => `| ${c.category} | ${c.questions} | ${share(c.top1, c.questions)} | ${share(c.any_hit, c.questions)} |`),
    '',
    '## Questions',
    '',
    '| Question | Find inputs | Expected | Top-1 | Any hit | First match | Hit 1 |',
    '|---|---|---|---|---|---|---|',
    ...report.questions.map((q) => `| ${q.question} | ${inputs(q.find)}${q.ranked_from === 'chain' ? ' (chain)' : ''} | ${q.expected.map(where).join(', ')} | ${q.top1 ? 'yes' : 'no'}${q.ambiguous ? ', ambiguous' : ''} | ${q.any_hit ? 'yes' : 'no'}${q.expected.length > 1 ? ` (${q.found} of ${q.expected.length})` : ''} | ${q.first_match ?? 'none'} | ${q.status === 200 ? named(q.ranked[0]) : `HTTP ${q.status}`} |`),
  ];
  if (report.skipped.length) {
    lines.push('', '## Not asked', '', ...report.skipped.map((s) => `- ${s.question} (${s.category}): ${s.reason}`));
  }
  return `${lines.join('\n')}\n`;
}

export interface FindEvalOptions {
  fixture?: string;
  questionSet?: string;
  /** Seed the fixture first. The CLI sets it when `--fixture` or `--question-set` is given, as a bench run does. */
  seed?: boolean;
  spawn?: Spawn;
  siteUrl?: string;
  /** `user:application-password`. Default `fixtures/.credentials`, which the seed writes. */
  credentials?: string;
  out?: string;
  date?: string;
  fetchImpl?: typeof fetch;
}

/** Runs the eval against a live site and writes `<site>-<date>-find-eval.json` and `.md`. */
export async function runFindEval(opts: FindEvalOptions = {}): Promise<{ report: FindEvalReport; paths: string[] }> {
  const { config, fixture, qs, truth } = loadContext(opts.fixture, opts.questionSet);
  const inputsFile = findInputsPath(fixture.questions);
  const inputs = loadFindInputs(resolve(BENCH_DIR, inputsFile));
  // Planned before seeding, so a stale inputs file stops the eval before it touches the site.
  const { planned, skipped } = planFindEval(qs.questions, qs.questions_sha256, truth, inputs);
  if (!planned.length) throw new Error(`no question in ${fixture.questions} has find inputs and an edit location`);
  // A snapshot fixture is started and imported instead of seeded, on its own site, as a bench run does.
  let siteUrl = opts.siteUrl ?? config.site_url;
  if (opts.seed && fixture.snapshot) siteUrl = opts.siteUrl ?? (await startSnapshot(resolve(BENCH_DIR, fixture.snapshot), opts.spawn)).siteUrl;
  else if (opts.seed && fixture.seed) seedFixture(fixture.seed, join(BENCH_DIR, '..'), opts.spawn);
  const commit = headCommit();

  const credentials = opts.credentials ?? readFileSync(resolve(BENCH_DIR, fixture.credentials ?? config.credentials), 'utf8').trim();
  const authorization = `Basic ${Buffer.from(credentials).toString('base64')}`;
  const fetchImpl = opts.fetchImpl ?? fetch;
  const fill = (template: string, values: Record<string, string | undefined>) =>
    template.replace(/\{(\w+)\}/g, (_, key: string) => encodeURIComponent(values[key] ?? ''));
  const get = async (route: string) => {
    const res = await fetchImpl(new URL(route, siteUrl), { headers: { authorization } });
    return { status: res.status, body: await res.text() };
  };
  const find = async (input: FindInput) => {
    const { status, body } = await get(fill(config.manual_routes.find, { ...input }));
    return { status, result: status === 200 ? FindResult.parse(JSON.parse(body)) : undefined, body };
  };
  const routeChains = new Map<string, FindResult['chain']>();
  const routeChain = async (url: string) => {
    if (!routeChains.has(url)) routeChains.set(url, (await find({ url })).result?.chain ?? []);
    return routeChains.get(url) as FindResult['chain'];
  };
  const itemEdits = new Map<string, string | null>();
  const itemEdit = async (id: string) => {
    if (!itemEdits.has(id)) {
      const { status, body } = await get(fill(config.manual_routes.section, { id }));
      const edit = status === 200 ? (JSON.parse(body) as { edit?: unknown }).edit : null;
      itemEdits.set(id, typeof edit === 'string' ? edit : null);
    }
    return itemEdits.get(id) as string | null;
  };

  let snapshot: string | null = null;
  const rows: Row[] = [];
  for (const { question, find: input, refs, expected } of planned) {
    const { status, result, body } = await find(input);
    if (result?.verify.changes_since) snapshot = result.verify.changes_since;
    const { from, ranked } = result ? await rankedEntries(result, () => routeChain(input.url), itemEdit) : { from: 'hits' as const, ranked: [] };
    const message = result ? result.message : body.slice(0, 300);
    rows.push({
      question: question.id, category: question.category, find: input, refs, expected, status,
      route: result?.route ?? null, matched: result?.matched ?? null, ambiguous: result?.ambiguous ?? false, message,
      ranked_from: from, ...score(expected, ranked), ranked,
    });
  }

  const report = FindEvalReport.parse({
    format: 'wayfinder-find-eval/1',
    site: fixture.site,
    date: opts.date ?? new Date().toISOString().slice(0, 10),
    fixture: fixture.name,
    ...(commit && { commit }),
    question_set: { file: fixture.questions, sha256: qs.questions_sha256, count: qs.questions.length, find_inputs: inputs ? inputsFile : null },
    snapshot,
    ...summarize(rows),
    questions: rows,
    skipped,
  });
  const dir = opts.out ?? resolve(BENCH_DIR, fixture.reports ?? 'reports');
  mkdirSync(dir, { recursive: true });
  const stem = join(dir, `${report.site}-${report.date}-find-eval`);
  writeFileSync(`${stem}.json`, `${JSON.stringify(report, null, 2)}\n`);
  writeFileSync(`${stem}.md`, renderFindEval(report));
  return { report, paths: [`${stem}.json`, `${stem}.md`] };
}
