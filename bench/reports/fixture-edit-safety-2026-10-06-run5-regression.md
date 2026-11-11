# Wayfinder benchmark: fixture-edit-safety, 2026-10-06 (run5-regression)

## Hypotheses

|  | Claim | Result | Numbers |
|---|---|---|---|
| R1 | The manual condition reaches at least 90.0% accuracy on the block fixture, on both models | **pass** | manual: claude-haiku-4-5-20251001 100.0% (20/20); claude-sonnet-5 95.0% (19/20) |
| R2 | The manual condition beats adapter by at least 10 percentage points of accuracy, on both models | **not measured** | no results for adapter claude-haiku-4-5-20251001 |
| R3 | claude-haiku-4-5-20251001 manual-preloaded matches or beats claude-sonnet-5 adapter on accuracy (mean over repetitions) | **not measured** | no results for adapter claude-sonnet-5 |
| R4 | The brief is at most 600 and the index at most 4,000 estimated tokens | **pass** | brief 2,187 chars = 547 est. tokens; index 9,974 chars = 2,494 est. tokens (chars / 4, rounded up) |
| R5 | Total input tokens across all questions in the manual condition are at least 3x lower than adapter, on both models | **not measured** | no results for adapter claude-haiku-4-5-20251001 |
| R6 | p90 input tokens per question in the manual condition are at least 3x lower than the adapter p90, on both models | **not measured** | no results for adapter claude-haiku-4-5-20251001 |
| R7 | claude-haiku-4-5-20251001 manual-preloaded costs at least 10x less per correct answer than claude-sonnet-5 adapter | **not measured** | no results for adapter claude-sonnet-5 |

The spec claims R1 to R7 on both fixtures. This report judges them on this fixture; `reports/run3-summary.md` combines the two.

## Intervals

Every figure below has a 95% interval from resampling questions with replacement: 10,000 resamples from seed 1215, so the numbers are the same on every run, with the 250th and 9,750th sorted values as bounds. A question counts once, scored as the share of its repetitions answered correctly, because the repetitions of one question are not independent samples. Each comparison pairs its two cells by question. McNemar's exact test pairs repetition k of one cell with repetition k of the other, over the repetitions both ran; it treats those pairs as independent, so its p is optimistic and the interval is the figure to read. "Read by" names the rules that read a figure against the named baseline; the best-baseline restatement reads whichever baseline row it names. Computed by `src/intervals.ts` from the graders' verdicts in this report, with no API calls. Hand re-grades in a `corrected` block are not resampled.

### Cell accuracy

| Model | Condition | Questions | Reps | Accuracy | 95% interval | Read by |
|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | manual | 20 | 1 | 100.0% | 100.0% to 100.0% | R1 |
| claude-haiku-4-5-20251001 | manual-preloaded | 20 | 1 | 85.0% | 65.0% to 100.0% |  |
| claude-sonnet-5 | manual | 20 | 1 | 95.0% | 85.0% to 100.0% | R1 |
| claude-sonnet-5 | manual-preloaded | 20 | 1 | 90.0% | 75.0% to 100.0% |  |

### Paired accuracy

Challenger minus baseline, in percentage points. "Within 5" is the non-inferiority check S3 uses: the lower bound is at least -5 points. McNemar counts the repetition-matched pairs only the challenger answered correctly, and only the baseline.

No comparisons: the report has no Wayfinder cell beside a baseline cell.

### Paired cost per correct answer

The baseline's cost per correct answer over the challenger's, as R7 reads it: agent cost over correct answers on each side, summed over the resampled questions and every repetition. Above 1x the challenger is cheaper per correct answer.

No comparisons.

## Losses

Every comparison on the same model where a baseline cell (raw, adapter or cli) did better than a Wayfinder cell (manual, manual-preloaded or cli-manual): accuracy, accuracy per category, median, p90 and total input tokens, and cost per correct answer, pooled over repetitions. The list is computed by `findLosses` in `src/report.ts`, which was committed before the run, so nothing is chosen after the fact.

None. No baseline cell did better than a Wayfinder cell on any metric listed.

## Most-missed questions

Per condition, over every model and repetition, as the graders scored them. The reason is the grader's, from the first miss.

### manual

| Question | Category | Missed | Reason |
|---|---|---|---|
| se-04 | stale_edit | 1/2 | claude-sonnet-5: expected "gone"; answered: changed |

### manual-preloaded

| Question | Category | Missed | Reason |
|---|---|---|---|
| mh-01 | multi_home | 2/2 | claude-haiku-4-5-20251001: missing post.php?post=90062&action=edit; answered: post.php?post=90070&action=edit |
| se-01 | stale_edit | 2/2 | claude-haiku-4-5-20251001: expected "5"; answered: no ANSWER line |
| lk-03 | locks | 1/2 | claude-haiku-4-5-20251001: expected {License notice, Plan inclusions, Plan name, Plan price, Refund terms, Renewal terms}; answered: Renewal terms, Refund terms, License notice |

## Prompt caching (manual-preloaded)

The brief and index sit in a system block marked `cache_control: ephemeral`. The first-call input is everything the first model call read: tools, system prompt, manual and question.

| Model | Minimum cacheable | First-call input median | Cache writes | Cache reads | Runs reading cache | Engaged |
|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | 4,096 | 5,061 | 64,602 | 331,920 | 20/20 | yes |
| claude-sonnet-5 | 1,024 | 6,222 | 72,795 | 367,819 | 20/20 | yes |

- claude-haiku-4-5-20251001: cached. 331,920 tokens came from the cache at the cache-read rate in 20 of 20 runs, and 64,602 were written at the cache-write rate.
- claude-sonnet-5: cached. 367,819 tokens came from the cache at the cache-read rate in 20 of 20 runs, and 72,795 were written at the cache-write rate.

Costs below are what the API billed: uncached input at the input rate, cache writes at 1.25x and cache reads at 0.1x of it (pricing.json). Prompts were not padded to reach a minimum.

## Conversation caching

Every model call put a cache breakpoint on its latest message (VOY-1129), so each call read the conversation before its newest turn from the cache once the prefix cleared the model's minimum. Input total counts uncached input, cache writes and cache reads; the read share is the part billed at the cache-read rate. Costs in this report are on that basis and are not comparable with runs 1 and 2, which cached only the preloaded manual.

| Condition | Model | Runs | Input total | Uncached | Cache writes | Cache reads | Read share | Runs reading cache |
|---|---|---|---|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 20 | 349,298 | 69,696 | 119,894 | 159,708 | 45.7% | 7/20 |
| manual | claude-sonnet-5 | 20 | 306,619 | 120 | 132,625 | 173,874 | 56.7% | 20/20 |
| manual-preloaded | claude-haiku-4-5-20251001 | 20 | 396,792 | 270 | 64,602 | 331,920 | 83.7% | 20/20 |
| manual-preloaded | claude-sonnet-5 | 20 | 440,712 | 98 | 72,795 | 367,819 | 83.5% | 20/20 |

## Run

Run in GitHub Actions (bench.yml) against a fixture site the workflow built and seeded. 6 concurrent question runs, so wall times include queueing at the site. Harness and plugin at commit `826cce2a66d29d1098b2b0c05ad0f6efa763641d`. At most 24 model calls per question. Question set questions/fixture-edit-safety.yaml, 20 questions, sha256 `69eb2c8401ba4ff31666e968dce3cf8ac31c3c5153c4920a70dbadb9fe372cc9`. Pricing from https://platform.claude.com/docs/en/about-claude/pricing, fetched 2026-10-01. Models: claude-haiku-4-5-20251001 {"temperature":0}, claude-sonnet-5 {}.

## Accuracy

| Condition | Model | Rep | Correct | Accuracy |
|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 1 | 20/20 | 100.0% |
| manual | claude-sonnet-5 | 1 | 19/20 | 95.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 17/20 | 85.0% |
| manual-preloaded | claude-sonnet-5 | 1 | 18/20 | 90.0% |

## Accuracy by category

Pooled over repetitions.

| Condition | Model | Category | Correct | Accuracy |
|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | locks | 5/5 | 100.0% |
| manual | claude-haiku-4-5-20251001 | multi_home | 5/5 | 100.0% |
| manual | claude-haiku-4-5-20251001 | selectors | 5/5 | 100.0% |
| manual | claude-haiku-4-5-20251001 | stale_edit | 5/5 | 100.0% |
| manual | claude-sonnet-5 | locks | 5/5 | 100.0% |
| manual | claude-sonnet-5 | multi_home | 5/5 | 100.0% |
| manual | claude-sonnet-5 | selectors | 5/5 | 100.0% |
| manual | claude-sonnet-5 | stale_edit | 4/5 | 80.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | locks | 4/5 | 80.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | multi_home | 4/5 | 80.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | selectors | 5/5 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | stale_edit | 4/5 | 80.0% |
| manual-preloaded | claude-sonnet-5 | locks | 5/5 | 100.0% |
| manual-preloaded | claude-sonnet-5 | multi_home | 4/5 | 80.0% |
| manual-preloaded | claude-sonnet-5 | selectors | 5/5 | 100.0% |
| manual-preloaded | claude-sonnet-5 | stale_edit | 4/5 | 80.0% |

## Tokens and tool calls per question

Input counts uncached input, cache writes and cache reads together.

| Condition | Model | Rep | Input median | Input p90 | Total median | Total p90 | Tool calls median | Tool calls p90 | Wall median (s) |
|---|---|---|---|---|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 1 | 8409.5 | 44030 | 8804.5 | 44809 | 3 | 8 | 5.2 |
| manual | claude-sonnet-5 | 1 | 4875.5 | 38807 | 5151 | 40011 | 1.5 | 7 | 4.8 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 15288.5 | 26916 | 15601.5 | 27390 | 2 | 6 | 4.2 |
| manual-preloaded | claude-sonnet-5 | 1 | 14418 | 30537 | 14653.5 | 31084 | 1 | 5 | 5.1 |

## Cost

| Condition | Model | Rep | Per question | Total |
|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 1 | $0.0139 | $0.2774 |
| manual | claude-sonnet-5 | 1 | $0.0233 | $0.4659 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | $0.0073 | $0.1461 |
| manual-preloaded | claude-sonnet-5 | 1 | $0.0169 | $0.3371 |

Agent total $1.2266. Judge total $0.0042. 74/80 correct.
