# CLAUDE.md

Read [AGENTS.md](AGENTS.md). It is the contract for this repo.

The spec, project notes and Discoveries live in the maintainers' own tracker, not in this public repo.
**Queue key:** label `component/wayfinder`, Linear team VOY, project Wayfinder MVP. Start every session with `/mission`.

A PR body names the issue it completes as `Fixes VOY-NNN`. Write no other `VOY-NNN` in a PR's title, branch or body: Linear attaches the PR to every issue ID it finds and moves each one to In Review, which takes an unclaimed issue out of the dispatch queue. Name other issues by title instead.

`.claude/settings.json` is tracked and holds only the public mission grants. The MCP grants (Linear, Notion, Slack) live in the user-level `~/.claude/settings.json` of the machine or environment that runs missions, so they stay out of this public repo.
