import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { grade, namesOwnScreen, ownEditScreen } from '../src/grade.ts';
import { loadQuestions, type Question } from '../src/questions.ts';
import { finalText } from '../src/regrade.ts';
import { BENCH_DIR, type Transcript } from '../src/run.ts';
import type { Json } from '../src/truth.ts';
import { fakeClient, text } from './helpers.ts';

// In run 3 the judge failed seven el-08 replies ("Where do I change the starting price shown for Drain
// cleaning?") that gave the service's own edit screen, post.php?post=90020&action=edit, and its price_from
// field, because EXPECTED held only the Services list screen. From run 4 the grader takes a post's own edit
// screen plus the field in code. Run 3 is not re-graded. The eighteen el-08 replies are pinned from the
// bench-run3-transcripts release, because the report keeps only the last ANSWER line.
const BLOCK_SHA256 = 'e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b';
const CLASSIC_SHA256 = '4511d7c994aabf5bd16fe64a880ef7dd06bebc2202ced544d78a30bd3b02f9ce';
const RUN_DIR = join(BENCH_DIR, 'runs', '2026-10-03T19-39-17-434Z');
const OWN = 'post.php?post=90020&action=edit';

const read = (path: string) => JSON.parse(readFileSync(path, 'utf8'));
const truth = read(join(BENCH_DIR, '..', 'fixtures', 'truth.json')) as Json;
const classicTruth = read(join(BENCH_DIR, '..', 'fixtures', 'classic', 'truth.json')) as Json;
const block = loadQuestions(join(BENCH_DIR, 'questions', 'fixture.yaml'));
const classic = loadQuestions(join(BENCH_DIR, 'questions', 'fixture-classic.yaml'));
const el08 = block.questions.find((q) => q.id === 'el-08') as Question;

interface Row {
  transcript: string;
  condition: string;
  model: string;
  rep: number;
  run3_correct: boolean;
  run3_reason: string;
  reply: string;
}
const pinned = read(join(BENCH_DIR, 'test', 'run3-el-08.json')) as { question: string; rows: Row[] };
const report = read(join(BENCH_DIR, 'reports', 'fixture-2026-10-03-run3.json')) as {
  results: { question: string; condition: string; model: string; rep: number; correct: boolean; reason: string }[];
};
const key = (r: { condition: string; model: string; rep: number }) => `${r.condition} ${r.model} r${r.rep}`;

/** Grades with a judge that returns `verdict`, so a pass with no judge call can only come from the code check. */
async function gradeWith(reply: string, verdict: boolean, q: Question = el08) {
  const { client, calls } = fakeClient(text(JSON.stringify({ correct: verdict, reason: 'judge' })));
  return { grade: await grade(q, reply, truth, { client, model: 'judge', params: {} }), calls };
}

describe('el-08: a post field at the post\'s own edit screen', () => {
  it('pins the question sets and the run 3 rows', () => {
    expect(block.questions_sha256).toBe(BLOCK_SHA256);
    expect(block.computed_sha256).toBe(BLOCK_SHA256);
    expect(classic.questions_sha256).toBe(CLASSIC_SHA256);
    expect(classic.computed_sha256).toBe(CLASSIC_SHA256);

    // The pinned rows are the report's eighteen el-08 rows, with the verdicts the report records.
    const rows = report.results.filter((r) => r.question === 'el-08');
    expect(pinned.question).toBe('el-08');
    expect(pinned.rows.map(key)).toEqual(rows.map(key));
    for (const [i, r] of rows.entries()) {
      expect(pinned.rows[i].run3_correct, key(r)).toBe(r.correct);
      expect(pinned.rows[i].run3_reason, key(r)).toBe(r.reason);
    }
    expect(rows.filter((r) => !r.correct)).toHaveLength(7);
  });

  it('passes the seven run 3 failures without a judge call', async () => {
    const failed = pinned.rows.filter((r) => !r.run3_correct);
    expect(failed.map(key)).toEqual([
      'manual claude-sonnet-5 r1',
      'manual claude-sonnet-5 r3',
      'manual-preloaded claude-sonnet-5 r1',
      'adapter claude-haiku-4-5-20251001 r1',
      'adapter claude-haiku-4-5-20251001 r2',
      'adapter claude-haiku-4-5-20251001 r3',
      'adapter claude-sonnet-5 r1',
    ]);
    for (const row of failed) {
      // Each names the service's own edit screen and the price field, by key or by its label.
      expect(row.reply, key(row)).toContain(OWN);
      expect(row.reply, key(row)).toMatch(/price_from|Price from/);
      // A judge that fails everything cannot be what passes them.
      const { grade: g, calls } = await gradeWith(row.reply, false);
      expect(g, key(row)).toEqual({ correct: true, reason: `names ${OWN} and price_from, judge not called` });
      expect(calls, key(row)).toHaveLength(0);
    }
  });

  it('keeps every other run 3 el-08 verdict', async () => {
    // The judge replays its recorded verdict, so a change can only come from the new rule.
    const byCode: string[] = [];
    for (const row of pinned.rows.filter((r) => r.run3_correct)) {
      const { grade: g, calls } = await gradeWith(row.reply, row.run3_correct);
      expect(g.correct, key(row)).toBe(row.run3_correct);
      if (calls.length === 0) byCode.push(key(row));
    }
    expect(pinned.rows.filter((r) => r.run3_correct)).toHaveLength(11);
    // Three passing replies also name the own edit screen and field; they stay passes, now without a judge call.
    expect(byCode).toEqual(['raw claude-haiku-4-5-20251001 r1', 'raw claude-sonnet-5 r1', 'adapter claude-sonnet-5 r3']);
  });

  it('matches the transcripts when the release is extracted into runs/', () => {
    // Optional: bench/REPRODUCE.md extracts bench-run3-transcripts into bench/runs/. Without it this checks nothing.
    if (!existsSync(RUN_DIR)) return;
    for (const row of pinned.rows) {
      const t = read(join(RUN_DIR, row.transcript)) as Transcript;
      expect(finalText(t), row.transcript).toBe(row.reply);
    }
  });

  it('applies only to el-08, and only adds passes', async () => {
    // el-08 is the one judge question, on either fixture, that references a post type's field, that post
    // type's edit_path and a subject with its own post.php edit screen.
    const qualifying = (qs: Question[], t: Json) => qs.filter((q) => q.answer.type === 'judge' && ownEditScreen(t, q.answer.refs)).map((q) => q.id);
    expect(qualifying(block.questions, truth)).toEqual(['el-08']);
    expect(qualifying(classic.questions, classicTruth)).toEqual([]);

    const own = ownEditScreen(truth, el08.answer.refs);
    expect(own?.path).toBe(OWN);
    expect(own?.field).toEqual({ key: 'price_from', label: 'Price from' });

    // Each of these misses the rule and goes to the judge, which here fails it.
    const toJudge = [
      'ANSWER: post.php?post=90021&action=edit, the price_from field', // another service
      `ANSWER: ${OWN}`, // no field
      `ANSWER: ${OWN}, the price_from_old field`, // a different key
      `ANSWER: ${OWN} or post.php?post=90021&action=edit, the price_from field`, // hedges between posts
      `ANSWER: ${OWN}, the price_from or duration field`, // hedges between fields
      `ANSWER: ${OWN}-old, the price_from field`, // a different path
      'ANSWER: Services > Drain cleaning, the Price from field', // the list screen stays with the judge
    ];
    for (const reply of toJudge) {
      const { grade: g, calls } = await gradeWith(reply, false);
      expect(g.correct, reply).toBe(false);
      expect(calls, reply).toHaveLength(1);
    }

    // The key or the label, the bare path or after wp-admin/.
    for (const reply of [
      `ANSWER: http://localhost:8888/wp-admin/${OWN} (Price from)`,
      `Edit the "Price from" field.\n\nANSWER: ${OWN}`,
      `ANSWER: \`${OWN}\`, field \`price_from\``,
    ]) {
      expect(namesOwnScreen(reply, own as NonNullable<typeof own>), reply).toBe(true);
    }
  });
});
