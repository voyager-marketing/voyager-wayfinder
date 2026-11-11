// Records the find route's answers for one question set, for test/find-eval.test.ts to replay with no site.
// Run from bench/ against a seeded wp-env site:
//   node test/record-find-eval.ts edit-safety-2 http://localhost:8888
// It reseeds the block fixture first, as `--find-eval --question-set` does, and keeps only the fields the eval reads.
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { FindResult, runFindEval } from '../src/findEval.ts';
import { BENCH_DIR } from '../src/run.ts';

const [questionSet = 'edit-safety-2', siteUrl = 'http://localhost:8888'] = process.argv.slice(2);
const recorded: Record<string, { status: number; body: unknown }> = {};

/** Only what FindResult reads, so the recording stays small and says what the eval depends on. */
function trim(route: string, status: number, body: string): unknown {
  if (status !== 200) return body;
  const json = JSON.parse(body);
  if (!route.includes('/find?')) return { edit: json.edit ?? null };
  const r = FindResult.parse(json);
  return {
    route: r.route, matched: r.matched, ambiguous: r.ambiguous, message: r.message,
    chain: r.chain.map(({ id, label, edit }) => ({ id, label, edit })),
    hits: r.hits.map(({ rank, id, block_path, snippet, on_route, anchor }) => ({ rank, id, block_path, snippet, on_route, anchor: anchor && { entity: anchor.entity, post: anchor.post, path: anchor.path } })),
    verify: { changes_since: r.verify.changes_since },
  };
}

const fetchImpl = (async (url: URL, init?: RequestInit) => {
  const res = await fetch(url, init);
  const body = await res.text();
  const route = `${url.pathname}${url.search}`;
  recorded[route] = { status: res.status, body: trim(route, res.status, body) };
  return new Response(body, { status: res.status });
}) as unknown as typeof fetch;

const { report } = await runFindEval({ questionSet, seed: true, siteUrl, fetchImpl, out: join(BENCH_DIR, 'runs', 'find-eval-recording') });
const file = join(BENCH_DIR, 'test', `find-eval-${report.site}.json`);
const sorted = Object.fromEntries(Object.entries(recorded).sort(([a], [b]) => (a < b ? -1 : 1)));
writeFileSync(file, `${JSON.stringify({ site: report.site, question_set: report.question_set, responses: sorted }, null, 2)}\n`);
console.log(`Recorded ${Object.keys(sorted).length} responses to ${file}`);
