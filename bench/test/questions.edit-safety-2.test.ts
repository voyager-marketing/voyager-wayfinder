import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { grade } from '../src/grade.ts';
import { EDIT_SAFETY_2_CATEGORY_COUNTS, expectedFor, loadQuestions, type Question } from '../src/questions.ts';
import { BENCH_DIR, loadConfig, loadContext, runBench } from '../src/run.ts';
import { resolveRef, type Json } from '../src/truth.ts';
import { fakeClient, text } from './helpers.ts';

// Frozen before any lock or find fix tuned on the first edit-safety set. A question edit changes its
// hash, so it fails here even when the header is updated with it.
const EDIT_SAFETY_2_SHA256 = '79b1b58aef25744644e6dfc104550afb654338bd89897963d14f1a5aec73bcb7';

const truth = JSON.parse(readFileSync(join(BENCH_DIR, '..', 'fixtures', 'truth.json'), 'utf8')) as Json;
const file = loadQuestions(join(BENCH_DIR, 'questions', 'fixture-edit-safety-2.yaml'));
const v1 = loadQuestions(join(BENCH_DIR, 'questions', 'fixture-edit-safety.yaml'));
const blockSet = loadQuestions(join(BENCH_DIR, 'questions', 'fixture.yaml'));
const noJudge = { ...fakeClient(text('unused')), model: 'judge', params: {} };
const codeGraded = file.questions.filter((q) => q.answer.type !== 'judge');
const byId = (id: string) => file.questions.find((q) => q.id === id) as Question;

/** A reply that gives the expected values the way the system prompt asks for them. */
function replyFor(q: Question, expected = expectedFor(q, truth) as string[]): string {
  return q.answer.type === 'contains_all' ? `It is ${expected.join(' and ')}.\nANSWER: ${expected.join(', ')}` : `ANSWER: ${expected.join(', ')}`;
}

/** Every run of eight words in a prompt, lowercased, with punctuation dropped. */
function eightWordRuns(prompt: string): Set<string> {
  const words = prompt.toLowerCase().split(/[^a-z0-9]+/).filter(Boolean);
  const runs = new Set<string>();
  for (let i = 0; i + 8 <= words.length; i++) runs.add(words.slice(i, i + 8).join(' '));
  return runs;
}

// An answer that names an edit screen or a block path is an edit location, and carries what the person asking
// would have to hand for the find route.
const LOCATION = /\.(?:edit_path|edit_paths|paths_now)$/;
const isLocation = (q: Question) => q.answer.refs.some((r) => LOCATION.test(r));

describe('edit-safety-2 question set', () => {
  it('has 30 questions, 6 in each of its five categories, with ids no other block fixture set uses', () => {
    expect(file.questions).toHaveLength(30);
    const counts: Record<string, number> = {};
    for (const q of file.questions) counts[q.category] = (counts[q.category] ?? 0) + 1;
    expect(counts).toEqual(EDIT_SAFETY_2_CATEGORY_COUNTS);
    const ids = file.questions.map((q) => q.id);
    expect(new Set(ids).size).toBe(30);
    const taken = [...blockSet.questions, ...v1.questions].map((q) => q.id);
    expect(ids.filter((id) => taken.includes(id))).toEqual([]);
  });

  it('resolves every expected value to a key in the block fixture truth.json', () => {
    for (const q of file.questions) {
      expect(() => expectedFor(q, truth), q.id).not.toThrow();
      if (q.answer.type === 'exact') expect(expectedFor(q, truth), q.id).toHaveLength(1);
      if (q.answer.type === 'judge') expect(q.answer.rubric, q.id).toBeTruthy();
    }
  });

  it('is frozen: the header carries the sha256 of the questions, and it is the pinned one', () => {
    expect(file.site).toBe('fixture');
    expect(file.questions_sha256).toBe(file.computed_sha256);
    expect(file.computed_sha256).toBe(EDIT_SAFETY_2_SHA256);
  });

  it('is held out: no prompt repeats an edit-safety v1 prompt or an eight-word run of one, and no question reads a v1 reference', () => {
    const v1Runs = new Set(v1.questions.flatMap((q) => [...eightWordRuns(q.prompt)]));
    // The check can fail: a v1 prompt shares its own runs.
    expect([...eightWordRuns(v1.questions[0].prompt)].some((r) => v1Runs.has(r))).toBe(true);
    const v1Refs = new Set(v1.questions.flatMap((q) => q.answer.refs));
    for (const q of file.questions) {
      expect(v1.questions.map((p) => p.prompt), q.id).not.toContain(q.prompt);
      expect([...eightWordRuns(q.prompt)].filter((r) => v1Runs.has(r)), q.id).toEqual([]);
      expect(q.answer.refs.filter((r) => v1Refs.has(r)), q.id).toEqual([]);
      expect(q.answer.refs.some((r) => r.startsWith('edit_safety_2.')), q.id).toBe(true);
    }
  });

  it('grades a reply built from the expected values as correct, for every question graded in code', async () => {
    expect(codeGraded).toHaveLength(29);
    for (const q of codeGraded) {
      const verdict = await grade(q, replyFor(q), truth, noJudge);
      expect(verdict.correct, `${q.id}: ${verdict.reason}`).toBe(true);
    }
  });

  it('grades an empty reply as wrong, for every question graded in code', async () => {
    for (const q of codeGraded) {
      for (const reply of ['', 'ANSWER: ']) {
        const verdict = await grade(q, reply, truth, noJudge);
        expect(verdict.correct, `${q.id} with ${JSON.stringify(reply)}`).toBe(false);
      }
    }
  });

  it('grades a reply missing one expected value as wrong, for every list answer', async () => {
    const lists = codeGraded.filter((q) => (expectedFor(q, truth) as string[]).length > 1);
    expect(lists.map((q) => q.id)).toEqual(['lk-23', 'mh-21', 'mh-22', 'mh-26', 'sl-22']);
    for (const q of lists) {
      const expected = expectedFor(q, truth) as string[];
      for (let i = 0; i < expected.length; i++) {
        const reply = replyFor(q, expected.filter((_, j) => j !== i));
        expect((await grade(q, reply, truth, noJudge)).correct, `${q.id} without ${expected[i]}`).toBe(false);
      }
    }
  });

  it('grades the other answer as wrong, alone or beside the right one, where the answer is one of a few values', async () => {
    const wrong: Record<string, string> = {
      'lk-21': 'yes', 'lk-22': 'no', 'lk-24': 'no', 'lk-25': 'yes', 'lk-26': 'no',
      'cc-21': 'yes', 'cc-22': 'manage_options', 'cc-23': 'yes', 'cc-24': 'no', 'cc-25': 'no',
      'mh-23': 'yes', 'mh-25': '1', 'sl-23': '/pricing/', 'sl-25': '1', 'sl-26': 'Rate card',
      'se-21': 'unchanged', 'se-22': 'changed', 'se-23': 'moved', 'se-24': 'unchanged', 'se-25': '4',
    };
    for (const [id, answer] of Object.entries(wrong)) {
      const [right] = expectedFor(byId(id), truth) as string[];
      expect(right, id).not.toBe(answer);
      for (const reply of [`ANSWER: ${answer}`, `ANSWER: ${right} or ${answer}`]) {
        expect((await grade(byId(id), reply, truth, noJudge)).correct, `${id}: ${reply}`).toBe(false);
      }
    }
    // se-26's answer is a dotted path, so the any-other-number rule does not apply, and its old path 1.1 is no
    // anchor's current path. A bare 1.1 fails; "1.1.0 or 1.1" passes the exact grader, which only refuses rivals.
    expect((await grade(byId('se-26'), 'ANSWER: 1.1', truth, noJudge)).correct).toBe(false);
  });

  it('gives each stale_edit question the anchor truth.json records: its post, path, fingerprint, text and page', () => {
    const staleEdit = file.questions.filter((q) => q.category === 'stale_edit');
    expect(staleEdit).toHaveLength(6);
    for (const q of staleEdit) {
      const key = /^edit_safety_2\.anchors\[key=([^\]]+)\]/.exec(q.answer.refs[0])?.[1];
      expect(key, q.id).toBeTruthy();
      const anchor = resolveRef(truth, `edit_safety_2.anchors[key=${key}]`) as Record<string, Json>;
      expect(q.prompt, q.id).toContain(JSON.stringify({ entity: anchor.entity, post: anchor.post, path: anchor.path, fp: anchor.fp }));
      expect(q.prompt, q.id).toContain(String(anchor.text));
      expect(q.prompt, q.id).toContain(String(anchor.page_path));
    }
  });

  it('gives find inputs to exactly the questions whose answer is an edit screen or a block path, and each is true of the site', () => {
    const located = file.questions.filter(isLocation);
    expect(located.map((q) => q.id)).toEqual(['cc-26', 'mh-21', 'mh-24', 'mh-26', 'sl-21', 'sl-24', 'se-25', 'se-26']);
    for (const q of file.questions) expect(q.find !== undefined, q.id).toBe(isLocation(q));

    const sitePaths = resolveRef(truth, 'pages[*].path') as string[];
    for (const q of located) {
      const find = q.find as NonNullable<Question['find']>;
      expect(sitePaths, q.id).toContain(find.url);
      // The page the person saw it on is one the asked-about item shows on.
      const ref = q.answer.refs.find((r) => /^(?:edit_safety_2\.(?:texts|classes|anchors)|templates)\[/.test(r)) as string;
      const subject = ref.slice(0, ref.indexOf(']') + 1);
      const pages = subject.startsWith('edit_safety_2.texts') ? resolveRef(truth, `${subject}.shown_at[*].path`)
        : subject.startsWith('edit_safety_2.classes') ? resolveRef(truth, `${subject}.shown_on`)
          : subject.startsWith('edit_safety_2.anchors') ? [resolveRef(truth, `${subject}.page_path`)]
            : resolveRef(truth, `${subject}.used_by_paths`);
      expect(pages, q.id).toContain(find.url);
      if (find.text !== undefined) {
        const shows = resolveRef(truth, `edit_safety_2.pages[path=${find.url}].shows`) as string[];
        expect(shows.some((s) => s.includes(find.text as string)), `${q.id}: "${find.text}" on ${find.url}`).toBe(true);
      }
      if (find.class !== undefined) expect(resolveRef(truth, `edit_safety_2.classes[class=${find.class}].shown_on`), q.id).toContain(find.url);
      if (subject.startsWith('edit_safety_2.anchors')) expect(find.text, q.id).toBe(resolveRef(truth, `${subject}.text`));
    }
  });

  it('asks about the rows the seed added for it, on the block fixture', () => {
    expect(resolveRef(truth, 'site.title')).toBe('Riverbend Plumbing');
    expect(resolveRef(truth, 'edit_safety_2.client_role')).toBe('editor');
    expect(resolveRef(truth, 'edit_safety_2.pages[*].post')).toEqual([90082, 90081, 90080]);
    expect(resolveRef(truth, 'patterns[id=pat:same-day-service].edit_path')).toBe('post.php?post=90071&action=edit');
    // Every answer a stale_edit status question can name is some anchor's, so each one is a rival of the others.
    expect(new Set(resolveRef(truth, 'edit_safety_2.anchors[*].status') as string[])).toEqual(new Set(['changed', 'gone', 'moved', 'unchanged']));
    // Both answers to a yes or no lock question are some block's, for the same reason.
    for (const field of ['can_remove', 'can_move']) {
      expect(new Set(resolveRef(truth, `edit_safety_2.blocks[*].${field}`) as string[]), field).toEqual(new Set(['no', 'yes']));
    }
  });
});

describe('the edit-safety-2 set in config.json', () => {
  it('runs on the block fixture seed and truth, under its own report name', () => {
    const { fixture, qs, truth: loaded } = loadContext(undefined, 'edit-safety-2');
    expect(fixture).toMatchObject({
      name: 'block', question_set: 'edit-safety-2', site: 'fixture-edit-safety-2', questions: 'questions/fixture-edit-safety-2.yaml',
      truth: '../fixtures/truth.json', seed: 'wp-content/plugins/voyager-wayfinder/fixtures/seed.php', recordings: undefined,
    });
    expect(qs.questions_sha256).toBe(EDIT_SAFETY_2_SHA256);
    expect(loaded).toEqual(truth);
  });

  it('is a set of the block fixture only', () => {
    expect(() => loadConfig('classic', 'edit-safety-2')).toThrow(/classic fixture has no question set "edit-safety-2"/);
  });

  it('does not replay the block recordings against the edit-safety-2 questions', async () => {
    await expect(runBench({ questionSet: 'edit-safety-2', dryRun: true })).rejects.toThrow(/no recordings for the edit-safety-2 question set/);
  });
});
