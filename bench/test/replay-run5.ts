// Replays run 6's grader changes over run 5 and writes reports/run5-regrade-graders.md. Run from bench/:
//   node test/replay-run5.ts
// No API calls. Exact and set answers are graded again from the ANSWER line each report row keeps, three ways:
//   - run 5's own grader (src/grade.ts at the commit the reports record), which must give every recorded grade back,
//     so the replay is shown to reproduce the run before it is trusted to show a change. It is read from git, so
//     this script needs a clone whose history holds that commit;
//   - today's grader, whose differences from the recorded grades are the list;
//   - a mutation of today's grader whose names() refuses every value, which must flip every pass that named its
//     value with a label or qualifier, so a replay that finds no change is shown able to find one.
// With the private bench data (src/private.ts) it replays the real-site set's report too and adds its df-06 judge
// rows, from private/test/run5-df-06-rejudge.json. The script that writes that record calls the judge; this one never does.
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import { grade } from '../src/grade.ts';
import { PRIVATE_DIR } from '../src/private.ts';
import { changedGrades, privateRun5Reports, replayReport, RUN5_REPORTS, type GradeFn, type LeftOut, type Replayed } from '../src/replay.ts';
import { BENCH_DIR } from '../src/run.ts';
import { graderModule } from './helpers.ts';

const reports = [...RUN5_REPORTS, ...privateRun5Reports()];
const reportCommit = (JSON.parse(readFileSync(join(BENCH_DIR, 'reports', RUN5_REPORTS[0]), 'utf8')) as { commit: string }).commit;
const todaySource = readFileSync(join(BENCH_DIR, 'src', 'grade.ts'), 'utf8');
const NAMES = 'function names(text: string, value: string, rivals: string[]): boolean {';
if (!todaySource.includes(NAMES)) throw new Error('names() moved; update the mutation');
const graders: Record<'run5' | 'today' | 'mutated', GradeFn> = {
  run5: (await graderModule('grade-run5', { commit: reportCommit })).grade,
  today: grade,
  mutated: (await graderModule('grade-names-refuses', { source: todaySource.replace(NAMES, `${NAMES}\n  return false;`) })).grade,
};

const replay = async (g: GradeFn) => {
  const runs = await Promise.all(reports.map((f) => replayReport(f, g)));
  const left = (k: keyof LeftOut) => runs.reduce((n, r) => n + r.left_out[k], 0);
  return { replayed: runs.flatMap((r) => r.replayed), left_out: { whole_reply: left('whole_reply'), turn_cap: left('turn_cap'), no_answer_line: left('no_answer_line') } };
};
const run5 = await replay(graders.run5);
const today = await replay(graders.today);
const mutated = await replay(graders.mutated);
// The mutation's own effect: today's grades it flips, and today's passes that went through names().
const QUALIFIED = /with (?:a label or qualifier|labels or qualifiers)$/;
const flipped = mutated.replayed.filter((r, i) => r.now.correct !== today.replayed[i].now.correct);
const qualified = today.replayed.filter((r) => r.now.correct && QUALIFIED.test(r.now.reason));

const cell = (s: string) => s.replace(/\|/g, '\\|').replace(/\s+/g, ' ');
const short = (m: string) => m.replace('claude-', '').replace('-20251001', '');
const row = (r: Replayed) => `| ${basename(r.report).replace('.json', '')} | ${r.result.question} | ${r.result.condition} | ${short(r.result.model)} | ${r.result.rep} | ${r.result.correct ? 'correct' : 'wrong'} | ${r.now.correct ? 'correct' : 'wrong'} | ${cell(r.now.reason)} |`;
const changed = changedGrades(today.replayed);
const reasonOnly = today.replayed.filter((r) => r.now.correct === r.result.correct && r.now.reason !== r.result.reason);
const EXPECTED_PREFIX = /^expected "[^"]*", /;
const reasonKinds = [...new Set(reasonOnly.map((r) => r.now.reason.replace(EXPECTED_PREFIX, '')))];
const newReasons = reasonKinds.length ? `: ${reasonKinds.map((s) => `"${s}"`).join(', ')}` : '';

const rejudgeFile = join(PRIVATE_DIR, 'test', 'run5-df-06-rejudge.json');
const rejudge = existsSync(rejudgeFile) ? (JSON.parse(readFileSync(rejudgeFile, 'utf8')) as Rejudge) : undefined;
interface RejudgeRow { condition: string; model: string; rep: number; run5: boolean; rubric: boolean; why?: string; reply: { correct: boolean }; control?: { correct: boolean } }
interface Draft { prompt: string; input: string; calls: number; cost_usd: number; disagreed_with_rubric: string[] }
interface Rejudge { judge: { model: string; params: Record<string, unknown> }; cost_usd: { control: number; replies: number }; calls: { control: number; replies: number }; drafts: Draft[]; rows: RejudgeRow[] }
const grade1 = (ok: boolean) => (ok ? 'correct' : 'wrong');
const usd = (x: number) => `$${x.toFixed(4)}`;

function judgeSection(j: Rejudge): string[] {
  const fixed = j.rows.filter((r) => r.rubric !== r.run5);
  const off = j.rows.filter((r) => r.reply.correct !== r.rubric);
  const total = j.cost_usd.control + j.cost_usd.replies + j.drafts.reduce((a, d) => a + d.cost_usd, 0);
  const controlled = j.rows.filter((r) => r.control);
  return [
    `Run 5's judge read each whole reply. Those sit only in the real-site set's private transcripts, so each df-06 reply was judged again locally by ${j.judge.model} ${JSON.stringify(j.judge.params)} with run 6's prompt (${j.calls.replies} calls for 36 replies, identical replies judged once, ${usd(j.cost_usd.replies)}). \`private/test/run5-df-06-rejudge.json\` keeps each verdict and hashes, never a reply or a judge reason, which can quote one. Run 5's grades are the control: its judge read the same replies with run 5's prompt at temperature 0. "Rubric" is the grade the question's rubric gives, read by hand: correct when the answer gives the Site Title under Settings > General, wrong when it offers a Rank Math screen as a place to change or override the name.`,
    `Run 6's judge grades ${36 - off.length} of 36 as the rubric says; run 5's graded ${j.rows.filter((r) => r.run5 === r.rubric).length}.`,
    ['| Condition | Model | Rep | Rubric | Run 5 | Run 6 judge |', '|---|---|---|---|---|---|',
      ...j.rows.map((r) => `| ${r.condition} | ${short(r.model)} | ${r.rep} | ${grade1(r.rubric)} | ${grade1(r.run5)} | ${grade1(r.reply.correct)} |`)].join('\n'),
    `### Grades that change (${fixed.filter((r) => r.reply.correct !== r.run5).length})`,
    ['| Condition | Model | Rep | Run 5 | Run 6 judge | Why the rubric passes it |', '|---|---|---|---|---|---|',
      ...fixed.map((r) => `| ${r.condition} | ${short(r.model)} | ${r.rep} | ${grade1(r.run5)} | ${grade1(r.reply.correct)} | ${cell(r.why ?? '')} |`)].join('\n'),
    `**The answer line alone could not test this.** As a control, run 5's prompt judged only the answer lines the report keeps, for the ${controlled.length} rows an early draft changed (${j.calls.control} calls, ${usd(j.cost_usd.control)}): it passes all ${fixed.filter((r) => r.control?.correct).length} of the rows above. What run 5's judge failed them for is in the replies, not in the line.`,
    '### Drafts',
    `The prompt took four drafts, each run at temperature 0 on the same 36 df-06 answers: drafts 1 and 2 on the answer lines, drafts 2 to 4 on the whole replies. Judge spend in all: ${usd(total)}. $0.10 was budgeted at first, and the maintainers approved the rest as each draft ran.`,
    ['| Prompt | Judged on | Calls | Cost | Off the rubric |', '|---|---|---|---|---|',
      ...j.drafts.map((d) => `| ${d.prompt} | ${d.input} | ${d.calls} | ${usd(d.cost_usd)} | ${d.disagreed_with_rubric.length ? d.disagreed_with_rubric.map((k) => short(k).replace(/ (\d)$/, ' r$1')).join(', ') : 'none'} |`),
      `| draft 3, shipped | whole replies | ${j.calls.replies} | ${usd(j.cost_usd.replies)} | ${off.length ? off.map((r) => `${r.condition} ${short(r.model)} r${r.rep}`).join(', ') : 'none'} |`].join('\n'),
    '- Draft 2 passed two answers that offer Rank Math "to override it": it read "naming Rank Math\'s setting as the place to change it" as the only place. Draft 3 reads a ruled-out item as the plain act, and has the judge quote the answer\'s words before marking it.',
    '- Draft 4 added an example of an explanatory clause, and the judge then required the theme filter in 13 replies. It was dropped.',
    '- **Draft 3 ran twice on the same replies and prompt at temperature 0.** The first run failed cli-manual Sonnet r1, the second passed it. One row is within this judge\'s run-to-run noise, so a single question\'s re-judge is weak evidence about a prompt.',
  ];
}

const md = [
  '# Run 5 replayed through the run 6 graders',
  `A correction note for run 5, not a re-grade: run 5's reports, \`run5-summary.md\` and every S1 to S6 verdict stay as the graders gave them. This lists the grades that run 6's grader fixes give differently, so run 6 can be read beside run 5. Written by \`node test/replay-run5.ts\` from the run 5 reports (${reports.map((f) => `\`${basename(f)}\``).join(', ')}).` +
    (rejudge ? '' : ' Run 5\'s other held-out set, on a snapshot of a real site, is private bench data, so its rows are not here.'),
  '## Code graders (exact and set)',
  `Exact and set graders read only the ANSWER line, which every report row keeps, so each answer is graded again with no transcript and gets the grade the run's reply would. ${today.replayed.length} answers were replayed. Left out: ${today.left_out.whole_reply} judge and contains_all rows, which read the whole reply; ${today.left_out.turn_cap} exact and set rows that reached the turn cap; and ${today.left_out.no_answer_line} whose reply had no ANSWER line, so the grader read the whole reply.`,
  '### Controls',
  `- **Run 5's grader reproduces run 5.** \`src/grade.ts\` at \`${reportCommit.slice(0, 12)}\`, the commit the reports record, gives ${changedGrades(run5.replayed).length} grades different from the recorded ones over the same ${run5.replayed.length} answers. The replay shows each answer what the run showed it.`,
  `- **A mutation is found.** Today's grader with \`names()\` refusing every value gives ${flipped.length} grades different from today's grader: the ${qualified.length} answers it passes for naming the value with a label or qualifier${flipped.length === qualified.length ? ', and no others' : ''}. So a replay that shows a grade unchanged could have shown it changed.`,
  `### Grades that change (${changed.length})`,
  changed.length
    ? ['| Report | Question | Condition | Model | Rep | Run 5 | Run 6 grader | Run 6 reason |', '|---|---|---|---|---|---|---|---|', ...changed.map(row)].join('\n')
    : 'None.',
  `${reasonOnly.length} more answers keep their grade with a new reason${newReasons}.`,
  ...(rejudge ? ['## Judge (the real-site set\'s df-06)', ...judgeSection(rejudge)] : []),
  '## Not replayed',
  rejudge
    ? '- **Other judge questions.** The judge prompt changed for every judge question, and only the real-site set\'s df-06 was judged again. How the new prompt grades the rest is unknown in either direction. Judging every run 5 judge row again (the real-site set alone has 972, and run 5\'s judge cost $0.97 there) is the check to make before run 6 reads its judge grades.'
    : '- **Judge questions.** The judge prompt changed for every judge question. How the new prompt grades run 5\'s judge rows is unknown in either direction, and judging them again is the check to make before run 6 reads its judge grades.',
  '- **contains_all.** Its grader did not change.',
  ...(rejudge ? ['- **The real-site set\'s si-02.** Checked against its rubric and not changed: the one miss (manual-preloaded Sonnet r2) names the Users screen and users.php, and nothing about opening the teammate\'s account or changing its Role, which the rubric asks for.'] : []),
].join('\n\n').concat('\n');

writeFileSync(join(BENCH_DIR, 'reports', 'run5-regrade-graders.md'), md);
console.log(`run5 grader: ${changedGrades(run5.replayed).length} changed; today: ${changed.length} changed, ${reasonOnly.length} reason-only; mutation flips ${flipped.length} of today's grades, ${qualified.length} qualified passes; replayed ${today.replayed.length}, left out ${JSON.stringify(today.left_out)}`);
