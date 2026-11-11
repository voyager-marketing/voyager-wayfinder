import Anthropic from '@anthropic-ai/sdk';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { confirmedDetail, extractAnswer, grade, RUBRIC } from '../src/grade.ts';
import { expectedFor, loadQuestions, type Question } from '../src/questions.ts';
import { CorrectionsFile } from '../src/report.ts';
import { BENCH_DIR, loadContext, pool } from '../src/run.ts';
import type { Json } from '../src/truth.ts';
import { addUsage, costUsd, emptyUsage } from '../src/usage.ts';
import { fakeClient, text } from './helpers.ts';

// In run 4 the judge failed three block ci-02 replies for listing the pages the footer shows on, and three
// classic df-01 replies for naming the Company details field group, which truth records on the Company
// Settings page. Every ci-02 and df-01 miss was re-graded by hand: those six pass by their rubrics, and
// eight stay wrong (five reached the turn cap with no answer, three say the number is hardcoded). From run 5
// the judge prompt says what extra detail looks like, and the judge sees CONFIRMED, the values a reply names
// from the records its references select. Run 4's verdicts are unchanged; the corrections files carry the
// hand re-grade. The replies are pinned from the bench-run4-transcripts release, because the report keeps
// only the last ANSWER line and the judge reads the whole reply.
const BLOCK_SHA256 = 'e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b';
const CLASSIC_SHA256 = '4511d7c994aabf5bd16fe64a880ef7dd06bebc2202ced544d78a30bd3b02f9ce';

type Fixture = 'block' | 'classic';
interface Row {
  fixture: Fixture;
  question: string;
  transcript: string;
  condition: string;
  model: string;
  rep: number;
  run4_correct: boolean;
  run4_reason: string;
  /** The hand verdict, on the rows run 4 graded wrong. */
  hand?: boolean;
  reply: string;
}
interface Control {
  id: string;
  kind: string;
  fixture: Fixture;
  question: string;
  reply: string;
}
interface ReportRow {
  question: string;
  condition: string;
  model: string;
  rep: number;
  correct: boolean;
  reason: string;
  answer: string;
  hit_turn_cap: boolean;
}

const read = (...path: string[]) => JSON.parse(readFileSync(join(BENCH_DIR, ...path), 'utf8'));
const pinned = read('test', 'run4-judge-regrade.json') as { rows: Row[]; controls: Control[] };
const sets = {
  block: {
    truth: read('..', 'fixtures', 'truth.json') as Json,
    questions: loadQuestions(join(BENCH_DIR, 'questions', 'fixture.yaml')),
    report: read('reports', 'fixture-2026-10-04-run4.json') as { results: ReportRow[]; corrected?: { issue: string } },
    corrections: CorrectionsFile.parse(read('reports', 'fixture-2026-10-04-run4-corrections.json')),
    question: 'ci-02',
  },
  classic: {
    truth: read('..', 'fixtures', 'classic', 'truth.json') as Json,
    questions: loadQuestions(join(BENCH_DIR, 'questions', 'fixture-classic.yaml')),
    report: read('reports', 'fixture-classic-2026-10-04-run4.json') as { results: ReportRow[]; corrected?: { issue: string } },
    corrections: CorrectionsFile.parse(read('reports', 'fixture-classic-2026-10-04-run4-corrections.json')),
    question: 'df-01',
  },
};
const question = (f: Fixture, id: string): Question => sets[f].questions.questions.find((q) => q.id === id) as Question;
const key = (r: { condition: string; model: string; rep: number }) => `${r.condition} ${r.model} r${r.rep}`;
const GROUP = 'options_pages[id=optpage:company-settings].field_groups';

/** Grades with a judge that returns `verdict`, and records the request it sent. */
async function gradeWith(f: Fixture, id: string, reply: string, verdict: boolean) {
  const { client, calls } = fakeClient(text(JSON.stringify({ correct: verdict, reason: 'judge' })));
  return { grade: await grade(question(f, id), reply, sets[f].truth, { client, model: 'judge', params: {} }), calls };
}

describe('run 4 ci-02 and df-01: the hand re-grade', () => {
  it('pins the question sets and every run 4 row that gave an answer', () => {
    expect(sets.block.questions.computed_sha256).toBe(BLOCK_SHA256);
    expect(sets.classic.questions.computed_sha256).toBe(CLASSIC_SHA256);
    for (const f of ['block', 'classic'] as const) {
      const { report, question: id } = sets[f];
      const rows = report.results.filter((r) => r.question === id);
      const answered = rows.filter((r) => !r.hit_turn_cap);
      const mine = pinned.rows.filter((r) => r.fixture === f);
      expect(mine.map(key), f).toEqual(answered.map(key));
      for (const [i, r] of answered.entries()) {
        expect(mine[i].run4_correct, key(r)).toBe(r.correct);
        expect(mine[i].run4_reason, key(r)).toBe(r.reason);
        // The report keeps the last ANSWER line, so the pinned reply must end on it.
        expect(extractAnswer(mine[i].reply).trim(), key(r)).toBe(r.answer);
      }
      // Every miss has a hand verdict, and only the misses do.
      expect(mine.filter((r) => r.hand !== undefined).map(key), f).toEqual(answered.filter((r) => !r.correct).map(key));
    }
    expect(pinned.rows.filter((r) => r.fixture === 'block')).toHaveLength(18);
    // df-01: 18 runs, five at the turn cap with no answer to grade.
    expect(sets.classic.report.results.filter((r) => r.question === 'df-01' && r.hit_turn_cap)).toHaveLength(5);
    expect(pinned.rows.filter((r) => r.fixture === 'classic')).toHaveLength(13);
  });

  it('corrects exactly the misses the hand re-grade passes, each with a reason', () => {
    for (const f of ['block', 'classic'] as const) {
      const { corrections } = sets[f];
      // The corrections file and the report's corrected block name the same grader fix.
      expect(corrections.issue).toBe(sets[f].report.corrected?.issue);
      const passed = pinned.rows.filter((r) => r.fixture === f && r.hand === true);
      expect(corrections.corrections.map(key), f).toEqual(passed.map(key));
      for (const c of corrections.corrections) {
        expect(c.question).toBe(sets[f].question);
        expect(c.why.length, key(c)).toBeGreaterThan(40);
      }
    }
    expect(pinned.rows.filter((r) => r.hand === true).map((r) => `${r.question} ${key(r)}`)).toEqual([
      'ci-02 manual-preloaded claude-haiku-4-5-20251001 r1',
      'ci-02 manual-preloaded claude-haiku-4-5-20251001 r2',
      'ci-02 manual-preloaded claude-haiku-4-5-20251001 r3',
      'df-01 manual claude-sonnet-5 r1',
      'df-01 manual claude-sonnet-5 r3',
      'df-01 manual-preloaded claude-sonnet-5 r1',
    ]);
  });

  it('keeps the three hardcoded df-01 replies wrong in code, with no judge call', async () => {
    const wrong = pinned.rows.filter((r) => r.hand === false);
    expect(wrong.map(key)).toEqual(['raw claude-sonnet-5 r1', 'adapter claude-sonnet-5 r2', 'adapter claude-sonnet-5 r3']);
    for (const row of wrong) {
      expect(row.reply, key(row)).toMatch(/hard-?coded/i);
      const { grade: g, calls } = await gradeWith(row.fixture, row.question, row.reply, true);
      expect(g, key(row)).toEqual({ correct: false, reason: 'missing Company Settings, judge not called' });
      expect(calls, key(row)).toHaveLength(0);
    }
  });

  it('sends the six corrected replies to the judge, with the field group confirmed for df-01', async () => {
    for (const row of pinned.rows.filter((r) => r.hand === true)) {
      const { grade: g, calls } = await gradeWith(row.fixture, row.question, row.reply, true);
      expect(g.correct, key(row)).toBe(true);
      expect(calls, key(row)).toHaveLength(1);
      expect(calls[0].system).toBe(RUBRIC);
      const content = String(calls[0].messages[0].content);
      expect(content, key(row)).toContain(`ANSWER TO GRADE:\n${row.reply}`);
      if (row.question === 'df-01') {
        expect(row.reply, key(row)).toContain('Company details');
        expect(content, key(row)).toContain(`"${GROUP}":"Company details"`);
      }
    }
  });
});

describe('CONFIRMED detail', () => {
  const df01 = question('classic', 'df-01');
  const confirmed = (reply: string) => confirmedDetail(sets.classic.truth, df01.answer.refs, expectedFor(df01, sets.classic.truth) as Record<string, Json>, reply);

  it('lists values beyond EXPECTED that the reply names from the referenced records', () => {
    expect(confirmed('ANSWER: the Phone field, company_phone (the "Company details" ACF group), on Company Settings, read by footer.php')).toEqual({
      [GROUP]: 'Company details',
      'options_pages[id=optpage:company-settings].fields[key=company_phone].label': 'Phone',
    });
    // `company_phone` does not name the label Phone: `_` continues a token.
    expect(confirmed('ANSWER: company_phone on Company Settings, read by footer.php')).toEqual({});
    // A name is matched the way a menu label is, so another word may follow it.
    expect(confirmed('ANSWER: the Company details field group')).toEqual({ [GROUP]: 'Company details' });
  });

  it('leaves out expected values, rivals, plain words and anything the reply does not name', () => {
    // company_phone, Company Settings and the footer file are expected; company_email sits where company_phone
    // does in another item, so it is a rival given in place of the fact, never a confirmed extra.
    expect(confirmed('ANSWER: company_email on Company Settings, read by themes/vwf-classic/footer.php')).toEqual({});
    // A lowercase single word (the field type `text`, the slug `footer`) stands in almost any reply.
    expect(confirmed('ANSWER: the footer text')).toEqual({});
    expect(confirmed('ANSWER: Company detail')).toEqual({});
  });

  it('adds no CONFIRMED line when the reply names nothing beyond EXPECTED', async () => {
    const { calls } = await gradeWith('classic', 'df-01', 'ANSWER: company_phone on Company Settings, read by footer.php', true);
    expect(String(calls[0].messages[0].content)).not.toContain('CONFIRMED');
  });

  it('tells the judge what extra detail is and that a hedge or a different value still fails', () => {
    // From run 6 the rubric, not every expected fact, says what an answer must state (test/judge.rubric.test.ts).
    expect(RUBRIC).toContain('A requirement is met only when the answer states it plainly, with the value EXPECTED holds.');
    expect(RUBRIC).toContain('That is extra detail, not a different value, unless the answer gives it in place of an expected value.');
    expect(RUBRIC).toContain('CONFIRMED, when given,');
    expect(RUBRIC).toContain('A requirement the answer hedges between options on, says it could not find out, or gives a different value for is not met.');
  });
});

// Calls the judge model. Off unless BENCH_LIVE_JUDGE=1, so CI and `npm test` make no API calls. It grades the
// pinned replies and the controls with the judge the bench uses, and expects: the six corrected replies pass,
// the three hardcoded ones fail in code, every other run 4 verdict is kept, and every control fails. Run 4's
// judge failed the six; the hand re-grade observed the prompt before this change fail them too, at temperature 0. About
// 36 judge calls, $0.04. Six of the kept passes leave out a fact their rubric names (five df-01 Haiku replies
// never name footer.php, and adapter Haiku r2 on ci-02 never names the template part); a judge that grades
// omissions more strictly would fail them, and this test would need them moved, which is a separate change.
describe.skipIf(!process.env.BENCH_LIVE_JUDGE)('live judge on the pinned replies', () => {
  it('passes the hand re-grade, keeps every other verdict and fails every control', { timeout: 600_000 }, async () => {
    const { config, pricing } = loadContext();
    const judge = { client: new Anthropic({ maxRetries: 8 }), model: config.judge_model, params: config.judge_params };
    const items = [
      ...pinned.rows.map((r) => ({ label: `${r.question} ${key(r)}`, fixture: r.fixture, question: r.question, reply: r.reply, want: r.hand ?? r.run4_correct })),
      ...pinned.controls.map((c) => ({ label: `${c.question} control ${c.id}`, fixture: c.fixture, question: c.question, reply: c.reply, want: false })),
    ];
    const graded = await pool(items, 4, async (it) => ({ ...it, grade: await grade(question(it.fixture, it.question), it.reply, sets[it.fixture].truth, judge) }));
    const usage = graded.reduce((u, g) => (g.grade.judge ? addUsage(u, g.grade.judge.usage) : u), emptyUsage());
    const calls = graded.filter((g) => g.grade.judge).length;
    console.log(`${calls} judge calls, $${costUsd(usage, pricing.models[config.judge_model]).toFixed(4)}`);
    const wrong = graded.filter((g) => g.grade.correct !== g.want).map((g) => `${g.label}: want ${g.want}, got ${g.grade.correct} (${g.grade.reason})`);
    expect(wrong).toEqual([]);
  });
});
