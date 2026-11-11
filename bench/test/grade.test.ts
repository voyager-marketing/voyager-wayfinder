import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { extractAnswer, grade, normalize } from '../src/grade.ts';
import { loadQuestions, type Question } from '../src/questions.ts';
import { BENCH_DIR } from '../src/run.ts';
import type { Json } from '../src/truth.ts';
import { fakeClient, text } from './helpers.ts';

const truth: Json = {
  site: { title: 'Riverbend Plumbing' },
  post_types: [{ slug: 'service', edit_path: 'edit.php?post_type=service' }, { slug: 'team_member', edit_path: 'edit.php?post_type=team_member' }],
  counts: { services: 6 },
  keys: [{ key: 'address', used_in: ['part:footer'] }, { key: 'hours', used_in: [] }, { key: 'phone', used_in: ['part:footer'] }],
  options: [{ id: 'opt:permalink_structure', value: '/%postname%/' }, { id: 'opt:show_on_front', value: 'page' }],
};
const q = (type: Question['answer']['type'], refs: string[]): Question => ({
  id: 'xx-01',
  category: 'content_model',
  prompt: 'A test question?',
  answer: { type, refs, rubric: 'test rubric' },
});
const noJudge = { ...fakeClient(text('unused')), model: 'judge', params: {} };

describe('graders', () => {
  it('reads the last ANSWER line and normalizes case, quotes, hyphens and a trailing period', () => {
    expect(extractAnswer('Thinking.\nANSWER: first\nMore.\nanswer: `Second`.')).toBe('`Second`.');
    expect(normalize(' `Team-Member`. ')).toBe(normalize('team_member'));
  });

  it('exact compares the answer line to one expected value', async () => {
    const exact = q('exact', ['site.title']);
    expect((await grade(exact, 'It is Riverbend Plumbing.\nANSWER: riverbend plumbing.', truth, noJudge)).correct).toBe(true);
    expect((await grade(exact, 'ANSWER: Riverbend Plumbing Co', truth, noJudge)).correct).toBe(false);
  });

  it('set ignores order and fails on a missing or extra item', async () => {
    const set = q('set', ['post_types[*].slug']);
    expect((await grade(set, 'ANSWER: team_member, service', truth, noJudge)).correct).toBe(true);
    expect((await grade(set, 'ANSWER: service', truth, noJudge)).correct).toBe(false);
    expect((await grade(set, 'ANSWER: service, team_member, page', truth, noJudge)).correct).toBe(false);
  });

  it('exact accepts the value standing as a whole token with a label or qualifier', async () => {
    const permalink = q('exact', ['options[id=opt:permalink_structure].value']);
    const qualified = await grade(permalink, 'ANSWER: Post name (/%postname%/), set at options-permalink.php', truth, noJudge);
    expect(qualified).toMatchObject({ correct: true, reason: 'matches, with a label or qualifier' });
    // An id= selector names the subject, so another option's value ("page") is not a rival.
    expect((await grade(permalink, 'ANSWER: /%postname%/ (Settings > Permalinks page)', truth, noJudge)).correct).toBe(true);
    expect((await grade(permalink, 'ANSWER: /%postname%/blog/', truth, noJudge)).correct).toBe(false);
    expect((await grade(q('exact', ['site.title']), 'ANSWER: Site title: "Riverbend Plumbing"', truth, noJudge)).correct).toBe(true);
  });

  it('exact refuses an answer that also names a rival candidate or another number', async () => {
    const phone = q('exact', ['keys[key=phone].key']);
    expect((await grade(phone, 'ANSWER: the phone key, bound in the footer', truth, noJudge)).correct).toBe(true);
    expect((await grade(phone, 'ANSWER: phone (or hours)', truth, noJudge)).correct).toBe(false);
    expect((await grade(phone, 'ANSWER: phones', truth, noJudge)).correct).toBe(false);
    const count = q('exact', ['counts.services']);
    expect((await grade(count, 'ANSWER: 6 published services', truth, noJudge)).correct).toBe(true);
    expect((await grade(count, 'ANSWER: 6-20 service pages (6 published)', truth, noJudge)).correct).toBe(false);
    expect((await grade(count, 'ANSWER: 1-6', truth, noJudge)).correct).toBe(false);
  });

  describe('hedged block path', () => {
    // Made-up anchors. The hedges (2.1, 4) are no anchor's current path, so no rival from truth refuses them.
    const anchors: Json = {
      edit_safety: {
        anchors: [
          { key: 'moved-heading', paths_now: ['2.1.0'] },
          { key: 'price-line', paths_now: ['5'] },
          { key: 'nested-button', paths_now: ['3.2'] },
        ],
      },
    };
    const path = (key: string) => q('exact', [`edit_safety.anchors[key=${key}].paths_now`]);

    it('is wrong when the path is hedged with another path', async () => {
      expect((await grade(path('moved-heading'), 'ANSWER: 2.1.0 or 2.1', anchors, noJudge)).correct).toBe(false);
    });

    it('is right when the path stands alone', async () => {
      expect(await grade(path('moved-heading'), 'ANSWER: 2.1.0', anchors, noJudge)).toMatchObject({ correct: true, reason: 'matches' });
    });

    it('is right with a qualifier that names no number', async () => {
      const qualified = await grade(path('moved-heading'), 'ANSWER: 2.1.0 (the second column)', anchors, noJudge);
      expect(qualified).toMatchObject({ correct: true, reason: 'matches, with a label or qualifier' });
    });

    it('is still wrong for a whole number hedged with another', async () => {
      expect((await grade(path('price-line'), 'ANSWER: 5 or 4', anchors, noJudge)).correct).toBe(false);
    });

    it('is wrong for a longer path, which names a block inside the expected one', async () => {
      expect((await grade(path('nested-button'), 'ANSWER: 3.2.1', anchors, noJudge)).correct).toBe(false);
    });
  });

  it('set accepts labelled and qualified items, one expected value per item', async () => {
    const footer = q('set', ['keys[used_in=part:footer].key']);
    const labelled = await grade(footer, 'ANSWER: business-info.address, business-info.phone', truth, noJudge);
    expect(labelled).toMatchObject({ correct: true, reason: 'same set, with labels or qualifiers' });
    expect((await grade(footer, 'ANSWER: Address: address, Phone: "phone"', truth, noJudge)).correct).toBe(true);
    expect((await grade(footer, 'ANSWER: address, phone, hours', truth, noJudge)).correct).toBe(false);
    expect((await grade(footer, 'ANSWER: address, phone or hours', truth, noJudge)).correct).toBe(false);
    expect((await grade(footer, 'ANSWER: address and phone', truth, noJudge)).correct).toBe(false);
    const slugs = q('set', ['post_types[*].slug']);
    expect((await grade(slugs, 'ANSWER: service/archive, team_member', truth, noJudge)).correct).toBe(false);
  });

  it('contains_all needs every expected value somewhere in the reply', async () => {
    const all = q('contains_all', ['post_types[*].edit_path']);
    const both = 'Services: /wp-admin/edit.php?post_type=service and team: edit.php?post_type=team_member\nANSWER: see above';
    expect((await grade(all, both, truth, noJudge)).correct).toBe(true);
    const one = await grade(all, 'ANSWER: edit.php?post_type=service', truth, noJudge);
    expect(one).toMatchObject({ correct: false, reason: 'missing edit.php?post_type=team_member' });
  });

  it('contains_all takes the older Site Editor URL of a menu, which redirects to the same page, and no other', async () => {
    const menus: Json = {
      navigation: [{ id: 'nav:primary', edit_path: 'site-editor.php?p=/wp_navigation/90001' }],
      templates: [{ id: 'tpl:index', edit_path: 'site-editor.php?p=/wp_template/vwf-fixture//index&canvas=edit' }],
    };
    const menu = q('contains_all', ['navigation[id=nav:primary].edit_path']);
    expect((await grade(menu, 'ANSWER: site-editor.php?p=/wp_navigation/90001', menus, noJudge)).correct).toBe(true);
    expect((await grade(menu, 'ANSWER: site-editor.php?postType=wp_navigation&postId=90001', menus, noJudge)).correct).toBe(true);
    expect((await grade(menu, 'ANSWER: site-editor.php?postType=wp_navigation&postId=90002', menus, noJudge)).correct).toBe(false);

    // The older template URL lands on the Templates list, so it is a different page.
    const template = q('contains_all', ['templates[id=tpl:index].edit_path']);
    expect((await grade(template, 'ANSWER: site-editor.php?postType=wp_template&postId=vwf-fixture//index', menus, noJudge)).correct).toBe(false);
  });

  it('judge sends the resolved facts and rubric to the judge model and reads its JSON verdict', async () => {
    const { client, calls } = fakeClient(text('{"correct": true, "reason": "states the title"}'));
    const verdict = await grade(q('judge', ['site.title']), 'ANSWER: Riverbend Plumbing', truth, { client, model: 'judge-model', params: { temperature: 0 } });
    expect(verdict).toMatchObject({ correct: true, reason: 'states the title' });
    expect(calls[0]).toMatchObject({ model: 'judge-model', temperature: 0 });
    expect(JSON.stringify(calls[0].messages)).toContain('{\\"site.title\\":\\"Riverbend Plumbing\\"}');
    expect(JSON.stringify(calls[0].messages)).toContain('test rubric');
  });

  it('judge counts a malformed verdict as incorrect', async () => {
    const j = { ...fakeClient(text('Looks right to me.')), model: 'judge-model', params: {} };
    const verdict = await grade(q('judge', ['site.title']), 'ANSWER: Riverbend Plumbing', truth, j);
    expect(verdict.correct).toBe(false);
    expect(verdict.reason).toMatch(/not the expected JSON/);
  });
});

// Run 5's answer lines, read from its reports, which keep each result's last ANSWER line. Exact and set graders
// read only that line, so these grade exactly as the run's replies would.
interface ReportRow { question: string; condition: string; model: string; rep: number; correct: boolean; reason: string; answer: string }
const HAIKU = 'claude-haiku-4-5-20251001';
const SONNET = 'claude-sonnet-5';
const run5 = (set: string, fixture: string, report: string) => {
  const read = (...path: string[]) => JSON.parse(readFileSync(join(BENCH_DIR, ...path), 'utf8'));
  const rows = (read('reports', report) as { results: ReportRow[] }).results;
  const questions = loadQuestions(join(BENCH_DIR, 'questions', set)).questions;
  return {
    truth: read('..', 'fixtures', ...fixture.split('/')) as Json,
    question: (id: string) => questions.find((x) => x.id === id) as Question,
    row: (question: string, condition: string, model: string, rep: number) =>
      rows.find((r) => r.question === question && r.condition === condition && r.model === model && r.rep === rep) as ReportRow,
  };
};

describe('a leading yes or no', () => {
  const es2 = run5('fixture-edit-safety-2.yaml', 'truth.json', 'fixture-edit-safety-2-2026-10-06-run5.json');
  // Made-up blocks: one that moves and one that does not.
  const blocks: Json = { blocks: [{ name: 'Night rate', can_move: 'yes' }, { name: 'Call-out fee', can_move: 'no' }] };
  const move = (name: string) => q('exact', [`blocks[name=${name}].can_move`]);

  it('passes "Yes, she can..." against yes, although its reason says "no lock changes" (lk-22, run 5)', async () => {
    const row = es2.row('lk-22', 'manual-preloaded', SONNET, 1);
    expect(row).toMatchObject({ correct: false, answer: expect.stringMatching(/^Yes, she can drag/) });
    expect(row.answer).toMatch(/\bno lock changes\b/);
    expect(await grade(es2.question('lk-22'), `ANSWER: ${row.answer}`, es2.truth, noJudge)).toMatchObject({ correct: true, reason: 'answers yes first' });
  });

  it('reads the first word against either expected value', async () => {
    expect((await grade(move('Night rate'), 'ANSWER: Yes. Both locks allow moving it.', blocks, noJudge)).correct).toBe(true);
    expect((await grade(move('Night rate'), 'ANSWER: No. The block is locked, so yes would be wrong.', blocks, noJudge)).correct).toBe(false);
    expect(await grade(move('Call-out fee'), 'ANSWER: No. The block is locked in place, yes.', blocks, noJudge)).toMatchObject({ correct: true, reason: 'answers no first' });
    expect((await grade(move('Call-out fee'), 'ANSWER: **No**, not without unlocking it', blocks, noJudge)).correct).toBe(true);
  });

  it('is still wrong when the first word hedges', async () => {
    expect((await grade(move('Night rate'), 'ANSWER: Yes or no, depending on the lock', blocks, noJudge)).correct).toBe(false);
    expect((await grade(move('Night rate'), 'ANSWER: yes/no', blocks, noJudge)).correct).toBe(false);
    expect((await grade(move('Night rate'), 'ANSWER: Yes and no', blocks, noJudge)).correct).toBe(false);
  });
});

describe('a value named only in a concession', () => {
  // Made-up answers in the shape of run 5's real-site df-01 misses: three said the number is typed into the page,
  // "even though" the option exists, and passed. The real-site set's own test pins those answers.
  const site: Json = { site_data: [{ key: 'phone', option: 'riverbend_site_phone' }] };
  const phone = (answer: string) => grade(q('exact', ['site_data[key=phone].option']), `ANSWER: ${answer}`, site, noJudge);

  it('fails an answer that says the number is typed, naming the option only after "though"', async () => {
    expect(await phone('Typed directly into the page, though a riverbend_site_phone option exists.')).toMatchObject({ correct: false, reason: 'expected "riverbend_site_phone", named only in a concession' });
    expect((await phone('Typed directly. It is not from a setting, even though a riverbend_site_phone option exists.')).correct).toBe(false);
  });

  it('reads only the concession: a denial written another way still names the option', async () => {
    expect((await phone('There is a riverbend_site_phone option, but nothing reads it.')).correct).toBe(true);
  });

  it('passes an answer that gives the option as the source', async () => {
    expect((await phone('It comes from a setting: the riverbend_site_phone option.')).correct).toBe(true);
    expect((await phone('riverbend_site_phone')).correct).toBe(true);
  });

  it('ends a concession that opens a sentence at its comma, and one inside a sentence at the sentence end', async () => {
    expect((await phone('Although it looks typed, it comes from the riverbend_site_phone option.')).correct).toBe(true);
    expect((await phone('It comes from the riverbend_site_phone option, even though it looks typed.')).correct).toBe(true);
    expect((await phone('Typed in the page, despite the riverbend_site_phone option.')).correct).toBe(false);
    expect((await phone('Typed in the page, even if a riverbend_site_phone option exists. Nothing reads it.')).correct).toBe(false);
  });
});
