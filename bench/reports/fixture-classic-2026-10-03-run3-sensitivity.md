# Wayfinder benchmark: fixture-classic, 2026-10-03 (run3-sensitivity)

## Turn cap sensitivity

This run repeats cells of `reports/fixture-classic-2026-10-03-run3.json`, which ran at 12 model calls per question, at 24. Questions, answer keys and graders are the same; a run that reaches the cap with no answer counts as wrong at either cap. Accuracy at 12 pools the reference run's repetitions. Every reference question run is paired with this run's answer to the same question, so the movement columns count reference question runs and add up to the change in correct answers. Costs are agent calls only, priced as in the reference, and cost per correct answer is agent cost over correct answers, as R7 counts it.

Where each ran: this run on GitHub Actions runner, wp-env; the reference on local wp-env. A run is capped by model calls, not time, so host speed moves wall times and not what a run can answer.

Runs that still reached the 24-turn cap: adapter claude-haiku-4-5-20251001 8 of 30 (26.7%); adapter claude-sonnet-5 1 of 30 (3.3%); raw claude-haiku-4-5-20251001 6 of 30 (20.0%); raw claude-sonnet-5 0 of 30 (0.0%).

| Condition | Model | Reps at 12 | Accuracy at 12 | Accuracy at 24 | Cap-hit misses at 12 | Converted at 24 | Other misses now correct | Correct at 12, wrong at 24 | Cap hits at 24 |
|---|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 3 | 48.9% (44/90) | 56.7% (17/30) | 35 of 46 | 6 | 4 | 3 | 8 of 30 (26.7%) |
| adapter | claude-sonnet-5 | 3 | 71.1% (64/90) | 80.0% (24/30) | 19 of 26 | 11 | 0 | 3 | 1 of 30 (3.3%) |
| raw | claude-haiku-4-5-20251001 | 1 | 46.7% (14/30) | 56.7% (17/30) | 11 of 16 | 2 | 1 | 0 | 6 of 30 (20.0%) |
| raw | claude-sonnet-5 | 1 | 86.7% (26/30) | 90.0% (27/30) | 0 of 4 | 0 | 2 | 1 | 0 of 30 (0.0%) |

| Condition | Model | Cost at 12, all reps | Cost per correct at 12 | Cost at 24 | Cost per correct at 24 |
|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | $2.0118 | $0.0457 | $1.2222 | $0.0719 |
| adapter | claude-sonnet-5 | $6.6305 | $0.1036 | $2.6648 | $0.1110 |
| raw | claude-haiku-4-5-20251001 | $0.6737 | $0.0481 | $1.0207 | $0.0600 |
| raw | claude-sonnet-5 | $1.3139 | $0.0505 | $1.4068 | $0.0521 |

The sections below are this run's own report. It ran only the cells listed above, so a rule that reads another cell is not measured here; `reports/fixture-classic-2026-10-03-run3.json` carries the verdicts.

## Hypotheses

|  | Claim | Result | Numbers |
|---|---|---|---|
| R1 | The manual condition reaches at least 85.0% accuracy on the classic fixture, on both models | **not measured** | no results for manual claude-haiku-4-5-20251001 |
| R2 | The manual condition beats adapter by at least 10 percentage points of accuracy, on both models | **not measured** | no results for manual claude-haiku-4-5-20251001 |
| R3 | claude-haiku-4-5-20251001 manual-preloaded matches or beats claude-sonnet-5 adapter on accuracy (mean over repetitions) | **not measured** | no results for manual-preloaded claude-haiku-4-5-20251001 |
| R4 | The brief is at most 600 and the index at most 4,000 estimated tokens | **pass** | brief 1,203 chars = 301 est. tokens; index 8,370 chars = 2,093 est. tokens (chars / 4, rounded up) |
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
| df-01 | data_flow | 2/2 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| df-02 | data_flow | 2/2 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| el-01 | edit_location | 2/2 | claude-haiku-4-5-20251001: turn cap reached with no answer |

### raw

| Question | Category | Missed | Reason |
|---|---|---|---|
| df-01 | data_flow | 2/2 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| el-01 | edit_location | 2/2 | claude-haiku-4-5-20251001: missing admin.php?page=company-settings; answered: Appearance > Customize or Appearance > Widgets (Footer widget area), or a custom Theme Options page in the Appearance m… |
| el-03 | edit_location | 2/2 | claude-haiku-4-5-20251001: missing nav-menus.php?action=edit&menu=90190; answered: Appearance → Menus, then edit the "Main navigation" menu |

## Prompt caching (manual-preloaded)

The brief and index sit in a system block marked `cache_control: ephemeral`. The first-call input is everything the first model call read: tools, system prompt, manual and question.

No manual-preloaded runs.

## Conversation caching

Every model call put a cache breakpoint on its latest message (VOY-1129), so each call read the conversation before its newest turn from the cache once the prefix cleared the model's minimum. Input total counts uncached input, cache writes and cache reads; the read share is the part billed at the cache-read rate. Costs in this report are on that basis and are not comparable with runs 1 and 2, which cached only the preloaded manual.

| Condition | Model | Runs | Input total | Uncached | Cache writes | Cache reads | Read share | Runs reading cache |
|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 30 | 4,183,732 | 200,665 | 391,596 | 3,591,471 | 85.8% | 21/30 |
| adapter | claude-sonnet-5 | 30 | 3,652,925 | 410 | 553,588 | 3,098,927 | 84.8% | 30/30 |
| raw | claude-haiku-4-5-20251001 | 30 | 3,313,151 | 177,430 | 318,447 | 2,817,274 | 85.0% | 16/30 |
| raw | claude-sonnet-5 | 30 | 1,199,287 | 25,312 | 315,734 | 858,241 | 71.6% | 20/30 |

## Run

Run in GitHub Actions (bench.yml) against a fixture site the workflow built and seeded. 6 concurrent question runs, so wall times include queueing at the site. Harness and plugin at commit `4263eb4a3a77b10120b5b127c4caba410a099959`. At most 24 model calls per question. Question set questions/fixture-classic.yaml, 30 questions, sha256 `4511d7c994aabf5bd16fe64a880ef7dd06bebc2202ced544d78a30bd3b02f9ce`. Pricing from https://platform.claude.com/docs/en/about-claude/pricing, fetched 2026-10-01. Models: claude-haiku-4-5-20251001 {"temperature":0}, claude-sonnet-5 {}.

## Accuracy

| Condition | Model | Rep | Correct | Accuracy |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 17/30 | 56.7% |
| adapter | claude-sonnet-5 | 1 | 24/30 | 80.0% |
| raw | claude-haiku-4-5-20251001 | 1 | 17/30 | 56.7% |
| raw | claude-sonnet-5 | 1 | 27/30 | 90.0% |

## Accuracy by category

Pooled over repetitions.

| Condition | Model | Category | Correct | Accuracy |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | change_impact | 2/3 | 66.7% |
| adapter | claude-haiku-4-5-20251001 | content_model | 5/6 | 83.3% |
| adapter | claude-haiku-4-5-20251001 | data_flow | 1/5 | 20.0% |
| adapter | claude-haiku-4-5-20251001 | edit_location | 4/7 | 57.1% |
| adapter | claude-haiku-4-5-20251001 | layout_routes | 3/6 | 50.0% |
| adapter | claude-haiku-4-5-20251001 | settings_integrations | 2/3 | 66.7% |
| adapter | claude-sonnet-5 | change_impact | 3/3 | 100.0% |
| adapter | claude-sonnet-5 | content_model | 6/6 | 100.0% |
| adapter | claude-sonnet-5 | data_flow | 3/5 | 60.0% |
| adapter | claude-sonnet-5 | edit_location | 4/7 | 57.1% |
| adapter | claude-sonnet-5 | layout_routes | 5/6 | 83.3% |
| adapter | claude-sonnet-5 | settings_integrations | 3/3 | 100.0% |
| raw | claude-haiku-4-5-20251001 | change_impact | 1/3 | 33.3% |
| raw | claude-haiku-4-5-20251001 | content_model | 5/6 | 83.3% |
| raw | claude-haiku-4-5-20251001 | data_flow | 1/5 | 20.0% |
| raw | claude-haiku-4-5-20251001 | edit_location | 3/7 | 42.9% |
| raw | claude-haiku-4-5-20251001 | layout_routes | 4/6 | 66.7% |
| raw | claude-haiku-4-5-20251001 | settings_integrations | 3/3 | 100.0% |
| raw | claude-sonnet-5 | change_impact | 3/3 | 100.0% |
| raw | claude-sonnet-5 | content_model | 6/6 | 100.0% |
| raw | claude-sonnet-5 | data_flow | 4/5 | 80.0% |
| raw | claude-sonnet-5 | edit_location | 5/7 | 71.4% |
| raw | claude-sonnet-5 | layout_routes | 6/6 | 100.0% |
| raw | claude-sonnet-5 | settings_integrations | 3/3 | 100.0% |

## Tokens and tool calls per question

Input counts uncached input, cache writes and cache reads together.

| Condition | Model | Rep | Input median | Input p90 | Total median | Total p90 | Tool calls median | Tool calls p90 | Wall median (s) |
|---|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 41006.5 | 371308 | 41912.5 | 373333 | 8 | 25 | 10.9 |
| adapter | claude-sonnet-5 | 1 | 18481.5 | 403286 | 18889.5 | 410184 | 4.5 | 29 | 5.9 |
| raw | claude-haiku-4-5-20251001 | 1 | 24177 | 307703 | 24742 | 310347 | 6 | 30 | 10.0 |
| raw | claude-sonnet-5 | 1 | 13221 | 140902 | 13953.5 | 144981 | 3 | 18 | 5.6 |

## Cost

| Condition | Model | Rep | Per question | Total |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | $0.0407 | $1.2222 |
| adapter | claude-sonnet-5 | 1 | $0.0888 | $2.6648 |
| raw | claude-haiku-4-5-20251001 | 1 | $0.0340 | $1.0207 |
| raw | claude-sonnet-5 | 1 | $0.0469 | $1.4068 |

Agent total $6.3145. Judge total $0.0122. 85/120 correct.
