# voyager-wayfinder

Agent contract for this repo. CLAUDE.md points here.

## What this is

Voyager Wayfinder is a WordPress plugin that scans a site from inside WordPress and keeps a living manual of how it is built and where each thing is edited. One canonical manifest feeds two readers: people in wp-admin and AI agents through compact context tiers. Pre-1.0, maintained by Voyager. The work queue and the Session end steps below are for Voyager's own sessions; an outside contributor's agent follows [CONTRIBUTING.md](CONTRIBUTING.md) instead.

- Format contract for AI output: docs/ai-context-format.md
- Spec, project notes and Discoveries: in the maintainers' own tracker, not in this repo
- Work queue: Linear team VOY, label `component/wayfinder`, project Wayfinder MVP

## Names

| Thing | Value |
|---|---|
| Display name | Voyager Wayfinder ("Wayfinder" in menus) |
| Plugin slug, main file, text domain | `voyager-wayfinder` |
| PHP namespace | `Voyager\Wayfinder\` |
| Composer package | `voyager-marketing/voyager-wayfinder` |
| Prefix for options, hooks, transients, tables | `vwf_` |
| REST namespace | `wayfinder/v1` |
| Ability namespace and category | `voyager-wayfinder/`, `voyager-wayfinder` |
| WP-CLI root | `wp wayfinder` |
| Admin menu slug | `voyager-wayfinder` |
| Fixture prefix | `vwf-fixture` (theme, binding source), `vwf-classic` (classic fixture theme), `vwf_` (options) |

Do not use the bare `wayfinder` slug anywhere WordPress.org could see it. It belongs to an unrelated block editor plugin.

## Stack

- PHP 8.1+, WordPress 6.9+ (Abilities API in core). Test against the latest WordPress in wp-env.
- Composer dev deps mirror voyager-orbit: phpunit ^10.5, phpstan ^2.2 with szepeviktor/phpstan-wordpress, php-stubs/wordpress-stubs ^7.1, squizlabs/php_codesniffer with wp-coding-standards/wpcs.
- Local site: wp-env (Docker). This repo carries no site content; the fixture site is seeded by code.
- Fixture versions are pinned in `.wp-env.json`: WordPress 7.1.2, ACF 6.8.10, MCP Adapter 0.7.0, twentytwentyfive 1.5, and for the classic fixture twentytwentyone 2.9 and Secure Custom Fields 6.9.5. They sit in the manifest body, so bumping one is a deliberate change that also reruns the benchmark. Secure Custom Fields is mapped into wp-content/plugins rather than listed in `plugins`, so wp-env installs it without activating it: it deactivates ACF when it activates, and only the classic seed turns it on.
- Benchmark: Node 22.18+ (runs the TypeScript directly, no build), vitest and @anthropic-ai/sdk in `bench/`. See bench/README.md.
- License: GPL-2.0-or-later.

## Layout (target)

```
voyager-wayfinder.php     plugin header and bootstrap
readme.txt                WordPress.org-format readme (ships in the ZIP; no listing yet)
.wordpress-org/           listing assets for a later WordPress.org decision: blueprints/blueprint.json (never ships)
blueprint.json            the README's Playground link: installs from main on GitHub (never ships)
assets/readme/            the README banners and the social preview, with build.py (never ships)
src/                      PSR-4 Voyager\Wayfinder\
  Manifest/               value objects, canonical serializer, hashing
  Scanners/               one class per manifest section
  Render/                 human view, AI tiers, export
  Admin/ Inspector/ Rest/ Cli/ Abilities/ Notes/
data/                     shipped lookup data (plugin-roles.json, php-sinks.json)
tests/Unit/               WordPress-free unit tests
fixtures/                 seed.php, truth.json, fixture theme and mu-plugins
  classic/                the classic fixture: seed.php, truth.json, vwf-classic theme
bench/                    benchmark harness, questions, reports
docs/                     format contract
```

## Rules

1. **Determinism.** Same site state in, same bytes out. Sort everything. No timestamps, random ids or translated strings in the manifest body. Labels are English: core's labels are read in en_US or taken from the fixed map in `src/Scanners/EnglishLabels.php`, and labels registered by themes and plugins are recorded as registered. The header carries `generated_at`; hashes cover the body only. Volatile counts and usage lists live in `stats`, outside every hash.
2. **No LLM calls** in the scan or render path.
3. **Security.** Every read surface requires auth (`edit_pages`). Notes writes require `manage_options`. MCP exposure is off by default. Never emit option values outside the scanner's allowlist, user emails, credentials or absolute server paths.
4. **Stable ids** per docs/ai-context-format.md. Slugs over row ids.
5. **Testable without WordPress.** Scanners take injectable lookups (the pattern in voyager-orbit `src/Modules/Blocks/Services/ReferenceResolver.php`) so the unit suite runs with no database.
6. **Port, don't import,** from voyager-orbit. Name the source path and sha in a docblock.
7. **Conventions.** WordPress coding standards, `declare(strict_types=1)`, final classes, names from the table above.
8. **UI copy:** sentence case, plain words, no em dashes.
9. **Work units:** one Linear issue per session, one PR per issue, `Fixes VOY-XXX` in the PR body. Never push to main directly.
10. **Versioning.** A change that makes the scanners emit something different for the same site (a new fact, a renamed or removed one, a new item kind) bumps the plugin version in the same PR: the `Version` header in `voyager-wayfinder.php`, `Plugin::VERSION` and `Stable tag` in `readme.txt`, minor while we are on 0.x. A `v*` tag runs `release.yml`, which refuses a tag that disagrees with any of the three. A refresh under a new version moves notes pinned to an item's old hash onto its new one (Refresher), so new facts do not flag every note. `VersionGuardTest` enforces it: it hashes every section the plugin registers (code, content_model, integrations, layout, routes and site, including the theme gates), scanned from the unit fixture's fixed inputs in `tests/Support` (`FakeCodeSource`, `FakeRouteSource`, `FixedSite`), and compares with `tests/fixtures/manifest-versions.json`. A new section must join that hash, or be listed with its reason in the test's `UNGUARDED`. When it fails, bump the version and append `"<version>": "<hash from the failure message>"` as a normal JSON entry. Never change, remove or reorder a recorded entry; CI compares the parsed file with the base branch and rejects that. A change that only adds an input, a renderer line or a CLI option does not need a bump.
11. **Extend core, don't duplicate.** When WordPress core or the WordPress AI team ships something that covers part of the map, such as the proposed site-orientation abilities, Wayfinder calls it for that layer and drops its own copy. Wayfinder keeps what core won't do: the "where is this edited?" lookup, the client manual with change flags, and the template, route and field maps. Shape new abilities to sit beside core's, not to compete with them.

## Verification commands

```
composer install              dev tools (no runtime dependencies)
npm install                   installs the pinned @wordpress/env
composer test                 unit suite
composer phpstan              static analysis
composer phpcs                coding standards (CI runs it in the checks job)
npx @wordpress/env start      local site at http://localhost:8888 (admin / password)
npx @wordpress/env run cli wp eval-file wp-content/plugins/voyager-wayfinder/fixtures/seed.php
npx @wordpress/env run cli wp eval-file wp-content/plugins/voyager-wayfinder/fixtures/classic/seed.php
npx @wordpress/env run cli wp wayfinder stats
npx @wordpress/env destroy --force   deletes the local site (see Session end)
cd bench && npm test          type check and vitest, offline
cd bench && npm run dry-run   replays recorded transcripts into runs/dry-run/
```

These become runnable as the scaffold and fixture issues land. Each issue's `## Fitness metric` is the authority for that issue.

Always call it by the scoped name: `npx @wordpress/env` runs the official package, using the local pin when installed.

The plugin is mapped to `wp-content/plugins/voyager-wayfinder` in `.wp-env.json` rather than listed in `plugins`, because wp-env names a listed plugin after the checkout folder (the worktree's folder name in a worktree). An `afterStart` script activates it. Parallel worktrees need their own ports: `WP_ENV_PORT=8892 WP_ENV_TESTS_PORT=8893 npx @wordpress/env start`.

Run no more than two sessions with a local site at once on one machine. Sessions without a site (unit tests, bench dry run, docs) don't count. Before starting a site, count the running ones: `docker ps --filter label=com.docker.compose.service=wordpress` lists one container per site.

### WordPress.org checks

CI's `Plugin Check` job runs the official Plugin Check on the built ZIP and fails on any error. To run it locally, unzip a build (the CI artifact, or the `build-zip` steps) to `build/voyager-wayfinder`, which wp-env sees inside the mapped checkout, then:

```
npx @wordpress/env run cli wp plugin install plugin-check --activate
npx @wordpress/env run cli wp plugin check /var/www/html/wp-content/plugins/voyager-wayfinder/build/voyager-wayfinder --slug=voyager-wayfinder
npx @wordpress/env run cli wp plugin deactivate plugin-check
```

Deactivate it before seeding: each seed refuses to run when the active plugins differ from its own list.

To try a build in a throwaway site, see the Playground recipe in the README. `.wordpress-org/blueprints/blueprint.json` installs from WordPress.org, which only works once a listing exists. The README's Playground link runs `blueprint.json` instead, which installs this repository's `main` with Playground's `git:directory` resource. A clone has no `vendor/`, so it writes a PSR-4 autoloader for `src/` before it activates the plugin. `PlaygroundBlueprintTest` checks those steps, runs that autoloader, and keeps the rest of its steps the same as the WordPress.org blueprint's, so the two demo sites never drift.

### Never delete the plugin or a fixture theme on wp-env

wp-env mounts this checkout as `wp-content/plugins/voyager-wayfinder`, and the fixture themes from `fixtures/`. Deleting a plugin or theme removes its folder, so `wp plugin uninstall voyager-wayfinder`, `wp plugin delete`, `wp theme delete vwf-classic` or Delete in wp-admin would delete the files on your disk, `.git` included (this happened once). `fixtures/mu-plugins/vwf-dev-guard.php` refuses all of these. It stops any plugin or theme folder that holds `.git`, `composer.json` or `AGENTS.md` (none of them ship in the ZIP), and both fixture themes. WordPress has already run the uninstall procedure when the guard refuses, so a refused uninstall still empties the database. To test uninstall.php, use:

```
npx @wordpress/env run cli wp plugin uninstall voyager-wayfinder --skip-delete
```

Then reseed: `wp plugin activate voyager-wayfinder` and the fixture seed. Don't remove the guard to get a deletion through. To watch a real deletion, map a build copy in a `.wp-env.override.json` (gitignored), and restart wp-env after rebuilding it: a folder recreated under a running mount shows up empty in the container.

`fixtures/seed.php` resets and reseeds the fixture site and rewrites `fixtures/truth.json` from its constants. Commit truth.json whenever the seed changes it. The reset deletes every page, post, service and team member, so the seed runs only where the fixture mu-plugin defines `VWF_FIXTURE`. Every row it creates has a reserved id (90001 to 90099), so edit paths and `manifest_hash` come back the same on each reseed.

`fixtures/classic/seed.php` makes the same site the classic fixture (Ashford Remodeling) and rewrites `fixtures/classic/truth.json`: Secure Custom Fields in place of ACF, the `vwf-classic` child theme of Twenty Twenty-One, a `project` post type with a `project_type` taxonomy, a Company Settings options page, and field groups with a repeater, flexible content and a relationship field. Its rows and menus have reserved ids 90100 to 90199. Each fixture's mu-plugin (`fixtures/mu-plugins/vwf-fixture-core.php`, `vwf-classic-core.php`) registers only while its own theme is active, so neither site carries the other's content model. Each seed switches the fields plugin and the theme, then reruns itself in a fresh WP-CLI process where `init` registers the right model, and reseeding the block fixture restores its `manifest_hash`. The benchmark picks a fixture with `--fixture` (bench/README.md).

## Parallel sessions

Several missions can run on this repo at once, each claiming one issue in its own worktree or cloud session. Up to four at a time, and five only when no two touch the same files and none bumps the version. The limit is review and merge, not compute: every extra session is one more PR to review and, often, one more conflict to resolve.

- **One version bump in flight.** An issue that changes scanner output bumps the version (rule 10). Two such PRs open together both append the next version to `tests/fixtures/manifest-versions.json` and edit the plugin header, `Plugin::VERSION` and `readme.txt`'s Stable tag. Start them together if you like, but merge them one at a time: the second merges main, takes the next version and recomputes its hash.
- **Shared files.** `docs/ai-context-format.md`, `bench/README.md`, `AGENTS.md`, `voyager-wayfinder.php` and `readme.txt` are touched by most PRs. Say in the dispatch prompt which session owns a section when two will edit the same file.
- **Merge check.** Before merging a batch, merge every open PR in the batch onto current main in one local checkout, in the intended order, and run `composer test`, `vendor/bin/phpcs -q` and `cd bench && npm test` on the result. Merge only when that combined tree is green. Run it in the main checkout, not a temporary worktree outside it: one Registrations test reads its frames' paths and fails when the checkout sits elsewhere.
- **Merge order.** CI and workflow fixes first, then version bumps one at a time, then features that don't bump, then bench changes (they share `bench/README.md` and `bench/reports/`).

### Cloud sessions

A cloud container cannot reach downloads.wordpress.org or the apk and apt mirrors, so `npx @wordpress/env start` fails at the image build, and `composer install` cannot fetch phpstan's dist.

- **wp-env:** start the site from the stock `wordpress:php8.2` and `wordpress:cli-php8.2` images with the compose file wp-env writes and the same mounts. If Docker Hub rate-limits the pull, use `mirror.gcr.io/library/wordpress`. Earlier cloud sessions verified their Done means this way.
- **phpstan:** download the release phar (2.2.16 was used) and clone `szepeviktor/phpstan-wordpress` and `php-stubs/wordpress-stubs` by hand, then run it with `phpstan.neon.dist`. If that fails too, say so in the PR; CI runs phpstan on every push.
- **Composer as root:** set `COMPOSER_ALLOW_SUPERUSER=1`.
- Anything still not runnable goes in the PR under "Needs local run", with the exact commands.

## Session end

Voyager sessions only. Run the Stop Protocol from the mission skill: open the PR, comment on the issue, add an entry to the project's Discoveries page in the maintainers' tracker, and read back each write before reporting it. Head the entry `## <date> · <issue id> · <short title>`, never with a running session number: parallel sessions count from the same last entry, and on 2026-10-03 three of them each wrote a "Session 35".

If Docker or the disk fails and the session cannot run its fitness metric, leave the PR as a draft with auto-merge off, and say so in the hand-off.

A session that started a wp-env site deletes it as its last step, after the fitness metric has passed and the hand-off write-backs are read back. Stopping is not enough: a stopped site keeps its database, its built images and a copy of WordPress on disk. Nothing is lost, because `fixtures/seed.php` rebuilds the fixture.

```
npx @wordpress/env destroy --force
```

`--force` skips the confirmation prompt. Without it the command asks for a yes, gets none in a Claude Code session, and cancels. `destroy` also removes `mariadb:lts` when no other container uses it, so the next start pulls it again.

If a start failed, `destroy` exits 1 with "Environment not initialized" and removes nothing, while the failed start has left volumes, built images and its work directory behind. Clear them with these three commands, run one at a time (a worktree-isolated session refuses them chained into one block). The first prints the work directory, for example `~/.wp-env/wp-env-voyager-wayfinder-e79a1a5a` (under `WP_ENV_HOME` when that is set); put it in the other two.

```
node -e "require('@wordpress/env/lib/config').loadConfig(process.cwd()).then(c => console.log(c.workDirectoryPath))"
docker compose -f '<work directory>/docker-compose.yml' down --volumes --remove-orphans --rmi local
rm -rf '<work directory>'
```

`docker ps -a` should then list nothing named `wp-env-<checkout folder>-`.
