import Anthropic from '@anthropic-ai/sdk';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import type { MessagesClient } from './agent.ts';
import { grade } from './grade.ts';
import { buildReport, renderMarkdown, reportMeta, ReportSchema, type Report, type Result } from './report.ts';
import { loadContext, pool, rulesFor, type Transcript } from './run.ts';
import type { Json } from './truth.ts';
import { addUsage, costUsd, emptyUsage } from './usage.ts';

/** The text a run ended on: its last turn's text blocks, the way runAgent sets `final_text`. */
export function finalText(t: Transcript): string {
  const last = t.turns.at(-1);
  return last ? last.response.content.flatMap((b) => (b.type === 'text' ? [b.text] : [])).join('\n') : '';
}

/** The transcript file runBench writes for a result. */
export const transcriptName = (r: Pick<Result, 'condition' | 'model' | 'question' | 'rep'>) =>
  `${r.condition}.${r.model}.${r.question}${r.rep > 1 ? `.r${r.rep}` : ''}.json`;

/**
 * Grades an earlier run again with today's graders, from its full transcripts. Every grader reads
 * the reply the run actually gave, and judge rows call the judge again. Usage, cost and every other
 * measurement stay as the run recorded them; only `correct`, `reason` and `judge_cost_usd` change.
 * Pass the truth the run was graded against, so a later change to the answer key is not counted as
 * a grading change. Writes `<site>-<date>-regraded.json` and `.md` beside nothing else: the run's own
 * report and the `latest` copies are left alone.
 */
export async function regradeTranscripts(opts: {
  report: string;
  transcripts: string;
  truth: string;
  out: string;
  /** The fixture whose question set the run used. Default `default_fixture`. */
  fixture?: string;
  /** The fixture's question set the run asked, when not its own. */
  questionSet?: string;
  client?: MessagesClient;
  concurrency?: number;
}): Promise<{ report: Report; paths: string[]; changed: number }> {
  const { config, pricing, qs } = loadContext(opts.fixture, opts.questionSet);
  const written = ReportSchema.parse(JSON.parse(readFileSync(opts.report, 'utf8')));
  const { results } = written;
  const meta = reportMeta(written);
  const truth = JSON.parse(readFileSync(opts.truth, 'utf8')) as Json;
  const client = opts.client ?? new Anthropic({ maxRetries: 8 });

  const regraded: Result[] = await pool(results, Math.max(1, opts.concurrency ?? 4), async (r) => {
    if (r.hit_turn_cap) return r; // No answer was given, so there is nothing to grade.
    const question = qs.questions.find((q) => q.id === r.question);
    if (!question) throw new Error(`question ${r.question} is not in the question set`);
    const t = JSON.parse(readFileSync(join(opts.transcripts, transcriptName(r)), 'utf8')) as Transcript;
    const verdict = await grade(question, finalText(t), truth, { client, model: config.judge_model, params: config.judge_params });
    const judge = verdict.judge ? costUsd(addUsage(emptyUsage(), verdict.judge.usage), pricing.models[config.judge_model]) : 0;
    return { ...r, correct: verdict.correct, reason: verdict.reason, judge_cost_usd: judge };
  });

  const report = buildReport(regraded, meta, rulesFor(config, meta.hypothesis_set));
  const stem = join(opts.out, `${report.site}-${report.date}-regraded`);
  writeFileSync(`${stem}.json`, `${JSON.stringify(report, null, 2)}\n`);
  writeFileSync(`${stem}.md`, renderMarkdown(report));
  const changed = regraded.filter((r, i) => r.correct !== results[i].correct).length;
  return { report, paths: [`${stem}.json`, `${stem}.md`], changed };
}
