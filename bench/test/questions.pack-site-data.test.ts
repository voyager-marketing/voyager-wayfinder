import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { grade } from '../src/grade.ts';
import { expectedFor, loadQuestions } from '../src/questions.ts';
import { BENCH_DIR } from '../src/run.ts';
import type { Json } from '../src/truth.ts';
import { fakeClient, text } from './helpers.ts';

// One edit-location question on a site that runs only the voyager-open-blocks pack. A question edit
// changes the hash, so it fails here even with the header updated.
const PACK_SITE_DATA_SHA256 = '3255359e9fb0d0b25f7911feac889179bfa4f65cf4b49c8c2b4b603512a9c5d3';

const truth = JSON.parse(readFileSync(join(BENCH_DIR, '..', 'fixtures', 'pack', 'truth.json'), 'utf8')) as Json;
const file = loadQuestions(join(BENCH_DIR, 'questions', 'pack-site-data.yaml'));
const noJudge = { ...fakeClient(text('unused')), model: 'judge', params: {} };
const question = file.questions[0];

describe('pack-site-data question', () => {
  it('is one edit-location question that carries the find inputs', () => {
    expect(file.questions).toHaveLength(1);
    expect(question.category).toBe('edit_location');
    expect(question.find).toEqual({ url: '/', text: '(540) 555-0142' });
  });

  it('is pinned: the header carries the sha256 of the questions, and it is the pinned one', () => {
    expect(file.site).toBe('fixture-pack');
    expect(file.questions_sha256).toBe(file.computed_sha256);
    expect(file.computed_sha256).toBe(PACK_SITE_DATA_SHA256);
  });

  it("expects the pack's own Site Data screen, from the pack's source", () => {
    expect(expectedFor(question, truth)).toEqual(['options-general.php?page=voyager-site-data']);
  });

  it("passes the pack's screen and fails voyager-blocks's, so the answer cannot be the other plugin's", async () => {
    const right = await grade(question, 'ANSWER: options-general.php?page=voyager-site-data', truth, noJudge);
    const blocks = await grade(question, 'ANSWER: options-general.php?page=voyager-blocks&tab=site-data', truth, noJudge);
    const none = await grade(question, 'ANSWER: ', truth, noJudge);

    expect(right.correct, right.reason).toBe(true);
    expect(blocks.correct, blocks.reason).toBe(false);
    expect(none.correct, none.reason).toBe(false);
  });
});
