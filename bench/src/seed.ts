import { spawnSync, type SpawnSyncOptions, type SpawnSyncReturns } from 'node:child_process';

export type Spawn = (command: string, args: string[], options: SpawnSyncOptions) => Pick<SpawnSyncReturns<Buffer>, 'status' | 'error'>;

/**
 * Seeds a fixture on the local wp-env site, the way AGENTS.md and bench.yml run a seed: from the repository root,
 * with the pinned @wordpress/env. `seed` is the script's path inside wp-env. The seed switches the site's theme and
 * fields plugin itself, so either fixture can follow the other.
 */
export function seedFixture(seed: string, repoRoot: string, spawn: Spawn = spawnSync): void {
  const args = ['@wordpress/env', 'run', 'cli', 'wp', 'eval-file', seed];
  // npx is npx.cmd on Windows, which only a shell resolves.
  const result = spawn('npx', args, { cwd: repoRoot, stdio: 'inherit', shell: process.platform === 'win32' });
  if (result.error) throw new Error(`could not run npx ${args.join(' ')}: ${result.error.message}`);
  if (result.status !== 0) throw new Error(`seeding failed: npx ${args.join(' ')} exited ${result.status}`);
}
