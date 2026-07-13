---
name: upload-screenshot
description: >-
  Capture a running app locally with the diffy-worker screenshot engine (the same rendering
  pipeline Diffy runs in production) and upload it to Diffy, returning a screenshot ID. Use when
  the user asks to capture a local or running app for Diffy, upload a current UI screenshot set, or
  create a Diffy screenshot ID from a live app URL.
allowed-tools: Bash, Read, Write
---

# upload-screenshot

Capture a running app with the **diffy-worker** engine, upload the generated screenshot set, and
return a `SCREENSHOT_ID`. The worker uses Diffy's production rendering pipeline: it reads the
project's pages, breakpoints, and advanced settings itself, re-bases each page onto your local URL,
captures every page × breakpoint on the host with Playwright, uploads the set, and returns the ID —
so there is no local `upload.json` to build and no separate `screenshot:create-uploaded` call.

Do not upload pre-existing image files or pre-built payloads, create a visual diff, or summarize
diff results in this skill.

`$PLUGIN_DIR` below = the diffy-local plugin root (the folder containing `.claude-plugin/` and
`scripts/`), i.e. two levels up from this `SKILL.md`.

## Preflight

1. **Capture engine.** Local capture runs the `diffy-worker` project. Check it with:
   ```bash
   node "$PLUGIN_DIR/scripts/run-worker.mjs" --check
   ```
   If it reports the worker is **not installed**, do a one-time setup: tell the user it will download
   the capture engine (clones `diffy-worker` + Playwright Chromium, ~150 MB, a couple of minutes),
   then run:
   ```bash
   node "$PLUGIN_DIR/scripts/run-worker.mjs" --provision
   ```
   That clones the engine into a cache dir (`~/.cache/diffy-local/diffy-worker`) and installs it;
   later runs reuse it. Advanced: set `DIFFY_WORKER_DIR` to an existing checkout to skip the download.
   Surface `run-worker.mjs`'s own messages rather than guessing.
2. **Authentication.** The worker reads the API key from `~/.diffy-cli/diffy-cli.yaml` (or
   `$DIFFYCLI_CONFIG`, or `$DIFFY_API_KEY`). If none exists, run `diffy auth:login <API_KEY>` after
   the user provides the key (from https://app.diffy.website/#/keys).

Required input:
- `PROJECT_ID`
- `APP_URL`, the base URL of the running app to capture, for example `http://localhost:3000`

If `APP_URL` is missing, ask the user for it. This skill always captures the running app; it never
uploads existing image files.

## Capture a Running App, Then Upload

1. Verify `APP_URL` is reachable (accept any HTTP response — a 401/403/404 at `/` still means the
   server is up):
   ```bash
   curl -s -o /dev/null -w "%{http_code}" "<APP_URL>"
   ```
   If this returns `000` (no connection), ask the user to start their dev server first.
2. Capture + upload with the worker engine, capturing the screenshot ID from stdout:
   ```bash
   SCREENSHOT_ID=$(node "$PLUGIN_DIR/scripts/run-worker.mjs" \
     --project-id=<PROJECT_ID> --url="<APP_URL>" --name="<snapshot-name>")
   ```
   Progress logs (per page/breakpoint) stream to stderr; stdout is just the numeric `SCREENSHOT_ID`.
   The pages, breakpoints, and advanced capture settings all come from the Diffy project itself — you
   do not pass them here.

If the command exits non-zero, show its stderr (it explains missing worker setup, auth, or an
unreachable app) and stop. Do not fall back to any other capture method.

## Output

After upload, write metadata to `.diffy-skills/uploads/<snapshot-name>/metadata.json`:

```json
{
  "projectId": 12345,
  "screenshotId": 67890,
  "snapshotName": "snapshot-20260706-120000",
  "appUrl": "http://localhost:3000",
  "capturedAt": "2026-07-06T12:00:00Z"
}
```

Return:
- screenshot ID
- project ID
- snapshot name
- app URL captured
- capture source (`running app via diffy-worker`)

Stop there. Tell the user to use `compare-screenshots` when they want to compare this screenshot set.

This skill writes metadata under `.diffy-skills/`; offer to add `.diffy-skills/` to the repo's
`.gitignore` if it is not already ignored.
