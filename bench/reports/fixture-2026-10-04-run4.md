# Wayfinder benchmark: fixture, 2026-10-04 (run4)

## Hypotheses

|  | Claim | Result | Numbers |
|---|---|---|---|
| R1 | The manual condition reaches at least 90.0% accuracy on the block fixture, on both models | **pass** | manual: claude-haiku-4-5-20251001 97.5% (117/120), per rep 97.5%, 97.5%, 97.5%; claude-sonnet-5 95.0% (114/120), per rep 95.0%, 95.0%, 95.0% |
| R2 | The manual condition beats adapter by at least 10 percentage points of accuracy, on both models | **fail** | claude-haiku-4-5-20251001 manual 97.5% vs adapter 64.2% (+33.3 points); claude-sonnet-5 manual 95.0% vs adapter 87.5% (+7.5 points) |
| R3 | claude-haiku-4-5-20251001 manual-preloaded matches or beats claude-sonnet-5 adapter on accuracy (mean over repetitions) | **pass** | claude-haiku-4-5-20251001 manual-preloaded 92.5%, 95.0%, 95.0% (mean 94.2%, 3 reps) vs claude-sonnet-5 adapter 87.5%, 90.0%, 85.0% (mean 87.5%, 3 reps) |
| R4 | The brief is at most 600 and the index at most 4,000 estimated tokens | **pass** | brief 1,879 chars = 470 est. tokens; index 8,885 chars = 2,222 est. tokens (chars / 4, rounded up) |
| R5 | Total input tokens across all questions in the manual condition are at least 3x lower than adapter, on both models | **pass** | total input tokens per pass: claude-haiku-4-5-20251001 adapter 1,940,041 vs manual 484,002 (4.01x); claude-sonnet-5 adapter 2,219,143 vs manual 401,796 (5.52x) |
| R6 | p90 input tokens per question in the manual condition are at least 3x lower than the adapter p90, on both models | **pass** | p90 input tokens per question: claude-haiku-4-5-20251001 adapter 123,835 vs manual 17,381 (7.12x); claude-sonnet-5 adapter 155,349 vs manual 13,331 (11.65x) |
| R7 | claude-haiku-4-5-20251001 manual-preloaded costs at least 10x less per correct answer than claude-sonnet-5 adapter | **pass** | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0025 vs claude-sonnet-5 adapter $0.0617 (25.06x) |

The spec claims R1 to R7 on both fixtures. This report judges them on this fixture; `reports/run3-summary.md` combines the two.

These are the graders' verdicts. With 3 misses re-graded by hand (VOY-1220, see Grader corrections): R1 pass, R2 fail, R3 pass, R4 pass, R5 pass, R6 pass, R7 pass.

## Losses

Every comparison on the same model where a baseline cell (raw or adapter) did better than a Wayfinder cell (manual or manual-preloaded): accuracy, accuracy per category, median, p90 and total input tokens, and cost per correct answer, pooled over repetitions. The list is computed by `findLosses` in `src/report.ts`, which was committed before the run, so nothing is chosen after the fact.

| Model | Metric | Baseline | Wayfinder cell |
|---|---|---|---|
| claude-haiku-4-5-20251001 | accuracy, change_impact | adapter 100.0% (12/12) | manual-preloaded 75.0% (9/12) |
| claude-sonnet-5 | accuracy, data_flow | adapter 77.8% (14/18) | manual 66.7% (12/18) |
| claude-sonnet-5 | accuracy, change_impact | raw 100.0% (4/4) | manual-preloaded 75.0% (3/4) |
| claude-sonnet-5 | accuracy, change_impact | adapter 100.0% (12/12) | manual-preloaded 75.0% (3/4) |

## Metrics without a threshold

|  | What | Numbers |
|---|---|---|
| median-input | Median input tokens per question, baseline against manual. Retired as a hypothesis after runs 1 and 2: on easy questions a baseline agent finishes cheaply, and the manual's advantage sits in the long tail, which R6 measures | claude-haiku-4-5-20251001 adapter 22,112 vs manual 9,763 (2.26x); claude-haiku-4-5-20251001 raw 16,177 vs manual 9,763 (1.66x); claude-sonnet-5 adapter 14,760 vs manual 6,253 (2.36x); claude-sonnet-5 raw 13,806 vs manual 6,253 (2.21x) |

## Most-missed questions

Per condition, over every model and repetition, as the graders scored them. The reason is the grader's, from the first miss.

### adapter

| Question | Category | Missed | Reason |
|---|---|---|---|
| cm-02 | content_model | 6/6 | claude-haiku-4-5-20251001: expected {duration, internal_sku, price_from}; answered: id, date, date_gmt, guid, modified, modified_gmt, slug, status, type, link, title, content, excerpt, featured_media, te… |
| cm-05 | content_model | 6/6 | claude-haiku-4-5-20251001: The answer fails to name the three specific ACF fields (role, photo, and LinkedIn) that are expected; instead it lists generic WordPress post fields and claims the ACF field is empty, contradicting the ground truth that these three fields are configured.; answered: Team m… |
| df-05 | data_flow | 6/6 | claude-haiku-4-5-20251001: turn cap reached with no answer |

### manual

| Question | Category | Missed | Reason |
|---|---|---|---|
| df-05 | data_flow | 6/6 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| df-01 | data_flow | 3/6 | claude-sonnet-5: missing vwf_phone, judge not called; answered: It comes from a block binding (vwf-fixture/business-info.phone) in the Footer template part, sourced from the Business … |

### manual-preloaded

| Question | Category | Missed | Reason |
|---|---|---|---|
| df-05 | data_flow | 4/4 | claude-haiku-4-5-20251001: The answer fails to identify 'hours' as a business info value that is set up but not shown anywhere on the front end; the expected data shows three business info values (address, hours, and phone), with hours having an empty 'used_in' array indicating it is not displayed … |
| ci-02 | change_impact | 3/4 | claude-haiku-4-5-20251001: The answer correctly identifies that the change shows in the footer across all pages, but it goes beyond what was asked by listing specific templates and pages, when the question only requires stating that the change appears in the footer menu/footer template part where i… |
| ci-04 | change_impact | 1/4 | claude-sonnet-5: expected "6"; answered: 15 |

### raw

| Question | Category | Missed | Reason |
|---|---|---|---|
| cm-02 | content_model | 2/2 | claude-haiku-4-5-20251001: expected {duration, internal_sku, price_from}; answered: id, date, date_gmt, guid, modified, modified_gmt, slug, status, type, link, title, content, excerpt, featured_media, te… |
| cm-05 | content_model | 2/2 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| df-02 | data_flow | 2/2 | claude-haiku-4-5-20251001: turn cap reached with no answer |

## Grader corrections

Re-graded by hand from the full replies in release bench-run4-transcripts, under ci-02's rubric and the judge's own instruction that extra correct detail never makes an answer wrong. A reply is correct when it says the change shows in the footer, because the footer menu sits in the footer template part, on every page that uses that part, and says nothing that puts the change in the header menu. The judge failed the three manual-preloaded Haiku replies only for listing the pages and templates, which that instruction excludes. The other 15 ci-02 replies were already graded correct, and none went to the turn cap. The questions, answer keys and graders are unchanged; the judge prompt changes from run 5. Tracked in VOY-1220.

|  | Corrected result | Numbers |
|---|---|---|
| R1 | **pass** | manual: claude-haiku-4-5-20251001 97.5% (117/120), per rep 97.5%, 97.5%, 97.5%; claude-sonnet-5 95.0% (114/120), per rep 95.0%, 95.0%, 95.0% |
| R2 | **fail** | claude-haiku-4-5-20251001 manual 97.5% vs adapter 64.2% (+33.3 points); claude-sonnet-5 manual 95.0% vs adapter 87.5% (+7.5 points) |
| R3 | **pass** | claude-haiku-4-5-20251001 manual-preloaded 95.0%, 97.5%, 97.5% (mean 96.7%, 3 reps) vs claude-sonnet-5 adapter 87.5%, 90.0%, 85.0% (mean 87.5%, 3 reps) |
| R4 | **pass** | brief 1,879 chars = 470 est. tokens; index 8,885 chars = 2,222 est. tokens (chars / 4, rounded up) |
| R5 | **pass** | total input tokens per pass: claude-haiku-4-5-20251001 adapter 1,940,041 vs manual 484,002 (4.01x); claude-sonnet-5 adapter 2,219,143 vs manual 401,796 (5.52x) |
| R6 | **pass** | p90 input tokens per question: claude-haiku-4-5-20251001 adapter 123,835 vs manual 17,381 (7.12x); claude-sonnet-5 adapter 155,349 vs manual 13,331 (11.65x) |
| R7 | **pass** | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0024 vs claude-sonnet-5 adapter $0.0617 (25.73x) |

| Condition | Model | Rep | Graded | Corrected |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 25/40 (62.5%) | 25/40 (62.5%) |
| adapter | claude-haiku-4-5-20251001 | 2 | 26/40 (65.0%) | 26/40 (65.0%) |
| adapter | claude-haiku-4-5-20251001 | 3 | 26/40 (65.0%) | 26/40 (65.0%) |
| adapter | claude-sonnet-5 | 1 | 35/40 (87.5%) | 35/40 (87.5%) |
| adapter | claude-sonnet-5 | 2 | 36/40 (90.0%) | 36/40 (90.0%) |
| adapter | claude-sonnet-5 | 3 | 34/40 (85.0%) | 34/40 (85.0%) |
| manual | claude-haiku-4-5-20251001 | 1 | 39/40 (97.5%) | 39/40 (97.5%) |
| manual | claude-haiku-4-5-20251001 | 2 | 39/40 (97.5%) | 39/40 (97.5%) |
| manual | claude-haiku-4-5-20251001 | 3 | 39/40 (97.5%) | 39/40 (97.5%) |
| manual | claude-sonnet-5 | 1 | 38/40 (95.0%) | 38/40 (95.0%) |
| manual | claude-sonnet-5 | 2 | 38/40 (95.0%) | 38/40 (95.0%) |
| manual | claude-sonnet-5 | 3 | 38/40 (95.0%) | 38/40 (95.0%) |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 37/40 (92.5%) | 38/40 (95.0%) |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 38/40 (95.0%) | 39/40 (97.5%) |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 38/40 (95.0%) | 39/40 (97.5%) |
| manual-preloaded | claude-sonnet-5 | 1 | 36/40 (90.0%) | 36/40 (90.0%) |
| raw | claude-haiku-4-5-20251001 | 1 | 24/40 (60.0%) | 24/40 (60.0%) |
| raw | claude-sonnet-5 | 1 | 34/40 (85.0%) | 34/40 (85.0%) |

| Question | Condition | Model | Rep | Why it is correct |
|---|---|---|---|---|
| ci-02 | manual-preloaded | claude-haiku-4-5-20251001 | 1 | Names part:footer as the part the footer menu reaches pages through, and says the renamed link shows in the footer of every page (homepage, contact page, service archive, services, blog home, posts and all other pages). Nothing puts it in the header menu. The judge failed it for listing pages and templates, which is extra correct detail. Its body lists six of the part's templates, the brief's first five plus single-service without the brief's "+5", but the answer line still says every page |
| ci-02 | manual-preloaded | claude-haiku-4-5-20251001 | 2 | The same reply as rep 1: part:footer, the footer of every page, nothing about the header menu. Failed only for listing pages and templates |
| ci-02 | manual-preloaded | claude-haiku-4-5-20251001 | 3 | The same reply as rep 1: part:footer, the footer of every page, nothing about the header menu. Failed only for listing pages and templates |

## Prompt caching (manual-preloaded)

The brief and index sit in a system block marked `cache_control: ephemeral`. The first-call input is everything the first model call read: tools, system prompt, manual and question.

| Model | Minimum cacheable | First-call input median | Cache writes | Cache reads | Runs reading cache | Engaged |
|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | 4,096 | 4,610 | 44,804 | 1,261,481 | 120/120 | yes |
| claude-sonnet-5 | 1,024 | 5,588 | 18,477 | 319,244 | 40/40 | yes |

- claude-haiku-4-5-20251001: cached. 1,261,481 tokens came from the cache at the cache-read rate in 120 of 120 runs, and 44,804 were written at the cache-write rate.
- claude-sonnet-5: cached. 319,244 tokens came from the cache at the cache-read rate in 40 of 40 runs, and 18,477 were written at the cache-write rate.

Costs below are what the API billed: uncached input at the input rate, cache writes at 1.25x and cache reads at 0.1x of it (pricing.json). Prompts were not padded to reach a minimum.

## Conversation caching

Every model call put a cache breakpoint on its latest message (VOY-1129), so each call read the conversation before its newest turn from the cache once the prefix cleared the model's minimum. Input total counts uncached input, cache writes and cache reads; the read share is the part billed at the cache-read rate. Costs in this report are on that basis and are not comparable with runs 1 and 2, which cached only the preloaded manual.

| Condition | Model | Runs | Input total | Uncached | Cache writes | Cache reads | Read share | Runs reading cache |
|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 120 | 5,820,124 | 704,937 | 631,082 | 4,484,105 | 77.0% | 81/120 |
| adapter | claude-sonnet-5 | 120 | 6,657,428 | 1,048 | 1,716,142 | 4,940,238 | 74.2% | 120/120 |
| manual | claude-haiku-4-5-20251001 | 120 | 1,452,007 | 215,276 | 214,775 | 1,021,956 | 70.4% | 85/120 |
| manual | claude-sonnet-5 | 120 | 1,205,388 | 598 | 427,657 | 777,133 | 64.5% | 120/120 |
| manual-preloaded | claude-haiku-4-5-20251001 | 120 | 1,307,419 | 1,134 | 44,804 | 1,261,481 | 96.5% | 120/120 |
| manual-preloaded | claude-sonnet-5 | 40 | 337,827 | 106 | 18,477 | 319,244 | 94.5% | 40/40 |
| raw | claude-haiku-4-5-20251001 | 40 | 1,796,975 | 149,843 | 408,115 | 1,239,017 | 69.0% | 20/40 |
| raw | claude-sonnet-5 | 40 | 1,583,702 | 34,184 | 555,898 | 993,620 | 62.7% | 28/40 |

## Run

Run in GitHub Actions (bench.yml) against a fixture site the workflow built and seeded. 6 concurrent question runs, so wall times include queueing at the site. Harness and plugin at commit `1cb750a6d2c79c42fe691329ff6d464631181234`. At most 12 model calls per question. Question set questions/fixture.yaml, 40 questions, sha256 `e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b`. Pricing from https://platform.claude.com/docs/en/about-claude/pricing, fetched 2026-10-01. Models: claude-haiku-4-5-20251001 {"temperature":0}, claude-sonnet-5 {}.

## Accuracy

| Condition | Model | Rep | Correct | Accuracy |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 25/40 | 62.5% |
| adapter | claude-haiku-4-5-20251001 | 2 | 26/40 | 65.0% |
| adapter | claude-haiku-4-5-20251001 | 3 | 26/40 | 65.0% |
| adapter | claude-sonnet-5 | 1 | 35/40 | 87.5% |
| adapter | claude-sonnet-5 | 2 | 36/40 | 90.0% |
| adapter | claude-sonnet-5 | 3 | 34/40 | 85.0% |
| manual | claude-haiku-4-5-20251001 | 1 | 39/40 | 97.5% |
| manual | claude-haiku-4-5-20251001 | 2 | 39/40 | 97.5% |
| manual | claude-haiku-4-5-20251001 | 3 | 39/40 | 97.5% |
| manual | claude-sonnet-5 | 1 | 38/40 | 95.0% |
| manual | claude-sonnet-5 | 2 | 38/40 | 95.0% |
| manual | claude-sonnet-5 | 3 | 38/40 | 95.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 37/40 | 92.5% |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 38/40 | 95.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 38/40 | 95.0% |
| manual-preloaded | claude-sonnet-5 | 1 | 36/40 | 90.0% |
| raw | claude-haiku-4-5-20251001 | 1 | 24/40 | 60.0% |
| raw | claude-sonnet-5 | 1 | 34/40 | 85.0% |

## Accuracy by category

Pooled over repetitions.

| Condition | Model | Category | Correct | Accuracy |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | change_impact | 12/12 | 100.0% |
| adapter | claude-haiku-4-5-20251001 | content_model | 15/24 | 62.5% |
| adapter | claude-haiku-4-5-20251001 | data_flow | 6/18 | 33.3% |
| adapter | claude-haiku-4-5-20251001 | edit_location | 17/30 | 56.7% |
| adapter | claude-haiku-4-5-20251001 | layout_routes | 18/24 | 75.0% |
| adapter | claude-haiku-4-5-20251001 | settings_integrations | 9/12 | 75.0% |
| adapter | claude-sonnet-5 | change_impact | 12/12 | 100.0% |
| adapter | claude-sonnet-5 | content_model | 16/24 | 66.7% |
| adapter | claude-sonnet-5 | data_flow | 14/18 | 77.8% |
| adapter | claude-sonnet-5 | edit_location | 28/30 | 93.3% |
| adapter | claude-sonnet-5 | layout_routes | 23/24 | 95.8% |
| adapter | claude-sonnet-5 | settings_integrations | 12/12 | 100.0% |
| manual | claude-haiku-4-5-20251001 | change_impact | 12/12 | 100.0% |
| manual | claude-haiku-4-5-20251001 | content_model | 24/24 | 100.0% |
| manual | claude-haiku-4-5-20251001 | data_flow | 15/18 | 83.3% |
| manual | claude-haiku-4-5-20251001 | edit_location | 30/30 | 100.0% |
| manual | claude-haiku-4-5-20251001 | layout_routes | 24/24 | 100.0% |
| manual | claude-haiku-4-5-20251001 | settings_integrations | 12/12 | 100.0% |
| manual | claude-sonnet-5 | change_impact | 12/12 | 100.0% |
| manual | claude-sonnet-5 | content_model | 24/24 | 100.0% |
| manual | claude-sonnet-5 | data_flow | 12/18 | 66.7% |
| manual | claude-sonnet-5 | edit_location | 30/30 | 100.0% |
| manual | claude-sonnet-5 | layout_routes | 24/24 | 100.0% |
| manual | claude-sonnet-5 | settings_integrations | 12/12 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | change_impact | 9/12 | 75.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | content_model | 24/24 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | data_flow | 14/18 | 77.8% |
| manual-preloaded | claude-haiku-4-5-20251001 | edit_location | 30/30 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | layout_routes | 24/24 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | settings_integrations | 12/12 | 100.0% |
| manual-preloaded | claude-sonnet-5 | change_impact | 3/4 | 75.0% |
| manual-preloaded | claude-sonnet-5 | content_model | 6/8 | 75.0% |
| manual-preloaded | claude-sonnet-5 | data_flow | 5/6 | 83.3% |
| manual-preloaded | claude-sonnet-5 | edit_location | 10/10 | 100.0% |
| manual-preloaded | claude-sonnet-5 | layout_routes | 8/8 | 100.0% |
| manual-preloaded | claude-sonnet-5 | settings_integrations | 4/4 | 100.0% |
| raw | claude-haiku-4-5-20251001 | change_impact | 3/4 | 75.0% |
| raw | claude-haiku-4-5-20251001 | content_model | 5/8 | 62.5% |
| raw | claude-haiku-4-5-20251001 | data_flow | 2/6 | 33.3% |
| raw | claude-haiku-4-5-20251001 | edit_location | 4/10 | 40.0% |
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
| adapter | claude-haiku-4-5-20251001 | 1 | 22365.5 | 106707 | 22807 | 107788 | 5.5 | 13 | 7.0 |
| adapter | claude-haiku-4-5-20251001 | 2 | 21104 | 121583 | 21613 | 122877 | 5 | 13 | 6.0 |
| adapter | claude-haiku-4-5-20251001 | 3 | 22374.5 | 133739 | 22826 | 134817 | 5 | 13 | 7.5 |
| adapter | claude-sonnet-5 | 1 | 14315 | 145933 | 14590 | 147666 | 3 | 13 | 5.3 |
| adapter | claude-sonnet-5 | 2 | 15123.5 | 175054 | 15367.5 | 177291 | 3 | 15 | 5.2 |
| adapter | claude-sonnet-5 | 3 | 14927.5 | 116196 | 15189.5 | 117871 | 3 | 13 | 5.8 |
| manual | claude-haiku-4-5-20251001 | 1 | 9762.5 | 17381 | 9928 | 17672 | 2 | 4 | 3.0 |
| manual | claude-haiku-4-5-20251001 | 2 | 9792 | 15015 | 9990.5 | 15480 | 2 | 4 | 3.1 |
| manual | claude-haiku-4-5-20251001 | 3 | 9748 | 15015 | 9918.5 | 15460 | 2 | 4 | 2.8 |
| manual | claude-sonnet-5 | 1 | 6244.5 | 15948 | 6331.5 | 16151 | 1 | 3 | 2.4 |
| manual | claude-sonnet-5 | 2 | 6262.5 | 13146 | 6380.5 | 13382 | 1 | 2 | 2.5 |
| manual | claude-sonnet-5 | 3 | 6248.5 | 13182 | 6350 | 13436 | 1 | 3 | 2.4 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 9538 | 15806 | 9662 | 16001 | 1 | 3 | 1.7 |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 9538 | 15806 | 9679 | 16001 | 1 | 3 | 2.1 |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 9541.5 | 15806 | 9711.5 | 16001 | 1 | 2 | 1.9 |
| manual-preloaded | claude-sonnet-5 | 1 | 5588 | 11710 | 5658.5 | 12116 | 0 | 1 | 1.4 |
| raw | claude-haiku-4-5-20251001 | 1 | 16177 | 116445 | 16575.5 | 117558 | 5 | 13 | 6.2 |
| raw | claude-sonnet-5 | 1 | 13806 | 99306 | 14031.5 | 100819 | 3 | 10 | 5.3 |

## Cost

| Condition | Model | Rep | Per question | Total |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | $0.0255 | $1.0183 |
| adapter | claude-haiku-4-5-20251001 | 2 | $0.0177 | $0.7091 |
| adapter | claude-haiku-4-5-20251001 | 3 | $0.0150 | $0.5996 |
| adapter | claude-sonnet-5 | 1 | $0.0550 | $2.1981 |
| adapter | claude-sonnet-5 | 2 | $0.0572 | $2.2889 |
| adapter | claude-sonnet-5 | 3 | $0.0498 | $1.9936 |
| manual | claude-haiku-4-5-20251001 | 1 | $0.0092 | $0.3677 |
| manual | claude-haiku-4-5-20251001 | 2 | $0.0048 | $0.1906 |
| manual | claude-haiku-4-5-20251001 | 3 | $0.0044 | $0.1771 |
| manual | claude-sonnet-5 | 1 | $0.0133 | $0.5303 |
| manual | claude-sonnet-5 | 2 | $0.0132 | $0.5287 |
| manual | claude-sonnet-5 | 3 | $0.0116 | $0.4650 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | $0.0031 | $0.1230 |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | $0.0019 | $0.0780 |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | $0.0019 | $0.0773 |
| manual-preloaded | claude-sonnet-5 | 1 | $0.0044 | $0.1741 |
| raw | claude-haiku-4-5-20251001 | 1 | $0.0224 | $0.8968 |
| raw | claude-sonnet-5 | 1 | $0.0482 | $1.9293 |

Agent total $14.3454. Judge total $0.1232. 620/720 correct.
