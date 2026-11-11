import { describe, expect, it } from 'vitest';
import { liveTools, MAX_REDIRECTS } from '../src/tools.ts';

const routes = { brief: '/b', index: '/i', section: '/s?id={id}', find: '/f?url={url}' };

/** A site whose paths answer from `pages`: a string is a 200 page, `[status, location]` a redirect. */
function site(pages: Record<string, string | [number, string]>) {
  const seen: { url: string; redirect?: RequestRedirect; auth?: string }[] = [];
  const fetchStub = (async (url: URL, init: RequestInit) => {
    seen.push({ url: url.toString(), redirect: init.redirect, auth: (init.headers as Record<string, string>).authorization });
    const page = pages[url.pathname] ?? [404, ''];
    if (typeof page === 'string') return new Response(page, { status: 200 });
    const [status, location] = page;
    return location ? new Response(null, { status, headers: { location } }) : new Response('<p>missing</p>', { status });
  }) as unknown as typeof fetch;
  return { exec: liveTools({ url: 'http://localhost:8888', credentials: 'admin:abc', routes }, fetchStub), seen };
}

describe('http_get on a redirect', () => {
  it('reports a 301 hop, its status and Location, before the page', async () => {
    const { exec, seen } = site({ '/old/': [301, 'http://localhost:8888/new/'], '/new/': '<p>New page</p>' });
    expect(await exec('http_get', { path: '/old/' })).toBe('HTTP 301 Location: http://localhost:8888/new/\nHTTP 200\nNew page');
    // fetch does not follow on its own, and the page stays anonymous at every hop.
    expect(seen).toEqual([
      { url: 'http://localhost:8888/old/', redirect: 'manual', auth: undefined },
      { url: 'http://localhost:8888/new/', redirect: 'manual', auth: undefined },
    ]);
  });

  it('lists every hop in order and resolves a relative Location against the hop', async () => {
    const { exec, seen } = site({ '/a': [301, '/a/'], '/a/': [302, 'b/'], '/a/b/': '<p>B</p>' });
    expect(await exec('http_get', { path: '/a' })).toBe('HTTP 301 Location: /a/\nHTTP 302 Location: b/\nHTTP 200\nB');
    expect(seen.map((s) => s.url)).toEqual(['http://localhost:8888/a', 'http://localhost:8888/a/', 'http://localhost:8888/a/b/']);
  });

  it('lists a hop to another origin and does not follow it', async () => {
    const { exec, seen } = site({ '/out/': [301, 'https://example.com/landing/'] });
    expect(await exec('http_get', { path: '/out/' })).toBe('HTTP 301 Location: https://example.com/landing/\nNot followed: https://example.com is not http://localhost:8888.');
    expect(seen).toHaveLength(1);
  });

  it(`stops after ${MAX_REDIRECTS} hops, as fetch would`, async () => {
    const { exec, seen } = site({ '/loop/': [302, '/loop/'] });
    const out = await exec('http_get', { path: '/loop/' });
    expect(out.split('\n')).toHaveLength(MAX_REDIRECTS + 1);
    expect(out).toMatch(new RegExp(`Not followed: stopped after ${MAX_REDIRECTS} redirects\\.$`));
    expect(seen).toHaveLength(MAX_REDIRECTS);
  });

  it('returns a page with no redirect as before, and a 3xx with no Location as the page', async () => {
    const { exec } = site({ '/about/': '<p>About</p>', '/choice/': [300, ''] });
    expect(await exec('http_get', { path: '/about/' })).toBe('HTTP 200\nAbout');
    expect(await exec('http_get', { path: '/choice/' })).toBe('HTTP 300\nmissing');
    expect(await exec('http_get', { path: '/gone/' })).toBe('HTTP 404\nmissing');
  });

  it('still refuses a path off the site before any request', async () => {
    const { exec, seen } = site({});
    await expect(exec('http_get', { path: 'https://example.com/' })).rejects.toThrow(/only paths on/);
    expect(seen).toEqual([]);
  });
});
