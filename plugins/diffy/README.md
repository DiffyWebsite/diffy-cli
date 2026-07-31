# diffy plugin

Claude Code skills for [Diffy](https://diffy.website/) visual-regression testing, built on the `diffy`
CLI. One plugin, **two capture modes**:

- **Local** — capture a **running app on your machine** inside Diffy's published
  [`screenshot-worker`](https://hub.docker.com/r/diffywebsite2/screenshot-worker) **Docker container**
  (the same Chromium/fonts/Playwright runtime Diffy runs in production, so a local capture renders exactly
  like a Diffy baseline) and upload the result. Requires Docker.
- **Remote** — have **Diffy capture on its own servers** (no local browser, no Docker, no Node) —
  screenshot an environment or diff two environments server-side.

Claude invokes these skills automatically based on what you ask; you can also call them explicitly with
their namespaced names (`/diffy:<skill>`). **When a request doesn't say where to capture** (e.g. a bare
"screenshot my site" or "compare my UI"), Claude asks whether to run **local** (this machine) or **remote**
(on [app.diffy.website](https://app.diffy.website)) before proceeding.

## Skills

| Skill | Mode | What it does |
|---|---|---|
| `/diffy:create-project` | either | Create a new Diffy project from a base URL, pages, and breakpoints. |
| `/diffy:get-project-settings` | either | Show an existing project's settings as export YAML (readable, not raw JSON). |
| `/diffy:update-project-settings` | either | Edit an existing project's pages, environments, breakpoints, masks, login, schedule, etc. |
| `/diffy:upload-screenshot` | local | Capture a running app in the screenshot-worker container and upload it as a Diffy screenshot set. |
| `/diffy:remote-screenshot` | remote | Ask Diffy to screenshot an environment (production/staging/development or a custom URL) on its servers. |
| `/diffy:compare-screenshots` | either | Create a diff from two existing screenshot set IDs. |
| `/diffy:get-diff-info` | either | Fetch and summarize a diff: % changed, per-page/per-breakpoint table, report link, JUnit. |
| `/diffy:visual-diff` | local | End-to-end before/after regression on your **local** UI: baseline, edit, then compare. |
| `/diffy:compare-environments` | remote | Compare two environments server-side (Diffy screenshots both, then diffs). |

`visual-diff` (local) and `compare-environments` (remote) are the one-shot orchestrators; the others are
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
- **Docker** (only for the **local** skills `upload-screenshot` and `visual-diff`) — local capture runs
  inside Diffy's published `diffywebsite2/screenshot-worker` container, so **Docker must be installed and
  running**. **You don't need to set anything up by hand:** the first time you capture, the plugin runs a
  one-time provisioning step that pulls the image (~1.1 GB), clones the worker code into a cache dir
  (`~/.cache/diffy/diffy-worker`), and installs its deps inside the container. You can also run it directly:
  ```bash
  node <path-to-plugin>/scripts/run-worker.mjs --provision   # one-time; --check to verify
  ```
  The container reaches your host dev server via `host.docker.internal` (the runner rewrites
  `localhost`/`127.0.0.1` in the URL). Advanced: set `DIFFY_WORKER_DIR=/path/to/diffy-worker` to use a
  worker checkout you manage, or `DIFFY_WORKER_IMAGE` to pin a different image tag. `git` is used to fetch
  the worker code during provisioning.
- **The remote skills need no Docker / Node** — screenshots are captured on Diffy's servers. Environment
  URLs must be configured on the project (or supplied as a `custom` URL) for Diffy to reach them.

## Working files & .gitignore

These skills write small metadata/state files (configs, screenshot/diff IDs, baseline state) under
`.diffy-skills/` (granular skills) and `.diffy-visual/` (`visual-diff`). The screenshots themselves are
captured in the worker's temp directory (local) or on Diffy's servers (remote) and uploaded to Diffy — they
are not stored in your repo. Add both dirs to your project's `.gitignore`:

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

Diffy is a cloud service — these skills need an account, API key, and project; they do not do a purely local
pixel diff. In **local** mode the screenshots are captured on your machine and uploaded; in **remote** mode
the environment URLs you compare must be reachable from Diffy's infrastructure (public URLs, or reachable
with the HTTP basic-auth credentials you pass).
