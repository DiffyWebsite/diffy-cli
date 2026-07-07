---
name: visual-diff
description: >-
  Capture before/after screenshots of a running UI and produce a visual-regression diff with the Diffy
  CLI. Use when the user makes UI changes and wants to see what changed visually: run once BEFORE edits to
  set a baseline, then again AFTER edits to compare. Pages and breakpoints are read from the Diffy project
  (no config file). Reports % changed per page/breakpoint plus a shareable diff report link. Trigger
  phrases: "visual diff", "compare the UI", "what changed visually", "screenshot diff", "did my UI change".
allowed-tools: Bash, Read, Write
---

# visual-diff

Two-phase visual regression for a running UI, built on the `diffy` CLI:

1. **Baseline** (run *before* editing): screenshot the current UI, upload it to Diffy, remember its ID.
2. **Compare** (run *after* editing): screenshot again, upload, diff against the baseline, and show what
   changed (per-page/per-breakpoint % and a shareable report link).

**The pages and breakpoints come from the Diffy project itself** (`diffy project:get`) — there is no config
file to maintain. Screenshots are captured deterministically with a bundled Playwright script. The upload
JSON that `diffy` consumes (`snapshotName` / `urls` / `breakpoints` / `files`) is generated automatically
by that script; you never write it by hand.

`$SKILL_DIR` below = the directory containing this SKILL.md (where `scripts/` and `reference/` live). For
exact `diffy` syntax and data shapes, consult `reference/diffy-cli.md`.

---

## Step 1 — Preflight checks (fail fast, print the fix)

Run these and stop with the specific remedy if any fails:

- **CLI present:** `command -v diffy` — if missing, look for `./vendor/bin/diffy` in the current repo; else
  ask the user to install it and stop until it is available. Suggested install (pick a bin directory the
  user can write to — `/usr/local/bin` may need `sudo`; Homebrew on Apple Silicon uses `/opt/homebrew/bin`):
  `wget -O /usr/local/bin/diffy https://github.com/diffywebsite/diffy-cli/releases/latest/download/diffy.phar && chmod a+x /usr/local/bin/diffy`
- **Authenticated:** check `~/.diffy-cli/diffy-cli.yaml` exists. If not, run
  `diffy auth:login <API_KEY>` (key from https://app.diffy.website/#/keys).
- **Playwright available:** `node -e "require.resolve('playwright')"`. If it fails, run once:
  `npm i -D playwright && npx playwright install chromium`.

## Step 2 — Pick the phase & resolve the project

- If invoked with an explicit argument `baseline` or `compare`, use that. Otherwise infer: if
  `.diffy-visual/state.json` does **not** exist → **Phase A (baseline)**; if it exists → **Phase B (compare)**.
- Offer to add `.diffy-visual/` to the project's `.gitignore` if it isn't already ignored.

Resolve the **projectId**:
- Phase B: read it from `.diffy-visual/state.json`.
- Phase A: use the skill argument if one was given; else run `diffy project:list` and ask the user which
  project to use.

## Step 3 — Read pages & breakpoints from the project

```bash
diffy project:get <projectId>        # prints full project settings as JSON
```

Extract from that JSON:
- **breakpoints** — the `breakpoints` array (viewport widths in px).
- **pages** — the page list (the `urls` array). Entries may be absolute URLs (e.g.
  `https://prod.site/faq`) or paths (`/faq`); the capture script handles both and always renders the path
  against your local `appUrl`.
- **environments** (Phase A only) — `production` / `staging` / `development` base URLs, used to suggest a
  default `appUrl`.

If field names differ in the response, show the user the JSON and confirm which fields hold the breakpoints
and page list. Reuse the **same** breakpoints + pages for both baseline and compare so the diff lines up
(Phase B takes them from `state.json`, not from a fresh `project:get`).

## Step 4 — Resolve the local URL (`appUrl`)

`appUrl` is the base URL of the **running app to screenshot** (your local dev server, e.g.
`http://localhost:3000`).
- Phase B: reuse `appUrl` from `state.json`.
- Phase A: use the skill argument if given; else suggest a non-empty `development`/`staging`/`production`
  URL from Step 3 as the default and ask the user to confirm or supply the local dev URL.

Then verify it is reachable: `curl -sf -o /dev/null "<appUrl>"`. If it fails, ask the user to start their
dev server first.

---

## Phase A — Baseline (run BEFORE the user's UI edits)

1. Capture (pages/breakpoints from Step 3; `--name` is auto and internal):
   ```bash
   node "$SKILL_DIR/scripts/capture.mjs" \
     --url="<appUrl>" --pages="<comma-joined page list>" --breakpoints="<comma-joined widths>" \
     --out=".diffy-visual/baseline" --name="baseline-$(date +%Y%m%d-%H%M%S)"
   ```
   Progress logs go to stderr; stdout is the path to the generated `upload.json`.

2. Upload → capture the screenshot ID:
   ```bash
   BEFORE_ID=$(diffy screenshot:create-uploaded <projectId> .diffy-visual/baseline/upload.json)
   ```

3. Persist state — write `.diffy-visual/state.json`:
   ```json
   {
     "baselineScreenshotId": <BEFORE_ID>,
     "projectId": <projectId>,
     "appUrl": "<appUrl>",
     "pages": [...],
     "breakpoints": [...],
     "capturedAt": "<ISO timestamp>"
   }
   ```

4. Tell the user the baseline is captured (show `BEFORE_ID`). They can now make their UI changes and
   re-invoke this skill to compare. **Stop here** — do not proceed to Phase B in the same run.

---

## Phase B — Compare (run AFTER the user's UI edits)

1. Read `.diffy-visual/state.json` for `baselineScreenshotId`, `projectId`, `appUrl`, and the **same**
   `pages` + `breakpoints` used for the baseline.

2. Capture the current UI:
   ```bash
   node "$SKILL_DIR/scripts/capture.mjs" \
     --url="<appUrl>" --pages="<same page list>" --breakpoints="<same widths>" \
     --out=".diffy-visual/after" --name="after-$(date +%Y%m%d-%H%M%S)"
   ```

3. Upload → new screenshot ID:
   ```bash
   AFTER_ID=$(diffy screenshot:create-uploaded <projectId> .diffy-visual/after/upload.json)
   ```

4. Create the diff and wait for it:
   ```bash
   DIFF_ID=$(diffy diff:create <projectId> <baselineScreenshotId> <AFTER_ID> --wait --name="Claude UI changes $(date +%Y-%m-%d)")
   ```

5. Fetch results as JSON:
   ```bash
   diffy diff:get-result "$DIFF_ID" --format=json
   ```

## Step 5 — Show the changes

Parse the JSON from Phase B and present:
- **Overall:** `result` (% of pages changed). `0` means "No changes found".
- **Per-page / per-breakpoint table:** iterate `diffs[url][breakpoint].percentageChanges`; list the pages
  and breakpoints that changed, sorted by change % descending. Surface non-zero entries prominently.
- **Report link:** `diffSharedUrl` — the shareable visual diff.

Offer to open the report so the user can see it visually: `open "<diffSharedUrl>"` (macOS), or use the
Chrome browser tools to open and screenshot it inline. Give a concise verdict, e.g. "3 of 6 page/breakpoint
views changed; largest: `/pricing` @ 375px (12.4%). Report: <diffSharedUrl>".

---

## Notes, resets, and troubleshooting

- **Re-baseline:** running Phase A again overwrites the baseline. To force a fresh start, delete
  `.diffy-visual/state.json` (and optionally `.diffy-visual/`).
- **Noisy diffs** (animations, fonts, lazy content): re-capture with the script's tuning flags
  `--delay=<ms>` and/or `--wait-selector="<css>"`. Keep `pages`/`breakpoints` identical across phases.
- **Async diffs:** without `--wait`, `diff:create` returns immediately and Diffy finishes in the
  background; poll with `diffy diff:get-status <DIFF_ID>`. This skill uses `--wait` by default.
- **Cloud dependency:** this skill uses the Diffy service — it needs an account, an API key, and a project.
  It does not do a purely local pixel diff.
- **Full command reference:** `reference/diffy-cli.md` in this skill folder.
