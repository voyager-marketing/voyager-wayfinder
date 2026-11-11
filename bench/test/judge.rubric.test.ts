import { describe, expect, it } from 'vitest';
import { grade, RUBRIC } from '../src/grade.ts';
import type { Question } from '../src/questions.ts';
import type { Json } from '../src/truth.ts';
import { fakeClient, text } from './helpers.ts';

// In run 5 the judge failed five real-site df-06 answers that its rubric accepts: they give the Site Title under
// Settings > General and name no Rank Math setting as the place to change it, and it failed them for leaving out
// the theme filter, which EXPECTED holds and the rubric only mentions to explain the mechanism. From run 6 the
// judge lists what WHAT COUNTS AS CORRECT requires and what it calls wrong, marks each against the answer, and code
// decides: correct when every requirement is met and nothing ruled out is said. Those 36 answers judged again with
// this prompt are private bench data, and their own test checks the record was made with today's prompt.
const truth: Json = { site: { title: 'Riverbend Plumbing', title_edit_path: 'options-general.php' } };
const question: Question = {
  id: 'xx-01',
  category: 'data_flow',
  prompt: 'Where does the site name come from?',
  answer: { type: 'judge', refs: ['site.title'], rubric: 'Correct if it says the Site Title under Settings > General, which the theme prints. Naming a plugin setting as the place to change it is wrong.' },
};
const judged = (reply: unknown) => grade(question, 'ANSWER: the Site Title, at options-general.php', truth, { ...fakeClient(text(JSON.stringify(reply))), model: 'judge', params: {} });
const SITE_TITLE = { item: 'the Site Title under Settings > General', met: true };
const PLUGIN = { item: 'naming a plugin setting as the place to change it', said: false };

describe('the judge keeps to its rubric', () => {
  it('passes an answer that meets every requirement and says nothing ruled out, whatever else the judge remarks', async () => {
    const verdict = await judged({ requires: [SITE_TITLE], rules_out: [PLUGIN], reason: 'It does not name the theme filter.' });
    expect(verdict).toMatchObject({ correct: true, reason: 'It does not name the theme filter.' });
  });

  it('fails an unmet requirement or a ruled-out statement, and says which', async () => {
    const unmet = await judged({ requires: [{ ...SITE_TITLE, met: false }], rules_out: [PLUGIN], reason: 'Wrong page.' });
    expect(unmet).toEqual(expect.objectContaining({ correct: false, reason: 'Wrong page. (not met: the Site Title under Settings > General)' }));
    const said = await judged({ requires: [SITE_TITLE], rules_out: [{ ...PLUGIN, said: true }], reason: 'Offers the plugin.' });
    expect(said).toEqual(expect.objectContaining({ correct: false, reason: 'Offers the plugin. (said: naming a plugin setting as the place to change it)' }));
  });

  it('takes the checklist over a verdict the judge adds beside it', async () => {
    expect((await judged({ correct: false, requires: [SITE_TITLE], rules_out: [], reason: 'x' })).correct).toBe(true);
    expect((await judged({ correct: true, requires: [SITE_TITLE, { item: 'the theme filter', met: false }], rules_out: [], reason: 'x' })).correct).toBe(false);
  });

  it('counts a checklist with no requirement as malformed, and so wrong', async () => {
    const verdict = await judged({ requires: [], rules_out: [], reason: 'Nothing to check.' });
    expect(verdict.correct).toBe(false);
    expect(verdict.reason).toMatch(/not the expected JSON/);
  });

  it('tells the judge to take requirements from the rubric alone, and to add none', () => {
    expect(RUBRIC).toContain('WHAT COUNTS AS CORRECT is the test.');
    expect(RUBRIC).toContain('Add nothing it does not state.');
    expect(RUBRIC).toContain('A clause that only explains how or why (which ..., because ..., so ...) describes the site; the answer does not have to say it.');
    expect(RUBRIC).toContain('Use it to check what the answer says, not as more requirements.');
    // A first draft without this passed three df-06 answers that offer Rank Math's screen "to override it".
    expect(RUBRIC).toContain('offering it beside a right answer still says it.');
    expect(RUBRIC).toContain('"requires": [{"item": "<a requirement>", "met": true or false}]');
  });
});
