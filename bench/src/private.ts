import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { z } from 'zod';

/**
 * Bench data that cannot be published: a fixture taken from a real, private site, with its questions, answer key,
 * snapshot pin and recorded reports. It lives in `bench/private/`, which git ignores, and this repository never
 * holds a copy. When the folder is absent every public fixture and test still runs, and the tests that need it
 * say they skipped.
 *
 * `bench/private/config.json` adds fixtures in the shape config.json's take, with paths relative to bench/ like
 * theirs (`private/questions/<set>.yaml`). A private fixture writes its reports to `private/reports/` unless it
 * names another folder, so a private run never lands in the published `reports/`. `run5` registers the private
 * question sets run 5 froze, the reports it recorded on them and where their transcripts live.
 */
export const PRIVATE_DIR = fileURLToPath(new URL('../private', import.meta.url));

const str = z.string();
export const PrivateRun5Set = z.object({
  site: str,
  sha256: str.regex(/^[0-9a-f]{64}$/),
  held_out: z.boolean(),
  /** The fixture the set runs on, and its question set name there when it is not the fixture's own questions. */
  fixture: str,
  question_set: str.optional(),
  /** A snapshot of a real site, which S5 needs beside a seeded fixture. */
  snapshot: z.boolean().default(false),
  /** The run 5 summary prints the el-01 smoke finding beside this set. */
  el01_finding: z.boolean().default(false),
});
export const PrivateConfig = z.object({
  about: str.optional(),
  /** `owner/name` of the private repository snapshot tarballs are published on, for `--build-snapshot`. */
  snapshot_repo: str.regex(/^[\w.-]+\/[\w.-]+$/).optional(),
  fixtures: z.record(str, z.unknown()).default({}),
  run5: z.object({
    sets: z.record(str, PrivateRun5Set).default({}),
    /** Report files in `private/reports/`, replayed beside the public run 5 reports. */
    reports: z.array(str).default([]),
    transcripts: z.object({ repo: str, release: str, holds: str }).optional(),
  }).optional(),
});
export type PrivateConfig = z.infer<typeof PrivateConfig> & { dir: string };

/** The private config in `dir`, or undefined when there is none. A config that is there but malformed throws. */
export function loadPrivate(dir = PRIVATE_DIR): PrivateConfig | undefined {
  const file = join(dir, 'config.json');
  if (!existsSync(file)) return undefined;
  return { ...PrivateConfig.parse(JSON.parse(readFileSync(file, 'utf8'))), dir };
}

/** One line for a test file that skips without the private data, so the skip shows in the run's output. */
export function privateSkipNote(what: string): string {
  return `Skipped without private bench data: ${what}. bench/private/ is not in this checkout (bench/README.md, "Private fixtures").`;
}
