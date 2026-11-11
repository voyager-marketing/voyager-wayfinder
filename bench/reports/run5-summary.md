# Wayfinder benchmark run 5: summary

> **Public copy.** Run 5's second held-out set runs on a snapshot of a real site. Its questions, answer key and reports are private bench data, so this copy calls it `real-site`, keeps its figures in the claim tables, and leaves out its own section, which quotes the site. With the private data present, `--summarize-run5` writes the full summary.

> **Correction, 2026-10-06: the cli and cli-manual cells ran without a working shell.** The run asked six questions at once, and two `wp-env run` calls at once can leave wp-env's cache without the runtime `wp-env start` saved. Within three minutes of each set's first cli cell, every `wp` and `read_file` call returned "Environment not initialized. Run `wp-env start` first." That is 465 of the 547 container calls in edit-safety-2's cli and cli-manual cells, and 1,949 of 2,174 in real-site's (counted from the run's transcripts: `wp` and `read_file` results carrying that text). So:
>
> - **S2:** the cli-manual vs cli pairs on each set, two of each set's four, compare the map with a broken shell. They are void, not failed. The manual vs raw pairs stand.
> - **S3 and S4** compare against Sonnet's best baseline, the highest of raw, adapter and cli. That came out as the adapter on both sets, but a working cli might have beaten it. So S3 may be wrong in either direction, and S4's real-site pass (10.39x) may be overstated.
> - **What still holds:** on real-site, both no-shell comparisons cleared zero (Haiku manual vs raw +21.5 points, +8.1 to +34.8; Sonnet +15.6, +3.0 to +28.1).
>
> The harness now runs one `wp-env run` at a time, checks the container before every cli cell and after the last, and fails a cell, and the run, when its tools break. Run 6 measures the working shell. Everything below is as `--summarize-run5` wrote it; regenerating this file drops this note.
>
> Grader correction note: [`run5-regrade-graders.md`](run5-regrade-graders.md) lists the run 5 grades the run 6 graders give differently. Every verdict below stands as graded.

Run 5 is the first run whose claims rest on questions no product fix was tuned on: two held-out sets, edit-safety-2 and real-site, with every condition, both models, 3 repetitions of every cell and 24 model calls per question. Every claim reads a 95% interval, not a point estimate. Plugin and harness at `826cce2a66d2`. 3,060 question runs, $130.93 in agent calls and $1.08 for the judge. Every figure below is recomputed from the reports by `src/run5.ts`; nothing is picked by hand.

## Hypotheses S1 to S6

As the spec words them, approved on 2026-10-04 before any run 5 data. S1 to S4 hold only where they hold on each held-out set. S5 reads every site a run read the manual on. S6 reads the find evals, which make no model call.

|  | Claim | edit-safety-2 | real-site | Run 5 |
|---|---|---|---|---|
| S1 | On each held-out set, the manual condition's accuracy has a 95% interval lower bound of at least 85% on Sonnet and 80% on Haiku | fail | fail | **fail** |
| S2 | The map helps the same agent: manual beats raw, and cli-manual beats cli, on each held-out set and both models, paired by question, with the 95% interval of the difference above zero | fail | fail | **fail** |
| S3 | Haiku with the preloaded manual is within 5 points of Sonnet's best baseline (the highest of raw, adapter and cli on that set) or better: the lower bound of the paired difference is at least -5 points, on each held-out set | fail | fail | **fail** |
| S4 | Haiku with the preloaded manual costs at least 5x less per correct answer than Sonnet's best baseline, with the interval's lower bound at 5x or more, on each held-out set | fail | pass | **fail** |
| S5 | The brief is at most 600 and the index at most 4,000 estimated tokens on the real-site snapshot as well as on the fixtures |  |  | **pass** |
| S6 | Without a model, find's top hit is the answer key's location for at least 85% of edit-location questions that carry find inputs, and one of its hits for at least 95% |  |  | **fail** |

### Numbers

#### edit-safety-2

|  | Result | Numbers |
|---|---|---|
| S1 | fail | Haiku manual 84.4%, 95% interval 71.1% to 96.7%, lower bound needs 80.0%; Sonnet manual 90.0%, 95% interval 80.0% to 97.8%, lower bound needs 85.0% |
| S2 | fail | Haiku manual vs raw +30.0 points (+12.2 to +48.9); Haiku cli-manual vs cli +35.6 points (+18.9 to +53.3); Sonnet manual vs raw +12.2 points (-2.2 to +26.7); Sonnet cli-manual vs cli +4.4 points (-11.1 to +21.1) |
| S3 | fail | Haiku manual-preloaded 91.1% vs Sonnet best (adapter) 85.6%: +5.6 points (-10.0 to +20.0), lower bound needs -5.0 |
| S4 | fail | cost per correct answer $0.0058 vs Sonnet best (adapter) $0.0349: 6.01x (3.39x to 9.98x), lower bound needs 5.00x |

#### real-site

|  | Result | Numbers |
|---|---|---|
| S1 | fail | Haiku manual 57.8%, 95% interval 42.2% to 71.1%, lower bound needs 80.0%; Sonnet manual 67.4%, 95% interval 54.1% to 80.0%, lower bound needs 85.0% |
| S2 | fail | Haiku manual vs raw +21.5 points (+8.1 to +34.8); Haiku cli-manual vs cli +10.4 points (-3.0 to +23.7); Sonnet manual vs raw +15.6 points (+3.0 to +28.1); Sonnet cli-manual vs cli +21.5 points (+8.9 to +34.1) |
| S3 | fail | Haiku manual-preloaded 57.8% vs Sonnet best (adapter) 54.8%: +3.0 points (-12.6 to +18.5), lower bound needs -5.0 |
| S4 | pass | cost per correct answer $0.0248 vs Sonnet best (adapter) $0.2576: 10.39x (6.06x to 17.44x), lower bound needs 5.00x |

#### S5 and S6

|  | Result | Numbers |
|---|---|---|
| S5 | pass | block: brief 547, index 2,494 est. tokens (2,187 and 9,974 chars); snapshot: brief 581, index 3,996 est. tokens (2,324 and 15,983 chars), within budget only because the index left out 170 items to fit it; classic: brief 380, index 2,319 est. tokens (1,517 and 9,275 chars) |
| S6 | fail | held-out sets pooled: top-1 12/18 (66.7%, needs 85.0%), any hit 15/18 (83.3%, needs 95.0%); edit-safety-2 top-1 7/8, any hit 8/8; real-site top-1 5/10, any hit 7/10 |

Beside S5: on the real-site snapshot the index leaves out items to fit its 16,000-character budget (170 in the smoke runs), so S5 holds there by truncation and the preloaded agent reads a partial map. Filed from the smoke runs and not fixed before this run.

### How to read them

- **S1** reads the lower bound of the manual cell's 95% interval, so a cell can score above the threshold and still fail it.
- **S2** needs the paired interval of the difference above zero: the same agent, with and without the map, question by question.
- **S3** is non-inferiority. Passing it means Haiku with the map trails Sonnet's best baseline by no more than 5 points at the interval's lower bound, not that it beats Sonnet. Sonnet's best baseline is the highest pooled accuracy of raw, adapter and cli on that set, chosen after the run; a tie is judged against each tied condition.
- **S4** reads the lower bound of the cost per correct answer ratio, baseline over challenger.
- **S6** pools the held-out sets' edit-location questions, the ones that carry find inputs in the question itself. Each set's own figure is in its numbers, and the frozen sets' find evals are listed below as context.

## The edit-safety-2 set

30 questions, sha256 `79b1b58aef25744644e6dfc104550afb654338bd89897963d14f1a5aec73bcb7`, on the block site, at 24 turns. 1,080 question runs, $18.49 in agent calls. Manual: brief 547 and index 2,494 est. tokens. The adapter cells used the site's MCP server at `/wp-json/mcp/mcp-adapter-default-server`. It listed 3 tools, none of them Wayfinder's (the run stops before any question if one is): `mcp-adapter-discover-abilities`, `mcp-adapter-execute-ability`, `mcp-adapter-get-ability-info`.

### Accuracy by cell

| Model | Condition | Reps | Accuracy | 95% interval | p90 input | Cost per correct | Turn cap hits |
|---|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | raw | 3 | 54.4% (49/90) | 36.7% to 71.1% | 47,372 | $0.0213 | 3 |
| claude-haiku-4-5-20251001 | adapter | 3 | 57.8% (52/90) | 40.0% to 74.4% | 65,388 | $0.0236 | 0 |
| claude-haiku-4-5-20251001 | cli | 3 | 57.8% (52/90) | 40.0% to 75.6% | 33,928 | $0.0246 | 0 |
| claude-haiku-4-5-20251001 | manual | 3 | 84.4% (76/90) | 71.1% to 96.7% | 22,177 | $0.0091 | 0 |
| claude-haiku-4-5-20251001 | manual-preloaded | 3 | 91.1% (82/90) | 80.0% to 100.0% | 29,871 | $0.0058 | 0 |
| claude-haiku-4-5-20251001 | cli-manual | 3 | 93.3% (84/90) | 83.3% to 100.0% | 31,998 | $0.0116 | 0 |
| claude-sonnet-5 | raw | 3 | 77.8% (70/90) | 65.6% to 88.9% | 17,278 | $0.0335 | 0 |
| claude-sonnet-5 | adapter | 3 | 85.6% (77/90) | 75.6% to 94.4% | 45,360 | $0.0349 | 0 |
| claude-sonnet-5 | cli | 3 | 84.4% (76/90) | 72.2% to 95.6% | 77,312 | $0.0399 | 3 |
| claude-sonnet-5 | manual | 3 | 90.0% (81/90) | 80.0% to 97.8% | 30,569 | $0.0194 | 0 |
| claude-sonnet-5 | manual-preloaded | 3 | 88.9% (80/90) | 78.9% to 97.8% | 32,970 | $0.0167 | 0 |
| claude-sonnet-5 | cli-manual | 3 | 88.9% (80/90) | 77.8% to 97.8% | 35,818 | $0.0227 | 0 |

### Accuracy by category

| Model | Condition | client_can | locks | multi_home | selectors | stale_edit | All |
|---|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | raw | 72.2% (13/18) | 33.3% (6/18) | 33.3% (6/18) | 50.0% (9/18) | 83.3% (15/18) | 54.4% (49/90) |
| claude-haiku-4-5-20251001 | adapter | 88.9% (16/18) | 16.7% (3/18) | 33.3% (6/18) | 66.7% (12/18) | 83.3% (15/18) | 57.8% (52/90) |
| claude-haiku-4-5-20251001 | cli | 72.2% (13/18) | 16.7% (3/18) | 33.3% (6/18) | 83.3% (15/18) | 83.3% (15/18) | 57.8% (52/90) |
| claude-haiku-4-5-20251001 | manual | 100.0% (18/18) | 50.0% (9/18) | 83.3% (15/18) | 88.9% (16/18) | 100.0% (18/18) | 84.4% (76/90) |
| claude-haiku-4-5-20251001 | manual-preloaded | 100.0% (18/18) | 66.7% (12/18) | 88.9% (16/18) | 100.0% (18/18) | 100.0% (18/18) | 91.1% (82/90) |
| claude-haiku-4-5-20251001 | cli-manual | 100.0% (18/18) | 83.3% (15/18) | 83.3% (15/18) | 100.0% (18/18) | 100.0% (18/18) | 93.3% (84/90) |
| claude-sonnet-5 | raw | 88.9% (16/18) | 66.7% (12/18) | 66.7% (12/18) | 72.2% (13/18) | 94.4% (17/18) | 77.8% (70/90) |
| claude-sonnet-5 | adapter | 94.4% (17/18) | 88.9% (16/18) | 72.2% (13/18) | 83.3% (15/18) | 88.9% (16/18) | 85.6% (77/90) |
| claude-sonnet-5 | cli | 100.0% (18/18) | 83.3% (15/18) | 77.8% (14/18) | 72.2% (13/18) | 88.9% (16/18) | 84.4% (76/90) |
| claude-sonnet-5 | manual | 100.0% (18/18) | 61.1% (11/18) | 100.0% (18/18) | 100.0% (18/18) | 88.9% (16/18) | 90.0% (81/90) |
| claude-sonnet-5 | manual-preloaded | 100.0% (18/18) | 55.6% (10/18) | 100.0% (18/18) | 100.0% (18/18) | 88.9% (16/18) | 88.9% (80/90) |
| claude-sonnet-5 | cli-manual | 100.0% (18/18) | 55.6% (10/18) | 100.0% (18/18) | 100.0% (18/18) | 88.9% (16/18) | 88.9% (80/90) |

### Most missed

| Condition | Question | Category | Missed | Reason |
|---|---|---|---|---|
| adapter | mh-24 | multi_home | 6/6 | claude-haiku-4-5-20251001: missing post.php?post=90071&action=edit; answered: edit.php?post_type=page (then edit page ID 90080, or search for "Pricing") |
| adapter | mh-26 | multi_home | 5/6 | claude-haiku-4-5-20251001: missing post.php?post=90071&action=edit, post.php?post=90082&action=edit; answered: edit.php?post_type=page&action=edit&post=90082, edit.php?post_type=page&action=edit&post=90081, edit.php?post_type=page… |
| adapter | lk-21 | locks | 4/6 | claude-haiku-4-5-20251001: expected "no"; answered: Yes |
| cli | lk-25 | locks | 6/6 | claude-haiku-4-5-20251001: expected "no"; answered: no ANSWER line |
| cli | mh-24 | multi_home | 6/6 | claude-haiku-4-5-20251001: missing post.php?post=90071&action=edit; answered: edit.php?post_type=wp_block&post=90071 |
| cli | mh-26 | multi_home | 4/6 | claude-haiku-4-5-20251001: missing post.php?post=90071&action=edit, post.php?post=90082&action=edit; answered: edit.php?post_type=page&action=edit&post=90082, edit.php?post_type=page&action=edit&post=90081, edit.php?post_type=page… |
| cli-manual | lk-26 | locks | 6/6 | claude-haiku-4-5-20251001: expected "yes"; answered: No |
| cli-manual | lk-24 | locks | 3/6 | claude-sonnet-5: expected "yes"; answered: No |
| cli-manual | mh-22 | multi_home | 3/6 | claude-haiku-4-5-20251001: expected {/about/, /guarantee/, /maintenance-plan/}; answered: /guarantee/, /maintenance-plan/ |
| manual | lk-21 | locks | 5/6 | claude-haiku-4-5-20251001: expected "no"; answered: Yes |
| manual | lk-26 | locks | 5/6 | claude-haiku-4-5-20251001: expected "yes"; answered: No |
| manual | lk-23 | locks | 3/6 | claude-haiku-4-5-20251001: expected {Call-out fee, Labor rate, Price promise}; answered: Call-out fee, Labor rate, Night rate, Price promise |

### Losses

| Model | Metric | Baseline | Wayfinder cell |
|---|---|---|---|
| claude-haiku-4-5-20251001 | median input tokens | raw 8,733 | manual-preloaded 16,750 |
| claude-haiku-4-5-20251001 | median input tokens | adapter 12,325 | manual-preloaded 16,750 |
| claude-haiku-4-5-20251001 | median input tokens | cli 13,016 | manual-preloaded 16,750 |
| claude-sonnet-5 | accuracy, locks | raw 66.7% (12/18) | manual 61.1% (11/18) |
| claude-sonnet-5 | accuracy, stale_edit | raw 94.4% (17/18) | manual 88.9% (16/18) |
| claude-sonnet-5 | median input tokens | raw 4,365 | manual 5,581 |
| claude-sonnet-5 | p90 input tokens | raw 17,278 | manual 30,569 |
| claude-sonnet-5 | accuracy, locks | adapter 88.9% (16/18) | manual 61.1% (11/18) |
| claude-sonnet-5 | accuracy, locks | cli 83.3% (15/18) | manual 61.1% (11/18) |
| claude-sonnet-5 | accuracy, locks | raw 66.7% (12/18) | manual-preloaded 55.6% (10/18) |
| claude-sonnet-5 | accuracy, stale_edit | raw 94.4% (17/18) | manual-preloaded 88.9% (16/18) |
| claude-sonnet-5 | median input tokens | raw 4,365 | manual-preloaded 13,978 |
| claude-sonnet-5 | p90 input tokens | raw 17,278 | manual-preloaded 32,970 |
| claude-sonnet-5 | total input tokens per pass | raw 424,617 | manual-preloaded 557,753 |
| claude-sonnet-5 | accuracy, locks | adapter 88.9% (16/18) | manual-preloaded 55.6% (10/18) |
| claude-sonnet-5 | median input tokens | adapter 6,568 | manual-preloaded 13,978 |
| claude-sonnet-5 | accuracy, locks | cli 83.3% (15/18) | manual-preloaded 55.6% (10/18) |
| claude-sonnet-5 | median input tokens | cli 8,679 | manual-preloaded 13,978 |
| claude-sonnet-5 | accuracy, locks | raw 66.7% (12/18) | cli-manual 55.6% (10/18) |
| claude-sonnet-5 | accuracy, stale_edit | raw 94.4% (17/18) | cli-manual 88.9% (16/18) |
| claude-sonnet-5 | median input tokens | raw 4,365 | cli-manual 6,993 |
| claude-sonnet-5 | p90 input tokens | raw 17,278 | cli-manual 35,818 |
| claude-sonnet-5 | total input tokens per pass | raw 424,617 | cli-manual 499,236 |
| claude-sonnet-5 | accuracy, locks | adapter 88.9% (16/18) | cli-manual 55.6% (10/18) |
| claude-sonnet-5 | median input tokens | adapter 6,568 | cli-manual 6,993 |
| claude-sonnet-5 | accuracy, locks | cli 83.3% (15/18) | cli-manual 55.6% (10/18) |

### Intervals

Every figure below has a 95% interval from resampling questions with replacement: 10,000 resamples from seed 1215, so the numbers are the same on every run, with the 250th and 9,750th sorted values as bounds. A question counts once, scored as the share of its repetitions answered correctly, because the repetitions of one question are not independent samples. Each comparison pairs its two cells by question. McNemar's exact test pairs repetition k of one cell with repetition k of the other, over the repetitions both ran; it treats those pairs as independent, so its p is optimistic and the interval is the figure to read. "Read by" names the rules that read a figure against the named baseline; the best-baseline restatement reads whichever baseline row it names. Computed by `src/intervals.ts` from the graders' verdicts in this report, with no API calls. Hand re-grades in a `corrected` block are not resampled.

#### Cell accuracy

| Model | Condition | Questions | Reps | Accuracy | 95% interval | Read by |
|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | raw | 30 | 3 | 54.4% | 36.7% to 71.1% |  |
| claude-haiku-4-5-20251001 | adapter | 30 | 3 | 57.8% | 40.0% to 74.4% |  |
| claude-haiku-4-5-20251001 | cli | 30 | 3 | 57.8% | 40.0% to 75.6% |  |
| claude-haiku-4-5-20251001 | manual | 30 | 3 | 84.4% | 71.1% to 96.7% | R1 |
| claude-haiku-4-5-20251001 | manual-preloaded | 30 | 3 | 91.1% | 80.0% to 100.0% |  |
| claude-haiku-4-5-20251001 | cli-manual | 30 | 3 | 93.3% | 83.3% to 100.0% |  |
| claude-sonnet-5 | raw | 30 | 3 | 77.8% | 65.6% to 88.9% |  |
| claude-sonnet-5 | adapter | 30 | 3 | 85.6% | 75.6% to 94.4% |  |
| claude-sonnet-5 | cli | 30 | 3 | 84.4% | 72.2% to 95.6% |  |
| claude-sonnet-5 | manual | 30 | 3 | 90.0% | 80.0% to 97.8% | R1 |
| claude-sonnet-5 | manual-preloaded | 30 | 3 | 88.9% | 78.9% to 97.8% |  |
| claude-sonnet-5 | cli-manual | 30 | 3 | 88.9% | 77.8% to 97.8% |  |

#### Paired accuracy

Challenger minus baseline, in percentage points. "Within 5" is the non-inferiority check S3 uses: the lower bound is at least -5 points. McNemar counts the repetition-matched pairs only the challenger answered correctly, and only the baseline.

| Challenger | Baseline | Read by | Challenger accuracy | Baseline accuracy | Difference | 95% interval | Within 5 | McNemar | p |
|---|---|---|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 manual | claude-haiku-4-5-20251001 raw |  | 84.4% | 54.4% | +30.0 | +12.2 to +48.9 | yes | 30 / 3 of 90 | <0.001 |
| claude-haiku-4-5-20251001 manual | claude-haiku-4-5-20251001 adapter | R2 | 84.4% | 57.8% | +26.7 | +10.0 to +44.4 | yes | 26 / 2 of 90 | <0.001 |
| claude-haiku-4-5-20251001 manual | claude-haiku-4-5-20251001 cli |  | 84.4% | 57.8% | +26.7 | +10.0 to +44.4 | yes | 26 / 2 of 90 | <0.001 |
| claude-haiku-4-5-20251001 manual-preloaded | claude-haiku-4-5-20251001 raw |  | 91.1% | 54.4% | +36.7 | +17.8 to +55.6 | yes | 36 / 3 of 90 | <0.001 |
| claude-haiku-4-5-20251001 manual-preloaded | claude-haiku-4-5-20251001 adapter |  | 91.1% | 57.8% | +33.3 | +17.8 to +50.0 | yes | 30 / 0 of 90 | <0.001 |
| claude-haiku-4-5-20251001 manual-preloaded | claude-haiku-4-5-20251001 cli |  | 91.1% | 57.8% | +33.3 | +16.7 to +50.0 | yes | 30 / 0 of 90 | <0.001 |
| claude-haiku-4-5-20251001 cli-manual | claude-haiku-4-5-20251001 raw |  | 93.3% | 54.4% | +38.9 | +18.9 to +58.9 | yes | 38 / 3 of 90 | <0.001 |
| claude-haiku-4-5-20251001 cli-manual | claude-haiku-4-5-20251001 adapter |  | 93.3% | 57.8% | +35.6 | +18.9 to +53.3 | yes | 32 / 0 of 90 | <0.001 |
| claude-haiku-4-5-20251001 cli-manual | claude-haiku-4-5-20251001 cli |  | 93.3% | 57.8% | +35.6 | +18.9 to +53.3 | yes | 32 / 0 of 90 | <0.001 |
| claude-sonnet-5 manual | claude-sonnet-5 raw |  | 90.0% | 77.8% | +12.2 | -2.2 to +26.7 | yes | 18 / 7 of 90 | 0.043 |
| claude-sonnet-5 manual | claude-sonnet-5 adapter | R2 | 90.0% | 85.6% | +4.4 | -7.8 to +16.7 | no | 11 / 7 of 90 | 0.481 |
| claude-sonnet-5 manual | claude-sonnet-5 cli |  | 90.0% | 84.4% | +5.6 | -8.9 to +21.1 | no | 12 / 7 of 90 | 0.359 |
| claude-sonnet-5 manual-preloaded | claude-sonnet-5 raw |  | 88.9% | 77.8% | +11.1 | -4.4 to +26.7 | yes | 18 / 8 of 90 | 0.076 |
| claude-sonnet-5 manual-preloaded | claude-sonnet-5 adapter |  | 88.9% | 85.6% | +3.3 | -10.0 to +16.7 | no | 11 / 8 of 90 | 0.648 |
| claude-sonnet-5 manual-preloaded | claude-sonnet-5 cli |  | 88.9% | 84.4% | +4.4 | -11.1 to +20.0 | no | 13 / 9 of 90 | 0.523 |
| claude-sonnet-5 cli-manual | claude-sonnet-5 raw |  | 88.9% | 77.8% | +11.1 | -4.4 to +26.7 | yes | 18 / 8 of 90 | 0.076 |
| claude-sonnet-5 cli-manual | claude-sonnet-5 adapter |  | 88.9% | 85.6% | +3.3 | -10.0 to +16.7 | no | 10 / 7 of 90 | 0.629 |
| claude-sonnet-5 cli-manual | claude-sonnet-5 cli |  | 88.9% | 84.4% | +4.4 | -11.1 to +21.1 | no | 13 / 9 of 90 | 0.523 |
| claude-haiku-4-5-20251001 manual-preloaded | claude-sonnet-5 raw |  | 91.1% | 77.8% | +13.3 | -2.2 to +28.9 | yes | 19 / 7 of 90 | 0.029 |
| claude-haiku-4-5-20251001 manual-preloaded | claude-sonnet-5 adapter | R3 | 91.1% | 85.6% | +5.6 | -10.0 to +20.0 | no | 13 / 8 of 90 | 0.383 |
| claude-haiku-4-5-20251001 manual-preloaded | claude-sonnet-5 cli |  | 91.1% | 84.4% | +6.7 | -10.0 to +23.3 | no | 14 / 8 of 90 | 0.286 |

#### Paired cost per correct answer

The baseline's cost per correct answer over the challenger's, as R7 reads it: agent cost over correct answers on each side, summed over the resampled questions and every repetition. Above 1x the challenger is cheaper per correct answer.

| Challenger | Baseline | Read by | Challenger | Baseline | Ratio | 95% interval |
|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 manual | claude-haiku-4-5-20251001 raw |  | $0.0091 | $0.0213 | 2.33x | 1.34x to 4.31x |
| claude-haiku-4-5-20251001 manual | claude-haiku-4-5-20251001 adapter |  | $0.0091 | $0.0236 | 2.58x | 1.87x to 3.73x |
| claude-haiku-4-5-20251001 manual | claude-haiku-4-5-20251001 cli |  | $0.0091 | $0.0246 | 2.69x | 1.89x to 4.02x |
| claude-haiku-4-5-20251001 manual-preloaded | claude-haiku-4-5-20251001 raw |  | $0.0058 | $0.0213 | 3.66x | 1.95x to 7.28x |
| claude-haiku-4-5-20251001 manual-preloaded | claude-haiku-4-5-20251001 adapter |  | $0.0058 | $0.0236 | 4.06x | 2.57x to 6.39x |
| claude-haiku-4-5-20251001 manual-preloaded | claude-haiku-4-5-20251001 cli |  | $0.0058 | $0.0246 | 4.23x | 2.69x to 6.66x |
| claude-haiku-4-5-20251001 cli-manual | claude-haiku-4-5-20251001 raw |  | $0.0116 | $0.0213 | 1.83x | 1.06x to 3.28x |
| claude-haiku-4-5-20251001 cli-manual | claude-haiku-4-5-20251001 adapter |  | $0.0116 | $0.0236 | 2.03x | 1.41x to 3.06x |
| claude-haiku-4-5-20251001 cli-manual | claude-haiku-4-5-20251001 cli |  | $0.0116 | $0.0246 | 2.12x | 1.46x to 3.27x |
| claude-sonnet-5 manual | claude-sonnet-5 raw |  | $0.0194 | $0.0335 | 1.73x | 0.98x to 3.03x |
| claude-sonnet-5 manual | claude-sonnet-5 adapter |  | $0.0194 | $0.0349 | 1.80x | 1.03x to 3.11x |
| claude-sonnet-5 manual | claude-sonnet-5 cli |  | $0.0194 | $0.0399 | 2.06x | 1.27x to 3.37x |
| claude-sonnet-5 manual-preloaded | claude-sonnet-5 raw |  | $0.0167 | $0.0335 | 2.00x | 1.21x to 3.20x |
| claude-sonnet-5 manual-preloaded | claude-sonnet-5 adapter |  | $0.0167 | $0.0349 | 2.09x | 1.26x to 3.31x |
| claude-sonnet-5 manual-preloaded | claude-sonnet-5 cli |  | $0.0167 | $0.0399 | 2.39x | 1.53x to 3.67x |
| claude-sonnet-5 cli-manual | claude-sonnet-5 raw |  | $0.0227 | $0.0335 | 1.47x | 0.83x to 2.59x |
| claude-sonnet-5 cli-manual | claude-sonnet-5 adapter |  | $0.0227 | $0.0349 | 1.54x | 0.89x to 2.66x |
| claude-sonnet-5 cli-manual | claude-sonnet-5 cli |  | $0.0227 | $0.0399 | 1.76x | 1.07x to 2.91x |
| claude-haiku-4-5-20251001 manual-preloaded | claude-sonnet-5 raw |  | $0.0058 | $0.0335 | 5.76x | 3.25x to 9.80x |
| claude-haiku-4-5-20251001 manual-preloaded | claude-sonnet-5 adapter | R7 | $0.0058 | $0.0349 | 6.01x | 3.39x to 9.98x |
| claude-haiku-4-5-20251001 manual-preloaded | claude-sonnet-5 cli |  | $0.0058 | $0.0399 | 6.88x | 3.99x to 11.36x |

## The real-site set

45 questions on a snapshot of a real site, at 24 turns. 1,620 question runs, $108.92 in agent calls. Manual: brief 581 and index 3,996 est. tokens. The adapter cells used the site's own read-only MCP server, which offered none of Wayfinder's tools. This set's own tables (accuracy by cell and by category, the most missed questions, losses and intervals) quote the site's pages, settings and answers, so they stay with its report in the private bench data. Its figures for S1 to S6 are in the tables above.

## Find eval

No model and no sampling: each edit-location question's find inputs go to the find route, and the answer key's location is checked at hit 1 and at every hit (`src/findEval.ts`).

| Set | Held out | Questions | Top-1 | Any hit | Report | Commit |
|---|---|---|---|---|---|---|
| edit-safety-2 | yes | 8 | 7 (87.5%) | 8 (100.0%) | `reports/fixture-edit-safety-2-2026-10-06-find-eval.json` | `826cce2a66d2` |
| real-site | yes | 10 | 5 (50.0%) | 7 (70.0%) | private bench data | `826cce2a66d2` |
| block | no, seen | 7 | 4 (57.1%) | 5 (71.4%) | `reports/fixture-2026-10-06-find-eval.json` | `3ffbe5315b47` |
| classic | no, seen | 6 | 0 (0.0%) | 0 (0.0%) | `reports/fixture-classic-2026-10-06-find-eval.json` | `3ffbe5315b47` |
| edit-safety | no, seen | 7 | 6 (85.7%) | 7 (100.0%) | `reports/fixture-edit-safety-2026-10-06-find-eval.json` | `3ffbe5315b47` |

## Frozen sets: regression, seen questions

The block, classic and edit-safety sets were seen by every run before this one, and product fixes were made from their misses, so nothing here is evidence for S1 to S6. Run 5 asks them again on the Wayfinder cells only, one repetition. Since run 4 the block site gained the edit-safety-2 rows (three pages and a synced pattern), the bench's find tool takes a class and has a longer description, the judge confirms the values a reply names, and the plugin changed. So a change below is not the plugin alone.

### Wayfinder cells against the last run that had them

| Set | Model | Condition | Before | Run 5 | Change (points) | Turn cap hits, run 5 |
|---|---|---|---|---|---|---|
| block | claude-haiku-4-5-20251001 | manual | 97.5% (117/120) (run4, 12 turns) | 100.0% (40/40) | +2.5 | 0 |
| block | claude-haiku-4-5-20251001 | manual-preloaded | 94.2% (113/120) (run4, 12 turns) | 100.0% (40/40) | +5.8 | 0 |
| block | claude-sonnet-5 | manual | 95.0% (114/120) (run4, 12 turns) | 97.5% (39/40) | +2.5 | 0 |
| block | claude-sonnet-5 | manual-preloaded | 90.0% (36/40) (run4, 12 turns) | 92.5% (37/40) | +2.5 | 0 |
| classic | claude-haiku-4-5-20251001 | manual | 97.8% (88/90) (run4, 12 turns) | 100.0% (30/30) | +2.2 | 0 |
| classic | claude-haiku-4-5-20251001 | manual-preloaded | 93.3% (84/90) (run4, 12 turns) | 93.3% (28/30) | 0.0 | 0 |
| classic | claude-sonnet-5 | manual | 97.8% (88/90) (run4, 12 turns) | 100.0% (30/30) | +2.2 | 0 |
| classic | claude-sonnet-5 | manual-preloaded | 93.3% (28/30) (run4, 12 turns) | 100.0% (30/30) | +6.7 | 0 |
| edit-safety | claude-haiku-4-5-20251001 | manual | 70.0% (42/60) (run4, 12 turns) | 100.0% (20/20) | +30.0 | 0 |
| edit-safety | claude-haiku-4-5-20251001 | manual-preloaded | 66.7% (40/60) (run4, 12 turns) | 85.0% (17/20) | +18.3 | 0 |
| edit-safety | claude-sonnet-5 | manual | 95.0% (57/60) (run4, 12 turns) | 95.0% (19/20) | 0.0 | 0 |
| edit-safety | claude-sonnet-5 | manual-preloaded | 75.0% (15/20) (run4, 12 turns) | 90.0% (18/20) | +15.0 | 0 |

### R1 to R7 against the best earlier baseline

Run 5 runs no baseline cell on these sets. Each model's baseline is the best raw, adapter or cli cell any earlier run recorded on the same question set (highest pooled accuracy, a tie going to fewer input tokens), judged by `judgeRun3` unchanged with that cell in place of adapter-only. The baseline cells come from other runs at other caps, so the token and cost rules (R5 to R7) compare run 5's Wayfinder cells with those runs' baselines. Labelled seen questions.

| Set | Model | Best earlier baseline | Accuracy | From |
|---|---|---|---|---|
| block | claude-haiku-4-5-20251001 | raw | 65.0% (26/40) | `reports/fixture-2026-10-03-run3-sensitivity.json` (run3-sensitivity, 24 turns) |
| block | claude-sonnet-5 | adapter (tied) | 87.5% (105/120) | `reports/fixture-2026-10-04-run4.json` (run4, 12 turns) |
| classic | claude-haiku-4-5-20251001 | raw (tied) | 56.7% (17/30) | `reports/fixture-classic-2026-10-03-run3-sensitivity.json` (run3-sensitivity, 24 turns) |
| classic | claude-sonnet-5 | raw | 90.0% (27/30) | `reports/fixture-classic-2026-10-03-run3-sensitivity.json` (run3-sensitivity, 24 turns) |
| edit-safety | claude-haiku-4-5-20251001 | adapter | 68.3% (41/60) | `reports/fixture-edit-safety-2026-10-04-run4.json` (run4, 12 turns) |
| edit-safety | claude-sonnet-5 | adapter | 75.0% (45/60) | `reports/fixture-edit-safety-2026-10-04-run4.json` (run4, 12 turns) |

|  | Claim | block | classic | edit-safety | Frozen sets |
|---|---|---|---|---|---|
| R1 | The manual condition reaches at least 90% accuracy on the block fixture and at least 85% on the classic fixture, on both models | pass | pass | pass | **pass** |
| R2 | The manual condition beats adapter-only by at least 10 percentage points of accuracy, on both fixtures and both models | pass | pass | pass | **pass** |
| R3 | Haiku with the preloaded manual matches or beats Sonnet adapter-only on accuracy (mean over repetitions), on both fixtures | pass | pass | pass | **pass** |
| R4 | The brief is at most 600 and the index at most 4,000 estimated tokens, on both fixtures | pass | pass | pass | **pass** |
| R5 | Total input tokens across all questions in the manual condition are at least 3x lower than adapter-only, on both fixtures and both models | pass | pass | fail | **fail** |
| R6 | p90 input tokens per question in the manual condition are at least 3x lower than adapter-only p90, on both fixtures and both models | pass | pass | fail | **fail** |
| R7 | Haiku with the preloaded manual costs at least 10x less per correct answer than Sonnet adapter-only, on both fixtures | pass | pass | fail | **fail** |

#### block

|  | Result | Numbers |
|---|---|---|
| R1 | pass | manual: claude-haiku-4-5-20251001 100.0% (40/40); claude-sonnet-5 97.5% (39/40) |
| R2 | pass | claude-haiku-4-5-20251001 manual 100.0% vs best baseline 65.0% (+35.0 points); claude-sonnet-5 manual 97.5% vs best baseline 87.5% (+10.0 points) |
| R3 | pass | claude-haiku-4-5-20251001 manual-preloaded 100.0% (mean 100.0%, 1 rep) vs claude-sonnet-5 best baseline 87.5%, 90.0%, 85.0% (mean 87.5%, 3 reps) |
| R4 | pass | brief 2,187 chars = 547 est. tokens; index 9,974 chars = 2,494 est. tokens (chars / 4, rounded up) |
| R5 | pass | total input tokens per pass: claude-haiku-4-5-20251001 best baseline 1,823,424 vs manual 399,153 (4.57x); claude-sonnet-5 best baseline 2,219,143 vs manual 306,113 (7.25x) |
| R6 | pass | p90 input tokens per question: claude-haiku-4-5-20251001 best baseline 143,209 vs manual 15,817 (9.05x); claude-sonnet-5 best baseline 155,349 vs manual 12,837 (12.10x) |
| R7 | pass | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0034 vs claude-sonnet-5 best baseline $0.0617 (18.08x) |

#### classic

|  | Result | Numbers |
|---|---|---|
| R1 | pass | manual: claude-haiku-4-5-20251001 100.0% (30/30); claude-sonnet-5 100.0% (30/30) |
| R2 | pass | claude-haiku-4-5-20251001 manual 100.0% vs best baseline 56.7% (+43.3 points); claude-sonnet-5 manual 100.0% vs best baseline 90.0% (+10.0 points) |
| R3 | pass | claude-haiku-4-5-20251001 manual-preloaded 93.3% (mean 93.3%, 1 rep) vs claude-sonnet-5 best baseline 90.0% (mean 90.0%, 1 rep) |
| R4 | pass | brief 1,517 chars = 380 est. tokens; index 9,275 chars = 2,319 est. tokens (chars / 4, rounded up) |
| R5 | pass | total input tokens per pass: claude-haiku-4-5-20251001 best baseline 3,313,151 vs manual 433,321 (7.65x); claude-sonnet-5 best baseline 1,199,287 vs manual 254,713 (4.71x) |
| R6 | pass | p90 input tokens per question: claude-haiku-4-5-20251001 best baseline 307,703 vs manual 43,431 (7.08x); claude-sonnet-5 best baseline 140,902 vs manual 14,636 (9.63x) |
| R7 | pass | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0051 vs claude-sonnet-5 best baseline $0.0521 (10.32x) |

#### edit-safety

|  | Result | Numbers |
|---|---|---|
| R1 | pass | manual: claude-haiku-4-5-20251001 100.0% (20/20); claude-sonnet-5 95.0% (19/20) |
| R2 | pass | claude-haiku-4-5-20251001 manual 100.0% vs best baseline 68.3% (+31.7 points); claude-sonnet-5 manual 95.0% vs best baseline 75.0% (+20.0 points) |
| R3 | pass | claude-haiku-4-5-20251001 manual-preloaded 85.0% (mean 85.0%, 1 rep) vs claude-sonnet-5 best baseline 80.0%, 70.0%, 75.0% (mean 75.0%, 3 reps) |
| R4 | pass | brief 2,187 chars = 547 est. tokens; index 9,974 chars = 2,494 est. tokens (chars / 4, rounded up) |
| R5 | fail | total input tokens per pass: claude-haiku-4-5-20251001 best baseline 406,647 vs manual 349,298 (1.16x); claude-sonnet-5 best baseline 280,773 vs manual 306,619 (0.92x) |
| R6 | fail | p90 input tokens per question: claude-haiku-4-5-20251001 best baseline 57,273 vs manual 44,030 (1.30x); claude-sonnet-5 best baseline 23,808 vs manual 38,807 (0.61x) |
| R7 | fail | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0086 vs claude-sonnet-5 best baseline $0.0330 (3.83x) |

## Transcripts

- **edit-safety-2 and the three regression runs:** release `bench-run5-transcripts` on voyager-marketing/voyager-wayfinder.
- **real-site and its second smoke run:** private bench data, beside its snapshot. Its cli cells read files from private plugins and a private theme, so its transcripts and its report stay private.

## Reports

| Set | Role | Report | Question runs | Correct | Agent cost | Turns | Runner | Commit | Question set sha256 | Frozen value |
|---|---|---|---|---|---|---|---|---|---|---|
| edit-safety-2 | held out | `reports/fixture-edit-safety-2-2026-10-06-run5.json` | 1080 | 859 | $18.49 | 24 | github-actions | `826cce2a66d2` | `79b1b58aef25744644e6dfc104550afb654338bd89897963d14f1a5aec73bcb7` | equal |
| real-site | held out | private bench data | 1620 | 882 | $108.92 | 24 | github-actions | `826cce2a66d2` | `de6c5e81de34b7038c74459b44678153b7da9e5ed317ab9cbade6737c7ec0b0d` | equal |
| block | regression | `reports/fixture-2026-10-06-run5-regression.json` | 160 | 156 | $1.23 | 24 | github-actions | `826cce2a66d2` | `e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b` | equal |
| classic | regression | `reports/fixture-classic-2026-10-06-run5-regression.json` | 120 | 118 | $1.06 | 24 | github-actions | `826cce2a66d2` | `4511d7c994aabf5bd16fe64a880ef7dd06bebc2202ced544d78a30bd3b02f9ce` | equal |
| edit-safety | regression | `reports/fixture-edit-safety-2026-10-06-run5-regression.json` | 80 | 74 | $1.23 | 24 | github-actions | `826cce2a66d2` | `69eb2c8401ba4ff31666e968dce3cf8ac31c3c5153c4920a70dbadb9fe372cc9` | equal |
| block | earlier baseline | `reports/fixture-2026-10-04-run4.json` | 720 | 620 | $14.35 | 12 | github-actions | `1cb750a6d2c7` | `e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b` | equal |
| classic | earlier baseline | `reports/fixture-classic-2026-10-04-run4.json` | 540 | 437 | $12.19 | 12 | github-actions | `1cb750a6d2c7` | `4511d7c994aabf5bd16fe64a880ef7dd06bebc2202ced544d78a30bd3b02f9ce` | equal |
| edit-safety | earlier baseline | `reports/fixture-edit-safety-2026-10-04-run4.json` | 360 | 267 | $5.73 | 12 | github-actions | `1cb750a6d2c7` | `69eb2c8401ba4ff31666e968dce3cf8ac31c3c5153c4920a70dbadb9fe372cc9` | equal |
| block | earlier baseline | `reports/fixture-2026-10-03-run3-sensitivity.json` | 160 | 120 | $6.63 | 24 | github-actions | `4263eb4a3a77` | `e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b` | equal |
| classic | earlier baseline | `reports/fixture-classic-2026-10-03-run3-sensitivity.json` | 120 | 85 | $6.31 | 24 | github-actions | `4263eb4a3a77` | `4511d7c994aabf5bd16fe64a880ef7dd06bebc2202ced544d78a30bd3b02f9ce` | equal |

Every question set sha256 above equals the value it was frozen at (`frozenSets()` in `src/run5.ts`, the same values the question-set tests pin); the summary refuses to render otherwise.
