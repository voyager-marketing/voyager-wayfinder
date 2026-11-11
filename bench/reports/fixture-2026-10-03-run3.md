# Wayfinder benchmark: fixture, 2026-10-03 (run3)

## Hypotheses

|  | Claim | Result | Numbers |
|---|---|---|---|
| R1 | The manual condition reaches at least 90.0% accuracy on the block fixture, on both models | **pass** | manual: claude-haiku-4-5-20251001 97.5% (117/120), per rep 97.5%, 97.5%, 97.5%; claude-sonnet-5 94.2% (113/120), per rep 92.5%, 97.5%, 92.5% |
| R2 | The manual condition beats adapter by at least 10 percentage points of accuracy, on both models | **fail** | claude-haiku-4-5-20251001 manual 97.5% vs adapter 54.2% (+43.3 points); claude-sonnet-5 manual 94.2% vs adapter 88.3% (+5.8 points) |
| R3 | claude-haiku-4-5-20251001 manual-preloaded matches or beats claude-sonnet-5 adapter on accuracy (mean over repetitions) | **pass** | claude-haiku-4-5-20251001 manual-preloaded 95.0%, 95.0%, 95.0% (mean 95.0%, 3 reps) vs claude-sonnet-5 adapter 87.5%, 85.0%, 92.5% (mean 88.3%, 3 reps) |
| R4 | The brief is at most 600 and the index at most 4,000 estimated tokens | **pass** | brief 1,790 chars = 448 est. tokens; index 8,681 chars = 2,171 est. tokens (chars / 4, rounded up) |
| R5 | Total input tokens across all questions in the manual condition are at least 3x lower than adapter, on both models | **pass** | total input tokens per pass: claude-haiku-4-5-20251001 adapter 1,656,815 vs manual 449,334 (3.69x); claude-sonnet-5 adapter 2,003,938 vs manual 407,298 (4.92x) |
| R6 | p90 input tokens per question in the manual condition are at least 3x lower than the adapter p90, on both models | **pass** | p90 input tokens per question: claude-haiku-4-5-20251001 adapter 106,770 vs manual 17,132 (6.23x); claude-sonnet-5 adapter 148,947 vs manual 13,016 (11.44x) |
| R7 | claude-haiku-4-5-20251001 manual-preloaded costs at least 10x less per correct answer than claude-sonnet-5 adapter | **pass** | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0026 vs claude-sonnet-5 adapter $0.0600 (23.13x) |

The spec claims R1 to R7 on both fixtures. This report judges them on this fixture; `reports/run3-summary.md` combines the two.

## Losses

Every comparison on the same model where a baseline cell (raw or adapter) did better than a Wayfinder cell (manual or manual-preloaded): accuracy, accuracy per category, median, p90 and total input tokens, and cost per correct answer, pooled over repetitions. The list is computed by `findLosses` in `src/report.ts`, which was committed before the run, so nothing is chosen after the fact.

| Model | Metric | Baseline | Wayfinder cell |
|---|---|---|---|
| claude-haiku-4-5-20251001 | accuracy, change_impact | adapter 83.3% (10/12) | manual-preloaded 75.0% (9/12) |
| claude-sonnet-5 | accuracy, settings_integrations | raw 100.0% (4/4) | manual 83.3% (10/12) |
| claude-sonnet-5 | accuracy, settings_integrations | adapter 100.0% (12/12) | manual 83.3% (10/12) |
| claude-sonnet-5 | accuracy, edit_location | adapter 93.3% (28/30) | manual-preloaded 90.0% (9/10) |

## Metrics without a threshold

|  | What | Numbers |
|---|---|---|
| median-input | Median input tokens per question, baseline against manual. Retired as a hypothesis after runs 1 and 2: on easy questions a baseline agent finishes cheaply, and the manual's advantage sits in the long tail, which R6 measures | claude-haiku-4-5-20251001 adapter 16,072 vs manual 6,008 (2.68x); claude-haiku-4-5-20251001 raw 17,720 vs manual 6,008 (2.95x); claude-sonnet-5 adapter 15,505 vs manual 6,157 (2.52x); claude-sonnet-5 raw 12,811 vs manual 6,157 (2.08x) |

## Most-missed questions

Per condition, over every model and repetition, as the graders scored them. The reason is the grader's, from the first miss.

### adapter

| Question | Category | Missed | Reason |
|---|---|---|---|
| cm-03 | content_model | 6/6 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| df-05 | data_flow | 6/6 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| cm-02 | content_model | 5/6 | claude-haiku-4-5-20251001: expected {duration, internal_sku, price_from}; answered: id, date, date_gmt, guid, modified, modified_gmt, slug, status, type, link, title, content, excerpt, featured_media, te… |

### manual

| Question | Category | Missed | Reason |
|---|---|---|---|
| df-05 | data_flow | 6/6 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| el-08 | edit_location | 2/6 | claude-sonnet-5: The answer provides a specific post.php URL with a post ID, but the expected answer requires editing through the Services post type admin list (edit.php?post_type=service), not a direct post edit URL, and does not mention accessing it via wp-admin Services.; answered: post.php?post… |
| si-02 | settings_integrations | 2/6 | claude-sonnet-5: expected {advanced-custom-fields, mcp-adapter, voyager-wayfinder}; answered: advanced-custom-fields/acf, mcp-adapter/mcp-adapter, voyager-wayfinder/voyager-wayfinder |

### manual-preloaded

| Question | Category | Missed | Reason |
|---|---|---|---|
| df-05 | data_flow | 4/4 | claude-haiku-4-5-20251001: The answer fails to identify 'hours' as the business info value that is set up but not shown on the front end, instead claiming only address and phone are configured when the expected data shows all three (address, hours, phone) are set up with hours having an empty 'used… |
| ci-02 | change_impact | 3/4 | claude-haiku-4-5-20251001: The answer correctly identifies that the change shows in the footer on all pages, but it fails to explicitly state that the footer menu is used in the footer template part (part:footer), which is the key structural fact required by the expected answer.; answered: The foot… |
| cm-07 | content_model | 1/4 | claude-sonnet-5: expected "6"; answered: 6-20 |

### raw

| Question | Category | Missed | Reason |
|---|---|---|---|
| cm-02 | content_model | 2/2 | claude-haiku-4-5-20251001: expected {duration, internal_sku, price_from}; answered: id, date, date_gmt, guid, modified, modified_gmt, slug, status, type, link, title, content, excerpt, featured_media, te… |
| cm-05 | content_model | 2/2 | claude-haiku-4-5-20251001: The answer lists standard WordPress post fields (Title, Content, Featured Media, etc.) but fails to identify the three specific ACF fields that are actually stored: Role, Photo, and LinkedIn, which are the expected custom fields configured for team members.; answered: Tit… |
| df-02 | data_flow | 2/2 | claude-haiku-4-5-20251001: turn cap reached with no answer |

## Prompt caching (manual-preloaded)

The brief and index sit in a system block marked `cache_control: ephemeral`. The first-call input is everything the first model call read: tools, system prompt, manual and question.

| Model | Minimum cacheable | First-call input median | Cache writes | Cache reads | Runs reading cache | Engaged |
|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | 4,096 | 4,527 | 61,771 | 1,213,347 | 120/120 | yes |
| claude-sonnet-5 | 1,024 | 5,480 | 40,580 | 407,442 | 39/40 | yes |

- claude-haiku-4-5-20251001: cached. 1,213,347 tokens came from the cache at the cache-read rate in 120 of 120 runs, and 61,771 were written at the cache-write rate.
- claude-sonnet-5: cached. 407,442 tokens came from the cache at the cache-read rate in 39 of 40 runs, and 40,580 were written at the cache-write rate.

Costs below are what the API billed: uncached input at the input rate, cache writes at 1.25x and cache reads at 0.1x of it (pricing.json). Prompts were not padded to reach a minimum.

## Conversation caching

Every model call put a cache breakpoint on its latest message (VOY-1129), so each call read the conversation before its newest turn from the cache once the prefix cleared the model's minimum. Input total counts uncached input, cache writes and cache reads; the read share is the part billed at the cache-read rate. Costs in this report are on that basis and are not comparable with runs 1 and 2, which cached only the preloaded manual.

| Condition | Model | Runs | Input total | Uncached | Cache writes | Cache reads | Read share | Runs reading cache |
|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 120 | 4,970,444 | 677,925 | 578,266 | 3,714,253 | 74.7% | 82/120 |
| adapter | claude-sonnet-5 | 120 | 6,011,814 | 1,062 | 1,726,158 | 4,284,594 | 71.3% | 120/120 |
| manual | claude-haiku-4-5-20251001 | 120 | 1,348,001 | 232,523 | 187,511 | 927,967 | 68.8% | 80/120 |
| manual | claude-sonnet-5 | 120 | 1,221,895 | 592 | 412,737 | 808,566 | 66.2% | 120/120 |
| manual-preloaded | claude-haiku-4-5-20251001 | 120 | 1,276,225 | 1,107 | 61,771 | 1,213,347 | 95.1% | 120/120 |
| manual-preloaded | claude-sonnet-5 | 40 | 448,140 | 118 | 40,580 | 407,442 | 90.9% | 39/40 |
| raw | claude-haiku-4-5-20251001 | 40 | 1,679,900 | 165,304 | 411,390 | 1,103,206 | 65.7% | 21/40 |
| raw | claude-sonnet-5 | 40 | 1,469,319 | 34,100 | 507,195 | 928,024 | 63.2% | 28/40 |

## Run

Run locally against a freshly seeded wp-env fixture site. 6 concurrent question runs, so wall times include queueing at the site. Harness and plugin at commit `66fef91bd2d8d819cd1a3ae66215b79eedcf7e3b`. Question set questions/fixture.yaml, 40 questions, sha256 `e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b`. Pricing from https://platform.claude.com/docs/en/about-claude/pricing, fetched 2026-10-01. Models: claude-haiku-4-5-20251001 {"temperature":0}, claude-sonnet-5 {}.

## Accuracy

| Condition | Model | Rep | Correct | Accuracy |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 22/40 | 55.0% |
| adapter | claude-haiku-4-5-20251001 | 2 | 22/40 | 55.0% |
| adapter | claude-haiku-4-5-20251001 | 3 | 21/40 | 52.5% |
| adapter | claude-sonnet-5 | 1 | 35/40 | 87.5% |
| adapter | claude-sonnet-5 | 2 | 34/40 | 85.0% |
| adapter | claude-sonnet-5 | 3 | 37/40 | 92.5% |
| manual | claude-haiku-4-5-20251001 | 1 | 39/40 | 97.5% |
| manual | claude-haiku-4-5-20251001 | 2 | 39/40 | 97.5% |
| manual | claude-haiku-4-5-20251001 | 3 | 39/40 | 97.5% |
| manual | claude-sonnet-5 | 1 | 37/40 | 92.5% |
| manual | claude-sonnet-5 | 2 | 39/40 | 97.5% |
| manual | claude-sonnet-5 | 3 | 37/40 | 92.5% |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 38/40 | 95.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 38/40 | 95.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 38/40 | 95.0% |
| manual-preloaded | claude-sonnet-5 | 1 | 37/40 | 92.5% |
| raw | claude-haiku-4-5-20251001 | 1 | 24/40 | 60.0% |
| raw | claude-sonnet-5 | 1 | 33/40 | 82.5% |

## Accuracy by category

Pooled over repetitions.

| Condition | Model | Category | Correct | Accuracy |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | change_impact | 10/12 | 83.3% |
| adapter | claude-haiku-4-5-20251001 | content_model | 15/24 | 62.5% |
| adapter | claude-haiku-4-5-20251001 | data_flow | 6/18 | 33.3% |
| adapter | claude-haiku-4-5-20251001 | edit_location | 7/30 | 23.3% |
| adapter | claude-haiku-4-5-20251001 | layout_routes | 18/24 | 75.0% |
| adapter | claude-haiku-4-5-20251001 | settings_integrations | 9/12 | 75.0% |
| adapter | claude-sonnet-5 | change_impact | 12/12 | 100.0% |
| adapter | claude-sonnet-5 | content_model | 17/24 | 70.8% |
| adapter | claude-sonnet-5 | data_flow | 13/18 | 72.2% |
| adapter | claude-sonnet-5 | edit_location | 28/30 | 93.3% |
| adapter | claude-sonnet-5 | layout_routes | 24/24 | 100.0% |
| adapter | claude-sonnet-5 | settings_integrations | 12/12 | 100.0% |
| manual | claude-haiku-4-5-20251001 | change_impact | 12/12 | 100.0% |
| manual | claude-haiku-4-5-20251001 | content_model | 24/24 | 100.0% |
| manual | claude-haiku-4-5-20251001 | data_flow | 15/18 | 83.3% |
| manual | claude-haiku-4-5-20251001 | edit_location | 30/30 | 100.0% |
| manual | claude-haiku-4-5-20251001 | layout_routes | 24/24 | 100.0% |
| manual | claude-haiku-4-5-20251001 | settings_integrations | 12/12 | 100.0% |
| manual | claude-sonnet-5 | change_impact | 12/12 | 100.0% |
| manual | claude-sonnet-5 | content_model | 24/24 | 100.0% |
| manual | claude-sonnet-5 | data_flow | 15/18 | 83.3% |
| manual | claude-sonnet-5 | edit_location | 28/30 | 93.3% |
| manual | claude-sonnet-5 | layout_routes | 24/24 | 100.0% |
| manual | claude-sonnet-5 | settings_integrations | 10/12 | 83.3% |
| manual-preloaded | claude-haiku-4-5-20251001 | change_impact | 9/12 | 75.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | content_model | 24/24 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | data_flow | 15/18 | 83.3% |
| manual-preloaded | claude-haiku-4-5-20251001 | edit_location | 30/30 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | layout_routes | 24/24 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | settings_integrations | 12/12 | 100.0% |
| manual-preloaded | claude-sonnet-5 | change_impact | 4/4 | 100.0% |
| manual-preloaded | claude-sonnet-5 | content_model | 7/8 | 87.5% |
| manual-preloaded | claude-sonnet-5 | data_flow | 5/6 | 83.3% |
| manual-preloaded | claude-sonnet-5 | edit_location | 9/10 | 90.0% |
| manual-preloaded | claude-sonnet-5 | layout_routes | 8/8 | 100.0% |
| manual-preloaded | claude-sonnet-5 | settings_integrations | 4/4 | 100.0% |
| raw | claude-haiku-4-5-20251001 | change_impact | 3/4 | 75.0% |
| raw | claude-haiku-4-5-20251001 | content_model | 5/8 | 62.5% |
| raw | claude-haiku-4-5-20251001 | data_flow | 1/6 | 16.7% |
| raw | claude-haiku-4-5-20251001 | edit_location | 5/10 | 50.0% |
| raw | claude-haiku-4-5-20251001 | layout_routes | 6/8 | 75.0% |
| raw | claude-haiku-4-5-20251001 | settings_integrations | 4/4 | 100.0% |
| raw | claude-sonnet-5 | change_impact | 4/4 | 100.0% |
| raw | claude-sonnet-5 | content_model | 6/8 | 75.0% |
| raw | claude-sonnet-5 | data_flow | 4/6 | 66.7% |
| raw | claude-sonnet-5 | edit_location | 8/10 | 80.0% |
| raw | claude-sonnet-5 | layout_routes | 7/8 | 87.5% |
| raw | claude-sonnet-5 | settings_integrations | 4/4 | 100.0% |

## Tokens and tool calls per question

Input counts uncached input, cache writes and cache reads together.

| Condition | Model | Rep | Input median | Input p90 | Total median | Total p90 | Tool calls median | Tool calls p90 | Wall median (s) |
|---|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 16071.5 | 106770 | 16316.5 | 107840 | 5 | 14 | 8.0 |
| adapter | claude-haiku-4-5-20251001 | 2 | 16071.5 | 106770 | 16316.5 | 107840 | 5 | 14 | 8.3 |
| adapter | claude-haiku-4-5-20251001 | 3 | 16071.5 | 106770 | 16316.5 | 107840 | 5 | 14 | 7.8 |
| adapter | claude-sonnet-5 | 1 | 16337.5 | 162306 | 16646.5 | 165036 | 3 | 13 | 6.5 |
| adapter | claude-sonnet-5 | 2 | 14942 | 132184 | 15222.5 | 136071 | 3 | 14 | 7.7 |
| adapter | claude-sonnet-5 | 3 | 17614.5 | 94683 | 18042.5 | 95911 | 3.5 | 13 | 8.0 |
| manual | claude-haiku-4-5-20251001 | 1 | 6008 | 17132 | 6222 | 17435 | 2 | 4 | 4.3 |
| manual | claude-haiku-4-5-20251001 | 2 | 6008 | 16285 | 6222 | 16552 | 2 | 4 | 4.3 |
| manual | claude-haiku-4-5-20251001 | 3 | 6008 | 16285 | 6222 | 16552 | 2 | 4 | 4.3 |
| manual | claude-sonnet-5 | 1 | 6168 | 13019 | 6274.5 | 13270 | 1 | 3 | 3.4 |
| manual | claude-sonnet-5 | 2 | 6155 | 11739 | 6238 | 12124 | 1 | 3 | 4.0 |
| manual | claude-sonnet-5 | 3 | 6157 | 12960 | 6266 | 13180 | 1 | 2 | 3.6 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 9393.5 | 14433 | 9587 | 14602 | 1 | 2 | 2.9 |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 9372 | 14433 | 9528 | 14602 | 1 | 2 | 2.6 |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 9375.5 | 14433 | 9555 | 14602 | 1 | 2 | 2.9 |
| manual-preloaded | claude-sonnet-5 | 1 | 5480.5 | 11895 | 5558 | 12115 | 0 | 1 | 1.5 |
| raw | claude-haiku-4-5-20251001 | 1 | 17719.5 | 123589 | 18099 | 125030 | 4 | 13 | 7.8 |
| raw | claude-sonnet-5 | 1 | 12810.5 | 120212 | 13146 | 122415 | 3 | 12 | 6.0 |

## Cost

| Condition | Model | Rep | Per question | Total |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | $0.0234 | $0.9348 |
| adapter | claude-haiku-4-5-20251001 | 2 | $0.0150 | $0.5995 |
| adapter | claude-haiku-4-5-20251001 | 3 | $0.0147 | $0.5877 |
| adapter | claude-sonnet-5 | 1 | $0.0556 | $2.2247 |
| adapter | claude-sonnet-5 | 2 | $0.0497 | $1.9873 |
| adapter | claude-sonnet-5 | 3 | $0.0537 | $2.1488 |
| manual | claude-haiku-4-5-20251001 | 1 | $0.0086 | $0.3434 |
| manual | claude-haiku-4-5-20251001 | 2 | $0.0047 | $0.1867 |
| manual | claude-haiku-4-5-20251001 | 3 | $0.0043 | $0.1721 |
| manual | claude-sonnet-5 | 1 | $0.0136 | $0.5448 |
| manual | claude-sonnet-5 | 2 | $0.0133 | $0.5308 |
| manual | claude-sonnet-5 | 3 | $0.0112 | $0.4473 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | $0.0035 | $0.1411 |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | $0.0020 | $0.0803 |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | $0.0019 | $0.0743 |
| manual-preloaded | claude-sonnet-5 | 1 | $0.0078 | $0.3135 |
| raw | claude-haiku-4-5-20251001 | 1 | $0.0227 | $0.9091 |
| raw | claude-sonnet-5 | 1 | $0.0450 | $1.8001 |

Agent total $14.0264. Judge total $0.1239. 609/720 correct.
