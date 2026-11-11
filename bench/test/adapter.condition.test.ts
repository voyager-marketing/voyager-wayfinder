import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { adapterTools, discoverAdapterTools, mcpClient, PROTOCOL_VERSION, type McpClient } from '../src/mcp.ts';
import { adapterEndpointFor, BENCH_DIR, loadConfig, runBench, type Transcript } from '../src/run.ts';
import { toolsFor } from '../src/tools.ts';

afterEach(() => vi.unstubAllGlobals());

const RECORDING = 'adapter.claude-haiku-4-5-20251001.si-01.json';
const recording = JSON.parse(readFileSync(join(BENCH_DIR, 'test', 'recordings', RECORDING), 'utf8')) as Transcript;
const site = { url: 'http://localhost:8888', credentials: 'admin:abc', endpoint: '/wp-json/mcp/mcp-adapter-default-server' };

/**
 * MCP Adapter 0.7.0's HTTP transport as the fixture answered it: a session from `initialize`, the
 * default server's three tools, JSON replies, unknown tools as a JSON-RPC error, and an ability the
 * site does not expose as an `isError` result.
 */
function fakeAdapter(abilities: string[]) {
  const seen: { url: string; method: string; headers: Record<string, string> }[] = [];
  const fetchStub = (async (url: URL, init: RequestInit) => {
    const headers = init.headers as Record<string, string>;
    const body = JSON.parse(String(init.body));
    seen.push({ url: url.toString(), method: body.method, headers });
    const json = (payload: object, extra: Record<string, string> = {}) =>
      new Response(JSON.stringify({ jsonrpc: '2.0', id: body.id, ...payload }), { status: 200, headers: { 'content-type': 'application/json', ...extra } });
    const text = (t: string, isError = false) => json({ result: { content: [{ type: 'text', text: t }], isError } });

    if (body.method === 'initialize') return json({ result: { protocolVersion: PROTOCOL_VERSION, capabilities: {} } }, { 'mcp-session-id': 'sess-1' });
    if (headers['mcp-session-id'] !== 'sess-1') return json({ error: { code: -32600, message: 'Missing Mcp-Session-Id header' } });
    if (body.method === 'notifications/initialized') return new Response(null, { status: 202 });
    if (body.method === 'tools/list') {
      return json({ result: { tools: recording.mcp_tools?.map((t) => ({ name: t.name, description: t.description, inputSchema: t.input_schema })) } });
    }
    const { name, arguments: args } = body.params;
    if (name === 'mcp-adapter-discover-abilities') return text(JSON.stringify({ abilities: abilities.map((n) => ({ name: n })) }));
    if (name === 'mcp-adapter-execute-ability') {
      return args.ability_name === 'core/get-site-info'
        ? text('{"success":true,"data":{"name":"Riverbend Plumbing"}}')
        : text(`Ability "${args.ability_name}" is not exposed via MCP (mcp.public!=true)`, true);
    }
    return json({ error: { code: -32602, message: `Tool not found: ${name}` } });
  }) as unknown as typeof fetch;
  return { fetchStub, seen };
}

// The baseline keeps raw's site access and adds the adapter, so it is never weaker than raw: on the
// fixture the adapter's tools reach only core's three abilities.
it('adapter condition offers rest_get beside the adapter tools', async () => {
  const names = (tools: { name: string }[]) => tools.map((t) => t.name);
  expect(names(toolsFor('adapter'))).toEqual(['http_get', 'rest_get']);
  expect(names(toolsFor('adapter', recording.mcp_tools))).toEqual(['http_get', 'rest_get', 'mcp-adapter-discover-abilities', 'mcp-adapter-get-ability-info', 'mcp-adapter-execute-ability']);
  expect(names(toolsFor('raw', recording.mcp_tools))).toEqual(['http_get', 'rest_get']);

  // http_get and rest_get run on the site as in raw. Every other name goes to the adapter, so the manual tools stay out of reach.
  const calls: string[] = [];
  const base = async (name: string) => (calls.push(`site ${name}`), 'HTTP 200\nok');
  const mcp: McpClient = { listTools: async () => [], callTool: async (name) => (calls.push(`adapter ${name}`), 'ok') };
  const execute = adapterTools(base, mcp);
  for (const name of ['http_get', 'rest_get', 'mcp-adapter-discover-abilities', 'manual_brief']) await execute(name, {});
  expect(calls).toEqual(['site http_get', 'site rest_get', 'adapter mcp-adapter-discover-abilities', 'adapter manual_brief']);
});

describe('adapter condition', () => {
  it('is in config.json, with the adapter endpoint', () => {
    const config = JSON.parse(readFileSync(join(BENCH_DIR, 'config.json'), 'utf8'));
    expect(config.conditions).toEqual(['raw', 'manual', 'manual-preloaded', 'adapter', 'cli', 'cli-manual']);
    expect(config.adapter_endpoint).toBe(site.endpoint);
  });

  it('uses a fixture\'s own MCP server where it names one, and the default server elsewhere', () => {
    const { config } = loadConfig();
    // A site where a plugin turns the default server off and serves its own read-only one.
    expect(adapterEndpointFor(config, { adapter_endpoint: '/wp-json/example-mcp/read' })).toBe('/wp-json/example-mcp/read');
    for (const [name, set] of [['block', undefined], ['classic', undefined], ['block', 'edit-safety-2']] as const) {
      const { config, fixture } = loadConfig(name, set);
      expect(adapterEndpointFor(config, fixture), `${name} ${set ?? ''}`).toBe(site.endpoint);
    }
  });

  it('opens a session, then lists and calls tools with its headers and the Application Password', async () => {
    const { fetchStub, seen } = fakeAdapter(['core/get-site-info']);
    const mcp = mcpClient(site, fetchStub);
    expect(await mcp.listTools()).toEqual(recording.mcp_tools);
    expect(await mcp.callTool('mcp-adapter-execute-ability', { ability_name: 'core/get-site-info', parameters: {} })).toBe('{"success":true,"data":{"name":"Riverbend Plumbing"}}');

    expect(seen.map((s) => s.method)).toEqual(['initialize', 'notifications/initialized', 'tools/list', 'tools/call']);
    expect(new Set(seen.map((s) => s.url))).toEqual(new Set(['http://localhost:8888/wp-json/mcp/mcp-adapter-default-server']));
    expect(seen.every((s) => s.headers.authorization === `Basic ${btoa('admin:abc')}`)).toBe(true);
    expect(seen[0].headers['mcp-session-id']).toBeUndefined();
    for (const s of seen.slice(1)) expect(s.headers).toMatchObject({ 'mcp-session-id': 'sess-1', 'mcp-protocol-version': PROTOCOL_VERSION });
  });

  it('turns an MCP error or an isError result into a thrown error, so the agent sees an error result', async () => {
    const mcp = mcpClient(site, fakeAdapter(['core/get-site-info']).fetchStub);
    await expect(mcp.callTool('mcp-adapter-execute-ability', { ability_name: 'voyager-wayfinder/get-brief', parameters: {} })).rejects.toThrow('is not exposed via MCP');
    await expect(mcp.callTool('rest_get', { path: '/' })).rejects.toThrow('Tool not found: rest_get');
  });

  it('stops before a run when a Wayfinder ability is reachable over MCP', async () => {
    const hidden = await discoverAdapterTools(mcpClient(site, fakeAdapter(['core/get-site-info', 'core/get-user-info']).fetchStub));
    expect(hidden).toEqual(recording.mcp_tools);
    const exposed = mcpClient(site, fakeAdapter(['core/get-site-info', 'voyager-wayfinder/get-brief']).fetchStub);
    await expect(discoverAdapterTools(exposed)).rejects.toThrow(/Turn vwf_mcp_enabled off/);
  });

  it('stops when a listed tool serves Wayfinder under another plugin\'s name, as on a server that lists abilities as tools', async () => {
    const listing = (description: string): McpClient => ({
      listTools: async () => [{ name: 'voyager-orbit-get-post', description: 'Get one post.', input_schema: { type: 'object' } },
        { name: 'voyager-orbit-site-map', description, input_schema: { type: 'object' } }],
      callTool: async () => { throw new Error('no discover tool on this server'); },
    });
    await expect(discoverAdapterTools(listing('Read the site\'s plugins and theme.'))).resolves.toHaveLength(2);
    await expect(discoverAdapterTools(listing('Returns the Wayfinder brief for the site.'))).rejects.toThrow(/Turn vwf_mcp_enabled off/);
  });

  it('replays its recording in a dry run with the recorded MCP tools, and reports the condition', async () => {
    vi.stubGlobal('fetch', () => Promise.reject(new Error('network call in a dry run')));
    const out = mkdtempSync(join(tmpdir(), 'vwf-bench-'));
    const { report } = await runBench({ dryRun: true, conditions: ['adapter'], out, date: '2026-10-03' });

    expect(report.runs).toHaveLength(1);
    // si-01: 4,502 in and 141 out over three calls, at Haiku's $1 / $5 per MTok.
    expect(report.runs[0]).toMatchObject({ condition: 'adapter', model: 'claude-haiku-4-5-20251001', correct: 1, questions: 1, tool_calls: { median: 2 }, input_tokens: { median: 4502 }, cost_usd: 0.005207 });
    expect(report.results[0]).toMatchObject({ question: 'si-01', answer: 'Riverbend Plumbing', reason: 'matches', turns: 3 });
    expect(readFileSync(join(out, 'fixture-latest.md'), 'utf8')).toContain('| adapter | claude-haiku-4-5-20251001 | 1 | 1/1 | 100.0% |');
  });

  it('fails a recording whose tool calls name a tool the condition does not offer', async () => {
    const dir = mkdtempSync(join(tmpdir(), 'vwf-recordings-'));
    const { mcp_tools: _dropped, ...withoutTools } = recording;
    writeFileSync(join(dir, RECORDING), JSON.stringify(withoutTools));
    const out = mkdtempSync(join(tmpdir(), 'vwf-bench-'));
    await expect(runBench({ dryRun: true, recordings: dir, out, date: '2026-10-03' })).rejects.toThrow(
      'recording calls mcp-adapter-discover-abilities, which the condition does not offer',
    );
  });
});
