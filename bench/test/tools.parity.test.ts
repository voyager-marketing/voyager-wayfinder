import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { BENCH_DIR } from '../src/run.ts';
import { liveTools, TOOLS, type Site } from '../src/tools.ts';

/**
 * The bench's find_edit_location against the find the plugin ships: the REST
 * route's args (src/Rest/FindController.php) and the find-edit-location
 * ability's input schema (src/Abilities/Abilities.php), read from the PHP
 * source so the test runs with no site. Run 4 offered url and text only
 * after find had gained class.
 */

const ROOT = join(BENCH_DIR, '..');
const CONTROLLER = readFileSync(join(ROOT, 'src', 'Rest', 'FindController.php'), 'utf8');
const ABILITIES = readFileSync(join(ROOT, 'src', 'Abilities', 'Abilities.php'), 'utf8');
const config = JSON.parse(readFileSync(join(BENCH_DIR, 'config.json'), 'utf8')) as { manual_routes: Site['routes'] };

/** Index of the quote closing the PHP string literal that opens at `open`. */
function endOfString(source: string, open: number): number {
  for (let i = open + 1; i < source.length; i++) {
    if (source[i] === '\\') i++;
    else if (source[i] === source[open]) return i;
  }
  throw new Error(`unterminated string at ${open}`);
}

/** Index of the `]` closing the PHP array literal that opens at `open`. Strings and comments are skipped. */
function closing(source: string, open: number): number {
  let depth = 0;
  for (let i = open; i < source.length; i++) {
    const c = source[i];
    if (c === "'" || c === '"') i = endOfString(source, i);
    else if (source.startsWith('//', i)) i = source.indexOf('\n', i);
    else if (source.startsWith('/*', i)) i = source.indexOf('*/', i) + 1;
    else if (c === '[') depth++;
    else if (c === ']' && --depth === 0) return i;
  }
  throw new Error(`no ] for the array at ${open}`);
}

/** The text between the brackets of the first PHP array literal at or after `from`. */
function arrayAt(source: string, from: number): string {
  const open = from < 0 ? -1 : source.indexOf('[', from);
  if (open < 0) throw new Error('array not found: the PHP source no longer has the shape this test reads');
  return source.slice(open + 1, closing(source, open));
}

/** Each top-level `'key' => [ ... ]` of an array's text, mapped to the text between its brackets. */
function entries(inner: string): Map<string, string> {
  const out = new Map<string, string>();
  for (let i = 0; i < inner.length; i++) {
    const c = inner[i];
    if (c === '[') {
      i = closing(inner, i);
    } else if (c === "'" || c === '"') {
      const end = endOfString(inner, i);
      const arrow = /^\s*=>\s*\[/.exec(inner.slice(end + 1));
      if (arrow) {
        const open = end + arrow[0].length;
        out.set(inner.slice(i + 1, end), inner.slice(open + 1, closing(inner, open)));
        i = closing(inner, open);
      } else {
        i = end;
      }
    }
  }
  return out;
}

const quoted = (inner: string) => [...inner.matchAll(/'([^']*)'/g)].map((m) => m[1]);
const sorted = (keys: Iterable<string>) => [...keys].sort();

/** The REST route's args: name, and whether it is required. */
function restInputs(source: string) {
  const args = entries(arrayAt(source, source.search(/'args'\s*=>/)));
  return { names: sorted(args.keys()), required: sorted([...args].filter(([, body]) => /'required'\s*=>\s*true/.test(body)).map(([name]) => name)) };
}

/** The find-edit-location ability's input schema: property names and `required`. */
function abilityInputs(source: string) {
  const ability = source.search(/self::FIND_EDIT_LOCATION\s*=>/);
  const schema = entries(arrayAt(source, ability < 0 ? -1 : source.indexOf("'input_schema'", ability)));
  return { names: sorted(entries(schema.get('properties') ?? '').keys()), required: sorted(quoted(schema.get('required') ?? '')) };
}

const rest = restInputs(CONTROLLER);
const ability = abilityInputs(ABILITIES);
const shipped = sorted(new Set([...rest.names, ...ability.names]));

const benchSchema = TOOLS.find_edit_location.input_schema as { properties: Record<string, unknown>; required: string[] };
const bench = { names: sorted(Object.keys(benchSchema.properties)), required: sorted(benchSchema.required) };

describe('find_edit_location takes every input the shipped find takes', () => {
  it('reads the shipped inputs from the REST route and the ability', () => {
    // A parser that matched nothing would make every comparison below pass on two empty lists.
    expect(rest.names).toContain('url');
    expect(ability.names).toContain('url');
    expect(rest.names, 'the REST route and the ability take different inputs').toEqual(ability.names);
  });

  it('offers each shipped input, and nothing the REST route does not take', () => {
    expect(shipped.filter((name) => !bench.names.includes(name)), 'shipped find inputs missing from the bench tool').toEqual([]);
    expect(bench.names.filter((name) => !rest.names.includes(name)), 'bench inputs the REST route would ignore').toEqual([]);
  });

  it('requires what the shipped find requires', () => {
    expect(rest.required).toEqual(ability.required);
    expect(bench.required).toEqual(rest.required);
  });

  it('sends each input to the REST route under its own name (config.json manual_routes.find)', () => {
    const params = [...config.manual_routes.find.matchAll(/[?&](\w+)=\{(\w+)\}/g)].map(([, name, slot]) => ({ name, slot }));
    expect(params.filter(({ name, slot }) => name !== slot)).toEqual([]);
    expect(sorted(params.map(({ name }) => name))).toEqual(rest.names);
  });

  it('passes every input through to the request', async () => {
    const requested: URL[] = [];
    const fetchStub = (async (url: URL) => {
      requested.push(new URL(url));
      return new Response('{}', { status: 200 });
    }) as unknown as typeof fetch;
    const exec = liveTools({ url: 'http://localhost:8888', credentials: 'admin:abc', routes: config.manual_routes }, fetchStub);
    const input = Object.fromEntries(rest.names.map((name) => [name, `value of ${name}`]));
    await exec('find_edit_location', input);
    expect(requested).toHaveLength(1);
    expect(Object.fromEntries(requested[0].searchParams)).toEqual(input);
  });

  it('would fail if the shipped find gained an input', () => {
    // The positive control: an arg added to the route shows up as missing from the bench tool.
    const grown = CONTROLLER.replace(/('args'\s*=>\s*\[)/, "$1\n'expect_hash' => ['type' => 'string', 'required' => false],");
    expect(grown).not.toBe(CONTROLLER);
    expect(restInputs(grown).names.filter((name) => !bench.names.includes(name))).toEqual(['expect_hash']);
  });
});
