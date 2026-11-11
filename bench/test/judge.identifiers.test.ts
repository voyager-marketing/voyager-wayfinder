import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { grade } from '../src/grade.ts';
import { loadQuestions, type Question } from '../src/questions.ts';
import { BENCH_DIR } from '../src/run.ts';
import type { Json } from '../src/truth.ts';
import { fakeClient, text } from './helpers.ts';

// A judge question's identifier facts are checked in code before the judge is called. In run 2 Haiku
// passed df-01 answers that never wrote the vwf_phone option. The ten df-01 replies are pinned from the
// run's transcripts, because the report keeps only the last ANSWER line and the judge reads the whole reply.
const truth = JSON.parse(readFileSync(join(BENCH_DIR, '..', 'fixtures', 'truth.json'), 'utf8')) as Json;
const file = loadQuestions(join(BENCH_DIR, 'questions', 'fixture.yaml'));
const question = (id: string): Question => file.questions.find((q) => q.id === id) ?? (() => { throw new Error(`no question ${id}`); })();
const run2 = JSON.parse(readFileSync(join(BENCH_DIR, 'test', 'run2-df-01.json'), 'utf8')) as {
  rows: { condition: string; model: string; rep: number; run2_correct: boolean; reply: string }[];
};
const key = (r: { condition: string; model: string; rep: number }) => `${r.condition} ${r.model} r${r.rep}`;

/** Grades with a judge that passes everything, so a wrong verdict can only come from the code check. */
async function gradePassingJudge(q: Question, reply: string) {
  const { client, calls } = fakeClient(text('{"correct": true, "reason": "ok"}'));
  return { verdict: await grade(q, reply, truth, { client, model: 'judge', params: {} }), calls };
}

const PATH = 'options-general.php?page=vwf-business-info';

describe('identifier facts before the judge', () => {
  it('judge questions need every identifier fact named', async () => {
    // The four run 2 replies that never write vwf_phone are wrong without a judge call. Raw Sonnet r2
    // passed in run 2, and with the admin-path fix the judge passed raw Sonnet r3 and manual Sonnet r1 too.
    const omitted: Record<string, string> = {
      'raw claude-haiku-4-5-20251001 r1': 'missing vwf_phone, Settings > Business Info, judge not called',
      'raw claude-sonnet-5 r2': 'missing vwf_phone, judge not called',
      'raw claude-sonnet-5 r3': 'missing vwf_phone, judge not called',
      'manual claude-sonnet-5 r1': 'missing vwf_phone, judge not called',
    };
    expect(run2.rows.map(key)).toHaveLength(10);
    for (const row of run2.rows) {
      const { verdict, calls } = await gradePassingJudge(question('df-01'), row.reply);
      const reason = omitted[key(row)];
      if (reason) {
        expect(row.reply, key(row)).not.toContain('vwf_phone');
        expect(verdict, key(row)).toEqual({ correct: false, reason });
        expect(calls, key(row)).toHaveLength(0);
      } else {
        expect(verdict.correct, key(row)).toBe(true);
        expect(calls, key(row)).toHaveLength(1);
      }
    }

    // Manual Haiku r1 names the source only by its label ("Business info: phone"), and manual-preloaded
    // Haiku r2 names the settings page only by its admin path. Both reach the judge.
    const row = (k: string) => run2.rows.find((r) => key(r) === k)?.reply ?? '';
    expect(row('manual claude-haiku-4-5-20251001 r1')).not.toContain('vwf-fixture/business-info');
    expect(row('manual claude-haiku-4-5-20251001 r1')).toContain('"Business info: phone" binding');
    expect(row('manual-preloaded claude-haiku-4-5-20251001 r2')).not.toMatch(/Settings\s*>\s*Business Info/i);

    // On the other nine judge questions nothing is checked in code: an empty reply still reaches the judge.
    const others = file.questions.filter((q) => q.answer.type === 'judge' && q.id !== 'df-01');
    expect(others.map((q) => q.id)).toEqual(['cm-04', 'cm-05', 'el-03', 'el-04', 'el-08', 'lr-08', 'df-05', 'ci-01', 'ci-02']);
    for (const q of others) expect((await gradePassingJudge(q, 'ANSWER: no idea')).calls, q.id).toHaveLength(1);

    expect(file.questions_sha256).toBe('e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b');
  });

  it('takes each identifier as a whole token or by its alias', async () => {
    const df01 = question('df-01');
    const reaches = async (q: Question, reply: string) => (await gradePassingJudge(q, reply)).calls.length === 1;
    const reason = async (q: Question, reply: string) => (await gradePassingJudge(q, reply)).verdict.reason;

    expect(await reaches(df01, 'ANSWER: vwf-fixture/business-info reads `vwf_phone`, edited at Settings → Business Info')).toBe(true);
    expect(await reaches(df01, `ANSWER: bind:vwf-fixture/business-info.phone reads vwf_phone, at http://localhost:8888/wp-admin/${PATH}`)).toBe(true);
    expect(await reason(df01, `ANSWER: vwf-fixture/business-info reads vwf_phone_backup, at ${PATH}`)).toBe('missing vwf_phone, judge not called');
    expect(await reason(df01, `ANSWER: the Business information binding reads vwf_phone, at ${PATH}`)).toBe('missing vwf-fixture/business-info, judge not called');
    expect(await reason(df01, 'ANSWER: vwf-fixture/business-info reads vwf_phone, at Settings > General')).toBe('missing Settings > Business Info, judge not called');
    expect(await reason(df01, `ANSWER: vwf-fixture/business-info reads vwf_phone, at ${PATH}-old`)).toBe('missing Settings > Business Info, judge not called');

    // A slug, and a menu by its route, its older URL or its menu path beside its title.
    const judgeOn = (ref: string): Question => ({ ...df01, answer: { type: 'judge', refs: [ref] } });
    const slug = judgeOn('taxonomies[id=tax:service_area].slug');
    expect(await reaches(slug, 'ANSWER: the service_area taxonomy')).toBe(true);
    expect(await reason(slug, 'ANSWER: the service areas taxonomy')).toBe('missing service_area, judge not called');
    const menu = judgeOn('navigation[id=nav:primary].edit_path');
    expect(await reaches(menu, 'ANSWER: site-editor.php?p=/wp_navigation/90001')).toBe(true);
    expect(await reaches(menu, 'ANSWER: site-editor.php?postType=wp_navigation&postId=90001')).toBe(true);
    expect(await reaches(menu, 'ANSWER: Appearance > Editor > Navigation, the Primary menu')).toBe(true);
    expect(await reaches(menu, 'ANSWER: Appearance > Editor > Navigation, the Footer menu')).toBe(false);
  });
});
