# Security policy

## Report a vulnerability privately

Please don't open a public issue, discussion or pull request for a security problem.

Report it through GitHub's private vulnerability reporting: go to the repository's **Security** tab and choose **Report a vulnerability** ([direct link](https://github.com/voyager-marketing/voyager-wayfinder/security/advisories/new)). Only the maintainers can see the report.

Include what you can of:

- the version of Wayfinder and of WordPress
- what an attacker can do, and what they need first (for example a logged-in role)
- steps or a proof of concept to reproduce it

## What happens next

- **Within two business days:** a maintainer acknowledges your report. This is the same reply window the project gives every issue, during the support hours in the [README](README.md#support).
- **Within ten business days:** we tell you whether we can reproduce it and how serious we think it is, or ask you for more detail.
- **Fix:** a confirmed vulnerability is fixed in a security release as soon as it's ready. It doesn't wait for the two-weekly release batch. We agree a disclosure date with you, publish a GitHub security advisory, and credit you unless you'd rather stay anonymous.

## Supported versions

Wayfinder is pre-1.0. Only the latest release gets security fixes. Please check you can reproduce on it before you report.

## What counts

Wayfinder is a read-only map of a site, and the map itself is sensitive: it shows how the site is built and where things are edited. These are in scope:

- any read surface (the Wayfinder page, REST routes under `wayfinder/v1`, abilities, WP-CLI output) reachable without the `edit_pages` capability
- notes written without `manage_options`
- Wayfinder abilities exposed over MCP while MCP exposure is off (the default)
- the manual emitting an option value outside the scanner's allowlist, a user email, a credential or an absolute server path
- injection, XSS, CSRF or SQL injection anywhere in the plugin

Out of scope: problems in WordPress core, in other plugins, or in the benchmark harness under `bench/` (it never ships in the plugin), and reports that need an attacker who is already an administrator.
