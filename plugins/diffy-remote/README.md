# diffy-remote plugin

Claude Code skills for [Diffy](https://diffy.website/) visual-regression testing that run **entirely on
Diffy's servers** — Diffy captures the screenshots of your environments remotely (no local browser, no
Playwright, no Node), built on the `diffy` CLI. Claude invokes these automatically based on what you ask;
you can also call them explicitly with their namespaced names (`/diffy-remote:<skill>`).

Need to screenshot a **locally running app** and upload it instead? Use the companion **`diffy-local`**
plugin.

## Skills

| Skill | What it does |
|---|---|
| `/diffy-remote:create-project` | Create a new Diffy project from a base URL, pages, and breakpoints. |
| `/diffy-remote:update-project-settings` | Edit an existing project's pages, environments, breakpoints, masks, login, schedule, etc. |
| `/diffy-remote:remote-screenshot` | Ask Diffy to screenshot an environment (production/staging/development or a custom URL) on its servers; returns a screenshot ID. |
| `/diffy-remote:compare-environments` | Compare two environments server-side (Diffy screenshots both, then diffs) — the remote analog of visual-diff; returns a diff ID. |
| `/diffy-remote:compare-screenshots` | Create a diff from two existing screenshot set IDs. |
| `/diffy-remote:get-diff-info` | Fetch and summarize a diff: % changed, per-page/per-breakpoint table, report link, JUnit. |

`compare-environments` is the one-shot orchestrator (screenshot both environments + diff). The others are
granular building blocks you can compose yourself.

## Prerequisites

- **`diffy` CLI** on `PATH` (or `./vendor/bin/diffy` in the repo). Install the phar:
  ```bash
  wget -O /usr/local/bin/diffy https://github.com/diffywebsite/diffy-cli/releases/latest/download/diffy.phar && chmod a+x /usr/local/bin/diffy
  ```
  Pick a bin directory you can write to — `/usr/local/bin` may need `sudo`; Homebrew on Apple Silicon uses
  `/opt/homebrew/bin`.
- **Authentication:** `diffy auth:login <API_KEY>` (get a key at https://app.diffy.website/#/keys). Stored
  in `~/.diffy-cli/diffy-cli.yaml`.
- **No Playwright / Node required** — screenshots are captured on Diffy's servers. Environment URLs must be
  configured on the project (or supplied as a `custom` URL) for Diffy to reach them.

## Working files & .gitignore

These skills write small metadata files (screenshot/diff JSON) under `.diffy-skills/`. Add it to your
project's `.gitignore`:

```gitignore
.diffy-skills/
```

## Safety: no project deletion

These skills **never delete Diffy projects.** Deleting a project (or projects) is out of scope for this
plugin — Claude will refuse to do it via the `diffy` CLI, a direct Diffy API/`curl` call, or the Diffy web
UI, and will point you to the Diffy dashboard to do it manually. As a hard backstop, this repo's
[`.claude/settings.json`](../../.claude/settings.json) `permissions.deny` list blocks any delete-shaped
`diffy` command outright.

## Notes

Diffy is a cloud service — these skills need an account, API key, and project. Because capture happens
remotely, the environment URLs you compare must be reachable from Diffy's infrastructure (public URLs, or
reachable with the HTTP basic-auth credentials you pass).
