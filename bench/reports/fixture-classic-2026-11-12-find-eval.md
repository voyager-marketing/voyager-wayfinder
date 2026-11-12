# Find eval: fixture-classic, 2026-11-12

`questions/fixture-classic.yaml` (sha256 `4511d7c994aa`), find inputs from `questions/find-inputs-fixture-classic.yaml` and the questions, on the classic fixture at `6eec85172c5eaa85bc3c9ebadaf12b84138b7a3b`. No model is called: each edit-location question's find inputs go to the find route, and the answer key's location is checked against hit 1 and against every hit. No manifest was stored, so hits carry no route counts. The eval asks for every hit (`detail: full`); find's default answer keeps the first 3, so a first match past 3 is not in it.

| | Questions | Top-1 | Any hit |
|---|---|---|---|
| All | 6 | 0 (0.0%) | 0 (0.0%) |
| Ranked hits (text or class given) | 4 | 0 (0.0%) | 0 (0.0%) |
| Chain (URL only, find returns no hits) | 2 | 0 (0.0%) | 0 (0.0%) |

Any hit means every location the key names is at some hit, so a question with three homes needs all three.

## By category

| Category | Questions | Top-1 | Any hit |
|---|---|---|---|
| edit_location | 6 | 0 (0.0%) | 0 (0.0%) |

## Questions

| Question | Find inputs | Expected | Top-1 | Any hit | First match | Hit 1 |
|---|---|---|---|---|---|---|
| el-01 | `/`, text "(434) 555-0187" | `admin.php?page=company-settings` | no | no | none | none |
| el-02 | `/projects/` (chain) | `edit.php?post_type=project` | no | no | none | tpl:archive-project |
| el-03 | `/`, text "Projects" | `nav-menus.php?action=edit&menu=90190` | no | no | none | none |
| el-04 | `/projects/` (chain) | `edit-tags.php?taxonomy=project_type&post_type=project` | no | no | none | tpl:archive-project |
| el-05 | `/projects/brennan-kitchen/`, text "Planning a remodel? Call for a free in-home estimate." | `widgets.php` | no | no | none | none |
| el-06 | `/kitchen-remodeling/`, text "Kitchens built around how you cook" | `post.php?post=90103&action=edit` | no | no | none | none |
