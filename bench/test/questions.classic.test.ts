import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { grade } from '../src/grade.ts';
import { CATEGORY_COUNTS, CLASSIC_CATEGORY_COUNTS, expectedFor, loadQuestions } from '../src/questions.ts';
import { BENCH_DIR, loadConfig, loadContext, runBench } from '../src/run.ts';
import { seedFixture, type Spawn } from '../src/seed.ts';
import { resolveRef, type Json } from '../src/truth.ts';
import { fakeClient, text } from './helpers.ts';

// Both sets are frozen. A question edit changes its hash, so it fails here even when the header is updated with it.
const CLASSIC_SHA256 = '4511d7c994aabf5bd16fe64a880ef7dd06bebc2202ced544d78a30bd3b02f9ce';
const BLOCK_SHA256 = 'e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b';

const truth = JSON.parse(readFileSync(join(BENCH_DIR, '..', 'fixtures', 'classic', 'truth.json'), 'utf8')) as Json;
const file = loadQuestions(join(BENCH_DIR, 'questions', 'fixture-classic.yaml'));
const noJudge = { ...fakeClient(text('unused')), model: 'judge', params: {} };

afterEach(() => vi.unstubAllGlobals());

describe('classic question set', () => {
  it('has 30 questions in the block set categories, with the agreed counts and unique ids', () => {
    expect(file.questions).toHaveLength(30);
    expect(Object.keys(CLASSIC_CATEGORY_COUNTS)).toEqual(Object.keys(CATEGORY_COUNTS));
    const counts: Record<string, number> = {};
    for (const q of file.questions) counts[q.category] = (counts[q.category] ?? 0) + 1;
    expect(counts).toEqual(CLASSIC_CATEGORY_COUNTS);
    expect(new Set(file.questions.map((q) => q.id)).size).toBe(30);
  });

  it('resolves every expected value to a key in fixtures/classic/truth.json', () => {
    for (const q of file.questions) {
      expect(() => expectedFor(q, truth), q.id).not.toThrow();
      if (q.answer.type === 'exact') expect(expectedFor(q, truth), q.id).toHaveLength(1);
      if (q.answer.type === 'judge') expect(q.answer.rubric, q.id).toBeTruthy();
    }
  });

  it('is frozen: the header carries the sha256 of the questions, and it is the pinned one', () => {
    expect(file.site).toBe('fixture-classic');
    expect(file.questions_sha256).toBe(file.computed_sha256);
    expect(file.computed_sha256).toBe(CLASSIC_SHA256);
  });

  it('grades a reply built from the expected values as correct, for every question graded in code', async () => {
    for (const q of file.questions.filter((question) => question.answer.type !== 'judge')) {
      const expected = expectedFor(q, truth) as string[];
      const reply = q.answer.type === 'contains_all' ? `It is ${expected.join(' and ')}.\nANSWER: ${expected.join(', ')}` : `ANSWER: ${expected.join(', ')}`;
      const verdict = await grade(q, reply, truth, noJudge);
      expect(verdict.correct, `${q.id}: ${verdict.reason}`).toBe(true);
    }
  });

  it('asks about the classic site, not the block one', () => {
    expect(resolveRef(truth, 'site.title')).toBe('Ashford Remodeling');
    expect(resolveRef(truth, 'site.parent_theme')).toBe('twentytwentyone');
    expect(resolveRef(truth, 'plugins[*].slug')).toEqual(['mcp-adapter', 'secure-custom-fields', 'voyager-wayfinder']);
    expect(resolveRef(truth, 'post_types[*].slug')).toEqual(['project']);
    expect(resolveRef(truth, 'options_pages[id=optpage:company-settings].fields[on_front_end=false].key')).toBe('company_email');
  });
});

describe('fixtures in config.json', () => {
  it('keeps the block fixture as the default, with its 40 questions and their hash', () => {
    const { fixture, qs } = loadContext();
    expect(fixture).toMatchObject({ name: 'block', site: 'fixture', questions: 'questions/fixture.yaml', truth: '../fixtures/truth.json' });
    expect(qs.questions).toHaveLength(40);
    expect(qs.questions_sha256).toBe(BLOCK_SHA256);
  });

  it('loads the classic fixture by name, with its own site, questions and truth', () => {
    const { fixture, qs, truth: loaded } = loadContext('classic');
    expect(fixture).toMatchObject({ name: 'classic', site: 'fixture-classic', seed: 'wp-content/plugins/voyager-wayfinder/fixtures/classic/seed.php' });
    expect(qs.questions_sha256).toBe(CLASSIC_SHA256);
    expect(loaded).toEqual(truth);
  });

  it('refuses a fixture config.json does not name', () => {
    expect(() => loadConfig('nope')).toThrow(/no fixture "nope"/);
  });

  it('does not replay the block recordings against the classic questions', async () => {
    await expect(runBench({ fixture: 'classic', dryRun: true })).rejects.toThrow(/no recordings for the classic fixture/);
  });
});

describe('seeding', () => {
  const recorder = (status: number) => {
    const calls: { command: string; args: string[]; cwd: unknown }[] = [];
    const spawn: Spawn = (command, args, options) => {
      calls.push({ command, args, cwd: options.cwd });
      return { status, error: undefined };
    };
    return { calls, spawn };
  };

  it('runs the seed through the pinned wp-env from the repository root', () => {
    const { calls, spawn } = recorder(0);
    seedFixture('wp-content/plugins/voyager-wayfinder/fixtures/classic/seed.php', '/repo', spawn);
    expect(calls).toEqual([{ command: 'npx', args: ['@wordpress/env', 'run', 'cli', 'wp', 'eval-file', 'wp-content/plugins/voyager-wayfinder/fixtures/classic/seed.php'], cwd: '/repo' }]);
  });

  it('seeds the chosen fixture before a live run touches the site, and stops when the seed fails', async () => {
    vi.stubGlobal('fetch', () => Promise.reject(new Error('network call before the seed')));
    const { calls, spawn } = recorder(1);
    await expect(runBench({ fixture: 'classic', seed: true, spawn })).rejects.toThrow(/seeding failed/);
    expect(calls).toHaveLength(1);
    expect(calls[0].args.at(-1)).toBe('wp-content/plugins/voyager-wayfinder/fixtures/classic/seed.php');
    expect(calls[0].cwd).toBe(join(BENCH_DIR, '..'));
  });
});
