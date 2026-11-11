import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { parse } from 'yaml';
import { z } from 'zod';
import { expectedValues, resolveRef, type Json } from './truth.ts';

export const CATEGORY_COUNTS = {
  content_model: 8,
  edit_location: 10,
  layout_routes: 8,
  data_flow: 6,
  settings_integrations: 4,
  change_impact: 4,
} as const;

/** The classic fixture's 30 questions: the same categories, scaled from 40 to 30. */
export const CLASSIC_CATEGORY_COUNTS = {
  content_model: 6,
  edit_location: 7,
  layout_routes: 6,
  data_flow: 5,
  settings_integrations: 3,
  change_impact: 3,
} as const satisfies Record<keyof typeof CATEGORY_COUNTS, number>;

/**
 * The edit-safety set's 20 questions on the block fixture, written before run 4: what a client role
 * can and cannot change and why, text with more than one home, a class or List View name to its block, and
 * whether an anchor taken before an edit still holds.
 */
export const EDIT_SAFETY_CATEGORY_COUNTS = {
  locks: 5,
  multi_home: 5,
  selectors: 5,
  stale_edit: 5,
} as const;

/**
 * The held-out edit-safety-2 set's 30 questions on the block fixture, written from the seed and
 * truth.json only, before any fix tuned on the first edit-safety set: the same four categories on rows that
 * set never asks about, and client_can, what the client role's capabilities allow, apart from locks.
 */
export const EDIT_SAFETY_2_CATEGORY_COUNTS = {
  locks: 6,
  client_can: 6,
  multi_home: 6,
  selectors: 6,
  stale_edit: 6,
} as const;

type CategoryName = keyof typeof CATEGORY_COUNTS | keyof typeof EDIT_SAFETY_CATEGORY_COUNTS | keyof typeof EDIT_SAFETY_2_CATEGORY_COUNTS;
const Category = z.enum([...new Set([...Object.keys(CATEGORY_COUNTS), ...Object.keys(EDIT_SAFETY_CATEGORY_COUNTS), ...Object.keys(EDIT_SAFETY_2_CATEGORY_COUNTS)])] as [CategoryName, ...CategoryName[]]);

export const Question = z.object({
  id: z.string().regex(/^[a-z]{2}-\d{2}$/),
  category: Category,
  prompt: z.string().min(10),
  answer: z.object({
    type: z.enum(['exact', 'set', 'contains_all', 'judge']),
    refs: z.array(z.string()).min(1),
    rubric: z.string().optional(),
  }),
  /**
   * What a person asking would have to hand for the find route: the page they saw it on, the text they saw
   * and a class their developer named. Never shown to the agent; for the find eval.
   */
  find: z.object({ url: z.string(), text: z.string().optional(), class: z.string().optional() }).strict().optional(),
  /** The real request a question on a real site was restated from: its channel and id, for the maintainers to check. */
  source: z.string().optional(),
});
export type Question = z.infer<typeof Question>;

const QuestionFile = z.object({
  site: z.string(),
  questions_sha256: z.string().regex(/^[0-9a-f]{64}$/),
  questions: z.array(Question),
});

/** JSON with object keys sorted at every level, so the hash ignores YAML key order. */
export function canonicalJson(value: unknown): string {
  if (Array.isArray(value)) return `[${value.map(canonicalJson).join(',')}]`;
  if (value && typeof value === 'object') {
    const entries = Object.entries(value).sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0));
    return `{${entries.map(([k, v]) => `${JSON.stringify(k)}:${canonicalJson(v)}`).join(',')}}`;
  }
  return JSON.stringify(value);
}

export function hashQuestions(questions: Question[]): string {
  return createHash('sha256').update(canonicalJson(questions)).digest('hex');
}

export function loadQuestions(file: string) {
  const parsed = QuestionFile.parse(parse(readFileSync(file, 'utf8')));
  return { ...parsed, computed_sha256: hashQuestions(parsed.questions) };
}

/** What a grader compares against: flat strings, or for judge the resolved value of each reference. */
export function expectedFor(question: Question, truth: Json): string[] | Record<string, Json> {
  const { type, refs } = question.answer;
  if (type !== 'judge') return expectedValues(truth, refs);
  const out: Record<string, Json> = {};
  for (const ref of refs) {
    const value = resolveRef(truth, ref);
    if (value === undefined || (Array.isArray(value) && !value.length)) {
      throw new Error(`reference resolves to nothing in truth.json: ${ref}`);
    }
    out[ref] = withOtherForm(truth, ref, value);
  }
  return out;
}

const ADMIN_FORMS = [
  { suffix: '.settings_page.menu', other: '.edit_path', label: 'admin path' },
  { suffix: '.edit_path', other: '.settings_page.menu', label: 'menu label' },
];

/**
 * An admin page has two names: its menu label and its admin path. Both name the same page,
 * so the judge sees the other one beside whichever the question references.
 */
function withOtherForm(truth: Json, ref: string, value: Json): Json {
  const form = ADMIN_FORMS.find((f) => ref.endsWith(f.suffix));
  if (!form || typeof value !== 'string') return value;
  const other = resolveRef(truth, ref.slice(0, -form.suffix.length) + form.other);
  if (typeof other !== 'string' || !other) return value;
  return `${value} (${form.label}: ${other})`;
}
