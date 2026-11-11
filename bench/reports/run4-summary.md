# Wayfinder benchmark run 4: summary

Run 4 asks the two frozen question sets of run 3 again, on plugin `1cb750a6d2c7`, and adds the edit-safety set. Same conditions, models, repetitions, 12-turn cap and conversation caching as run 3. 1620 question runs, $32.27 in agent calls and $0.20 for the judge. Per-set verdicts and numbers come from each set's own report; the comparison and best-baseline sections below recompute from those reports' results with `src/run4.ts`.

## Hypotheses, frozen sets, against adapter

R1 to R7 as the spec words them, against the adapter-only baseline named before run 3, on the block and classic sets. A rule passes only where it passes on every set.

|  | Claim | block run 3 | classic run 3 | block run 4 | classic run 4 | Run 3 | Run 4 |
|---|---|---|---|---|---|---|---|
| R1 | The manual condition reaches at least 90% accuracy on the block fixture and at least 85% on the classic fixture, on both models | pass | pass | pass | pass | pass | **pass** |
| R2 | The manual condition beats adapter-only by at least 10 percentage points of accuracy, on both fixtures and both models | fail | pass | fail | pass | fail | **fail** |
| R3 | Haiku with the preloaded manual matches or beats Sonnet adapter-only on accuracy (mean over repetitions), on both fixtures | pass | pass | pass | pass | pass | **pass** |
| R4 | The brief is at most 600 and the index at most 4,000 estimated tokens, on both fixtures | pass | pass | pass | pass | pass | **pass** |
| R5 | Total input tokens across all questions in the manual condition are at least 3x lower than adapter-only, on both fixtures and both models | pass | pass | pass | pass | pass | **pass** |
| R6 | p90 input tokens per question in the manual condition are at least 3x lower than adapter-only p90, on both fixtures and both models | pass | pass | pass | pass | pass | **pass** |
| R7 | Haiku with the preloaded manual costs at least 10x less per correct answer than Sonnet adapter-only, on both fixtures | pass | pass | pass | pass | pass | **pass** |

## Numbers

### block

|  | Result | Numbers |
|---|---|---|
| R1 | pass | manual: claude-haiku-4-5-20251001 97.5% (117/120), per rep 97.5%, 97.5%, 97.5%; claude-sonnet-5 95.0% (114/120), per rep 95.0%, 95.0%, 95.0% |
| R2 | fail | claude-haiku-4-5-20251001 manual 97.5% vs adapter 64.2% (+33.3 points); claude-sonnet-5 manual 95.0% vs adapter 87.5% (+7.5 points) |
| R3 | pass | claude-haiku-4-5-20251001 manual-preloaded 92.5%, 95.0%, 95.0% (mean 94.2%, 3 reps) vs claude-sonnet-5 adapter 87.5%, 90.0%, 85.0% (mean 87.5%, 3 reps) |
| R4 | pass | brief 1,879 chars = 470 est. tokens; index 8,885 chars = 2,222 est. tokens (chars / 4, rounded up) |
| R5 | pass | total input tokens per pass: claude-haiku-4-5-20251001 adapter 1,940,041 vs manual 484,002 (4.01x); claude-sonnet-5 adapter 2,219,143 vs manual 401,796 (5.52x) |
| R6 | pass | p90 input tokens per question: claude-haiku-4-5-20251001 adapter 123,835 vs manual 17,381 (7.12x); claude-sonnet-5 adapter 155,349 vs manual 13,331 (11.65x) |
| R7 | pass | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0025 vs claude-sonnet-5 adapter $0.0617 (25.06x) |

- median-input: claude-haiku-4-5-20251001 adapter 22,112 vs manual 9,763 (2.26x); claude-haiku-4-5-20251001 raw 16,177 vs manual 9,763 (1.66x); claude-sonnet-5 adapter 14,760 vs manual 6,253 (2.36x); claude-sonnet-5 raw 13,806 vs manual 6,253 (2.21x). Median input tokens per question, baseline against manual. Retired as a hypothesis after runs 1 and 2: on easy questions a baseline agent finishes cheaply, and the manual's advantage sits in the long tail, which R6 measures.

### classic

|  | Result | Numbers |
|---|---|---|
| R1 | pass | manual: claude-haiku-4-5-20251001 97.8% (88/90), per rep 96.7%, 100.0%, 96.7%; claude-sonnet-5 97.8% (88/90), per rep 96.7%, 100.0%, 96.7% |
| R2 | pass | claude-haiku-4-5-20251001 manual 97.8% vs adapter 46.7% (+51.1 points); claude-sonnet-5 manual 97.8% vs adapter 74.4% (+23.3 points) |
| R3 | pass | claude-haiku-4-5-20251001 manual-preloaded 93.3%, 93.3%, 93.3% (mean 93.3%, 3 reps) vs claude-sonnet-5 adapter 70.0%, 70.0%, 83.3% (mean 74.4%, 3 reps) |
| R4 | pass | brief 1,425 chars = 357 est. tokens; index 8,884 chars = 2,221 est. tokens (chars / 4, rounded up) |
| R5 | pass | total input tokens per pass: claude-haiku-4-5-20251001 adapter 1,630,385 vs manual 418,439 (3.90x); claude-sonnet-5 adapter 2,193,976 vs manual 237,831 (9.22x) |
| R6 | pass | p90 input tokens per question: claude-haiku-4-5-20251001 adapter 122,277 vs manual 34,894 (3.50x); claude-sonnet-5 adapter 199,506 vs manual 13,026 (15.32x) |
| R7 | pass | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0050 vs claude-sonnet-5 adapter $0.0864 (17.39x) |

- median-input: claude-haiku-4-5-20251001 adapter 42,580 vs manual 9,658 (4.41x); claude-haiku-4-5-20251001 raw 25,389 vs manual 9,658 (2.63x); claude-sonnet-5 adapter 16,884 vs manual 5,950 (2.84x); claude-sonnet-5 raw 11,632 vs manual 5,950 (1.95x). Median input tokens per question, baseline against manual. Retired as a hypothesis after runs 1 and 2: on easy questions a baseline agent finishes cheaply, and the manual's advantage sits in the long tail, which R6 measures.

### edit-safety

|  | Result | Numbers |
|---|---|---|
| R1 | fail | manual: claude-haiku-4-5-20251001 70.0% (42/60), per rep 70.0%, 70.0%, 70.0%; claude-sonnet-5 95.0% (57/60), per rep 95.0%, 95.0%, 95.0% |
| R2 | fail | claude-haiku-4-5-20251001 manual 70.0% vs adapter 68.3% (+1.7 points); claude-sonnet-5 manual 95.0% vs adapter 75.0% (+20.0 points) |
| R3 | fail | claude-haiku-4-5-20251001 manual-preloaded 65.0%, 70.0%, 65.0% (mean 66.7%, 3 reps) vs claude-sonnet-5 adapter 80.0%, 70.0%, 75.0% (mean 75.0%, 3 reps) |
| R4 | pass | brief 1,879 chars = 470 est. tokens; index 8,885 chars = 2,222 est. tokens (chars / 4, rounded up) |
| R5 | fail | total input tokens per pass: claude-haiku-4-5-20251001 adapter 406,647 vs manual 434,913 (0.94x); claude-sonnet-5 adapter 280,773 vs manual 294,397 (0.95x) |
| R6 | fail | p90 input tokens per question: claude-haiku-4-5-20251001 adapter 57,273 vs manual 44,470 (1.29x); claude-sonnet-5 adapter 23,808 vs manual 32,791 (0.73x) |
| R7 | fail | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0095 vs claude-sonnet-5 adapter $0.0330 (3.45x) |

- median-input: claude-haiku-4-5-20251001 adapter 10,766 vs manual 10,762 (1.00x); claude-haiku-4-5-20251001 raw 9,142 vs manual 10,762 (0.85x); claude-sonnet-5 adapter 5,825 vs manual 9,564 (0.61x); claude-sonnet-5 raw 5,097 vs manual 9,564 (0.53x). Median input tokens per question, baseline against manual. Retired as a hypothesis after runs 1 and 2: on easy questions a baseline agent finishes cheaply, and the manual's advantage sits in the long tail, which R6 measures.

## Run 3 against run 4, cell by cell

Same question set, same cell. Accuracy pools repetitions. p90 is input tokens per question, cost is agent cost per correct answer. The edit-safety set has no run 3 counterpart and is not in this section.

### block

| Model | Condition | Passes | Run 3 | Run 4 | Change (points) | p90 input, run 3 | p90 input, run 4 | Cost per correct, run 3 | Cost per correct, run 4 |
|---|---|---|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | raw | 1 | 60.0% (24/40) | 60.0% (24/40) | +0.0 | 123,589 | 116,445 | $0.0379 | $0.0374 |
| claude-haiku-4-5-20251001 | adapter | 3 | 54.2% (65/120) | 64.2% (77/120) | +10.0 | 106,770 | 123,835 | $0.0326 | $0.0302 |
| claude-haiku-4-5-20251001 | manual | 3 | 97.5% (117/120) | 97.5% (117/120) | +0.0 | 17,132 | 17,381 | $0.0060 | $0.0063 |
| claude-haiku-4-5-20251001 | manual-preloaded | 3 | 95.0% (114/120) | 94.2% (113/120) | -0.8 | 14,433 | 15,806 | $0.0026 | $0.0025 |
| claude-sonnet-5 | raw | 1 | 82.5% (33/40) | 85.0% (34/40) | +2.5 | 120,212 | 99,306 | $0.0545 | $0.0567 |
| claude-sonnet-5 | adapter | 3 | 88.3% (106/120) | 87.5% (105/120) | -0.8 | 148,947 | 155,349 | $0.0600 | $0.0617 |
| claude-sonnet-5 | manual | 3 | 94.2% (113/120) | 95.0% (114/120) | +0.8 | 13,016 | 13,331 | $0.0135 | $0.0134 |
| claude-sonnet-5 | manual-preloaded | 1 | 92.5% (37/40) | 90.0% (36/40) | -2.5 | 11,895 | 11,710 | $0.0085 | $0.0048 |

### classic

| Model | Condition | Passes | Run 3 | Run 4 | Change (points) | p90 input, run 3 | p90 input, run 4 | Cost per correct, run 3 | Cost per correct, run 4 |
|---|---|---|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | raw | 1 | 46.7% (14/30) | 50.0% (15/30) | +3.3 | 117,264 | 110,357 | $0.0481 | $0.0452 |
| claude-haiku-4-5-20251001 | adapter | 3 | 48.9% (44/90) | 46.7% (42/90) | -2.2 | 129,575 | 122,277 | $0.0457 | $0.0442 |
| claude-haiku-4-5-20251001 | manual | 3 | 92.2% (83/90) | 97.8% (88/90) | +5.6 | 37,464 | 34,894 | $0.0098 | $0.0108 |
| claude-haiku-4-5-20251001 | manual-preloaded | 3 | 88.9% (80/90) | 93.3% (84/90) | +4.4 | 25,095 | 29,205 | $0.0094 | $0.0050 |
| claude-sonnet-5 | raw | 1 | 86.7% (26/30) | 83.3% (25/30) | -3.3 | 106,069 | 75,522 | $0.0505 | $0.0504 |
| claude-sonnet-5 | adapter | 3 | 71.1% (64/90) | 74.4% (67/90) | +3.3 | 224,131 | 199,506 | $0.1036 | $0.0864 |
| claude-sonnet-5 | manual | 3 | 97.8% (88/90) | 97.8% (88/90) | +0.0 | 14,509 | 13,026 | $0.0118 | $0.0126 |
| claude-sonnet-5 | manual-preloaded | 1 | 96.7% (29/30) | 93.3% (28/30) | -3.3 | 14,769 | 10,463 | $0.0052 | $0.0044 |

## Best baseline per cell

For every set and model the best baseline is the higher of raw and adapter on accuracy, pooled over repetitions (3 passes for adapter, 1 for raw); a tie goes to the one with fewer total input tokens per pass. Choosing it after both were seen gives the baseline every advantage, so a rule that still passes does not depend on which baseline was named before the run. All cells are run 4's, at 12 turns. Computed by `judgeAgainstBest` in `src/run4.ts`.

| Set | Model | Raw | Adapter | Best baseline | Manual | Manual-preloaded |
|---|---|---|---|---|---|---|
| block | claude-haiku-4-5-20251001 | 60.0% | 64.2% | adapter 64.2% | 97.5% | 94.2% |
| block | claude-sonnet-5 | 85.0% | 87.5% | adapter 87.5% | 95.0% | 90.0% |
| classic | claude-haiku-4-5-20251001 | 50.0% | 46.7% | raw 50.0% | 97.8% | 93.3% |
| classic | claude-sonnet-5 | 83.3% | 74.4% | raw 83.3% | 97.8% | 93.3% |
| edit-safety | claude-haiku-4-5-20251001 | 65.0% | 68.3% | adapter 68.3% | 70.0% | 66.7% |
| edit-safety | claude-sonnet-5 | 70.0% | 75.0% | adapter 75.0% | 95.0% | 75.0% |

### R1 to R7 against the best baseline

Each rule is judged by `judgeRun3`, unchanged, with the best baseline per model in place of adapter-only: R2, R5 and R6 compare each model's manual cell with that model's best baseline, and R3 and R7 compare Haiku manual-preloaded with Sonnet's. R1 and R4 read no baseline and repeat the verdicts above.

|  | block | classic | edit-safety | Frozen sets (block, classic) |
|---|---|---|---|---|
| R1 | pass | pass | fail | **pass** |
| R2 | fail | pass | fail | **fail** |
| R3 | pass | pass | fail | **pass** |
| R4 | pass | pass | pass | **pass** |
| R5 | pass | pass | fail | **pass** |
| R6 | pass | pass | fail | **pass** |
| R7 | pass | pass | fail | **pass** |

#### block

|  | Result | Numbers |
|---|---|---|
| R1 | pass | manual: claude-haiku-4-5-20251001 97.5% (117/120), per rep 97.5%, 97.5%, 97.5%; claude-sonnet-5 95.0% (114/120), per rep 95.0%, 95.0%, 95.0% |
| R2 | fail | claude-haiku-4-5-20251001 manual 97.5% vs best (adapter) 64.2% (+33.3 points); claude-sonnet-5 manual 95.0% vs best (adapter) 87.5% (+7.5 points) |
| R3 | pass | claude-haiku-4-5-20251001 manual-preloaded 92.5%, 95.0%, 95.0% (mean 94.2%, 3 reps) vs claude-sonnet-5 best (adapter) 87.5%, 90.0%, 85.0% (mean 87.5%, 3 reps) |
| R4 | pass | brief 1,879 chars = 470 est. tokens; index 8,885 chars = 2,222 est. tokens (chars / 4, rounded up) |
| R5 | pass | total input tokens per pass: claude-haiku-4-5-20251001 best (adapter) 1,940,041 vs manual 484,002 (4.01x); claude-sonnet-5 best (adapter) 2,219,143 vs manual 401,796 (5.52x) |
| R6 | pass | p90 input tokens per question: claude-haiku-4-5-20251001 best (adapter) 123,835 vs manual 17,381 (7.12x); claude-sonnet-5 best (adapter) 155,349 vs manual 13,331 (11.65x) |
| R7 | pass | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0025 vs claude-sonnet-5 best (adapter) $0.0617 (25.06x) |

#### classic

|  | Result | Numbers |
|---|---|---|
| R1 | pass | manual: claude-haiku-4-5-20251001 97.8% (88/90), per rep 96.7%, 100.0%, 96.7%; claude-sonnet-5 97.8% (88/90), per rep 96.7%, 100.0%, 96.7% |
| R2 | pass | claude-haiku-4-5-20251001 manual 97.8% vs best (raw) 50.0% (+47.8 points); claude-sonnet-5 manual 97.8% vs best (raw) 83.3% (+14.4 points) |
| R3 | pass | claude-haiku-4-5-20251001 manual-preloaded 93.3%, 93.3%, 93.3% (mean 93.3%, 3 reps) vs claude-sonnet-5 best (raw) 83.3% (mean 83.3%, 1 rep) |
| R4 | pass | brief 1,425 chars = 357 est. tokens; index 8,884 chars = 2,221 est. tokens (chars / 4, rounded up) |
| R5 | pass | total input tokens per pass: claude-haiku-4-5-20251001 best (raw) 1,405,002 vs manual 418,439 (3.36x); claude-sonnet-5 best (raw) 976,042 vs manual 237,831 (4.10x) |
| R6 | pass | p90 input tokens per question: claude-haiku-4-5-20251001 best (raw) 110,357 vs manual 34,894 (3.16x); claude-sonnet-5 best (raw) 75,522 vs manual 13,026 (5.80x) |
| R7 | pass | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0050 vs claude-sonnet-5 best (raw) $0.0504 (10.14x) |

#### edit-safety

|  | Result | Numbers |
|---|---|---|
| R1 | fail | manual: claude-haiku-4-5-20251001 70.0% (42/60), per rep 70.0%, 70.0%, 70.0%; claude-sonnet-5 95.0% (57/60), per rep 95.0%, 95.0%, 95.0% |
| R2 | fail | claude-haiku-4-5-20251001 manual 70.0% vs best (adapter) 68.3% (+1.7 points); claude-sonnet-5 manual 95.0% vs best (adapter) 75.0% (+20.0 points) |
| R3 | fail | claude-haiku-4-5-20251001 manual-preloaded 65.0%, 70.0%, 65.0% (mean 66.7%, 3 reps) vs claude-sonnet-5 best (adapter) 80.0%, 70.0%, 75.0% (mean 75.0%, 3 reps) |
| R4 | pass | brief 1,879 chars = 470 est. tokens; index 8,885 chars = 2,222 est. tokens (chars / 4, rounded up) |
| R5 | fail | total input tokens per pass: claude-haiku-4-5-20251001 best (adapter) 406,647 vs manual 434,913 (0.94x); claude-sonnet-5 best (adapter) 280,773 vs manual 294,397 (0.95x) |
| R6 | fail | p90 input tokens per question: claude-haiku-4-5-20251001 best (adapter) 57,273 vs manual 44,470 (1.29x); claude-sonnet-5 best (adapter) 23,808 vs manual 32,791 (0.73x) |
| R7 | fail | cost per correct answer, pooled over repetitions: claude-haiku-4-5-20251001 manual-preloaded $0.0095 vs claude-sonnet-5 best (adapter) $0.0330 (3.45x) |

## The edit-safety set

20 questions, frozen before any run saw them (sha256 `69eb2c8401ba4ff31666e968dce3cf8ac31c3c5153c4920a70dbadb9fe372cc9`), on the block fixture. It has no run 3 counterpart. R1 uses the block threshold. Its R1 to R7 verdicts are in "Numbers" above and against the best baseline in the section before this one.

### Accuracy by category

| Model | Condition | locks | multi_home | selectors | stale_edit | All |
|---|---|---|---|---|---|---|
| claude-haiku-4-5-20251001 | raw | 60.0% (3/5) | 60.0% (3/5) | 60.0% (3/5) | 80.0% (4/5) | 65.0% (13/20) |
| claude-haiku-4-5-20251001 | adapter | 66.7% (10/15) | 60.0% (9/15) | 60.0% (9/15) | 86.7% (13/15) | 68.3% (41/60) |
| claude-haiku-4-5-20251001 | manual | 40.0% (6/15) | 80.0% (12/15) | 80.0% (12/15) | 80.0% (12/15) | 70.0% (42/60) |
| claude-haiku-4-5-20251001 | manual-preloaded | 40.0% (6/15) | 66.7% (10/15) | 60.0% (9/15) | 100.0% (15/15) | 66.7% (40/60) |
| claude-sonnet-5 | raw | 60.0% (3/5) | 60.0% (3/5) | 80.0% (4/5) | 80.0% (4/5) | 70.0% (14/20) |
| claude-sonnet-5 | adapter | 66.7% (10/15) | 60.0% (9/15) | 80.0% (12/15) | 93.3% (14/15) | 75.0% (45/60) |
| claude-sonnet-5 | manual | 80.0% (12/15) | 100.0% (15/15) | 100.0% (15/15) | 100.0% (15/15) | 95.0% (57/60) |
| claude-sonnet-5 | manual-preloaded | 60.0% (3/5) | 80.0% (4/5) | 80.0% (4/5) | 80.0% (4/5) | 75.0% (15/20) |

### Most missed

| Condition | Question | Category | Missed | Reason |
|---|---|---|---|---|
| adapter | mh-01 | multi_home | 6/6 | claude-haiku-4-5-20251001: missing post.php?post=90062&action=edit, post.php?post=90070&action=edit; answered: edit.php?post_type=page&action=edit&post=90061, edit.php?post_type=page&action=edit&post=90060, edit.php?post_type=page… |
| adapter | mh-02 | multi_home | 6/6 | claude-haiku-4-5-20251001: missing post.php?post=90070&action=edit; answered: edit.php?post_type=page (then click to edit the "Financing" page with ID 90061) |
| adapter | sl-04 | selectors | 6/6 | claude-haiku-4-5-20251001: missing post.php?post=90070&action=edit; answered: wp-admin/edit.php?post_type=wp_block&p=90070 |
| manual | lk-03 | locks | 4/6 | claude-haiku-4-5-20251001: expected {License notice, Plan inclusions, Plan name, Plan price, Refund terms, Renewal terms}; answered: Plan terms, License notice |
| manual | lk-04 | locks | 4/6 | claude-haiku-4-5-20251001: expected "yes"; answered: No |
| manual | lk-05 | locks | 4/6 | claude-haiku-4-5-20251001: The answer correctly states 'no' and identifies that the Editor role lacks the necessary capability, but it incorrectly attributes the restriction to a 'content_only lock' rather than the Editor role simply lacking the 'edit_theme_options' capability required to edit temp… |
| manual-preloaded | lk-03 | locks | 4/4 | claude-haiku-4-5-20251001: expected {License notice, Plan inclusions, Plan name, Plan price, Refund terms, Renewal terms}; answered: Plan card, Plan terms, License notice |
| manual-preloaded | lk-05 | locks | 4/4 | claude-haiku-4-5-20251001: The answer attributes the restriction to a 'content_only lock' on the footer template part, but the expected ground truth indicates the Editor role lacks the 'edit_theme_options' capability required to edit template parts in the Site Editor, which is the actual reason she… |
| manual-preloaded | mh-01 | multi_home | 4/4 | claude-haiku-4-5-20251001: missing post.php?post=90062&action=edit; answered: post.php?post=90070&action=edit |
| raw | lk-03 | locks | 2/2 | claude-haiku-4-5-20251001: expected {License notice, Plan inclusions, Plan name, Plan price, Refund terms, Renewal terms}; answered: Plan terms, License notice |

## Losses

Every comparison on the same model where a baseline cell (raw or adapter) did better than a Wayfinder cell (manual or manual-preloaded). Computed by `findLosses` in `src/report.ts` and stored in each report; nothing here is picked by hand.

### block

| Model | Metric | Baseline | Wayfinder cell |
|---|---|---|---|
| claude-haiku-4-5-20251001 | accuracy, change_impact | adapter 100.0% (12/12) | manual-preloaded 75.0% (9/12) |
| claude-sonnet-5 | accuracy, data_flow | adapter 77.8% (14/18) | manual 66.7% (12/18) |
| claude-sonnet-5 | accuracy, change_impact | raw 100.0% (4/4) | manual-preloaded 75.0% (3/4) |
| claude-sonnet-5 | accuracy, change_impact | adapter 100.0% (12/12) | manual-preloaded 75.0% (3/4) |

### classic

None. No baseline cell did better than a Wayfinder cell on any metric listed.

### edit-safety

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

## Reports

| Set | Report | Question runs | Correct | Agent cost | Runner | Commit | Question set sha256 |
|---|---|---|---|---|---|---|---|
| block | `reports/fixture-2026-10-04-run4.json` | 720 | 620 | $14.35 | github-actions | `1cb750a6d2c7` | `e404e30f7180e638250227fe1f0ac7bb6ca7c5e755c1b0a06a3ff7265c0eb81b` |
| classic | `reports/fixture-classic-2026-10-04-run4.json` | 540 | 437 | $12.19 | github-actions | `1cb750a6d2c7` | `4511d7c994aabf5bd16fe64a880ef7dd06bebc2202ced544d78a30bd3b02f9ce` |
| edit-safety | `reports/fixture-edit-safety-2026-10-04-run4.json` | 360 | 267 | $5.73 | github-actions | `1cb750a6d2c7` | `69eb2c8401ba4ff31666e968dce3cf8ac31c3c5153c4920a70dbadb9fe372cc9` |

## Notes, written after the run

These add context and change no number above. Questions, answer keys and graders were not changed after the run started, and run 3's reports are untouched.

- **Same tools as run 3.** The edit-safety set is answered with run 3's tool set: `find_edit_location` takes a URL and text only, with no class input and no anchor lookup. Its lock, selector and anchor questions are answerable through manual sections, find hits and `rest_get`, but the product's newer lookups were not exposed to the agent, so the set measures the manual, not the full toolset.
- **The old sets ran on a larger site.** #60 added 3 pages (Commercial plumbing, Financing, Maintenance plan) and a synced pattern (Financing offer) to the block fixture for the edit-safety set. The block set's questions, answer keys and grader rival pools are unchanged, but run 4's agents read a larger block site and manual than run 3's: brief 1,790 to 1,879 characters, index 8,681 to 8,885. The classic manual also grew with the plugin's work since 66fef91 (brief 1,203 to 1,425, index 8,370 to 8,884). Every cell stays inside R4.
- **Where it ran.** Run 3 ran on a local wp-env site; run 4 ran on three GitHub Actions runners with wp-env (bench.yml runs 37163170004, 37163171259 and 37163172520), at main `1cb750a` (plugin 0.6.0) with no uncommitted changes. The cap counts model calls, not time, so the host moves wall times, not what a run can answer. Each set ran on its own runner and seeded its own site.
- **The el-08 rule graded run 4.** All 18 block el-08 runs passed: 6 in code (the subject's own edit screen and the field) and 12 by the judge. In run 3 the judge failed 7 of 18, so part of the run 3 to run 4 movement in block cells is this rule rather than the plugin.
- **Frozen sets: same verdicts as run 3.** R1 and R3 to R7 pass on both sets; R2 fails on block Sonnet only, now +7.5 points over adapter (run 3: +5.8). Haiku manual rose on classic (92.2% to 97.8%), block Haiku adapter rose 10 points (54.2% to 64.2%), and every other cell moved by 4.4 points or less.
- **Against the best baseline per cell, the frozen sets read the same as against adapter:** R2 fails on block Sonnet (+7.5 over adapter 87.5%), everything else passes. R7 on classic passes against Sonnet raw at 10.14x, close to the 10x line. Applied to run 3's own reports, the same calculation fails R7 on classic (5.39x against Sonnet raw); run 3's published best-baseline section restated only R2 and R3.
- **The edit-safety set is where the manual did not help Haiku.** Haiku manual 70.0% against best baseline 68.3% (+1.7); Haiku manual-preloaded 66.7%, below Sonnet adapter's 75.0%, so R3 fails there. Sonnet manual is 95.0% against 75.0% (+20.0). R1 uses the block threshold (90%) and fails on Haiku. R5 to R7 fail: no baseline run reached the 12-call cap on this set, so the baseline answers cheaply, and the manual cells spend more input reading sections (Sonnet manual p90 32,791 against adapter 23,808).
- **Locks drive the Haiku gap.** Haiku manual and manual-preloaded each scored 6 of 15 on locks, below raw (3 of 5) and adapter (10 of 15). lk-03 (which blocks a client can change inside a locked layout) failed for every Haiku run in every condition; lk-05 failed in all 6 Haiku manual and manual-preloaded runs; the judge's reasons read the replies as blaming a `contentOnly` lock on the footer for what truth records as the Editor role lacking `edit_theme_options`. Whether the manual's lock lines invite that reading is a product question for triage, not a grading change.
- **Losses.** Block: four category-level losses (Haiku manual-preloaded against adapter on change_impact, 9/12 against 12/12; Sonnet manual against adapter on data_flow, 12/18 against 14/18; Sonnet manual-preloaded against raw and adapter on change_impact, 3/4 against 4/4 and 12/12). Classic: none. Edit-safety: 29, listed above, mostly input tokens; on overall accuracy only Haiku manual-preloaded against adapter (40/60 against 41/60).
- **Cost.** $32.27 in agent calls and $0.20 for the judge against an estimate of $34.70 central and about $41 with margins, posted before the first call.
- **Transcripts.** The run directories hold 720, 540 and 360 transcripts, packed as `run4-transcripts.tar.gz` (sha256 `b6d5be69edfa8c0ec8687167de318e5cd9f85af24dd44185c1162c8ad01b8050`) for the `bench-run4-transcripts` release. They are also in the `bench-report` artifact of each Actions run for 30 days.
