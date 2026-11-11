import type Anthropic from '@anthropic-ai/sdk';
import { z } from 'zod';
import { cap, type ToolExecutor } from './tools.ts';

// The adapter condition: the agent reaches the site through the WordPress MCP Adapter, the way an
// agency connects an agent today, with no Wayfinder. This is a minimal MCP client for the adapter's
// HTTP transport, enough for tools/list and tools/call: JSON-RPC 2.0 over POST to one REST route,
// signed in with the bench's Application Password. `initialize` opens a session; every later request
// carries its Mcp-Session-Id and MCP-Protocol-Version headers. MCP Adapter 0.7.0 answers in plain
// JSON, not SSE (includes/Transport/HttpTransport.php).

export const PROTOCOL_VERSION = '2025-11-25';

/** The default server's tool that lists the abilities it can run. */
export const DISCOVER_TOOL = 'mcp-adapter-discover-abilities';

export interface McpSite {
  url: string;
  /** `user:application-password`, from fixtures/.credentials. */
  credentials: string;
  /** The adapter server's REST route, `adapter_endpoint` in config.json. */
  endpoint: string;
}

export interface McpClient {
  /** tools/list, as Anthropic tool definitions. */
  listTools(): Promise<Anthropic.Tool[]>;
  /** tools/call. Returns the text content, capped. An MCP error or an `isError` result throws, so the agent sees an error result. */
  callTool(name: string, input: unknown): Promise<string>;
}

const Rpc = z.object({ result: z.unknown().optional(), error: z.object({ code: z.number(), message: z.string() }).optional() });
const Initialized = z.object({ protocolVersion: z.string() });
const ToolList = z.object({
  tools: z.array(z.object({ name: z.string(), description: z.string().optional(), inputSchema: z.record(z.string(), z.unknown()) })),
  nextCursor: z.string().optional(),
});
const ToolResult = z.object({ content: z.array(z.object({ type: z.string(), text: z.string().optional() })), isError: z.boolean().optional() });

export function mcpClient(site: McpSite, fetchImpl: typeof fetch = fetch): McpClient {
  const url = new URL(site.endpoint, site.url);
  const authorization = `Basic ${Buffer.from(site.credentials).toString('base64')}`;
  let id = 0;
  let session: Promise<Record<string, string>> | undefined;

  const post = (body: object, headers: Record<string, string> = {}) =>
    fetchImpl(url, {
      method: 'POST',
      headers: { authorization, 'content-type': 'application/json', accept: 'application/json, text/event-stream', ...headers },
      body: JSON.stringify({ jsonrpc: '2.0', ...body }),
    });

  const resultOf = async (res: Response, method: string): Promise<unknown> => {
    const text = await res.text();
    let reply: z.infer<typeof Rpc>;
    try {
      reply = Rpc.parse(JSON.parse(text));
    } catch {
      throw new Error(`MCP ${method}: HTTP ${res.status}, not a JSON-RPC reply: ${text.slice(0, 200)}`);
    }
    if (reply.error) throw new Error(`MCP ${method}: ${reply.error.message} (${reply.error.code})`);
    if (!res.ok || reply.result === undefined) throw new Error(`MCP ${method}: HTTP ${res.status} ${text.slice(0, 200)}`);
    return reply.result;
  };

  const open = async (): Promise<Record<string, string>> => {
    const params = { protocolVersion: PROTOCOL_VERSION, capabilities: {}, clientInfo: { name: 'wayfinder-bench', version: '1' } };
    const res = await post({ id: ++id, method: 'initialize', params });
    const { protocolVersion } = Initialized.parse(await resultOf(res, 'initialize'));
    const sessionId = res.headers.get('mcp-session-id');
    if (!sessionId) throw new Error('MCP initialize: no Mcp-Session-Id header');
    const headers = { 'mcp-session-id': sessionId, 'mcp-protocol-version': protocolVersion };
    const ack = await post({ method: 'notifications/initialized' }, headers);
    if (!ack.ok) throw new Error(`MCP notifications/initialized: HTTP ${ack.status}`);
    return headers;
  };

  const request = async (method: string, params: object): Promise<unknown> => {
    session ??= open();
    return resultOf(await post({ id: ++id, method, params }, await session), method);
  };

  return {
    async listTools() {
      const tools: Anthropic.Tool[] = [];
      let cursor: string | undefined;
      do {
        const page = ToolList.parse(await request('tools/list', cursor ? { cursor } : {}));
        for (const t of page.tools) {
          tools.push({ name: t.name, description: t.description ?? '', input_schema: { ...t.inputSchema, type: 'object' } });
        }
        cursor = page.nextCursor;
      } while (cursor);
      return tools;
    },
    async callTool(name, input) {
      const result = ToolResult.parse(await request('tools/call', { name, arguments: input ?? {} }));
      const text = cap(result.content.map((c) => c.text ?? JSON.stringify(c)).join('\n'));
      if (result.isError) throw new Error(text);
      return text;
    },
  };
}

/**
 * Lists the adapter's tools once per run, so every question is offered the same list. Stops if any
 * Wayfinder ability is reachable: this condition is the baseline without the map, so the site must
 * keep `vwf_mcp_enabled` off. The default server runs abilities through its discover and execute
 * tools rather than listing them, so the discover tool's list is checked as well. Descriptions are
 * checked too, so a server that serves the manual, find or the brief under another plugin's name
 * (a plugin proxying Wayfinder's abilities) stops the run as well.
 */
export async function discoverAdapterTools(mcp: McpClient): Promise<Anthropic.Tool[]> {
  const tools = await mcp.listTools();
  if (!tools.length) throw new Error('the MCP Adapter lists no tools');
  const reachable = tools.flatMap((t) => [t.name, t.description ?? '']);
  if (tools.some((t) => t.name === DISCOVER_TOOL)) reachable.push(await mcp.callTool(DISCOVER_TOOL, {}));
  if (reachable.some((text) => /wayfinder/i.test(text))) {
    throw new Error('Wayfinder abilities are reachable over MCP, so the adapter condition would not be a baseline without Wayfinder. Turn vwf_mcp_enabled off on the site.');
  }
  return tools;
}

/** The adapter condition's executor: `http_get` and `rest_get` as in raw, every other name goes to the adapter. */
export const adapterTools = (base: ToolExecutor, mcp: McpClient): ToolExecutor => (name, input) =>
  name === 'http_get' || name === 'rest_get' ? base(name, input) : mcp.callTool(name, input);
