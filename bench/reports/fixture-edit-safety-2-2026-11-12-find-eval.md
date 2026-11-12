# Find eval: fixture-edit-safety-2, 2026-11-12

`questions/fixture-edit-safety-2.yaml` (sha256 `79b1b58aef25`), find inputs from the questions, on the block fixture at `6eec85172c5eaa85bc3c9ebadaf12b84138b7a3b`. No model is called: each edit-location question's find inputs go to the find route, and the answer key's location is checked against hit 1 and against every hit. No manifest was stored, so hits carry no route counts. The eval asks for every hit (`detail: full`); find's default answer keeps the first 3, so a first match past 3 is not in it.

| | Questions | Top-1 | Any hit |
|---|---|---|---|
| All | 8 | 7 (87.5%) | 8 (100.0%) |
| Ranked hits (text or class given) | 7 | 7 (100.0%) | 7 (100.0%) |
| Chain (URL only, find returns no hits) | 1 | 0 (0.0%) | 1 (100.0%) |

Any hit means every location the key names is at some hit, so a question with three homes needs all three.

## By category

| Category | Questions | Top-1 | Any hit |
|---|---|---|---|
| client_can | 1 | 0 (0.0%) | 1 (100.0%) |
| multi_home | 3 | 3 (100.0%) | 3 (100.0%) |
| selectors | 2 | 2 (100.0%) | 2 (100.0%) |
| stale_edit | 2 | 2 (100.0%) | 2 (100.0%) |

## Questions

| Question | Find inputs | Expected | Top-1 | Any hit | First match | Hit 1 |
|---|---|---|---|---|---|---|
| cc-26 | `/contact/` (chain) | `site-editor.php?p=/wp_template/vwf-fixture//page-contact&canvas=edit` | no | yes | 2 | pt:page (`post.php?post=90012&action=edit`) |
| mh-21 | `/guarantee/`, text "licensed in Virginia" | `post.php?post=90010&action=edit`, `post.php?post=90062&action=edit`, `post.php?post=90081&action=edit` | yes | yes (3 of 3) | 1 | pt:page `3` (`post.php?post=90081&action=edit`) |
| mh-24 | `/pricing/`, text "Same-day service on calls booked before noon." | `post.php?post=90071&action=edit` | yes | yes | 1 | pat:same-day-service `0.0` (`post.php?post=90071&action=edit`) |
| mh-26 | `/emergencies/`, text "Same-day service on calls booked before noon." | `post.php?post=90071&action=edit`, `post.php?post=90082&action=edit` | yes | yes (2 of 2) | 1 | pt:page `2` (`post.php?post=90082&action=edit`) |
| sl-21 | `/pricing/`, class `rate-card` | `post.php?post=90080&action=edit` | yes | yes | 1 | pt:page `2` (`post.php?post=90080&action=edit`) |
| sl-24 | `/guarantee/`, class `notice-bar` | `post.php?post=90071&action=edit` | yes | yes | 1 | pat:same-day-service `0` (`post.php?post=90071&action=edit`) |
| se-25 | `/pricing/`, text "The price we quote is the price you pay." | post 90080 block `3` | yes | yes | 1 | pt:page `3` (`post.php?post=90080&action=edit`) |
| se-26 | `/emergencies/`, text "Our main valve is in the crawl space." | post 90082 block `1.1.0` | yes | yes | 1 | pt:page `1.1.0` (`post.php?post=90082&action=edit`) |
