# Contributing to Voyager Wayfinder

Thanks for helping. This file covers who runs the project, what it will and won't take, how fast you can expect a reply, and how to set up, test and send a change.

## Who maintains it

Voyager Marketing maintains Wayfinder. Ben Whitlock ([@benwhit](https://github.com/benwhit)) is the lead maintainer and makes the calls on scope, design and releases. Decisions are made in public GitHub issues, so the reasoning stays where anyone can read it.

The plugin's display name is "Voyager Wayfinder" and its slug is `voyager-wayfinder`. A WordPress.org listing is a later decision. "Voyager" and "Voyager Wayfinder" are Voyager's names: the GPL covers the code, not the name, so a fork needs a name of its own.

## Scope: read-only

> Read-only mapping only: no write abilities, no page-builder editing, no memory feature.

Wayfinder maps how a site is built and where each thing is edited. It never changes the site it maps. Its only writes are its own data: the manifest, its snapshots and the notes people pin to items. A pull request that adds a write ability, edits page-builder content or adds a memory feature will be closed, however well it's built. If you're unsure, open an issue before you write code.

Other rules every change keeps (the full list is in [AGENTS.md](AGENTS.md)):

- **Deterministic.** The same site state gives the same bytes. Sort everything. No timestamps, random ids or translated strings in the manifest body.
- **No LLM calls** in the scan or render path.
- **Private by default.** Every read needs the `edit_pages` capability, notes writes need `manage_options`, and MCP exposure is off by default. Never emit option values outside the scanner's allowlist, user emails, credentials or absolute server paths.
- **Testable without WordPress.** Scanners take injectable lookups, so the unit suite runs with no database.

## Support window and releases

> Issues answered within two business days. Support hours stated in the readme. Releases batched every two weeks. Builder-specific sections are left to contributors.

Support hours are in the [README](README.md#support). "Answered" means a maintainer replies; a fix may take longer. Merged changes ship in the next two-weekly release. Security fixes follow [SECURITY.md](SECURITY.md) and don't wait for the batch.

Builder-specific sections (Bricks, Beaver Themer, Divi Theme Builder, Elementor and others) are left to contributors. The maintainers review them but won't write them. Use the "Builder support" issue template to propose one, and see [docs/good-first-issues.md](docs/good-first-issues.md) for scoped starters.

## Set up

You need PHP 8.1+, Composer, Node 22.18+ and Docker (for the local site).

```
composer install                 dev tools (the plugin has no runtime dependencies)
npm install                      installs the pinned @wordpress/env
npx @wordpress/env start         local site at http://localhost:8888 (admin / password)
```

Always call wp-env by its scoped name, `npx @wordpress/env`. A bare `npx wp-env` can resolve to an unrelated npm package.

Seed a fixture site. The seed deletes every page, post and fixture item first, so it runs only on the wp-env site, where the fixture mu-plugin is present.

```
npx @wordpress/env run cli wp eval-file wp-content/plugins/voyager-wayfinder/fixtures/seed.php
npx @wordpress/env run cli wp wayfinder stats
```

`fixtures/classic/seed.php` seeds the classic fixture (a classic child theme with Secure Custom Fields) instead. Either seed can follow the other.

When you're done, delete the site. Stopping it leaves its database and images on disk.

```
npx @wordpress/env destroy --force
```

## Test

```
composer test                    unit suite, no WordPress needed
composer phpstan                 static analysis
composer phpcs                   coding standards
```

CI runs the same unit suite, PHPStan and PHPCS on PHP 8.1, builds the plugin ZIP and runs the official Plugin Check on it, runs the benchmark's offline tests, and scans the commits a pull request adds for secrets with gitleaks.

### Benchmark, dry run

The benchmark measures whether the manual helps an AI agent answer questions about a site. The dry run replays recorded transcripts, so it needs no API key, no site and no network beyond npm.

```
cd bench
npm ci
npm test                         type check and the vitest suite
npm run dry-run                  replays test/recordings/ into runs/dry-run/
```

A live run needs the fixture site and an Anthropic API key, and costs money. You don't need one to contribute. See [bench/README.md](bench/README.md) and [bench/REPRODUCE.md](bench/REPRODUCE.md).

## Send a change

1. For anything bigger than a typo, open an issue first and say what you plan to do. A maintainer will confirm it fits the scope.
2. Branch from `main`. Keep one change per pull request.
3. Add or update unit tests. A scanner change needs a test in `tests/Unit/Scanners/`.
4. If your change makes the scanners emit something different for the same site (a new fact, or a renamed or removed one), bump the plugin version as [AGENTS.md](AGENTS.md) describes. `VersionGuardTest` fails until you do, and its message says what to add.
5. Run `composer test`, `composer phpstan` and `composer phpcs` before you push.
6. Fill in the pull request template.

Conventions: WordPress coding standards on top of PSR-12, `declare(strict_types=1)`, final classes, and the names in the AGENTS.md table (`vwf_` prefix, `wayfinder/v1` REST namespace, `voyager-wayfinder` text domain). Never use the bare `wayfinder` slug where WordPress.org could see it: it belongs to an unrelated plugin.

UI copy is sentence case and plain words, with no em dashes.

Contributions are licensed GPL-2.0-or-later, the same as the plugin.

## Questions

Ask in an [issue](https://github.com/voyager-marketing/voyager-wayfinder/issues/new/choose). Issues are for questions, bugs, features and builder support.

Everyone taking part follows the [code of conduct](CODE_OF_CONDUCT.md).
