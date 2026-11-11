# Find eval: fixture, 2026-10-06

`questions/fixture.yaml` (sha256 `e404e30f7180`), find inputs from `questions/find-inputs-fixture.yaml` and the questions, on the block fixture at `3ffbe5315b47c0d5b48dd1d829be3ac1a4a55e09`. No model is called: each edit-location question's find inputs go to the find route, and the answer key's location is checked against hit 1 and against every hit. No manifest was stored, so hits carry no route counts.

| | Questions | Top-1 | Any hit |
|---|---|---|---|
| All | 7 | 4 (57.1%) | 5 (71.4%) |
| Ranked hits (text or class given) | 4 | 3 (75.0%) | 3 (75.0%) |
| Chain (URL only, find returns no hits) | 3 | 1 (33.3%) | 2 (66.7%) |

Any hit means every location the key names is at some hit, so a question with three homes needs all three.

## By category

| Category | Questions | Top-1 | Any hit |
|---|---|---|---|
| edit_location | 7 | 4 (57.1%) | 5 (71.4%) |

## Questions

| Question | Find inputs | Expected | Top-1 | Any hit | First match | Hit 1 |
|---|---|---|---|---|---|---|
| el-01 | `/`, text "(540) 555-0142" | `options-general.php?page=vwf-business-info` | yes | yes | 1 | bind:vwf-fixture/business-info.phone `0.1` (`options-general.php?page=vwf-business-info`) |
| el-02 | `/services/` (chain) | `edit.php?post_type=service` | no | no | none | tpl:archive (`site-editor.php?p=/wp_template/vwf-fixture//archive&canvas=edit`) |
| el-03 | `/` (chain) | `site-editor.php?p=/wp_template_part/vwf-fixture//footer&canvas=edit` | no | yes | 3 | pt:page (`post.php?post=90013&action=edit`) |
| el-04 | `/`, text "Need a plumber today?" | `post.php?post=90003&action=edit` | yes | yes | 1 | pat:cta-banner `0.0` (`post.php?post=90003&action=edit`) |
| el-06 | `/`, text "Services" | `site-editor.php?p=/wp_navigation/90001` | yes | yes | 1 | nav:primary `2` (`site-editor.php?p=/wp_navigation/90001`) |
| el-08 | `/services/drain-cleaning/` (chain) | `post.php?post=90020&action=edit` | yes | yes | 1 | pt:service (`post.php?post=90020&action=edit`) |
| el-09 | `/services/drain-cleaning/`, text "Service areas:" | `edit-tags.php?taxonomy=service_area&post_type=service` | no | no | none | none |

## Not asked

- el-05 (edit_location): the hours show on no page; no block binds the hours key (truth.json used_in is empty)
- el-07 (edit_location): team members show on no page; the post type is not public
