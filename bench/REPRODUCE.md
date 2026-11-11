# Reproduce the Wayfinder benchmark

This rebuilds both fixture sites on your machine and reruns the benchmark with your own Anthropic API key. Everything the run reads is in this repository: the plugin, the two fixture sites and their seeds, the question sets, the answer keys, the graders and the harness. Nothing comes from a Voyager server.

Run 3 is the reference run. Its reports are `reports/fixture-2026-10-03-run3.json` (block fixture) and `reports/fixture-classic-2026-10-03-run3.json` (classic fixture), combined in `reports/run3-summary.md`. Each report records the commit it ran at in its `commit` field.

> [!NOTE]
> This repository's history starts after run 6, so the commit a report records is not in it. A rerun here uses today's plugin and harness; each section below says which flags give that run's design.

## What you need

- Docker, running (wp-env builds the site in containers)
- Node 22.18 or newer
- PHP 8.1 or newer and Composer
- git
- An Anthropic API key with access to `claude-haiku-4-5-20251001` and `claude-sonnet-5`

A full run of both fixtures is 1,260 question runs. Run 3 took 40 minutes at 6 concurrent question runs (18 for block, 22 for classic) and cost $27.41 in agent calls plus $0.18 for the judge (`reports/run3-summary.md`). The first steps below cost nothing, and step 6 costs under a cent, so try those before you spend on a full run.

## 1. Clone and install

```
git clone https://github.com/voyager-marketing/voyager-wayfinder.git
cd voyager-wayfinder
composer install           # the plugin's autoloader; without it the plugin will not load
npm ci                     # the pinned @wordpress/env
```

## 2. Start the fixture site

```
npx @wordpress/env start
```

The site comes up at http://localhost:8888 (admin / password). `.wp-env.json` pins WordPress 7.1.2, ACF 6.8.10, Secure Custom Fields 6.9.5, MCP Adapter 0.7.0, Twenty Twenty-Five 1.5 and Twenty Twenty-One 2.9, so you get the same site the reports measured. If port 8888 is taken, pick others and pass the URL to the harness later:

```
WP_ENV_PORT=8892 WP_ENV_TESTS_PORT=8893 npx @wordpress/env start
```

Keep those two variables set in the shell you run the harness from, because the harness seeds the site through `npx @wordpress/env`.

## 3. Check the harness offline

```
cd bench
npm ci
npm test            # type check and the unit suite, no network
npm run dry-run     # replays recorded transcripts; writes runs/dry-run/, measures nothing
```

## 4. Add your API key

Put it in `bench/.env` (gitignored):

```
ANTHROPIC_API_KEY=sk-ant-...
```

or export `ANTHROPIC_API_KEY` in your shell.

## 5. Check the question sets are the frozen ones

```
node src/cli.ts --hash-questions                    # e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b
node src/cli.ts --hash-questions --fixture classic  # 4511d7c994aabf5bd16fe64a880ef7dd06bebc2202ced544d78a30bd3b02f9ce
```

Both must match the `question_set.sha256` in the run 3 reports. The harness refuses to run a question file whose header disagrees with its contents.

## 6. One question, to check the wiring

```
node src/cli.ts --fixture block --question cm-01 --condition raw --model claude-haiku-4-5-20251001 --out runs/check
```

Add `--site-url http://localhost:8892` if you moved the port. `--fixture` makes the harness seed that fixture first, so the site always matches its answer key. It prints one line for the question, such as `raw claude-haiku-4-5-20251001 cm-01: correct (same set)`, then the hypotheses, most of them `not measured` because only one cell ran.

## 7. The full run

```
node src/cli.ts --fixture block   --concurrency 6 --run-label run3
node src/cli.ts --fixture classic --concurrency 6 --run-label run3
node src/cli.ts --summarize reports/fixture-<date>-run3.json --summarize reports/fixture-classic-<date>-run3.json --out reports/run3-summary.md
```

Each run seeds its fixture, then runs 2 models x 4 conditions over its question set, with 3 repetitions of every cell R1 to R7 read: 720 question runs on the block fixture and 540 on the classic one. Reports go to `reports/`, full transcripts to `runs/<timestamp>/`. `README.md` explains the conditions, graders, hypotheses and report fields.

Lower `--concurrency` if your API tier rate-limits you. The SDK retries 429s, and the harness reruns a failed question twice before it stops.

`config.json` has listed six conditions since run 5 added `cli` and `cli-manual`. To rerun run 3 or run 4's design, add `--condition raw --condition manual --condition manual-preloaded --condition adapter` to each run line.

## Run 4

Run 4 asks the same two frozen sets again on a later plugin, with the same conditions, models, repetitions, 12-turn cap and caching, and adds the edit-safety set (`questions/fixture-edit-safety.yaml`, sha256 `69eb2c8401ba4ff31666e968dce3cf8ac31c3c5153c4920a70dbadb9fe372cc9`). Check all three hashes first:

```
node src/cli.ts --hash-questions
node src/cli.ts --hash-questions --fixture classic
node src/cli.ts --hash-questions --question-set edit-safety
```

Then the three runs and the summary:

```
node src/cli.ts --fixture block              --concurrency 6 --run-label run4
node src/cli.ts --fixture classic            --concurrency 6 --run-label run4
node src/cli.ts --question-set edit-safety   --concurrency 6 --run-label run4
node src/cli.ts --summarize-run4 reports/fixture-<date>-run4.json --summarize-run4 reports/fixture-classic-<date>-run4.json --summarize-run4 reports/fixture-edit-safety-<date>-run4.json \
  --run3 reports/fixture-2026-10-03-run3.json --run3 reports/fixture-classic-2026-10-03-run3.json --out reports/run4-summary.md
```

Run 4 ran each line on a GitHub Actions runner through `.github/workflows/bench.yml`, with the line's arguments as the workflow's `args` input, from main at the commit each report records. The block fixture gained 3 pages and a synced pattern for the edit-safety set, so the block set in run 4 runs on that larger site, and its manual is larger than run 3's. The bench tools are run 3's: `find_edit_location` takes a URL and text, with no class or anchor input. Transcripts are the release asset on `bench-run4-transcripts`.

Six run 4 misses were then re-graded by hand: three block ci-02 and three classic df-01 replies the judge failed for correct extra detail. The block and classic reports carry them as a `corrected` block beside the graders' verdicts, added with no API calls:

```
node src/cli.ts --regrade reports/fixture-2026-10-04-run4.json --corrections reports/fixture-2026-10-04-run4-corrections.json
node src/cli.ts --regrade reports/fixture-classic-2026-10-04-run4.json --corrections reports/fixture-classic-2026-10-04-run4-corrections.json
```

## Run 5

Run 5 rests its claims (S1 to S6 in the spec) on two held-out sets: edit-safety-2 (`questions/fixture-edit-safety-2.yaml`, sha256 `79b1b58aef25744644e6dfc104550afb654338bd89897963d14f1a5aec73bcb7`) on the block fixture, and a set of real requests on a snapshot of a real site, which is private bench data (README, "Private fixtures"). `reports/run5-summary.md` calls that one `real-site`. Both sets run every condition and both models, with 3 repetitions of every cell and 24 model calls per question. The three frozen sets run again on the Wayfinder cells only, one repetition, as a regression check. Check the hash first:

```
node src/cli.ts --hash-questions --question-set edit-safety-2
```

Then the published runs and find eval:

```
node src/cli.ts --question-set edit-safety-2 --max-turns 24 --repetitions 3 --concurrency 6 --run-label run5
node src/cli.ts --fixture block              --condition manual --condition manual-preloaded --max-turns 24 --repetitions 1 --run-label run5-regression
node src/cli.ts --fixture classic            --condition manual --condition manual-preloaded --max-turns 24 --repetitions 1 --run-label run5-regression
node src/cli.ts --question-set edit-safety   --condition manual --condition manual-preloaded --max-turns 24 --repetitions 1 --run-label run5-regression
node src/cli.ts --find-eval --question-set edit-safety-2
```

`--summarize-run5` takes each held-out report with `--summarize-run5`, the regression runs with `--regression`, the run 4 and turn cap sensitivity reports with `--earlier`, and every find eval with `--find-eval-report`. With only the published reports it judges S1 to S4 on edit-safety-2 alone and leaves S5 unmeasured, because S5 needs a real-site snapshot beside a seeded fixture. With the private data present, `bench/private/config.json` registers the private set and its reports sit in `private/reports/`.

The regression runs have no baseline cells, so the summary restates R1 to R7 on the frozen sets against the best baseline cell the `--earlier` reports recorded on each set: run 4's at 12 turns, or the turn cap sensitivity run's at 24. A regression run writes `<site>-latest` like any run. Since it covers two conditions, keep each set's `-latest` from its last full run (run 4) when collecting reports.

### How run 5 ran

- **Where:** each line ran on a GitHub Actions runner through `.github/workflows/bench.yml`, with the line's arguments as the workflow's `args`. All five runs and both held-out find evals are at `826cce2`, a commit that differs from the day's main only under `bench/`, so the plugin measured is 0.8.0. Each report records the commit.
- **Order:** the real-site set and the two held-out find evals ran first. edit-safety-2 and the three regression runs started once the real-site set's measured cost showed the run would stay within its approved budget.
- **The adapter on the real site:** the site has no default MCP server, so the condition used the site's own read-only server (`adapter_endpoint`, see README). It offered 28 tools, none of them Wayfinder's.
- **Smoke runs:** two smoke runs on the real-site set (one repetition of every cell on two questions) came before the full run. The first stopped at adapter discovery, which is what added `adapter_endpoint`; the second set the budget. Neither report is in `reports/`.
- **Size and cost:** 3,060 question runs, $130.93 in agent calls and $1.08 for the judge, plus $3.13 for the second smoke run.
- **Transcripts:** edit-safety-2 and the three regression runs are the release `bench-run5-transcripts` (`README.md`, "Transcripts"). Extract it into `bench/runs/` to re-grade with `--regrade-transcripts`. The real-site set's cli cells read files from private plugins and a private theme, so its transcripts and its report are private bench data.

## Re-grade the published transcripts without rerunning

The full transcripts of run 3 are the release asset `run3-transcripts.tar.gz` on the `bench-run3-transcripts` release. `README.md`, "Transcripts", says where the releases are and how to check a copy. Extract it into `bench/runs/`, then:

```
node src/cli.ts --regrade-transcripts runs/<block run dir> --report reports/fixture-2026-10-03-run3.json --truth ../fixtures/truth.json
node src/cli.ts --regrade-transcripts runs/<classic run dir> --report reports/fixture-classic-2026-10-03-run3.json --truth ../fixtures/classic/truth.json --fixture classic
```

This grades every reply again with the graders in your checkout, calling the judge for judge questions, and writes a `-regraded` report beside the original. It costs a few cents.

Without the release, re-grade what the reports keep: `src/replay.ts` grades every exact and set answer again from its answer line (`node test/replay-run5.ts` does it for run 5), and a rerun of any set above gives you transcripts of your own.

## Turn cap

Run 3 stops every question at 12 model calls and scores a run that reaches the cap as wrong, in every condition, and most baseline misses were cap hits. So the raw and adapter cells were rerun at 24 calls, one repetition each, with the same questions, graders and fixtures: `reports/fixture-2026-10-03-run3-sensitivity.md` and `reports/fixture-classic-2026-10-03-run3-sensitivity.md`, with the transcripts in `run3-sensitivity-transcripts.tar.gz` on the `bench-run3-sensitivity-transcripts` release (`README.md`, "Transcripts"). They ran on a GitHub Actions runner with wp-env, where run 3 ran on a local wp-env site. The cap counts model calls, not time, so the host moves wall times and not what a run can answer. Doubling the cap moved the best baseline per cell by -0.8 to +7.8 points. Haiku with the manual stays 32.5 to 43.3 points ahead of the best Haiku baseline at either cap. On block, Haiku's baseline runs rarely reached the 24-call cap (1 and 2 of 40) and still answered only 65.0% (raw) and 62.5% (adapter); on classic a fifth to a quarter of them (6 and 8 of 30) still reached it. For the small model the baseline needs the map, not more turns. Sonnet is closer. Against the best baseline per cell R2 fails at both caps (block +5.8 and +6.7 points; classic +11.1 at 12 and +7.8 at 24, against raw), and R3 fails on classic at 24, where Sonnet raw answered 90.0% (27 of 30) against Haiku manual-preloaded's 88.9%. The 24-call cells are single repetitions and Sonnet samples at its default, so one of them can move several points between repetitions (run 3's three classic Sonnet adapter passes scored 76.7%, 70.0% and 66.7%). `reports/run3-summary.md` carries the table under "Best baseline per cell". To rerun it, use the commands under "Turn cap sensitivity" in `README.md`, about $13 in agent calls for both fixtures.

## What will differ from our numbers

- **Sampling.** Runs 1 to 5 sent Haiku 4.5 temperature 0, and Sonnet 5, which rejects a temperature setting, ran at its default. From run 6 no agent model is sent a temperature, so every model runs at its default and answers vary between runs. That is why every cell a hypothesis reads runs 3 times. The judge stays at temperature 0, and even then one df-06 verdict flipped between two identical runs.
- **Models and prices.** The model ids are pinned, but the API can change behind an id. Costs use `pricing.json`, which records the page and date it was read; update it if prices move.
- **Caching.** Every agent call caches its conversation, so a cell's cost depends on which calls landed inside the cache's 5-minute window. Input token counts do not depend on it.
- **Wall times** depend on your machine and the concurrency.

## Clean up

```
npx @wordpress/env destroy --force
```

This deletes the local site, its database and its images. `--fixture` reseeds everything on the next run.
