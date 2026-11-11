# Wayfinder benchmark: fixture, 2026-10-02

## Hypotheses

|  | Claim | Result | Numbers |
|---|---|---|---|
| H1 | The manual condition reaches 90.0% accuracy or better, on every model | **fail** | manual: claude-haiku-4-5-20251001 87.5% (35/40); claude-sonnet-5 87.5% (35/40). For reference, manual-preloaded: claude-haiku-4-5-20251001 88.3% (106/120); claude-sonnet-5 85.0% (34/40) |
| H2 | The manual condition reads at least 5x fewer input tokens per question than raw (median), on every model | **fail** | median input tokens per question: claude-haiku-4-5-20251001 raw 15,361 vs manual 9,205 (1.67x); claude-sonnet-5 raw 13,620 vs manual 5,879 (2.32x). For reference: claude-haiku-4-5-20251001 raw 15,361 vs manual-preloaded 8,824 (1.74x); claude-sonnet-5 raw 13,620 vs manual-preloaded 5,085 (2.68x) |
| H2b | Across all questions, total input tokens in the manual condition are at least 3x lower than raw, on every model | **pass** | total input tokens per pass: claude-haiku-4-5-20251001 raw 1,792,655 vs manual 478,798 (3.74x); claude-sonnet-5 raw 1,747,301 vs manual 448,843 (3.89x) |
| H3 | claude-haiku-4-5-20251001 manual-preloaded matches or beats claude-sonnet-5 raw on accuracy (mean over repetitions) | **pass** | claude-haiku-4-5-20251001 manual-preloaded 90.0%, 87.5%, 87.5% (mean 88.3%, 3 reps) vs claude-sonnet-5 raw 77.5%, 77.5%, 82.5% (mean 79.2%, 3 reps) |
| H4 | On the fixture the brief is at most 600 estimated tokens and the index at most 4,000 | **pass** | brief 1,649 chars = 413 est. tokens; index 7,996 chars = 1,999 est. tokens (chars / 4, rounded up) |
| H5 | p90 input tokens per question in the manual condition are at least 3x lower than the raw p90, on every model | **pass** | p90 input tokens per question: claude-haiku-4-5-20251001 raw 109,841 vs manual 23,022 (4.77x); claude-sonnet-5 raw 128,960 vs manual 16,261 (7.93x) |
| H6 | claude-haiku-4-5-20251001 manual-preloaded costs at least 5x less per correct answer than claude-sonnet-5 raw | **pass** | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0132 vs claude-sonnet-5 raw $0.1215 (9.19x) |

## Most-missed questions

Per condition, over every model and repetition, as the graders scored them. The reason is the grader's, from the first miss.

### manual

| Question | Category | Missed | Reason |
|---|---|---|---|
| df-01 | data_flow | 2/2 | claude-haiku-4-5-20251001: The answer correctly identifies the binding source (vwf-fixture/business-info) and key (phone), but states the settings page is at options-general.php?page=vwf-business-info when it should be Settings > Business Info, and does not explicitly confirm the binding reads the … |
| df-02 | data_flow | 2/2 | claude-haiku-4-5-20251001: expected "vwf_address"; answered: vwf-fixture/business-info.address (or the underlying option key: address, accessed via the Business info settings page) |
| df-04 | data_flow | 2/2 | claude-haiku-4-5-20251001: missing vwf-fixture-core.php; answered: functions.php (in the vwf-fixture child theme) |

### manual-preloaded

| Question | Category | Missed | Reason |
|---|---|---|---|
| df-02 | data_flow | 4/4 | claude-haiku-4-5-20251001: expected "vwf_address"; answered: vwf-fixture/business-info (address key) |
| df-04 | data_flow | 4/4 | claude-haiku-4-5-20251001: missing vwf-fixture-core.php; answered: functions.php (in the vwf-fixture theme directory) |
| df-05 | data_flow | 4/4 | claude-haiku-4-5-20251001: The answer states there are only 2 business info values set up, but the expected data shows 3 values exist (address, hours, and phone), with 'hours' being the one set up but not used anywhere on the front end.; answered: Based on the Wayfinder index, there are only 2 busi… |

### raw

| Question | Category | Missed | Reason |
|---|---|---|---|
| cm-02 | content_model | 4/4 | claude-haiku-4-5-20251001: expected {duration, internal_sku, price_from}; answered: id, date, date_gmt, guid, modified, modified_gmt, slug, status, type, link, title, content, excerpt, featured_media, te… |
| cm-05 | content_model | 4/4 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| df-02 | data_flow | 4/4 | claude-haiku-4-5-20251001: expected "vwf_address"; answered: The business address is stored via a custom block binding source called `vwf-fixture/business-info` with the key `addre… |

## Prompt caching (manual-preloaded)

The brief and index sit in a system block marked `cache_control: ephemeral`. The first-call input is everything the first model call read: tools, system prompt, manual and question.

| Model | Minimum cacheable | First-call input median | Cache writes | Cache reads | Runs reading cache | Engaged |
|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | 4,096 | 4,256 | 0 | 0 | 0/120 | no |
| claude-sonnet-5 | 1,024 | 5,081 | 9,980 | 349,300 | 38/40 | yes |

- claude-haiku-4-5-20251001: not cached. Nothing was read from the cache, although the first call read 4,256 tokens (median) against a 4,096-token minimum. Every call paid the full input rate for the manual, so its manual-preloaded cost is not a cached cost.
- claude-sonnet-5: cached. 349,300 tokens came from the cache at the cache-read rate in 38 of 40 runs, and 9,980 were written at the cache-write rate.

Costs below are what the API billed: uncached input at the input rate, cache writes at 1.25x and cache reads at 0.1x of it (pricing.json). Prompts were not padded to reach a minimum.

## Run

Run locally against a freshly seeded wp-env fixture site. 6 concurrent question runs, so wall times include queueing at the site. Question set questions/fixture.yaml, 40 questions, sha256 `e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b`. Pricing from https://platform.claude.com/docs/en/about-claude/pricing, fetched 2026-10-01. Models: claude-haiku-4-5-20251001 {"temperature":0}, claude-sonnet-5 {}.

## Accuracy

| Condition | Model | Rep | Correct | Accuracy |
|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 1 | 35/40 | 87.5% |
| manual | claude-sonnet-5 | 1 | 35/40 | 87.5% |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 36/40 | 90.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 35/40 | 87.5% |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 35/40 | 87.5% |
| manual-preloaded | claude-sonnet-5 | 1 | 34/40 | 85.0% |
| raw | claude-haiku-4-5-20251001 | 1 | 25/40 | 62.5% |
| raw | claude-sonnet-5 | 1 | 31/40 | 77.5% |
| raw | claude-sonnet-5 | 2 | 31/40 | 77.5% |
| raw | claude-sonnet-5 | 3 | 33/40 | 82.5% |

## Accuracy by category

Pooled over repetitions.

| Condition | Model | Category | Correct | Accuracy |
|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | change_impact | 4/4 | 100.0% |
| manual | claude-haiku-4-5-20251001 | content_model | 8/8 | 100.0% |
| manual | claude-haiku-4-5-20251001 | data_flow | 2/6 | 33.3% |
| manual | claude-haiku-4-5-20251001 | edit_location | 9/10 | 90.0% |
| manual | claude-haiku-4-5-20251001 | layout_routes | 8/8 | 100.0% |
| manual | claude-haiku-4-5-20251001 | settings_integrations | 4/4 | 100.0% |
| manual | claude-sonnet-5 | change_impact | 4/4 | 100.0% |
| manual | claude-sonnet-5 | content_model | 8/8 | 100.0% |
| manual | claude-sonnet-5 | data_flow | 2/6 | 33.3% |
| manual | claude-sonnet-5 | edit_location | 9/10 | 90.0% |
| manual | claude-sonnet-5 | layout_routes | 8/8 | 100.0% |
| manual | claude-sonnet-5 | settings_integrations | 4/4 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | change_impact | 10/12 | 83.3% |
| manual-preloaded | claude-haiku-4-5-20251001 | content_model | 24/24 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | data_flow | 9/18 | 50.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | edit_location | 27/30 | 90.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | layout_routes | 24/24 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | settings_integrations | 12/12 | 100.0% |
| manual-preloaded | claude-sonnet-5 | change_impact | 4/4 | 100.0% |
| manual-preloaded | claude-sonnet-5 | content_model | 7/8 | 87.5% |
| manual-preloaded | claude-sonnet-5 | data_flow | 2/6 | 33.3% |
| manual-preloaded | claude-sonnet-5 | edit_location | 9/10 | 90.0% |
| manual-preloaded | claude-sonnet-5 | layout_routes | 8/8 | 100.0% |
| manual-preloaded | claude-sonnet-5 | settings_integrations | 4/4 | 100.0% |
| raw | claude-haiku-4-5-20251001 | change_impact | 3/4 | 75.0% |
| raw | claude-haiku-4-5-20251001 | content_model | 5/8 | 62.5% |
| raw | claude-haiku-4-5-20251001 | data_flow | 2/6 | 33.3% |
| raw | claude-haiku-4-5-20251001 | edit_location | 5/10 | 50.0% |
| raw | claude-haiku-4-5-20251001 | layout_routes | 6/8 | 75.0% |
| raw | claude-haiku-4-5-20251001 | settings_integrations | 4/4 | 100.0% |
| raw | claude-sonnet-5 | change_impact | 12/12 | 100.0% |
| raw | claude-sonnet-5 | content_model | 17/24 | 70.8% |
| raw | claude-sonnet-5 | data_flow | 7/18 | 38.9% |
| raw | claude-sonnet-5 | edit_location | 26/30 | 86.7% |
| raw | claude-sonnet-5 | layout_routes | 22/24 | 91.7% |
| raw | claude-sonnet-5 | settings_integrations | 11/12 | 91.7% |

## Tokens and tool calls per question

Input counts uncached input, cache writes and cache reads together.

| Condition | Model | Rep | Input median | Input p90 | Total median | Total p90 | Tool calls median | Tool calls p90 | Wall median (s) |
|---|---|---|---|---|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 1 | 9204.5 | 23022 | 9375.5 | 23389 | 2 | 4 | 4.8 |
| manual | claude-sonnet-5 | 1 | 5878.5 | 16261 | 6059.5 | 16502 | 2 | 3 | 3.6 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 8823.5 | 13574 | 8986.5 | 13969 | 1 | 2 | 3.0 |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 8823.5 | 13550 | 8996 | 13969 | 1 | 2 | 3.6 |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 8823.5 | 13569 | 8995.5 | 13969 | 1 | 2 | 3.2 |
| manual-preloaded | claude-sonnet-5 | 1 | 5085 | 16841 | 5172 | 17050 | 0 | 2 | 1.9 |
| raw | claude-haiku-4-5-20251001 | 1 | 15360.5 | 109841 | 15707 | 110831 | 4 | 14 | 7.2 |
| raw | claude-sonnet-5 | 1 | 13273.5 | 144678 | 13473 | 147217 | 3 | 11 | 5.9 |
| raw | claude-sonnet-5 | 2 | 13317 | 126181 | 13615 | 129250 | 3 | 13 | 5.8 |
| raw | claude-sonnet-5 | 3 | 13645.5 | 128960 | 13897.5 | 130652 | 3 | 13 | 6.2 |

## Cost

| Condition | Model | Rep | Per question | Total |
|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 1 | $0.0133 | $0.5315 |
| manual | claude-sonnet-5 | 1 | $0.0252 | $1.0077 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | $0.0121 | $0.4849 |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | $0.0117 | $0.4667 |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | $0.0113 | $0.4503 |
| manual-preloaded | claude-sonnet-5 | 1 | $0.0134 | $0.5353 |
| raw | claude-haiku-4-5-20251001 | 1 | $0.0479 | $1.9143 |
| raw | claude-sonnet-5 | 1 | $0.0917 | $3.6664 |
| raw | claude-sonnet-5 | 2 | $0.0992 | $3.9670 |
| raw | claude-sonnet-5 | 3 | $0.0978 | $3.9118 |

Agent total $16.9359. Judge total $0.0748. 330/400 correct.
