# Wayfinder benchmark: fixture-edit-safety, 2026-10-04 (run4)

## Hypotheses

|  | Claim | Result | Numbers |
|---|---|---|---|
| R1 | The manual condition reaches at least 90.0% accuracy on the block fixture, on both models | **fail** | manual: claude-haiku-4-5-20251001 70.0% (42/60), per rep 70.0%, 70.0%, 70.0%; claude-sonnet-5 95.0% (57/60), per rep 95.0%, 95.0%, 95.0% |
| R2 | The manual condition beats adapter by at least 10 percentage points of accuracy, on both models | **fail** | claude-haiku-4-5-20251001 manual 70.0% vs adapter 68.3% (+1.7 points); claude-sonnet-5 manual 95.0% vs adapter 75.0% (+20.0 points) |
| R3 | claude-haiku-4-5-20251001 manual-preloaded matches or beats claude-sonnet-5 adapter on accuracy (mean over repetitions) | **fail** | claude-haiku-4-5-20251001 manual-preloaded 65.0%, 70.0%, 65.0% (mean 66.7%, 3 reps) vs claude-sonnet-5 adapter 80.0%, 70.0%, 75.0% (mean 75.0%, 3 reps) |
| R4 | The brief is at most 600 and the index at most 4,000 estimated tokens | **pass** | brief 1,879 chars = 470 est. tokens; index 8,885 chars = 2,222 est. tokens (chars / 4, rounded up) |
| R5 | Total input tokens across all questions in the manual condition are at least 3x lower than adapter, on both models | **fail** | total input tokens per pass: claude-haiku-4-5-20251001 adapter 406,647 vs manual 434,913 (0.94x); claude-sonnet-5 adapter 280,773 vs manual 294,397 (0.95x) |
| R6 | p90 input tokens per question in the manual condition are at least 3x lower than the adapter p90, on both models | **fail** | p90 input tokens per question: claude-haiku-4-5-20251001 adapter 57,273 vs manual 44,470 (1.29x); claude-sonnet-5 adapter 23,808 vs manual 32,791 (0.73x) |
| R7 | claude-haiku-4-5-20251001 manual-preloaded costs at least 10x less per correct answer than claude-sonnet-5 adapter | **fail** | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0095 vs claude-sonnet-5 adapter $0.0330 (3.45x) |

The spec claims R1 to R7 on both fixtures. This report judges them on this fixture; `reports/run3-summary.md` combines the two.

## Losses

Every comparison on the same model where a baseline cell (raw or adapter) did better than a Wayfinder cell (manual or manual-preloaded): accuracy, accuracy per category, median, p90 and total input tokens, and cost per correct answer, pooled over repetitions. The list is computed by `findLosses` in `src/report.ts`, which was committed before the run, so nothing is chosen after the fact.

| Model | Metric | Baseline | Wayfinder cell |
|---|---|---|---|
| claude-haiku-4-5-20251001 | accuracy, locks | raw 60.0% (3/5) | manual 40.0% (6/15) |
| claude-haiku-4-5-20251001 | median input tokens | raw 9,142 | manual 10,762 |
| claude-haiku-4-5-20251001 | p90 input tokens | raw 27,674 | manual 44,470 |
| claude-haiku-4-5-20251001 | total input tokens per pass | raw 335,339 | manual 434,913 |
| claude-haiku-4-5-20251001 | accuracy, locks | adapter 66.7% (10/15) | manual 40.0% (6/15) |
| claude-haiku-4-5-20251001 | accuracy, stale_edit | adapter 86.7% (13/15) | manual 80.0% (12/15) |
| claude-haiku-4-5-20251001 | total input tokens per pass | adapter 406,647 | manual 434,913 |
| claude-haiku-4-5-20251001 | accuracy, locks | raw 60.0% (3/5) | manual-preloaded 40.0% (6/15) |
| claude-haiku-4-5-20251001 | median input tokens | raw 9,142 | manual-preloaded 15,804 |
| claude-haiku-4-5-20251001 | p90 input tokens | raw 27,674 | manual-preloaded 36,089 |
| claude-haiku-4-5-20251001 | total input tokens per pass | raw 335,339 | manual-preloaded 414,433 |
| claude-haiku-4-5-20251001 | accuracy | adapter 68.3% (41/60) | manual-preloaded 66.7% (40/60) |
| claude-haiku-4-5-20251001 | accuracy, locks | adapter 66.7% (10/15) | manual-preloaded 40.0% (6/15) |
| claude-haiku-4-5-20251001 | median input tokens | adapter 10,766 | manual-preloaded 15,804 |
| claude-haiku-4-5-20251001 | total input tokens per pass | adapter 406,647 | manual-preloaded 414,433 |
| claude-sonnet-5 | median input tokens | raw 5,097 | manual 9,564 |
| claude-sonnet-5 | p90 input tokens | raw 9,427 | manual 32,791 |
| claude-sonnet-5 | total input tokens per pass | raw 172,609 | manual 294,397 |
| claude-sonnet-5 | median input tokens | adapter 5,825 | manual 9,564 |
| claude-sonnet-5 | p90 input tokens | adapter 23,808 | manual 32,791 |
| claude-sonnet-5 | total input tokens per pass | adapter 280,773 | manual 294,397 |
| claude-sonnet-5 | median input tokens | raw 5,097 | manual-preloaded 13,204 |
| claude-sonnet-5 | p90 input tokens | raw 9,427 | manual-preloaded 42,475 |
| claude-sonnet-5 | total input tokens per pass | raw 172,609 | manual-preloaded 415,842 |
| claude-sonnet-5 | accuracy, locks | adapter 66.7% (10/15) | manual-preloaded 60.0% (3/5) |
| claude-sonnet-5 | accuracy, stale_edit | adapter 93.3% (14/15) | manual-preloaded 80.0% (4/5) |
| claude-sonnet-5 | median input tokens | adapter 5,825 | manual-preloaded 13,204 |
| claude-sonnet-5 | p90 input tokens | adapter 23,808 | manual-preloaded 42,475 |
| claude-sonnet-5 | total input tokens per pass | adapter 280,773 | manual-preloaded 415,842 |

## Metrics without a threshold

|  | What | Numbers |
|---|---|---|
| median-input | Median input tokens per question, baseline against manual. Retired as a hypothesis after runs 1 and 2: on easy questions a baseline agent finishes cheaply, and the manual's advantage sits in the long tail, which R6 measures | claude-haiku-4-5-20251001 adapter 10,766 vs manual 10,762 (1.00x); claude-haiku-4-5-20251001 raw 9,142 vs manual 10,762 (0.85x); claude-sonnet-5 adapter 5,825 vs manual 9,564 (0.61x); claude-sonnet-5 raw 5,097 vs manual 9,564 (0.53x) |

## Most-missed questions

Per condition, over every model and repetition, as the graders scored them. The reason is the grader's, from the first miss.

### adapter

| Question | Category | Missed | Reason |
|---|---|---|---|
| mh-01 | multi_home | 6/6 | claude-haiku-4-5-20251001: missing post.php?post=90062&action=edit, post.php?post=90070&action=edit; answered: edit.php?post_type=page&action=edit&post=90061, edit.php?post_type=page&action=edit&post=90060, edit.php?post_type=page… |
| mh-02 | multi_home | 6/6 | claude-haiku-4-5-20251001: missing post.php?post=90070&action=edit; answered: edit.php?post_type=page (then click to edit the "Financing" page with ID 90061) |
| sl-04 | selectors | 6/6 | claude-haiku-4-5-20251001: missing post.php?post=90070&action=edit; answered: wp-admin/edit.php?post_type=wp_block&p=90070 |

### manual

| Question | Category | Missed | Reason |
|---|---|---|---|
| lk-03 | locks | 4/6 | claude-haiku-4-5-20251001: expected {License notice, Plan inclusions, Plan name, Plan price, Refund terms, Renewal terms}; answered: Plan terms, License notice |
| lk-04 | locks | 4/6 | claude-haiku-4-5-20251001: expected "yes"; answered: No |
| lk-05 | locks | 4/6 | claude-haiku-4-5-20251001: The answer correctly states 'no' and identifies that the Editor role lacks the necessary capability, but it incorrectly attributes the restriction to a 'content_only lock' rather than the Editor role simply lacking the 'edit_theme_options' capability required to edit temp… |

### manual-preloaded

| Question | Category | Missed | Reason |
|---|---|---|---|
| lk-03 | locks | 4/4 | claude-haiku-4-5-20251001: expected {License notice, Plan inclusions, Plan name, Plan price, Refund terms, Renewal terms}; answered: Plan card, Plan terms, License notice |
| lk-05 | locks | 4/4 | claude-haiku-4-5-20251001: The answer attributes the restriction to a 'content_only lock' on the footer template part, but the expected ground truth indicates the Editor role lacks the 'edit_theme_options' capability required to edit template parts in the Site Editor, which is the actual reason she… |
| mh-01 | multi_home | 4/4 | claude-haiku-4-5-20251001: missing post.php?post=90062&action=edit; answered: post.php?post=90070&action=edit |

### raw

| Question | Category | Missed | Reason |
|---|---|---|---|
| lk-03 | locks | 2/2 | claude-haiku-4-5-20251001: expected {License notice, Plan inclusions, Plan name, Plan price, Refund terms, Renewal terms}; answered: Plan terms, License notice |
| lk-04 | locks | 2/2 | claude-haiku-4-5-20251001: expected "yes"; answered: No |
| mh-01 | multi_home | 2/2 | claude-haiku-4-5-20251001: missing post.php?post=90062&action=edit, post.php?post=90070&action=edit; answered: edit.php?post_type=page&post=90062, edit.php?post_type=page&post=90061, edit.php?post_type=page&post=90060 |

## Prompt caching (manual-preloaded)

The brief and index sit in a system block marked `cache_control: ephemeral`. The first-call input is everything the first model call read: tools, system prompt, manual and question.

| Model | Minimum cacheable | First-call input median | Cache writes | Cache reads | Runs reading cache | Engaged |
|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | 4,096 | 4,629 | 118,890 | 1,123,482 | 60/60 | yes |
| claude-sonnet-5 | 1,024 | 5,613 | 73,638 | 342,094 | 19/20 | yes |

- claude-haiku-4-5-20251001: cached. 1,123,482 tokens came from the cache at the cache-read rate in 60 of 60 runs, and 118,890 were written at the cache-write rate.
- claude-sonnet-5: cached. 342,094 tokens came from the cache at the cache-read rate in 19 of 20 runs, and 73,638 were written at the cache-write rate.

Costs below are what the API billed: uncached input at the input rate, cache writes at 1.25x and cache reads at 0.1x of it (pricing.json). Prompts were not padded to reach a minimum.

## Conversation caching

Every model call put a cache breakpoint on its latest message (VOY-1129), so each call read the conversation before its newest turn from the cache once the prefix cleared the model's minimum. Input total counts uncached input, cache writes and cache reads; the read share is the part billed at the cache-read rate. Costs in this report are on that basis and are not comparable with runs 1 and 2, which cached only the preloaded manual.

| Condition | Model | Runs | Input total | Uncached | Cache writes | Cache reads | Read share | Runs reading cache |
|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 60 | 1,219,941 | 250,767 | 224,904 | 744,270 | 61.0% | 29/60 |
| adapter | claude-sonnet-5 | 60 | 842,319 | 322 | 373,220 | 468,777 | 55.7% | 60/60 |
| manual | claude-haiku-4-5-20251001 | 60 | 1,304,740 | 280,029 | 162,824 | 861,887 | 66.1% | 32/60 |
| manual | claude-sonnet-5 | 60 | 883,192 | 398 | 309,583 | 573,211 | 64.9% | 60/60 |
| manual-preloaded | claude-haiku-4-5-20251001 | 60 | 1,243,298 | 926 | 118,890 | 1,123,482 | 90.4% | 60/60 |
| manual-preloaded | claude-sonnet-5 | 20 | 415,842 | 110 | 73,638 | 342,094 | 82.3% | 19/20 |
| raw | claude-haiku-4-5-20251001 | 20 | 335,339 | 77,904 | 122,194 | 135,241 | 40.3% | 5/20 |
| raw | claude-sonnet-5 | 20 | 172,609 | 16,894 | 111,996 | 43,719 | 25.3% | 10/20 |

## Run

Run in GitHub Actions (bench.yml) against a fixture site the workflow built and seeded. 6 concurrent question runs, so wall times include queueing at the site. Harness and plugin at commit `1cb750a6d2c79c42fe691329ff6d464631181234`. At most 12 model calls per question. Question set questions/fixture-edit-safety.yaml, 20 questions, sha256 `69eb2c8401ba4ff31666e968dce3cf8ac31c3c5153c4920a70dbadb9fe372cc9`. Pricing from https://platform.claude.com/docs/en/about-claude/pricing, fetched 2026-10-01. Models: claude-haiku-4-5-20251001 {"temperature":0}, claude-sonnet-5 {}.

## Accuracy

| Condition | Model | Rep | Correct | Accuracy |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 14/20 | 70.0% |
| adapter | claude-haiku-4-5-20251001 | 2 | 13/20 | 65.0% |
| adapter | claude-haiku-4-5-20251001 | 3 | 14/20 | 70.0% |
| adapter | claude-sonnet-5 | 1 | 16/20 | 80.0% |
| adapter | claude-sonnet-5 | 2 | 14/20 | 70.0% |
| adapter | claude-sonnet-5 | 3 | 15/20 | 75.0% |
| manual | claude-haiku-4-5-20251001 | 1 | 14/20 | 70.0% |
| manual | claude-haiku-4-5-20251001 | 2 | 14/20 | 70.0% |
| manual | claude-haiku-4-5-20251001 | 3 | 14/20 | 70.0% |
| manual | claude-sonnet-5 | 1 | 19/20 | 95.0% |
| manual | claude-sonnet-5 | 2 | 19/20 | 95.0% |
| manual | claude-sonnet-5 | 3 | 19/20 | 95.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 13/20 | 65.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 14/20 | 70.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 13/20 | 65.0% |
| manual-preloaded | claude-sonnet-5 | 1 | 15/20 | 75.0% |
| raw | claude-haiku-4-5-20251001 | 1 | 13/20 | 65.0% |
| raw | claude-sonnet-5 | 1 | 14/20 | 70.0% |

## Accuracy by category

Pooled over repetitions.

| Condition | Model | Category | Correct | Accuracy |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | locks | 10/15 | 66.7% |
| adapter | claude-haiku-4-5-20251001 | multi_home | 9/15 | 60.0% |
| adapter | claude-haiku-4-5-20251001 | selectors | 9/15 | 60.0% |
| adapter | claude-haiku-4-5-20251001 | stale_edit | 13/15 | 86.7% |
| adapter | claude-sonnet-5 | locks | 10/15 | 66.7% |
| adapter | claude-sonnet-5 | multi_home | 9/15 | 60.0% |
| adapter | claude-sonnet-5 | selectors | 12/15 | 80.0% |
| adapter | claude-sonnet-5 | stale_edit | 14/15 | 93.3% |
| manual | claude-haiku-4-5-20251001 | locks | 6/15 | 40.0% |
| manual | claude-haiku-4-5-20251001 | multi_home | 12/15 | 80.0% |
| manual | claude-haiku-4-5-20251001 | selectors | 12/15 | 80.0% |
| manual | claude-haiku-4-5-20251001 | stale_edit | 12/15 | 80.0% |
| manual | claude-sonnet-5 | locks | 12/15 | 80.0% |
| manual | claude-sonnet-5 | multi_home | 15/15 | 100.0% |
| manual | claude-sonnet-5 | selectors | 15/15 | 100.0% |
| manual | claude-sonnet-5 | stale_edit | 15/15 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | locks | 6/15 | 40.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | multi_home | 10/15 | 66.7% |
| manual-preloaded | claude-haiku-4-5-20251001 | selectors | 9/15 | 60.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | stale_edit | 15/15 | 100.0% |
| manual-preloaded | claude-sonnet-5 | locks | 3/5 | 60.0% |
| manual-preloaded | claude-sonnet-5 | multi_home | 4/5 | 80.0% |
| manual-preloaded | claude-sonnet-5 | selectors | 4/5 | 80.0% |
| manual-preloaded | claude-sonnet-5 | stale_edit | 4/5 | 80.0% |
| raw | claude-haiku-4-5-20251001 | locks | 3/5 | 60.0% |
| raw | claude-haiku-4-5-20251001 | multi_home | 3/5 | 60.0% |
| raw | claude-haiku-4-5-20251001 | selectors | 3/5 | 60.0% |
| raw | claude-haiku-4-5-20251001 | stale_edit | 4/5 | 80.0% |
| raw | claude-sonnet-5 | locks | 3/5 | 60.0% |
| raw | claude-sonnet-5 | multi_home | 3/5 | 60.0% |
| raw | claude-sonnet-5 | selectors | 4/5 | 80.0% |
| raw | claude-sonnet-5 | stale_edit | 4/5 | 80.0% |

## Tokens and tool calls per question

Input counts uncached input, cache writes and cache reads together.

| Condition | Model | Rep | Input median | Input p90 | Total median | Total p90 | Tool calls median | Tool calls p90 | Wall median (s) |
|---|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 11192 | 57273 | 11540 | 58279 | 3 | 10 | 4.9 |
| adapter | claude-haiku-4-5-20251001 | 2 | 11212 | 24321 | 11620.5 | 24805 | 3 | 8 | 4.5 |
| adapter | claude-haiku-4-5-20251001 | 3 | 10763.5 | 24321 | 11001.5 | 24805 | 2.5 | 8 | 4.2 |
| adapter | claude-sonnet-5 | 1 | 6044 | 24767 | 6705.5 | 25292 | 2 | 5 | 6.0 |
| adapter | claude-sonnet-5 | 2 | 5651 | 23649 | 6330 | 24383 | 2 | 5 | 4.7 |
| adapter | claude-sonnet-5 | 3 | 5833.5 | 22883 | 6407.5 | 23811 | 2 | 4 | 5.3 |
| manual | claude-haiku-4-5-20251001 | 1 | 10762 | 44470 | 11245.5 | 45187 | 3.5 | 9 | 4.9 |
| manual | claude-haiku-4-5-20251001 | 2 | 10762 | 40318 | 11245.5 | 40946 | 3.5 | 9 | 4.8 |
| manual | claude-haiku-4-5-20251001 | 3 | 10762 | 40334 | 11245.5 | 40969 | 3.5 | 9 | 4.9 |
| manual | claude-sonnet-5 | 1 | 8018 | 37017 | 8520 | 37704 | 2.5 | 7 | 6.2 |
| manual | claude-sonnet-5 | 2 | 10901 | 32791 | 11362.5 | 35737 | 2.5 | 6 | 6.1 |
| manual | claude-sonnet-5 | 3 | 9328.5 | 26476 | 9672 | 27719 | 2 | 5 | 5.0 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 13332 | 35779 | 13586 | 36426 | 2.5 | 6 | 4.1 |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 16896.5 | 35786 | 17205.5 | 36351 | 2.5 | 6 | 4.3 |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 13332 | 36089 | 13589 | 36890 | 2.5 | 7 | 3.9 |
| manual-preloaded | claude-sonnet-5 | 1 | 13204 | 42475 | 13426.5 | 43539 | 1.5 | 6 | 3.8 |
| raw | claude-haiku-4-5-20251001 | 1 | 9142 | 27674 | 9637.5 | 28062 | 3 | 8 | 5.3 |
| raw | claude-sonnet-5 | 1 | 5097 | 9427 | 5759 | 10521 | 2 | 4 | 4.9 |

## Cost

| Condition | Model | Rep | Per question | Total |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | $0.0183 | $0.3650 |
| adapter | claude-haiku-4-5-20251001 | 2 | $0.0104 | $0.2082 |
| adapter | claude-haiku-4-5-20251001 | 3 | $0.0087 | $0.1747 |
| adapter | claude-sonnet-5 | 1 | $0.0306 | $0.6130 |
| adapter | claude-sonnet-5 | 2 | $0.0230 | $0.4595 |
| adapter | claude-sonnet-5 | 3 | $0.0205 | $0.4106 |
| manual | claude-haiku-4-5-20251001 | 1 | $0.0165 | $0.3299 |
| manual | claude-haiku-4-5-20251001 | 2 | $0.0097 | $0.1946 |
| manual | claude-haiku-4-5-20251001 | 3 | $0.0095 | $0.1901 |
| manual | claude-sonnet-5 | 1 | $0.0251 | $0.5011 |
| manual | claude-sonnet-5 | 2 | $0.0206 | $0.4126 |
| manual | claude-sonnet-5 | 3 | $0.0184 | $0.3675 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | $0.0089 | $0.1777 |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | $0.0046 | $0.0915 |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | $0.0056 | $0.1125 |
| manual-preloaded | claude-sonnet-5 | 1 | $0.0206 | $0.4122 |
| raw | claude-haiku-4-5-20251001 | 1 | $0.0144 | $0.2882 |
| raw | claude-sonnet-5 | 1 | $0.0213 | $0.4257 |

Agent total $5.7346. Judge total $0.0180. 267/360 correct.
