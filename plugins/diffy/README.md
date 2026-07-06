# diffy plugin

Claude Code skills for [Diffy](https://diffy.website/) visual-regression testing, built on the `diffy` CLI.
Claude invokes these automatically based on what you ask; you can also call them explicitly with their
namespaced names (`/diffy:<skill>`).

## Skills

| Skill | What it does |
|---|---|
| `/diffy:create-project` | Create a new Diffy project from a base URL, pages, and breakpoints. |
| `/diffy:update-project-settings` | Edit an existing project's pages, environments, breakpoints, masks, login, schedule, etc. |
| `/diffy:upload-screenshot` | Capture a running app with Playwright and upload it as a Diffy screenshot set. |
| `/diffy:compare-screenshots` | Create a diff from two existing screenshot set IDs. |
| `/diffy:get-diff-info` | Fetch and summarize a diff: % changed, per-page/per-breakpoint table, report link, JUnit. |
| `/diffy:visual-diff` | End-to-end before/after regression: baseline your UI, edit, then compare — pages/breakpoints read from the project. |

`visual-diff` is the one-shot orchestrator (baseline → edit → compare). The other five are granular
building blocks you can compose yourself.

## Prerequisites

- **`diffy` CLI** on `PATH` (or `./vendor/bin/diffy` in the repo). Install the phar:
  ```bash
  wget -O /usr/local/bin/diffy https://github.com/diffywebsite/diffy-cli/releases/latest/download/diffy.phar && chmod a+x /usr/local/bin/diffy
  ```
  Pick a bin directory you can write to — `/usr/local/bin` may need `sudo`; Homebrew on Apple Silicon uses
  `/opt/homebrew/bin`.
- **Authentication:** `diffy auth:login <API_KEY>` (get a key at https://app.diffy.website/#/keys). Stored
  in `~/.diffy-cli/diffy-cli.yaml`.
- **Playwright** (only for `upload-screenshot` and `visual-diff`):
  `npm i -D playwright && npx playwright install chromium`.

## Working files & .gitignore

These skills write intermediate files (configs, PNGs, upload payloads, diff JSON) under `.diffy-skills/`
(granular skills) and `.diffy-visual/` (`visual-diff`). Add both to your project's `.gitignore`:

```gitignore
.diffy-skills/
.diffy-visual/
```

## Notes

Diffy is a cloud service — these skills need an account, API key, and project; they do not do a purely
local pixel diff.
