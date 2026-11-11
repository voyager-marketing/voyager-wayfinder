# Wayfinder benchmark: fixture, 2026-10-06 (run5-regression)

## Hypotheses

|  | Claim | Result | Numbers |
|---|---|---|---|
| R1 | The manual condition reaches at least 90.0% accuracy on the block fixture, on both models | **pass** | manual: claude-haiku-4-5-20251001 100.0% (40/40); claude-sonnet-5 97.5% (39/40) |
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
| claude-haiku-4-5-20251001 | manual | 40 | 1 | 100.0% | 100.0% to 100.0% | R1 |
| claude-haiku-4-5-20251001 | manual-preloaded | 40 | 1 | 100.0% | 100.0% to 100.0% |  |
| claude-sonnet-5 | manual | 40 | 1 | 97.5% | 92.5% to 100.0% | R1 |
| claude-sonnet-5 | manual-preloaded | 40 | 1 | 92.5% | 82.5% to 100.0% |  |

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
| df-01 | data_flow | 1/2 | claude-sonnet-5: missing vwf_phone, judge not called; answered: Settings > Business Info (options-general.php?page=vwf-business-info) — the footer block binds to the "phone" field the… |

### manual-preloaded

| Question | Category | Missed | Reason |
|---|---|---|---|
| ci-04 | change_impact | 1/2 | claude-sonnet-5: expected "6"; answered: 15 |
| cm-07 | content_model | 1/2 | claude-sonnet-5: expected "6"; answered: 15 |
| cm-08 | content_model | 1/2 | claude-sonnet-5: expected "5"; answered: 1-5 |

## Prompt caching (manual-preloaded)

The brief and index sit in a system block marked `cache_control: ephemeral`. The first-call input is everything the first model call read: tools, system prompt, manual and question.

| Model | Minimum cacheable | First-call input median | Cache writes | Cache reads | Runs reading cache | Engaged |
|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | 4,096 | 5,042 | 52,490 | 412,612 | 40/40 | yes |
| claude-sonnet-5 | 1,024 | 6,197 | 18,874 | 263,283 | 38/40 | yes |

- claude-haiku-4-5-20251001: cached. 412,612 tokens came from the cache at the cache-read rate in 40 of 40 runs, and 52,490 were written at the cache-write rate.
- claude-sonnet-5: cached. 263,283 tokens came from the cache at the cache-read rate in 38 of 40 runs, and 18,874 were written at the cache-write rate.

Costs below are what the API billed: uncached input at the input rate, cache writes at 1.25x and cache reads at 0.1x of it (pricing.json). Prompts were not padded to reach a minimum.

## Conversation caching

Every model call put a cache breakpoint on its latest message (VOY-1129), so each call read the conversation before its newest turn from the cache once the prefix cleared the model's minimum. Input total counts uncached input, cache writes and cache reads; the read share is the part billed at the cache-read rate. Costs in this report are on that basis and are not comparable with runs 1 and 2, which cached only the preloaded manual.

| Condition | Model | Runs | Input total | Uncached | Cache writes | Cache reads | Read share | Runs reading cache |
|---|---|---|---|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 40 | 399,153 | 83,430 | 170,424 | 145,299 | 36.4% | 19/40 |
| manual | claude-sonnet-5 | 40 | 306,113 | 184 | 199,580 | 106,349 | 34.7% | 40/40 |
| manual-preloaded | claude-haiku-4-5-20251001 | 40 | 465,463 | 361 | 52,490 | 412,612 | 88.6% | 40/40 |
| manual-preloaded | claude-sonnet-5 | 40 | 282,247 | 90 | 18,874 | 263,283 | 93.3% | 38/40 |

## Run

Run in GitHub Actions (bench.yml) against a fixture site the workflow built and seeded. 6 concurrent question runs, so wall times include queueing at the site. Harness and plugin at commit `826cce2a66d29d1098b2b0c05ad0f6efa763641d`. At most 24 model calls per question. Question set questions/fixture.yaml, 40 questions, sha256 `e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b`. Pricing from https://platform.claude.com/docs/en/about-claude/pricing, fetched 2026-10-01. Models: claude-haiku-4-5-20251001 {"temperature":0}, claude-sonnet-5 {}.

## Accuracy

| Condition | Model | Rep | Correct | Accuracy |
|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 1 | 40/40 | 100.0% |
| manual | claude-sonnet-5 | 1 | 39/40 | 97.5% |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 40/40 | 100.0% |
| manual-preloaded | claude-sonnet-5 | 1 | 37/40 | 92.5% |

## Accuracy by category

Pooled over repetitions.

| Condition | Model | Category | Correct | Accuracy |
|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | change_impact | 4/4 | 100.0% |
| manual | claude-haiku-4-5-20251001 | content_model | 8/8 | 100.0% |
| manual | claude-haiku-4-5-20251001 | data_flow | 6/6 | 100.0% |
| manual | claude-haiku-4-5-20251001 | edit_location | 10/10 | 100.0% |
| manual | claude-haiku-4-5-20251001 | layout_routes | 8/8 | 100.0% |
| manual | claude-haiku-4-5-20251001 | settings_integrations | 4/4 | 100.0% |
| manual | claude-sonnet-5 | change_impact | 4/4 | 100.0% |
| manual | claude-sonnet-5 | content_model | 8/8 | 100.0% |
| manual | claude-sonnet-5 | data_flow | 5/6 | 83.3% |
| manual | claude-sonnet-5 | edit_location | 10/10 | 100.0% |
| manual | claude-sonnet-5 | layout_routes | 8/8 | 100.0% |
| manual | claude-sonnet-5 | settings_integrations | 4/4 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | change_impact | 4/4 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | content_model | 8/8 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | data_flow | 6/6 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | edit_location | 10/10 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | layout_routes | 8/8 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | settings_integrations | 4/4 | 100.0% |
| manual-preloaded | claude-sonnet-5 | change_impact | 3/4 | 75.0% |
| manual-preloaded | claude-sonnet-5 | content_model | 6/8 | 75.0% |
| manual-preloaded | claude-sonnet-5 | data_flow | 6/6 | 100.0% |
| manual-preloaded | claude-sonnet-5 | edit_location | 10/10 | 100.0% |
| manual-preloaded | claude-sonnet-5 | layout_routes | 8/8 | 100.0% |
| manual-preloaded | claude-sonnet-5 | settings_integrations | 4/4 | 100.0% |

## Tokens and tool calls per question

Input counts uncached input, cache writes and cache reads together.

| Condition | Model | Rep | Input median | Input p90 | Total median | Total p90 | Tool calls median | Tool calls p90 | Wall median (s) |
|---|---|---|---|---|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 1 | 7866.5 | 15817 | 8146 | 16148 | 2 | 4 | 3.2 |
| manual | claude-sonnet-5 | 1 | 6899.5 | 12837 | 7030 | 13148 | 1 | 2 | 2.9 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 10401 | 16620 | 10598 | 16788 | 1 | 2 | 2.0 |
| manual-preloaded | claude-sonnet-5 | 1 | 6197 | 6209 | 6268 | 6999 | 0 | 0 | 1.4 |

## Cost

| Condition | Model | Rep | Per question | Total |
|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 1 | $0.0089 | $0.3566 |
| manual | claude-sonnet-5 | 1 | $0.0146 | $0.5841 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | $0.0034 | $0.1366 |
| manual-preloaded | claude-sonnet-5 | 1 | $0.0039 | $0.1561 |

Agent total $1.2334. Judge total $0.0364. 156/160 correct.
