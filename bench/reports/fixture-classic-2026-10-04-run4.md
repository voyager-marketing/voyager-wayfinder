# Wayfinder benchmark: fixture-classic, 2026-10-04 (run4)

## Hypotheses

|  | Claim | Result | Numbers |
|---|---|---|---|
| R1 | The manual condition reaches at least 85.0% accuracy on the classic fixture, on both models | **pass** | manual: claude-haiku-4-5-20251001 97.8% (88/90), per rep 96.7%, 100.0%, 96.7%; claude-sonnet-5 97.8% (88/90), per rep 96.7%, 100.0%, 96.7% |
| R2 | The manual condition beats adapter by at least 10 percentage points of accuracy, on both models | **pass** | claude-haiku-4-5-20251001 manual 97.8% vs adapter 46.7% (+51.1 points); claude-sonnet-5 manual 97.8% vs adapter 74.4% (+23.3 points) |
| R3 | claude-haiku-4-5-20251001 manual-preloaded matches or beats claude-sonnet-5 adapter on accuracy (mean over repetitions) | **pass** | claude-haiku-4-5-20251001 manual-preloaded 93.3%, 93.3%, 93.3% (mean 93.3%, 3 reps) vs claude-sonnet-5 adapter 70.0%, 70.0%, 83.3% (mean 74.4%, 3 reps) |
| R4 | The brief is at most 600 and the index at most 4,000 estimated tokens | **pass** | brief 1,425 chars = 357 est. tokens; index 8,884 chars = 2,221 est. tokens (chars / 4, rounded up) |
| R5 | Total input tokens across all questions in the manual condition are at least 3x lower than adapter, on both models | **pass** | total input tokens per pass: claude-haiku-4-5-20251001 adapter 1,630,385 vs manual 418,439 (3.90x); claude-sonnet-5 adapter 2,193,976 vs manual 237,831 (9.22x) |
| R6 | p90 input tokens per question in the manual condition are at least 3x lower than the adapter p90, on both models | **pass** | p90 input tokens per question: claude-haiku-4-5-20251001 adapter 122,277 vs manual 34,894 (3.50x); claude-sonnet-5 adapter 199,506 vs manual 13,026 (15.32x) |
| R7 | claude-haiku-4-5-20251001 manual-preloaded costs at least 10x less per correct answer than claude-sonnet-5 adapter | **pass** | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0050 vs claude-sonnet-5 adapter $0.0864 (17.39x) |

The spec claims R1 to R7 on both fixtures. This report judges them on this fixture; `reports/run3-summary.md` combines the two.

These are the graders' verdicts. With 3 misses re-graded by hand (VOY-1220, see Grader corrections): R1 pass, R2 pass, R3 pass, R4 pass, R5 pass, R6 pass, R7 pass.

## Losses

Every comparison on the same model where a baseline cell (raw or adapter) did better than a Wayfinder cell (manual or manual-preloaded): accuracy, accuracy per category, median, p90 and total input tokens, and cost per correct answer, pooled over repetitions. The list is computed by `findLosses` in `src/report.ts`, which was committed before the run, so nothing is chosen after the fact.

None. No baseline cell did better than a Wayfinder cell on any metric listed.

## Metrics without a threshold

|  | What | Numbers |
|---|---|---|
| median-input | Median input tokens per question, baseline against manual. Retired as a hypothesis after runs 1 and 2: on easy questions a baseline agent finishes cheaply, and the manual's advantage sits in the long tail, which R6 measures | claude-haiku-4-5-20251001 adapter 42,580 vs manual 9,658 (4.41x); claude-haiku-4-5-20251001 raw 25,389 vs manual 9,658 (2.63x); claude-sonnet-5 adapter 16,884 vs manual 5,950 (2.84x); claude-sonnet-5 raw 11,632 vs manual 5,950 (1.95x) |

## Most-missed questions

Per condition, over every model and repetition, as the graders scored them. The reason is the grader's, from the first miss.

### adapter

| Question | Category | Missed | Reason |
|---|---|---|---|
| df-01 | data_flow | 6/6 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| df-02 | data_flow | 6/6 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| df-03 | data_flow | 6/6 | claude-haiku-4-5-20251001: turn cap reached with no answer |

### manual

| Question | Category | Missed | Reason |
|---|---|---|---|
| df-01 | data_flow | 2/6 | claude-sonnet-5: The answer states the ACF group is 'Company details' but the expected ground truth indicates the options page is 'Company Settings' without specifying a group name as 'Company details', and the answer refers to 'acf_option:company_phone' which is not the standard way get_field retr… |
| el-07 | edit_location | 1/6 | claude-haiku-4-5-20251001: The answer incorrectly identifies the Page sections field as the place to change the link, when the correct answer is that the link is hard-coded in the vwf-classic theme's page-templates/landing.php file and cannot be changed via wp-admin fields.; answered: Edit the Home… |
| lr-04 | layout_routes | 1/6 | claude-haiku-4-5-20251001: expected {footer.php, header.php}; answered: archive-project.php, page-templates/landing.php, single-project.php, footer.php, header.php, template-parts/content-pro… |

### manual-preloaded

| Question | Category | Missed | Reason |
|---|---|---|---|
| el-07 | edit_location | 4/4 | claude-haiku-4-5-20251001: The answer incorrectly states that the button link can be changed via the 'Page sections' ACF field in wp-admin, when the ground truth shows the link is hard-coded in the vwf-classic theme's page-templates/landing.php file and can only be changed by editing that theme fil… |
| el-06 | edit_location | 3/4 | claude-haiku-4-5-20251001: The answer incorrectly identifies the headline as the page title and directs to the post editor, but fails to specify that the headline must be changed in the Hero section of the Page sections (ACF flexible content) field, not in the main page title field.; answered: post… |
| df-01 | data_flow | 1/4 | claude-sonnet-5: The answer states the field is part of a 'Company details' field group, but the expected ground truth only confirms the field key is 'company_phone' within the 'Company Settings' options page without specifying a field group name, making this an unverified extra detail that could c… |

### raw

| Question | Category | Missed | Reason |
|---|---|---|---|
| cm-06 | content_model | 2/2 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| df-01 | data_flow | 2/2 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| df-05 | data_flow | 2/2 | claude-haiku-4-5-20251001: turn cap reached with no answer |

## Grader corrections

Re-graded by hand from the full replies in release bench-run4-transcripts, under df-01's rubric and the judge's own instruction that extra correct detail never makes an answer wrong. A reply is correct when it says footer.php shows the company_phone field of the Company Settings ACF options page, edited at Company Settings (admin.php?page=company-settings), and contradicts none of it. The judge failed three Sonnet replies for naming the field group, Company details, which EXPECTED did not hold. Truth records it: options_pages[id=optpage:company-settings].field_groups is ["Company details"], and the manual prints "ACF group Company details" on that page's line. Eight df-01 misses stay wrong: five reached the turn cap with no answer, and raw Sonnet rep 1 and adapter Sonnet reps 2 and 3 say the number is hardcoded in footer.php, which contradicts truth. The questions, answer keys and graders are unchanged; the judge prompt changes from run 5. Tracked in VOY-1220.

|  | Corrected result | Numbers |
|---|---|---|
| R1 | **pass** | manual: claude-haiku-4-5-20251001 97.8% (88/90), per rep 96.7%, 100.0%, 96.7%; claude-sonnet-5 100.0% (90/90), per rep 100.0%, 100.0%, 100.0% |
| R2 | **pass** | claude-haiku-4-5-20251001 manual 97.8% vs adapter 46.7% (+51.1 points); claude-sonnet-5 manual 100.0% vs adapter 74.4% (+25.6 points) |
| R3 | **pass** | claude-haiku-4-5-20251001 manual-preloaded 93.3%, 93.3%, 93.3% (mean 93.3%, 3 reps) vs claude-sonnet-5 adapter 70.0%, 70.0%, 83.3% (mean 74.4%, 3 reps) |
| R4 | **pass** | brief 1,425 chars = 357 est. tokens; index 8,884 chars = 2,221 est. tokens (chars / 4, rounded up) |
| R5 | **pass** | total input tokens per pass: claude-haiku-4-5-20251001 adapter 1,630,385 vs manual 418,439 (3.90x); claude-sonnet-5 adapter 2,193,976 vs manual 237,831 (9.22x) |
| R6 | **pass** | p90 input tokens per question: claude-haiku-4-5-20251001 adapter 122,277 vs manual 34,894 (3.50x); claude-sonnet-5 adapter 199,506 vs manual 13,026 (15.32x) |
| R7 | **pass** | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0050 vs claude-sonnet-5 adapter $0.0864 (17.39x) |

| Condition | Model | Rep | Graded | Corrected |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 15/30 (50.0%) | 15/30 (50.0%) |
| adapter | claude-haiku-4-5-20251001 | 2 | 13/30 (43.3%) | 13/30 (43.3%) |
| adapter | claude-haiku-4-5-20251001 | 3 | 14/30 (46.7%) | 14/30 (46.7%) |
| adapter | claude-sonnet-5 | 1 | 21/30 (70.0%) | 21/30 (70.0%) |
| adapter | claude-sonnet-5 | 2 | 21/30 (70.0%) | 21/30 (70.0%) |
| adapter | claude-sonnet-5 | 3 | 25/30 (83.3%) | 25/30 (83.3%) |
| manual | claude-haiku-4-5-20251001 | 1 | 29/30 (96.7%) | 29/30 (96.7%) |
| manual | claude-haiku-4-5-20251001 | 2 | 30/30 (100.0%) | 30/30 (100.0%) |
| manual | claude-haiku-4-5-20251001 | 3 | 29/30 (96.7%) | 29/30 (96.7%) |
| manual | claude-sonnet-5 | 1 | 29/30 (96.7%) | 30/30 (100.0%) |
| manual | claude-sonnet-5 | 2 | 30/30 (100.0%) | 30/30 (100.0%) |
| manual | claude-sonnet-5 | 3 | 29/30 (96.7%) | 30/30 (100.0%) |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 28/30 (93.3%) | 28/30 (93.3%) |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 28/30 (93.3%) | 28/30 (93.3%) |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 28/30 (93.3%) | 28/30 (93.3%) |
| manual-preloaded | claude-sonnet-5 | 1 | 28/30 (93.3%) | 29/30 (96.7%) |
| raw | claude-haiku-4-5-20251001 | 1 | 15/30 (50.0%) | 15/30 (50.0%) |
| raw | claude-sonnet-5 | 1 | 25/30 (83.3%) | 25/30 (83.3%) |

| Question | Condition | Model | Rep | Why it is correct |
|---|---|---|---|---|
| df-01 | manual | claude-sonnet-5 | 1 | Says themes/vwf-classic/footer.php pulls the number from the ACF option field company_phone, edited at the Company Settings admin page (admin.php?page=company-settings). Company details, the group it names, is the field group truth records on that page. acf_option:company_phone is the manual's id for that option read, not a claim about the PHP call, so it does not contradict get_field with 'option' |
| df-01 | manual | claude-sonnet-5 | 3 | Says themes/vwf-classic/footer.php outputs the ACF option field company_phone from the Company Settings options page, edited at admin.php?page=company-settings. Company details, the group it names, is the field group truth records on that page |
| df-01 | manual-preloaded | claude-sonnet-5 | 1 | Says footer.php pulls the number from the ACF option field company_phone on the Company Settings options page, edited at admin.php?page=company-settings, and that it is not hard-coded. Company details, the group it names, is the field group truth records on that page |

## Prompt caching (manual-preloaded)

The brief and index sit in a system block marked `cache_control: ephemeral`. The first-call input is everything the first model call read: tools, system prompt, manual and question.

| Model | Minimum cacheable | First-call input median | Cache writes | Cache reads | Runs reading cache | Engaged |
|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | 4,096 | 4,225 | 170,049 | 1,096,009 | 87/90 | yes |
| claude-sonnet-5 | 1,024 | 5,002 | 16,468 | 183,054 | 29/30 | yes |

- claude-haiku-4-5-20251001: cached. 1,096,009 tokens came from the cache at the cache-read rate in 87 of 90 runs, and 170,049 were written at the cache-write rate.
- claude-sonnet-5: cached. 183,054 tokens came from the cache at the cache-read rate in 29 of 30 runs, and 16,468 were written at the cache-write rate.

Costs below are what the API billed: uncached input at the input rate, cache writes at 1.25x and cache reads at 0.1x of it (pricing.json). Prompts were not padded to reach a minimum.

## Conversation caching

Every model call put a cache breakpoint on its latest message (VOY-1129), so each call read the conversation before its newest turn from the cache once the prefix cleared the model's minimum. Input total counts uncached input, cache writes and cache reads; the read share is the part billed at the cache-read rate. Costs in this report are on that basis and are not comparable with runs 1 and 2, which cached only the preloaded manual.

| Condition | Model | Runs | Input total | Uncached | Cache writes | Cache reads | Read share | Runs reading cache |
|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 90 | 4,891,154 | 584,532 | 430,445 | 3,876,177 | 79.2% | 67/90 |
| adapter | claude-sonnet-5 | 90 | 6,581,929 | 1,062 | 1,342,895 | 5,237,972 | 79.6% | 90/90 |
| manual | claude-haiku-4-5-20251001 | 90 | 1,255,318 | 417,235 | 263,388 | 574,695 | 45.8% | 38/90 |
| manual | claude-sonnet-5 | 90 | 713,494 | 448 | 341,126 | 371,920 | 52.1% | 90/90 |
| manual-preloaded | claude-haiku-4-5-20251001 | 90 | 1,267,143 | 1,085 | 170,049 | 1,096,009 | 86.5% | 87/90 |
| manual-preloaded | claude-sonnet-5 | 30 | 199,596 | 74 | 16,468 | 183,054 | 91.7% | 29/30 |
| raw | claude-haiku-4-5-20251001 | 30 | 1,405,002 | 171,674 | 239,099 | 994,229 | 70.8% | 16/30 |
| raw | claude-sonnet-5 | 30 | 976,042 | 24,356 | 321,241 | 630,445 | 64.6% | 19/30 |

## Run

Run in GitHub Actions (bench.yml) against a fixture site the workflow built and seeded. 6 concurrent question runs, so wall times include queueing at the site. Harness and plugin at commit `1cb750a6d2c79c42fe691329ff6d464631181234`. At most 12 model calls per question. Question set questions/fixture-classic.yaml, 30 questions, sha256 `4511d7c994aabf5bd16fe64a880ef7dd06bebc2202ced544d78a30bd3b02f9ce`. Pricing from https://platform.claude.com/docs/en/about-claude/pricing, fetched 2026-10-01. Models: claude-haiku-4-5-20251001 {"temperature":0}, claude-sonnet-5 {}.

## Accuracy

| Condition | Model | Rep | Correct | Accuracy |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 15/30 | 50.0% |
| adapter | claude-haiku-4-5-20251001 | 2 | 13/30 | 43.3% |
| adapter | claude-haiku-4-5-20251001 | 3 | 14/30 | 46.7% |
| adapter | claude-sonnet-5 | 1 | 21/30 | 70.0% |
| adapter | claude-sonnet-5 | 2 | 21/30 | 70.0% |
| adapter | claude-sonnet-5 | 3 | 25/30 | 83.3% |
| manual | claude-haiku-4-5-20251001 | 1 | 29/30 | 96.7% |
| manual | claude-haiku-4-5-20251001 | 2 | 30/30 | 100.0% |
| manual | claude-haiku-4-5-20251001 | 3 | 29/30 | 96.7% |
| manual | claude-sonnet-5 | 1 | 29/30 | 96.7% |
| manual | claude-sonnet-5 | 2 | 30/30 | 100.0% |
| manual | claude-sonnet-5 | 3 | 29/30 | 96.7% |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 28/30 | 93.3% |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 28/30 | 93.3% |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 28/30 | 93.3% |
| manual-preloaded | claude-sonnet-5 | 1 | 28/30 | 93.3% |
| raw | claude-haiku-4-5-20251001 | 1 | 15/30 | 50.0% |
| raw | claude-sonnet-5 | 1 | 25/30 | 83.3% |

## Accuracy by category

Pooled over repetitions.

| Condition | Model | Category | Correct | Accuracy |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | change_impact | 1/9 | 11.1% |
| adapter | claude-haiku-4-5-20251001 | content_model | 12/18 | 66.7% |
| adapter | claude-haiku-4-5-20251001 | data_flow | 3/15 | 20.0% |
| adapter | claude-haiku-4-5-20251001 | edit_location | 11/21 | 52.4% |
| adapter | claude-haiku-4-5-20251001 | layout_routes | 9/18 | 50.0% |
| adapter | claude-haiku-4-5-20251001 | settings_integrations | 6/9 | 66.7% |
| adapter | claude-sonnet-5 | change_impact | 7/9 | 77.8% |
| adapter | claude-sonnet-5 | content_model | 16/18 | 88.9% |
| adapter | claude-sonnet-5 | data_flow | 3/15 | 20.0% |
| adapter | claude-sonnet-5 | edit_location | 15/21 | 71.4% |
| adapter | claude-sonnet-5 | layout_routes | 17/18 | 94.4% |
| adapter | claude-sonnet-5 | settings_integrations | 9/9 | 100.0% |
| manual | claude-haiku-4-5-20251001 | change_impact | 9/9 | 100.0% |
| manual | claude-haiku-4-5-20251001 | content_model | 18/18 | 100.0% |
| manual | claude-haiku-4-5-20251001 | data_flow | 15/15 | 100.0% |
| manual | claude-haiku-4-5-20251001 | edit_location | 20/21 | 95.2% |
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
| manual-preloaded | claude-haiku-4-5-20251001 | data_flow | 15/15 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | edit_location | 15/21 | 71.4% |
| manual-preloaded | claude-haiku-4-5-20251001 | layout_routes | 18/18 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | settings_integrations | 9/9 | 100.0% |
| manual-preloaded | claude-sonnet-5 | change_impact | 3/3 | 100.0% |
| manual-preloaded | claude-sonnet-5 | content_model | 6/6 | 100.0% |
| manual-preloaded | claude-sonnet-5 | data_flow | 4/5 | 80.0% |
| manual-preloaded | claude-sonnet-5 | edit_location | 6/7 | 85.7% |
| manual-preloaded | claude-sonnet-5 | layout_routes | 6/6 | 100.0% |
| manual-preloaded | claude-sonnet-5 | settings_integrations | 3/3 | 100.0% |
| raw | claude-haiku-4-5-20251001 | change_impact | 1/3 | 33.3% |
| raw | claude-haiku-4-5-20251001 | content_model | 5/6 | 83.3% |
| raw | claude-haiku-4-5-20251001 | data_flow | 1/5 | 20.0% |
| raw | claude-haiku-4-5-20251001 | edit_location | 2/7 | 28.6% |
| raw | claude-haiku-4-5-20251001 | layout_routes | 4/6 | 66.7% |
| raw | claude-haiku-4-5-20251001 | settings_integrations | 2/3 | 66.7% |
| raw | claude-sonnet-5 | change_impact | 3/3 | 100.0% |
| raw | claude-sonnet-5 | content_model | 5/6 | 83.3% |
| raw | claude-sonnet-5 | data_flow | 3/5 | 60.0% |
| raw | claude-sonnet-5 | edit_location | 5/7 | 71.4% |
| raw | claude-sonnet-5 | layout_routes | 6/6 | 100.0% |
| raw | claude-sonnet-5 | settings_integrations | 3/3 | 100.0% |

## Tokens and tool calls per question

Input counts uncached input, cache writes and cache reads together.

| Condition | Model | Rep | Input median | Input p90 | Total median | Total p90 | Tool calls median | Tool calls p90 | Wall median (s) |
|---|---|---|---|---|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | 42579.5 | 114505 | 43310 | 115736 | 8.5 | 13 | 12.6 |
| adapter | claude-haiku-4-5-20251001 | 2 | 42579.5 | 122277 | 43310 | 123586 | 8.5 | 15 | 12.2 |
| adapter | claude-haiku-4-5-20251001 | 3 | 42579.5 | 116254 | 43310 | 117503 | 8.5 | 13 | 11.0 |
| adapter | claude-sonnet-5 | 1 | 17889.5 | 201591 | 18392.5 | 204788 | 4 | 21 | 6.9 |
| adapter | claude-sonnet-5 | 2 | 21061.5 | 231817 | 21608.5 | 235887 | 4 | 18 | 7.8 |
| adapter | claude-sonnet-5 | 3 | 14209.5 | 191669 | 14728 | 194646 | 4.5 | 19 | 7.6 |
| manual | claude-haiku-4-5-20251001 | 1 | 9460.5 | 33398 | 9679.5 | 34096 | 2.5 | 7 | 3.5 |
| manual | claude-haiku-4-5-20251001 | 2 | 9624 | 38374 | 9890 | 39195 | 3 | 8 | 3.6 |
| manual | claude-haiku-4-5-20251001 | 3 | 10141 | 33733 | 10376.5 | 34534 | 3 | 8 | 3.5 |
| manual | claude-sonnet-5 | 1 | 5943 | 13026 | 6066 | 13512 | 1 | 4 | 2.6 |
| manual | claude-sonnet-5 | 2 | 5950.5 | 10993 | 6071 | 11265 | 1 | 3 | 2.4 |
| manual | claude-sonnet-5 | 3 | 5949.5 | 10123 | 6081.5 | 10248 | 1 | 3 | 2.6 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 9804.5 | 26167 | 10030.5 | 26540 | 1 | 6 | 2.5 |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 9979 | 29205 | 10216.5 | 29711 | 1.5 | 6 | 2.4 |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 9979 | 29205 | 10216.5 | 29711 | 1.5 | 6 | 2.4 |
| manual-preloaded | claude-sonnet-5 | 1 | 5004.5 | 10463 | 5096.5 | 10870 | 0 | 1 | 1.4 |
| raw | claude-haiku-4-5-20251001 | 1 | 25389 | 110357 | 25969 | 111641 | 6 | 17 | 9.6 |
| raw | claude-sonnet-5 | 1 | 11631.5 | 75522 | 12184 | 77533 | 3.5 | 15 | 5.2 |

## Cost

| Condition | Model | Rep | Per question | Total |
|---|---|---|---|---|
| adapter | claude-haiku-4-5-20251001 | 1 | $0.0255 | $0.7661 |
| adapter | claude-haiku-4-5-20251001 | 2 | $0.0188 | $0.5639 |
| adapter | claude-haiku-4-5-20251001 | 3 | $0.0176 | $0.5267 |
| adapter | claude-sonnet-5 | 1 | $0.0690 | $2.0692 |
| adapter | claude-sonnet-5 | 2 | $0.0662 | $1.9860 |
| adapter | claude-sonnet-5 | 3 | $0.0578 | $1.7340 |
| manual | claude-haiku-4-5-20251001 | 1 | $0.0118 | $0.3526 |
| manual | claude-haiku-4-5-20251001 | 2 | $0.0109 | $0.3276 |
| manual | claude-haiku-4-5-20251001 | 3 | $0.0091 | $0.2740 |
| manual | claude-sonnet-5 | 1 | $0.0149 | $0.4470 |
| manual | claude-sonnet-5 | 2 | $0.0105 | $0.3137 |
| manual | claude-sonnet-5 | 3 | $0.0116 | $0.3480 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | $0.0087 | $0.2609 |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | $0.0027 | $0.0806 |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | $0.0025 | $0.0758 |
| manual-preloaded | claude-sonnet-5 | 1 | $0.0041 | $0.1242 |
| raw | claude-haiku-4-5-20251001 | 1 | $0.0226 | $0.6781 |
| raw | claude-sonnet-5 | 1 | $0.0420 | $1.2590 |

Agent total $12.1874. Judge total $0.0552. 437/540 correct.
