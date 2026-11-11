import type Anthropic from '@anthropic-ai/sdk';
import { z } from 'zod';
import { OPTION_NAMES, READ_ROOTS, readFile, runWp, WP_COMMANDS, type ContainerRun } from './wpcli.ts';

export type Condition = 'raw' | 'manual' | 'manual-preloaded' | 'adapter' | 'cli' | 'cli-manual';
export type ToolExecutor = (name: string, input: unknown) => Promise<string>;

export interface Site {
  url: string;
  /** `user:application-password`, from fixtures/.credentials. */
  credentials: string;
  /** wayfinder/v1 route templates from config.json; `{id}`, `{url}`, `{text}` and `{class}` are filled in. */
  routes: { brief: string; index: string; section: string; find: string };
}

export const MAX_CHARS = 20_000;
const TRUNCATED = '\n[truncated at 20,000 characters]';
/** Redirect hops `http_get` follows before it stops, as fetch does. */
export const MAX_REDIRECTS = 20;

/** A tool whose inputs are all strings. `required` defaults to every input. */
function tool(name: string, description: string, inputs: Record<string, string> = {}, required = Object.keys(inputs)): Anthropic.Tool {
  const properties = Object.fromEntries(Object.entries(inputs).map(([key, about]) => [key, { type: 'string', description: about }]));
  return { name, description, input_schema: { type: 'object', properties, required } };
}

export const TOOLS = {
  http_get: tool('http_get', 'Fetch a front-end page of the site by path, for example /about/. Returns the page text with HTML removed, capped at 20,000 characters.', {
    path: 'Path on the site, starting with /',
  }),
  rest_get: tool('rest_get', 'GET a WordPress REST API route as an administrator, for example /wp/v2/types or /wp/v2/pages?slug=about. The /wp-json prefix is optional. Returns the response body, capped at 20,000 characters.', {
    path: 'REST route, for example /wp/v2/types',
  }),
  wp: tool('wp', `Run a read-only WP-CLI command on the site's server. Give the arguments after wp, for example post list --post_type=page --fields=ID,post_title. Only these commands run: ${WP_COMMANDS.join(', ')}. option get reads only ${OPTION_NAMES.join(', ')}. Anything else is refused. There is no shell, so pipes and redirects do not work. Returns the command's output, capped at 20,000 characters.`, {
    args: 'The WP-CLI arguments after wp',
  }),
  read_file: tool('read_file', `Read a file in the site's wp-content folder, under ${READ_ROOTS.join(', ')}, for example themes/twentytwentyfive/theme.json. A folder, such as mu-plugins, lists its entries one per line, with / after each subfolder. Capped at 20,000 characters.`, {
    path: 'Path under wp-content, for example themes/twentytwentyfive/theme.json',
  }),
  manual_brief: tool('manual_brief', 'Read the Wayfinder brief: a short summary of how this site is built and where the common things are edited.'),
  manual_index: tool('manual_index', 'Read the Wayfinder index: one line per item on the site (post types, fields, templates, parts, patterns, menus, bindings, settings) with its edit path.'),
  manual_section: tool('manual_section', 'Read the full JSON detail for one Wayfinder item or section by id, for example pt:service or part:footer.', {
    id: 'Item or section id from the index',
  }),
  // Takes every input the shipped find takes (test/tools.parity.test.ts). Run 4 and earlier offered url and text only.
  find_edit_location: tool(
    'find_edit_location',
    'Ask Wayfinder where something on a front-end page is edited. Give the page path or URL and, optionally, text visible on that page, a CSS class or block name, or both. '
      + 'hits lists every place the match lives, on this page or elsewhere on the site, best first; when ambiguous is true the top two are equally good answers. '
      + 'Each hit on a block carries an anchor (entity, post, path, fingerprint fp, expect_hash).',
    {
      url: 'Page path or URL',
      text: 'Visible text to locate',
      class: 'A CSS class a block carries, or a block name. With text, only blocks matching both',
    },
    ['url'],
  ),
};

type ToolName = keyof typeof TOOLS;

export const CONDITION_TOOLS: Record<Condition, ToolName[]> = {
  raw: ['http_get', 'rest_get'],
  manual: ['http_get', 'rest_get', 'manual_brief', 'manual_index', 'manual_section', 'find_edit_location'],
  'manual-preloaded': ['http_get', 'rest_get', 'manual_section', 'find_edit_location'],
  // Raw's site access plus the MCP tools the site's adapter lists, discovered at run time (src/mcp.ts),
  // so the baseline is never weaker than raw.
  adapter: ['http_get', 'rest_get'],
  // Raw's site access plus read-only WP-CLI and the theme and plugin files, the shell an agency gives an
  // agent (src/wpcli.ts). cli-manual is the same agent with the manual, so the pair isolates what the map adds.
  cli: ['http_get', 'rest_get', 'wp', 'read_file'],
  'cli-manual': ['http_get', 'rest_get', 'wp', 'read_file', 'manual_brief', 'manual_index', 'manual_section', 'find_edit_location'],
};

/** Whether a condition's tools run commands in the wp-env cli container. */
export const usesContainer = (condition: Condition): boolean => CONDITION_TOOLS[condition].includes('wp');

/** A condition's tools. `adapter` appends the MCP tools discovered on the site, or recorded in a transcript. */
export const toolsFor = (condition: Condition, mcpTools: Anthropic.Tool[] = []): Anthropic.Tool[] => [
  ...CONDITION_TOOLS[condition].map((n) => TOOLS[n]),
  ...(condition === 'adapter' ? mcpTools : []),
];

export const cap = (text: string): string =>
  text.length <= MAX_CHARS ? text : text.slice(0, MAX_CHARS - TRUNCATED.length) + TRUNCATED;

const ENTITIES = { nbsp: ' ', lt: '<', gt: '>', quot: '"', amp: '&' };

export function stripHtml(html: string): string {
  return html
    .replace(/<(script|style|noscript|svg|template)\b[\s\S]*?<\/\1>/gi, ' ')
    .replace(/<!--[\s\S]*?-->/g, ' ')
    .replace(/<br\s*\/?>|<\/(p|div|h[1-6]|li|tr|section|header|footer|nav|main|article)>/gi, '\n')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&#x([0-9a-f]+);/gi, (_, hex: string) => String.fromCodePoint(parseInt(hex, 16)))
    .replace(/&#(\d+);/g, (_, dec: string) => String.fromCodePoint(Number(dec)))
    .replace(/&(nbsp|lt|gt|quot|amp);/g, (_, name: keyof typeof ENTITIES) => ENTITIES[name])
    .replace(/[ \t\r]+/g, ' ')
    .replace(/ *\n[\s]*/g, '\n')
    .trim();
}

const PathInput = z.object({ path: z.string().min(1) });
const IdInput = z.object({ id: z.string().min(1) });
const FindInput = z.object({ url: z.string().min(1), text: z.string().optional(), class: z.string().optional() });

/** Tools against a running site. Paths are resolved on the site's origin and nowhere else. */
export function liveTools(site: Site, fetchImpl: typeof fetch = fetch): ToolExecutor {
  const origin = new URL(site.url).origin;
  const authorization = `Basic ${Buffer.from(site.credentials).toString('base64')}`;
  const get = async (route: string): Promise<string> => {
    const url = new URL(route, site.url);
    if (url.origin !== origin) throw new Error(`only paths on ${origin} are allowed`);
    const res = await fetchImpl(url, { headers: { authorization } });
    return cap(`HTTP ${res.status}\n${await res.text()}`);
  };
  /**
   * A front-end page, anonymously. Redirects are followed here rather than by fetch, so the agent sees each hop as
   * `HTTP 301 Location: <url>` before the page: run 5's lr-01 asked about a redirect that fetch followed silently
   * (from run 6). A hop to another origin is listed and not followed.
   */
  const page = async (route: string): Promise<string> => {
    let url = new URL(route, site.url);
    if (url.origin !== origin) throw new Error(`only paths on ${origin} are allowed`);
    const hops: string[] = [];
    for (;;) {
      const res = await fetchImpl(url, { headers: {}, redirect: 'manual' });
      const location = res.status >= 300 && res.status < 400 ? res.headers.get('location') : null;
      if (location === null) return cap([...hops, `HTTP ${res.status}\n${stripHtml(await res.text())}`].join('\n'));
      await res.body?.cancel();
      hops.push(`HTTP ${res.status} Location: ${location}`);
      const next = new URL(location, url);
      if (next.origin !== origin) return cap([...hops, `Not followed: ${next.origin} is not ${origin}.`].join('\n'));
      if (hops.length >= MAX_REDIRECTS) return cap([...hops, `Not followed: stopped after ${MAX_REDIRECTS} redirects.`].join('\n'));
      url = next;
    }
  };
  const fill = (template: string, values: Record<string, string | undefined>) =>
    template.replace(/\{(\w+)\}/g, (_, key: string) => encodeURIComponent(values[key] ?? ''));

  return async (name, input) => {
    switch (name) {
      case 'http_get':
        return page(PathInput.parse(input).path);
      case 'rest_get': {
        const path = PathInput.parse(input).path.replace(/^\/?(wp-json\/?)?/, '/');
        return get(`/wp-json${path}`);
      }
      case 'manual_brief':
        return get(site.routes.brief);
      case 'manual_index':
        return get(site.routes.index);
      case 'manual_section':
        return get(fill(site.routes.section, IdInput.parse(input)));
      case 'find_edit_location':
        return get(fill(site.routes.find, FindInput.parse(input)));
      default:
        throw new Error(`unknown tool: ${name}`);
    }
  };
}

const ArgsInput = z.object({ args: z.string() });

/** The cli conditions' executor: `wp` and `read_file` run in the wp-env cli container, every other name as in `base`. */
export const cliTools = (base: ToolExecutor, run: ContainerRun): ToolExecutor => async (name, input) => {
  if (name === 'wp') return cap(await runWp(run, ArgsInput.parse(input).args));
  if (name === 'read_file') return cap(await readFile(run, PathInput.parse(input).path));
  return base(name, input);
};
