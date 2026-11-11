# Wayfinder benchmark

Measures whether the Wayfinder manual helps an AI agent understand a WordPress site. The same agent answers the same questions about a seeded fixture site under six conditions, and the report compares accuracy, tokens, tool calls and cost. There are two fixtures, each with its own question set and ground truth, written by its seed script, never by the scanner under test:

| Fixture | Site | Questions | Ground truth | Seed |
|---|---|---|---|---|
| `block` (default) | Riverbend Plumbing: a block child theme of Twenty Twenty-Five, ACF free, a block bindings source | `questions/fixture.yaml`, 40 | `fixtures/truth.json` | `fixtures/seed.php` |
| `classic` | Ashford Remodeling: a classic child theme of Twenty Twenty-One with most of its structure in Secure Custom Fields (a repeater, flexible content, a relationship field and an options page) | `questions/fixture-classic.yaml`, 30 | `fixtures/classic/truth.json` | `fixtures/classic/seed.php` |

Both run on the same wp-env site, one at a time. Each seed switches the site to its own theme and fields plugin, so either can follow the other. `fixtures` in `config.json` maps each name to its site name, question set, truth file and seed.

The block fixture has a second question set, `edit-safety` (`questions/fixture-edit-safety.yaml`, 20 questions), on the same seed and truth file. `--question-set edit-safety` asks it in place of the block set; see "The edit-safety set" below.

It has a third, `edit-safety-2` (`questions/fixture-edit-safety-2.yaml`, 30 questions), held out from the fixes the edit-safety set's misses drive; see "The edit-safety-2 set" below.

## Run it

Node 22.18 or newer. The CLI runs the TypeScript sources directly, so there is no build step.

```
cd bench
npm ci
npm test                 type check plus the vitest suite, offline
npm run dry-run          replays test/recordings/ and writes a report to runs/dry-run/
npm run bench            the real run: needs the fixture site and an API key
```

`npm run bench` needs:

1. The fixture site running and seeded (`npx @wordpress/env start`, then the seed command in AGENTS.md). The seed writes `fixtures/.credentials` (an Application Password for admin), which `rest_get`, the manual tools and the adapter condition use. With `--fixture block` or `--fixture classic` the harness seeds that fixture itself before a live run (`npx @wordpress/env run cli wp eval-file <seed>` from the repository root), so the site always matches the answer key. Without `--fixture` it seeds nothing and uses the block fixture, as before.
2. `ANTHROPIC_API_KEY` in the environment or in `bench/.env` (gitignored).
3. For the manual conditions, the `wayfinder/v1` routes. Their paths live in `config.json` under `manual_routes`, so the REST issue can change them without touching code.
4. For the adapter condition, the MCP Adapter's endpoint, `adapter_endpoint` in `config.json`. `.wp-env.json` installs and activates the adapter at a pinned version.
5. For the cli conditions, the pinned `@wordpress/env` installed at the repository root (`npm ci` there, as bench.yml does) and the `docker` CLI on the PATH. Their commands run in the site's wp-env cli container.

Filters narrow a run: `--condition raw`, `--model claude-haiku-4-5-20251001`, `--question cm-01` (each repeatable), `--site-url`, `--out`. `--concurrency 4` keeps four question runs in flight; the default is one. `--fixture classic` runs the classic fixture's questions against its truth and names the report `fixture-classic-<date>`. `--question-set edit-safety` runs the block fixture's edit-safety set against the block truth, seeds the block fixture first, and names the report `fixture-edit-safety-<date>`; a fixture's `question_sets` in `config.json` lists the sets it has. Raw transcripts go to `runs/<timestamp>/` (gitignored); reports go to `reports/`. `.github/workflows/bench.yml` runs the whole thing on workflow_dispatch; its seed step seeds the block fixture, and `--fixture classic` in its args reseeds the site as the classic one.

A full run is 2 models x 6 conditions over the question set, and every cell a hypothesis reads runs 3 times. Under R1 to R7 (`hypothesis_set: "run3"`, the default since run 3) that is manual and adapter on both models plus Haiku manual-preloaded, so 22 passes: 880 question runs on the block fixture and 660 on the classic one. Under H1 to H6 only the two H3 cells repeat, 640 and 480. Runs 3 and 4 had four conditions, 18 passes under R1 to R7; `--condition` picks them again. A question that throws after the SDK's own retries is rerun from the start twice, 30s and 90s apart, before the run stops.

`--run-label run3` names the dated report `<site>-<date>-run3`. `--summarize reports/<a>.json --summarize reports/<b>.json --out reports/run3-summary.md` combines run 3 reports across fixtures: a rule passes only where every fixture's report passes it. `--summarize-run4 reports/<a>-run4.json ... --run3 reports/<a>-run3.json ... --out reports/run4-summary.md` writes the run 4 summary: each run 4 report beside the run 3 report on the same question set, cell by cell, then the best baseline per cell (the higher of raw and adapter, a tie going to fewer input tokens) with R1 to R7 restated against it by `judgeRun3` itself, and any set without a run 3 partner, such as edit-safety, in its own section (`src/run4.ts`). `REPRODUCE.md` walks through a full rerun from a clean clone.

`--summarize-run5 reports/<held-out>-run5.json ... --regression reports/<frozen>-run5-regression.json ... --earlier reports/<frozen>-run4.json ... --find-eval-report reports/<site>-<date>-find-eval.json ... --out reports/run5-summary.md` writes the run 5 summary (`src/run5.ts`, committed before any run 5 result existed):
- **S1 to S6** (spec, "Run 5: plan and hypotheses"). S1 to S4 come from each held-out report's intervals and hold only where they hold on both held-out sets. S5 reads the manual sizes every run recorded. S6 comes from the held-out sets' find evals, pooled.
- **Ties.** Sonnet's best baseline for S3 and S4 is the highest pooled accuracy of raw, adapter and cli. When two tie, S3 and S4 must hold against each.
- **The frozen sets.** Their run 5 runs carry only the Wayfinder cells. Each is set beside the last earlier run that had those cells. R1 to R7 are restated against the best baseline cell any `--earlier` report recorded on the same question set, labelled seen questions.
- **Refusals.** It refuses a report on a question set whose sha256 is not the frozen one (`FROZEN_SETS`, the values the question-set tests pin), and a held-out run at any cap but 24.

`--max-turns 24` gives each question up to 24 model calls instead of 12, and `--repetitions 1` runs every cell once instead of repeating the cells the rules read. `--sensitivity-of reports/<site>-<date>-run3.json` makes the run a turn cap sensitivity run against that report (below).

`--intervals reports/<site>-<date>.json` prints the report's 95% intervals and McNemar tests, recomputed from its results, for any report (see "Intervals" below). It makes no API calls and writes nothing.

## Conditions

| Condition | Tools | System prompt |
|---|---|---|
| `raw` | `http_get(path)`, `rest_get(path)` | shared skeleton |
| `manual` | raw tools plus `manual_brief`, `manual_index`, `manual_section(id)`, `find_edit_location(url, text?, class?)` | shared skeleton |
| `manual-preloaded` | raw tools plus `manual_section`, `find_edit_location` | skeleton plus the brief and index in a block marked `cache_control: ephemeral` |
| `adapter` | `http_get(path)`, `rest_get(path)` plus the MCP tools the site's MCP Adapter lists, discovered at run time | shared skeleton |
| `cli` | raw tools plus `wp(args)`, read-only WP-CLI, and `read_file(path)` under wp-content themes, plugins and mu-plugins | shared skeleton |
| `cli-manual` | cli tools plus the four manual tools `manual` has | shared skeleton |

`http_get` returns the front-end page as text with HTML stripped, anonymously. It follows a redirect itself and lists each hop first, as `HTTP 301 Location: <url>`, so the agent sees that the path redirects and where; a hop to another origin is listed and not followed (from run 6: through run 5 fetch followed redirects silently, which hid lr-01's redirect). `rest_get` is authenticated as admin. Every tool result is capped at 20,000 characters, and no tool reaches outside the site's origin. The loop stops at 12 model calls (`--max-turns` changes it); a run that hits the cap counts as wrong.

`find_edit_location` takes every input the shipped find takes: `url`, `text` and `class` (a CSS class or block name), sent to `manual_routes.find` under the same names, and it returns the route's JSON as is, so the agent sees the ranked hits, the `ambiguous` flag and each block hit's anchor. Find has no anchor input; anchors are part of its answer. `test/tools.parity.test.ts` reads the inputs from `src/Rest/FindController.php` and the find-edit-location ability's input schema in `src/Abilities/Abilities.php`, and fails when find gains an input the bench tool lacks. Runs 1 to 4 offered `url` and `text` only, with a shorter description, so run 5's Wayfinder cells are not comparable with run 4's.

`adapter` is the named baseline: an agent connected the way agencies connect one today, through the standard WordPress MCP Adapter, with no Wayfinder. The fixture runs MCP Adapter 0.7.0, pinned in `.wp-env.json`. `src/mcp.ts` talks to its default server at `adapter_endpoint` (`/wp-json/mcp/mcp-adapter-default-server`): JSON-RPC over POST, signed in with the same Application Password, with a session opened by `initialize` (protocol 2025-11-25) and its `Mcp-Session-Id` sent on every later request. The tools are listed once per run, so every question is offered the same list, and each transcript records it as `mcp_tools`. On the fixture the list is the default server's three tools, `mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info` and `mcp-adapter-execute-ability`, and the abilities they reach are core's `core/get-site-info`, `core/get-user-info` and `core/get-environment-info`. An MCP tool returns its text content; an MCP error or an `isError` result comes back to the agent as an error result. `http_get` and `rest_get` run on the site as in raw, and every other tool name goes to the adapter, so the manual tools are out of reach. The adapter agent keeps raw's REST access because the adapter alone reaches only those three core abilities: without REST it could not even list the site's plugins, and a named baseline weaker than raw would not be a fair comparison.

A fixture can name its own `adapter_endpoint` when its site serves another MCP server. On some real sites the default server does not exist, because a plugin filters `mcp_adapter_create_default_server` to false and serves a read-only server of its own. That server is what an agent connecting to the site over MCP gets, so the fixture's adapter condition uses it, and a report records the route as `adapter_endpoint` (from run 5).

Wayfinder's own abilities stay hidden: they reach MCP only when the `vwf_mcp_enabled` option is on, and it is off by default. The run checks this before the first question. If a Wayfinder ability is in the tool list or in the discover tool's list, it stops.

The adapter is active in every condition, not only this one, so every condition answers questions about the same site. The seed lists it in `truth.json` (`plugin:mcp-adapter`, role `ai`) and stops if the active plugins differ from the ones it declares. The manual lists it too, as `mcp-adapter (ai)` in the brief's plugins line.

### The cli conditions

`cli` is the agent an agency gives a shell in Claude Code: WP-CLI and the theme and plugin files, read-only, beside raw's `http_get` and `rest_get`. `cli-manual` is the same agent with the manual's four tools, so `cli` against `cli-manual` shows what the map adds to one agent. In a report `cli` is a baseline condition and `cli-manual` a Wayfinder one (`BASELINE_CONDITIONS` and `WAYFINDER_CONDITIONS` in `src/report.ts`), so the losses, the intervals and the best baseline per cell include them. `src/wpcli.ts` holds both tools:

- **`wp(args)`** runs one WP-CLI command in the wp-env cli container with `docker exec` (`dockerExecRunner` in `src/wpcli.ts`, below). The arguments are split like shell words, but no shell sees them: they reach `docker exec` as an array.
- **Only read-only commands run** (`WP_COMMANDS`): listing and gets for posts and post meta, post types, terms and term meta, taxonomies, menus and menu locations, themes, plugins, sidebars and widgets, roles and capabilities, and rewrite rules, plus `help`. WP-CLI 2.12 has no pattern command, so synced patterns, templates and template parts are read as posts (`post list --post_type=wp_block`).
- **`option get`** reads only the scanner's option allowlist (`OptionAllowlist::OPTIONS` in `src/Scanners/OptionAllowlist.php`), and the test fails when the two lists differ. `wp wayfinder` is not on the list, so the cli agent cannot reach the map through WP-CLI either.
- **Everything else is refused before any process starts:** a write command, `eval`, `db`, `shell`, an option off the allowlist, the global parameters that run code or reach another site (`--exec`, `--require`, `--ssh`, `--http`, `--path`, `--url`, `--context`, `--prompt`), and shell operators such as `|` and `;`. The agent gets an error result that names the refused command, not the code after it.
- **`read_file(path)`** reads a file, or lists a folder, under wp-content `themes`, `plugins` and `mu-plugins`. It refuses a path that leaves those folders, and `plugins/voyager-wayfinder`, where wp-env maps this checkout with the answer keys, the questions and the bench's Application Password. In the container a `php -r` one-liner (`READER`) checks the path again after resolving symlinks, reads at most 64 KB, and describes a binary file instead of printing it.
- **Output** is capped at 20,000 characters, like every tool. WP-CLI's warnings follow its output, and a failed command comes back as an error result with WP-CLI's message.
- **`docker exec`, not `wp-env run` (from run 6).** Every `wp-env run` rewrites the work directory's `wp-env-cache.json` with no lock, so two at once can leave the cache without the runtime `wp-env start` saved, and from then on every call says "Environment not initialized. Run `wp-env start` first." That is what broke run 5's cli and cli-manual cells, and the fix then was to queue every call, about 0.9 s each whatever `--concurrency` was. Now each call is one `docker exec` in the cli container, with no wp-env process, so the cache is never touched and calls run side by side. `wp-env start`, `destroy` and the seeds still go through wp-env.
  - **The same command as `wp-env run cli`.** wp-env runs `docker compose -f <work folder>/docker-compose.yml exec -w /var/www/html --user <uid:gid> -T cli <command>`. The runner runs `docker exec --interactive --user <uid:gid> --workdir /var/www/html <container> <command>`: the same user (wp-env's own `get-host-user.js`, 1000:1000 on Windows), the same folder, stdin attached as compose's default is, no TTY. The environment is the container's own, which both inherit. A failed command reports exit status 1, as `wp-env run` does whatever the command's code, so the agent reads the same error either way.
  - **Which container.** The runner works out the folder `wp-env start` wrote `docker-compose.yml` to, as wp-env's `load-config.js` names it (`wpEnvWorkDir`, ported because wp-env does not export it, and because its `loadConfig` reads and may rewrite the cache). The compose project is that folder's name as docker compose normalizes it, or `COMPOSE_PROJECT_NAME` when that is set (`composeProject`). It lists every compose project's `cli` container and keeps its own project's, so a worktree's site or a snapshot site running beside it is never picked. There is no fallback to `wp-env run`: a missing or stopped container fails the check below, naming the project and what docker listed.
  - **A cap, not a queue.** At most `cli_exec_limit` calls (8, in `config.json`) are in flight in the container at once, across every question run; the rest wait. Unset means no cap.
- **A live run checks the container** before the first question, again before the first question of every cli and cli-manual cell, and once more after the last, and stops if a check fails. Each check finds the container again from the site's compose project, then runs `wp option get blogname` and a `themes` listing in it.
- **The tool error guard** (`src/toolErrors.ts`) fails a cell, and the run, when the cell's tools break. Once the cell's last question is in, it fails when its tool calls together returned errors on more than 20% of at least 10 calls, or one tool did on more than half of at least 10 of its own. The message names the cell, the counts and each broken tool's first error. It applies to every condition. The second rule is there because a cell's working tools hide a dead one: run 5's edit-safety-2 cli-manual cells stayed at 14% to 18% as a whole while every container call failed. One tool errs more often than a cell does, so its bar is higher. In run 5, with the init errors taken out, no cell went past 10% and no tool past 22% (Haiku's `wp`: bad `cap list` arguments and refused options), so neither rule trips a working cell. The report records every tool's calls and errors per question run and per cell (`tools`, below).

Nothing in the tools names a fixture: they read whichever site the checkout's wp-env runs. There is no SSH path. `test/cli.condition.test.ts` checks the refusals against a fake container that must see no command, runs the container's PHP against a temporary folder when PHP is installed, and replays the two cli recordings. `test/wpcli-exec.test.ts` checks the exec runner against a fake docker and a stand-in docker CLI: the arguments, the project it picks with others present, the check on a missing or stopped container, the cap, and six calls in flight where the queued runner ran one. With the pinned wp-env installed at the repository root, on Linux or macOS, it also runs the real wp-env with a stand-in `docker` first on the PATH and checks both runners pass docker the same user, folder and command and give the agent the same text. CI's bench job does not install wp-env, so those two tests skip there.

`node src/cli.ts --exec-parity` checks the two runners on the running site, with no model: 72 read-only `wp` calls (`--calls`) at 6 in flight (`--concurrency`) through `docker exec`, then the same calls through the queued `wp-env run`. It prints each runner's wall time, time per call and "Environment not initialized" count, lists any call whose output differs, and exits 1 unless every output matches and the exec runner never said "Environment not initialized". `--fixture <name>` checks that fixture's site, a snapshot's included. It starts and seeds nothing.

Models come from `config.json`. From run 6 no agent model is sent a temperature, so each runs at its provider's default and two cells differ only in the model. Sonnet 5 rejects `temperature`, `top_p` and `top_k` with a 400, so the default is the one setting every model accepts. Runs 1 to 5 sent Haiku 4.5 `temperature: 0` and Sonnet nothing. Every model's `params` stays empty, and the config refuses one that sets anything. The judge keeps `temperature: 0` (`judge_params`), since one judge grades every cell. Each report's header records the policy (`temperature_policy`).

## Questions

`questions/fixture.yaml` holds 40 questions: content_model 8, edit_location 10, layout_routes 8, data_flow 6, settings_integrations 4, change_impact 4. Each answer spec names references into truth.json, not values:

```yaml
answer:
  type: set                                  # exact | set | contains_all | judge
  refs: ["post_types[*].slug"]
```

| Reference | Means |
|---|---|
| `site.title` | a key path |
| `post_types[*].slug` | `[*]` fans out over an array |
| `post_types[id=pt:service].edit_path` | select array items whose field equals a value |
| `keys[used_in=part:footer].key` | on an array field, the selector matches by membership |

A selector that matches anything other than exactly one item fans out like `[*]`.

The header carries `questions_sha256`, the sha256 of the canonical JSON of the question list. Edit a question and the bench refuses to run until the header is updated, so every change shows in the diff. `node src/cli.ts --hash-questions` prints the new value, and `--hash-questions --fixture classic` the classic set's.

`questions/fixture-classic.yaml` holds the classic fixture's 30 questions in the same six categories: content_model 6, edit_location 7, layout_routes 6, data_flow 5, settings_integrations 3, change_impact 3 (`CLASSIC_CATEGORY_COUNTS` in `src/questions.ts`). Its references point into `fixtures/classic/truth.json`. It was frozen before any run used it, at sha256 `4511d7c994aabf5bd16fe64a880ef7dd06bebc2202ced544d78a30bd3b02f9ce`, and `test/questions.classic.test.ts` pins that value and the block set's, so an edit fails the tests even with the header updated. The test also grades a reply built from each code-graded question's expected values and expects it to pass.

The classic set asks what a classic, field-heavy site hides: fields shown through `get_field( ..., 'option' )` from an options page, a repeater, a flexible content field and a relationship field, the parent theme's `page.php` rendering pages with the child theme's header and footer, a landing template that never shows the page's own content, and a link hard coded in a template. 26 of its 30 questions are graded in code (exact 6, set 11, contains_all 9). The 4 judge questions (el-06, el-07, lr-06, df-01) are the ones whose answer is an explanation rather than a value.

### The edit-safety set

`questions/fixture-edit-safety.yaml` holds 20 questions on the block fixture, 5 in each of four categories (`EDIT_SAFETY_CATEGORY_COUNTS` in `src/questions.ts`). They ask what the manual and find gained after run 3, which the two frozen sets barely touch: lock and client-can-edit facts, class and List View name search, every home of a match, and block anchors. Each prompt is put the way a client ticket would put it.

| Category | Asks |
|---|---|
| `locks` | which blocks the client role (Editor, Wayfinder's default) can and cannot change, and why: a footer value behind a settings page, a content-only group, a group locked with `templateLock: all`, a block that cannot be removed, the footer template part |
| `multi_home` | text with more than one home: a line typed into a page and also in a synced pattern, which home a given URL shows, the same wording twice on one page, a fact on two pages |
| `selectors` | a CSS class (`className`) or a List View name (`metadata.name`) to the block, its page and its edit screen, a style variation (`is-style-outline`) included |
| `stale_edit` | given an anchor taken before an edit (`{"entity","post","path","fp"}` plus the text the block had), whether the block is unchanged, moved, changed or gone, or where it is now |

The answers are under `edit_safety` in `fixtures/truth.json`, which `fixtures/seed.php` writes from its own constants for rows it added for this set: three pages (Commercial plumbing, Financing, Maintenance plan, ids 90060 to 90062) and a synced pattern (Financing offer, 90070). Lock and removal facts follow core's rules (a block's own `lock`, else its parent's `templateLock`), what the client role can do comes from its capabilities, which the seed checks against the site, and the fingerprints in the stale_edit prompts are computed by the seed as `docs/ai-context-format.md` defines them. None of it comes from the scanner under test. The new rows sit in no menu, use neither the CTA banner nor the page-contact template, and show no business info value, so every block set question's expected values and grader rivals are the same as before; the only existing truth line that moved is `counts.pt:page` (4 to 7), which no question reads.

19 of the 20 are graded in code (exact 10, set 5, contains_all 4); lk-05, why the client cannot change the footer layout, goes to the judge. A yes or no, a status word or a block path is an `exact` answer whose rivals are the other answers truth holds for the same field, so `yes or no` and `changed or unchanged` fail.

It was frozen before run 4, before any run saw it, at sha256 `69eb2c8401ba4ff31666e968dce3cf8ac31c3c5153c4920a70dbadb9fe372cc9`. `test/questions.edit-safety.test.ts` pins that value, grades a reply built from each code-graded question's expected values (it passes) and an empty one (it fails), and checks every stale_edit prompt carries the anchor truth.json records. `--hash-questions --question-set edit-safety` prints its hash. There are no recordings for it, so `--dry-run --question-set edit-safety` stops unless `--recordings <dir>` names some.

### The edit-safety-2 set

`questions/fixture-edit-safety-2.yaml` holds 30 held-out questions on the block fixture, 6 in each of five categories (`EDIT_SAFETY_2_CATEGORY_COUNTS` in `src/questions.ts`). The edit-safety set's misses drive the run 5 product fixes, so that set cannot also be the evidence that they work. This one was written from `fixtures/seed.php` and `fixtures/truth.json` only, with no scan, manual output, or run 3 or run 4 transcript read, and frozen before any of those fixes merged. `--question-set edit-safety-2` runs it and names the report `fixture-edit-safety-2-<date>`.

| Category | Asks |
|---|---|
| `locks` | what a lock lets an Editor do to a block: add a line to a rate card under `templateLock: insert`, drag a line inside it, remove a line whose own `lock` allows it, move a step under `templateLock: all`, add to an unlocked group (`templateLock: false`) inside a locked one, and list what cannot be deleted |
| `client_can` | what the Editor role's capabilities allow, apart from any lock: the main menu and a template (`edit_theme_options`), the site title (`manage_options`), a synced pattern another user published, and a locked line's text |
| `multi_home` | text with more than one home: a phrase on three pages across both sets' rows, a pattern line with a plain copy on another page, and the same words twice on one page |
| `selectors` | a class or List View name to its page, edit screen, block name or count, a class inside a synced pattern included |
| `stale_edit` | an anchor taken before an edit: a changed price, a lock added since (fingerprints leave out `lock` and `className`, so the block is unchanged), a deleted line, a class added and the block moved, and a block moved into a new group |

The answers are under `edit_safety_2` in truth.json, which the seed writes from its own constants for the rows it added for this set: three pages (Pricing, Our guarantee, Emergencies, ids 90080 to 90082) and a synced pattern (Same-day service, 90071). It adds `can_move` and `can_insert` to the edit-safety lock facts, following core's `canMoveBlock()` and `canInsertBlockType()`, and lists the text each page shows. The seed stops if these rows reuse one of the edit-safety set's classes, List View names or tracked texts, or place its pattern. So the `edit_safety` section is unchanged, and so is every block set and edit-safety expected value and grader rival; the only existing truth line that moved is `counts.pt:page` (7 to 10), which no question reads.

29 of the 30 are graded in code (exact 21, set 3, contains_all 5); cc-26, why the client cannot drop the site header on the Contact page, goes to the judge.

Each of the 8 questions whose answer is an edit screen or a block path carries `find`: the page, the text the person saw and the class their developer named, for the find eval. The agent never sees it. The hash covers it, and the test checks each input against truth.json: the page shows the text, the class is on the page, and the page is one the asked-about item shows on.

It was frozen at sha256 `79b1b58aef25744644e6dfc104550afb654338bd89897963d14f1a5aec73bcb7`. `test/questions.edit-safety-2.test.ts` pins that value. It grades a reply built from each code-graded question's expected values (it passes), an empty reply and one missing a list item (they fail), and the other answer alone or beside the right one (it fails). The one exception is se-26: its answer is a dotted block path, which the any-other-number rule does not cover, so `1.1.0 or 1.1` passes the exact grader. The test also checks that no prompt repeats an edit-safety prompt or any eight-word run of one. `--hash-questions --question-set edit-safety-2` prints its hash. Like the edit-safety set it has no recordings.

## The pack-site-data question

`questions/pack-site-data.yaml` holds one edit-location question for a site that runs only the [Voyager Open Blocks](https://github.com/voyager-marketing/voyager-open-blocks) pack: where to change the phone number shown on the home page. The pack owns the `voyager/site-data` source there, and its screen is Settings > Site Data (`options-general.php?page=voyager-site-data`), where a site with voyager-blocks has the Site Data tab of Settings > Voyager Blocks. The answer is `site_data[key=phone].edit_path` in `fixtures/pack/truth.json`, written from the pack's `inc/site-data.php`, never from a scan.

There is no pack-only fixture yet, so `config.json` has no entry for it and the question cannot be run live. `test/questions.pack-site-data.test.ts` pins its hash, resolves the answer in the truth file and checks that the pack's screen grades correct and voyager-blocks's does not. A live run needs a wp-env site with the pack active and voyager-blocks absent, a seed that writes the truth file, and a `pack` fixture in `config.json` (find inputs `/` and `(540) 555-0142` are ready).

## Graders

The agent ends its reply with `ANSWER: <answer>`.

| Type | Correct when |
|---|---|
| `exact` | the answer line equals the one expected value, or names it with a label or qualifier |
| `set` | each comma-separated item equals or names one expected value, and together the items cover them all, in any order |
| `contains_all` | the whole reply contains every expected value, or for an admin path the menu label truth records beside it |
| `judge` | the reply names every identifier fact (below), and the judge model (Haiku 4.5), given the resolved facts and the question's rubric, marks every requirement the rubric states as met and nothing it rules out as said (from run 6, below) |

Comparison ignores case, quotes, backticks, a trailing period, and hyphens versus underscores. A judge reply that is not the JSON the prompt asks for counts as wrong. The judge is told that extra detail never makes an answer wrong, even when it cannot check it (from run 5, below).

An admin location has two correct names, its menu label and its admin path, and both graders that compare one accept either. The judge is told that a label, a path or both is the same answer when it names the same page, and that a label or path for a different page is wrong. Its EXPECTED also carries both forms, because in run 2 Haiku faulted two df-01 answers for giving the path when EXPECTED held only the label: `Settings > Business Info (admin path: options-general.php?page=vwf-business-info)`, and the reverse for an `.edit_path` reference. `contains_all` accepts an `.edit_path` value when the reply gives the menu label truth records beside it (`settings_page.menu`, or `menu_path` for a taxonomy or a menu): `Settings > Business Info` stands for `options-general.php?page=vwf-business-info` in el-01 and el-05, and `Services > Service areas` for the service_area screen in el-09. The label's parts must appear in order, joined by `>`, `->`, `→`, `›`, `»` or `/`, so `Tools > Business Info` and `Settings > Business Information` fail. Every menu shares one menu path, `Appearance > Editor > Navigation`, so el-06 takes it only beside the menu's title (`Primary`); the same path beside `Footer` fails. A pass this way reads `contains all, <label> by its menu label`, with the title in parentheses for a menu. el-02 and el-07 stay path-only: the post type screens' only labels are one word (`Services`, `Team`) that almost any reply would contain. `test/judge.locations.test.ts` covers it.

The classic fixture's admin locations carry their menu labels the same way: `menu_path` beside the Company Settings options page (`Company Settings`, el-01), the project type screen (`Projects > Project types`, el-04), each menu (`Appearance > Menus`, which every menu shares, so el-03 takes it only beside the menu's title, `Main navigation`) and each sidebar (`Appearance > Widgets`, el-05, which also asks for the sidebar's name). el-02 stays path-only, like the block set's post type questions.

A line or item names a value when the value stands as a whole token in it and no rival does. `Front page: home`, `vwf-fixture/business-info.phone` and `Post name (/%postname%/), set at options-permalink.php` name `home`, `phone` and `/%postname%/`. The rules:

- Letters, digits, `_`, `-`, `/` and `%` continue a token. A dot, a space and any other punctuation end one. So a slug, path or basename is one token: `advanced-custom-fields/acf` does not name `advanced-custom-fields`, and `1-5` does not name `5`.
- A value with spaces of its own needs punctuation or the line's edge on each side. `Site title: Riverbend Plumbing` names the title; `Riverbend Plumbing Co` does not.
- The rivals are every value the question's references could have named: each selector widened to `[*]`, except `id=`, which names the question's subject. For `keys[used_in=part:footer].key` the rivals are every key, so `phone (or hours)` fails.
- Every number is a possible count, so beside a numeric value any other number is a rival. `6 published` names 6; `6-20 service pages (6 published)` does not.
- A block path follows the same rule (from run 5): beside `2.1.0`, any other plain or dotted number is a rival. The rivals from truth are only the paths anchors hold now, so before this a hedge with an old path, a parent or a child passed: `2.1.0 or 2.1` named `2.1.0`, and because a dot ends a token, `3.2.1` named `3.2`. Both now fail, and `2.1.0 (the second column)` still passes. Replaying the 3,042 recorded exact and set answers in `reports/` changes no grade: the rule only refuses, and none of the 142 that pass with a label or qualifier has another number in it. `test/grade.test.ts` covers it (`-t "hedged block path"`).
- A yes-or-no answer is read by its first word (from run 6). In run 5 edit-safety-2 lk-22's `Yes, she can drag the Night rate block ... no lock changes required` failed against `yes`, because the `no` in its reason stood as a rival. Now it reads `answers yes first`. A first word followed by `or`, `and` or `/` hedges, so `Yes or no, depending` and `yes/no` decide nothing and still fail.
- A value named only in a concession is not given (from run 6). A concession opens at `even though`, `although`, `though`, `despite` or `even if` and runs to the end of its sentence, or to the first comma when it opens the sentence. In run 5 three real-site df-01 answers said the phone number is typed into the page, "even though" the site's phone option exists, and passed. Now they read `expected "<option>", named only in a concession`, while `Although it looks typed, it comes from the <option> option` still passes. The rule reads only the concession: a denial written another way (`there is a <option> option, but nothing reads it`) still names the option. `test/grade.test.ts` covers both rules with made-up answers in the same shape, and lk-22 with run 5's own answer line; the real-site set's own test pins its df-01 answers.

A judge question's identifier facts are checked in code before the judge is called. In run 2 Haiku passed df-01 answers that never wrote `vwf_phone`, reading the option as implied by the binding. An option name (`.option`), a slug (`.slug`), a binding source id, and an admin location that truth records in both forms must each stand as a whole token somewhere in the reply. A binding source also counts by its label, matched the way a menu label is, so the `"Business info: phone" binding` names `vwf-fixture/business-info`. The settings page shares that label, so `Settings > Business Info` alone passes this check too and leaves the source to the judge. An admin location counts by its menu label, its admin path (alone or after `wp-admin/`) or the older menu URL. A miss is wrong with the reason `missing <value>, judge not called`, and costs no judge call. Everything else stays with the judge:

- booleans, numbers and lists, whose items are Wayfinder ids such as `part:footer` or front-end paths
- prose such as a title
- an admin path with no menu label: the el-03, el-04 and el-08 rubrics accept other forms of those pages
- el-03's theme: 6 of the 9 run 2 answers the judge passed give `vwf-fixture` only inside the template part id (`vwf-fixture//footer`), where `/` continues the token
- el-08's field key: 3 of the 10 run 2 answers name `price_from` only by its label, `Price from`

So today only df-01 has facts to check. `test/judge.identifiers.test.ts` pins run 2's ten df-01 replies from the transcripts (see Transcripts), since the report keeps only the last `ANSWER:` line.

A field on a post is also edited at that post's own edit screen (from run 4). A judge question that references a post type's field (`post_types[id=…].fields[key=…].key`), that post type's `edit_path`, and a subject whose truth record has its own `post.php?post=<id>&action=edit` edit path is correct without a judge call when the reply names the subject's edit path (alone or after `wp-admin/`) and the field, by key or by its label, and names no other post's edit screen and no other field of that post type. A pass this way reads `names <edit path> and <field key>, judge not called`. Anything else goes to the judge as before, so the rule only ever adds passes. Only el-08 has that shape: the block fixture's truth records each service's own edit path (`services[slug=drain-cleaning].edit_path`, `post.php?post=90020&action=edit`), and no classic judge question qualifies. `test/judge.price-field.test.ts` pins run 3's eighteen el-08 replies (`test/run3-el-08.json`): the seven the judge failed now pass, and the other eleven keep their verdicts.

Extra detail and CONFIRMED (from run 5). EXPECTED holds only the values a judge question's references name, so the judge cannot tell a correct detail beyond them from an invented one. In run 4 it failed three block ci-02 replies for listing the pages the footer shows on ("goes beyond what was asked"), and three classic df-01 replies for naming the Company details field group, which it read as a second name for the Company Settings page. The prompt now says what extra detail looks like (more pages or templates than asked, or a group, file, id or edit screen beside an expected item), that it is not a different value unless the answer gives it in place of one, and that it never makes an answer wrong, even unchecked. Code also gives the judge CONFIRMED: each value the reply names from the records the question's references select (each reference's first selector, when it picks one record), keyed by where truth holds it, so `options_pages[id=optpage:company-settings].field_groups: Company details`. A value is named the way a menu label is. Three kinds of value are left out: an expected value, a rival (a value at the same place as an expected fact in another item, such as company_email beside company_phone) and a lowercase single word such as a slug or a field type. The judge never sees the rest of a record: in a trial it read `navigation[id=nav:footer]`, whose `used_in` lists only `part:footer`, as the whole site, and failed answers that listed the templates the footer part is in. A hedge or a missing fact still fails. From run 6 a contradiction fails when it breaks the rubric (below).

The rubric decides (from run 6). In run 5 the judge failed five real-site df-06 replies that its rubric accepts: they give the Site Title under Settings > General and name no Rank Math setting as the place to change it. It failed them for leaving out the theme filter, which EXPECTED holds and the rubric mentions only to explain how the name reaches the share tags, because the prompt said an answer must state every expected fact. Now the judge lists what WHAT COUNTS AS CORRECT requires and what it calls wrong, quotes the answer's words for each wrong thing, and marks each item. Code decides: correct when every requirement is met and nothing ruled out is said. The prompt says to add nothing the rubric does not state, that a clause explaining how or why is not a requirement, that EXPECTED checks what the answer says rather than adding requirements, and that offering a ruled-out thing beside the right answer, as an override or under a condition, still says it. A reply in the older `{"correct", "reason"}` shape is still read.

`test/judge.rubric.test.ts` covers the checklist with made-up replies. Run 5's 36 real-site df-06 replies were judged again with this prompt, 36 of 36 as the rubric says, where run 5's judge got 31. The replies are private, so that record keeps each verdict and hashes and never a reply, and it stays with the private set (below), whose own test checks it was made with today's prompt. The judge moves with wording, and one df-06 reply flipped between two runs of the same prompt, so judging one question again says little about the others.

`test/judge.run4-regrade.test.ts` pins every run 4 ci-02 and df-01 reply that gave an answer (`test/run4-judge-regrade.json`, 31 replies from the `bench-run4-transcripts` release, see Transcripts) and 8 wrong replies written for it, as controls. Offline, it checks the corrections, the code-graded misses and what the judge is sent. With `BENCH_LIVE_JUDGE=1` it also calls the judge: the six corrected replies must pass, every other run 4 verdict must hold, and every control must fail. That is 36 judge calls, about $0.04:

```
BENCH_LIVE_JUDGE=1 npx vitest run test/judge.run4-regrade.test.ts --silent=false
```

`test/graders.run1.test.ts` replays run 1's answers through the graders and checks they agree with the hand re-grade below on every row the report can reproduce.

## Accounting

Each run sums `input_tokens`, `cache_creation_input_tokens`, `cache_read_input_tokens` and `output_tokens` over every model call, plus tool calls and wall time. Cost uses `pricing.json`, which records its source URL and fetch date. Cache writes are priced at the 5-minute rate, which is what `cache_control: ephemeral` uses. Judge calls are costed separately.

With `conversation_caching: true` in `config.json` (on since run 3), every agent call puts a cache breakpoint on the last block of its latest message, the way production agents cache their loop, so each call reads the conversation before its newest turn from the cache once that prefix clears the model's minimum. Only the request is marked; the stored conversation never is, so a request carries that breakpoint plus, in manual-preloaded, the one on the manual. Input token counts are unchanged by caching, since they count uncached input, writes and reads together, but costs fall. Run 3's costs are therefore not comparable with runs 1 and 2. Set it to false to reproduce their request shape.

## Report

`reports/<site>-<date>.json` and `.md`, copied to `<site>-latest.json` and `.md`. The JSON is validated against `ReportSchema` in `src/report.ts` before it is written. Recorded reports, corrections files and test records keep the tracker references they were written with, because they record a run as it happened: each Markdown report is written from its JSON report, and tests read the JSON back.

```
format          "wayfinder-bench-report/1"
site, date      "fixture", "YYYY-MM-DD"
run_label       optional, from --run-label: "run3"
fixture         the config.json fixture key, "block" or "classic" (since run 3)
hypothesis_set  "run2" (H1 to H6) or "run3" (R1 to R7); absent before run 3, which means run2
commit          the checkout's HEAD when the run started, with -dirty if a tracked file differed (live runs, since run 3)
conversation_caching  true when every agent call cached its conversation (since run 3)
max_turns       model calls per question before a run counts as wrong; absent before --max-turns, which means 12
adapter_endpoint  the MCP server route the adapter condition used, when it ran; absent before run 5 (the default server)
adapter_tools   the tools that server listed, by name, sorted; the run stops before any question if a name or description names Wayfinder
dry_run         true when replayed from recordings
runner          { where: local | github-actions | dry-run, concurrency }
question_set    { file, sha256, count }
pricing         { source, fetched }
models          [{ id, params, cache_min_tokens }]
temperature_policy  { agents: "provider-default", judge: { model, params } }: no agent model was sent a temperature;
                absent before run 6, whose models carry their own params
manual          { brief_chars, index_chars, brief_est_tokens, index_est_tokens, index_dropped }, null in a dry run;
                index_dropped counts the items the index left out to fit its budget (from run 5)
hypotheses      [{ id, claim, verdict: pass | fail | not measured, numbers }] for H1 to H6, or R1 to R7
metrics         run 3: [{ id, claim, numbers }], figures without a threshold (the median input ratio)
losses          run 3: [{ model, metric, baseline, wayfinder, baseline_value, wayfinder_value }], every
                same-model comparison where raw or adapter did better than manual or manual-preloaded
cache_by_cell   with conversation caching: per condition and model, question_runs, input_total,
                uncached_input_tokens, cache_creation_input_tokens, cache_read_input_tokens, runs_reading_cache
caching         per model, manual-preloaded only: question_runs, first_call_input_tokens { median, p90 },
                cache_creation_input_tokens, cache_read_input_tokens, runs_reading_cache, engaged
most_missed     per condition, up to three: question, category, missed, runs, reason
runs            one row per condition, model and repetition: rep, questions, correct, accuracy,
                input_tokens, output_tokens, total_tokens, tool_calls, wall_ms as { median, p90 },
                cost_per_question_usd, cost_usd, and tools: { <tool>: { calls, errors } } summed over
                the row's question runs (from run 6)
by_category     per condition, model and category, pooled over repetitions: questions, correct, accuracy
totals          { question_runs, correct, cost_usd, judge_cost_usd }
results         one row per question run: question, category, condition, model, rep, correct, reason,
                answer, usage, first_call_input_tokens, tool_calls, tool_errors, tools, turns, hit_turn_cap,
                wall_ms, cost_usd, judge_cost_usd; tool_errors and tools from run 6
corrected       optional: { issue, rule, corrections, hypotheses, runs } after a hand re-grade (below)
sensitivity     optional: { reference: { file, max_turns, commit }, max_turns, cells } for a turn cap
                sensitivity run (below)
intervals       { resamples, seed, cells, comparisons }: 95% intervals per cell and per paired comparison,
                with McNemar's exact test (from run 5, below)
```

`input_tokens` in a run row counts everything the model read: uncached input, cache writes and cache reads. p90 is nearest-rank. `answer` is the agent's last `ANSWER:` line, empty when it gave none.

## Hypotheses

The Markdown report opens with H1 to H6 from the spec, judged by the thresholds under `hypotheses` in `config.json`. H1 to H4 were fixed before run 1; H2b, H5 and H6 before run 2:

| | Passes when |
|---|---|
| H1 | every model reaches `h1_min_accuracy` (90%) on the `manual` condition |
| H2 | for every model, raw's median input tokens per question are at least `h2_min_input_ratio` (5) times `manual`'s |
| H2b | for every model, raw's total input tokens over one pass of the question set are at least `h2b_min_total_input_ratio` (3) times `manual`'s. A cell with repetitions counts its total divided by its passes |
| H3 | the challenger cell's mean accuracy over its repetitions is at least the baseline cell's (Haiku manual-preloaded against Sonnet raw) |
| H4 | the brief is at most 600 and the index at most 4,000 estimated tokens (characters / 4, rounded up, as `wp wayfinder stats` counts) |
| H5 | for every model, raw's p90 input tokens per question are at least `h5_min_p90_input_ratio` (3) times `manual`'s |
| H6 | the H3 challenger costs at least `h6_min_cost_per_correct_ratio` (5) times less per correct answer than the H3 baseline: total cost over every repetition divided by correct answers |

Medians and p90s pool repetitions. The rows also print manual-preloaded figures for reference; they do not decide H1 or H2. H1 to H6 never read the adapter condition. A hypothesis whose cells were filtered out of the run reads `not measured`.

Since run 3 `hypothesis_set` in `config.json` is `run3`, and a new report is judged against R1 to R7 (the run 3 hypotheses, set on 2026-10-02 before the run), with thresholds under `run3_hypotheses`. The baseline is the adapter condition. Each report judges its own fixture; the spec claims every rule on both, so `--summarize` passes a rule only where both reports do:

| | Passes when |
|---|---|
| R1 | every model reaches `r1_min_accuracy` for this fixture on `manual`: 90% on block, 85% on classic |
| R2 | for every model, `manual`'s accuracy is at least `r2_min_gain_points` (10) percentage points above adapter's, compared to a tenth of a point |
| R3 | Haiku manual-preloaded's mean accuracy over its repetitions is at least Sonnet adapter's |
| R4 | the brief is at most 600 and the index at most 4,000 estimated tokens |
| R5 | for every model, adapter's total input tokens over one pass of the question set are at least `r5_min_total_input_ratio` (3) times `manual`'s |
| R6 | for every model, adapter's p90 input tokens per question are at least `r6_min_p90_input_ratio` (3) times `manual`'s |
| R7 | Haiku manual-preloaded costs at least `r7_min_cost_per_correct_ratio` (10) times less per correct answer than Sonnet adapter |

A run 3 report also carries the median input ratio as a metric without a threshold (the spec retired H2 after runs 1 and 2), and a losses list: every comparison on the same model where raw, adapter or cli did better than manual, manual-preloaded or cli-manual, on accuracy, accuracy per category, median, p90 and total input tokens, and cost per correct answer. `findLosses` in `src/report.ts` decides it, and nothing is picked by hand. The Markdown puts the losses straight after the hypotheses.

Then come the three most-missed questions per condition, with the grader's reason and the answer given, and whether prompt caching engaged for each model. A prefix under `cache_min_tokens` is not cached, and nothing reports that except a cache write of 0. The caching probe (below) measures the prefix.

## Intervals

Runs 3 and 4 decided every rule on a point estimate. At 30 to 40 questions one accuracy figure near 90% carries a 95% margin of about 10 points, the size of R2's threshold. So every report carries 95% intervals for the figures the rules read: `intervals` in the JSON, and an "Intervals" section in the Markdown straight after the hypotheses. `node src/cli.ts --intervals reports/<site>-<date>.json` prints the same section for any report, including the ones written before it existed. `src/intervals.ts` computes it:

- **The unit is the question.** Each question is scored as the share of its repetitions answered correctly. Every question in a cell is asked the same number of times, so the mean share equals the pooled accuracy the rules read, and a three-repetition cell compares with a one-repetition cell question by question. Repetitions of one question are not independent samples, so they are never resampled on their own.
- **Intervals come from resampling questions** with replacement, 10,000 times, taking the 250th and 9,750th sorted values (nearest rank). The generator is mulberry32 from seed 1215, started fresh for every interval. So a report is the same on every run, and no interval depends on how many others come before it.
- **Cells:** each cell's accuracy with its interval. S1 reads the lower bound.
- **Comparisons cover two kinds of pair.** The first is every same-model pair of a Wayfinder condition (`WAYFINDER_CONDITIONS`) with a baseline condition (`BASELINE_CONDITIONS`). The second is the cross-model pair R3 and R7 name, Haiku manual-preloaded against every baseline condition on Sonnet, so the best-baseline restatement and S3 and S4 have their row whichever baseline wins. Each comparison gives challenger minus baseline accuracy with its interval, McNemar's exact test, and the cost per correct answer ratio with its interval. "Read by" names the rules that read a figure against the named baseline: R1, R2, R3 and R7, or H1, H3 and H6 on a run 1 or 2 report.
- **McNemar** pairs repetition k of one cell with repetition k of the other, over the repetitions both ran, so a one-repetition raw cell is paired on repetition 1 only. The two-sided exact p is twice the Binomial(b + c, 1/2) tail at the smaller discordant count, at most 1. It treats repeated questions as independent pairs, so its p is optimistic; the interval is the figure to read.
- **Cost per correct answer** is the baseline's agent cost over its correct answers, divided by the challenger's, summed over each resample's questions and every repetition. On the whole set that is R7's ratio. A resample where neither side has a correct answer is 0/0: it is left out of the bounds and counted. The JSON stores an unbounded figure as null.
- **Non-inferiority:** `nonInferior(lower, margin)` passes when the lower bound of challenger minus baseline is at least minus the margin, compared to a tenth of a point as R2 compares. The Markdown applies S3's 5 points to every row ("Within 5").
- **Graders' verdicts only.** Hand re-grades in a `corrected` block are not resampled.

## Find eval

`node src/cli.ts --find-eval` measures find itself, with no model. For each edit-location question it sends the question's find inputs to `manual_routes.find` and checks the location the answer key names against hit 1 (top-1) and against every hit (any hit). Find has no sampling noise and the eval makes no API calls, so it can run on every change to find. `--fixture` and `--question-set` pick the set and seed its fixture first, as a bench run does; `--site-url` and `--out` work as in a run. It writes `reports/<site>-<date>-find-eval.json` and `.md`, and `FindEvalReport` in `src/findEval.ts` is the JSON's schema. There is no CI job for it.

- **Which questions.** An edit-location question is one whose answer references an `edit_path`, `edit_paths` or `paths_now`, the rule that decides which edit-safety-2 questions carry `find`. The block set has 9, classic 6, edit-safety 7 and edit-safety-2 8.
- **Find inputs.** edit-safety-2 carries them on each question, inside its hash. The sets frozen before it keep theirs in `questions/find-inputs-<question file>.yaml`, outside their hashes: `find-inputs-fixture.yaml`, `find-inputs-fixture-classic.yaml` and `find-inputs-fixture-edit-safety.yaml`. Each file records its set's `questions_sha256`, and the eval stops if the set has changed since. An entry is `find: {url, text?, class?}`, what the person asking would have to hand, or `none: <reason>` when no page shows what the question asks about (block el-05, the business hours, and el-07, a team member). `location` replaces the answer's location references for one question: block el-08's key names the Services list screen, and the grader has accepted the service's own screen since run 4.
- **Locations.** An `edit_path` is an admin screen, compared after dropping the origin, `wp-admin/` and HTML escaping. A `paths_now` is a block: the post its anchor record names, at that block path.
- **Where a hit is edited.** A hit carries an item id and, on a block, an anchor. Its screen is the one find gives that id: the result's chain, then the chain for the URL alone (which lists the page's own post and every binding the page shows), then the anchor's post for content on another page, then the manifest item's `edit` from the section tier. A binding's screen is its source's, never the post its block sits in.
- **A URL alone.** With no text and no class find returns no hits, only the chain of everything the page is built from, so the chain is ranked in their place and the report says `ranked_from: chain`. A text or class search that matches nothing ranks nothing: find has said it found nothing.
- **Any hit** means every location the key names is at some hit, so a text with three homes needs all three. The report also gives the rank of the first hit at an expected location, and whether find called the top two ambiguous.
- **No stored manifest.** A seeded site has none, and none of the routes the bench calls stores one (only `admin_init` refreshes it), so in the eval and in every bench run find ranks without route counts and `verify.changes_since` is null. The report records it as `snapshot`. With a manifest stored by `wp wayfinder scan --refresh`, no ranking moved on the three block sets on 2026-10-06.

The eval reads any fixture, a private snapshot fixture too (below), and reads each question's own `find` where the question carries one. The first results, on 2026-10-06, are `reports/*-2026-10-06-find-eval.md`.

`test/find-eval.test.ts` checks the scoring and where hits are edited on small cases, the planner's refusals, and that each inputs file covers exactly its set's edit-location questions with a page the fixture serves and text and classes its sources print. It also replays find answers recorded on the block fixture for edit-safety-2 (`test/find-eval-fixture-edit-safety-2.json`, written by `test/record-find-eval.ts`) through a whole run, which must give the committed report.

`test/intervals.test.ts` checks four things. McNemar matches binomial tails computed by hand. The bounds of small cases match the exact bootstrap distribution, enumerated over every possible resample. A four-question comparison worked by hand gives the expected difference, McNemar counts and cost ratio. And run 4's block report gives back its own R2 and R7 figures, each inside its interval.

## Turn cap sensitivity

Run 3 gave every condition 12 model calls, and most baseline misses were runs that reached the cap. A sensitivity run reruns cells at another cap and sets each beside the same cell of a reference report:

```
node src/cli.ts --fixture block --condition raw --condition adapter --max-turns 24 --repetitions 1 \
  --run-label run3-sensitivity --sensitivity-of reports/fixture-2026-10-03-run3.json --concurrency 6
```

The run stops before its first API call unless the reference has the same fixture and question set sha256 and a different cap. Its report, `<site>-<date>-run3-sensitivity`, carries a `sensitivity` block and opens with it in the Markdown: per cell, accuracy at both caps, the reference misses that reached the cap, how many of those this run answered correctly, the rest of the movement (other misses now correct, correct answers now wrong) so the change adds up, how many runs still reached the new cap, and cost per correct answer at both caps. It also says where each run ran (`local wp-env` or `GitHub Actions runner, wp-env`, from each report's `runner`): a run is capped by model calls, not time, so the host moves wall times and not accuracy, and the report says so rather than leaving it to be asked. Each reference question run is paired with this run's answer to the same question, so with one repetition here and three in the reference, the counts are reference question runs. `turnCapSensitivity` in `src/sensitivity.ts` computes it. A sensitivity report never replaces `<site>-latest`, which stays the last full run.

```
node src/cli.ts --best-baseline reports/fixture-<date>-run3-sensitivity.json --best-baseline reports/fixture-classic-<date>-run3-sensitivity.json --append reports/run3-summary.md
```

appends a "Best baseline per cell" section to the run 3 summary: for every fixture and model, the higher of raw and adapter at each cap, the Wayfinder cells beside it, and R2 and R3 restated against that best baseline. It reads the sensitivity reports and the reference reports they name, makes no API calls, and refuses to append the section twice. Without `--append` it prints it.

## Caching probe

`npm run probe-cache` (`node src/cli.ts --probe-cache`) checks whether the manual-preloaded prefix caches for each model in `config.json` and writes `reports/caching-probe.json`. For each model it:

1. counts the request (the manual-preloaded tools and system prompt plus a one-word user turn) with the token counting endpoint, as `request_tokens`
2. sends that request twice, 5 seconds apart, and records the cache writes and reads of each call
3. sends one calibration call with the manual twice, which clears every model's minimum, and takes its uncached input as `tail_tokens`: everything after the breakpoint

`prefix_tokens` is `request_tokens` less `tail_tokens`, the span the minimum applies to. The count alone overstates it. With tools present the API renders tokens of its own after the system prompt, so they fall after the breakpoint: 317 on Haiku 4.5, on top of the one-word turn. Where a model caches, `prefix_tokens` equals the span it wrote or read, which checks the calibration. The bench never sends the calibration prompt, and the bench prompts are never padded.

By default the manual comes from the running site, the way a bench run fetches it. `--preload-from runs/<timestamp>/<transcript>.json` uses the bytes a past manual-preloaded run sent and needs no site; pass the `--site-url` that run used, since it is in the system prompt. `--model` narrows the models. Each model costs two token counts and three short calls, a few cents in all.

## Grader corrections

Questions, answers and graders never change for a run whose results have been seen. When a grader is wrong, file a Bug issue and re-grade that run by hand instead. A grader fix lands before the next run exists, and is tested against the last run's hand re-grade, so it cannot be tuned to the results it will grade:

```
node src/cli.ts --regrade reports/fixture-2026-10-02.json --corrections reports/fixture-2026-10-02-corrections.json
```

The corrections file names the issue, the rule applied and each re-graded result (`question`, `condition`, `model`, `rep`, `why`). Each one must match exactly one result the grader marked wrong, or the command stops. It makes no API calls, rewrites the dated and latest reports in place, and adds a `corrected` block with the hypotheses and accuracy recomputed. Every field the graders produced stays as it was, so the report carries both sets of numbers.

### Grader history

Rules added after a run's results were seen, with the first run they grade. Earlier runs keep the verdicts they were graded with; the rules above without an entry here graded run 3 as written.

- Run 4: a post field at the post's own edit screen (el-08). In run 3 the judge failed seven el-08 replies that gave `post.php?post=90020&action=edit` and `price_from`, because EXPECTED held only the Services list screen. Counting them correct changes no run 3 result, so run 3 is not re-graded and its reports are unchanged.
- Run 5: extra detail and CONFIRMED (above). Run 4 is hand re-graded instead: `reports/fixture-2026-10-04-run4-corrections.json` passes the three ci-02 replies and `reports/fixture-classic-2026-10-04-run4-corrections.json` the three df-01 replies, and each report carries a `corrected` block. No R1 to R7 verdict moves. Eight df-01 misses stay wrong: five reached the turn cap, and three say the number is hardcoded in footer.php. Before the change the judge failed the same six replies at temperature 0, and after it passes them and fails every control.
- Run 6: a leading yes or no, a value named only in a concession, and a judge held to its rubric (above). Run 5 is not re-graded. `reports/run5-regrade-graders.md` is a correction note: replaying run 5's 1,060 published exact and set answers changes 1 grade (lk-22, to correct). With the real-site set's 528, the count is 1,588 answers and 5 grades (its lk-05 to correct and three df-01 answers to wrong), and judging its 36 df-06 replies again changes 5 (to correct); those rows are in the private copy of the note. It also records the controls: run 5's own grader gives back every recorded grade, and a mutation is found. `node test/replay-run5.ts` writes it with no API call, from a clone whose history holds run 5's commit, and `test/replay.test.ts` pins the published grade change. No other judge question was judged again, so the prompt's effect on them is unmeasured.

## Dry run

`--dry-run` replays `test/recordings/*.json` with no network calls: each file holds the model responses, tool results and judge reply for one question under one condition and model, in the same shape a live run writes to `runs/`. The recordings in this repo are hand-written (`"synthetic": true`) to exercise every grader, condition and model. They are not results. An adapter recording carries the `mcp_tools` its condition offers, and the replay stops when a recorded tool call names a tool its condition does not offer. `cli` and `cli-manual` have one recording each. Their `wp` and `read_file` calls pass the guard, and the one refusal they record is the guard's own message, which `test/cli.condition.test.ts` checks. A dry-run report says so at the top and is written to `runs/dry-run/`, never to `reports/`.

The recordings answer the block fixture's questions (`recordings` in its `config.json` entry). Question ids repeat across the two sets, so `--dry-run --fixture classic` stops unless `--recordings <dir>` names recordings made against the classic set. A dry run never seeds.

## Transcripts

A live run writes one transcript per question run to `runs/<timestamp>/`, which git ignores: the whole exchange, every tool call and result, and the judge's reply. The reports keep each answer line and verdict, so everything a summary claims is checkable from `reports/` alone.

The transcripts of the runs on the published fixtures are release assets, one tarball per run: `bench-run1-transcripts` to `bench-run5-transcripts`, and `bench-run3-sensitivity-transcripts`. They hold only the fixture sites (Riverbend Plumbing and Ashford Remodeling) and no private data. This repository's history starts after run 6, so a release can be missing from its Releases page; the maintainers hold every one, and a record that cites a release names the tarball's sha256, so a copy can be checked. The real-site set's transcripts read files from private plugins and a private theme, and stay private.

Nothing offline needs a release. The tests that pin replies keep the reply text they check in `test/` (`run2-df-01.json`, `run3-el-08.json`, `run4-judge-regrade.json`), and `test/judge.price-field.test.ts` checks the run 3 transcripts only when they are extracted into `runs/`. Without the transcripts you can re-grade nothing that needs a whole reply, but you can rerun any set (`REPRODUCE.md`), which reproduces the design and not the bytes.

## Private fixtures

Every published set was written by the maintainers, on a site they seeded, for a tool they built. A private fixture asks real requests on a real site: a sanitized snapshot of a site the maintainers run. Its questions, answer key, snapshot pin and reports name that site's pages, settings and people, so none of them is in this repository. Run 5 held one out beside edit-safety-2, and `reports/run5-summary.md` calls it `real-site` and keeps only its figures.

> [!NOTE]
> Without private data, every published fixture, set and test runs as described above. The tests that need private data live with it, and `test/private.test.ts` prints a line saying they were skipped.

### Where it goes

`bench/private/` is gitignored. Its `config.json` adds fixtures in the shape `config.json`'s take, with paths relative to `bench/` like theirs:

```json
{
  "fixtures": {
    "acme": {
      "site": "acme-real",
      "truth": "private/fixtures/acme/truth.json",
      "questions": "private/questions/acme-real.yaml",
      "snapshot": "private/fixtures/acme/snapshot.json",
      "credentials": "private/fixtures/acme/.credentials"
    }
  }
}
```

- A private fixture may not reuse a published fixture's name, and `--fixture acme` or `--question-set` finds it like any other (`withPrivateFixtures` in `src/run.ts`).
- It writes its reports and find evals to `private/reports/` unless it names another `reports` folder, so a private run never lands in the published `reports/`.
- Its tests go in `private/test/`. Vitest runs them with the rest and `npm test` type-checks them.
- `run5` registers the private sets run 5 froze: each set's `site`, `sha256`, whether it is held out, its `fixture`, and whether it is a `snapshot`. `src/run5.ts` reads them beside the published sets, and `src/replay.ts` replays the reports `run5.reports` names.

`.github/workflows/bench.yml` never has `bench/private/`, so it runs the published fixtures only. A private fixture runs on a maintainer's machine.

### A snapshot fixture

A fixture names `snapshot`, a pin file, in place of `seed`. The pin names one release asset on a private repository: its tag, file name and sha256, the WordPress and PHP versions, the theme and the plugins. Beside the pin go two files:

- `.wp-env.json`, the snapshot's own site. It maps this checkout as the plugin, the MCP Adapter at the version the root `.wp-env.json` pins, and the dev guard mu-plugin (AGENTS.md).
- `load.php`, which points the snapshot's own URLs at the local site, gives user 1 the password `password`, writes an Application Password to `.credentials` beside it (gitignored with the folder), and stops if the theme, the plugins or MCP exposure are not what the answer key assumes. It changes no content.

```
node src/cli.ts --fixture <name> --condition manual --model claude-haiku-4-5-20251001
node src/cli.ts --start-snapshot --fixture <name>      starts the snapshot and stops, for checking the answer key
```

A run on a snapshot fixture downloads the pinned asset beside the pin, refuses it unless its sha256 matches, extracts it to `snapshot/`, starts it in its own wp-env site and imports the database. No seed runs: the snapshot is the site. The cli conditions run their WP-CLI commands in that site's container (`wpEnvRoot` in `src/run.ts`), never the default fixture's, and `--find-eval` starts it the same way. The download needs read access to the private repository: `WAYFINDER_BENCH_DATA_TOKEN`, or a `gh auth login` session. The site takes `WP_ENV_PORT` (8888 when unset). Stop it with `npx @wordpress/env destroy --force` from the pin's folder.

<details>
<summary>The sanitizer</summary>

`src/sanitize.ts` reads the dump as bytes, so a serialized value keeps valid lengths and a row no rule touches is written back unchanged. It:

- blanks password hashes and activation keys and gives each user a placeholder email;
- drops sessions, application passwords and the community events location;
- drops transients and Wayfinder's own `vwf_*` state, so the plugin under test starts from its defaults with MCP exposure off;
- blanks every option or meta whose name looks like a key, token, secret, licence or password, keeping the value's shape, and inside every other value blanks key-like entries and credential-shaped strings and replaces emails (except the business email the site shows);
- empties logs, IP lists and pattern usage counts;
- drops trashed posts, auto-drafts, drafts, posts pending review, every revision and log post types, with their meta, so nothing unpublished, including an earlier version of a published page, reaches a bench transcript;
- blanks comment author emails, IPs and user agents;
- empties form entry, lead, submission, session, log, activity, request, job, queue, cache and audit tables. On the first snapshot, a plugin's activity log held staff emails and visitor IPs.

A key-like name never blanks an on or off value, and the keys of `cron` and `rewrite_rules` are hook names and URL patterns, so they are not read as names. It keeps roles and capabilities, which `client_can` reads. It renames the table prefix to wp-env's `wp_` and MySQL 8's utf8mb4_0900 collations to one MariaDB has. `test/snapshot.test.ts` runs it on `test/snapshot-synthetic.sql`, a made-up dump of the same shape, and checks each of these, on the input as well as the output.

</details>

<details>
<summary>Building a snapshot</summary>

From a local session with SSH access to the site. The SSH target, the key and the WordPress root on the server are not in this repo, and all three flags are required. The export only reads: `wp db export` and `tar` write to stdout, and nothing is written on the server.

```
node src/cli.ts --build-snapshot --ssh <user@host> --ssh-key <private key> --site-path <WordPress root on the server> --snapshot-repo <owner>/<private repo>
```

`--snapshot-repo` names the private repository the tarball will be published on, which the pin records. Leave it out when `bench/private/config.json` sets `snapshot_repo`.

The build goes to `private/build/`, and the raw dump never touches the disk: it is sanitized in memory. The tarball holds `db.sql`, the active theme and its parent, the plugins and mu-plugins less what `LEFT_OUT` in `src/snapshot.ts` names with its reason, `sanitize-report.json` (every table, option and meta key the sanitizer touched, never a value) and `meta.json`. Every theme or plugin file, CSS aside, that names an unpublished case study is left out too; the build reads those slugs from the site and never writes them anywhere. The command refuses to pack when a file or the sanitized dump still holds a credential-shaped string.

Publish the tarball as a release asset on the private repository, then copy `private/build/snapshot.json` over the fixture's pin. A rebuilt snapshot is a different site: its answer key is checked again, and a run on it is not comparable with a run on the old one.

</details>
