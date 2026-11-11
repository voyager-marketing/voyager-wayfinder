import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { grade } from '../src/grade.ts';
import { EDIT_SAFETY_CATEGORY_COUNTS, expectedFor, loadQuestions, type Question } from '../src/questions.ts';
import { BENCH_DIR, loadConfig, loadContext, runBench } from '../src/run.ts';
import { resolveRef, type Json } from '../src/truth.ts';
import { fakeClient, text } from './helpers.ts';

// Frozen before run 4. A question edit changes its hash, so it fails here even when the header is updated with it.
const EDIT_SAFETY_SHA256 = '69eb2c8401ba4ff31666e968dce3cf8ac31c3c5153c4920a70dbadb9fe372cc9';

const truth = JSON.parse(readFileSync(join(BENCH_DIR, '..', 'fixtures', 'truth.json'), 'utf8')) as Json;
const file = loadQuestions(join(BENCH_DIR, 'questions', 'fixture-edit-safety.yaml'));
const blockSet = loadQuestions(join(BENCH_DIR, 'questions', 'fixture.yaml'));
const noJudge = { ...fakeClient(text('unused')), model: 'judge', params: {} };
const codeGraded = file.questions.filter((q) => q.answer.type !== 'judge');

/** A reply that gives the expected values the way the system prompt asks for them. */
function replyFor(q: Question): string {
  const expected = expectedFor(q, truth) as string[];
  return q.answer.type === 'contains_all' ? `It is ${expected.join(' and ')}.\nANSWER: ${expected.join(', ')}` : `ANSWER: ${expected.join(', ')}`;
}

describe('edit-safety question set', () => {
  it('has 20 questions, 5 in each of its four categories, with ids no other block set question uses', () => {
    expect(file.questions).toHaveLength(20);
    const counts: Record<string, number> = {};
    for (const q of file.questions) counts[q.category] = (counts[q.category] ?? 0) + 1;
    expect(counts).toEqual(EDIT_SAFETY_CATEGORY_COUNTS);
    const ids = file.questions.map((q) => q.id);
    expect(new Set(ids).size).toBe(20);
    expect(ids.filter((id) => blockSet.questions.some((q) => q.id === id))).toEqual([]);
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
    expect(file.computed_sha256).toBe(EDIT_SAFETY_SHA256);
  });

  it('grades a reply built from the expected values as correct, for every question graded in code', async () => {
    expect(codeGraded).toHaveLength(19);
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

  it('grades the other answer as wrong where the answer is one of a few words', async () => {
    const wrong: Record<string, string> = {
      'lk-01': 'yes', 'lk-02': 'no', 'lk-04': 'no', 'mh-03': 'yes',
      'se-02': 'unchanged', 'se-03': 'changed', 'se-04': 'moved', 'se-01': '4', 'mh-04': '1',
    };
    for (const [id, answer] of Object.entries(wrong)) {
      const q = file.questions.find((question) => question.id === id) as Question;
      const [right] = expectedFor(q, truth) as string[];
      expect(right, id).not.toBe(answer);
      for (const reply of [`ANSWER: ${answer}`, `ANSWER: ${right} or ${answer}`]) {
        expect((await grade(q, reply, truth, noJudge)).correct, `${id}: ${reply}`).toBe(false);
      }
    }
  });

  it('gives each stale_edit question the anchor truth.json records: its post, path, fingerprint and text', () => {
    const staleEdit = file.questions.filter((q) => q.category === 'stale_edit');
    expect(staleEdit).toHaveLength(5);
    for (const q of staleEdit) {
      const key = /^edit_safety\.anchors\[key=([^\]]+)\]/.exec(q.answer.refs[0])?.[1];
      expect(key, q.id).toBeTruthy();
      const anchor = resolveRef(truth, `edit_safety.anchors[key=${key}]`) as Record<string, Json>;
      expect(q.prompt, q.id).toContain(JSON.stringify({ entity: anchor.entity, post: anchor.post, path: anchor.path, fp: anchor.fp }));
      expect(q.prompt, q.id).toContain(String(anchor.text));
      expect(q.prompt, q.id).toContain(String(anchor.page_path));
    }
  });

  it('asks about the rows the seed added for it, on the block fixture', () => {
    expect(resolveRef(truth, 'site.title')).toBe('Riverbend Plumbing');
    expect(resolveRef(truth, 'edit_safety.client_role')).toBe('editor');
    expect(resolveRef(truth, 'edit_safety.pages[*].post')).toEqual([90060, 90061, 90062]);
    expect(resolveRef(truth, 'patterns[id=pat:financing-offer].edit_path')).toBe('post.php?post=90070&action=edit');
    // Every status a stale_edit answer can name is some anchor's, so each one is a rival of the others.
    expect(new Set(resolveRef(truth, 'edit_safety.anchors[*].status') as string[])).toEqual(new Set(['changed', 'gone', 'moved', 'unchanged']));
  });
});

describe('the edit-safety set in config.json', () => {
  it('runs on the block fixture seed and truth, under its own report name', () => {
    const { fixture, qs, truth: loaded } = loadContext(undefined, 'edit-safety');
    expect(fixture).toMatchObject({
      name: 'block', question_set: 'edit-safety', site: 'fixture-edit-safety', questions: 'questions/fixture-edit-safety.yaml',
      truth: '../fixtures/truth.json', seed: 'wp-content/plugins/voyager-wayfinder/fixtures/seed.php', recordings: undefined,
    });
    expect(qs.questions_sha256).toBe(EDIT_SAFETY_SHA256);
    expect(loaded).toEqual(truth);
  });

  it('leaves the block fixture its own questions when no set is named', () => {
    const { fixture } = loadConfig('block');
    expect(fixture).toMatchObject({ questions: 'questions/fixture.yaml', site: 'fixture', recordings: 'test/recordings', question_set: undefined });
  });

  it('refuses a set the fixture does not have', () => {
    expect(() => loadConfig('classic', 'edit-safety')).toThrow(/classic fixture has no question set "edit-safety"/);
    expect(() => loadConfig('block', 'nope')).toThrow(/no question set "nope"/);
  });

  it('does not replay the block recordings against the edit-safety questions', async () => {
    await expect(runBench({ questionSet: 'edit-safety', dryRun: true })).rejects.toThrow(/no recordings for the edit-safety question set/);
  });
});
