# Wayfinder benchmark: fixture-classic, 2026-10-06 (run5-regression)

## Hypotheses

|  | Claim | Result | Numbers |
|---|---|---|---|
| R1 | The manual condition reaches at least 85.0% accuracy on the classic fixture, on both models | **pass** | manual: claude-haiku-4-5-20251001 100.0% (30/30); claude-sonnet-5 100.0% (30/30) |
| R2 | The manual condition beats adapter by at least 10 percentage points of accuracy, on both models | **not measured** | no results for adapter claude-haiku-4-5-20251001 |
| R3 | claude-haiku-4-5-20251001 manual-preloaded matches or beats claude-sonnet-5 adapter on accuracy (mean over repetitions) | **not measured** | no results for adapter claude-sonnet-5 |
| R4 | The brief is at most 600 and the index at most 4,000 estimated tokens | **pass** | brief 1,517 chars = 380 est. tokens; index 9,275 chars = 2,319 est. tokens (chars / 4, rounded up) |
| R5 | Total input tokens across all questions in the manual condition are at least 3x lower than adapter, on both models | **not measured** | no results for adapter claude-haiku-4-5-20251001 |
| R6 | p90 input tokens per question in the manual condition are at least 3x lower than the adapter p90, on both models | **not measured** | no results for adapter claude-haiku-4-5-20251001 |
| R7 | claude-haiku-4-5-20251001 manual-preloaded costs at least 10x less per correct answer than claude-sonnet-5 adapter | **not measured** | no results for adapter claude-sonnet-5 |

The spec claims R1 to R7 on both fixtures. This report judges them on this fixture; `reports/run3-summary.md` combines the two.

## Intervals

Every figure below has a 95% interval from resampling questions with replacement: 10,000 resamples from seed 1215, so the numbers are the same on every run, with the 250th and 9,750th sorted values as bounds. A question counts once, scored as the share of its repetitions answered correctly, because the repetitions of one question are not independent samples. Each comparison pairs its two cells by question. McNemar's exact test pairs repetition k of one cell with repetition k of the other, over the repetitions both ran; it treats those pairs as independent, so its p is optimistic and the interval is the figure to read. "Read by" names the rules that read a figure against the named baseline; the best-baseline restatement reads whichever baseline row it names. Computed by `src/intervals.ts` from the graders' verdicts in this report, with no API calls. Hand re-grades in a `corrected` block are not resampled.

### Cell accuracy

| Model | Condition | Questions | Reps | Accuracy | 95% interval | Read by |
|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | manual | 30 | 1 | 100.0% | 100.0% to 100.0% | R1 |
| claude-haiku-4-5-20251001 | manual-preloaded | 30 | 1 | 93.3% | 83.3% to 100.0% |  |
| claude-sonnet-5 | manual | 30 | 1 | 100.0% | 100.0% to 100.0% | R1 |
| claude-sonnet-5 | manual-preloaded | 30 | 1 | 100.0% | 100.0% to 100.0% |  |

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

### manual-preloaded

| Question | Category | Missed | Reason |
|---|---|---|---|
| el-07 | edit_location | 1/2 | claude-haiku-4-5-20251001: The answer incorrectly identifies the link as editable through the Page sections field in wp-admin, when the ground truth shows the link is hard-coded in the vwf-classic theme's page-templates/landing.php file and can only be changed by editing that theme file.; answered:… |
| lr-04 | layout_routes | 1/2 | claude-haiku-4-5-20251001: expected {footer.php, header.php}; answered: footer.php, header.php, archive-project.php, page-templates/landing.php, single-project.php, template-parts/content-pro… |

## Prompt caching (manual-preloaded)

The brief and index sit in a system block marked `cache_control: ephemeral`. The first-call input is everything the first model call read: tools, system prompt, manual and question.

| Model | Minimum cacheable | First-call input median | Cache writes | Cache reads | Runs reading cache | Engaged |
|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | 4,096 | 4,438 | 56,040 | 382,981 | 30/30 | yes |
| claude-sonnet-5 | 1,024 | 5,297 | 24,265 | 205,235 | 29/30 | yes |

- claude-haiku-4-5-20251001: cached. 382,981 tokens came from the cache at the cache-read rate in 30 of 30 runs, and 56,040 were written at the cache-write rate.
- claude-sonnet-5: cached. 205,235 tokens came from the cache at the cache-read rate in 29 of 30 runs, and 24,265 were written at the cache-write rate.

Costs below are what the API billed: uncached input at the input rate, cache writes at 1.25x and cache reads at 0.1x of it (pricing.json). Prompts were not padded to reach a minimum.

## Conversation caching

Every model call put a cache breakpoint on its latest message (VOY-1129), so each call read the conversation before its newest turn from the cache once the prefix cleared the model's minimum. Input total counts uncached input, cache writes and cache reads; the read share is the part billed at the cache-read rate. Costs in this report are on that basis and are not comparable with runs 1 and 2, which cached only the preloaded manual.

| Condition | Model | Runs | Input total | Uncached | Cache writes | Cache reads | Read share | Runs reading cache |
|---|---|---|---|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 30 | 433,321 | 102,306 | 133,624 | 197,391 | 45.6% | 14/30 |
| manual | claude-sonnet-5 | 30 | 254,713 | 154 | 132,880 | 121,679 | 47.8% | 30/30 |
| manual-preloaded | claude-haiku-4-5-20251001 | 30 | 439,393 | 372 | 56,040 | 382,981 | 87.2% | 30/30 |
| manual-preloaded | claude-sonnet-5 | 30 | 229,578 | 78 | 24,265 | 205,235 | 89.4% | 29/30 |

## Run

Run in GitHub Actions (bench.yml) against a fixture site the workflow built and seeded. 6 concurrent question runs, so wall times include queueing at the site. Harness and plugin at commit `826cce2a66d29d1098b2b0c05ad0f6efa763641d`. At most 24 model calls per question. Question set questions/fixture-classic.yaml, 30 questions, sha256 `4511d7c994aabf5bd16fe64a880ef7dd06bebc2202ced544d78a30bd3b02f9ce`. Pricing from https://platform.claude.com/docs/en/about-claude/pricing, fetched 2026-10-01. Models: claude-haiku-4-5-20251001 {"temperature":0}, claude-sonnet-5 {}.

## Accuracy

| Condition | Model | Rep | Correct | Accuracy |
|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 1 | 30/30 | 100.0% |
| manual | claude-sonnet-5 | 1 | 30/30 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 28/30 | 93.3% |
| manual-preloaded | claude-sonnet-5 | 1 | 30/30 | 100.0% |

## Accuracy by category

Pooled over repetitions.

| Condition | Model | Category | Correct | Accuracy |
|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | change_impact | 3/3 | 100.0% |
| manual | claude-haiku-4-5-20251001 | content_model | 6/6 | 100.0% |
| manual | claude-haiku-4-5-20251001 | data_flow | 5/5 | 100.0% |
| manual | claude-haiku-4-5-20251001 | edit_location | 7/7 | 100.0% |
| manual | claude-haiku-4-5-20251001 | layout_routes | 6/6 | 100.0% |
| manual | claude-haiku-4-5-20251001 | settings_integrations | 3/3 | 100.0% |
| manual | claude-sonnet-5 | change_impact | 3/3 | 100.0% |
| manual | claude-sonnet-5 | content_model | 6/6 | 100.0% |
| manual | claude-sonnet-5 | data_flow | 5/5 | 100.0% |
| manual | claude-sonnet-5 | edit_location | 7/7 | 100.0% |
| manual | claude-sonnet-5 | layout_routes | 6/6 | 100.0% |
| manual | claude-sonnet-5 | settings_integrations | 3/3 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | change_impact | 3/3 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | content_model | 6/6 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | data_flow | 5/5 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | edit_location | 6/7 | 85.7% |
| manual-preloaded | claude-haiku-4-5-20251001 | layout_routes | 5/6 | 83.3% |
| manual-preloaded | claude-haiku-4-5-20251001 | settings_integrations | 3/3 | 100.0% |
| manual-preloaded | claude-sonnet-5 | change_impact | 3/3 | 100.0% |
| manual-preloaded | claude-sonnet-5 | content_model | 6/6 | 100.0% |
| manual-preloaded | claude-sonnet-5 | data_flow | 5/5 | 100.0% |
| manual-preloaded | claude-sonnet-5 | edit_location | 7/7 | 100.0% |
| manual-preloaded | claude-sonnet-5 | layout_routes | 6/6 | 100.0% |
| manual-preloaded | claude-sonnet-5 | settings_integrations | 3/3 | 100.0% |

## Tokens and tool calls per question

Input counts uncached input, cache writes and cache reads together.

| Condition | Model | Rep | Input median | Input p90 | Total median | Total p90 | Tool calls median | Tool calls p90 | Wall median (s) |
|---|---|---|---|---|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 1 | 8611 | 43431 | 8973 | 44147 | 2.5 | 7 | 3.6 |
| manual | claude-sonnet-5 | 1 | 6290.5 | 14636 | 6422 | 15101 | 1 | 4 | 2.8 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 10718 | 31165 | 10804.5 | 31594 | 1 | 4 | 2.2 |
| manual-preloaded | claude-sonnet-5 | 1 | 5301 | 11773 | 5402.5 | 12082 | 0 | 1 | 1.9 |

## Cost

| Condition | Model | Rep | Per question | Total |
|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 1 | $0.0113 | $0.3390 |
| manual | claude-sonnet-5 | 1 | $0.0144 | $0.4318 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | $0.0047 | $0.1414 |
| manual-preloaded | claude-sonnet-5 | 1 | $0.0051 | $0.1523 |

Agent total $1.0645. Judge total $0.0170. 118/120 correct.
