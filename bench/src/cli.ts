// node src/cli.ts [--fixture classic] [--dry-run] [--condition raw] [--model <id>] [--question cm-01] [--site-url <url>] [--out <dir>] [--concurrency 4]
//   --fixture block|classic seeds that fixture on the local wp-env site before a live run and uses its question set and truth.json
//   --question-set edit-safety asks that set of the fixture's `question_sets` (block by default) in place of its own, and seeds it too
// node src/cli.ts --hash-questions [--fixture classic] [--question-set edit-safety]    prints the sha256 the question file header must carry
// node src/cli.ts --regrade reports/<site>-<date>.json --corrections <file>   adds hand re-grades, no API calls
// node src/cli.ts --probe-cache [--model <id>] [--site-url <url>] [--preload-from runs/<ts>/<transcript>.json]   writes reports/caching-probe.json
// node src/cli.ts --regrade-transcripts runs/<ts> --report reports/<site>-<date>.json --truth <answer key the run used> [--fixture classic]   writes <site>-<date>-regraded.json and .md
// node src/cli.ts --compare a.json --label A --compare b.json --label B ...   prints the reports side by side as Markdown
// node src/cli.ts --summarize reports/a-run3.json --summarize reports/b-run3.json [--out reports/run3-summary.md]   combines run 3 verdicts across fixtures
//   --run-label run3 on a live run names the dated report <site>-<date>-run3
//   --max-turns 24 caps each question at 24 model calls (default 12); --repetitions 1 runs every cell once
//   --sensitivity-of reports/<site>-<date>-run3.json sets each cell beside that report's, which ran at another cap
// node src/cli.ts --best-baseline reports/a-run3-sensitivity.json --best-baseline reports/b-run3-sensitivity.json [--append reports/run3-summary.md]
//   the higher of raw and adapter per fixture and model at both caps, with R2 and R3 restated against it
// node src/cli.ts --summarize-run4 reports/a-run4.json ... --run3 reports/a-run3.json ... [--out reports/run4-summary.md]
//   run 4 beside run 3 on each shared question set, the best baseline per cell with R1 to R7 restated against it, and any new set on its own
// node src/cli.ts --summarize-run5 reports/<held-out>-run5.json ... --regression reports/<frozen>-run5-regression.json ...
//   --earlier reports/<frozen>-run4.json ... --find-eval-report reports/<site>-<date>-find-eval.json ... [--out reports/run5-summary.md]
//   S1 to S6 on the held-out sets from their intervals and find evals, and R1 to R7 restated on the frozen sets
//   against the best earlier baseline cell; refuses any report on a question set other than the frozen one
// node src/cli.ts --intervals reports/<site>-<date>.json   prints the report's 95% intervals and McNemar tests, no API calls
// node src/cli.ts --find-eval [--fixture classic] [--question-set edit-safety-2] [--site-url <url>] [--out <dir>]
//   asks find each edit-location question's find inputs and checks the answer key's location at hit 1 and at every hit,
//   no model; seeds like a bench run, and writes reports/<site>-<date>-find-eval.json and .md
// node src/cli.ts --build-snapshot --ssh <user@host> --ssh-key <file> --site-path <dir> [--snapshot-repo <owner/name>] [--out <dir>]
//   exports a real site read-only over SSH, sanitizes it and packs the tarball a snapshot fixture's pin names
//   (default out: bench/private/build/, which git ignores). The pin names the private repository the tarball is
//   published on: --snapshot-repo, else snapshot_repo in bench/private/config.json
// node src/cli.ts --start-snapshot --fixture <name>   downloads a snapshot fixture's pinned snapshot, checks its hash and
//   starts it in wp-env, with no bench run
// node src/cli.ts --exec-parity [--fixture <name>] [--calls 72] [--concurrency 6]
//   runs the same read-only wp calls through docker exec (what a live run uses) and through the queued wp-env run, on the
//   running site, and checks every output matches; prints both runners' times. No model. Exits 1 when parity fails
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { parseArgs } from 'node:util';
import { renderComparison } from './compare.ts';
import { hashQuestions, loadQuestions } from './questions.ts';
import { probeCache } from './probe.ts';
import { regradeTranscripts } from './regrade.ts';
import { intervalsFor, ReportSchema } from './report.ts';
import { renderIntervals } from './intervals.ts';
import { FindEvalReport, runFindEval } from './findEval.ts';
import { BENCH_DIR, loadConfig, regradeReport, rulesFor, runBench, wpEnvRoot } from './run.ts';
import { BEST_BASELINE_HEADING, renderBestBaseline } from './sensitivity.ts';
import { renderRun3Summary } from './summary.ts';
import { renderRun4Summary } from './run4.ts';
import { renderRun5Summary } from './run5.ts';
import { loadPrivate, PRIVATE_DIR } from './private.ts';
import { buildSnapshot, startSnapshot } from './snapshot.ts';
import { execParity, parityHolds, renderParity } from './execParity.ts';
import { dockerExecRunner, wpEnvRunner } from './wpcli.ts';

const { values } = parseArgs({
  options: {
    fixture: { type: 'string' },
    'question-set': { type: 'string' },
    'dry-run': { type: 'boolean' },
    'hash-questions': { type: 'boolean' },
    condition: { type: 'string', multiple: true },
    model: { type: 'string', multiple: true },
    question: { type: 'string', multiple: true },
    'site-url': { type: 'string' },
    out: { type: 'string' },
    recordings: { type: 'string' },
    concurrency: { type: 'string' },
    regrade: { type: 'string' },
    corrections: { type: 'string' },
    'probe-cache': { type: 'boolean' },
    'preload-from': { type: 'string' },
    'regrade-transcripts': { type: 'string' },
    report: { type: 'string' },
    truth: { type: 'string' },
    compare: { type: 'string', multiple: true },
    label: { type: 'string', multiple: true },
    summarize: { type: 'string', multiple: true },
    'run-label': { type: 'string' },
    'max-turns': { type: 'string' },
    repetitions: { type: 'string' },
    'sensitivity-of': { type: 'string' },
    'best-baseline': { type: 'string', multiple: true },
    append: { type: 'string' },
    'summarize-run4': { type: 'string', multiple: true },
    run3: { type: 'string', multiple: true },
    'summarize-run5': { type: 'string', multiple: true },
    regression: { type: 'string', multiple: true },
    earlier: { type: 'string', multiple: true },
    'find-eval-report': { type: 'string', multiple: true },
    intervals: { type: 'string' },
    'find-eval': { type: 'boolean' },
    'build-snapshot': { type: 'boolean' },
    ssh: { type: 'string' },
    'ssh-key': { type: 'string' },
    'site-path': { type: 'string' },
    'snapshot-repo': { type: 'string' },
    'start-snapshot': { type: 'boolean' },
    'exec-parity': { type: 'boolean' },
    calls: { type: 'string' },
  },
});
const readReport = (file: string) => ReportSchema.parse(JSON.parse(readFileSync(file, 'utf8')));

// bench/.env is optional. Without it the SDK reads ANTHROPIC_API_KEY from the environment.
const env = join(BENCH_DIR, '.env');
if (existsSync(env)) process.loadEnvFile(env);

if (values['build-snapshot']) {
  if (!values.ssh || !values['ssh-key'] || !values['site-path']) {
    throw new Error('--build-snapshot needs --ssh <user@host>, --ssh-key <file> and --site-path <the WordPress root on the server>. The maintainers keep those values outside this repo.');
  }
  const repo = values['snapshot-repo'] ?? loadPrivate()?.snapshot_repo;
  if (!repo) throw new Error('--build-snapshot needs the private repository the tarball is published on: --snapshot-repo <owner/name>, or snapshot_repo in bench/private/config.json');
  const out = values.out ?? join(PRIVATE_DIR, 'build');
  mkdirSync(out, { recursive: true });
  const { tarball, sha256, pin } = buildSnapshot({ ssh: values.ssh, key: values['ssh-key'], path: values['site-path'], out, repo });
  writeFileSync(join(out, 'snapshot.json'), `${JSON.stringify(pin, null, 2)}\n`);
  console.log(`Wrote ${tarball}\nsha256 ${sha256}\nPin: ${join(out, 'snapshot.json')} (copy it over the fixture's pin once the release asset is published)`);
} else if (values['exec-parity']) {
  // The site must already be running: this starts and seeds nothing, so it compares the two runners on the same state.
  const { config, fixture } = loadConfig(values.fixture, values['question-set']);
  const root = wpEnvRoot(fixture);
  const report = await execParity({
    exec: dockerExecRunner(root, { limit: config.cli_exec_limit }), wpEnv: wpEnvRunner(root),
    calls: values.calls ? Number(values.calls) : undefined, concurrency: values.concurrency ? Number(values.concurrency) : undefined,
  });
  console.log(renderParity(report));
  if (!parityHolds(report)) process.exitCode = 1;
} else if (values['start-snapshot']) {
  if (!values.fixture) throw new Error('--start-snapshot needs --fixture <name>, a fixture with a snapshot pin');
  const { fixture } = loadConfig(values.fixture);
  if (!fixture.snapshot) throw new Error(`the ${fixture.name} fixture has a seed, not a snapshot`);
  const { siteUrl } = await startSnapshot(resolve(BENCH_DIR, fixture.snapshot));
  console.log(`${fixture.name} snapshot running at ${siteUrl}`);
} else if (values['best-baseline']?.length) {
  const pairs = values['best-baseline'].map((file) => {
    const report = readReport(file);
    if (!report.sensitivity) throw new Error(`${file} carries no turn cap sensitivity`);
    return { file, report, reference: readReport(resolve(BENCH_DIR, report.sensitivity.reference.file)) };
  });
  const md = renderBestBaseline(pairs, loadConfig().config.run3_hypotheses);
  if (values.append) {
    // Appends, so the summary above it keeps every byte. A second append would duplicate the section.
    const existing = readFileSync(values.append, 'utf8');
    if (existing.includes(BEST_BASELINE_HEADING)) throw new Error(`${values.append} already has "${BEST_BASELINE_HEADING}"; remove that section to write it again`);
    writeFileSync(values.append, `${existing.replace(/\n*$/, '\n\n')}${md}`);
    console.log(`Appended to ${values.append}`);
  } else console.log(md);
} else if (values['summarize-run4']?.length) {
  if (!values.run3?.length) throw new Error('--summarize-run4 needs --run3 <report> for each frozen set');
  const named = (files: string[]) => files.map((file) => ({ file, report: readReport(file) }));
  const md = renderRun4Summary(named(values['summarize-run4']), named(values.run3), loadConfig().config.run3_hypotheses);
  if (values.out) {
    writeFileSync(values.out, md);
    console.log(`Wrote ${values.out}`);
  } else console.log(md);
} else if (values['summarize-run5']?.length) {
  const named = (files: string[] = []) => files.map((file) => ({ file, report: readReport(file) }));
  const md = renderRun5Summary({
    heldOut: named(values['summarize-run5']),
    regression: named(values.regression),
    earlier: named(values.earlier),
    findEvals: (values['find-eval-report'] ?? []).map((file) => ({ file, report: FindEvalReport.parse(JSON.parse(readFileSync(file, 'utf8'))) })),
  }, loadConfig().config.run3_hypotheses);
  if (values.out) {
    writeFileSync(values.out, md);
    console.log(`Wrote ${values.out}`);
  } else console.log(md);
} else if (values.summarize?.length) {
  const md = renderRun3Summary(values.summarize.map((file) => ({ file, report: ReportSchema.parse(JSON.parse(readFileSync(file, 'utf8'))) })));
  if (values.out) {
    writeFileSync(values.out, md);
    console.log(`Wrote ${values.out}`);
  } else console.log(md);
} else if (values.compare?.length) {
  const labels = values.label ?? [];
  if (labels.length !== values.compare.length) throw new Error('--compare needs one --label per report');
  console.log(renderComparison(values.compare.map((file, i) => ({ label: labels[i], report: ReportSchema.parse(JSON.parse(readFileSync(file, 'utf8'))) }))));
} else if (values['regrade-transcripts']) {
  if (!values.report || !values.truth) throw new Error('--regrade-transcripts needs --report <file> and --truth <file>');
  const { report, paths, changed } = await regradeTranscripts({
    transcripts: values['regrade-transcripts'], report: values.report, truth: values.truth, fixture: values.fixture, questionSet: values['question-set'],
    out: values.out ?? dirname(values.report), concurrency: values.concurrency ? Number(values.concurrency) : undefined,
  });
  for (const h of report.hypotheses) console.log(`regraded ${h.id} ${h.verdict}: ${h.numbers}`);
  console.log(`${changed} verdicts changed. Wrote ${paths.join(', ')}`);
} else if (values['hash-questions']) {
  const { fixture } = loadConfig(values.fixture, values['question-set']);
  console.log(hashQuestions(loadQuestions(resolve(BENCH_DIR, fixture.questions)).questions));
} else if (values.regrade) {
  if (!values.corrections) throw new Error('--regrade needs --corrections <file>');
  const { report, paths } = regradeReport(values.regrade, values.corrections);
  for (const h of report.corrected?.hypotheses ?? []) console.log(`corrected ${h.id} ${h.verdict}: ${h.numbers}`);
  console.log(`Wrote ${paths.join(', ')}`);
} else if (values['probe-cache']) {
  const { probe, path } = await probeCache({ models: values.model, siteUrl: values['site-url'], preloadFrom: values['preload-from'], out: values.out });
  for (const m of probe.models) console.log(`${m.model}: prefix ${m.prefix_tokens} tokens, minimum ${m.minimum_documented}. ${m.finding}`);
  console.log(`Wrote ${path}`);
} else if (values.intervals) {
  // Recomputed from the results, so a report written before the intervals existed gets them too. Nothing is written.
  const report = readReport(values.intervals);
  const rules = rulesFor(loadConfig().config, report.hypothesis_set);
  const intervals = intervalsFor(report.results, report.models.map((m) => m.id), rules);
  const ruleNames = report.hypothesis_set === 'run3' ? 'R1 to R7' : 'H1 to H6';
  console.log(`# Intervals: ${report.site}, ${report.date}${report.run_label ? ` (${report.run_label})` : ''}\n\n\`${values.intervals}\`, read with the ${ruleNames} rules in config.json.\n\n${renderIntervals(intervals)}`);
} else if (values['find-eval']) {
  const { report, paths } = await runFindEval({
    fixture: values.fixture,
    questionSet: values['question-set'],
    seed: values.fixture !== undefined || values['question-set'] !== undefined,
    siteUrl: values['site-url'],
    out: values.out,
  });
  const t = report.totals;
  console.log(`${report.site}: top-1 ${t.top1}/${t.questions}, any hit ${t.any_hit}/${t.questions}, ${report.skipped.length} not asked`);
  console.log(`Wrote ${paths.join(', ')}`);
} else {
  const { report, paths } = await runBench({
    fixture: values.fixture,
    questionSet: values['question-set'],
    seed: values.fixture !== undefined || values['question-set'] !== undefined,
    dryRun: values['dry-run'],
    conditions: values.condition,
    models: values.model,
    questions: values.question,
    siteUrl: values['site-url'],
    out: values.out,
    recordings: values.recordings,
    concurrency: values.concurrency ? Number(values.concurrency) : undefined,
    runLabel: values['run-label'],
    maxTurns: values['max-turns'] ? Number(values['max-turns']) : undefined,
    repetitions: values.repetitions ? Number(values.repetitions) : undefined,
    sensitivityOf: values['sensitivity-of'],
  });
  for (const s of report.runs) {
    console.log(`${s.condition} ${s.model} rep ${s.rep}: ${s.correct}/${s.questions} correct, median input ${s.input_tokens.median} tokens, $${s.cost_usd.toFixed(4)}`);
  }
  for (const h of report.hypotheses) console.log(`${h.id} ${h.verdict}: ${h.numbers}`);
  console.log(`Wrote ${paths.join(', ')}`);
}
