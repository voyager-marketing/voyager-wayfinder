/**
 * Sanitizes a mysqldump of a live site before it becomes a bench fixture. The dump is read and written
 * as latin1, so every character is one byte: serialized PHP lengths stay byte counts and no byte changes unless a
 * rule changes it. Rows are re-emitted from their original text unless a rule touched them.
 *
 * What goes, by table (names without the prefix):
 * - users: password hashes and activation keys blanked, each email replaced by user-<ID>@example.invalid
 * - usermeta: sessions, application passwords and the community events location (an IP) dropped; key-like values
 *   blanked; emails replaced
 * - options: transients dropped; Wayfinder's own `vwf_*` state dropped, so the plugin under test starts from its
 *   defaults (MCP exposure off); key-like options blanked; logs and IP lists emptied; inside every other value,
 *   key-like entries and secret-shaped strings blanked and emails replaced
 * - posts: trash, auto-drafts, drafts, posts pending review, every revision, and log and entry post types,
 *   dropped with their meta;
 *   secret-shaped strings in content replaced
 * - postmeta, termmeta, commentmeta: key-like entries and secret-shaped strings blanked
 * - comments: author email, IP and user agent blanked
 * - form entries, leads, submissions, sessions, logs, activity logs, request logs, job queues, caches, audit
 *   tables and Action Scheduler's actions, claims and logs: emptied
 *
 * The key-name rule never blanks an on or off value, and does not read the keys of `cron` and `rewrite_rules`,
 * which are hook names and URL patterns (`^oauth/token$`), not setting names.
 *
 * What stays: roles and capabilities (the `user_roles` option and each user's `capabilities` and `user_level`),
 * because `client_can` reads them, and every row of content. The table prefix is renamed to the one wp-env uses,
 * and MySQL 8's utf8mb4_0900 collations to one MariaDB has. The report names every table, option and meta key a
 * rule touched, never a value.
 */

export interface SanitizeOptions {
  /** The dump's table prefix, for example `syn_`. */
  prefix: string;
  /** The prefix the snapshot imports under. wp-env's is `wp_`. */
  targetPrefix?: string;
  /** Options kept as they are although they hold an email: business details the site shows to visitors. */
  publicOptions?: string[];
}

export interface SanitizeReport {
  format: 'wayfinder-sanitize-report/1';
  prefix: { from: string; to: string };
  /** Rows per table, before and after, by the snapshot's table names. */
  rows: Record<string, { before: number; after: number }>;
  /** Tables whose rows were all dropped. Their structure stays. */
  emptied: string[];
  dropped: {
    options: string[];
    transients: number;
    usermeta: Record<string, number>;
    posts: Record<string, number>;
    postmeta: number;
  };
  /** `<table>.<column>` blanked in every row, or `<table> <name>` for an option or meta value blanked whole. */
  blanked: string[];
  /** `<table> <name>: <path>` for an entry blanked inside a structured value. */
  redacted: string[];
  emails_replaced: number;
  renamed: string[];
  collations: Record<string, number>;
}

/** Plain words that mark a name as holding a secret. `key` counts after the first word: `api_key`, `auth_key`. */
const SECRET_WORDS = new Set([
  'password', 'passwd', 'pass', 'pwd', 'secret', 'secrets', 'token', 'tokens', 'apikey', 'license', 'licence', 'salt',
  'jwt', 'credential', 'credentials', 'oauth', 'passphrase', 'dsn',
]);
/**
 * Words that mark an option as a log, an IP list or a census, emptied rather than kept. A census is a plugin's cached
 * inventory of the theme's patterns, which can name a draft case study's pattern; the plugin rebuilds it.
 */
const LOG_WORDS = new Set(['log', 'logs', 'logged', 'lockouts', 'retries', 'ip', 'ips', 'denylist', 'blacklist', 'blocklist', 'allowlist', 'whitelist', 'census']);

const words = (name: string) => name.replace(/([a-z0-9])([A-Z])/g, '$1_$2').toLowerCase().split(/[^a-z0-9]+/).filter(Boolean);

/** True for a name that looks like it holds a key, token, secret or password. */
export function isSecretName(name: string): boolean {
  const w = words(name);
  return w.some((x) => SECRET_WORDS.has(x)) || w.slice(1).some((x) => x === 'key' || x === 'keys');
}

const isLogName = (name: string) => words(name).some((x) => LOG_WORDS.has(x));

/** Strings that are credentials whatever they are called. */
export const SECRET_VALUES: RegExp[] = [
  /sk-ant-[A-Za-z0-9_-]{8,}/,
  /\bsk-(?:proj-)?[A-Za-z0-9_-]{20,}/,
  /\b[rsp]k_(?:live|test)_[A-Za-z0-9]{8,}/,
  /\bwhsec_[A-Za-z0-9]{16,}/,
  /\bgh[pousr]_[A-Za-z0-9]{20,}/,
  /\bgithub_pat_[A-Za-z0-9_]{20,}/,
  /\bxox[abprs]-[A-Za-z0-9-]{10,}/,
  /\bAKIA[0-9A-Z]{16}\b/,
  /\bAIza[0-9A-Za-z_-]{30,}/,
  /\bya29\.[A-Za-z0-9_-]{20,}/,
  /\b1\/\/0[A-Za-z0-9_-]{20,}/,
  /\beyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/,
  /-----BEGIN [A-Z ]*PRIVATE KEY-----/,
  /\b(?:secret|ntn)_[A-Za-z0-9]{30,}/,
  /\bSG\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}/,
];
const looksSecret = (s: string) => SECRET_VALUES.some((re) => re.test(s));

const EMAIL = /[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.(?!(?:png|jpe?g|gif|svg|webp|avif|css|js|php|html?)\b)[A-Za-z]{2,}\b/g;
export const REDACTED_EMAIL = 'redacted@example.invalid';

// ---------------------------------------------------------------------------------------------------------------
// PHP serialization, byte for byte (the strings are latin1, one character per byte).

type Php =
  | { t: 'N' }
  | { t: 'b' | 'i' | 'd'; v: string }
  | { t: 's'; v: string }
  | { t: 'a'; e: [Php, Php][] }
  | { t: 'O'; c: string; e: [Php, Php][] }
  | { t: 'x'; raw: string };

/** Parses one PHP-serialized value that spans all of `s`, or returns undefined. */
export function unserialize(s: string): Php | undefined {
  if (!/^(?:[NbidsaOCrRE]:|N;)/.test(s)) return undefined;
  try {
    const [value, end] = readPhp(s, 0);
    return end === s.length ? value : undefined;
  } catch {
    return undefined;
  }
}

function readPhp(s: string, i: number): [Php, number] {
  const type = s[i];
  if (type === 'N' && s[i + 1] === ';') return [{ t: 'N' }, i + 2];
  if (s[i + 1] !== ':') throw new Error('bad');
  if (type === 'b' || type === 'i' || type === 'd' || type === 'r' || type === 'R') {
    const end = s.indexOf(';', i);
    if (end < 0) throw new Error('bad');
    const v = s.slice(i + 2, end);
    if (type === 'r' || type === 'R') return [{ t: 'x', raw: s.slice(i, end + 1) }, end + 1];
    if (!(type === 'd' ? /^(?:-?\d+(?:\.\d+)?(?:E[+-]?\d+)?|-?INF|NAN)$/i : /^-?\d+$/).test(v)) throw new Error('bad');
    return [{ t: type, v }, end + 1];
  }
  if (type === 's' || type === 'E') {
    const colon = s.indexOf(':', i + 2);
    const len = Number(s.slice(i + 2, colon));
    if (!Number.isInteger(len) || s[colon + 1] !== '"') throw new Error('bad');
    const start = colon + 2;
    if (s.slice(start + len, start + len + 2) !== '";') throw new Error('bad');
    if (type === 'E') return [{ t: 'x', raw: s.slice(i, start + len + 2) }, start + len + 2];
    return [{ t: 's', v: s.slice(start, start + len) }, start + len + 2];
  }
  if (type === 'a') {
    const colon = s.indexOf(':', i + 2);
    const n = Number(s.slice(i + 2, colon));
    if (!Number.isInteger(n) || s[colon + 1] !== '{') throw new Error('bad');
    const [e, end] = readEntries(s, colon + 2, n);
    return [{ t: 'a', e }, end];
  }
  if (type === 'O' || type === 'C') {
    const colon = s.indexOf(':', i + 2);
    const len = Number(s.slice(i + 2, colon));
    const c = s.slice(colon + 2, colon + 2 + len);
    let j = colon + 2 + len;
    if (s[j] !== '"' || s[j + 1] !== ':') throw new Error('bad');
    const colon2 = s.indexOf(':', j + 2);
    const n = Number(s.slice(j + 2, colon2));
    if (!Number.isInteger(n) || s[colon2 + 1] !== '{') throw new Error('bad');
    if (type === 'C') {
      // A class with its own serializer: n bytes we cannot read, kept as they are.
      if (s[colon2 + 2 + n] !== '}') throw new Error('bad');
      return [{ t: 'x', raw: s.slice(i, colon2 + 3 + n) }, colon2 + 3 + n];
    }
    const [e, end] = readEntries(s, colon2 + 2, n);
    return [{ t: 'O', c, e }, end];
  }
  throw new Error('bad');
}

function readEntries(s: string, i: number, n: number): [[Php, Php][], number] {
  const e: [Php, Php][] = [];
  for (let k = 0; k < n; k++) {
    const [key, a] = readPhp(s, i);
    const [value, b] = readPhp(s, a);
    e.push([key, value]);
    i = b;
  }
  if (s[i] !== '}') throw new Error('bad');
  return [e, i + 1];
}

export function serialize(v: Php): string {
  switch (v.t) {
    case 'N': return 'N;';
    case 'b': case 'i': case 'd': return `${v.t}:${v.v};`;
    case 's': return `s:${v.v.length}:"${v.v}";`;
    case 'a': return `a:${v.e.length}:{${v.e.map(([k, x]) => serialize(k) + serialize(x)).join('')}}`;
    case 'O': return `O:${v.c.length}:"${v.c}":${v.e.length}:{${v.e.map(([k, x]) => serialize(k) + serialize(x)).join('')}}`;
    case 'x': return v.raw;
  }
}

// ---------------------------------------------------------------------------------------------------------------
// Redaction inside a value: PHP-serialized, JSON or plain text, nested to any depth.

interface Ctx {
  /** Replace emails. Off for public business details and for post meta, which is content. */
  emails: boolean;
  /** Blank an entry for its key-like name. Off where the keys are hook names or URL patterns (`cron`, `rewrite_rules`). */
  keyNames: boolean;
  /** Called once per entry blanked, with its path inside the value. */
  hit: (path: string) => void;
  emailCount: { n: number };
}

const keyName = (k: Php) => (k.t === 's' || k.t === 'i' ? k.v : '');

/** A setting's on or off value is never a secret, whatever the setting is called (`noindex_password_protected`). */
const FLAG = /^(?:on|off|yes|no|true|false|enabled|disabled|[01])$/i;
const secretEntry = (ctx: Ctx, name: string, value: string) => ctx.keyNames && value !== '' && !FLAG.test(value) && isSecretName(name);

/** Rewrites `s` under the rules and returns it, unchanged when nothing applied. */
function redactValue(s: string, ctx: Ctx, path: string): string {
  const php = s.length > 1 ? unserialize(s) : undefined;
  if (php && php.t !== 's') {
    return redactPhp(php, ctx, path) ? serialize(php) : s;
  }
  const json = jsonOf(s);
  if (json !== undefined) {
    return redactJson(json, ctx, path) ? JSON.stringify(json.value) : s;
  }
  return redactText(s, ctx, path);
}

function redactText(s: string, ctx: Ctx, path: string): string {
  if (looksSecret(s)) {
    ctx.hit(path || '(value)');
    return '';
  }
  if (!ctx.emails) return s;
  return s.replace(EMAIL, (m) => {
    if (m === REDACTED_EMAIL) return m;
    ctx.emailCount.n++;
    return REDACTED_EMAIL;
  });
}

function redactPhp(v: Php, ctx: Ctx, path: string): boolean {
  if (v.t !== 'a' && v.t !== 'O') return false;
  let changed = false;
  for (const entry of v.e) {
    const name = keyName(entry[0]);
    const at = path ? `${path}.${name}` : name;
    const value = entry[1];
    if (value.t === 's') {
      const next = secretEntry(ctx, name, value.v) ? (ctx.hit(at), '') : redactValue(value.v, ctx, at);
      if (next !== value.v) {
        value.v = next;
        changed = true;
      }
    } else if (ctx.keyNames && isSecretName(name) && (value.t === 'a' || value.t === 'O')) {
      if (blankLeavesPhp(value)) {
        ctx.hit(at);
        changed = true;
      }
    } else if (redactPhp(value, ctx, at)) changed = true;
  }
  return changed;
}

type JsonBox = { value: unknown };
function jsonOf(s: string): JsonBox | undefined {
  const t = s.trimStart();
  if (t[0] !== '{' && t[0] !== '[') return undefined;
  try {
    const value: unknown = JSON.parse(s);
    return value && typeof value === 'object' ? { value } : undefined;
  } catch {
    return undefined;
  }
}

function redactJson(box: JsonBox, ctx: Ctx, path: string): boolean {
  const node = box.value as Record<string, unknown> | unknown[];
  let changed = false;
  for (const name of Object.keys(node)) {
    const at = path ? `${path}.${name}` : name;
    const value = (node as Record<string, unknown>)[name];
    if (typeof value === 'string') {
      const next = secretEntry(ctx, name, value) ? (ctx.hit(at), '') : redactValue(value, ctx, at);
      if (next !== value) {
        (node as Record<string, unknown>)[name] = next;
        changed = true;
      }
    } else if (value && typeof value === 'object') {
      if (ctx.keyNames && isSecretName(name)) {
        if (blankLeavesJson(value)) {
          ctx.hit(at);
          changed = true;
        }
      } else if (redactJson({ value }, ctx, at)) changed = true;
    }
  }
  return changed;
}

/** Blanks every string inside a structure, keeping its shape. */
function blankLeavesPhp(v: Php): boolean {
  if (v.t === 's') {
    const was = v.v !== '';
    v.v = '';
    return was;
  }
  if (v.t !== 'a' && v.t !== 'O') return false;
  let changed = false;
  for (const [, x] of v.e) if (blankLeavesPhp(x)) changed = true;
  return changed;
}

function blankLeavesJson(node: unknown): boolean {
  let changed = false;
  for (const [k, x] of Object.entries(node as Record<string, unknown>)) {
    if (typeof x === 'string' && x !== '') {
      (node as Record<string, unknown>)[k] = '';
      changed = true;
    } else if (x && typeof x === 'object' && blankLeavesJson(x)) changed = true;
  }
  return changed;
}

/** A whole value blanked with its shape kept: every string in a structure emptied, or '' for plain text. */
function blankWhole(s: string): string {
  const php = s.length > 1 ? unserialize(s) : undefined;
  if (php && php.t !== 's') {
    blankLeavesPhp(php);
    return serialize(php);
  }
  const json = jsonOf(s);
  if (json) {
    blankLeavesJson(json.value);
    return JSON.stringify(json.value);
  }
  return '';
}

/** A log or list emptied: an empty array or object of the same kind, or ''. */
function emptyWhole(s: string): string {
  const php = s.length > 1 ? unserialize(s) : undefined;
  if (php && php.t === 'a') return 'a:0:{}';
  const json = jsonOf(s);
  if (json) return Array.isArray(json.value) ? '[]' : '{}';
  return '';
}

// ---------------------------------------------------------------------------------------------------------------
// The dump: CREATE TABLE gives each table's columns, and each INSERT line holds whole rows.

interface Value {
  raw: string;
  /** The unescaped string, or null for NULL. Numbers and hex literals keep `raw` only. */
  value: string | null;
  binary: boolean;
}

const UNESCAPE: Record<string, string> = { '0': '\0', n: '\n', r: '\r', t: '\t', b: '\b', Z: '\x1a', '\\': '\\', "'": "'", '"': '"' };
const ESCAPE: Record<string, string> = { '\0': '\\0', '\n': '\\n', '\r': '\\r', '\\': '\\\\', "'": "\\'", '"': '\\"', '\x1a': '\\Z' };
const sqlString = (s: string) => `'${s.replace(/[\0\n\r\\'"\x1a]/g, (c) => ESCAPE[c])}'`;

/** Parses `(…),(…);` starting at `i`. */
export function parseRows(s: string, i: number): Value[][] {
  const rows: Value[][] = [];
  for (;;) {
    if (s[i] !== '(') throw new Error(`expected ( at ${i}`);
    i++;
    const row: Value[] = [];
    for (;;) {
      const [v, j] = parseValue(s, i);
      row.push(v);
      i = j;
      if (s[i] === ',') { i++; continue; }
      if (s[i] === ')') { i++; break; }
      throw new Error(`expected , or ) at ${i}`);
    }
    rows.push(row);
    if (s[i] === ',') { i++; continue; }
    if (s[i] === ';') return rows;
    throw new Error(`expected , or ; at ${i}`);
  }
}

function parseValue(s: string, i: number): [Value, number] {
  if (s.startsWith('NULL', i)) return [{ raw: 'NULL', value: null, binary: false }, i + 4];
  const start = i;
  let binary = false;
  if (s.startsWith('_binary ', i)) {
    binary = true;
    i += 8;
  }
  if (s[i] === "'") {
    let j = i + 1;
    let out = '';
    for (;;) {
      const q = s.indexOf("'", j);
      const b = s.indexOf('\\', j);
      if (q < 0) throw new Error('unterminated string');
      if (b >= 0 && b < q) {
        out += s.slice(j, b) + (UNESCAPE[s[b + 1]] ?? s[b + 1]);
        j = b + 2;
      } else if (s[q + 1] === "'") {
        out += `${s.slice(j, q)}'`;
        j = q + 2;
      } else {
        out += s.slice(j, q);
        j = q + 1;
        break;
      }
    }
    return [{ raw: s.slice(start, j), value: out, binary }, j];
  }
  const m = /^(?:0x[0-9A-Fa-f]*|-?[0-9][0-9.eE+-]*)/.exec(s.slice(i, i + 80));
  if (!m) throw new Error(`unexpected value at ${i}: ${s.slice(i, i + 20)}`);
  return [{ raw: s.slice(start, i + m[0].length), value: null, binary }, i + m[0].length];
}

const setValue = (v: Value, next: string) => {
  v.value = next;
  v.raw = `${v.binary ? '_binary ' : ''}${sqlString(next)}`;
};

const INSERT = /^INSERT INTO `([^`]+)`(?: \(([^)]*)\))? VALUES /;

/**
 * Tables whose rows are form entries, leads, sessions, logs, queues or caches. Their structure is kept and their
 * rows dropped. On the first snapshot that included a plugin's activity log, which records staff emails and visitor
 * IPs, and its backup job queue, whose request payloads are not site content.
 */
const EMPTIED_TABLE = /^(?:actionscheduler_(?:actions|claims|logs)|gf_.*|wpforms_.*|frmt_.*|fluentform_.*|e_submissions.*)$|(?:^|_)(?:entry|entries|submission|submissions|lead|leads|session|sessions|log|logs|activity|activities|request|requests|job|jobs|queue|cache|audit)(?:_|$)/;
/** Options whose keys are hook names or URL patterns rather than setting names, so a key never marks a secret. */
const STRUCTURAL_OPTIONS = new Set(['cron', 'rewrite_rules']);
/** Post types that are logs, requests or captured entries, not site content. */
const DROPPED_POST_TYPE = /^(?:llar_.*|wp_user_request|flamingo_.*|.*_(?:log|logs|lead|leads|entry|entries|submission|submissions))$/;
/**
 * Unpublished posts go too: drafts and posts pending review are not part of the site a visitor or a client sees,
 * and on the first snapshot they included an unpublished case study naming a client, while bench transcripts
 * are published.
 */
const DROPPED_POST_STATUS = new Set(['trash', 'auto-draft', 'draft', 'pending']);
const DROPPED_USERMETA = new Set(['session_tokens', '_application_passwords', 'community-events-location']);

export function sanitizeDump(dump: string, opts: SanitizeOptions): { sql: string; report: SanitizeReport } {
  const from = opts.prefix;
  const to = opts.targetPrefix ?? 'wp_';
  const publicOptions = new Set(opts.publicOptions ?? []);
  const lines = dump.split('\n');
  const report: SanitizeReport = {
    format: 'wayfinder-sanitize-report/1',
    prefix: { from, to },
    rows: {},
    emptied: [],
    dropped: { options: [], transients: 0, usermeta: {}, posts: {}, postmeta: 0 },
    blanked: [],
    redacted: [],
    emails_replaced: 0,
    renamed: [],
    collations: {},
  };
  const emailCount = { n: 0 };
  const blanked = new Map<string, number>();
  const redacted = new Set<string>();
  const renamed = new Set<string>();
  const note = (map: Map<string, number>, key: string) => map.set(key, (map.get(key) ?? 0) + 1);
  const bare = (table: string) => (table.startsWith(from) ? table.slice(from.length) : table);
  const target = (table: string) => (table.startsWith(from) ? to + table.slice(from.length) : table);

  // Columns per table, from CREATE TABLE.
  const columns = new Map<string, string[]>();
  let creating: string | undefined;
  for (const line of lines) {
    const create = /^CREATE TABLE `([^`]+)`/.exec(line);
    if (create) {
      creating = create[1];
      columns.set(creating, []);
    } else if (creating && /^\) /.test(line)) creating = undefined;
    else if (creating) {
      const col = /^\s+`([^`]+)` /.exec(line);
      if (col) columns.get(creating)?.push(col[1]);
    }
  }
  const colsOf = (table: string, list?: string) => (list ? [...list.matchAll(/`([^`]+)`/g)].map((m) => m[1]) : columns.get(table) ?? []);

  // Pass 1: which posts go, so their meta and revisions go with them (postmeta is dumped before posts).
  const droppedPosts = new Set<string>();
  const parents = new Map<string, string>();
  const postRows: { id: string; type: string; status: string; parent: string }[] = [];
  for (const line of lines) {
    const m = INSERT.exec(line);
    if (!m || bare(m[1]) !== 'posts') continue;
    const cols = colsOf(m[1], m[2]);
    const at = (name: string) => cols.indexOf(name);
    for (const row of parseRows(line, m[0].length)) {
      const get = (name: string) => row[at(name)]?.value ?? row[at(name)]?.raw ?? '';
      postRows.push({ id: get('ID'), type: get('post_type'), status: get('post_status'), parent: get('post_parent') });
    }
  }
  // Revisions are earlier, unpublished versions: on the first snapshot four old homepage revisions named a client the
  // published homepage no longer did. No edit location lives in one, so every revision goes.
  for (const p of postRows) {
    parents.set(p.id, p.parent);
    if (DROPPED_POST_STATUS.has(p.status) || DROPPED_POST_TYPE.test(p.type) || p.type === 'revision') droppedPosts.add(p.id);
  }

  const out: string[] = [];
  for (const line of lines) {
    const m = INSERT.exec(line);
    if (!m) {
      if (/GTID_PURGED|SQL_LOG_BIN/.test(line)) continue;
      let next = line.replaceAll(`\`${from}`, `\`${to}`);
      next = next.replace(/utf8mb4_0900_\w+/g, (c) => {
        report.collations[c] = (report.collations[c] ?? 0) + 1;
        return 'utf8mb4_unicode_520_ci';
      });
      out.push(next);
      continue;
    }
    const table = m[1];
    const name = bare(table);
    const tableOut = target(table);
    const cols = colsOf(table, m[2]);
    const at = (col: string) => cols.indexOf(col);
    const rows = parseRows(line, m[0].length);
    const counts = (report.rows[tableOut] ??= { before: 0, after: 0 });
    counts.before += rows.length;

    const ctxFor = (where: string, emails: boolean, keyNames = true): Ctx => ({ emails, keyNames, emailCount, hit: (path) => redacted.add(`${where}: ${path}`) });
    let kept: Value[][] = rows;
    if (EMPTIED_TABLE.test(name)) {
      kept = [];
      if (!report.emptied.includes(tableOut)) report.emptied.push(tableOut);
    } else if (name === 'users') {
      for (const row of rows) {
        const id = row[at('ID')].raw;
        for (const col of ['user_pass', 'user_activation_key']) {
          if (row[at(col)]?.value) {
            setValue(row[at(col)], '');
            note(blanked, `${tableOut}.${col}`);
          }
        }
        setValue(row[at('user_email')], `user-${id}@example.invalid`);
        note(blanked, `${tableOut}.user_email (replaced with user-<ID>@example.invalid)`);
      }
    } else if (name === 'usermeta' || name === 'options' || name === 'termmeta' || name === 'commentmeta' || name === 'postmeta') {
      const isOptions = name === 'options';
      const keyCol = isOptions ? 'option_name' : 'meta_key';
      const valCol = isOptions ? 'option_value' : 'meta_value';
      kept = [];
      for (const row of rows) {
        const keyV = row[at(keyCol)];
        const valV = row[at(valCol)];
        const key = keyV.value ?? '';
        if (name === 'postmeta' && droppedPosts.has(row[at('post_id')].raw)) {
          report.dropped.postmeta++;
          continue;
        }
        if (isOptions && /^_(?:site_)?transient_/.test(key)) {
          report.dropped.transients++;
          continue;
        }
        if (isOptions && key.startsWith('vwf_')) {
          report.dropped.options.push(key);
          continue;
        }
        if (name === 'usermeta' && DROPPED_USERMETA.has(key)) {
          report.dropped.usermeta[key] = (report.dropped.usermeta[key] ?? 0) + 1;
          continue;
        }
        // Prefixed names: the roles option and each user's capabilities, level and settings.
        if ((isOptions && key === `${from}user_roles`) || (name === 'usermeta' && key.startsWith(from))) {
          const next = to + key.slice(from.length);
          setValue(keyV, next);
          renamed.add(`${tableOut} ${key} -> ${next}`);
        }
        const value = valV.value;
        if (value !== null && value !== '') {
          const where = `${tableOut} ${keyV.value}`;
          let next: string;
          if (isSecretName(key)) {
            next = blankWhole(value);
            if (next !== value) note(blanked, where);
          } else if (isOptions && isLogName(key)) {
            next = emptyWhole(value);
            if (next !== value) note(blanked, `${where} (log or IP list emptied)`);
          } else {
            const emails = name !== 'postmeta' && !(isOptions && publicOptions.has(key));
            next = redactValue(value, ctxFor(where, emails, !(isOptions && STRUCTURAL_OPTIONS.has(key))), '');
          }
          if (next !== value) setValue(valV, next);
        }
        kept.push(row);
      }
    } else if (name === 'posts') {
      kept = [];
      for (const row of rows) {
        const id = row[at('ID')].raw;
        if (droppedPosts.has(id)) {
          const type = row[at('post_type')].value ?? '';
          const status = row[at('post_status')].value ?? '';
          const why = type === 'revision' ? 'revision' : DROPPED_POST_STATUS.has(status) ? `status ${status}` : `type ${type}`;
          report.dropped.posts[why] = (report.dropped.posts[why] ?? 0) + 1;
          continue;
        }
        for (const col of ['post_content', 'post_excerpt', 'post_content_filtered']) {
          const v = row[at(col)];
          if (!v?.value) continue;
          let hit = false;
          const next = SECRET_VALUES.reduce((text, re) => text.replace(new RegExp(re.source, 'g'), () => ((hit = true), '[redacted]')), v.value);
          if (hit) {
            setValue(v, next);
            redacted.add(`${tableOut} ${id}: ${col}`);
          }
        }
        kept.push(row);
      }
    } else if (name === 'comments') {
      for (const row of rows) {
        for (const col of ['comment_author_email', 'comment_author_IP', 'comment_agent']) {
          if (row[at(col)]?.value) {
            setValue(row[at(col)], '');
            note(blanked, `${tableOut}.${col}`);
          }
        }
      }
    }

    counts.after += kept.length;
    if (kept.length) out.push(`INSERT INTO \`${tableOut}\`${m[2] ? ` (${m[2]})` : ''} VALUES ${kept.map((r) => `(${r.map((v) => v.raw).join(',')})`).join(',')};`);
  }

  report.blanked = [...blanked].map(([k, n]) => (n > 1 ? `${k} (${n} rows)` : k)).sort();
  report.redacted = [...redacted].sort();
  report.renamed = [...renamed].sort();
  report.dropped.options.sort();
  report.emptied.sort();
  report.emails_replaced = emailCount.n;
  return { sql: out.join('\n'), report };
}
