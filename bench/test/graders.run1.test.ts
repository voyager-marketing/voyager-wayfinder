import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { grade } from '../src/grade.ts';
import { loadQuestions, type Question } from '../src/questions.ts';
import { CorrectionsFile, ReportSchema, type Result } from '../src/report.ts';
import { BENCH_DIR } from '../src/run.ts';
import { resolveRef, type Json } from '../src/truth.ts';
import { fakeClient, text } from './helpers.ts';

// Run 1 replayed offline. The report keeps each run's last ANSWER line, which is all the exact and set
// graders read, and the corrections file is the hand re-grade of all 88 misses. The graders must now
// agree with that re-grade on every replayable row, without touching the questions or the answer key.
const read = (...path: string[]): unknown => JSON.parse(readFileSync(join(BENCH_DIR, ...path), 'utf8'));
const report = ReportSchema.parse(read('reports', 'fixture-2026-10-02.json'));
const corrections = CorrectionsFile.parse(read('reports', 'fixture-2026-10-02-corrections.json'));
// Run 1's site ran two plugins. The MCP Adapter joined the fixture after run 2, for the adapter
// condition, so si-02 and si-04 are graded against the plugins run 1's site actually ran.
const RUN1_PLUGINS = ['advanced-custom-fields', 'voyager-wayfinder'];
const current = read('..', 'fixtures', 'truth.json') as { [key: string]: Json; plugins: { [key: string]: Json; slug: string }[] };
const truth: Json = { ...current, plugins: current.plugins.filter((p) => RUN1_PLUGINS.includes(p.slug)) };
const file = loadQuestions(join(BENCH_DIR, 'questions', 'fixture.yaml'));

const questions = new Map(file.questions.map((q) => [q.id, q]));
const question = (id: string): Question => questions.get(id) ?? (() => { throw new Error(`no question ${id}`); })();
const key = (r: Pick<Result, 'question' | 'condition' | 'model' | 'rep'>) => `${r.question} ${r.condition} ${r.model} rep ${r.rep}`;
const regraded = new Set(corrections.corrections.map(key));
/** Misses the hand re-grade kept wrong that a later, approved grader rule accepts. */
const acceptedSince = new Set(['el-06 raw claude-sonnet-5 rep 3']);
const noJudge = { ...fakeClient(text('unused')), model: 'judge', params: {} };
const regrade = (r: Result) => grade(question(r.question), `ANSWER: ${r.answer}`, truth, noJudge);

// A judge verdict needs a live call, a run that hit the turn cap never reached a grader, and a run
// with no ANSWER line was graded on its whole reply, which the report does not keep.
const replayable = report.results.filter((r) => question(r.question).answer.type !== 'judge' && !r.hit_turn_cap && r.answer !== '');

describe('graders replayed on run 1', () => {
  it('replays every exact, set and contains_all answer the report can reproduce', () => {
    const graded = report.results.filter((r) => question(r.question).answer.type !== 'judge');
    expect(report.results).toHaveLength(400);
    expect(graded).toHaveLength(300);
    expect(graded.filter((r) => r.hit_turn_cap)).toHaveLength(3);
    expect(replayable).toHaveLength(297);
  });

  it('passes the 13 non-judge rows of the hand re-grade', async () => {
    expect(corrections.corrections.filter((c) => question(c.question).answer.type === 'judge').map(key)).toEqual(['lr-08 raw claude-sonnet-5 rep 1']);
    const fixed = replayable.filter((r) => regraded.has(key(r)));
    expect(fixed).toHaveLength(13);
    for (const r of fixed) expect((await regrade(r)).correct, `${key(r)}: ${r.answer}`).toBe(true);
  });

  it('still passes every answer the old graders passed', async () => {
    const passed = replayable.filter((r) => r.correct);
    expect(passed).toHaveLength(240);
    for (const r of passed) expect((await regrade(r)).correct, `${key(r)}: ${r.answer}`).toBe(true);
  });

  it('still fails every miss the hand re-grade kept wrong', async () => {
    const wrong = replayable.filter((r) => !r.correct && !regraded.has(key(r)) && !acceptedSince.has(key(r)));
    expect(wrong).toHaveLength(43);
    for (const r of wrong) expect((await regrade(r)).correct, `${key(r)}: ${r.answer}`).toBe(false);
  });

  // The judge accepts a menu by its Site Editor menu path beside its title. This answer gives both, and
  // its URL is core's Navigation Menus list, whose Primary row opens site-editor.php?p=/wp_navigation/90001.
  it('passes the one kept-wrong miss that names the menu by its menu path and title', async () => {
    const moved = replayable.filter((r) => acceptedSince.has(key(r)));
    expect(moved.map(key)).toEqual([...acceptedSince]);
    for (const r of moved) expect(await regrade(r), `${key(r)}: ${r.answer}`).toMatchObject({ correct: true, reason: 'contains all, Appearance > Editor > Navigation (Primary) by its menu label' });
  });

  it('tells the judge that extra correct detail never makes an answer wrong', async () => {
    const { client, calls } = fakeClient(text('{"correct": true, "reason": "names all three"}'));
    const lr08 = report.results.find((r) => key(r) === 'lr-08 raw claude-sonnet-5 rep 1');
    await grade(question('lr-08'), `ANSWER: ${lr08?.answer}`, truth, { client, model: 'judge', params: {} });
    // The run 4 hand re-grade widened the sentence from run 5: detail the judge cannot check counts too.
    expect(calls[0].system).toContain('Extra detail never makes an answer wrong, even when you cannot check it, and neither does going beyond the question.');
  });

  it('grades plugin answers against the plugins run 1 ran, not the adapter added since', () => {
    expect(current.plugins.map((p) => p.slug)).toEqual(['advanced-custom-fields', 'mcp-adapter', 'voyager-wayfinder']);
    expect(resolveRef(truth, 'plugins[*].slug')).toEqual(RUN1_PLUGINS);
  });

  it('grades the same question set run 1 used', () => {
    expect(file.computed_sha256).toBe(report.question_set.sha256);
    expect(file.questions_sha256).toBe('e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b');
  });
});
