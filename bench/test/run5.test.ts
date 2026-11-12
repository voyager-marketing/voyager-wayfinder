import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { FindEvalReport } from '../src/findEval.ts';
import { hashQuestions, loadQuestions } from '../src/questions.ts';
import { buildReport, ReportSchema, type Report, type Result } from '../src/report.ts';
import { BENCH_DIR, indexDropped, loadConfig } from '../src/run.ts';
import {
  bestEarlierBaseline, checkFrozen, frozenSets, HAIKU, heldOutSets, judgeHeldOut, judgeS5, judgeS6, PUBLIC_FROZEN_SETS, renderRun5Summary, restateFrozen, RUN5_CLAIMS, RUN5_RULES,
  SONNET, sonnetBest, usePrivateRun5,
} from '../src/run5.ts';

// Run 5's second held-out set is private bench data. A made-up one stands in for it here, whether or not
// bench/private/ is present, so these tests read the same everywhere.
const REAL = 'real-site';
const useMadeUpSet = () => usePrivateRun5(
  { [REAL]: { site: REAL, sha256: 'b'.repeat(64), heldOut: true, snapshotFixture: 'snapshot', el01Finding: true } },
  { repo: 'example/private-bench-data', release: 'bench-run5-private-transcripts', holds: 'real-site and its smoke run' },
);
useMadeUpSet();

const rules = loadConfig().config.run3_hypotheses;
const read = (file: string) => ReportSchema.parse(JSON.parse(readFileSync(join(BENCH_DIR, file), 'utf8')));
const readEval = (file: string) => FindEvalReport.parse(JSON.parse(readFileSync(join(BENCH_DIR, file), 'utf8')));
const BLOCK_RUN4 = 'reports/fixture-2026-10-04-run4.json';
const BLOCK_SENSITIVITY = 'reports/fixture-2026-10-03-run3-sensitivity.json';
const CONDITIONS = ['raw', 'adapter', 'cli', 'manual', 'manual-preloaded', 'cli-manual'];
const QUESTIONS = Array.from({ length: 20 }, (_, i) => `q-${String(i + 1).padStart(2, '0')}`);

type Outcome = (condition: string, model: string, question: string, index: number) => boolean;

/** A held-out report built by the real report code from made-up results: 20 questions, every cell, 3 repetitions. */
function heldOut(site: string, correct: Outcome, opts: { cost?: (condition: string, model: string) => number; maxTurns?: number; brief?: number; index?: number; dropped?: number } = {}): Report {
  const set = site === REAL ? REAL : 'edit-safety-2';
  const results: Result[] = CONDITIONS.flatMap((condition) => [HAIKU, SONNET].flatMap((model) => [1, 2, 3].flatMap((rep) => QUESTIONS.map((question, i) => ({
    question, category: i % 2 ? 'locks' : 'selectors', condition, model, rep, correct: correct(condition, model, question, i), reason: 'made up', answer: '',
    usage: { input_tokens: 1000, cache_creation_input_tokens: 0, cache_read_input_tokens: 0, output_tokens: 100 },
    first_call_input_tokens: 1000, tool_calls: 2, turns: 3, hit_turn_cap: false, wall_ms: 1000,
    cost_usd: opts.cost?.(condition, model) ?? 0.01, judge_cost_usd: 0,
  })))));
  const brief = opts.brief ?? 500;
  const index = opts.index ?? 3000;
  return buildReport(results, {
    format: 'wayfinder-bench-report/1', site, date: '2026-10-07', run_label: 'run5', fixture: set === REAL ? 'snapshot' : 'block', hypothesis_set: 'run3',
    commit: 'c'.repeat(40), conversation_caching: true, max_turns: opts.maxTurns ?? 24, dry_run: false, runner: { where: 'github-actions', concurrency: 6 },
    adapter_endpoint: '/wp-json/mcp/mcp-adapter-default-server', adapter_tools: ['mcp-adapter-discover-abilities', 'mcp-adapter-execute-ability', 'mcp-adapter-get-ability-info'],
    question_set: { file: `questions/${site}.yaml`, sha256: frozenSets()[set].sha256, count: QUESTIONS.length },
    pricing: { source: 'test', fetched: '2026-10-01' },
    models: [{ id: HAIKU, params: { temperature: 0 }, cache_min_tokens: 4096 }, { id: SONNET, params: {}, cache_min_tokens: 1024 }],
    manual: { brief_chars: brief * 4, index_chars: index * 4, brief_est_tokens: brief, index_est_tokens: index, index_dropped: opts.dropped ?? 0 },
  }, rules);
}

/** Wayfinder cells right on everything, baselines right on the first half, and Sonnet baselines dear. */
const strong: Outcome = (condition, _model, _q, i) => ['manual', 'manual-preloaded', 'cli-manual'].includes(condition) || i < 10;
const dearSonnet = (condition: string, model: string) => (model === SONNET && ['raw', 'adapter', 'cli'].includes(condition) ? 0.1 : 0.002);

function findEval(site: string, questions: number, top1: number, anyHit: number): { file: string; report: FindEvalReport } {
  const set = site === REAL ? REAL : site === 'fixture-edit-safety-2' ? 'edit-safety-2' : 'block';
  const t = { questions, top1, any_hit: anyHit };
  return {
    file: `reports/${site}-find-eval.json`,
    report: {
      format: 'wayfinder-find-eval/1', site, date: '2026-10-07', fixture: 'block', question_set: { file: 'q', sha256: frozenSets()[set].sha256, count: 30, find_inputs: null },
      snapshot: null, totals: { ...t, from_hits: t, from_chain: { questions: 0, top1: 0, any_hit: 0 } }, by_category: [], questions: [], skipped: [],
    },
  };
}

describe('run 5 frozen sets', () => {
  it('pins the hash each published set has today, the same one --hash-questions prints', () => {
    const where: Record<string, [string | undefined, string | undefined]> = {
      'edit-safety-2': [undefined, 'edit-safety-2'], block: ['block', undefined], classic: ['classic', undefined], 'edit-safety': [undefined, 'edit-safety'],
    };
    expect(Object.keys(PUBLIC_FROZEN_SETS).sort()).toEqual(Object.keys(where).sort());
    for (const [set, { sha256 }] of Object.entries(PUBLIC_FROZEN_SETS)) {
      const { fixture } = loadConfig(...where[set]);
      expect(hashQuestions(loadQuestions(join(BENCH_DIR, fixture.questions)).questions), set).toBe(sha256);
    }
  });

  it('holds out edit-safety-2 and the private set, published first, and refuses a private set named like a published one', () => {
    try {
      expect(heldOutSets()).toEqual(['edit-safety-2', REAL]);
      usePrivateRun5({});
      expect(heldOutSets()).toEqual(['edit-safety-2']);
      usePrivateRun5({ block: { site: 'elsewhere', sha256: 'c'.repeat(64), heldOut: true } });
      expect(() => frozenSets()).toThrow('has the name of a published set');
    } finally {
      useMadeUpSet();
    }
  });

  it('accepts the run 4 reports and refuses a changed set', () => {
    expect(checkFrozen(BLOCK_RUN4, read(BLOCK_RUN4))).toBe('block');
    const edited = { site: 'fixture-edit-safety-2', question_set: { sha256: '0'.repeat(64) } };
    expect(() => checkFrozen('x.json', edited)).toThrow('not the frozen 79b1b58a');
    expect(() => checkFrozen('x.json', { site: 'somewhere', question_set: { sha256: '' } })).toThrow('no run 5 question set');
  });

  it('carries the thresholds its claims state', () => {
    expect(RUN5_CLAIMS.S1).toContain('85% on Sonnet and 80% on Haiku');
    expect(RUN5_RULES.s1MinLower).toEqual({ [HAIKU]: 0.8, [SONNET]: 0.85 });
    expect(RUN5_CLAIMS.S3).toContain('-5 points');
    expect(RUN5_RULES.s3.marginPoints).toBe(5);
    expect(RUN5_CLAIMS.S4).toContain('5x');
    expect(RUN5_CLAIMS.S6).toContain('85%');
    expect(RUN5_RULES.s6).toEqual({ minTop1: 0.85, minAnyHit: 0.95 });
  });
});

describe('S1 to S4 on a held-out report', () => {
  it('passes all four when the map is right everywhere and Sonnet\'s baselines are dear', () => {
    const { s1, s2, s3, s4 } = judgeHeldOut(heldOut('fixture-edit-safety-2', strong, { cost: dearSonnet }), rules);
    expect([s1, s2, s3, s4].map((s) => s.verdict)).toEqual(['pass', 'pass', 'pass', 'pass']);
    expect(s2.numbers).toContain('Haiku cli-manual vs cli +50.0 points');
  });

  it('fails S1 on the lower bound when the point estimate clears the threshold', () => {
    // Haiku manual right on 17 of 20 questions: 85.0%, above 80%, with a lower bound below it.
    const report = heldOut('fixture-edit-safety-2', (c, m, q, i) => (c === 'manual' && m === HAIKU ? i < 17 : strong(c, m, q, i)), { cost: dearSonnet });
    const { s1 } = judgeHeldOut(report, rules);
    expect(s1.verdict).toBe('fail');
    expect(s1.numbers).toContain('Haiku manual 85.0%, 95% interval 70.0% to 100.0%, lower bound needs 80.0%');
  });

  it('fails S2 when the map does not lift the same agent above zero', () => {
    const report = heldOut(REAL, (c, m, q, i) => (c === 'cli-manual' && m === SONNET ? i < 10 : strong(c, m, q, i)), { cost: dearSonnet });
    const { s2 } = judgeHeldOut(report, rules);
    expect(s2.verdict).toBe('fail');
    expect(s2.numbers).toContain('Sonnet cli-manual vs cli 0.0 points (0.0 to 0.0)');
  });

  it('judges S3 and S4 against every Sonnet baseline tied for best, not the easier one', () => {
    // Haiku preloaded right on q1 to q16. Sonnet raw right on the same 16, Sonnet cli on q5 to q20: both 80%.
    const outcome: Outcome = (c, m, _q, i) => {
      if (c === 'manual-preloaded' && m === HAIKU) return i < 16;
      if (m === SONNET && c === 'raw') return i < 16;
      if (m === SONNET && c === 'cli') return i >= 4;
      if (m === SONNET && c === 'adapter') return i < 10;
      return strong(c, m, _q, i);
    };
    const report = heldOut('fixture-edit-safety-2', outcome, { cost: dearSonnet });
    expect(sonnetBest(report.intervals!)).toEqual({ conditions: ['raw', 'cli'], accuracy: 0.8 });
    const { s3 } = judgeHeldOut(report, rules);
    // Against raw alone the difference is exactly zero and would pass; against cli it spreads past -5.
    expect(s3.verdict).toBe('fail');
    expect(s3.numbers).toContain('Sonnet best (raw) 80.0% (tied at 80.0%; judged against each): 0.0 points (0.0 to 0.0), lower bound needs -5.0');
    expect(s3.numbers).toContain('Sonnet best (cli) 80.0% (tied at 80.0%; judged against each): 0.0 points (-30.0 to +30.0)');
  });

  it('fails S4 on the lower bound when the ratio itself clears 5x', () => {
    // Sonnet's baselines are right half the time at $0.02 a run: $0.04 per correct answer, against Haiku's $0.006.
    const report = heldOut('fixture-edit-safety-2', strong, { cost: (c, m) => (m === SONNET && ['raw', 'adapter', 'cli'].includes(c) ? 0.02 : 0.006) });
    const { s4 } = judgeHeldOut(report, rules);
    expect(s4.verdict).toBe('fail');
    expect(s4.numbers).toContain('$0.0060 vs Sonnet best (raw) $0.0400 (tied at 50.0%; judged against each): 6.67x (4.76x to 11.11x), lower bound needs 5.00x');
  });
});

describe('S5 and S6', () => {
  const real = { file: 'real.json', report: heldOut(REAL, strong) };
  const block = { file: 'es2.json', report: heldOut('fixture-edit-safety-2', strong) };

  it('S5 needs a real-site snapshot and a seeded fixture, each within budget', () => {
    expect(judgeS5([real, block]).verdict).toBe('pass');
    expect(judgeS5([block]).verdict).toBe('not measured');
    expect(judgeS5([block]).numbers).toContain('a real-site snapshot and a seeded fixture are both needed');
    const big = { file: 'real.json', report: heldOut(REAL, strong, { index: 4001 }) };
    const s5 = judgeS5([big, block]);
    expect(s5.verdict).toBe('fail');
    expect(s5.numbers).toContain('snapshot: brief 500, index 4,001 est. tokens');
  });

  it('S5 says when an index fits its budget only because it left items out', () => {
    const cut = { file: 'real.json', report: heldOut(REAL, strong, { index: 3996, dropped: 170 }) };
    const s5 = judgeS5([cut, block]);
    expect(s5.verdict).toBe('pass');
    expect(s5.numbers).toContain('snapshot: brief 500, index 3,996 est. tokens (2,000 and 15,984 chars), within budget only because the index left out 170 items to fit it');
    expect(s5.numbers).not.toContain('block: brief 500, index 3,000 est. tokens (2,000 and 12,000 chars), within');
  });

  it('counts the items an index left out, in the format IndexRenderer writes them', () => {
    const php = readFileSync(join(BENCH_DIR, '..', 'src', 'Render', 'Ai', 'IndexRenderer.php'), 'utf8');
    const format = php.match(/DROPPED_LINE = '([^']+)'/)?.[1];
    expect(format).toBe('+%d more %s (%s)');
    const line = (n: number, noun: string, where: string) => format!.replace('%d', String(n)).replace('%s', noun).replace('%s', where);
    const index = [
      '# wayfinder v1 index h:abc',
      'pt:service | Services',
      line(99, 'patterns in use', 'section layout, pages 2-4, 6'),
      line(23, 'fields', 'section content_model, page 3'),
      line(15, 'plugins', 'section integrations'),
      line(1, 'REST route', 'section code, page 1'),
      line(32, 'options', 'section site'),
    ].join('\n');
    expect(indexDropped(index)).toBe(170);
    expect(indexDropped('# wayfinder v1 index h:abc\npt:service | Services | 3 more fields')).toBe(0);
    // Left out by rule, not to fit the budget: fold lines and the fields of a post type with no posts.
    const folded = ['tpl: +4 unused from acme-parent (section layout)', 'field: +33 of pt:service_area, which has no posts (section content_model, pages 3-4)'];
    expect(indexDropped(['# wayfinder v1 index h:abc', ...folded].join('\n'))).toBe(0);
  });

  it('S6 pools the held-out sets and ignores the frozen sets\' evals for the verdict', () => {
    const pass = [findEval('fixture-edit-safety-2', 8, 7, 8), findEval(REAL, 12, 11, 12), findEval('fixture', 7, 0, 0)];
    const s6 = judgeS6(pass);
    expect(s6.verdict).toBe('pass');
    expect(s6.numbers).toContain('held-out sets pooled: top-1 18/20 (90.0%, needs 85.0%), any hit 20/20 (100.0%, needs 95.0%)');
    expect(judgeS6([findEval('fixture-edit-safety-2', 8, 7, 8), findEval(REAL, 12, 9, 12)]).verdict).toBe('fail');
    expect(judgeS6([findEval('fixture-edit-safety-2', 8, 8, 8)]).verdict).toBe('not measured');
  });

  it('S6 reads the committed edit-safety-2 find eval', () => {
    const real = { file: 'reports/fixture-edit-safety-2-2026-10-06-find-eval.json', report: readEval('reports/fixture-edit-safety-2-2026-10-06-find-eval.json') };
    expect(judgeS6([real]).numbers).toContain('edit-safety-2 top-1 7/8, any hit 8/8; no find eval for real-site');
  });
});

describe('R1 to R7 on the frozen sets', () => {
  const run4 = read(BLOCK_RUN4);
  const sensitivity = read(BLOCK_SENSITIVITY);
  const wayfinderOnly: Report = { ...run4, run_label: 'run5-regression', results: run4.results.filter((r) => ['manual', 'manual-preloaded'].includes(r.condition)) };

  it('takes the best baseline cell of any earlier run on the same set', () => {
    const earlier = [{ file: BLOCK_RUN4, report: run4 }, { file: BLOCK_SENSITIVITY, report: sensitivity }];
    // Haiku: sensitivity raw at 24 turns, 65.0%, beats run 4's raw 60.0% and adapter 64.2%.
    expect(bestEarlierBaseline(earlier, run4.question_set.sha256, HAIKU)).toMatchObject({ best: { file: BLOCK_SENSITIVITY, condition: 'raw' }, tied: false });
    expect(bestEarlierBaseline(earlier, '0'.repeat(64), HAIKU)).toBeUndefined();
  });

  it('breaks an accuracy tie toward fewer input tokens', () => {
    // A second report whose Haiku raw cell is run 4's Haiku adapter cell with a million more input tokens a run.
    const dear: Report = { ...run4, results: run4.results.filter((r) => r.condition === 'adapter' && r.model === HAIKU)
      .map((r) => ({ ...r, condition: 'raw', usage: { ...r.usage, input_tokens: r.usage.input_tokens + 1e6 } })) };
    const earlier = [{ file: 'dear.json', report: dear }, { file: BLOCK_RUN4, report: run4 }];
    expect(bestEarlierBaseline(earlier, run4.question_set.sha256, HAIKU)).toMatchObject({ best: { file: BLOCK_RUN4, condition: 'adapter' }, tied: true });
  });

  it('judges with judgeRun3 against it and names it', () => {
    const { hypotheses, best } = restateFrozen(wayfinderOnly, [{ file: BLOCK_RUN4, report: run4 }], rules);
    expect(best[SONNET]?.best.condition).toBe('adapter');
    const r2 = hypotheses.find((h) => h.id === 'R2')!;
    expect(r2.numbers).toContain(`${SONNET} manual 95.0% vs best baseline 87.5% (+7.5 points)`);
    expect(r2.verdict).toBe('fail');
    expect(hypotheses.map((h) => h.numbers).join(' ')).not.toContain('best-baseline');
  });
});

describe('the run 5 summary', () => {
  const es2 = { file: 'reports/fixture-edit-safety-2-2026-10-07-run5.json', report: heldOut('fixture-edit-safety-2', strong, { cost: dearSonnet }) };
  const realRun = { file: 'private/reports/real-site-2026-10-07-run5.json', report: heldOut(REAL, strong, { cost: dearSonnet }) };
  const run4 = read(BLOCK_RUN4);
  const regression = { file: 'reports/fixture-2026-10-07-run5-regression.json', report: { ...run4, run_label: 'run5-regression', results: run4.results.filter((r) => r.condition.startsWith('manual')) } };
  const evals = [findEval('fixture-edit-safety-2', 8, 8, 8), findEval(REAL, 12, 12, 12)];

  it('renders S1 to S6, the held-out sections with intervals, and the frozen sets as seen questions', () => {
    const md = renderRun5Summary({ heldOut: [es2, realRun], regression: [regression], earlier: [{ file: BLOCK_RUN4, report: run4 }], findEvals: evals }, rules);
    expect(md).toContain('95% interval');
    expect(md).toMatch(/\| S1 \| [^\n]+\| pass \| pass \| \*\*pass\*\* \|/);
    expect(md).toMatch(/\| S6 \| [^\n]+\| \*\*pass\*\* \|/);
    expect(md).toContain('## The edit-safety-2 set');
    expect(md).toContain('## The real-site set');
    expect(md).toContain('#### Paired accuracy');
    expect(md).toContain('## Frozen sets: regression, seen questions');
    expect(md).toContain(`| block | ${HAIKU} | manual | 97.5% (117/120) (run4, 12 turns) | 97.5% (117/120) | 0.0 | 3 |`);
    expect(md).toContain('two held-out sets, edit-safety-2 and real-site,');
    expect(md).toContain('Beside S5: on the real-site snapshot the index leaves out items');
    // The made-up real-site set has no el-01, so the line reads an empty cell rather than dropping out.
    expect(md).toContain('Beside el-01: a theme pattern registered in PHP');
    expect(md.match(/Beside el-01/g)).toHaveLength(1);
    expect(md).toContain('release `bench-run5-transcripts` on voyager-marketing/voyager-wayfinder');
    expect(md).toContain('release `bench-run5-private-transcripts` on the private example/private-bench-data');
    expect(md).not.toContain('missing');
    expect(md).not.toContain('NaN');
  });

  it('leaves S1 to S4 unmeasured while a held-out set has no report', () => {
    const md = renderRun5Summary({ heldOut: [es2], regression: [], earlier: [], findEvals: [] }, rules);
    expect(md).toMatch(/\| S1 \| [^\n]+\| pass \| not run \| \*\*not measured\*\* \|/);
    expect(md).toContain('No run 5 report for real-site');
  });

  it('refuses a held-out run at another cap, a frozen set passed as held out, and an edited set', () => {
    const capped = { ...es2, report: { ...es2.report, max_turns: 12 } };
    expect(() => renderRun5Summary({ heldOut: [capped], regression: [], earlier: [], findEvals: [] }, rules)).toThrow('ran at 12 turns');
    expect(() => renderRun5Summary({ heldOut: [{ file: BLOCK_RUN4, report: run4 }], regression: [], earlier: [], findEvals: [] }, rules)).toThrow('not held out');
    const edited = { ...realRun, report: { ...realRun.report, question_set: { ...realRun.report.question_set, sha256: 'f'.repeat(64) } } };
    expect(() => renderRun5Summary({ heldOut: [es2, edited], regression: [], earlier: [], findEvals: [] }, rules)).toThrow('not the frozen bbbbbbbb');
  });

  it('prints the adapter tools beside the endpoint, and refuses a run whose adapter cells were offered a Wayfinder tool', () => {
    const tools = ['example-get-post', 'example-list-posts'];
    const withTools = { ...realRun, report: { ...realRun.report, adapter_endpoint: '/wp-json/example-mcp/read', adapter_tools: tools } };
    const md = renderRun5Summary({ heldOut: [es2, withTools], regression: [], earlier: [], findEvals: [] }, rules);
    expect(md).toContain('The adapter cells used the site\'s MCP server at `/wp-json/example-mcp/read`. It listed 2 tools, none of them Wayfinder\'s');
    const leaky = { ...withTools, report: { ...withTools.report, adapter_tools: [...tools, 'voyager-wayfinder-get-brief'] } };
    expect(() => renderRun5Summary({ heldOut: [es2, leaky], regression: [], earlier: [], findEvals: [] }, rules)).toThrow('offered Wayfinder tools (voyager-wayfinder-get-brief)');
    const { adapter_tools: _, ...unrecorded } = realRun.report;
    expect(() => renderRun5Summary({ heldOut: [es2, { ...realRun, report: unrecorded }], regression: [], earlier: [], findEvals: [] }, rules)).toThrow('records no adapter_tools');
  });
});
