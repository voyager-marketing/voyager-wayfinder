## What this changes

<!-- One or two sentences. Link the issue it closes: "Fixes #123". -->

## Why

<!-- The problem, or the issue that describes it. -->

## How it was tested

- [ ] `composer test`
- [ ] `composer phpstan`
- [ ] `composer phpcs`
- [ ] `cd bench && npm test` (if `bench/` changed)
- [ ] Checked on a wp-env site or a fixture (say which)

## Checklist

- [ ] Read-only: nothing here writes to the site, edits builder content or adds a memory feature.
- [ ] Deterministic: the same site state gives the same bytes, with no timestamps, random ids or translated strings in the manifest body.
- [ ] No option values outside the allowlist, user emails, credentials or absolute server paths reach any output.
- [ ] If scanner output changes for the same site, the plugin version is bumped as AGENTS.md describes.
- [ ] UI copy is sentence case and plain words, with no em dashes.
- [ ] Tests added or updated.
