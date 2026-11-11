import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { grade } from '../src/grade.ts';
import { loadQuestions, type Question } from '../src/questions.ts';
import { BENCH_DIR } from '../src/run.ts';
import { resolveRef, type Json } from '../src/truth.ts';
import { fakeClient, text } from './helpers.ts';

// An admin location has two correct names: its menu label and its admin path. df-01 expects the
// Business Info settings page by its label, and el-01 and el-05 expect the same page by its path, so
// every grader that reads that page has to accept both and still reject a different page.
const truth = JSON.parse(readFileSync(join(BENCH_DIR, '..', 'fixtures', 'truth.json'), 'utf8')) as Json;
const file = loadQuestions(join(BENCH_DIR, 'questions', 'fixture.yaml'));
const questions = new Map(file.questions.map((q) => [q.id, q]));
const question = (id: string): Question => questions.get(id) ?? (() => { throw new Error(`no question ${id}`); })();
const noJudge = { ...fakeClient(text('unused')), model: 'judge', params: {} };

const PATH = 'options-general.php?page=vwf-business-info';
const LABEL = 'Settings > Business Info';
const RULE =
  'An admin location given by its menu label (Settings > Reading), its admin path (options-reading.php), or both is the same answer, whichever form EXPECTED uses, provided it names the same page. A label or path that leads to a different page is wrong.';

describe('admin locations by menu label or admin path', () => {
  it('reads both names of the Business Info page from truth', () => {
    expect(resolveRef(truth, 'binding_sources[id=vwf-fixture/business-info].edit_path')).toBe(PATH);
    expect(resolveRef(truth, 'binding_sources[id=vwf-fixture/business-info].settings_page.menu')).toBe(LABEL);
  });

  it('tells the judge both forms name the same page, on df-01', async () => {
    const { client, calls } = fakeClient(text('{"correct": true, "reason": "names the source, key, option and page"}'));
    const reply = `ANSWER: block binding vwf-fixture/business-info, key phone, reads vwf_phone, edited at ${PATH}`;
    await grade(question('df-01'), reply, truth, { client, model: 'judge', params: {} });
    expect(calls[0].system).toContain(RULE);
    expect(JSON.stringify(calls[0].messages)).toContain(LABEL);
  });

  it('judge sees an admin location in both forms', async () => {
    // Haiku faulted two run 2 answers for giving the path when EXPECTED held only the label, so
    // EXPECTED carries both forms and the judge no longer has to apply the rule itself.
    const { client, calls } = fakeClient(text('{"correct": true, "reason": "ok"}'));
    await grade(question('df-01'), `ANSWER: vwf-fixture/business-info reads vwf_phone, edited at ${PATH}`, truth, { client, model: 'judge', params: {} });
    const expected = JSON.parse(/^EXPECTED[^:]*: (.*)$/m.exec(String(calls[0].messages[0].content))?.[1] ?? '{}') as Record<string, string>;
    expect(expected['binding_sources[id=vwf-fixture/business-info].settings_page.menu']).toBe(
      `${LABEL} (admin path: ${PATH})`,
    );
    expect(expected['binding_sources[id=vwf-fixture/business-info].keys[key=phone].option']).toBe('vwf_phone');

    // The reverse: a judge question that references the path also gets the label.
    const byPath = fakeClient(text('{"correct": true, "reason": "ok"}'));
    const pathQuestion: Question = { ...question('df-01'), answer: { ...question('df-01').answer, refs: ['binding_sources[id=vwf-fixture/business-info].edit_path'] } };
    await grade(pathQuestion, `ANSWER: ${LABEL}`, truth, { client: byPath.client, model: 'judge', params: {} });
    expect(JSON.stringify(byPath.calls[0].messages)).toContain(`${PATH} (menu label: ${LABEL})`);
  });

  it('finds el-01, el-05, el-06 and el-09 as the only non-judge questions whose admin path has a menu label', () => {
    const hasLabel = (r: string) => ['settings_page.menu', 'menu_path'].some((key) => typeof resolveRef(truth, r.replace(/edit_path$/, key)) === 'string');
    const labelled = file.questions.filter((q) => q.answer.type !== 'judge' && q.answer.refs.some((r) => r.endsWith('.edit_path') && hasLabel(r)));
    // el-02 and el-07 stay path-only: their only labels are one word ("Services", "Team") that any reply would contain.
    expect(labelled.map((q) => q.id)).toEqual(['el-01', 'el-05', 'el-06', 'el-09']);
  });

  it('el-06 takes the Site Editor menu path with the menu title', async () => {
    const el06 = question('el-06');
    expect(resolveRef(truth, 'navigation[id=nav:primary].menu_path')).toBe('Appearance > Editor > Navigation');
    // Run 2, raw, Sonnet 5, rep 2: graded wrong before, for a percent-encoded `/navigation/` URL.
    const run2 =
      'ANSWER: Appearance > Editor > Navigation, edit the "Primary" menu (site-editor.php?p=%2Fnavigation%2F90001), which is referenced by the Header template part (site-editor.php?postType=wp_template_part&postId=vwf-fixture%2F%2Fheader)';
    expect(await grade(el06, run2, truth, noJudge)).toMatchObject({ correct: true, reason: 'contains all, Appearance > Editor > Navigation (Primary) by its menu label' });
    expect((await grade(el06, 'ANSWER: Appearance → Editor → Navigation → Primary', truth, noJudge)).correct).toBe(true);
    // Every menu shares the path, so it needs the right menu's title.
    expect((await grade(el06, 'ANSWER: Appearance > Editor > Navigation', truth, noJudge)).correct).toBe(false);
    expect((await grade(el06, 'ANSWER: Appearance > Editor > Navigation, the "Footer" menu', truth, noJudge)).correct).toBe(false);
    expect((await grade(el06, 'ANSWER: Appearance > Menus, the "Primary" menu', truth, noJudge)).correct).toBe(false);
  });

  it('el-09 takes the taxonomy by its menu path, and el-02 and el-07 stay path-only', async () => {
    const el09 = question('el-09');
    expect(resolveRef(truth, 'taxonomies[id=tax:service_area].menu_path')).toBe('Services > Service areas');
    expect(await grade(el09, 'ANSWER: Services > Service areas', truth, noJudge)).toMatchObject({ correct: true, reason: 'contains all, Services > Service areas by its menu label' });
    expect((await grade(el09, 'ANSWER: /wp-admin/edit-tags.php?taxonomy=service_area&post_type=service', truth, noJudge)).correct).toBe(true);
    for (const wrong of ['Posts > Service areas', 'Services > Categories', 'Services']) {
      expect((await grade(el09, `ANSWER: ${wrong}`, truth, noJudge)).correct, wrong).toBe(false);
    }
    expect((await grade(question('el-02'), 'ANSWER: Services', truth, noJudge)).correct).toBe(false);
    expect((await grade(question('el-07'), 'ANSWER: Team', truth, noJudge)).correct).toBe(false);
  });

  for (const id of ['el-01', 'el-05']) {
    it(`${id} accepts the page by its admin path, its menu label or both`, async () => {
      const q = question(id);
      const byPath = await grade(q, `ANSWER: /wp-admin/${PATH}`, truth, noJudge);
      expect(byPath).toMatchObject({ correct: true, reason: 'contains all' });
      const byLabel = await grade(q, `ANSWER: ${LABEL}`, truth, noJudge);
      expect(byLabel).toMatchObject({ correct: true, reason: 'contains all, Settings > Business Info by its menu label' });
      expect((await grade(q, `ANSWER: ${LABEL} (${PATH})`, truth, noJudge)).correct).toBe(true);
      expect((await grade(q, 'Open **Settings → Business Info** and edit the field there.\nANSWER: Settings → Business Info', truth, noJudge)).correct).toBe(true);
      expect((await grade(q, 'ANSWER: settings / business info, then save', truth, noJudge)).correct).toBe(true);
    });

    it(`${id} rejects a label or path for a different page`, async () => {
      const q = question(id);
      for (const wrong of ['Settings > General', 'Tools > Business Info', 'Settings > Business Information', 'options-general.php', 'options-general.php?page=vwf-business-hours']) {
        expect((await grade(q, `ANSWER: ${wrong}`, truth, noJudge)).correct, wrong).toBe(false);
      }
      expect(await grade(q, 'ANSWER: Settings > General', truth, noJudge)).toMatchObject({ reason: `missing ${PATH}` });
    });
  }

  it('leaves the questions, the answer key and the question-set hash unchanged', () => {
    expect(file.computed_sha256).toBe(file.questions_sha256);
    expect(file.questions_sha256).toBe('e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b');
  });
});
