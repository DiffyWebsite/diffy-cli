---
name: visual-diff
description: >-
  Capture before/after screenshots of a running UI inside Diffy's screenshot-worker Docker container
  (the same rendering pipeline Diffy runs in production) and produce a visual-regression diff with the
  Diffy CLI. Use
  when the user makes UI changes and wants to see what changed visually: run once BEFORE edits to set
  a baseline, then again AFTER edits to compare. Pages, breakpoints, and capture settings come from
  the Diffy project (no config file). Reports % changed per page/breakpoint plus a shareable diff
  report link. This captures on THIS machine (a running local app); to compare two already-deployed
  environments/URLs on Diffy's servers instead, use the compare-environments skill. If the user is
  ambiguous about whether to capture locally or remotely, ask. Trigger phrases: "visual diff",
  "compare the UI", "what changed visually", "screenshot diff", "did my UI change".
allowed-tools: Bash, Read, Write
---

# visual-diff

Two-phase visual regression for a running UI, built on Diffy's screenshot-worker Docker container +
the `diffy` CLI:

1. **Baseline** (run *before* editing): capture the current UI, upload it to Diffy, remember its ID.
2. **Compare** (run *after* editing): capture again, upload, diff against the baseline, and show what
   changed (per-page/per-breakpoint % and a shareable report link).

**Pages, breakpoints, and advanced capture settings come from the Diffy project itself** — the worker
fetches them (`getProject`) and re-bases each page onto your local URL. There is no config file to
maintain and no `upload.json` to build; capture and upload happen in one step and return a screenshot
ID directly, using the same production pipeline that renders Diffy's baselines.

`$PLUGIN_DIR` below = the diffy plugin root (the folder containing `.claude-plugin/` and
`scripts/`), i.e. two levels up from this `SKILL.md`. For exact `diffy` diff syntax and result shapes,
consult `reference/diffy-cli.md`.

---

## Step 0 — Local vs remote (only if unclear)

This skill captures the UI **on this machine** inside Diffy's screenshot-worker container (a running
local dev server). Diffy can also do the whole thing **remotely on its servers** — that is the
`compare-environments` skill, used to diff two deployed environments/URLs (prod vs staging, a PR URL,
etc.). If the user's request doesn't make clear which they want — e.g. a bare "compare my UI" with no
local dev server mentioned — ask whether to run **local** (capture here) or **remote** (Diffy captures
on app.diffy.website), and if they choose remote, switch to `compare-environments`. When the user
clearly means their local running app (localhost, "as I edit", "my dev server"), just proceed.

## Step 1 — Preflight checks (fail fast, print the fix)

- **Capture engine (Docker):** local capture runs inside Diffy's published screenshot-worker container
  (`diffywebsite2/screenshot-worker`) — the same Chromium/fonts/Playwright runtime Diffy uses in
  production. **Docker must be installed and running.** Check it with
  `node "$PLUGIN_DIR/scripts/run-worker.mjs" --check`. If it reports the setup is incomplete, tell the
  user a one-time setup will pull the worker image (~1.1 GB) and fetch the worker code, then run
  `node "$PLUGIN_DIR/scripts/run-worker.mjs" --provision` (it pulls the image, clones the worker code
  into `~/.cache/diffy/diffy-worker`, and installs its deps inside the container; reused afterward).
  Advanced: `DIFFY_WORKER_DIR` points at a checkout you manage; `DIFFY_WORKER_IMAGE` pins a tag.
- **Diffy CLI (for the diff step):** `command -v diffy` — if missing, look for `./vendor/bin/diffy`
  in the current repo; else ask the user to install it. Suggested install (pick a bin directory the
  user can write to — `/usr/local/bin` may need `sudo`; Homebrew on Apple Silicon uses
  `/opt/homebrew/bin`):
  `wget -O /usr/local/bin/diffy https://github.com/diffywebsite/diffy-cli/releases/latest/download/diffy.phar && chmod a+x /usr/local/bin/diffy`
- **Authenticated:** `~/.diffy-cli/diffy-cli.yaml` must exist (created by `diffy auth:login <API_KEY>`,
  key from https://app.diffy.website/#/keys). Both the worker and the CLI read from it.

## Step 2 — Pick the phase & resolve the project

- If invoked with an explicit argument `baseline` or `compare`, use that. Otherwise infer: if
  `.diffy-visual/state.json` does **not** exist → **Phase A (baseline)**; if it exists → **Phase B
  (compare)**.
- Offer to add `.diffy-visual/` to the project's `.gitignore` if it isn't already ignored.

Resolve the **projectId**:
- Phase B: read it from `.diffy-visual/state.json`.
- Phase A: use the skill argument if one was given; else run `diffy project:list` and ask the user
  which project to use.

## Step 3 — Resolve the local URL (`appUrl`)

`appUrl` is the base URL of the **running app to screenshot** (your local dev server, e.g.
`http://localhost:3000`).
- Phase B: reuse `appUrl` from `state.json`.
- Phase A: use the skill argument if given; else ask the user for the local dev URL. You may run
  `diffy project:get <projectId>` to suggest a non-empty `development`/`staging`/`production` URL as a
  default, but the pages/breakpoints themselves are read by the worker — you do not pass them.

Verify it is reachable (accept any HTTP response — a 401/403/404 at `/` still means the server is up):
```bash
curl -s -o /dev/null -w "%{http_code}" "<appUrl>"
```
If it returns `000`, ask the user to start their dev server first.

---

## Phase A — Baseline (run BEFORE the user's UI edits)

1. Capture + upload with the worker engine (pages/breakpoints/settings come from the project):
   ```bash
   BEFORE_ID=$(node "$PLUGIN_DIR/scripts/run-worker.mjs" \
     --project-id=<projectId> --url="<appUrl>" --name="baseline-$(date +%Y%m%d-%H%M%S)")
   ```
   Progress streams to stderr; stdout is the numeric screenshot ID.

2. Persist state — write `.diffy-visual/state.json`:
   ```json
   {
     "baselineScreenshotId": <BEFORE_ID>,
     "projectId": <projectId>,
     "appUrl": "<appUrl>",
     "capturedAt": "<ISO timestamp>"
   }
   ```

3. Tell the user the baseline is captured (show `BEFORE_ID`). They can now make their UI changes and
   re-invoke this skill to compare. **Stop here** — do not proceed to Phase B in the same run.

---

## Phase B — Compare (run AFTER the user's UI edits)

1. Read `.diffy-visual/state.json` for `baselineScreenshotId`, `projectId`, and `appUrl`.

2. Capture the current UI (same project → same pages/breakpoints as the baseline):
   ```bash
   AFTER_ID=$(node "$PLUGIN_DIR/scripts/run-worker.mjs" \
     --project-id=<projectId> --url="<appUrl>" --name="after-$(date +%Y%m%d-%H%M%S)")
   ```

3. Create the diff and wait for it:
   ```bash
   DIFF_ID=$(diffy diff:create <projectId> <baselineScreenshotId> <AFTER_ID> --wait --name="Claude UI changes $(date +%Y-%m-%d)")
   ```

4. Fetch results as JSON:
   ```bash
   diffy diff:get-result "$DIFF_ID" --format=json
   ```

## Step 4 — Show the changes

Parse the JSON from Phase B and present:
- **Overall:** `result` (% of pages changed). `0` means "No changes found".
- **Per-page / per-breakpoint table:** iterate `diffs[url][breakpoint].percentageChanges`; list the
  pages and breakpoints that changed, sorted by change % descending. Surface non-zero entries
  prominently.
- **Report link:** `diffSharedUrl` — the shareable visual diff.

Offer to open the report so the user can see it visually: `open "<diffSharedUrl>"` (macOS), or use the
Chrome browser tools to open and screenshot it inline. Give a concise verdict, e.g. "3 of 6
page/breakpoint views changed; largest: `/pricing` @ 375px (12.4%). Report: <diffSharedUrl>".

---

## Notes, resets, and troubleshooting

- **Re-baseline:** running Phase A again overwrites the baseline. To force a fresh start, delete
  `.diffy-visual/state.json` (and optionally `.diffy-visual/`).
- **Capture fidelity:** the worker applies the project's own advanced settings (stabilization,
  scroll, delay, masking, login, cookies, headers, custom JS/CSS) — the same ones Diffy uses
  server-side — so a local capture lines up with a Diffy baseline. To change capture behavior, edit
  the **project settings** (see `update-project-settings`), not this skill.
- **Async diffs:** without `--wait`, `diff:create` returns immediately and Diffy finishes in the
  background; poll with `diffy diff:get-status <DIFF_ID>`. This skill uses `--wait` by default. If a
  diff is slow, confirm completion via the `state` field of `diff:get-result --format=json` before
  reporting.
- **Cloud dependency:** this skill uses the Diffy service — it needs an account, an API key, and a
  project. It does not do a purely local pixel diff.
- **Full command reference:** `reference/diffy-cli.md` in this skill folder.
