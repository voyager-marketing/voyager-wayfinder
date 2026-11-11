import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  expectedLocations, findInputsPath, FindEvalReport, isLocation, loadFindInputs, normalizeEdit, planFindEval, rankedEntries,
  renderFindEval, runFindEval, score, type FindInputsFile, type FindResult, type Location, type Ranked,
} from '../src/findEval.ts';
import { loadQuestions, type Question } from '../src/questions.ts';
import { BENCH_DIR, loadContext } from '../src/run.ts';
import { resolveRef, type Json } from '../src/truth.ts';

/**
 * The find eval: find inputs in, the answer key's location checked at hit 1 and at every hit, no model.
 * Offline throughout: the end-to-end case replays find answers recorded on the block fixture.
 */

const read = (...parts: string[]) => readFileSync(join(BENCH_DIR, ...parts), 'utf8');
const blockTruth = JSON.parse(read('..', 'fixtures', 'truth.json')) as Json;
const classicTruth = JSON.parse(read('..', 'fixtures', 'classic', 'truth.json')) as Json;

const question = (over: Partial<Question> & Pick<Question, 'id'>): Question => ({
  category: 'edit_location', prompt: 'Where do I change this thing?', answer: { type: 'contains_all', refs: ['patterns[id=pat:cta-banner].edit_path'] }, ...over,
});
const entry = (rank: number, over: Partial<Ranked> = {}): Ranked => ({ rank, id: `item:${rank}`, edit: null, post: null, block_path: null, ...over });
const result = (over: Partial<FindResult> = {}): FindResult =>
  ({ route: 'route:single-page', matched: true, ambiguous: false, message: null, chain: [], hits: [], verify: { changes_since: null }, ...over }) as FindResult;
const hit = (rank: number, id: string, anchor: { entity: string; post: number | null; path: string } | null = null) =>
  ({ rank, id, block_path: anchor?.path ?? null, snippet: '', on_route: true, anchor });

describe('expected locations', () => {
  it('reads admin screens from edit_path and edit_paths references, normalized', () => {
    expect(expectedLocations(['patterns[id=pat:cta-banner].edit_path'], blockTruth)).toEqual([{ edit: 'post.php?post=90003&action=edit' }]);
    expect(expectedLocations(['edit_safety_2.texts[key=licensed-in-virginia].edit_paths'], blockTruth)).toEqual([
      { edit: 'post.php?post=90010&action=edit' }, { edit: 'post.php?post=90062&action=edit' }, { edit: 'post.php?post=90081&action=edit' },
    ]);
  });

  it('reads a paths_now reference as blocks in the post its anchor record names', () => {
    expect(expectedLocations(['edit_safety_2.anchors[key=price-promise].paths_now'], blockTruth)).toEqual([{ post: 90080, path: '3' }]);
    expect(expectedLocations(['edit_safety.anchors[key=after-hours].paths_now'], blockTruth)).toEqual([{ post: 90060, path: '2' }, { post: 90060, path: '4' }]);
  });

  it('names one screen once, however many references reach it', () => {
    const refs = ['binding_sources[id=vwf-fixture/business-info].edit_path', 'binding_sources[id=vwf-fixture/business-info].edit_path'];
    expect(expectedLocations(refs, blockTruth)).toHaveLength(1);
  });

  it('treats the forms of one admin screen as the same screen', () => {
    for (const form of ['/wp-admin/post.php?post=7&amp;action=edit', 'http://localhost:8888/wp-admin/post.php?post=7&action=edit', ' post.php?post=7&action=edit ']) {
      expect(normalizeEdit(form), form).toBe('post.php?post=7&action=edit');
    }
    expect(normalizeEdit('site-editor.php?p=%2Fwp_template%2Fvwf-fixture%2F%2Fpage&canvas=edit')).toBe('site-editor.php?p=/wp_template/vwf-fixture//page&canvas=edit');
    expect(normalizeEdit('post.php?post=70&action=edit')).not.toBe(normalizeEdit('post.php?post=7&action=edit'));
  });
});

describe('scoring', () => {
  const screen: Location = { edit: 'post.php?post=1&action=edit' };
  const other: Location = { edit: 'post.php?post=2&action=edit' };

  it('top-1 is hit 1 at an expected location; any hit is every expected location at some hit', () => {
    expect(score([screen], [entry(1, { edit: screen.edit })])).toEqual({ top1: true, any_hit: true, found: 1, first_match: 1 });
    expect(score([screen], [entry(1, { edit: other.edit }), entry(2, { edit: screen.edit })])).toEqual({ top1: false, any_hit: true, found: 1, first_match: 2 });
    expect(score([screen], [entry(1, { edit: other.edit })])).toEqual({ top1: false, any_hit: false, found: 0, first_match: null });
  });

  it('needs every home of a multi-home answer for any hit, and one of them at hit 1 for top-1', () => {
    expect(score([screen, other], [entry(1, { edit: other.edit })])).toEqual({ top1: true, any_hit: false, found: 1, first_match: 1 });
    expect(score([screen, other], [entry(1, { edit: other.edit }), entry(2, { edit: screen.edit })])).toMatchObject({ top1: true, any_hit: true, found: 2 });
  });

  it('matches a block by its post and path, not by the screen it is edited on', () => {
    const block: Location = { post: 90080, path: '3' };
    expect(score([block], [entry(1, { post: 90080, block_path: '3', edit: 'post.php?post=90080&action=edit' })]).top1).toBe(true);
    expect(score([block], [entry(1, { post: 90080, block_path: '4', edit: 'post.php?post=90080&action=edit' })]).any_hit).toBe(false);
    expect(score([block], [entry(1, { post: 90081, block_path: '3' })]).any_hit).toBe(false);
  });

  it('scores no hits as a miss on both counts', () => {
    expect(score([screen], [])).toEqual({ top1: false, any_hit: false, found: 0, first_match: null });
  });
});

describe('what find ranked, and where each entry is edited', () => {
  const never = async () => {
    throw new Error('not needed for this hit');
  };

  it('ranks the chain when only a URL was given, since find then returns no hits', async () => {
    const r = result({ matched: null, chain: [{ id: 'pt:page', label: 'Page', edit: 'post.php?post=5&action=edit' }, { id: 'tpl:page', label: 'Page', edit: null }] });
    expect(await rankedEntries(r, never, never)).toEqual({
      from: 'chain', ranked: [entry(1, { id: 'pt:page', edit: 'post.php?post=5&action=edit' }), entry(2, { id: 'tpl:page' })],
    });
  });

  it('ranks nothing when a text search matched nothing, though the chain is still there', async () => {
    const r = result({ matched: false, chain: [{ id: 'pt:page', label: 'Page', edit: 'post.php?post=5&action=edit' }] });
    expect(await rankedEntries(r, never, never)).toEqual({ from: 'hits', ranked: [] });
  });

  it("takes a hit's screen from the result's chain first, then the URL's own chain, then the anchor's post, then the manifest", async () => {
    const asked: string[] = [];
    const r = result({
      chain: [{ id: 'bind:src.phone', label: 'Phone', edit: 'options-general.php?page=src' }],
      hits: [
        hit(1, 'bind:src.phone', { entity: 'part:footer', post: null, path: '0.1' }),
        hit(2, 'nav:primary', { entity: 'nav:primary', post: null, path: '2' }),
        hit(3, 'page:about', { entity: 'pt:page', post: 90010, path: '1' }),
        hit(4, 'pat:other', { entity: 'pat:other', post: null, path: '0' }),
        hit(5, 'bind:core/post-meta.price', { entity: 'pt:service', post: 90020, path: '1' }),
      ],
    });
    const routeChain = async () => [{ id: 'nav:primary', label: 'Primary', edit: '/wp-admin/site-editor.php?p=/wp_navigation/1' }];
    const itemEdit = async (id: string) => {
      asked.push(id);
      return id === 'pat:other' ? 'post.php?post=9&action=edit' : null;
    };
    const { from, ranked } = await rankedEntries(r, routeChain, itemEdit);
    expect(from).toBe('hits');
    expect(ranked.map((e) => e.edit)).toEqual([
      'options-general.php?page=src', 'site-editor.php?p=/wp_navigation/1', 'post.php?post=90010&action=edit', 'post.php?post=9&action=edit', null,
    ]);
    // A binding's screen is its source's, never the post its block sits in, so it goes to the manifest.
    expect(asked).toEqual(['pat:other', 'bind:core/post-meta.price']);
    expect(ranked[2]).toMatchObject({ post: 90010, block_path: '1' });
  });
});

describe('planning', () => {
  const qs = [
    question({ id: 'aa-01', find: { url: '/' } }),
    question({ id: 'aa-02' }),
    question({ id: 'aa-03' }),
    question({ id: 'aa-04', answer: { type: 'exact', refs: ['site.title'] } }),
    question({ id: 'aa-05', answer: { type: 'exact', refs: ['site.title'] }, find: { url: '/' } }),
    question({ id: 'aa-06' }),
  ];
  const inputs = (list: FindInputsFile['inputs'], sha = 'a'.repeat(64)): FindInputsFile => ({ questions: 'questions/x.yaml', questions_sha256: sha, inputs: list });

  it('asks each question with inputs and a location, and says why it skips the rest', () => {
    const { planned, skipped } = planFindEval(qs, 'a'.repeat(64), blockTruth, inputs([
      { id: 'aa-02', find: { url: '/', text: 'Need a plumber today?' } },
      { id: 'aa-03', find: { url: '/services/drain-cleaning/' }, location: ['services[slug=drain-cleaning].edit_path'] },
      { id: 'aa-06', none: 'no page shows it' },
    ]));
    expect(planned.map((p) => [p.question.id, p.find, p.expected])).toEqual([
      ['aa-01', { url: '/' }, [{ edit: 'post.php?post=90003&action=edit' }]],
      ['aa-02', { url: '/', text: 'Need a plumber today?' }, [{ edit: 'post.php?post=90003&action=edit' }]],
      ['aa-03', { url: '/services/drain-cleaning/' }, [{ edit: 'post.php?post=90020&action=edit' }]],
    ]);
    expect(skipped).toEqual([
      { question: 'aa-05', category: 'edit_location', reason: 'its answer names no edit screen or block path' },
      { question: 'aa-06', category: 'edit_location', reason: 'no page shows it' },
    ]);
    // aa-04 is not an edit-location question, so it is neither asked nor listed.
    expect(planFindEval(qs, 'a'.repeat(64), blockTruth).skipped.map((s) => [s.question, s.reason])).toEqual([
      ['aa-02', 'no find inputs'], ['aa-03', 'no find inputs'], ['aa-05', 'its answer names no edit screen or block path'], ['aa-06', 'no find inputs'],
    ]);
  });

  it('stops on inputs written for another version of the set, an unknown question, a repeat, or a question with its own', () => {
    const plan = (file: FindInputsFile) => () => planFindEval(qs, 'a'.repeat(64), blockTruth, file);
    expect(plan(inputs([], 'b'.repeat(64)))).toThrow(/written against questions_sha256 b+/);
    expect(plan(inputs([{ id: 'zz-99', find: { url: '/' } }]))).toThrow(/zz-99, which is not in/);
    expect(plan(inputs([{ id: 'aa-02', find: { url: '/' } }, { id: 'aa-02', none: 'x' }]))).toThrow(/aa-02 twice/);
    expect(plan(inputs([{ id: 'aa-01', find: { url: '/' } }]))).toThrow(/aa-01 carries its own/);
  });
});

/** The frozen sets' inputs files, the truth each is checked against, and what that fixture's sources print. */
const SETS = [
  { name: undefined, truth: blockTruth, sources: ['fixtures/seed.php', 'fixtures/theme/vwf-fixture/templates/single-service.html'] },
  { name: 'edit-safety', truth: blockTruth, sources: ['fixtures/seed.php'] },
  { name: 'classic', fixture: 'classic', truth: classicTruth, sources: ['fixtures/classic/seed.php'] },
] as const;

describe('the frozen sets get find inputs outside their hashes', () => {
  for (const set of SETS) {
    const fixture = 'fixture' in set ? set.fixture : undefined;
    const questionSet = 'fixture' in set ? undefined : set.name;
    const { fixture: f, qs } = loadContext(fixture, questionSet);
    const file = findInputsPath(f.questions);
    const inputs = loadFindInputs(join(BENCH_DIR, file)) as FindInputsFile;
    const sources = set.sources.map((s) => read('..', s)).join('\n');

    describe(file, () => {
      it('exists, names its set, and was written against the frozen hash', () => {
        expect(inputs, file).toBeDefined();
        expect(inputs.questions).toBe(f.questions);
        expect(inputs.questions_sha256).toBe(qs.questions_sha256);
        expect(qs.computed_sha256).toBe(qs.questions_sha256);
      });

      it('covers exactly the questions whose answer is an edit screen or a block path, and plans every one', () => {
        const located = qs.questions.filter(isLocation).map((q) => q.id);
        expect(inputs.inputs.map((i) => i.id)).toEqual(located);
        const { planned, skipped } = planFindEval(qs.questions, qs.questions_sha256, set.truth, inputs);
        expect([...planned.map((p) => p.question.id), ...skipped.map((s) => s.question)].sort()).toEqual([...located].sort());
        for (const p of planned) expect(p.expected.length, p.question.id).toBeGreaterThan(0);
      });

      it('gives each input a page the fixture serves, and text and classes its sources print', () => {
        const paths = new Set([
          ...(resolveRef(set.truth, 'pages[*].path') as string[]),
          ...((resolveRef(set.truth, 'services[*].path') ?? []) as string[]),
          ...((resolveRef(set.truth, 'projects[*].path') ?? []) as string[]),
          ...((resolveRef(set.truth, 'post_types[*].archive_path') ?? []) as (string | null)[]).filter((p) => p !== null),
        ]);
        for (const i of inputs.inputs) {
          if (!('find' in i)) continue;
          expect(paths, `${i.id}: ${i.find.url}`).toContain(i.find.url);
          if (i.find.text !== undefined) expect(sources.includes(i.find.text) || JSON.stringify(set.truth).includes(i.find.text), `${i.id}: "${i.find.text}"`).toBe(true);
          if (i.find.class !== undefined) expect(sources, i.id).toContain(i.find.class);
        }
      });
    });
  }

  it('leaves edit-safety-2 alone: its questions carry their own find inputs, so it has no inputs file', () => {
    const { fixture } = loadContext(undefined, 'edit-safety-2');
    expect(loadFindInputs(join(BENCH_DIR, findInputsPath(fixture.questions)))).toBeUndefined();
    const qs = loadQuestions(join(BENCH_DIR, fixture.questions));
    expect(planFindEval(qs.questions, qs.questions_sha256, blockTruth).planned.map((p) => p.question.id)).toEqual(['cc-26', 'mh-21', 'mh-24', 'mh-26', 'sl-21', 'sl-24', 'se-25', 'se-26']);
  });
});

describe('a run, replayed from find answers recorded on the block fixture', () => {
  const recording = JSON.parse(read('test', 'find-eval-fixture-edit-safety-2.json')) as { responses: Record<string, { status: number; body: unknown }> };

  async function replay() {
    const requested: string[] = [];
    const fetchImpl = (async (url: URL, init?: RequestInit) => {
      const route = `${url.pathname}${url.search}`;
      requested.push(route);
      expect(url.origin).toBe('http://localhost:8888');
      expect(new Headers(init?.headers).get('authorization')).toBe(`Basic ${Buffer.from('admin:secret').toString('base64')}`);
      const recorded = recording.responses[route];
      if (!recorded) throw new Error(`not recorded: ${route}`);
      return new Response(typeof recorded.body === 'string' ? recorded.body : JSON.stringify(recorded.body), { status: recorded.status });
    }) as unknown as typeof fetch;
    const out = mkdtempSync(join(tmpdir(), 'find-eval-'));
    try {
      const { report, paths } = await runFindEval({ questionSet: 'edit-safety-2', siteUrl: 'http://localhost:8888', credentials: 'admin:secret', date: '2026-10-06', out, fetchImpl });
      const written = FindEvalReport.parse(JSON.parse(readFileSync(paths[0], 'utf8')));
      return { report, written, markdown: readFileSync(paths[1], 'utf8'), requested };
    } finally {
      rmSync(out, { recursive: true, force: true });
    }
  }

  it('asks the site each thing once, and gives the result recorded on it', async () => {
    const { report, written, requested } = await replay();
    expect(written).toEqual(report);
    // Eight questions, three URLs' own chains and one manifest item, each fetched once.
    expect(requested).toHaveLength(12);
    expect(new Set(requested).size).toBe(12);
    expect(report.totals).toEqual({ questions: 8, top1: 7, any_hit: 8, from_hits: { questions: 7, top1: 7, any_hit: 7 }, from_chain: { questions: 1, top1: 0, any_hit: 1 } });
    expect(report.questions.map((q) => [q.question, q.top1, q.any_hit, q.first_match])).toEqual([
      ['cc-26', false, true, 2], ['mh-21', true, true, 1], ['mh-24', true, true, 1], ['mh-26', true, true, 1],
      ['sl-21', true, true, 1], ['sl-24', true, true, 1], ['se-25', true, true, 1], ['se-26', true, true, 1],
    ]);
    expect(report.skipped).toEqual([]);
    expect(report.snapshot).toBeNull();
  });

  it('agrees with the committed block fixture report for this set', async () => {
    const { report } = await replay();
    const committed = FindEvalReport.parse(JSON.parse(read('reports', 'fixture-edit-safety-2-2026-10-06-find-eval.json')));
    const strip = ({ commit: _commit, ...rest }: FindEvalReport) => rest;
    expect(strip(report)).toEqual(strip(committed));
  });

  it('writes Markdown with the totals, every question and its hit 1', async () => {
    const { report, markdown } = await replay();
    expect(markdown).toBe(renderFindEval(report));
    expect(markdown).toContain('| All | 8 | 7 (87.5%) | 8 (100.0%) |');
    expect(markdown).toMatch(/\| mh-21 \| `\/guarantee\/`, text "licensed in Virginia" \| .* \| yes \| yes \(3 of 3\) \| 1 \| pt:page `3`/);
    expect(markdown).toMatch(/\| cc-26 \| `\/contact\/` \(chain\) \|/);
  });
});
