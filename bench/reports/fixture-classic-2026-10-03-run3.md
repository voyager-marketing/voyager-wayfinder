# Wayfinder benchmark: fixture-classic, 2026-10-03 (run3)

## Hypotheses

|  | Claim | Result | Numbers |
|---|---|---|---|
| R1 | The manual condition reaches at least 85.0% accuracy on the classic fixture, on both models | **pass** | manual: claude-haiku-4-5-20251001 92.2% (83/90), per rep 93.3%, 93.3%, 90.0%; claude-sonnet-5 97.8% (88/90), per rep 100.0%, 96.7%, 96.7% |
| R2 | The manual condition beats adapter by at least 10 percentage points of accuracy, on both models | **pass** | claude-haiku-4-5-20251001 manual 92.2% vs adapter 48.9% (+43.3 points); claude-sonnet-5 manual 97.8% vs adapter 71.1% (+26.7 points) |
| R3 | claude-haiku-4-5-20251001 manual-preloaded matches or beats claude-sonnet-5 adapter on accuracy (mean over repetitions) | **pass** | claude-haiku-4-5-20251001 manual-preloaded 90.0%, 90.0%, 86.7% (mean 88.9%, 3 reps) vs claude-sonnet-5 adapter 76.7%, 70.0%, 66.7% (mean 71.1%, 3 reps) |
| R4 | The brief is at most 600 and the index at most 4,000 estimated tokens | **pass** | brief 1,203 chars = 301 est. tokens; index 8,370 chars = 2,093 est. tokens (chars / 4, rounded up) |
| R5 | Total input tokens across all questions in the manual condition are at least 3x lower than adapter, on both models | **pass** | total input tokens per pass: claude-haiku-4-5-20251001 adapter 1,823,588 vs manual 427,523 (4.27x); claude-sonnet-5 adapter 2,535,547 vs manual 236,208 (10.73x) |
| R6 | p90 input tokens per question in the manual condition are at least 3x lower than the adapter p90, on both models | **pass** | p90 input tokens per question: claude-haiku-4-5-20251001 adapter 129,575 vs manual 37,464 (3.46x); claude-sonnet-5 adapter 224,131 vs manual 14,509 (15.45x) |
| R7 | claude-haiku-4-5-20251001 manual-preloaded costs at least 10x less per correct answer than claude-sonnet-5 adapter | **pass** | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0094 vs claude-sonnet-5 adapter $0.1036 (11.05x) |

The spec claims R1 to R7 on both fixtures. This report judges them on this fixture; `reports/run3-summary.md` combines the two.

## Losses

Every comparison on the same model where a baseline cell (raw or adapter) did better than a Wayfinder cell (manual or manual-preloaded): accuracy, accuracy per category, median, p90 and total input tokens, and cost per correct answer, pooled over repetitions. The list is computed by `findLosses` in `src/report.ts`, which was committed before the run, so nothing is chosen after the fact.

None. No baseline cell did better than a Wayfinder cell on any metric listed.

## Metrics without a threshold

|  | What | Numbers |
|---|---|---|
| median-input | Median input tokens per question, baseline against manual. Retired as a hypothesis after runs 1 and 2: on easy questions a baseline agent finishes cheaply, and the manual's advantage sits in the long tail, which R6 measures | claude-haiku-4-5-20251001 adapter 50,991 vs manual 9,151 (5.57x); claude-haiku-4-5-20251001 raw 19,147 vs manual 9,151 (2.09x); claude-sonnet-5 adapter 26,255 vs manual 5,750 (4.57x); claude-sonnet-5 raw 12,698 vs manual 5,750 (2.21x) |

## Most-missed questions

Per condition, over every model and repetition, as the graders scored them. The reason is the grader's, from the first miss.

### adapter

| Question | Category | Missed | Reason |
|---|---|---|---|
| cm-06 | content_model | 6/6 | claude-haiku-4-5-20251001: expected {Company details, Landing page, Project details}; answered: The site does not appear to have any custom field groups (ACF) defined. The site uses Smart Custom Fields (SCF) instead… |
| df-01 | data_flow | 6/6 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| df-02 | data_flow | 6/6 | claude-haiku-4-5-20251001: turn cap reached with no answer |

### manual

| Question | Category | Missed | Reason |
|---|---|---|---|
| el-06 | edit_location | 3/6 | claude-haiku-4-5-20251001: The answer's explanation is incorrect—it states the headline is in the page's main content editor, when it actually must be edited in the Hero section of the Page sections ACF flexible content field—though the path provided (post.php?post=90103&action=edit) is correct for… |
| el-07 | edit_location | 3/6 | claude-haiku-4-5-20251001: The answer incorrectly identifies the Page sections field as the place to change the button link, when the expected answer indicates the link is hard-coded in the vwf-classic theme's page-templates/landing.php file and can only be changed by editing that theme file, not t… |
| df-01 | data_flow | 2/6 | claude-sonnet-5: The answer states the field group is 'Company details' but the expected ground truth does not mention this field group name, and the answer should reference 'Company Settings' as the options page name, which it does correctly, but the field group name discrepancy and lack of explic… |

### manual-preloaded

| Question | Category | Missed | Reason |
|---|---|---|---|
| df-01 | data_flow | 4/4 | claude-haiku-4-5-20251001: The answer correctly identifies the Company Settings options page and the company_phone field, but states the field is within a 'Company details' ACF field group, which contradicts the expected ground truth that shows the field belongs to the 'Company Settings' options pa… |
| el-06 | edit_location | 3/4 | claude-haiku-4-5-20251001: The answer fails to specify that the headline is in the Hero section of the Page sections ACF flexible content field, not in the main page content editor, which is a critical distinction for this page's template.; answered: post.php?post=90103&action=edit |
| el-07 | edit_location | 3/4 | claude-haiku-4-5-20251001: The answer incorrectly identifies the Page sections field as where to change the link, when the expected answer states the link is hard-coded in the vwf-classic theme's page-templates/landing.php file and can only be changed by editing that theme file.; answered: Edit the… |

### raw

| Question | Category | Missed | Reason |
|---|---|---|---|
| ci-01 | change_impact | 2/2 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| cm-06 | content_model | 2/2 | claude-haiku-4-5-20251001: expected {Company details, Landing page, Project details}; answered: The site does not have ACF (Advanced Custom Fields) enabled. ACF is installed but inactive. The site uses Secure Custom… |
| df-01 | data_flow | 2/2 | claude-haiku-4-5-20251001: turn cap reached with no answer |

## Prompt caching (manual-preloaded)

The brief and index sit in a system block marked `cache_control: ephemeral`. The first-call input is everything the first model call read: tools, system prompt, manual and question.

| Model | Minimum cacheable | First-call input median | Cache writes | Cache reads | Runs reading cache | Engaged |
|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | 4,096 | 4,041 | 167,604 | 764,273 | 67/90 | yes |
| claude-sonnet-5 | 1,024 | 4,785 | 24,742 | 191,135 | 29/30 | yes |

- claude-haiku-4-5-20251001: cached. 764,273 tokens came from the cache at the cache-read rate in 67 of 90 runs, and 167,604 were written at the cache-write rate.
- claude-sonnet-5: cached. 191,135 tokens came from the cache at the cache-read rate in 29 of 30 runs, and 24,742 were written at the cache-write rate.

Costs below are what the API billed: uncached input at the input rate, cache writes at 1.25x and cache reads at 0.1x of it (pricing.json). Prompts were not padded to reach a minimum.

## Conversation caching

Every model call put a cache breakpoint on its latest message (VOY-1129), so each call read the conversation before its newest turn from the cache once the prefix cleared the model's minimum. Input total counts uncached input, cache writes and cache reads; the read share is the part billed at the cache-read rate. Costs in this report are on that basis and are not comparable with runs 1 and 2, which cached only the preloaded manual.

| Condition | Model | Runs | Input total | Uncached | Cache writes | Cache reads | Read share | Runs reading cache |
|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 90 | 5,470,765 | 634,748 | 470,985 | 4,365,032 | 79.8% | 71/90 |
| adapter | claude-sonnet-5 | 90 | 7,606,640 | 1,108 | 1,491,144 | 6,114,388 | 80.4% | 90/90 |
| manual | claude-haiku-4-5-20251001 | 90 | 1,282,568 | 405,385 | 154,230 | 722,953 | 56.4% | 37/90 |
| manual | claude-sonnet-5 | 90 | 708,624 | 458 | 306,467 | 401,699 | 56.7% | 90/90 |
| manual-preloaded | claude-haiku-4-5-20251001 | 90 | 1,296,522 | 364,645 | 167,604 | 764,273 | 58.9% | 67/90 |
| manual-preloaded | claude-sonnet-5 | 30 | 215,957 | 80 | 24,742 | 191,135 | 88.5% | 29/30 |
| raw | claude-haiku-4-5-20251001 | 30 | 1,356,646 | 174,930 | 233,592 | 948,124 | 69.9% | 16/30 |
| raw | claude-sonnet-5 | 30 | 1,024,133 | 24,364 | 314,456 | 685,313 | 66.9% | 19/30 |

## Run

Run locally against a freshly seeded wp-env fixture site. 6 concurrent question runs, so wall times include queueing at the site. Harness and plugin at commit `66fef91bd2d8d819cd1a3ae66215b79eedcf7e3b-dirty`. Question set questions/fixture-classic.yaml, 30 questions, sha256 `4511d7c994aabf5bd16fe64a880ef7dd06bebc2202ced544d78a30bd3b02f9ce`. Pricing from https://platform.claude.com/docs/en/about-claude/pricing, fetched 2026-10-01. Models: claude-haiku-4-5-20251001 {"temperature":0}, claude-sonnet-5 {}.

## Accuracy

| Condition | Model | Rep | Correct | Accuracy |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 16/30 | 53.3% |
| adapter | claude-haiku-4-5-20251001 | 2 | 15/30 | 50.0% |
| adapter | claude-haiku-4-5-20251001 | 3 | 13/30 | 43.3% |
| adapter | claude-sonnet-5 | 1 | 23/30 | 76.7% |
| adapter | claude-sonnet-5 | 2 | 21/30 | 70.0% |
| adapter | claude-sonnet-5 | 3 | 20/30 | 66.7% |
| manual | claude-haiku-4-5-20251001 | 1 | 28/30 | 93.3% |
| manual | claude-haiku-4-5-20251001 | 2 | 28/30 | 93.3% |
| manual | claude-haiku-4-5-20251001 | 3 | 27/30 | 90.0% |
| manual | claude-sonnet-5 | 1 | 30/30 | 100.0% |
| manual | claude-sonnet-5 | 2 | 29/30 | 96.7% |
| manual | claude-sonnet-5 | 3 | 29/30 | 96.7% |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 27/30 | 90.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 27/30 | 90.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 26/30 | 86.7% |
| manual-preloaded | claude-sonnet-5 | 1 | 29/30 | 96.7% |
| raw | claude-haiku-4-5-20251001 | 1 | 14/30 | 46.7% |
| raw | claude-sonnet-5 | 1 | 26/30 | 86.7% |

## Accuracy by category

Pooled over repetitions.

| Condition | Model | Category | Correct | Accuracy |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | change_impact | 3/9 | 33.3% |
| adapter | claude-haiku-4-5-20251001 | content_model | 12/18 | 66.7% |
| adapter | claude-haiku-4-5-20251001 | data_flow | 3/15 | 20.0% |
| adapter | claude-haiku-4-5-20251001 | edit_location | 9/21 | 42.9% |
| adapter | claude-haiku-4-5-20251001 | layout_routes | 11/18 | 61.1% |
| adapter | claude-haiku-4-5-20251001 | settings_integrations | 6/9 | 66.7% |
| adapter | claude-sonnet-5 | change_impact | 8/9 | 88.9% |
| adapter | claude-sonnet-5 | content_model | 13/18 | 72.2% |
| adapter | claude-sonnet-5 | data_flow | 5/15 | 33.3% |
| adapter | claude-sonnet-5 | edit_location | 13/21 | 61.9% |
| adapter | claude-sonnet-5 | layout_routes | 16/18 | 88.9% |
| adapter | claude-sonnet-5 | settings_integrations | 9/9 | 100.0% |
| manual | claude-haiku-4-5-20251001 | change_impact | 9/9 | 100.0% |
| manual | claude-haiku-4-5-20251001 | content_model | 18/18 | 100.0% |
| manual | claude-haiku-4-5-20251001 | data_flow | 15/15 | 100.0% |
| manual | claude-haiku-4-5-20251001 | edit_location | 15/21 | 71.4% |
| manual | claude-haiku-4-5-20251001 | layout_routes | 17/18 | 94.4% |
| manual | claude-haiku-4-5-20251001 | settings_integrations | 9/9 | 100.0% |
| manual | claude-sonnet-5 | change_impact | 9/9 | 100.0% |
| manual | claude-sonnet-5 | content_model | 18/18 | 100.0% |
| manual | claude-sonnet-5 | data_flow | 13/15 | 86.7% |
| manual | claude-sonnet-5 | edit_location | 21/21 | 100.0% |
| manual | claude-sonnet-5 | layout_routes | 18/18 | 100.0% |
| manual | claude-sonnet-5 | settings_integrations | 9/9 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | change_impact | 9/9 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | content_model | 18/18 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | data_flow | 11/15 | 73.3% |
| manual-preloaded | claude-haiku-4-5-20251001 | edit_location | 15/21 | 71.4% |
| manual-preloaded | claude-haiku-4-5-20251001 | layout_routes | 18/18 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | settings_integrations | 9/9 | 100.0% |
| manual-preloaded | claude-sonnet-5 | change_impact | 3/3 | 100.0% |
| manual-preloaded | claude-sonnet-5 | content_model | 6/6 | 100.0% |
| manual-preloaded | claude-sonnet-5 | data_flow | 4/5 | 80.0% |
| manual-preloaded | claude-sonnet-5 | edit_location | 7/7 | 100.0% |
| manual-preloaded | claude-sonnet-5 | layout_routes | 6/6 | 100.0% |
| manual-preloaded | claude-sonnet-5 | settings_integrations | 3/3 | 100.0% |
| raw | claude-haiku-4-5-20251001 | change_impact | 1/3 | 33.3% |
| raw | claude-haiku-4-5-20251001 | content_model | 5/6 | 83.3% |
| raw | claude-haiku-4-5-20251001 | data_flow | 1/5 | 20.0% |
| raw | claude-haiku-4-5-20251001 | edit_location | 2/7 | 28.6% |
| raw | claude-haiku-4-5-20251001 | layout_routes | 3/6 | 50.0% |
| raw | claude-haiku-4-5-20251001 | settings_integrations | 2/3 | 66.7% |
| raw | claude-sonnet-5 | change_impact | 2/3 | 66.7% |
| raw | claude-sonnet-5 | content_model | 5/6 | 83.3% |
| raw | claude-sonnet-5 | data_flow | 4/5 | 80.0% |
| raw | claude-sonnet-5 | edit_location | 6/7 | 85.7% |
| raw | claude-sonnet-5 | layout_routes | 6/6 | 100.0% |
| raw | claude-sonnet-5 | settings_integrations | 3/3 | 100.0% |

## Tokens and tool calls per question

Input counts uncached input, cache writes and cache reads together.

| Condition | Model | Rep | Input median | Input p90 | Total median | Total p90 | Tool calls median | Tool calls p90 | Wall median (s) |
|---|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 53763 | 120945 | 54777 | 122098 | 9 | 16 | 21.0 |
| adapter | claude-haiku-4-5-20251001 | 2 | 51291 | 129575 | 52264.5 | 130681 | 9 | 15 | 20.6 |
| adapter | claude-haiku-4-5-20251001 | 3 | 48518.5 | 135601 | 49488 | 136697 | 9 | 16 | 21.0 |
| adapter | claude-sonnet-5 | 1 | 28100 | 224131 | 29100.5 | 227361 | 7 | 20 | 16.9 |
| adapter | claude-sonnet-5 | 2 | 30325 | 214712 | 31174 | 223446 | 6 | 22 | 17.6 |
| adapter | claude-sonnet-5 | 3 | 26255 | 191135 | 26904.5 | 195207 | 4.5 | 21 | 13.3 |
| manual | claude-haiku-4-5-20251001 | 1 | 9114.5 | 37464 | 9285.5 | 38325 | 2 | 7 | 6.9 |
| manual | claude-haiku-4-5-20251001 | 2 | 9196.5 | 37464 | 9381.5 | 38325 | 2 | 7 | 7.4 |
| manual | claude-haiku-4-5-20251001 | 3 | 9196.5 | 37464 | 9381.5 | 38325 | 2 | 7 | 7.4 |
| manual | claude-sonnet-5 | 1 | 5741 | 14627 | 5863.5 | 15117 | 1 | 4 | 4.8 |
| manual | claude-sonnet-5 | 2 | 5752 | 12817 | 5849.5 | 13236 | 1 | 3 | 4.9 |
| manual | claude-sonnet-5 | 3 | 5750 | 13389 | 5863 | 13899 | 1 | 4 | 5.6 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 9924.5 | 25095 | 10017.5 | 25428 | 1 | 4 | 4.4 |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 10537.5 | 25095 | 10665.5 | 25428 | 1 | 4 | 4.8 |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 10537.5 | 25095 | 10665.5 | 25428 | 1 | 4 | 4.9 |
| manual-preloaded | claude-sonnet-5 | 1 | 4789.5 | 14769 | 4883.5 | 14942 | 0 | 1 | 1.6 |
| raw | claude-haiku-4-5-20251001 | 1 | 19147 | 117264 | 19616.5 | 118631 | 6 | 18 | 15.5 |
| raw | claude-sonnet-5 | 1 | 12698 | 106069 | 13204.5 | 107800 | 3.5 | 13 | 8.4 |

## Cost

| Condition | Model | Rep | Per question | Total |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | $0.0277 | $0.8319 |
| adapter | claude-haiku-4-5-20251001 | 2 | $0.0201 | $0.6025 |
| adapter | claude-haiku-4-5-20251001 | 3 | $0.0192 | $0.5775 |
| adapter | claude-sonnet-5 | 1 | $0.0800 | $2.4013 |
| adapter | claude-sonnet-5 | 2 | $0.0777 | $2.3321 |
| adapter | claude-sonnet-5 | 3 | $0.0632 | $1.8971 |
| manual | claude-haiku-4-5-20251001 | 1 | $0.0110 | $0.3286 |
| manual | claude-haiku-4-5-20251001 | 2 | $0.0084 | $0.2524 |
| manual | claude-haiku-4-5-20251001 | 3 | $0.0079 | $0.2361 |
| manual | claude-sonnet-5 | 1 | $0.0137 | $0.4098 |
| manual | claude-sonnet-5 | 2 | $0.0103 | $0.3100 |
| manual | claude-sonnet-5 | 3 | $0.0106 | $0.3171 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | $0.0122 | $0.3649 |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | $0.0063 | $0.1894 |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | $0.0065 | $0.1955 |
| manual-preloaded | claude-sonnet-5 | 1 | $0.0051 | $0.1519 |
| raw | claude-haiku-4-5-20251001 | 1 | $0.0225 | $0.6737 |
| raw | claude-sonnet-5 | 1 | $0.0438 | $1.3139 |

Agent total $13.3856. Judge total $0.0545. 428/540 correct.
