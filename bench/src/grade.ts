import type Anthropic from '@anthropic-ai/sdk';
import { z } from 'zod';
import type { MessagesClient, ModelParams } from './agent.ts';
import { expectedFor, type Question } from './questions.ts';
import { resolveRef, type Json } from './truth.ts';

export interface Grade {
  correct: boolean;
  reason: string;
  /** The judge call, when there was one, so its tokens are counted. */
  judge?: Anthropic.Message;
}

export interface Judge {
  client: MessagesClient;
  model: string;
  params: ModelParams;
}

/** Case, quotes, backticks, hyphens versus underscores and a trailing period never decide a grade. */
export const normalize = (s: string): string =>
  s.toLowerCase().replace(/[`"'*]/g, '').replace(/[-_]/g, ' ').replace(/\s+/g, ' ').trim().replace(/[.\s]+$/, '');

/** The text after the last `ANSWER:` line, or the whole reply when the agent left none. */
export function extractAnswer(text: string): string {
  const lines = [...text.matchAll(/^\s*ANSWER:\s*(.*)$/gim)];
  return lines.length ? lines[lines.length - 1][1] : text;
}

const splitList = (s: string): string[] =>
  s.split(/[,;\n]/).map((item) => item.replace(/^\s*and\s+/i, '')).filter((item) => normalize(item));

// A qualified or labelled answer names a value when the value stands as a whole token in it
// ("Front page: home", "business-info.phone", "Post name (/%postname%/)") and no rival does.
// Letters, digits, `_`, `-`, `/` and `%` continue a token, so a slug, path or basename is one token
// and `advanced-custom-fields/acf` does not name `advanced-custom-fields`. A dot, a space and any
// other punctuation end one. A value with spaces of its own needs punctuation or the line's edge
// around it, because a space cannot show where it ends: "Riverbend Plumbing Co" is another name.
const TOKEN = '[\\p{L}\\p{N}_/%-]';

const fold = (s: string): string => s.toLowerCase().replace(/[`"'*]/g, ' ').replace(/_/g, '-').replace(/\s+/g, ' ').trim();

const escape = (s: string): string => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

function standing(value: string): string {
  const v = escape(fold(value));
  return v.includes(' ') ? `(?<!${TOKEN}\\s*)${v}(?!\\s*${TOKEN})` : `(?<!${TOKEN})${v}(?!${TOKEN})`;
}

/** True when `value` stands as a whole token somewhere in `text`. */
const stands = (text: string, value: string): boolean => new RegExp(standing(value), 'u').test(fold(text));

// An admin page has two names: its menu label (Settings > Business Info) and its admin path
// (options-general.php?page=vwf-business-info). Truth records the label beside the path as
// `settings_page.menu`, or as `menu_path` for a taxonomy or a menu, so an `.edit_path` reference accepts
// either. The label's parts appear in order, joined by any menu separator, with a token boundary at each
// end. Prose may follow the label, because contains_all reads the whole reply. A different parent or item
// is a different page. Every menu shares one menu path (Appearance > Editor > Navigation), so a menu's
// label counts only beside the menu's title.
const MENU_SEPARATOR = '\\s*(?:>|->|→|›|»|/)\\s*';

interface MenuLabel {
  label: string;
  /** The title the reply must also give, for a page the label alone does not single out. */
  title?: string;
}

/** The menu label truth records for each admin path the references name, keyed by path. */
function menuLabels(truth: Json, refs: string[]): Map<string, MenuLabel> {
  const out = new Map<string, MenuLabel>();
  for (const ref of refs.filter((r) => r.endsWith('.edit_path'))) {
    const at = (key: string) => resolveRef(truth, ref.replace(/edit_path$/, key));
    const path = resolveRef(truth, ref);
    const label = at('settings_page.menu') ?? at('menu_path');
    const title = ref.startsWith('navigation[') ? at('title') : undefined;
    if (typeof path === 'string' && typeof label === 'string') out.set(path, { label, ...(typeof title === 'string' ? { title } : {}) });
  }
  return out;
}

/** True when `text` gives the menu label and, where the label is shared, the page's title. */
function namesPage(text: string, { label, title }: MenuLabel): boolean {
  return namesMenu(text, label) && (title === undefined || stands(text, title));
}

const describeLabel = ({ label, title }: MenuLabel): string => (title === undefined ? label : `${label} (${title})`);

// A menu has two Site Editor URLs. WordPress 7.1 redirects the older `postType=wp_navigation&postId=<id>`
// to the route `p=/wp_navigation/<id>`, the same page, so an answer may give either. The older template
// and template part URLs redirect to the Templates or Patterns list instead, a different page, so they
// are not aliases.
const MENU_ROUTE = /^site-editor\.php\?p=\/wp_navigation\/(\d+)$/;

/** Other ways of writing the same admin location as `path`. */
function aliases(path: string): string[] {
  const menu = MENU_ROUTE.exec(path);
  return menu ? [`site-editor.php?postType=wp_navigation&postId=${menu[1]}`] : [];
}

/** True when `text` gives the menu label, written with any menu separator. */
function namesMenu(text: string, label: string): boolean {
  const parts = label.split('>').map((part) => escape(fold(part)));
  return new RegExp(`(?<!${TOKEN})${parts.join(MENU_SEPARATOR)}(?!${TOKEN})`, 'u').test(fold(text));
}

// Every number is a possible count, so beside a number any other number is a rival. A block path (2.1.0) is
// treated the same way: an answer can hedge with a path no anchor holds now (an old one, a parent or a child),
// which no rival from truth would catch, so beside a path any other plain or dotted number is a rival. A dot
// ends a token, so 2.1 stands inside 2.1.0, and the .0 left over is what refuses it.
const NUMBER_OR_PATH = /^\d+(?:\.\d+)*$/;

// A yes-or-no question is answered by the answer line's first word. In run 5 edit-safety-2 lk-22's "Yes, she can
// drag the Night rate block ... no lock changes required" failed against `yes`, because the `no` in its reason stood
// as a rival. A first word followed by `or`, `and` or `/` hedges, so it decides nothing and the rules below apply.
const YES_OR_NO = ['yes', 'no'];
const LEADING_YES_OR_NO = /^(yes|no)\b(?!\s*(?:or|and)\b|\/)/;

// A value named only in a concession is conceded, not given. In run 5 three real-site df-01 answers said the phone
// number is typed into the page, "even though" the site's phone option exists, and passed as naming the option.
// A concession opens at even though, although, though, despite or even if. Inside a sentence it runs to the
// sentence's end; opening a sentence, it runs to the first comma, where the main clause starts. A denial written
// any other way ("there is a site_phone option, but nothing reads it") still passes: the rule reads only the
// concession, because that is the shape the run 5 answers took and the one a word list can find.
const CONCESSION = /\b(?:even though|although|though|despite|even if)\b/;

/** `text`, folded, with every concession cut out. */
function outsideConcessions(text: string): string {
  const clause = (sentence: string): string => {
    const at = sentence.search(CONCESSION);
    if (at < 0) return sentence;
    if (sentence.slice(0, at).trim()) return sentence.slice(0, at);
    const comma = sentence.indexOf(',', at);
    return comma < 0 ? '' : clause(sentence.slice(comma + 1));
  };
  return fold(text).split(/(?<=[.;!?])\s+/).map(clause).join(' ; ');
}

/** True when `text` names `value` and none of `rivals`. Beside a number or a block path, any other number is a rival. */
function names(text: string, value: string, rivals: string[]): boolean {
  if (!stands(outsideConcessions(text), value)) return false;
  const rest = fold(text).replace(new RegExp(standing(value), 'gu'), ' ; ');
  if (rivals.some((r) => new RegExp(standing(r), 'u').test(rest))) return false;
  return !(NUMBER_OR_PATH.test(fold(value)) && /\d/.test(rest));
}

/** Every value the references could have named: each criterion selector widened to `[*]`. An `id=` selector names the question's subject, so it stays. */
function candidates(truth: Json, refs: string[]): string[] {
  const out: string[] = [];
  const walk = (node: Json | undefined): void => {
    if (Array.isArray(node)) node.forEach(walk);
    else if (node !== undefined && (node === null || typeof node !== 'object')) out.push(String(node));
  };
  for (const ref of refs) walk(resolveRef(truth, ref.replace(/\[(?!id=)[^\]]*\]/g, '[*]')));
  return out;
}

// A judge question's identifier facts are checked in code first, because in run 2 Haiku passed df-01
// answers that never wrote the vwf_phone option. An option name, a slug, a binding source id (or its
// label) and an admin location truth records in both forms (either form, or the older menu URL) must
// stand in the reply as a whole token, or the answer is wrong without a judge call. The rest stays with
// the judge, including an admin path with no menu label, which answers give in forms truth does not
// record, a theme, which they give inside a template part id (`vwf-fixture//footer`), and a field key,
// which they name by its label.
const ADMIN_LOCATION = /^(.*)\.(?:edit_path|settings_page\.menu|menu_path)$/;
const BINDING_SOURCE_ID = /^binding_sources\[[^\]]*\]\.id$/;
const IDENTIFIER = /\.(?:option|slug)$/;

interface Fact {
  /** The value as EXPECTED gives it. */
  value: string;
  named: (reply: string) => boolean;
}

/** True when `text` gives the admin path as a whole token, alone or after `wp-admin/`. */
function namesPath(text: string, path: string): boolean {
  return new RegExp(`(?:(?<!${TOKEN})|(?<=wp-admin/))${escape(fold(path))}(?!${TOKEN})`, 'u').test(fold(text));
}

/** The facts a judge question's references hold that code can check in the reply. */
function identifierFacts(truth: Json, refs: string[]): Fact[] {
  return refs.flatMap((ref): Fact[] => {
    const value = resolveRef(truth, ref);
    if (typeof value !== 'string') return [];
    const admin = ADMIN_LOCATION.exec(ref);
    if (admin) {
      const editPath = `${admin[1]}.edit_path`;
      const path = resolveRef(truth, editPath);
      const label = typeof path === 'string' ? menuLabels(truth, [editPath]).get(path) : undefined;
      if (typeof path !== 'string' || !label) return [];
      return [{ value, named: (reply) => [path, ...aliases(path)].some((p) => namesPath(reply, p)) || namesPage(reply, label) }];
    }
    if (/\s/.test(value)) return [];
    if (BINDING_SOURCE_ID.test(ref)) {
      const label = resolveRef(truth, ref.replace(/\.id$/, '.label'));
      return [{ value, named: (reply) => stands(reply, value) || (typeof label === 'string' && namesMenu(reply, label)) }];
    }
    return IDENTIFIER.test(ref) ? [{ value, named: (reply) => stands(reply, value) }] : [];
  });
}

// A field on a post is also edited at that post's own edit screen. In run 3 the judge failed seven el-08
// replies that gave post.php?post=90020&action=edit and price_from, because EXPECTED held only the post
// type's list screen (edit.php?post_type=service). From run 4, a judge question that references a post
// type's field, that post type's edit_path and a subject whose truth record has its own post.php edit_path
// is correct without a judge call when the reply names the subject's edit path (alone or after wp-admin/)
// and the field, by key or label, and names no other post's edit screen and no other field of that post
// type. Anything else goes to the judge as before, so the rule only adds passes.
const POST_FIELD = /^post_types\[id=([^\]]+)\]\.fields\[key=([^\]]+)\]\.key$/;
const SUBJECT = /^([a-z_]+\[(?:slug|id)=[^\]]+\])\./;
const POST_EDIT = /^post\.php\?post=(\d+)&action=edit$/;

interface OwnScreen {
  path: string;
  field: { key: string; label?: string };
  rivals: { key: string; label?: string }[];
}

/** The subject's own edit screen and the field the question asks about, when its references have that shape. */
export function ownEditScreen(truth: Json, refs: string[]): OwnScreen | undefined {
  const fieldRef = refs.map((r) => POST_FIELD.exec(r)).find((m) => m !== null);
  if (!fieldRef || !refs.includes(`post_types[id=${fieldRef[1]}].edit_path`)) return undefined;
  const paths = new Set(
    refs.flatMap((r) => {
      const subject = SUBJECT.exec(r);
      if (!subject || r.startsWith('post_types[')) return [];
      const path = resolveRef(truth, `${subject[1]}.edit_path`);
      return typeof path === 'string' && POST_EDIT.test(path) ? [path] : [];
    }),
  );
  if (paths.size !== 1) return undefined;
  const fields = resolveRef(truth, `post_types[id=${fieldRef[1]}].fields`);
  if (!Array.isArray(fields)) return undefined;
  const all = fields.flatMap((f) =>
    f && typeof f === 'object' && !Array.isArray(f) && typeof f.key === 'string' ? [{ key: f.key, ...(typeof f.label === 'string' ? { label: f.label } : {}) }] : [],
  );
  const field = all.find((f) => f.key === fieldRef[2]);
  if (!field) return undefined;
  return { path: [...paths][0], field, rivals: all.filter((f) => f !== field) };
}

const namesField = (reply: string, f: { key: string; label?: string }): boolean =>
  stands(reply, f.key) || (f.label !== undefined && namesMenu(reply, f.label));

/** True when `reply` gives the subject's own edit screen and the field, and no other post or field. */
export function namesOwnScreen(reply: string, own: OwnScreen): boolean {
  if (!namesPath(reply, own.path) || !namesField(reply, own.field)) return false;
  const id = POST_EDIT.exec(own.path)?.[1];
  const otherPost = [...fold(reply).matchAll(/post\.php\?post=(\d+)/g)].some((m) => m[1] !== id);
  return !otherPost && !own.rivals.some((r) => namesField(reply, r));
}

// EXPECTED holds only the values a judge question's references name, so the judge cannot tell a correct
// detail beyond them from an invented one. In run 4 it failed three classic df-01 replies for naming the
// Company details field group, which truth records on the Company Settings page, as if it were another
// name for the page, and three block ci-02 replies for listing the pages the footer shows on, although the
// prompt said extra correct detail never makes an answer wrong. From run 5 the prompt says what extra detail
// looks like, and code gives the judge CONFIRMED: the values the reply names from the records the references
// select (each reference's first selector, when it picks one record). The judge never sees the rest of a
// record, because in a trial it read a partial record as the whole site and failed answers for what the
// record left out. A lowercase single word (a slug, a field type) is left out, since almost any reply has one,
// and so is a rival: a value at the same place as an expected fact in another item (company_email beside
// company_phone), which an answer gives in place of the fact, not beside it. A name is matched the way a
// menu label is, so `the "Company details" ACF group` names Company details.
const RECORD_SELECTOR = /^[^[]*\[[^\]*]+\]/;
const PLAIN_WORD = /^[a-z]*$/;
const shape = (ref: string): string => ref.replace(/\[[^\]]*\]/g, '[*]');

/** Every string in `node`, with its path below `path`. An array item with an id, key or slug is selected by it. */
function strings(node: Json, path: string, out: [string, string][] = []): [string, string][] {
  if (typeof node === 'string') out.push([path, node]);
  else if (Array.isArray(node)) {
    for (const item of node) {
      const record = item !== null && typeof item === 'object' && !Array.isArray(item) ? item : undefined;
      const by = record && ['id', 'key', 'slug'].find((k) => typeof record[k] === 'string');
      strings(item, record && by ? `${path}[${by}=${record[by]}]` : path, out);
    }
  } else if (node !== null && typeof node === 'object') for (const [key, value] of Object.entries(node)) strings(value, `${path}.${key}`, out);
  return out;
}

/** Values beyond EXPECTED that `reply` names and the records the references select hold, keyed by their place in truth. */
export function confirmedDetail(truth: Json, refs: string[], expected: Record<string, Json>, reply: string): Record<string, string | string[]> {
  const known = new Set(strings(expected, '').map(([, value]) => fold(value)));
  const rivals = new Set(refs.map(shape));
  const subjects = new Set(refs.flatMap((ref) => RECORD_SELECTOR.exec(ref)?.[0] ?? []));
  const out: Record<string, string | string[]> = {};
  for (const subject of subjects) {
    const record = resolveRef(truth, subject);
    if (record === null || typeof record !== 'object' || Array.isArray(record)) continue;
    for (const [path, value] of strings(record, subject)) {
      if (PLAIN_WORD.test(value) || known.has(fold(value)) || rivals.has(shape(path))) continue;
      if (!namesMenu(reply, value)) continue;
      const before = out[path];
      out[path] = before === undefined ? value : [...(Array.isArray(before) ? before : [before]), value];
    }
  }
  return out;
}

// The judge keeps to the question's rubric (from run 6). In run 5 it failed five real-site df-06 answers the rubric
// accepts, for leaving out the theme filter that EXPECTED holds and the rubric mentions only to explain how the name
// gets to the share tags: the prompt said an answer must state every expected fact. Now the judge lists what WHAT
// COUNTS AS CORRECT requires and what it calls wrong, marks each one against the answer, and code decides: correct
// when every requirement is met and nothing ruled out is said. A reply in the older {correct, reason} shape is still
// read as it is.
const Checklist = z.object({
  requires: z.array(z.object({ item: z.string(), met: z.boolean() })).min(1),
  rules_out: z.array(z.object({ item: z.string(), quote: z.string().optional(), said: z.boolean() })).default([]),
  reason: z.string(),
});
const JudgeReply = z.object({ correct: z.boolean(), reason: z.string() });

/** The verdict a judge reply gives: from its checklist when it has one, which outranks a `correct` beside it. */
function verdictOf(reply: unknown): { correct: boolean; reason: string } {
  if (!(reply !== null && typeof reply === 'object' && 'requires' in reply)) return JudgeReply.parse(reply);
  const { requires, rules_out, reason } = Checklist.parse(reply);
  const unmet = requires.filter((r) => !r.met).map((r) => r.item);
  const said = rules_out.filter((r) => r.said).map((r) => r.item);
  const why = [unmet.length ? ` (not met: ${unmet.join('; ')})` : '', said.length ? ` (said: ${said.join('; ')})` : ''].join('');
  return { correct: !why, reason: `${reason}${why}` };
}

export const RUBRIC = `You grade one answer about a WordPress site against the question's rubric.
WHAT COUNTS AS CORRECT is the test. From it, list what the answer must say (requires) and what it calls wrong (rules_out). Add nothing it does not state. A clause that only explains how or why (which ..., because ..., so ...) describes the site; the answer does not have to say it. When WHAT COUNTS AS CORRECT is "the expected facts", each expected fact is a requirement. Read each thing it calls wrong as the plain act to look for: "naming X as the place to change it" means offering X as a place to change, set or override it at all.
EXPECTED is the site's ground truth behind the rubric, keyed by where truth records it. Use it to check what the answer says, not as more requirements. A requirement is met only when the answer states it plainly, with the value EXPECTED holds. A requirement the answer hedges between options on, says it could not find out, or gives a different value for is not met. For each item in rules_out, search the whole answer and quote its words that say it, then mark it said. Something ruled out is said when the answer says it in any words, including as one of two places or options, as a way to override, or with a condition such as "if configured": offering it beside a right answer still says it. Be strict about both.
EXPECTED holds only the facts this question needs, not everything true about the site. An answer often names more: more pages or templates than the question asked about, or a group, file, id or edit screen beside an expected item. That is extra detail, not a different value, unless the answer gives it in place of an expected value. Extra detail never makes an answer wrong, even when you cannot check it, and neither does going beyond the question.
CONFIRMED, when given, lists detail beyond EXPECTED that the answer names and the site's ground truth holds, keyed by where truth records it. It is correct.
An admin location given by its menu label (Settings > Reading), its admin path (options-reading.php), or both is the same answer, whichever form EXPECTED uses, provided it names the same page. A label or path that leads to a different page is wrong.
Reply with JSON only: {"requires": [{"item": "<a requirement>", "met": true or false}], "rules_out": [{"item": "<something it calls wrong>", "quote": "<the answer's words that say it, or empty>", "said": true or false}], "reason": "<one sentence>"}
The answer is correct when every requirement is met and nothing in rules_out is said. Nothing else makes it wrong.`;

async function judge(q: Question, expected: Record<string, Json>, confirmed: Record<string, Json>, reply: string, j: Judge): Promise<Grade> {
  const content = [
    `QUESTION: ${q.prompt}`,
    `EXPECTED (keys are references into the site's ground truth): ${JSON.stringify(expected)}`,
    ...(Object.keys(confirmed).length ? [`CONFIRMED (detail beyond EXPECTED that the answer names, keyed by where the site's ground truth records it): ${JSON.stringify(confirmed)}`] : []),
    `WHAT COUNTS AS CORRECT: ${q.answer.rubric ?? 'the expected facts'}`,
    `ANSWER TO GRADE:\n${reply}`,
  ].join('\n\n');
  const message = await j.client.messages.create({ model: j.model, max_tokens: 600, system: RUBRIC, messages: [{ role: 'user', content }], ...j.params });
  const text = message.content.flatMap((b) => (b.type === 'text' ? [b.text] : [])).join('');
  try {
    const verdict = verdictOf(JSON.parse(text.slice(text.indexOf('{'), text.lastIndexOf('}') + 1)));
    return { ...verdict, judge: message };
  } catch {
    return { correct: false, reason: `judge reply was not the expected JSON: ${text.slice(0, 200)}`, judge: message };
  }
}

export async function grade(q: Question, reply: string, truth: Json, j: Judge): Promise<Grade> {
  const expected = expectedFor(q, truth);
  if (!Array.isArray(expected)) {
    const missing = identifierFacts(truth, q.answer.refs).filter((f) => !f.named(reply));
    if (missing.length) return { correct: false, reason: `missing ${missing.map((f) => f.value).join(', ')}, judge not called` };
    const own = ownEditScreen(truth, q.answer.refs);
    if (own && namesOwnScreen(reply, own)) return { correct: true, reason: `names ${own.path} and ${own.field.key}, judge not called` };
    return judge(q, expected, confirmedDetail(truth, q.answer.refs, expected, reply), reply, j);
  }
  const wanted = expected.map(normalize);
  const answer = extractAnswer(reply);
  const pool = [...expected, ...candidates(truth, q.answer.refs)];
  const rivals = (value: string): string[] => pool.filter((c) => fold(c) !== fold(value));
  switch (q.answer.type) {
    case 'exact': {
      if (wanted.length === 1 && normalize(answer) === wanted[0]) return { correct: true, reason: 'matches' };
      const first = wanted.length === 1 && YES_OR_NO.includes(wanted[0]) ? LEADING_YES_OR_NO.exec(fold(answer))?.[1] : undefined;
      if (first) return { correct: first === wanted[0], reason: first === wanted[0] ? `answers ${first} first` : `expected "${expected[0]}", answers ${first} first` };
      const ok = wanted.length === 1 && names(answer, expected[0], rivals(expected[0]));
      if (ok) return { correct: true, reason: 'matches, with a label or qualifier' };
      const conceded = wanted.length === 1 && stands(answer, expected[0]);
      return { correct: false, reason: `expected "${expected.join(', ')}"${conceded && !stands(outsideConcessions(answer), expected[0]) ? ', named only in a concession' : ''}` };
    }
    case 'set': {
      const items = splitList(answer);
      const got = new Set(items.map(normalize));
      const want = new Set(wanted);
      if (got.size === want.size && [...want].every((w) => got.has(w))) return { correct: true, reason: 'same set' };
      // Each item names one expected value, either outright or with a label or qualifier, and together they name them all.
      const named = items.map((item) => (want.has(normalize(item)) ? [normalize(item)] : expected.filter((v) => names(item, v, rivals(v))).map(normalize)));
      const ok = named.every((n) => n.length === 1) && new Set(named.flat()).size === want.size;
      return { correct: ok, reason: ok ? 'same set, with labels or qualifiers' : `expected {${expected.join(', ')}}` };
    }
    default: {
      const body = normalize(reply);
      const labels = menuLabels(truth, q.answer.refs);
      const absent = expected.filter((v, i) => !body.includes(wanted[i]) && !aliases(v).some((a) => body.includes(normalize(a))));
      const byLabel = absent.filter((v) => {
        const label = labels.get(v);
        return label !== undefined && namesPage(reply, label);
      });
      const missing = absent.filter((v) => !byLabel.includes(v));
      if (missing.length) return { correct: false, reason: `missing ${missing.join(', ')}` };
      const described = byLabel.map((v) => describeLabel(labels.get(v) as MenuLabel));
      return { correct: true, reason: byLabel.length ? `contains all, ${described.join(', ')} by its menu label` : 'contains all' };
    }
  }
}
