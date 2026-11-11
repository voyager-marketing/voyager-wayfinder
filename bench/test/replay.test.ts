import { describe, expect, it } from 'vitest';
import { changedGrades, replayReport, RUN5_REPORTS } from '../src/replay.ts';

// Run 5's published exact and set answers, graded again from the ANSWER line each report row keeps. The leading
// yes or no rule changes this one grade and no other; reports/run5-regrade-graders.md lists it with the controls
// (node test/replay-run5.ts). A grader change that moves another run 5 grade fails here first. The real-site set's
// report is private bench data, and its own test pins the four grades the concession rule changes there.
describe('run 5 replayed through the code graders', () => {
  it('changes exactly the grade the correction note lists', { timeout: 60_000 }, async () => {
    const runs = await Promise.all(RUN5_REPORTS.map((f) => replayReport(f)));
    const changed = runs.flatMap((r) => changedGrades(r.replayed)).map((r) => `${r.result.question} ${r.result.condition} ${r.result.model} r${r.result.rep}: ${r.now.reason}`);
    expect(changed).toEqual(['lk-22 manual-preloaded claude-sonnet-5 r1: answers yes first']);
    expect(runs.reduce((n, r) => n + r.replayed.length, 0)).toBe(1060);
  });
});
