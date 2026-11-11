# Wayfinder benchmark: fixture-edit-safety-2, 2026-10-06 (run5)

## Hypotheses

|  | Claim | Result | Numbers |
|---|---|---|---|
| R1 | The manual condition reaches at least 90.0% accuracy on the block fixture, on both models | **fail** | manual: claude-haiku-4-5-20251001 84.4% (76/90), per rep 86.7%, 83.3%, 83.3%; claude-sonnet-5 90.0% (81/90), per rep 93.3%, 90.0%, 86.7% |
| R2 | The manual condition beats adapter by at least 10 percentage points of accuracy, on both models | **fail** | claude-haiku-4-5-20251001 manual 84.4% vs adapter 57.8% (+26.7 points); claude-sonnet-5 manual 90.0% vs adapter 85.6% (+4.4 points) |
| R3 | claude-haiku-4-5-20251001 manual-preloaded matches or beats claude-sonnet-5 adapter on accuracy (mean over repetitions) | **pass** | claude-haiku-4-5-20251001 manual-preloaded 93.3%, 90.0%, 90.0% (mean 91.1%, 3 reps) vs claude-sonnet-5 adapter 93.3%, 86.7%, 76.7% (mean 85.6%, 3 reps) |
| R4 | The brief is at most 600 and the index at most 4,000 estimated tokens | **pass** | brief 2,187 chars = 547 est. tokens; index 9,974 chars = 2,494 est. tokens (chars / 4, rounded up) |
| R5 | Total input tokens across all questions in the manual condition are at least 3x lower than adapter, on both models | **fail** | total input tokens per pass: claude-haiku-4-5-20251001 adapter 765,060 vs manual 312,408 (2.45x); claude-sonnet-5 adapter 578,490 vs manual 340,399 (1.70x) |
| R6 | p90 input tokens per question in the manual condition are at least 3x lower than the adapter p90, on both models | **fail** | p90 input tokens per question: claude-haiku-4-5-20251001 adapter 65,388 vs manual 22,177 (2.95x); claude-sonnet-5 adapter 45,360 vs manual 30,569 (1.48x) |
| R7 | claude-haiku-4-5-20251001 manual-preloaded costs at least 10x less per correct answer than claude-sonnet-5 adapter | **fail** | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0058 vs claude-sonnet-5 adapter $0.0349 (6.01x) |

The spec claims R1 to R7 on both fixtures. This report judges them on this fixture; `reports/run3-summary.md` combines the two.

## Intervals

Every figure below has a 95% interval from resampling questions with replacement: 10,000 resamples from seed 1215, so the numbers are the same on every run, with the 250th and 9,750th sorted values as bounds. A question counts once, scored as the share of its repetitions answered correctly, because the repetitions of one question are not independent samples. Each comparison pairs its two cells by question. McNemar's exact test pairs repetition k of one cell with repetition k of the other, over the repetitions both ran; it treats those pairs as independent, so its p is optimistic and the interval is the figure to read. "Read by" names the rules that read a figure against the named baseline; the best-baseline restatement reads whichever baseline row it names. Computed by `src/intervals.ts` from the graders' verdicts in this report, with no API calls. Hand re-grades in a `corrected` block are not resampled.

### Cell accuracy

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

### Paired accuracy

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

### Paired cost per correct answer

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

## Losses

Every comparison on the same model where a baseline cell (raw, adapter or cli) did better than a Wayfinder cell (manual, manual-preloaded or cli-manual): accuracy, accuracy per category, median, p90 and total input tokens, and cost per correct answer, pooled over repetitions. The list is computed by `findLosses` in `src/report.ts`, which was committed before the run, so nothing is chosen after the fact.

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

## Metrics without a threshold

|  | What | Numbers |
|---|---|---|
| median-input | Median input tokens per question, baseline against manual. Retired as a hypothesis after runs 1 and 2: on easy questions a baseline agent finishes cheaply, and the manual's advantage sits in the long tail, which R6 measures | claude-haiku-4-5-20251001 adapter 12,325 vs manual 7,276 (1.69x); claude-haiku-4-5-20251001 raw 8,733 vs manual 7,276 (1.20x); claude-haiku-4-5-20251001 cli 13,016 vs manual 7,276 (1.79x); claude-sonnet-5 adapter 6,568 vs manual 5,581 (1.18x); claude-sonnet-5 raw 4,365 vs manual 5,581 (0.78x); claude-sonnet-5 cli 8,679 vs manual 5,581 (1.56x) |

## Most-missed questions

Per condition, over every model and repetition, as the graders scored them. The reason is the grader's, from the first miss.

### adapter

| Question | Category | Missed | Reason |
|---|---|---|---|
| mh-24 | multi_home | 6/6 | claude-haiku-4-5-20251001: missing post.php?post=90071&action=edit; answered: edit.php?post_type=page (then edit page ID 90080, or search for "Pricing") |
| mh-26 | multi_home | 5/6 | claude-haiku-4-5-20251001: missing post.php?post=90071&action=edit, post.php?post=90082&action=edit; answered: edit.php?post_type=page&action=edit&post=90082, edit.php?post_type=page&action=edit&post=90081, edit.php?post_type=page… |
| lk-21 | locks | 4/6 | claude-haiku-4-5-20251001: expected "no"; answered: Yes |

### cli

| Question | Category | Missed | Reason |
|---|---|---|---|
| lk-25 | locks | 6/6 | claude-haiku-4-5-20251001: expected "no"; answered: no ANSWER line |
| mh-24 | multi_home | 6/6 | claude-haiku-4-5-20251001: missing post.php?post=90071&action=edit; answered: edit.php?post_type=wp_block&post=90071 |
| mh-26 | multi_home | 4/6 | claude-haiku-4-5-20251001: missing post.php?post=90071&action=edit, post.php?post=90082&action=edit; answered: edit.php?post_type=page&action=edit&post=90082, edit.php?post_type=page&action=edit&post=90081, edit.php?post_type=page… |

### cli-manual

| Question | Category | Missed | Reason |
|---|---|---|---|
| lk-26 | locks | 6/6 | claude-haiku-4-5-20251001: expected "yes"; answered: No |
| lk-24 | locks | 3/6 | claude-sonnet-5: expected "yes"; answered: No |
| mh-22 | multi_home | 3/6 | claude-haiku-4-5-20251001: expected {/about/, /guarantee/, /maintenance-plan/}; answered: /guarantee/, /maintenance-plan/ |

### manual

| Question | Category | Missed | Reason |
|---|---|---|---|
| lk-21 | locks | 5/6 | claude-haiku-4-5-20251001: expected "no"; answered: Yes |
| lk-26 | locks | 5/6 | claude-haiku-4-5-20251001: expected "yes"; answered: No |
| lk-23 | locks | 3/6 | claude-haiku-4-5-20251001: expected {Call-out fee, Labor rate, Price promise}; answered: Call-out fee, Labor rate, Night rate, Price promise |

### manual-preloaded

| Question | Category | Missed | Reason |
|---|---|---|---|
| lk-26 | locks | 6/6 | claude-haiku-4-5-20251001: expected "yes"; answered: No |
| lk-22 | locks | 4/6 | claude-haiku-4-5-20251001: expected "yes"; answered: No |
| lk-24 | locks | 3/6 | claude-sonnet-5: expected "yes"; answered: no |

### raw

| Question | Category | Missed | Reason |
|---|---|---|---|
| lk-25 | locks | 6/6 | claude-haiku-4-5-20251001: expected "no"; answered: Yes |
| mh-24 | multi_home | 6/6 | claude-haiku-4-5-20251001: missing post.php?post=90071&action=edit; answered: edit.php?post_type=page (then edit the page with ID 90080, or search for "Pricing") |
| lk-21 | locks | 5/6 | claude-haiku-4-5-20251001: expected "no"; answered: Yes |

## Prompt caching (manual-preloaded)

The brief and index sit in a system block marked `cache_control: ephemeral`. The first-call input is everything the first model call read: tools, system prompt, manual and question.

| Model | Minimum cacheable | First-call input median | Cache writes | Cache reads | Runs reading cache | Engaged |
|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | 4,096 | 5,067 | 112,821 | 1,683,117 | 90/90 | yes |
| claude-sonnet-5 | 1,024 | 6,233 | 227,028 | 1,445,798 | 90/90 | yes |

- claude-haiku-4-5-20251001: cached. 1,683,117 tokens came from the cache at the cache-read rate in 90 of 90 runs, and 112,821 were written at the cache-write rate.
- claude-sonnet-5: cached. 1,445,798 tokens came from the cache at the cache-read rate in 90 of 90 runs, and 227,028 were written at the cache-write rate.

Costs below are what the API billed: uncached input at the input rate, cache writes at 1.25x and cache reads at 0.1x of it (pricing.json). Prompts were not padded to reach a minimum.

## Conversation caching

Every model call put a cache breakpoint on its latest message (VOY-1129), so each call read the conversation before its newest turn from the cache once the prefix cleared the model's minimum. Input total counts uncached input, cache writes and cache reads; the read share is the part billed at the cache-read rate. Costs in this report are on that basis and are not comparable with runs 1 and 2, which cached only the preloaded manual.

| Condition | Model | Runs | Input total | Uncached | Cache writes | Cache reads | Read share | Runs reading cache |
|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 90 | 2,295,181 | 520,368 | 246,067 | 1,528,746 | 66.6% | 39/90 |
| adapter | claude-sonnet-5 | 90 | 1,735,470 | 554 | 629,867 | 1,105,049 | 63.7% | 89/90 |
| cli | claude-haiku-4-5-20251001 | 90 | 2,016,319 | 613,789 | 233,504 | 1,169,026 | 58.0% | 40/90 |
| cli | claude-sonnet-5 | 90 | 2,675,963 | 914 | 632,668 | 2,042,381 | 76.3% | 90/90 |
| cli-manual | claude-haiku-4-5-20251001 | 90 | 1,778,373 | 489,469 | 133,829 | 1,155,075 | 65.0% | 34/90 |
| cli-manual | claude-sonnet-5 | 90 | 1,497,707 | 606 | 409,071 | 1,088,030 | 72.6% | 90/90 |
| manual | claude-haiku-4-5-20251001 | 90 | 937,223 | 385,981 | 93,086 | 458,156 | 48.9% | 30/90 |
| manual | claude-sonnet-5 | 90 | 1,021,196 | 512 | 370,782 | 649,902 | 63.6% | 90/90 |
| manual-preloaded | claude-haiku-4-5-20251001 | 90 | 1,797,310 | 1,372 | 112,821 | 1,683,117 | 93.6% | 90/90 |
| manual-preloaded | claude-sonnet-5 | 90 | 1,673,260 | 434 | 227,028 | 1,445,798 | 86.4% | 90/90 |
| raw | claude-haiku-4-5-20251001 | 90 | 3,032,517 | 347,648 | 173,785 | 2,511,084 | 82.8% | 36/90 |
| raw | claude-sonnet-5 | 90 | 1,273,851 | 78,240 | 575,113 | 620,498 | 48.7% | 38/90 |

## Run

Run in GitHub Actions (bench.yml) against a fixture site the workflow built and seeded. 6 concurrent question runs, so wall times include queueing at the site. Harness and plugin at commit `826cce2a66d29d1098b2b0c05ad0f6efa763641d`. At most 24 model calls per question. Question set questions/fixture-edit-safety-2.yaml, 30 questions, sha256 `79b1b58aef25744644e6dfc104550afb654338bd89897963d14f1a5aec73bcb7`. Pricing from https://platform.claude.com/docs/en/about-claude/pricing, fetched 2026-10-01. Models: claude-haiku-4-5-20251001 {"temperature":0}, claude-sonnet-5 {}.

## Accuracy

| Condition | Model | Rep | Correct | Accuracy |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 17/30 | 56.7% |
| adapter | claude-haiku-4-5-20251001 | 2 | 18/30 | 60.0% |
| adapter | claude-haiku-4-5-20251001 | 3 | 17/30 | 56.7% |
| adapter | claude-sonnet-5 | 1 | 28/30 | 93.3% |
| adapter | claude-sonnet-5 | 2 | 26/30 | 86.7% |
| adapter | claude-sonnet-5 | 3 | 23/30 | 76.7% |
| cli | claude-haiku-4-5-20251001 | 1 | 18/30 | 60.0% |
| cli | claude-haiku-4-5-20251001 | 2 | 17/30 | 56.7% |
| cli | claude-haiku-4-5-20251001 | 3 | 17/30 | 56.7% |
| cli | claude-sonnet-5 | 1 | 26/30 | 86.7% |
| cli | claude-sonnet-5 | 2 | 25/30 | 83.3% |
| cli | claude-sonnet-5 | 3 | 25/30 | 83.3% |
| cli-manual | claude-haiku-4-5-20251001 | 1 | 28/30 | 93.3% |
| cli-manual | claude-haiku-4-5-20251001 | 2 | 28/30 | 93.3% |
| cli-manual | claude-haiku-4-5-20251001 | 3 | 28/30 | 93.3% |
| cli-manual | claude-sonnet-5 | 1 | 27/30 | 90.0% |
| cli-manual | claude-sonnet-5 | 2 | 27/30 | 90.0% |
| cli-manual | claude-sonnet-5 | 3 | 26/30 | 86.7% |
| manual | claude-haiku-4-5-20251001 | 1 | 26/30 | 86.7% |
| manual | claude-haiku-4-5-20251001 | 2 | 25/30 | 83.3% |
| manual | claude-haiku-4-5-20251001 | 3 | 25/30 | 83.3% |
| manual | claude-sonnet-5 | 1 | 28/30 | 93.3% |
| manual | claude-sonnet-5 | 2 | 27/30 | 90.0% |
| manual | claude-sonnet-5 | 3 | 26/30 | 86.7% |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 28/30 | 93.3% |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 27/30 | 90.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 27/30 | 90.0% |
| manual-preloaded | claude-sonnet-5 | 1 | 25/30 | 83.3% |
| manual-preloaded | claude-sonnet-5 | 2 | 28/30 | 93.3% |
| manual-preloaded | claude-sonnet-5 | 3 | 27/30 | 90.0% |
| raw | claude-haiku-4-5-20251001 | 1 | 16/30 | 53.3% |
| raw | claude-haiku-4-5-20251001 | 2 | 17/30 | 56.7% |
| raw | claude-haiku-4-5-20251001 | 3 | 16/30 | 53.3% |
| raw | claude-sonnet-5 | 1 | 22/30 | 73.3% |
| raw | claude-sonnet-5 | 2 | 23/30 | 76.7% |
| raw | claude-sonnet-5 | 3 | 25/30 | 83.3% |

## Accuracy by category

Pooled over repetitions.

| Condition | Model | Category | Correct | Accuracy |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | client_can | 16/18 | 88.9% |
| adapter | claude-haiku-4-5-20251001 | locks | 3/18 | 16.7% |
| adapter | claude-haiku-4-5-20251001 | multi_home | 6/18 | 33.3% |
| adapter | claude-haiku-4-5-20251001 | selectors | 12/18 | 66.7% |
| adapter | claude-haiku-4-5-20251001 | stale_edit | 15/18 | 83.3% |
| adapter | claude-sonnet-5 | client_can | 17/18 | 94.4% |
| adapter | claude-sonnet-5 | locks | 16/18 | 88.9% |
| adapter | claude-sonnet-5 | multi_home | 13/18 | 72.2% |
| adapter | claude-sonnet-5 | selectors | 15/18 | 83.3% |
| adapter | claude-sonnet-5 | stale_edit | 16/18 | 88.9% |
| cli | claude-haiku-4-5-20251001 | client_can | 13/18 | 72.2% |
| cli | claude-haiku-4-5-20251001 | locks | 3/18 | 16.7% |
| cli | claude-haiku-4-5-20251001 | multi_home | 6/18 | 33.3% |
| cli | claude-haiku-4-5-20251001 | selectors | 15/18 | 83.3% |
| cli | claude-haiku-4-5-20251001 | stale_edit | 15/18 | 83.3% |
| cli | claude-sonnet-5 | client_can | 18/18 | 100.0% |
| cli | claude-sonnet-5 | locks | 15/18 | 83.3% |
| cli | claude-sonnet-5 | multi_home | 14/18 | 77.8% |
| cli | claude-sonnet-5 | selectors | 13/18 | 72.2% |
| cli | claude-sonnet-5 | stale_edit | 16/18 | 88.9% |
| cli-manual | claude-haiku-4-5-20251001 | client_can | 18/18 | 100.0% |
| cli-manual | claude-haiku-4-5-20251001 | locks | 15/18 | 83.3% |
| cli-manual | claude-haiku-4-5-20251001 | multi_home | 15/18 | 83.3% |
| cli-manual | claude-haiku-4-5-20251001 | selectors | 18/18 | 100.0% |
| cli-manual | claude-haiku-4-5-20251001 | stale_edit | 18/18 | 100.0% |
| cli-manual | claude-sonnet-5 | client_can | 18/18 | 100.0% |
| cli-manual | claude-sonnet-5 | locks | 10/18 | 55.6% |
| cli-manual | claude-sonnet-5 | multi_home | 18/18 | 100.0% |
| cli-manual | claude-sonnet-5 | selectors | 18/18 | 100.0% |
| cli-manual | claude-sonnet-5 | stale_edit | 16/18 | 88.9% |
| manual | claude-haiku-4-5-20251001 | client_can | 18/18 | 100.0% |
| manual | claude-haiku-4-5-20251001 | locks | 9/18 | 50.0% |
| manual | claude-haiku-4-5-20251001 | multi_home | 15/18 | 83.3% |
| manual | claude-haiku-4-5-20251001 | selectors | 16/18 | 88.9% |
| manual | claude-haiku-4-5-20251001 | stale_edit | 18/18 | 100.0% |
| manual | claude-sonnet-5 | client_can | 18/18 | 100.0% |
| manual | claude-sonnet-5 | locks | 11/18 | 61.1% |
| manual | claude-sonnet-5 | multi_home | 18/18 | 100.0% |
| manual | claude-sonnet-5 | selectors | 18/18 | 100.0% |
| manual | claude-sonnet-5 | stale_edit | 16/18 | 88.9% |
| manual-preloaded | claude-haiku-4-5-20251001 | client_can | 18/18 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | locks | 12/18 | 66.7% |
| manual-preloaded | claude-haiku-4-5-20251001 | multi_home | 16/18 | 88.9% |
| manual-preloaded | claude-haiku-4-5-20251001 | selectors | 18/18 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | stale_edit | 18/18 | 100.0% |
| manual-preloaded | claude-sonnet-5 | client_can | 18/18 | 100.0% |
| manual-preloaded | claude-sonnet-5 | locks | 10/18 | 55.6% |
| manual-preloaded | claude-sonnet-5 | multi_home | 18/18 | 100.0% |
| manual-preloaded | claude-sonnet-5 | selectors | 18/18 | 100.0% |
| manual-preloaded | claude-sonnet-5 | stale_edit | 16/18 | 88.9% |
| raw | claude-haiku-4-5-20251001 | client_can | 13/18 | 72.2% |
| raw | claude-haiku-4-5-20251001 | locks | 6/18 | 33.3% |
| raw | claude-haiku-4-5-20251001 | multi_home | 6/18 | 33.3% |
| raw | claude-haiku-4-5-20251001 | selectors | 9/18 | 50.0% |
| raw | claude-haiku-4-5-20251001 | stale_edit | 15/18 | 83.3% |
| raw | claude-sonnet-5 | client_can | 16/18 | 88.9% |
| raw | claude-sonnet-5 | locks | 12/18 | 66.7% |
| raw | claude-sonnet-5 | multi_home | 12/18 | 66.7% |
| raw | claude-sonnet-5 | selectors | 13/18 | 72.2% |
| raw | claude-sonnet-5 | stale_edit | 17/18 | 94.4% |

## Tokens and tool calls per question

Input counts uncached input, cache writes and cache reads together.

| Condition | Model | Rep | Input median | Input p90 | Total median | Total p90 | Tool calls median | Tool calls p90 | Wall median (s) |
|---|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 12315 | 65388 | 12545.5 | 66125 | 4 | 9 | 6.1 |
| adapter | claude-haiku-4-5-20251001 | 2 | 12404 | 36397 | 12644 | 38147 | 4 | 9 | 5.9 |
| adapter | claude-haiku-4-5-20251001 | 3 | 12315 | 57779 | 12545.5 | 58561 | 4 | 9 | 5.6 |
| adapter | claude-sonnet-5 | 1 | 9049.5 | 46994 | 9528 | 47788 | 2.5 | 6 | 7.0 |
| adapter | claude-sonnet-5 | 2 | 5406.5 | 17122 | 5921.5 | 17999 | 2 | 5 | 7.0 |
| adapter | claude-sonnet-5 | 3 | 6518.5 | 45360 | 6767.5 | 47374 | 2 | 7 | 6.3 |
| cli | claude-haiku-4-5-20251001 | 1 | 12930.5 | 31042 | 13370.5 | 32163 | 4.5 | 15 | 9.0 |
| cli | claude-haiku-4-5-20251001 | 2 | 12904 | 33928 | 13336.5 | 34808 | 4 | 9 | 6.1 |
| cli | claude-haiku-4-5-20251001 | 3 | 13730 | 33928 | 14229 | 34808 | 4 | 9 | 6.3 |
| cli | claude-sonnet-5 | 1 | 10656 | 80140 | 11370.5 | 81393 | 4 | 16 | 10.0 |
| cli | claude-sonnet-5 | 2 | 8447 | 66942 | 9374 | 69266 | 3 | 12 | 10.8 |
| cli | claude-sonnet-5 | 3 | 8010 | 31809 | 8548 | 33297 | 3 | 11 | 8.1 |
| cli-manual | claude-haiku-4-5-20251001 | 1 | 7882.5 | 31998 | 8221 | 32688 | 3 | 11 | 4.3 |
| cli-manual | claude-haiku-4-5-20251001 | 2 | 7882.5 | 31998 | 8221 | 32688 | 3 | 11 | 4.2 |
| cli-manual | claude-haiku-4-5-20251001 | 3 | 7882.5 | 31998 | 8221 | 32688 | 3 | 11 | 4.5 |
| cli-manual | claude-sonnet-5 | 1 | 6978 | 25690 | 7352.5 | 26208 | 2 | 5 | 4.8 |
| cli-manual | claude-sonnet-5 | 2 | 10673 | 52375 | 11014.5 | 53988 | 2 | 10 | 7.1 |
| cli-manual | claude-sonnet-5 | 3 | 6477.5 | 35546 | 6824.5 | 36091 | 2 | 6 | 5.3 |
| manual | claude-haiku-4-5-20251001 | 1 | 7275.5 | 22177 | 7615 | 22704 | 3 | 5 | 4.1 |
| manual | claude-haiku-4-5-20251001 | 2 | 7275.5 | 20816 | 7615 | 21152 | 2.5 | 5 | 4.0 |
| manual | claude-haiku-4-5-20251001 | 3 | 7275.5 | 20816 | 7615 | 21152 | 2.5 | 5 | 4.1 |
| manual | claude-sonnet-5 | 1 | 4815 | 30569 | 5293 | 31195 | 1 | 5 | 4.8 |
| manual | claude-sonnet-5 | 2 | 6038.5 | 21684 | 6609.5 | 22045 | 2 | 5 | 4.2 |
| manual | claude-sonnet-5 | 3 | 8393.5 | 21744 | 8748 | 22108 | 2 | 5 | 4.1 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 16214 | 29871 | 16474 | 30426 | 2 | 6 | 3.9 |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 17071.5 | 29871 | 17401 | 30426 | 2.5 | 6 | 4.0 |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 16214 | 25326 | 16474 | 25774 | 2 | 6 | 3.9 |
| manual-preloaded | claude-sonnet-5 | 1 | 14210 | 34074 | 14500 | 34994 | 1 | 4 | 4.8 |
| manual-preloaded | claude-sonnet-5 | 2 | 13889.5 | 31757 | 14278 | 32900 | 1 | 3 | 4.9 |
| manual-preloaded | claude-sonnet-5 | 3 | 13915.5 | 26277 | 14310 | 26877 | 1 | 3 | 4.5 |
| raw | claude-haiku-4-5-20251001 | 1 | 8720.5 | 47372 | 9062 | 48092 | 2.5 | 9 | 5.0 |
| raw | claude-haiku-4-5-20251001 | 2 | 8720.5 | 47372 | 9062 | 48092 | 2.5 | 9 | 4.9 |
| raw | claude-haiku-4-5-20251001 | 3 | 8965 | 47372 | 9466 | 48092 | 2.5 | 9 | 4.9 |
| raw | claude-sonnet-5 | 1 | 4375.5 | 16355 | 4783 | 20803 | 2 | 5 | 5.5 |
| raw | claude-sonnet-5 | 2 | 4230.5 | 17278 | 4692.5 | 20773 | 2 | 5 | 6.4 |
| raw | claude-sonnet-5 | 3 | 4398 | 15249 | 4754.5 | 18545 | 2 | 5 | 5.7 |

## Cost

| Condition | Model | Rep | Per question | Total |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | $0.0179 | $0.5366 |
| adapter | claude-haiku-4-5-20251001 | 2 | $0.0114 | $0.3430 |
| adapter | claude-haiku-4-5-20251001 | 3 | $0.0116 | $0.3472 |
| adapter | claude-sonnet-5 | 1 | $0.0342 | $1.0263 |
| adapter | claude-sonnet-5 | 2 | $0.0248 | $0.7425 |
| adapter | claude-sonnet-5 | 3 | $0.0307 | $0.9197 |
| cli | claude-haiku-4-5-20251001 | 1 | $0.0183 | $0.5505 |
| cli | claude-haiku-4-5-20251001 | 2 | $0.0135 | $0.4036 |
| cli | claude-haiku-4-5-20251001 | 3 | $0.0108 | $0.3248 |
| cli | claude-sonnet-5 | 1 | $0.0469 | $1.4059 |
| cli | claude-sonnet-5 | 2 | $0.0311 | $0.9323 |
| cli | claude-sonnet-5 | 3 | $0.0233 | $0.6976 |
| cli-manual | claude-haiku-4-5-20251001 | 1 | $0.0134 | $0.4026 |
| cli-manual | claude-haiku-4-5-20251001 | 2 | $0.0097 | $0.2902 |
| cli-manual | claude-haiku-4-5-20251001 | 3 | $0.0094 | $0.2832 |
| cli-manual | claude-sonnet-5 | 1 | $0.0202 | $0.6054 |
| cli-manual | claude-sonnet-5 | 2 | $0.0228 | $0.6836 |
| cli-manual | claude-sonnet-5 | 3 | $0.0177 | $0.5302 |
| manual | claude-haiku-4-5-20251001 | 1 | $0.0100 | $0.3013 |
| manual | claude-haiku-4-5-20251001 | 2 | $0.0065 | $0.1965 |
| manual | claude-haiku-4-5-20251001 | 3 | $0.0065 | $0.1964 |
| manual | claude-sonnet-5 | 1 | $0.0213 | $0.6376 |
| manual | claude-sonnet-5 | 2 | $0.0151 | $0.4526 |
| manual | claude-sonnet-5 | 3 | $0.0160 | $0.4805 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | $0.0077 | $0.2323 |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | $0.0044 | $0.1311 |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | $0.0038 | $0.1129 |
| manual-preloaded | claude-sonnet-5 | 1 | $0.0185 | $0.5536 |
| manual-preloaded | claude-sonnet-5 | 2 | $0.0128 | $0.3851 |
| manual-preloaded | claude-sonnet-5 | 3 | $0.0133 | $0.3990 |
| raw | claude-haiku-4-5-20251001 | 1 | $0.0158 | $0.4733 |
| raw | claude-haiku-4-5-20251001 | 2 | $0.0094 | $0.2814 |
| raw | claude-haiku-4-5-20251001 | 3 | $0.0096 | $0.2880 |
| raw | claude-sonnet-5 | 1 | $0.0281 | $0.8420 |
| raw | claude-sonnet-5 | 2 | $0.0255 | $0.7640 |
| raw | claude-sonnet-5 | 3 | $0.0245 | $0.7363 |

Agent total $18.4891. Judge total $0.0444. 859/1080 correct.
