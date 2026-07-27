---
name: upload-screenshot
description: >-
  Capture a running app locally inside Diffy's screenshot-worker Docker container (the same rendering
  pipeline Diffy runs in production) and upload it to Diffy, returning a screenshot ID. Use when
  the user asks to capture a local or running app for Diffy, upload a current UI screenshot set, or
  create a Diffy screenshot ID from a live app URL. This captures on THIS machine; to have Diffy
  screenshot a deployed environment on its own servers instead, use the remote-screenshot skill. If
  the user is ambiguous about local vs remote capture, ask.
allowed-tools: Bash, Read, Write
---

# upload-screenshot

Capture a running app inside Diffy's **screenshot-worker Docker container**, upload the generated
screenshot set, and return a `SCREENSHOT_ID`. The container is Diffy's production rendering runtime: the
worker reads the project's pages, breakpoints, and advanced settings itself, re-bases each page onto
your local URL, captures every page × breakpoint with the container's Chromium, uploads the set, and
returns the ID — so there is no local `upload.json` to build and no separate `screenshot:create-uploaded`
call. The container reaches your host dev server via `host.docker.internal` (the runner rewrites
`localhost`/`127.0.0.1` for you).

Do not upload pre-existing image files or pre-built payloads, create a visual diff, or summarize
diff results in this skill.

`$PLUGIN_DIR` below = the diffy plugin root (the folder containing `.claude-plugin/` and
`scripts/`), i.e. two levels up from this `SKILL.md`.

**Local vs remote (only if unclear):** this skill captures the running app **on this machine** inside
Diffy's screenshot-worker **Docker container**. Diffy can instead screenshot a deployed environment
**on its own servers** — that is the `remote-screenshot` skill. If the user hasn't made clear which
they want, ask whether to capture **local** (here) or **remote** (Diffy captures on app.diffy.website);
if remote, switch to `remote-screenshot`.

## Preflight

1. **Capture engine (Docker).** Local capture runs inside Diffy's published screenshot-worker container
   (`diffywebsite2/screenshot-worker`) — the same Chromium/fonts/Playwright runtime Diffy uses in
   production, so every user renders identically. **Docker must be installed and running.** Check the
   whole setup with:
   ```bash
   node "$PLUGIN_DIR/scripts/run-worker.mjs" --check
   ```
   If it reports the setup is incomplete, do a one-time setup: tell the user it will pull the worker
   image (~1.1 GB) and fetch the worker code, then run:
   ```bash
   node "$PLUGIN_DIR/scripts/run-worker.mjs" --provision
   ```
   That pulls the image, clones the worker code into a cache dir (`~/.cache/diffy/diffy-worker`), and
   installs its deps **inside** the container; later runs reuse it. Advanced: set `DIFFY_WORKER_DIR` to
   a worker checkout you manage, or `DIFFY_WORKER_IMAGE` to pin a different image tag. Surface
   `run-worker.mjs`'s own messages rather than guessing.
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
2. Capture + upload with the worker container, capturing the screenshot ID from stdout:
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
- capture source (`running app via screenshot-worker container`)

Stop there. Tell the user to use `compare-screenshots` when they want to compare this screenshot set.

This skill writes metadata under `.diffy-skills/`; offer to add `.diffy-skills/` to the repo's
`.gitignore` if it is not already ignored.
