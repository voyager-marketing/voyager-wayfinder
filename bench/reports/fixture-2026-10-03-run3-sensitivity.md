# Wayfinder benchmark: fixture, 2026-10-03 (run3-sensitivity)

## Turn cap sensitivity

This run repeats cells of `reports/fixture-2026-10-03-run3.json`, which ran at 12 model calls per question, at 24. Questions, answer keys and graders are the same; a run that reaches the cap with no answer counts as wrong at either cap. Accuracy at 12 pools the reference run's repetitions. Every reference question run is paired with this run's answer to the same question, so the movement columns count reference question runs and add up to the change in correct answers. Costs are agent calls only, priced as in the reference, and cost per correct answer is agent cost over correct answers, as R7 counts it.

Where each ran: this run on GitHub Actions runner, wp-env; the reference on local wp-env. A run is capped by model calls, not time, so host speed moves wall times and not what a run can answer.

Runs that still reached the 24-turn cap: adapter claude-haiku-4-5-20251001 2 of 40 (5.0%); adapter claude-sonnet-5 1 of 40 (2.5%); raw claude-haiku-4-5-20251001 1 of 40 (2.5%); raw claude-sonnet-5 0 of 40 (0.0%).

| Condition | Model | Reps at 12 | Accuracy at 12 | Accuracy at 24 | Cap-hit misses at 12 | Converted at 24 | Other misses now correct | Correct at 12, wrong at 24 | Cap hits at 24 |
|---|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 3 | 54.2% (65/120) | 62.5% (25/40) | 30 of 55 | 6 | 8 | 4 | 2 of 40 (5.0%) |
| adapter | claude-sonnet-5 | 3 | 88.3% (106/120) | 87.5% (35/40) | 8 of 14 | 4 | 2 | 7 | 1 of 40 (2.5%) |
| raw | claude-haiku-4-5-20251001 | 1 | 60.0% (24/40) | 65.0% (26/40) | 4 of 16 | 1 | 2 | 1 | 1 of 40 (2.5%) |
| raw | claude-sonnet-5 | 1 | 82.5% (33/40) | 85.0% (34/40) | 1 of 7 | 0 | 3 | 2 | 0 of 40 (0.0%) |

| Condition | Model | Cost at 12, all reps | Cost per correct at 12 | Cost at 24 | Cost per correct at 24 |
|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | $2.1220 | $0.0326 | $1.1190 | $0.0448 |
| adapter | claude-sonnet-5 | $6.3608 | $0.0600 | $2.5250 | $0.0721 |
| raw | claude-haiku-4-5-20251001 | $0.9091 | $0.0379 | $0.8761 | $0.0337 |
| raw | claude-sonnet-5 | $1.8001 | $0.0545 | $2.1053 | $0.0619 |

The sections below are this run's own report. It ran only the cells listed above, so a rule that reads another cell is not measured here; `reports/fixture-2026-10-03-run3.json` carries the verdicts.

## Hypotheses

|  | Claim | Result | Numbers |
|---|---|---|---|
| R1 | The manual condition reaches at least 90.0% accuracy on the block fixture, on both models | **not measured** | no results for manual claude-haiku-4-5-20251001 |
| R2 | The manual condition beats adapter by at least 10 percentage points of accuracy, on both models | **not measured** | no results for manual claude-haiku-4-5-20251001 |
| R3 | claude-haiku-4-5-20251001 manual-preloaded matches or beats claude-sonnet-5 adapter on accuracy (mean over repetitions) | **not measured** | no results for manual-preloaded claude-haiku-4-5-20251001 |
| R4 | The brief is at most 600 and the index at most 4,000 estimated tokens | **pass** | brief 1,790 chars = 448 est. tokens; index 8,681 chars = 2,171 est. tokens (chars / 4, rounded up) |
| R5 | Total input tokens across all questions in the manual condition are at least 3x lower than adapter, on both models | **not measured** | no results for manual claude-haiku-4-5-20251001 |
| R6 | p90 input tokens per question in the manual condition are at least 3x lower than the adapter p90, on both models | **not measured** | no results for manual claude-haiku-4-5-20251001 |
| R7 | claude-haiku-4-5-20251001 manual-preloaded costs at least 10x less per correct answer than claude-sonnet-5 adapter | **not measured** | no results for manual-preloaded claude-haiku-4-5-20251001 |

The spec claims R1 to R7 on both fixtures. This report judges them on this fixture; `reports/run3-summary.md` combines the two.

## Losses

Every comparison on the same model where a baseline cell (raw or adapter) did better than a Wayfinder cell (manual or manual-preloaded): accuracy, accuracy per category, median, p90 and total input tokens, and cost per correct answer, pooled over repetitions. The list is computed by `findLosses` in `src/report.ts`, which was committed before the run, so nothing is chosen after the fact.

None. No baseline cell did better than a Wayfinder cell on any metric listed.

## Most-missed questions

Per condition, over every model and repetition, as the graders scored them. The reason is the grader's, from the first miss.

### adapter

| Question | Category | Missed | Reason |
|---|---|---|---|
| cm-02 | content_model | 2/2 | claude-haiku-4-5-20251001: expected {duration, internal_sku, price_from}; answered: id, date, date_gmt, guid, modified, modified_gmt, slug, status, type, link, title, content, excerpt, featured_media, te… |
| df-05 | data_flow | 2/2 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| el-06 | edit_location | 2/2 | claude-haiku-4-5-20251001: missing site-editor.php?p=/wp_navigation/90001; answered: wp-admin/nav-menus.php |

### raw

| Question | Category | Missed | Reason |
|---|---|---|---|
| cm-02 | content_model | 2/2 | claude-haiku-4-5-20251001: expected {duration, internal_sku, price_from}; answered: id, date, date_gmt, guid, modified, modified_gmt, slug, status, type, link, title, content, excerpt, featured_media, te… |
| cm-05 | content_model | 2/2 | claude-haiku-4-5-20251001: The answer fails to identify the three specific ACF fields (role, photo, and LinkedIn) that are actually configured for team members; instead it lists only WordPress core fields and incorrectly states that no custom ACF fields are configured.; answered: Title, Content, Da… |
| df-04 | data_flow | 2/2 | claude-haiku-4-5-20251001: turn cap reached with no answer |

## Prompt caching (manual-preloaded)

The brief and index sit in a system block marked `cache_control: ephemeral`. The first-call input is everything the first model call read: tools, system prompt, manual and question.

No manual-preloaded runs.

## Conversation caching

Every model call put a cache breakpoint on its latest message (VOY-1129), so each call read the conversation before its newest turn from the cache once the prefix cleared the model's minimum. Input total counts uncached input, cache writes and cache reads; the read share is the part billed at the cache-read rate. Costs in this report are on that basis and are not comparable with runs 1 and 2, which cached only the preloaded manual.

| Condition | Model | Runs | Input total | Uncached | Cache writes | Cache reads | Read share | Runs reading cache |
|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 40 | 2,622,656 | 236,146 | 427,009 | 1,959,501 | 74.7% | 21/40 |
| adapter | claude-sonnet-5 | 40 | 2,747,420 | 384 | 636,005 | 2,111,031 | 76.8% | 40/40 |
| raw | claude-haiku-4-5-20251001 | 40 | 1,823,424 | 163,617 | 369,102 | 1,290,705 | 70.8% | 19/40 |
| raw | claude-sonnet-5 | 40 | 1,867,288 | 34,104 | 578,768 | 1,254,416 | 67.2% | 30/40 |

## Run

Run in GitHub Actions (bench.yml) against a fixture site the workflow built and seeded. 6 concurrent question runs, so wall times include queueing at the site. Harness and plugin at commit `4263eb4a3a77b10120b5b127c4caba410a099959`. At most 24 model calls per question. Question set questions/fixture.yaml, 40 questions, sha256 `e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b`. Pricing from https://platform.claude.com/docs/en/about-claude/pricing, fetched 2026-10-01. Models: claude-haiku-4-5-20251001 {"temperature":0}, claude-sonnet-5 {}.

## Accuracy

| Condition | Model | Rep | Correct | Accuracy |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 25/40 | 62.5% |
| adapter | claude-sonnet-5 | 1 | 35/40 | 87.5% |
| raw | claude-haiku-4-5-20251001 | 1 | 26/40 | 65.0% |
| raw | claude-sonnet-5 | 1 | 34/40 | 85.0% |

## Accuracy by category

Pooled over repetitions.

| Condition | Model | Category | Correct | Accuracy |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | change_impact | 2/4 | 50.0% |
| adapter | claude-haiku-4-5-20251001 | content_model | 6/8 | 75.0% |
| adapter | claude-haiku-4-5-20251001 | data_flow | 2/6 | 33.3% |
| adapter | claude-haiku-4-5-20251001 | edit_location | 5/10 | 50.0% |
| adapter | claude-haiku-4-5-20251001 | layout_routes | 6/8 | 75.0% |
| adapter | claude-haiku-4-5-20251001 | settings_integrations | 4/4 | 100.0% |
| adapter | claude-sonnet-5 | change_impact | 4/4 | 100.0% |
| adapter | claude-sonnet-5 | content_model | 6/8 | 75.0% |
| adapter | claude-sonnet-5 | data_flow | 5/6 | 83.3% |
| adapter | claude-sonnet-5 | edit_location | 9/10 | 90.0% |
| adapter | claude-sonnet-5 | layout_routes | 7/8 | 87.5% |
| adapter | claude-sonnet-5 | settings_integrations | 4/4 | 100.0% |
| raw | claude-haiku-4-5-20251001 | change_impact | 4/4 | 100.0% |
| raw | claude-haiku-4-5-20251001 | content_model | 5/8 | 62.5% |
| raw | claude-haiku-4-5-20251001 | data_flow | 2/6 | 33.3% |
| raw | claude-haiku-4-5-20251001 | edit_location | 5/10 | 50.0% |
| raw | claude-haiku-4-5-20251001 | layout_routes | 6/8 | 75.0% |
| raw | claude-haiku-4-5-20251001 | settings_integrations | 4/4 | 100.0% |
| raw | claude-sonnet-5 | change_impact | 4/4 | 100.0% |
| raw | claude-sonnet-5 | content_model | 6/8 | 75.0% |
| raw | claude-sonnet-5 | data_flow | 4/6 | 66.7% |
| raw | claude-sonnet-5 | edit_location | 8/10 | 80.0% |
| raw | claude-sonnet-5 | layout_routes | 8/8 | 100.0% |
| raw | claude-sonnet-5 | settings_integrations | 4/4 | 100.0% |

## Tokens and tool calls per question

Input counts uncached input, cache writes and cache reads together.

| Condition | Model | Rep | Input median | Input p90 | Total median | Total p90 | Tool calls median | Tool calls p90 | Wall median (s) |
|---|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 20692 | 234514 | 21134.5 | 236275 | 5 | 19 | 6.2 |
| adapter | claude-sonnet-5 | 1 | 15616.5 | 151420 | 15975.5 | 158033 | 3 | 14 | 5.1 |
| raw | claude-haiku-4-5-20251001 | 1 | 16177 | 143209 | 16575.5 | 144534 | 5 | 13 | 6.0 |
| raw | claude-sonnet-5 | 1 | 13647.5 | 113140 | 13849 | 114953 | 3 | 11 | 5.1 |

## Cost

| Condition | Model | Rep | Per question | Total |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | $0.0280 | $1.1190 |
| adapter | claude-sonnet-5 | 1 | $0.0631 | $2.5250 |
| raw | claude-haiku-4-5-20251001 | 1 | $0.0219 | $0.8761 |
| raw | claude-sonnet-5 | 1 | $0.0526 | $2.1053 |

Agent total $6.6254. Judge total $0.0314. 120/160 correct.
