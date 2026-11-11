import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { grade, type Grade, type Judge } from './grade.ts';
import { loadPrivate, PRIVATE_DIR } from './private.ts';
import { loadQuestions, type Question } from './questions.ts';
import { ReportSchema, type Result } from './report.ts';
import { BENCH_DIR, loadConfig } from './run.ts';
import type { Json } from './truth.ts';

/** Run 5's published reports in `reports/`, the answers run 6's grader changes are replayed over. */
export const RUN5_REPORTS = [
  'fixture-edit-safety-2-2026-10-06-run5.json',
  'fixture-2026-10-06-run5-regression.json',
  'fixture-classic-2026-10-06-run5-regression.json',
  'fixture-edit-safety-2026-10-06-run5-regression.json',
];

/** Run 5's private reports, in `private/reports/`: none without the private bench data (src/private.ts). */
export const privateRun5Reports = (dir = PRIVATE_DIR): string[] => (loadPrivate(dir)?.run5?.reports ?? []).map((f) => join(dir, 'reports', f));

export type GradeFn = (q: Question, reply: string, truth: Json, j: Judge) => Promise<Grade>;

export interface Replayed {
  report: string;
  result: Result;
  now: { correct: boolean; reason: string };
}

/** Rows a replay cannot grade from the report: judge and contains_all rows, which read the whole reply; turn caps; replies with no ANSWER line. */
export interface LeftOut { whole_reply: number; turn_cap: number; no_answer_line: number }

/** Exact and set graders never call the judge, so a replay gives them one that fails loudly. */
const NO_JUDGE: Judge = { client: { messages: { create: () => Promise.reject(new Error('a code grader called the judge')) } }, model: 'none', params: {} };

/**
 * The question file a report recorded. A report records it relative to bench/, and a private report written before
 * its set moved to `private/` records it as it was then, so that report's file is looked for under `private/` too.
 */
const recordedQuestions = (file: string): string => {
  const here = resolve(BENCH_DIR, file);
  return existsSync(here) ? here : resolve(PRIVATE_DIR, file);
};

/**
 * Grades every exact and set answer in a written report again. Those two graders read only the ANSWER line, which
 * each result keeps, so the replay needs no transcript and gives what the run's reply would. Left out: judge and
 * contains_all rows, which read the whole reply; rows with no ANSWER line, where the grader read the whole reply too;
 * and rows that reached the turn cap, which gave no answer. `file` is in `reports/` unless it is a full path, as a
 * private report's is. `gradeFn` defaults to today's `grade`.
 */
export async function replayReport(file: string, gradeFn: GradeFn = grade): Promise<{ replayed: Replayed[]; left_out: LeftOut }> {
  const report = ReportSchema.parse(JSON.parse(readFileSync(resolve(BENCH_DIR, 'reports', file), 'utf8')));
  const { fixture } = loadConfig(report.fixture);
  const truth = JSON.parse(readFileSync(resolve(BENCH_DIR, fixture.truth), 'utf8')) as Json;
  const qs = loadQuestions(recordedQuestions(report.question_set.file));
  if (qs.computed_sha256 !== report.question_set.sha256) throw new Error(`${file}: ${report.question_set.file} has changed since the run`);
  const byId = new Map(qs.questions.map((q) => [q.id, q]));
  const replayed: Replayed[] = [];
  const left_out: LeftOut = { whole_reply: 0, turn_cap: 0, no_answer_line: 0 };
  for (const result of report.results) {
    const q = byId.get(result.question);
    if (!q) throw new Error(`${file}: question ${result.question} is not in ${report.question_set.file}`);
    const why = !['exact', 'set'].includes(q.answer.type) ? 'whole_reply' : result.hit_turn_cap ? 'turn_cap' : !result.answer ? 'no_answer_line' : undefined;
    if (why) {
      left_out[why]++;
      continue;
    }
    const g = await gradeFn(q, `ANSWER: ${result.answer}`, truth, NO_JUDGE);
    replayed.push({ report: file, result, now: { correct: g.correct, reason: g.reason } });
  }
  return { replayed, left_out };
}

/** The replayed rows whose grade differs from the one the report recorded. */
export const changedGrades = (replayed: Replayed[]) => replayed.filter((r) => r.now.correct !== r.result.correct);
