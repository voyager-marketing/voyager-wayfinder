import type { FindEvalReport } from './findEval.ts';
import { NON_INFERIORITY_MARGIN_POINTS, nonInferior, renderIntervals, type Intervals } from './intervals.ts';
import { BASELINE_CONDITIONS, intervalsFor, judgeRun3, lossesSection, percentile, WAYFINDER_CONDITIONS, type Report, type Result, type Run3Rules } from './report.ts';
import { loadPrivate, type PrivateConfig } from './private.ts';
import { combine, RUN3_CLAIMS } from './summary.ts';

// The run 5 summary. S1 to S6 are judged on the held-out sets from each report's paired 95% intervals and the find
// evals, and R1 to R7 are restated on the three frozen sets, whose run 5 runs carry only the Wayfinder cells, against
// the best baseline cell any earlier run recorded on the same question set. Written before any run 5 result existed.
// Reads reports only; no API calls. Run 5 held out two sets: edit-safety-2, published here, and a set on a snapshot of
// a real site, which is private bench data (src/private.ts). Without it, the summary has edit-safety-2 alone.

export const HAIKU = 'claude-haiku-4-5-20251001';
export const SONNET = 'claude-sonnet-5';

/** S1 to S6 as the spec words them (approved 2026-10-04 before any run 5 data), with the real site's name left out. */
export const RUN5_CLAIMS: Record<string, string> = {
  S1: 'On each held-out set, the manual condition\'s accuracy has a 95% interval lower bound of at least 85% on Sonnet and 80% on Haiku',
  S2: 'The map helps the same agent: manual beats raw, and cli-manual beats cli, on each held-out set and both models, paired by question, with the 95% interval of the difference above zero',
  S3: 'Haiku with the preloaded manual is within 5 points of Sonnet\'s best baseline (the highest of raw, adapter and cli on that set) or better: the lower bound of the paired difference is at least -5 points, on each held-out set',
  S4: 'Haiku with the preloaded manual costs at least 5x less per correct answer than Sonnet\'s best baseline, with the interval\'s lower bound at 5x or more, on each held-out set',
  S5: 'The brief is at most 600 and the index at most 4,000 estimated tokens on the real-site snapshot as well as on the fixtures',
  S6: 'Without a model, find\'s top hit is the answer key\'s location for at least 85% of edit-location questions that carry find inputs, and one of its hits for at least 95%',
};

/** The thresholds in RUN5_CLAIMS, and the run design the spec fixes. The held-out sets are `heldOutSets()`. */
export const RUN5_RULES = {
  maxTurns: 24,
  repetitions: 3,
  s1MinLower: { [HAIKU]: 0.8, [SONNET]: 0.85 } as Record<string, number>,
  s2Pairs: [{ challenger: 'manual', baseline: 'raw' }, { challenger: 'cli-manual', baseline: 'cli' }],
  s3: { challenger: { condition: 'manual-preloaded', model: HAIKU }, baselineModel: SONNET, marginPoints: NON_INFERIORITY_MARGIN_POINTS },
  s4MinRatioLower: 5,
  s5MaxEstTokens: { brief: 600, index: 4000 },
  s6: { minTop1: 0.85, minAnyHit: 0.95 },
};

/**
 * Product gaps filed from the run 5 smoke runs, before any full-run result existed, and left unfixed so the real-site
 * set stays held out (a fix driven by its answers would be tuned on it). The summary cites each beside what it bears on.
 */
export const SMOKE_FINDINGS = {
  s5: { text: 'on the real-site snapshot the index leaves out items to fit its 16,000-character budget (170 in the smoke runs), so S5 holds there by truncation and the preloaded agent reads a partial map' },
  el01: { text: 'a theme pattern registered in PHP that prints a Site Data option is reported as hard-coded, so in the smoke runs every manual cell sent el-01\'s phone number to the pattern instead of the Site Data screen' },
};

/** Where a run's transcripts live: a release, its repository and what it holds. */
export type Transcripts = { repo: string; release: string; holds: string };

/**
 * Where run 5's public transcripts live. The fixture sets' transcripts quote no private code. The real-site set's
 * cli cells read files from private plugins and a private theme, so its transcripts are private bench data, and
 * `bench/private/config.json` says where they are.
 */
export const RUN5_TRANSCRIPTS: { public: Transcripts } = {
  public: { repo: 'voyager-marketing/voyager-wayfinder', release: 'bench-run5-transcripts', holds: 'edit-safety-2 and the three regression runs' },
};

/** A question set run 5 reads: the site its reports name and the sha256 it was frozen at. */
export type FrozenSet = {
  site: string;
  sha256: string;
  heldOut: boolean;
  /** A snapshot of a real site: S5 needs one beside a seeded fixture. Its reports name this fixture. */
  snapshotFixture?: string;
  /** The summary prints the el-01 smoke finding beside this set. */
  el01Finding?: boolean;
};

/**
 * The published question sets run 5 reads, with the sha256 each was frozen at: pinned in each set's test and
 * recorded in the reports of the runs before. A report on any other hash is refused, so the summary cannot be
 * written over an edited set.
 */
export const PUBLIC_FROZEN_SETS: Record<string, FrozenSet> = {
  'edit-safety-2': { site: 'fixture-edit-safety-2', sha256: '79b1b58aef25744644e6dfc104550afb654338bd89897963d14f1a5aec73bcb7', heldOut: true },
  block: { site: 'fixture', sha256: 'e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b', heldOut: false },
  classic: { site: 'fixture-classic', sha256: '4511d7c994aabf5bd16fe64a880ef7dd06bebc2202ced544d78a30bd3b02f9ce', heldOut: false },
  'edit-safety': { site: 'fixture-edit-safety', sha256: '69eb2c8401ba4ff31666e968dce3cf8ac31c3c5153c4920a70dbadb9fe372cc9', heldOut: false },
};

const fromPrivate = (p: PrivateConfig | undefined) => ({
  sets: Object.fromEntries(Object.entries(p?.run5?.sets ?? {}).map(([name, s]): [string, FrozenSet] => [name, {
    site: s.site, sha256: s.sha256, heldOut: s.held_out, ...(s.snapshot && { snapshotFixture: s.fixture }), ...(s.el01_finding && { el01Finding: true }),
  }])),
  transcripts: p?.run5?.transcripts,
});
let privateRun5 = fromPrivate(loadPrivate());

/**
 * Replaces the private sets with these, or with the ones in `bench/private/config.json` when called with none.
 * Tests use it to stand in a made-up real-site set.
 */
export function usePrivateRun5(sets?: Record<string, FrozenSet>, transcripts?: Transcripts): void {
  privateRun5 = sets ? { sets, transcripts } : fromPrivate(loadPrivate());
}

/** Every question set run 5 reads: the published ones and any private ones. A private set may not reuse a name. */
export function frozenSets(): Record<string, FrozenSet> {
  for (const name of Object.keys(privateRun5.sets)) {
    if (PUBLIC_FROZEN_SETS[name]) throw new Error(`private run 5 set "${name}" has the name of a published set`);
  }
  return { ...PUBLIC_FROZEN_SETS, ...privateRun5.sets };
}

/** The held-out sets, published first. S1 to S4 hold only where they hold on each. */
export const heldOutSets = (): string[] => Object.entries(frozenSets()).filter(([, s]) => s.heldOut).map(([name]) => name);

/** The condition name the best baseline is judged under, so `judgeRun3` reads it like any baseline. */
const BEST = 'best-baseline';

type Named = { file: string; report: Report };
type NamedFindEval = { file: string; report: FindEvalReport };
type Verdict = Report['hypotheses'][number]['verdict'];
export type Judged = { id: string; claim: string; verdict: Verdict; numbers: string };

const pct = (x: number) => `${(x * 100).toFixed(1)}%`;
const usd2 = (x: number) => `$${x.toFixed(2)}`;
const usd4 = (x: number) => (Number.isFinite(x) ? `$${x.toFixed(4)}` : 'n/a');
const int = (x: number) => Math.round(x).toLocaleString('en-US');
const sum = (xs: number[]) => xs.reduce((a, b) => a + b, 0);
/** Percentage points to a tenth, so floating error cannot decide a threshold, as R2 compares. */
const tenth = (x: number) => Math.round(x * 1000) / 10;
const signed = (x: number) => `${tenth(x) > 0 ? '+' : ''}${tenth(x).toFixed(1)}`;
const times = (x: number | null) => (x === null ? 'n/a' : `${x.toFixed(2)}x`);
const table = (head: string[], rows: string[][]) =>
  [`| ${head.join(' | ')} |`, `|${head.map(() => '---').join('|')}|`, ...rows.map((r) => `| ${r.join(' | ')} |`)].join('\n');
const escapeCell = (text: string) => text.replace(/\|/g, '\\|');
const short = (model: string) => (model === HAIKU ? 'Haiku' : model === SONNET ? 'Sonnet' : model);

const cellOf = (rs: Result[], condition: string, model: string) => rs.filter((r) => r.condition === condition && r.model === model);
const accuracy = (rs: Result[]) => (rs.length ? rs.filter((r) => r.correct).length / rs.length : NaN);
const inputOf = (r: Result) => r.usage.input_tokens + r.usage.cache_creation_input_tokens + r.usage.cache_read_input_tokens;
const passes = (rs: Result[]) => new Set(rs.map((r) => r.rep)).size || 1;
const totalPerPass = (rs: Result[]) => sum(rs.map(inputOf)) / passes(rs);
const costPerCorrect = (rs: Result[]) => sum(rs.map((r) => r.cost_usd)) / rs.filter((r) => r.correct).length;
const scored = (rs: Result[]) => `${pct(accuracy(rs))} (${rs.filter((r) => r.correct).length}/${rs.length})`;
const sourceOf = (report: Report) => `${report.run_label ?? report.date}, ${report.max_turns ?? 12} turns`;

/** The question set a bench or find-eval report ran, by its site name. */
export function setOf(report: { site: string }): string {
  const set = Object.entries(frozenSets()).find(([, s]) => s.site === report.site)?.[0];
  if (!set) throw new Error(`no run 5 question set for site "${report.site}"`);
  return set;
}

/** Refuses a report whose question set is not the one run 5 froze, by name and sha256. */
export function checkFrozen(file: string, report: { site: string; question_set: { sha256: string } }): string {
  const set = setOf(report);
  const frozen = frozenSets()[set];
  if (report.question_set.sha256 !== frozen.sha256) {
    throw new Error(`${file}: the ${set} set has sha256 ${report.question_set.sha256}, not the frozen ${frozen.sha256}`);
  }
  return set;
}

const intervalsOf = (report: Report, rules: Run3Rules): Intervals => report.intervals ?? intervalsFor(report.results, report.models.map((m) => m.id), rules);
const cellInterval = (iv: Intervals, condition: string, model: string) => iv.cells.find((c) => c.condition === condition && c.model === model);
const comparisonOf = (iv: Intervals, challenger: { condition: string; model: string }, baseline: { condition: string; model: string }) =>
  iv.comparisons.find((c) => c.challenger.condition === challenger.condition && c.challenger.model === challenger.model
    && c.baseline.condition === baseline.condition && c.baseline.model === baseline.model);

/**
 * Sonnet's best baseline on one held-out set, as S3 and S4 name it: the highest pooled accuracy of raw, adapter
 * and cli. Every condition tied for the top is returned, and S3 and S4 must hold against each of them, so a tie
 * can never pick the baseline that is easier to beat.
 */
export function sonnetBest(iv: Intervals, model = RUN5_RULES.s3.baselineModel): { conditions: string[]; accuracy: number } | undefined {
  const cells = BASELINE_CONDITIONS.map((c) => cellInterval(iv, c, model)).filter((c) => c !== undefined);
  if (!cells.length) return undefined;
  const top = Math.max(...cells.map((c) => c.accuracy));
  return { conditions: cells.filter((c) => tenth(c.accuracy) === tenth(top)).map((c) => c.condition), accuracy: top };
}

/** S1 to S4 on one held-out report. Each part names its figure; a missing cell or comparison is "not measured". */
export function judgeHeldOut(report: Report, rules: Run3Rules): { s1: Judged; s2: Judged; s3: Judged; s4: Judged; best?: ReturnType<typeof sonnetBest> } {
  const iv = intervalsOf(report, rules);
  const models = report.models.map((m) => m.id);
  const part = (id: string, parts: { ok: boolean | undefined; text: string }[]): Judged => ({
    id, claim: RUN5_CLAIMS[id],
    verdict: parts.some((p) => p.ok === false) ? 'fail' : parts.length && parts.every((p) => p.ok === true) ? 'pass' : 'not measured',
    numbers: parts.map((p) => p.text).join('; '),
  });

  const s1 = part('S1', models.map((m) => {
    const cell = cellInterval(iv, 'manual', m);
    const min = RUN5_RULES.s1MinLower[m];
    if (!cell || min === undefined) return { ok: undefined, text: `${short(m)} manual: ${cell ? 'no S1 threshold' : 'not run'}` };
    return { ok: tenth(cell.lower) >= tenth(min), text: `${short(m)} manual ${pct(cell.accuracy)}, 95% interval ${pct(cell.lower)} to ${pct(cell.upper)}, lower bound needs ${pct(min)}` };
  }));

  const s2 = part('S2', models.flatMap((m) => RUN5_RULES.s2Pairs.map(({ challenger, baseline }) => {
    const c = comparisonOf(iv, { condition: challenger, model: m }, { condition: baseline, model: m });
    if (!c) return { ok: undefined, text: `${short(m)} ${challenger} vs ${baseline}: not run` };
    return { ok: tenth(c.accuracy.lower) > 0, text: `${short(m)} ${challenger} vs ${baseline} ${signed(c.accuracy.difference)} points (${signed(c.accuracy.lower)} to ${signed(c.accuracy.upper)})` };
  })));

  const best = sonnetBest(iv);
  const { challenger, baselineModel, marginPoints } = RUN5_RULES.s3;
  const against = best?.conditions.map((condition) => ({ condition, c: comparisonOf(iv, challenger, { condition, model: baselineModel }) })) ?? [];
  const tie = best && best.conditions.length > 1 ? ` (tied at ${pct(best.accuracy)}; judged against each)` : '';
  const s3 = part('S3', best ? against.map(({ condition, c }) => (c
    ? { ok: nonInferior(c.accuracy.lower, marginPoints), text: `Haiku manual-preloaded ${pct(c.accuracy.challenger)} vs Sonnet best (${condition}) ${pct(c.accuracy.baseline)}${tie}: ${signed(c.accuracy.difference)} points (${signed(c.accuracy.lower)} to ${signed(c.accuracy.upper)}), lower bound needs -${marginPoints}.0` }
    : { ok: undefined, text: `Haiku manual-preloaded vs Sonnet ${condition}: not run` })) : [{ ok: undefined, text: 'no Sonnet baseline cell' }]);
  const s4 = part('S4', best ? against.map(({ condition, c }) => {
    if (!c) return { ok: undefined, text: `Haiku manual-preloaded vs Sonnet ${condition}: not run` };
    const x = c.cost_per_correct;
    return {
      ok: x.lower !== null && x.lower >= RUN5_RULES.s4MinRatioLower,
      text: `cost per correct answer ${usd4(x.challenger_usd ?? NaN)} vs Sonnet best (${condition}) ${usd4(x.baseline_usd ?? NaN)}${tie}: ${times(x.ratio)} (${times(x.lower)} to ${times(x.upper)}), lower bound needs ${RUN5_RULES.s4MinRatioLower}.00x`,
    };
  }) : [{ ok: undefined, text: 'no Sonnet baseline cell' }]);

  return { s1, s2, s3, s4, best };
}

/** S5 over every report that read a manual: a real-site snapshot and at least one seeded fixture must both be in it. */
export function judgeS5(reports: Named[]): Judged {
  const { brief, index } = RUN5_RULES.s5MaxEstTokens;
  const read = reports.filter(({ report }) => report.manual);
  const sites = new Map(read.map(({ report }) => [report.fixture ?? report.site, report.manual!]));
  const parts = [...sites].map(([fixture, m]) => ({
    ok: m.brief_est_tokens <= brief && m.index_est_tokens <= index,
    text: `${fixture}: brief ${int(m.brief_est_tokens)}, index ${int(m.index_est_tokens)} est. tokens (${int(m.brief_chars)} and ${int(m.index_chars)} chars)` +
      (m.index_dropped ? `, within budget only because the index left out ${int(m.index_dropped)} items to fit it` : ''),
  }));
  const snapshots = new Set(Object.values(frozenSets()).flatMap((s) => (s.snapshotFixture ? [s.snapshotFixture] : [])));
  const complete = [...sites.keys()].some((f) => snapshots.has(f)) && [...sites.keys()].some((f) => !snapshots.has(f));
  return {
    id: 'S5', claim: RUN5_CLAIMS.S5,
    verdict: parts.some((p) => !p.ok) ? 'fail' : complete ? 'pass' : 'not measured',
    numbers: parts.length ? parts.map((p) => p.text).join('; ') + (complete ? '' : '; a real-site snapshot and a seeded fixture are both needed') : 'no report read a manual',
  };
}

/**
 * S6 on the held-out sets' find evals, pooled over their questions as the spec words it ("edit-location
 * questions that carry find inputs": only the held-out sets carry them in the question itself). Each set's
 * own figures are in the numbers; the frozen sets' evals are context and decide nothing.
 */
export function judgeS6(evals: NamedFindEval[]): Judged {
  const held = evals.filter(({ report }) => frozenSets()[setOf(report)].heldOut);
  const missing = heldOutSets().filter((s) => !held.some(({ report }) => setOf(report) === s));
  const q = sum(held.map(({ report }) => report.totals.questions));
  const top1 = sum(held.map(({ report }) => report.totals.top1));
  const any = sum(held.map(({ report }) => report.totals.any_hit));
  const per = held.map(({ report }) => `${setOf(report)} top-1 ${report.totals.top1}/${report.totals.questions}, any hit ${report.totals.any_hit}/${report.totals.questions}`);
  const { minTop1, minAnyHit } = RUN5_RULES.s6;
  const ok = q > 0 && tenth(top1 / q) >= tenth(minTop1) && tenth(any / q) >= tenth(minAnyHit);
  return {
    id: 'S6', claim: RUN5_CLAIMS.S6,
    verdict: missing.length || !q ? 'not measured' : ok ? 'pass' : 'fail',
    numbers: [
      q ? `held-out sets pooled: top-1 ${top1}/${q} (${pct(top1 / q)}, needs ${pct(minTop1)}), any hit ${any}/${q} (${pct(any / q)}, needs ${pct(minAnyHit)})` : 'no held-out find eval',
      ...per,
      ...(missing.length ? [`no find eval for ${missing.join(', ')}`] : []),
    ].join('; '),
  };
}

type Candidate = { file: string; report: Report; condition: string; rs: Result[]; accuracy: number; input: number };

/**
 * The best baseline cell recorded on one question set for one model, over every baseline condition of every
 * report given: the highest pooled accuracy, a tie going to fewer total input tokens per pass (the harder side
 * for the token rules), as run 4 chose it. Choosing after every run was seen gives the baseline every advantage.
 */
export function bestEarlierBaseline(reports: Named[], sha256: string, model: string): { best: Candidate; tied: boolean } | undefined {
  const options: Candidate[] = reports.filter(({ report }) => report.question_set.sha256 === sha256)
    .flatMap(({ file, report }) => BASELINE_CONDITIONS.map((condition) => ({ file, report, condition, rs: cellOf(report.results, condition, model) })))
    .filter((o) => o.rs.length)
    .map((o) => ({ ...o, accuracy: accuracy(o.rs), input: totalPerPass(o.rs) }));
  if (!options.length) return undefined;
  options.sort((a, b) => b.accuracy - a.accuracy || a.input - b.input);
  return { best: options[0], tied: options.length > 1 && options[1].accuracy === options[0].accuracy };
}

/**
 * R1 to R7 on one frozen set: run 5's Wayfinder cells against the best earlier baseline cell per model, judged
 * by `judgeRun3` unchanged with that cell in place of adapter-only. These are seen questions.
 */
export function restateFrozen(regression: Report, earlier: Named[], rules: Run3Rules): { hypotheses: Report['hypotheses']; best: Record<string, ReturnType<typeof bestEarlierBaseline>> } {
  const models = regression.models.map((m) => m.id);
  const best = Object.fromEntries(models.map((m) => [m, bestEarlierBaseline(earlier, regression.question_set.sha256, m)]));
  const merged = [
    ...regression.results.filter((r) => WAYFINDER_CONDITIONS.includes(r.condition)),
    ...models.flatMap((m) => best[m]?.best.rs.map((r) => ({ ...r, condition: BEST })) ?? []),
  ];
  const swapped: Run3Rules = { ...rules, baseline: BEST, r3: { ...rules.r3, baseline: { ...rules.r3.baseline, condition: BEST } } };
  const hypotheses = judgeRun3(merged, { models: regression.models, manual: regression.manual, fixture: regression.fixture }, swapped)
    .map((h) => ({ ...h, claim: h.claim.replaceAll(BEST, 'the best earlier baseline'), numbers: h.numbers.replaceAll(BEST, 'best baseline') }));
  return { hypotheses, best };
}

function categoryTable(report: Report): string {
  const categories = [...new Set(report.results.map((r) => r.category))].sort();
  const order = [...BASELINE_CONDITIONS, ...WAYFINDER_CONDITIONS];
  const cells = [...new Map(report.results.map((r) => [`${r.condition} ${r.model}`, { condition: r.condition, model: r.model }])).values()]
    .sort((a, b) => a.model.localeCompare(b.model) || order.indexOf(a.condition) - order.indexOf(b.condition));
  return table(['Model', 'Condition', ...categories, 'All'], cells.map(({ condition, model }) => {
    const rs = cellOf(report.results, condition, model);
    return [model, condition, ...categories.map((c) => scored(rs.filter((r) => r.category === c))), scored(rs)];
  }));
}

function cellTable(report: Report, iv: Intervals): string {
  const order = [...BASELINE_CONDITIONS, ...WAYFINDER_CONDITIONS];
  const cells = [...iv.cells].sort((a, b) => a.model.localeCompare(b.model) || order.indexOf(a.condition) - order.indexOf(b.condition));
  return table(['Model', 'Condition', 'Reps', 'Accuracy', '95% interval', 'p90 input', 'Cost per correct', 'Turn cap hits'], cells.map((c) => {
    const rs = cellOf(report.results, c.condition, c.model);
    return [c.model, c.condition, String(c.reps), scored(rs), `${pct(c.lower)} to ${pct(c.upper)}`, int(percentile(rs.map(inputOf), 90)), usd4(costPerCorrect(rs)), String(rs.filter((r) => r.hit_turn_cap).length)];
  }));
}

/** Demotes the intervals section by one level so it nests under its set's heading. */
const nested = (md: string) => md.replace(/^(#{2,3}) /gm, '#$1 ');

/**
 * The run 5 summary. `heldOut` is one full run per held-out set; `regression` the Wayfinder-only runs on the
 * frozen sets; `earlier` the reports of earlier runs whose baseline cells R1 to R7 are restated against (and whose
 * Wayfinder cells the regression is set beside); `findEvals` the find eval reports. Refuses any report on a
 * question set other than the frozen one.
 */
export function renderRun5Summary(input: { heldOut: Named[]; regression: Named[]; earlier: Named[]; findEvals: NamedFindEval[] }, rules: Run3Rules): string {
  const { heldOut, regression, earlier, findEvals } = input;
  for (const { file, report } of [...heldOut, ...regression, ...earlier]) {
    checkFrozen(file, report);
    if (report.dry_run) throw new Error(`${file} is a dry run`);
  }
  for (const { file, report } of findEvals) checkFrozen(file, report);
  const heldSets = heldOut.map(({ file, report }) => {
    const set = setOf(report);
    if (!frozenSets()[set].heldOut) throw new Error(`${file} is the ${set} set, which is not held out; pass it as a regression run`);
    if ((report.max_turns ?? 12) !== RUN5_RULES.maxTurns) throw new Error(`${file} ran at ${report.max_turns ?? 12} turns; run 5 gives every question ${RUN5_RULES.maxTurns}`);
    if (report.results.some((r) => r.condition === 'adapter') && !report.adapter_tools) {
      throw new Error(`${file} records no adapter_tools, so nothing shows the adapter cells were offered no Wayfinder tool`);
    }
    const wayfinderTools = (report.adapter_tools ?? []).filter((t) => /wayfinder/i.test(t));
    if (wayfinderTools.length) throw new Error(`${file}: the adapter cells were offered Wayfinder tools (${wayfinderTools.join(', ')}), so adapter is not a baseline without the map`);
    return set;
  });
  if (new Set(heldSets).size !== heldSets.length) throw new Error(`more than one report for a held-out set: ${heldSets.join(', ')}`);
  for (const { file, report } of regression) {
    if (frozenSets()[setOf(report)].heldOut) throw new Error(`${file} is a held-out set; pass it with --summarize-run5`);
  }

  const judged = heldOut.map((n) => ({ ...n, set: setOf(n.report), iv: intervalsOf(n.report, rules), s: judgeHeldOut(n.report, rules) }));
  const s5 = judgeS5([...heldOut, ...regression]);
  const s6 = judgeS6(findEvals);
  const ids = ['S1', 'S2', 'S3', 'S4'] as const;
  const key = (id: (typeof ids)[number]) => id.toLowerCase() as 's1' | 's2' | 's3' | 's4';
  const held = heldOutSets();
  const missingSets = held.filter((s) => !heldSets.includes(s));
  const overall = (id: (typeof ids)[number]): Verdict => (missingSets.length ? (judged.some((j) => j.s[key(id)].verdict === 'fail') ? 'fail' : 'not measured') : combine(judged.map((j) => j.s[key(id)].verdict)));

  const restated = regression.map((n) => ({ ...n, set: setOf(n.report), ...restateFrozen(n.report, earlier, rules) }));
  const run5 = [...heldOut, ...regression];
  const commits = [...new Set(run5.map(({ report }) => report.commit ?? 'unknown'))];

  const sRows = [
    ...ids.map((id) => [id, RUN5_CLAIMS[id], ...held.map((s) => judged.find((j) => j.set === s)?.s[key(id)].verdict ?? 'not run'), `**${overall(id)}**`]),
    ['S5', RUN5_CLAIMS.S5, ...held.map(() => ''), `**${s5.verdict}**`],
    ['S6', RUN5_CLAIMS.S6, ...held.map(() => ''), `**${s6.verdict}**`],
  ];

  const regressionRows = restated.flatMap(({ report, set }) => report.models.flatMap(({ id }) => WAYFINDER_CONDITIONS.filter((c) => cellOf(report.results, c, id).length).map((c) => {
    const now = cellOf(report.results, c, id);
    const before = earlier.filter((e) => e.report.question_set.sha256 === report.question_set.sha256 && cellOf(e.report.results, c, id).length)
      .sort((a, b) => b.report.date.localeCompare(a.report.date))[0];
    const was = before && cellOf(before.report.results, c, id);
    return [set, id, c, was ? `${scored(was)} (${sourceOf(before.report)})` : 'not run', scored(now), was ? signed(accuracy(now) - accuracy(was)) : '', String(now.filter((r) => r.hit_turn_cap).length)];
  })));

  return [
    '# Wayfinder benchmark run 5: summary',
    `Run 5 is the first run whose claims rest on questions no product fix was tuned on: ${held.length === 1 ? 'one held-out set' : `${['no', 'one', 'two', 'three', 'four'][held.length] ?? held.length} held-out sets`}, ${held.join(' and ')}, with every condition, both models, ${RUN5_RULES.repetitions} repetitions of every cell and ${RUN5_RULES.maxTurns} model calls per question. ` +
      `Every claim reads a 95% interval, not a point estimate. Plugin and harness at ${commits.map((c) => `\`${c.slice(0, 12)}\``).join(', ')}. ` +
      `${int(sum(run5.map(({ report }) => report.totals.question_runs)))} question runs, ${usd2(sum(run5.map(({ report }) => report.totals.cost_usd)))} in agent calls and ${usd2(sum(run5.map(({ report }) => report.totals.judge_cost_usd)))} for the judge. ` +
      'Every figure below is recomputed from the reports by `src/run5.ts`; nothing is picked by hand.',
    '## Hypotheses S1 to S6',
    'As the spec words them, approved on 2026-10-04 before any run 5 data. S1 to S4 hold only where they hold on each held-out set. S5 reads every site a run read the manual on. S6 reads the find evals, which make no model call.',
    table(['', 'Claim', ...held, 'Run 5'], sRows),
    '### Numbers',
    ...judged.map(({ set, s }) => `#### ${set}\n\n` + table(['', 'Result', 'Numbers'], ids.map((id) => [id, s[key(id)].verdict, escapeCell(s[key(id)].numbers)]))),
    `#### S5 and S6\n\n` + table(['', 'Result', 'Numbers'], [s5, s6].map((h) => [h.id, h.verdict, escapeCell(h.numbers)])),
    `Beside S5: ${SMOKE_FINDINGS.s5.text}. Filed from the smoke runs and not fixed before this run.`,
    ...(missingSets.length ? [`No run 5 report for ${missingSets.join(', ')}, so S1 to S4 cannot pass yet.`] : []),
    '### How to read them',
    [
      '- **S1** reads the lower bound of the manual cell\'s 95% interval, so a cell can score above the threshold and still fail it.',
      '- **S2** needs the paired interval of the difference above zero: the same agent, with and without the map, question by question.',
      `- **S3** is non-inferiority. Passing it means Haiku with the map trails Sonnet's best baseline by no more than ${RUN5_RULES.s3.marginPoints} points at the interval's lower bound, not that it beats Sonnet. Sonnet's best baseline is the highest pooled accuracy of raw, adapter and cli on that set, chosen after the run; a tie is judged against each tied condition.`,
      '- **S4** reads the lower bound of the cost per correct answer ratio, baseline over challenger.',
      '- **S6** pools the held-out sets\' edit-location questions, the ones that carry find inputs in the question itself. Each set\'s own figure is in its numbers, and the frozen sets\' find evals are listed below as context.',
    ].join('\n'),
    ...judged.flatMap(({ set, report, iv }) => [
      `## The ${set} set`,
      `${report.question_set.count} questions, sha256 \`${report.question_set.sha256}\`, on the ${report.fixture ?? report.site} site, at ${report.max_turns ?? 12} turns. ${int(report.totals.question_runs)} question runs, ${usd2(report.totals.cost_usd)} in agent calls.` +
        (report.manual ? ` Manual: brief ${int(report.manual.brief_est_tokens)} and index ${int(report.manual.index_est_tokens)} est. tokens.` : '') +
        (report.adapter_endpoint ? ` The adapter cells used the site's MCP server at \`${report.adapter_endpoint}\`.` : '') +
        (report.adapter_tools ? ` It listed ${report.adapter_tools.length} tools, none of them Wayfinder's (the run stops before any question if one is): ${report.adapter_tools.map((t) => `\`${t}\``).join(', ')}.` : ''),
      '### Accuracy by cell',
      cellTable(report, iv),
      '### Accuracy by category',
      categoryTable(report),
      '### Most missed',
      report.most_missed.length
        ? table(['Condition', 'Question', 'Category', 'Missed', 'Reason'], report.most_missed.slice(0, 12).map((m) => [m.condition, m.question, m.category, `${m.missed}/${m.runs}`, escapeCell(m.reason)]))
        : 'None.',
      ...(frozenSets()[set].el01Finding ? [`Beside el-01: ${SMOKE_FINDINGS.el01.text}. Filed from the smoke runs and not fixed before this run. el-01 in this run: ${((rs) => (rs.length ? scored(rs) : 'not asked'))(report.results.filter((r) => r.question === 'el-01'))}.`] : []),
      '### Losses',
      lossesSection(report.losses ?? []),
      nested(renderIntervals(iv)),
    ]),
    '## Find eval',
    'No model and no sampling: each edit-location question\'s find inputs go to the find route, and the answer key\'s location is checked at hit 1 and at every hit (`src/findEval.ts`).',
    findEvals.length
      ? table(['Set', 'Held out', 'Questions', 'Top-1', 'Any hit', 'Report', 'Commit'], findEvals.map(({ file, report }) => [
        setOf(report), frozenSets()[setOf(report)].heldOut ? 'yes' : 'no, seen', String(report.totals.questions),
        `${report.totals.top1} (${pct(report.totals.top1 / report.totals.questions)})`, `${report.totals.any_hit} (${pct(report.totals.any_hit / report.totals.questions)})`,
        `\`${file}\``, report.commit ? `\`${report.commit.slice(0, 12)}\`` : 'unknown',
      ]))
      : 'No find eval report was given.',
    '## Frozen sets: regression, seen questions',
    'The block, classic and edit-safety sets were seen by every run before this one, and product fixes were made from their misses, so nothing here is evidence for S1 to S6. Run 5 asks them again on the Wayfinder cells only, one repetition. ' +
      'Since run 4 the block site gained the edit-safety-2 rows (three pages and a synced pattern), the bench\'s find tool takes a class and has a longer description, the judge confirms the values a reply names, and the plugin changed. So a change below is not the plugin alone.',
    '### Wayfinder cells against the last run that had them',
    regressionRows.length
      ? table(['Set', 'Model', 'Condition', 'Before', 'Run 5', 'Change (points)', 'Turn cap hits, run 5'], regressionRows)
      : 'No regression run was given.',
    '### R1 to R7 against the best earlier baseline',
    'Run 5 runs no baseline cell on these sets. Each model\'s baseline is the best raw, adapter or cli cell any earlier run recorded on the same question set (highest pooled accuracy, a tie going to fewer input tokens), judged by `judgeRun3` unchanged with that cell in place of adapter-only. ' +
      'The baseline cells come from other runs at other caps, so the token and cost rules (R5 to R7) compare run 5\'s Wayfinder cells with those runs\' baselines. Labelled seen questions.',
    restated.length
      ? table(['Set', 'Model', 'Best earlier baseline', 'Accuracy', 'From'], restated.flatMap(({ set, report, best }) => report.models.map(({ id }) => {
        const b = best[id];
        return [set, id, b ? `${b.best.condition}${b.tied ? ' (tied)' : ''}` : 'none recorded', b ? scored(b.best.rs) : '', b ? `\`${b.best.file}\` (${sourceOf(b.best.report)})` : ''];
      })))
      : '',
    restated.length
      ? table(['', 'Claim', ...restated.map((r) => r.set), 'Frozen sets'], Object.keys(RUN3_CLAIMS).map((id) => {
        const per = restated.map((r) => r.hypotheses.find((h) => h.id === id)?.verdict);
        return [id, RUN3_CLAIMS[id], ...per.map((v) => v ?? 'missing'), `**${combine(per)}**`];
      }))
      : '',
    ...restated.map(({ set, hypotheses }) => `#### ${set}\n\n` + table(['', 'Result', 'Numbers'], hypotheses.map((h) => [h.id, h.verdict, escapeCell(h.numbers)]))),
    '## Transcripts',
    `- **${RUN5_TRANSCRIPTS.public.holds}:** release \`${RUN5_TRANSCRIPTS.public.release}\` on ${RUN5_TRANSCRIPTS.public.repo}.` +
      (privateRun5.transcripts
        ? `\n- **${privateRun5.transcripts.holds}:** release \`${privateRun5.transcripts.release}\` on the private ${privateRun5.transcripts.repo}, beside its snapshot. Its cli cells read files from private plugins and a private theme, so the full exchanges stay private.`
        : ''),
    '## Reports',
    table(['Set', 'Role', 'Report', 'Question runs', 'Correct', 'Agent cost', 'Turns', 'Runner', 'Commit', 'Question set sha256', 'Frozen value'], [
      ...heldOut.map((n) => ({ ...n, role: 'held out' })), ...regression.map((n) => ({ ...n, role: 'regression' })), ...earlier.map((n) => ({ ...n, role: 'earlier baseline' })),
    ].map(({ file, report, role }) => [
      setOf(report), role, `\`${file}\``, String(report.totals.question_runs), String(report.totals.correct), usd2(report.totals.cost_usd), String(report.max_turns ?? 12),
      report.runner.where, report.commit ? `\`${report.commit.slice(0, 12)}\`` : 'unknown', `\`${report.question_set.sha256}\``, 'equal',
    ])),
    'Every question set sha256 above equals the value it was frozen at (`frozenSets()` in `src/run5.ts`, the same values the question-set tests pin); the summary refuses to render otherwise.',
  ].filter((block) => block !== '').join('\n\n').concat('\n');
}
