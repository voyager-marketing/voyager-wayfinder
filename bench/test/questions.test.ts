import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { CATEGORY_COUNTS, expectedFor, loadQuestions } from '../src/questions.ts';
import { BENCH_DIR } from '../src/run.ts';
import { expectedValues, resolveRef, type Json } from '../src/truth.ts';

const truth = JSON.parse(readFileSync(join(BENCH_DIR, '..', 'fixtures', 'truth.json'), 'utf8')) as Json;
const file = loadQuestions(join(BENCH_DIR, 'questions', 'fixture.yaml'));

describe('question set', () => {
  it('has 40 questions with the agreed category counts and unique ids', () => {
    expect(file.questions).toHaveLength(40);
    const counts: Record<string, number> = {};
    for (const q of file.questions) counts[q.category] = (counts[q.category] ?? 0) + 1;
    expect(counts).toEqual(CATEGORY_COUNTS);
    expect(new Set(file.questions.map((q) => q.id)).size).toBe(40);
  });

  it('resolves every expected value to a truth.json key', () => {
    for (const q of file.questions) {
      expect(() => expectedFor(q, truth), q.id).not.toThrow();
      if (q.answer.type === 'exact') expect(expectedFor(q, truth), q.id).toHaveLength(1);
      if (q.answer.type === 'judge') expect(q.answer.rubric, q.id).toBeTruthy();
    }
  });

  it('records the sha256 of the question set in the header', () => {
    expect(file.questions_sha256).toBe(file.computed_sha256);
  });
});

describe('truth references', () => {
  it('reads one value, fans out with [*], and matches array fields by membership', () => {
    expect(resolveRef(truth, 'site.title')).toBe('Riverbend Plumbing');
    expect(resolveRef(truth, 'counts.pt:service')).toBe(6);
    expect(resolveRef(truth, 'post_types[*].slug')).toEqual(['service', 'team_member']);
    expect(resolveRef(truth, 'template_parts[refs=bind:vwf-fixture/business-info.phone].slug')).toBe('footer');
    expect(resolveRef(truth, 'binding_sources[*].keys[used_in=part:footer].key')).toEqual(['address', 'phone']);
  });

  it('refuses a reference that names nothing', () => {
    expect(resolveRef(truth, 'site.tagline')).toBeUndefined();
    expect(() => expectedValues(truth, ['post_types[id=pt:nope].slug'])).toThrow(/resolves to nothing/);
    expect(() => expectedValues(truth, ['site'])).toThrow(/object/);
  });
});
