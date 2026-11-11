# Wayfinder benchmark: fixture, 2026-10-03

## Hypotheses

|  | Claim | Result | Numbers |
|---|---|---|---|
| H1 | The manual condition reaches 90.0% accuracy or better, on every model | **pass** | manual: claude-haiku-4-5-20251001 92.5% (37/40); claude-sonnet-5 92.5% (37/40). For reference, manual-preloaded: claude-haiku-4-5-20251001 96.7% (116/120); claude-sonnet-5 92.5% (37/40) |
| H2 | The manual condition reads at least 5x fewer input tokens per question than raw (median), on every model | **fail** | median input tokens per question: claude-haiku-4-5-20251001 raw 15,241 vs manual 9,639 (1.58x); claude-sonnet-5 raw 13,297 vs manual 6,140 (2.17x). For reference: claude-haiku-4-5-20251001 raw 15,241 vs manual-preloaded 9,249 (1.65x); claude-sonnet-5 raw 13,297 vs manual-preloaded 5,399 (2.46x) |
| H2b | Across all questions, total input tokens in the manual condition are at least 3x lower than raw, on every model | **pass** | total input tokens per pass: claude-haiku-4-5-20251001 raw 1,779,648 vs manual 448,590 (3.97x); claude-sonnet-5 raw 1,704,925 vs manual 325,664 (5.24x) |
| H3 | claude-haiku-4-5-20251001 manual-preloaded matches or beats claude-sonnet-5 raw on accuracy (mean over repetitions) | **pass** | claude-haiku-4-5-20251001 manual-preloaded 97.5%, 95.0%, 97.5% (mean 96.7%, 3 reps) vs claude-sonnet-5 raw 82.5%, 82.5%, 87.5% (mean 84.2%, 3 reps) |
| H4 | On the fixture the brief is at most 600 estimated tokens and the index at most 4,000 | **pass** | brief 1,684 chars = 421 est. tokens; index 8,622 chars = 2,156 est. tokens (chars / 4, rounded up) |
| H5 | p90 input tokens per question in the manual condition are at least 3x lower than the raw p90, on every model | **pass** | p90 input tokens per question: claude-haiku-4-5-20251001 raw 111,950 vs manual 14,762 (7.58x); claude-sonnet-5 raw 124,768 vs manual 12,211 (10.22x) |
| H6 | claude-haiku-4-5-20251001 manual-preloaded costs at least 5x less per correct answer than claude-sonnet-5 raw | **pass** | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0036 vs claude-sonnet-5 raw $0.1118 (30.76x) |

## Most-missed questions

Per condition, over every model and repetition, as the graders scored them. The reason is the grader's, from the first miss.

### manual

| Question | Category | Missed | Reason |
|---|---|---|---|
| df-01 | data_flow | 2/2 | claude-haiku-4-5-20251001: The answer correctly identifies the binding source (Business info: phone), the option name (vwf_phone), and the settings page, but gives the admin path as 'options-general.php?page=vwf-business-info' when the expected path is 'Settings > Business Info' (which corresponds … |
| df-05 | data_flow | 2/2 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| el-08 | edit_location | 2/2 | claude-haiku-4-5-20251001: The answer provides a specific post edit URL but fails to state the complete path to access the service (Services menu in wp-admin via edit.php?post_type=service) and does not explicitly confirm changing the price_from field for the Drain cleaning service.; answered: post… |

### manual-preloaded

| Question | Category | Missed | Reason |
|---|---|---|---|
| df-05 | data_flow | 4/4 | claude-haiku-4-5-20251001: The answer fails to identify 'hours' as the business info value that is set up but not shown on the front end; the expected data clearly shows hours has an empty 'used_in' array while address and phone are used in the footer.; answered: Based on the Wayfinder index, both … |
| ci-04 | change_impact | 1/4 | claude-sonnet-5: expected "6"; answered: 6-20 service pages (all published Services, via tpl:single-service) |
| cm-07 | content_model | 1/4 | claude-sonnet-5: expected "6"; answered: 6-20 |

### raw

| Question | Category | Missed | Reason |
|---|---|---|---|
| cm-02 | content_model | 4/4 | claude-haiku-4-5-20251001: expected {duration, internal_sku, price_from}; answered: id, date, date_gmt, guid, modified, modified_gmt, slug, status, type, link, title, content, excerpt, featured_media, te… |
| cm-03 | content_model | 4/4 | claude-haiku-4-5-20251001: turn cap reached with no answer |
| df-05 | data_flow | 4/4 | claude-haiku-4-5-20251001: turn cap reached with no answer |

## Prompt caching (manual-preloaded)

The brief and index sit in a system block marked `cache_control: ephemeral`. The first-call input is everything the first model call read: tools, system prompt, manual and question.

| Model | Minimum cacheable | First-call input median | Cache writes | Cache reads | Runs reading cache | Engaged |
|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | 4,096 | 4,465 | 4,129 | 1,085,927 | 120/120 | yes |
| claude-sonnet-5 | 1,024 | 5,399 | 10,616 | 265,400 | 38/40 | yes |

- claude-haiku-4-5-20251001: cached. 1,085,927 tokens came from the cache at the cache-read rate in 120 of 120 runs, and 4,129 were written at the cache-write rate.
- claude-sonnet-5: cached. 265,400 tokens came from the cache at the cache-read rate in 38 of 40 runs, and 10,616 were written at the cache-write rate.

Costs below are what the API billed: uncached input at the input rate, cache writes at 1.25x and cache reads at 0.1x of it (pricing.json). Prompts were not padded to reach a minimum.

## Run

Run locally against a freshly seeded wp-env fixture site. 6 concurrent question runs, so wall times include queueing at the site. Question set questions/fixture.yaml, 40 questions, sha256 `e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b`. Pricing from https://platform.claude.com/docs/en/about-claude/pricing, fetched 2026-10-01. Models: claude-haiku-4-5-20251001 {"temperature":0}, claude-sonnet-5 {}.

## Accuracy

| Condition | Model | Rep | Correct | Accuracy |
|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 1 | 37/40 | 92.5% |
| manual | claude-sonnet-5 | 1 | 37/40 | 92.5% |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 39/40 | 97.5% |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 38/40 | 95.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 39/40 | 97.5% |
| manual-preloaded | claude-sonnet-5 | 1 | 37/40 | 92.5% |
| raw | claude-haiku-4-5-20251001 | 1 | 25/40 | 62.5% |
| raw | claude-sonnet-5 | 1 | 33/40 | 82.5% |
| raw | claude-sonnet-5 | 2 | 33/40 | 82.5% |
| raw | claude-sonnet-5 | 3 | 35/40 | 87.5% |

## Accuracy by category

Pooled over repetitions.

| Condition | Model | Category | Correct | Accuracy |
|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | change_impact | 4/4 | 100.0% |
| manual | claude-haiku-4-5-20251001 | content_model | 8/8 | 100.0% |
| manual | claude-haiku-4-5-20251001 | data_flow | 4/6 | 66.7% |
| manual | claude-haiku-4-5-20251001 | edit_location | 9/10 | 90.0% |
| manual | claude-haiku-4-5-20251001 | layout_routes | 8/8 | 100.0% |
| manual | claude-haiku-4-5-20251001 | settings_integrations | 4/4 | 100.0% |
| manual | claude-sonnet-5 | change_impact | 4/4 | 100.0% |
| manual | claude-sonnet-5 | content_model | 8/8 | 100.0% |
| manual | claude-sonnet-5 | data_flow | 4/6 | 66.7% |
| manual | claude-sonnet-5 | edit_location | 9/10 | 90.0% |
| manual | claude-sonnet-5 | layout_routes | 8/8 | 100.0% |
| manual | claude-sonnet-5 | settings_integrations | 4/4 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | change_impact | 12/12 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | content_model | 24/24 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | data_flow | 14/18 | 77.8% |
| manual-preloaded | claude-haiku-4-5-20251001 | edit_location | 30/30 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | layout_routes | 24/24 | 100.0% |
| manual-preloaded | claude-haiku-4-5-20251001 | settings_integrations | 12/12 | 100.0% |
| manual-preloaded | claude-sonnet-5 | change_impact | 3/4 | 75.0% |
| manual-preloaded | claude-sonnet-5 | content_model | 7/8 | 87.5% |
| manual-preloaded | claude-sonnet-5 | data_flow | 5/6 | 83.3% |
| manual-preloaded | claude-sonnet-5 | edit_location | 10/10 | 100.0% |
| manual-preloaded | claude-sonnet-5 | layout_routes | 8/8 | 100.0% |
| manual-preloaded | claude-sonnet-5 | settings_integrations | 4/4 | 100.0% |
| raw | claude-haiku-4-5-20251001 | change_impact | 4/4 | 100.0% |
| raw | claude-haiku-4-5-20251001 | content_model | 5/8 | 62.5% |
| raw | claude-haiku-4-5-20251001 | data_flow | 2/6 | 33.3% |
| raw | claude-haiku-4-5-20251001 | edit_location | 4/10 | 40.0% |
| raw | claude-haiku-4-5-20251001 | layout_routes | 6/8 | 75.0% |
| raw | claude-haiku-4-5-20251001 | settings_integrations | 4/4 | 100.0% |
| raw | claude-sonnet-5 | change_impact | 12/12 | 100.0% |
| raw | claude-sonnet-5 | content_model | 16/24 | 66.7% |
| raw | claude-sonnet-5 | data_flow | 13/18 | 72.2% |
| raw | claude-sonnet-5 | edit_location | 26/30 | 86.7% |
| raw | claude-sonnet-5 | layout_routes | 22/24 | 91.7% |
| raw | claude-sonnet-5 | settings_integrations | 12/12 | 100.0% |

## Tokens and tool calls per question

Input counts uncached input, cache writes and cache reads together.

| Condition | Model | Rep | Input median | Input p90 | Total median | Total p90 | Tool calls median | Tool calls p90 | Wall median (s) |
|---|---|---|---|---|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 1 | 9639 | 14762 | 9859.5 | 15239 | 2 | 4 | 4.7 |
| manual | claude-sonnet-5 | 1 | 6139.5 | 12211 | 6250.5 | 12541 | 1 | 3 | 3.2 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | 9252 | 14241 | 9445 | 14454 | 1 | 2 | 2.6 |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | 9248.5 | 14241 | 9430 | 14442 | 1 | 2 | 2.9 |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | 9248.5 | 15371 | 9444.5 | 15566 | 1 | 2 | 2.6 |
| manual-preloaded | claude-sonnet-5 | 1 | 5399 | 11727 | 5471.5 | 11934 | 0 | 1 | 1.3 |
| raw | claude-haiku-4-5-20251001 | 1 | 15240.5 | 111950 | 15510.5 | 112962 | 4 | 13 | 7.8 |
| raw | claude-sonnet-5 | 1 | 13681 | 97935 | 13974 | 99753 | 3 | 9 | 5.4 |
| raw | claude-sonnet-5 | 2 | 12296.5 | 149283 | 12516.5 | 152289 | 2.5 | 12 | 4.6 |
| raw | claude-sonnet-5 | 3 | 13297 | 130985 | 13515.5 | 133022 | 3 | 13 | 4.8 |

## Cost

| Condition | Model | Rep | Per question | Total |
|---|---|---|---|---|
| manual | claude-haiku-4-5-20251001 | 1 | $0.0124 | $0.4976 |
| manual | claude-sonnet-5 | 1 | $0.0184 | $0.7374 |
| manual-preloaded | claude-haiku-4-5-20251001 | 1 | $0.0035 | $0.1417 |
| manual-preloaded | claude-haiku-4-5-20251001 | 2 | $0.0035 | $0.1382 |
| manual-preloaded | claude-haiku-4-5-20251001 | 3 | $0.0035 | $0.1418 |
| manual-preloaded | claude-sonnet-5 | 1 | $0.0065 | $0.2604 |
| raw | claude-haiku-4-5-20251001 | 1 | $0.0474 | $1.8940 |
| raw | claude-sonnet-5 | 1 | $0.0869 | $3.4779 |
| raw | claude-sonnet-5 | 2 | $0.0977 | $3.9068 |
| raw | claude-sonnet-5 | 3 | $0.0978 | $3.9102 |

Agent total $15.1060. Judge total $0.0785. 353/400 correct.

## Notes on run 2

- **Same test as run 1.** 2 models x 3 conditions x 40 questions, with 3 repetitions of the two H3 cells: 400 question runs, 10 runs, run locally at concurrency 6 on a freshly seeded fixture. The question set hashes to e404e30f…, the same as run 1. Agent calls cost $15.11 and the judge $0.08. No question needed a retry.
- **Graders** are those on main at the run: VOY-1124 (labels and qualifiers), VOY-1128 (the judge takes a menu label or an admin path) and VOY-1141 (the older Site Editor menu URL counts as the same answer). H2b, H5 and H6 were added and committed (6e220f9) before any run 2 result existed.
- **What changed in the manual since run 1:**
  - **VOY-1125:** template file paths, the file that registers each post type and taxonomy, and the option each binding reads.
  - **VOY-1141:** Site Editor routes for templates, parts and menus, in the manual and in truth.json.
  - **VOY-1150 and VOY-1149:** brief edit lines and the brief budget. The fixture brief is byte-identical across those two.
- **Caching differs from run 1.** The probe on run 2's manual (`caching-probe.json`) measured Haiku's preloaded prefix at 4,129 tokens against a 4,096 minimum, so it caches. In the run, Haiku read the cached manual on 120 of 120 preloaded questions (1,085,927 cache-read tokens), and Sonnet on 38 of 40. In run 1 Haiku cached nothing, so its preloaded cost there was uncached and here it is cached. That is most of the gap between H6's run 1 figure (9.19x, re-graded) and run 2's (30.76x).
- **Two misses are grader errors, so here are both numbers.** No verdict changes either way.
  - **df-01 (VOY-1155):** the judge faulted two answers whose only stated fault was giving the admin path instead of the menu label, which the judge prompt says to accept. Manual Haiku is 37/40 as graded and 38/40 counting it. Preloaded Haiku is 116/120 as graded and 117/120 counting it.
  - **el-06 (VOY-1154):** Sonnet raw rep 2 named the Primary menu by its menu label ("Appearance > Editor > Navigation, edit the Primary menu"). Sonnet raw is 101/120 as graded and 102/120 counting it.
- **Four questions accept only the admin path:** el-02, el-06, el-07 and el-09. Truth records no menu label for those pages, and Ben decided to keep it that way for run 2 (VOY-1140, option b). el-06's answer key changed with VOY-1141. Its counts are the same in run 1, re-graded run 1 and run 2: raw 1/4, manual 2/2, manual-preloaded 4/4.
- **How run 1 was re-graded.** All 400 run 1 rows were graded again from the `bench-run1-transcripts` release, with these graders and run 1's own truth.json (from d00f4e4), so a changed answer key is not counted as a grading change. Judge rows called the judge again, for $0.07. 20 verdicts changed. See `fixture-2026-10-02-regraded.md`.
## Run 1 and run 2 side by side

| Hypothesis | Run 1 (original) | Run 1 (re-graded) | Run 2 |
|---|---|---|---|
| H1 | fail | fail | pass |
| H2 | fail | fail | fail |
| H2b | - | pass | pass |
| H3 | pass | pass | pass |
| H4 | pass | pass | pass |
| H5 | - | pass | pass |
| H6 | - | pass | pass |

Accuracy per cell, pooled over repetitions:

| Cell | Run 1 (original) | Run 1 (re-graded) | Run 2 |
|---|---|---|---|
| raw claude-haiku-4-5-20251001 | 57.5% (23/40) | 62.5% (25/40) | 62.5% (25/40) |
| raw claude-sonnet-5 | 75.0% (90/120) | 79.2% (95/120) | 84.2% (101/120) |
| manual claude-haiku-4-5-20251001 | 82.5% (33/40) | 87.5% (35/40) | 92.5% (37/40) |
| manual claude-sonnet-5 | 85.0% (34/40) | 87.5% (35/40) | 92.5% (37/40) |
| manual-preloaded claude-haiku-4-5-20251001 | 81.7% (98/120) | 88.3% (106/120) | 96.7% (116/120) |
| manual-preloaded claude-sonnet-5 | 85.0% (34/40) | 85.0% (34/40) | 92.5% (37/40) |

Input tokens per question, median / p90, pooled over repetitions:

| Cell | Run 1 (original) | Run 1 (re-graded) | Run 2 |
|---|---|---|---|
| raw claude-haiku-4-5-20251001 | 15,361 / 109,841 | 15,361 / 109,841 | 15,241 / 111,950 |
| raw claude-sonnet-5 | 13,620 / 128,960 | 13,620 / 128,960 | 13,297 / 124,768 |
| manual claude-haiku-4-5-20251001 | 9,205 / 23,022 | 9,205 / 23,022 | 9,639 / 14,762 |
| manual claude-sonnet-5 | 5,879 / 16,261 | 5,879 / 16,261 | 6,140 / 12,211 |
| manual-preloaded claude-haiku-4-5-20251001 | 8,824 / 13,574 | 8,824 / 13,574 | 9,249 / 15,371 |
| manual-preloaded claude-sonnet-5 | 5,085 / 16,841 | 5,085 / 16,841 | 5,399 / 11,727 |

