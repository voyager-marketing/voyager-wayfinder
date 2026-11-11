import { mkdtempSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterAll, describe, expect, it } from 'vitest';
import { loadPrivate, PRIVATE_DIR, privateSkipNote } from '../src/private.ts';
import { loadConfig } from '../src/run.ts';

// The real-site fixture is private bench data in bench/private/ (src/private.ts), with its own tests in
// bench/private/test/. Without it those tests do not exist here, so this file says so in the run's output. It
// writes to stdout itself: vitest keeps a passing file's console output to itself.
const present = loadPrivate();
if (!present) process.stdout.write(`${privateSkipNote('the real-site fixture\'s tests (its question set, answer key, snapshot pin and run 5 records)')}\n`);

describe('the private config loader', () => {
  const dir = mkdtempSync(join(tmpdir(), 'vwf-private-loader-'));
  afterAll(() => rmSync(dir, { recursive: true, force: true }));

  it('reads nothing from a folder with no config.json', () => {
    expect(loadPrivate(dir)).toBeUndefined();
  });

  it('throws on a config.json that is there but malformed, rather than running without it', () => {
    writeFileSync(join(dir, 'config.json'), JSON.stringify({ run5: { sets: { x: { site: 'x', sha256: 'not a hash', held_out: true, fixture: 'f' } } } }));
    expect(() => loadPrivate(dir)).toThrow();
  });

  it('reads the private repository snapshots are published on, and refuses one that is not owner/name', () => {
    writeFileSync(join(dir, 'config.json'), JSON.stringify({ snapshot_repo: 'example/private-bench-data' }));
    expect(loadPrivate(dir)?.snapshot_repo).toBe('example/private-bench-data');
    writeFileSync(join(dir, 'config.json'), JSON.stringify({ snapshot_repo: 'https://github.com/example' }));
    expect(() => loadPrivate(dir)).toThrow();
  });

  it('names where the data goes in its skip note', () => {
    expect(privateSkipNote('a test')).toBe('Skipped without private bench data: a test. bench/private/ is not in this checkout (bench/README.md, "Private fixtures").');
  });
});

describe.skipIf(!present)('the private bench data in this checkout', () => {
  it('adds fixtures beside the published ones, each writing its reports to a private folder', () => {
    for (const name of Object.keys(present!.fixtures)) {
      const { fixture } = loadConfig(name);
      expect(fixture.reports, name).toMatch(/^private\//);
    }
  });

  it('has its own tests, which vitest runs from bench/private/test/', () => {
    expect(readdirSync(join(PRIVATE_DIR, 'test')).filter((f) => f.endsWith('.test.ts')).length).toBeGreaterThan(0);
  });
});
