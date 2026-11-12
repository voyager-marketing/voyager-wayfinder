# Find eval: fixture-edit-safety, 2026-11-12

`questions/fixture-edit-safety.yaml` (sha256 `69eb2c8401ba`), find inputs from `questions/find-inputs-fixture-edit-safety.yaml` and the questions, on the block fixture at `6eec85172c5eaa85bc3c9ebadaf12b84138b7a3b`. No model is called: each edit-location question's find inputs go to the find route, and the answer key's location is checked against hit 1 and against every hit. No manifest was stored, so hits carry no route counts. The eval asks for every hit (`detail: full`); find's default answer keeps the first 3, so a first match past 3 is not in it.

| | Questions | Top-1 | Any hit |
|---|---|---|---|
| All | 7 | 6 (85.7%) | 7 (100.0%) |
| Ranked hits (text or class given) | 6 | 6 (100.0%) | 6 (100.0%) |
| Chain (URL only, find returns no hits) | 1 | 0 (0.0%) | 1 (100.0%) |

Any hit means every location the key names is at some hit, so a question with three homes needs all three.

## By category

| Category | Questions | Top-1 | Any hit |
|---|---|---|---|
| locks | 1 | 0 (0.0%) | 1 (100.0%) |
| multi_home | 2 | 2 (100.0%) | 2 (100.0%) |
| selectors | 2 | 2 (100.0%) | 2 (100.0%) |
| stale_edit | 2 | 2 (100.0%) | 2 (100.0%) |

## Questions

| Question | Find inputs | Expected | Top-1 | Any hit | First match | Hit 1 |
|---|---|---|---|---|---|---|
| lk-05 | `/` (chain) | `site-editor.php?p=/wp_template_part/vwf-fixture//footer&canvas=edit` | no | yes | 3 | pt:page (`post.php?post=90013&action=edit`) |
| mh-01 | `/financing/`, text "0% financing for 12 months on approved credit." | `post.php?post=90062&action=edit`, `post.php?post=90070&action=edit` | yes | yes (2 of 2) | 1 | pat:financing-offer `0.0` (`post.php?post=90070&action=edit`) |
| mh-02 | `/financing/`, text "0% financing for 12 months on approved credit." | `post.php?post=90070&action=edit` | yes | yes | 1 | pat:financing-offer `0.0` (`post.php?post=90070&action=edit`) |
| sl-01 | `/maintenance-plan/`, class `plan-card` | `post.php?post=90062&action=edit` | yes | yes | 1 | pt:page `2` (`post.php?post=90062&action=edit`) |
| sl-04 | `/financing/`, class `promo-strip` | `post.php?post=90070&action=edit` | yes | yes | 1 | pat:financing-offer `0` (`post.php?post=90070&action=edit`) |
| se-01 | `/maintenance-plan/`, text "0% financing for 12 months on approved credit." | post 90062 block `5` | yes | yes | 1 | pt:page `5` (`post.php?post=90062&action=edit`) |
| se-05 | `/commercial/`, text "Ask about after-hours service." | post 90060 block `2`, post 90060 block `4` | yes, ambiguous | yes (2 of 2) | 1 | pt:page `2` (`post.php?post=90060&action=edit`) |
