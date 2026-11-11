# Good first issues

Ten scoped starter tasks, to post as GitHub issues at launch with the `good first issue` label. Builder sections come first: they are what the project leaves to contributors, and each one is a self-contained scanner.

Every task follows the rules in [CONTRIBUTING.md](../CONTRIBUTING.md): read-only, deterministic, no LLM calls, unit tests that run without WordPress. Each one is done when its tests pass, `composer test`, `composer phpstan` and `composer phpcs` are clean, and the pull request says how it was checked on a real site.

## How a builder section works

A builder section is a scanner: a class that implements `Voyager\Wayfinder\Scanners\Scanner` and returns one manifest section. It joins the manifest through the `vwf_scanners` filter (see `src/Scanners/ScannerRegistry.php`), so it can sit in core or ship as a separate add-on. Take WordPress lookups as injectable callables, the way `ContentModelScanner` and `LayoutScanner` do, so the unit test needs no database. Item ids follow [ai-context-format.md](ai-context-format.md#item-ids).

Builder content is opaque to Wayfinder: the section lists each template, says what it applies to and where it's edited, and never parses or changes the builder's layout data. A section that adds facts to the manifest bumps the plugin version (see AGENTS.md). Check the open issues before you start: if shared support for builder documents has landed in core, build on it rather than repeating it.

---

## 1. Bricks: map templates and point agents at Bricks' own abilities

**Labels:** `good first issue`, `builder`, `help wanted`

Bricks stores its templates as the `bricks_template` post type, with a template type (header, footer, single, archive, section and others) and display conditions. Bricks 2.4 also registers its own abilities through the Abilities API.

- Add a `bricks` section, present only when the Bricks theme is active.
- One item per template: title, template type, a plain summary of its conditions, and its edit path in the Bricks builder.
- List the Bricks abilities the site registers (names only) so an agent knows to call Bricks for builder-level detail. Read them through the Abilities API registry; don't call them.
- Unit test with a fake template lookup covering a header, a footer and a single template with conditions.

## 2. Beaver Themer: map theme layouts and their locations

**Labels:** `good first issue`, `builder`, `help wanted`

Beaver Themer stores layouts as the `fl-theme-layout` post type. Each has a layout type (header, footer, part, singular, archive, 404) and location rules in post meta.

- Add a `beaver-themer` section, present only when Beaver Themer is active.
- One item per layout: title, layout type, location rules in plain words, and the edit path that opens it in Beaver Builder.
- Unit test with a fake lookup covering a header, a singular layout with two location rules, and a part.

## 3. Divi Theme Builder: map templates and their assignments

**Labels:** `good first issue`, `builder`, `help wanted`

Divi's Theme Builder stores templates as `et_template` posts, each with a header, body and footer layout and use-on / exclude-from settings in post meta.

- Add a `divi` section, present only when Divi or the Divi Builder plugin is active.
- One item per template: what it's used on and excluded from, which of header, body and footer it overrides, and the Theme Builder edit path.
- Unit test with a fake lookup covering the default template and one template assigned to a post type.

## 4. Elementor Pro: map Theme Builder templates and display conditions

**Labels:** `good first issue`, `builder`, `help wanted`

Elementor Pro stores Theme Builder templates in the `elementor_library` post type, with a template type and display conditions.

- Add an `elementor` section, present only when Elementor Pro is active.
- One item per header, footer, single or archive template: its conditions in plain words and its Elementor edit path. Skip saved sections and pages that no condition uses.
- Unit test with a fake lookup covering a header with an include and an exclude condition.

## 5. Builder roles for more plugins

**Labels:** `good first issue`

`data/plugin-roles.json` gives each known plugin a role in the integrations section. Builders and add-ons that sites use often are missing, so they show as `other`.

- Add Beaver Themer, Oxygen, Breakdance, Spectra Pro, Essential Addons for Elementor and Happy Elementor Addons under `builder`. Check each slug against the plugin's folder name, not its display name.
- Keep each list sorted, as the file asks.
- Extend `tests/Unit/Scanners/PluginRolesTest.php` with a case per new slug.

## 6. Plugin roles for forms and SEO plugins

**Labels:** `good first issue`

Same file, same pattern, for the next most common gaps. Run `wp wayfinder stats` or read the brief on a test site with a few popular forms and SEO plugins active, and add any that come out as `other` to the right role. List the slugs you checked in the pull request.

## 7. `wp wayfinder stats --format=json`

**Labels:** `good first issue`

`wp wayfinder stats` prints the size of the brief and the index as a table or CSV. Scripts that track the manual's size over time want JSON.

- Add `json` and `yaml` to the `--format` options of `stats` in `src/Cli/ContextCommand.php`. `WP_CLI\Utils\format_items` already handles both.
- Print the "est_tokens is chars / 4" line only for `table`, as now, so JSON output stays parseable.
- Update the command's `## OPTIONS` and `## EXAMPLES` blocks.

## 8. Add "Blog pages show at most" to the settings the manual reports

**Labels:** `good first issue`

"Why does the blog only show ten posts?" is a common client question, and the answer is a core setting under Settings > Reading. The manual can only report options on the allowlist in `src/Scanners/OptionAllowlist.php`.

- Add `posts_per_page` with the label "Blog pages show at most" and the edit path `options-reading.php`.
- Extend `tests/Unit/Scanners/OptionAllowlistTest.php` and the site scanner's test.
- This adds a fact to the manifest, so bump the plugin version as AGENTS.md describes. `VersionGuardTest` tells you what to add.

## 9. Determinism test for the classic fixture's AI tiers

**Labels:** `good first issue`, `tests`

`tests/Unit/Render/Ai/DeterminismTest.php` checks that the same manifest renders the same bytes. Add a case built from a classic-theme manifest (a classic child theme with Secure Custom Fields, the shape `fixtures/classic/` seeds), so a classic-only fact can't introduce ordering drift unnoticed.

## 10. A step-by-step "your first scan" guide

**Labels:** `good first issue`, `documentation`

Write `docs/first-scan.md`: install the plugin on a wp-env site, seed a fixture, open the Wayfinder page, run `wp wayfinder find` on the homepage, and read the brief over REST. Use the commands in CONTRIBUTING.md and the screenshots in `docs/screenshots/`. Link it from the README.
