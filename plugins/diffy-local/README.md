# diffy-local plugin

Claude Code skills for [Diffy](https://diffy.website/) visual-regression testing that **capture screenshots
locally** and upload them to Diffy, built on the `diffy` CLI. Local capture is done by the
[`diffy-worker`](https://github.com/diffywebsite/diffy-worker) project — Diffy's **production** screenshot
engine — run on your machine, so a local capture renders exactly like a Diffy baseline. Claude invokes these
skills automatically based on what you ask; you can also call them explicitly with their namespaced names
(`/diffy-local:<skill>`).

Want Diffy to run the screenshots on its own servers instead (no local browser)? Use the companion
**`diffy-remote`** plugin.

## Skills

| Skill | What it does |
|---|---|
| `/diffy-local:create-project` | Create a new Diffy project from a base URL, pages, and breakpoints. |
| `/diffy-local:update-project-settings` | Edit an existing project's pages, environments, breakpoints, masks, login, schedule, etc. |
| `/diffy-local:upload-screenshot` | Capture a running app with the diffy-worker engine and upload it as a Diffy screenshot set. |
| `/diffy-local:compare-screenshots` | Create a diff from two existing screenshot set IDs. |
| `/diffy-local:get-diff-info` | Fetch and summarize a diff: % changed, per-page/per-breakpoint table, report link, JUnit. |
| `/diffy-local:visual-diff` | End-to-end before/after regression: baseline your UI, edit, then compare — pages/breakpoints read from the project. |

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
- **Local capture engine** (only for `upload-screenshot` and `visual-diff`) — these skills capture
  with the `diffy-worker` project. **You don't need to clone anything yourself:** the first time you
  capture, the plugin runs a one-time setup that clones the engine into a cache dir and installs it
  (`~/.cache/diffy-local/diffy-worker`, includes Playwright Chromium, ~150 MB). You can also run it
  directly:
  ```bash
  node <path-to-plugin>/scripts/run-worker.mjs --provision   # one-time; --check to verify
  ```
  Advanced: to use a `diffy-worker` checkout you manage instead of the cached one, set
  `DIFFY_WORKER_DIR=/path/to/diffy-worker` (or keep it as a `./diffy-worker` / `../diffy-worker`
  sibling). `git` is required for the auto-setup; Playwright ships as a worker dependency, so you
  don't install it separately in your project.

## Working files & .gitignore

These skills write small metadata/state files (configs, screenshot/diff IDs, baseline state) under
`.diffy-skills/` (granular skills) and `.diffy-visual/` (`visual-diff`). The screenshots themselves are
captured in the worker's temp directory and uploaded to Diffy — they are not stored in your repo. Add both
dirs to your project's `.gitignore`:

```gitignore
.diffy-skills/
.diffy-visual/
```

## Safety: no project deletion

These skills **never delete Diffy projects.** Deleting a project (or projects) is out of scope for this
plugin — Claude will refuse to do it via the `diffy` CLI, a direct Diffy API/`curl` call, or the Diffy web
UI, and will point you to the Diffy dashboard to do it manually. As a hard backstop, this repo's
[`.claude/settings.json`](../../.claude/settings.json) `permissions.deny` list blocks any delete-shaped
`diffy` command outright.

## Notes

Diffy is a cloud service — these skills need an account, API key, and project; they do not do a purely
local pixel diff. The screenshots themselves are captured on your machine and uploaded.
