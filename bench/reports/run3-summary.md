# Wayfinder benchmark run 3: summary

Run 3 judges R1 to R7 from the spec on 2 fixtures (block, classic), against the adapter-only baseline. Each claim reads "on both fixtures", so a rule passes only where it passes on every fixture. Per-fixture verdicts and numbers come from each fixture's own report; this summary computes nothing else. 1260 question runs, $27.41 in agent calls and $0.18 for the judge. Conversation caching was on in every condition, so these costs are not comparable with runs 1 and 2.

## Hypotheses

|  | Claim | block | classic | Result |
|---|---|---|---|---|
| R1 | The manual condition reaches at least 90% accuracy on the block fixture and at least 85% on the classic fixture, on both models | pass | pass | **pass** |
| R2 | The manual condition beats adapter-only by at least 10 percentage points of accuracy, on both fixtures and both models | fail | pass | **fail** |
| R3 | Haiku with the preloaded manual matches or beats Sonnet adapter-only on accuracy (mean over repetitions), on both fixtures | pass | pass | **pass** |
| R4 | The brief is at most 600 and the index at most 4,000 estimated tokens, on both fixtures | pass | pass | **pass** |
| R5 | Total input tokens across all questions in the manual condition are at least 3x lower than adapter-only, on both fixtures and both models | pass | pass | **pass** |
| R6 | p90 input tokens per question in the manual condition are at least 3x lower than adapter-only p90, on both fixtures and both models | pass | pass | **pass** |
| R7 | Haiku with the preloaded manual costs at least 10x less per correct answer than Sonnet adapter-only, on both fixtures | pass | pass | **pass** |

## Numbers

### block

|  | Result | Numbers |
|---|---|---|
| R1 | pass | manual: claude-haiku-4-5-20251001 97.5% (117/120), per rep 97.5%, 97.5%, 97.5%; claude-sonnet-5 94.2% (113/120), per rep 92.5%, 97.5%, 92.5% |
| R2 | fail | claude-haiku-4-5-20251001 manual 97.5% vs adapter 54.2% (+43.3 points); claude-sonnet-5 manual 94.2% vs adapter 88.3% (+5.8 points) |
| R3 | pass | claude-haiku-4-5-20251001 manual-preloaded 95.0%, 95.0%, 95.0% (mean 95.0%, 3 reps) vs claude-sonnet-5 adapter 87.5%, 85.0%, 92.5% (mean 88.3%, 3 reps) |
| R4 | pass | brief 1,790 chars = 448 est. tokens; index 8,681 chars = 2,171 est. tokens (chars / 4, rounded up) |
| R5 | pass | total input tokens per pass: claude-haiku-4-5-20251001 adapter 1,656,815 vs manual 449,334 (3.69x); claude-sonnet-5 adapter 2,003,938 vs manual 407,298 (4.92x) |
| R6 | pass | p90 input tokens per question: claude-haiku-4-5-20251001 adapter 106,770 vs manual 17,132 (6.23x); claude-sonnet-5 adapter 148,947 vs manual 13,016 (11.44x) |
| R7 | pass | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0026 vs claude-sonnet-5 adapter $0.0600 (23.13x) |

- median-input: claude-haiku-4-5-20251001 adapter 16,072 vs manual 6,008 (2.68x); claude-haiku-4-5-20251001 raw 17,720 vs manual 6,008 (2.95x); claude-sonnet-5 adapter 15,505 vs manual 6,157 (2.52x); claude-sonnet-5 raw 12,811 vs manual 6,157 (2.08x). Median input tokens per question, baseline against manual. Retired as a hypothesis after runs 1 and 2: on easy questions a baseline agent finishes cheaply, and the manual's advantage sits in the long tail, which R6 measures.

### classic

|  | Result | Numbers |
|---|---|---|
| R1 | pass | manual: claude-haiku-4-5-20251001 92.2% (83/90), per rep 93.3%, 93.3%, 90.0%; claude-sonnet-5 97.8% (88/90), per rep 100.0%, 96.7%, 96.7% |
| R2 | pass | claude-haiku-4-5-20251001 manual 92.2% vs adapter 48.9% (+43.3 points); claude-sonnet-5 manual 97.8% vs adapter 71.1% (+26.7 points) |
| R3 | pass | claude-haiku-4-5-20251001 manual-preloaded 90.0%, 90.0%, 86.7% (mean 88.9%, 3 reps) vs claude-sonnet-5 adapter 76.7%, 70.0%, 66.7% (mean 71.1%, 3 reps) |
| R4 | pass | brief 1,203 chars = 301 est. tokens; index 8,370 chars = 2,093 est. tokens (chars / 4, rounded up) |
| R5 | pass | total input tokens per pass: claude-haiku-4-5-20251001 adapter 1,823,588 vs manual 427,523 (4.27x); claude-sonnet-5 adapter 2,535,547 vs manual 236,208 (10.73x) |
| R6 | pass | p90 input tokens per question: claude-haiku-4-5-20251001 adapter 129,575 vs manual 37,464 (3.46x); claude-sonnet-5 adapter 224,131 vs manual 14,509 (15.45x) |
| R7 | pass | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0094 vs claude-sonnet-5 adapter $0.1036 (11.05x) |

- median-input: claude-haiku-4-5-20251001 adapter 50,991 vs manual 9,151 (5.57x); claude-haiku-4-5-20251001 raw 19,147 vs manual 9,151 (2.09x); claude-sonnet-5 adapter 26,255 vs manual 5,750 (4.57x); claude-sonnet-5 raw 12,698 vs manual 5,750 (2.21x). Median input tokens per question, baseline against manual. Retired as a hypothesis after runs 1 and 2: on easy questions a baseline agent finishes cheaply, and the manual's advantage sits in the long tail, which R6 measures.

## Losses

Every comparison on the same model where a baseline cell (raw or adapter) did better than a Wayfinder cell (manual or manual-preloaded), on either fixture. Computed by `findLosses` in `src/report.ts`, committed before the run.

### block

| Model | Metric | Baseline | Wayfinder cell |
|---|---|---|---|
| claude-haiku-4-5-20251001 | accuracy, change_impact | adapter 83.3% (10/12) | manual-preloaded 75.0% (9/12) |
| claude-sonnet-5 | accuracy, settings_integrations | raw 100.0% (4/4) | manual 83.3% (10/12) |
| claude-sonnet-5 | accuracy, settings_integrations | adapter 100.0% (12/12) | manual 83.3% (10/12) |
| claude-sonnet-5 | accuracy, edit_location | adapter 93.3% (28/30) | manual-preloaded 90.0% (9/10) |

### classic

None. No baseline cell did better than a Wayfinder cell on any metric listed.

## Reports

| Fixture | Report | Question runs | Correct | Agent cost | Commit | Question set sha256 |
|---|---|---|---|---|---|---|
| block | `reports/fixture-2026-10-03-run3.json` | 720 | 609 | $14.03 | `66fef91bd2d8` | `e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b` |
| classic | `reports/fixture-classic-2026-10-03-run3.json` | 540 | 428 | $13.39 | `66fef91bd2d8` | `4511d7c994aabf5bd16fe64a880ef7dd06bebc2202ced544d78a30bd3b02f9ce` |

## Notes, written after the run

These add context and change no number above. Questions, answer keys and graders were not changed after results existed.

- **Losses.** Block: four category-level losses, listed above. Haiku adapter beat Haiku manual-preloaded on change_impact (10/12 against 9/12). Sonnet raw and Sonnet adapter beat Sonnet manual on settings_integrations (4/4 and 12/12 against 10/12). Sonnet adapter beat Sonnet manual-preloaded on edit_location (28/30 against 9/10). No baseline beat a Wayfinder cell on overall accuracy, tokens or cost per correct answer on either fixture. Classic: none.
- **R2 fails on one cell.** On the block fixture Sonnet adapter scored 88.3% (106/120) against Sonnet manual's 94.2% (113/120), a 5.8-point gain where R2 asks for 10. Every other model and fixture pair clears it by 26.7 to 43.3 points.
- **The 12-turn cap decides most baseline misses.** Every condition gets the same 12 model calls, as in runs 1 and 2, and a run that reaches the cap with no answer counts as wrong. Adapter misses that hit the cap: block Haiku 30 of 55, block Sonnet 8 of 14, classic Haiku 35 of 46, classic Sonnet 19 of 26. Manual hit it 4 times on block (3 Haiku, 1 Sonnet) and never on classic. A higher cap might let the baseline answer more of those questions, at more tokens; run 3 does not measure that.
- **The adapter baseline is not uniformly stronger than raw.** Accuracy, adapter against raw: block Haiku 54.2% against 60.0%, block Sonnet 88.3% against 82.5%, classic Haiku 48.9% against 46.7%, classic Sonnet 71.1% against 86.7%. R2, R3, R5, R6 and R7 compare against adapter, as the spec says. Against raw, block Sonnet manual's gain would be 11.7 points.
- **A grader pattern, filed for triage and not applied.** On block el-08 ("Where do I change the starting price shown for Drain cleaning?") the judge failed 7 replies that gave the service's own edit screen, `post.php?post=90020&action=edit`, because they did not name Services or `edit.php?post_type=service`. All seven final replies also name the price_from field (a case-insensitive match on `price from` or `price_from` in the transcripts). They are 2 manual Sonnet, 1 manual-preloaded Sonnet, 3 adapter Haiku and 1 adapter Sonnet. Counting all seven correct changes no verdict: block R2 for Sonnet would move from +5.8 to +6.7 points.
- **Caching.** Haiku's preloaded prefix on the classic fixture is under its 4,096-token minimum (first call median 4,041), so its system breakpoint alone would not cache. The conversation breakpoint did, once a conversation grew: 67 of 90 Haiku manual-preloaded runs read the cache. On block it cached in 120 of 120. Sonnet read from the cache in every cell, and in every one of its manual and adapter runs on both fixtures.
- **Commit.** Both fixtures ran the harness and plugin at `66fef91`, pushed before the run. The classic report reads `66fef91…-dirty` because the block run had just rewritten its own `fixture-latest.json` and `.md`, the only tracked files that differed (`git diff --name-only` after the run). No harness, plugin, fixture or question file differed.
- **Smoke check.** Before the run, one question (cm-01) went through all 18 block passes to check that the API accepted the conversation cache breakpoint. It is not in either report or in the transcript release.
- **Time.** Block took 18 minutes and classic 22, at 6 concurrent question runs, on a local wp-env site.

## Best baseline per cell

Added after the turn cap sensitivity run. It changes no number above. For every fixture and model it takes the higher of raw and adapter, at 12 turns from the run 3 reports and at 24 turns from the sensitivity reports, and sets the Wayfinder cells beside it. Choosing the stronger baseline per cell after both were seen gives the baseline every advantage, so a rule that still passes does not depend on which baseline was named before the run. Accuracy pools repetitions, which equals the mean over repetitions because every repetition asks the whole question set: 3 for adapter, manual and Haiku manual-preloaded at 12 turns, 1 for raw, Sonnet manual-preloaded and every cell at 24. Computed by `renderBestBaseline` in `src/sensitivity.ts`.

- block: the Wayfinder cells are run 3's, at 12 turns on local wp-env, and reached the cap in 5 of 400 runs. The baseline cells at 24 turns ran on GitHub Actions runner, wp-env and still reached the cap in: adapter claude-haiku-4-5-20251001 2 of 40 (5.0%), adapter claude-sonnet-5 1 of 40 (2.5%), raw claude-haiku-4-5-20251001 1 of 40 (2.5%), raw claude-sonnet-5 0 of 40 (0.0%).
- classic: the Wayfinder cells are run 3's, at 12 turns on local wp-env, and reached the cap in 0 of 300 runs. The baseline cells at 24 turns ran on GitHub Actions runner, wp-env and still reached the cap in: adapter claude-haiku-4-5-20251001 8 of 30 (26.7%), adapter claude-sonnet-5 1 of 30 (3.3%), raw claude-haiku-4-5-20251001 6 of 30 (20.0%), raw claude-sonnet-5 0 of 30 (0.0%).

| Fixture | Model | Raw at 12 | Adapter at 12 | Best at 12 | Raw at 24 | Adapter at 24 | Best at 24 | Manual | Manual-preloaded |
|---|---|---|---|---|---|---|---|---|---|
| block | claude-haiku-4-5-20251001 | 60.0% | 54.2% | raw 60.0% | 65.0% | 62.5% | raw 65.0% | 97.5% | 95.0% |
| block | claude-sonnet-5 | 82.5% | 88.3% | adapter 88.3% | 85.0% | 87.5% | adapter 87.5% | 94.2% | 92.5% |
| classic | claude-haiku-4-5-20251001 | 46.7% | 48.9% | adapter 48.9% | 56.7% | 56.7% | raw and adapter 56.7% | 92.2% | 88.9% |
| classic | claude-sonnet-5 | 86.7% | 71.1% | raw 86.7% | 90.0% | 80.0% | raw 90.0% | 97.8% | 96.7% |

### R2 against the best baseline

R2 asks the manual condition to beat the baseline by at least 10 percentage points of accuracy on both fixtures and both models.

| Fixture | Model | Manual | Best at 12 | Gain | Best at 24 | Gain |
|---|---|---|---|---|---|---|
| block | claude-haiku-4-5-20251001 | 97.5% | raw 60.0% | +37.5 | raw 65.0% | +32.5 |
| block | claude-sonnet-5 | 94.2% | adapter 88.3% | +5.8 | adapter 87.5% | +6.7 |
| classic | claude-haiku-4-5-20251001 | 92.2% | adapter 48.9% | +43.3 | raw and adapter 56.7% | +35.6 |
| classic | claude-sonnet-5 | 97.8% | raw 86.7% | +11.1 | raw 90.0% | +7.8 |

Against the best baseline at 12 turns: **fail**. At 24 turns: **fail**.

### R3 against the best baseline

R3 asks claude-haiku-4-5-20251001 manual-preloaded to match or beat claude-sonnet-5 on the baseline, on both fixtures.

| Fixture | claude-haiku-4-5-20251001 manual-preloaded | claude-sonnet-5 best at 12 | Result | claude-sonnet-5 best at 24 | Result |
|---|---|---|---|---|---|
| block | 95.0% | adapter 88.3% | pass | adapter 87.5% | pass |
| classic | 88.9% | raw 86.7% | pass | raw 90.0% | fail |

Against the best baseline at 12 turns: **pass**. At 24 turns: **fail**.
