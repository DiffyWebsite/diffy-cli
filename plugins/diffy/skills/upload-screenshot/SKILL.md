---
name: upload-screenshot
description: >-
  Capture a running app with Playwright, upload the captured browser screenshots into Diffy with
  screenshot:create-uploaded, and return a screenshot ID. Use when the user asks to capture a local or
  running app for Diffy, upload a current UI screenshot set, or create a Diffy screenshot ID from a live
  app URL.
allowed-tools: Bash, Read, Write
---

# upload-screenshot

Capture a running app, upload the generated screenshot set, and return a `SCREENSHOT_ID`. Do not upload
pre-existing image files or pre-built payloads, create a visual diff, or summarize diff results in this
skill.

`$SKILL_DIR` is the directory containing this `SKILL.md`.

## Preflight

1. Resolve the CLI:
   - Prefer `diffy` from `PATH`.
   - Else, if `./vendor/bin/diffy` exists in the current repo, use that.
   - Else, ask the user to install it and stop until it is available. Suggested install (pick a bin
     directory the user can write to — `/usr/local/bin` may need `sudo`; Homebrew on Apple Silicon uses
     `/opt/homebrew/bin`):
     `wget -O /usr/local/bin/diffy https://github.com/diffywebsite/diffy-cli/releases/latest/download/diffy.phar && chmod a+x /usr/local/bin/diffy`
2. Check authentication: `~/.diffy-cli/diffy-cli.yaml` must exist. If it does not, run
   `diffy auth:login <API_KEY>` after the user provides the key (key from https://app.diffy.website/#/keys).

Required input:
- `PROJECT_ID`
- `APP_URL`, the base URL of the running app to capture, for example `http://localhost:3000`

If `APP_URL` is missing, ask the user for it. Do not upload existing image files or an existing
`upload.json`; this skill always captures the running app first.

## Capture a Running App, Then Upload

1. Fetch project pages and breakpoints:
   ```bash
   diffy project:get <PROJECT_ID>
   ```
   Extract:
   - `urls` or page list
   - `breakpoints`
   - environment URLs (`production`, `staging`, `development`) only as suggestions for `APP_URL`
2. Resolve `APP_URL`, the base URL to capture, for example `http://localhost:3000`.
3. Verify it is reachable:
   ```bash
   curl -sf -o /dev/null "<APP_URL>"
   ```
4. Ensure Playwright is available:
   ```bash
   node -e "require.resolve('playwright')"
   ```
   If missing, install once in the target project:
   `npm i -D playwright && npx playwright install chromium`
5. Capture pages and generate the upload payload:
   ```bash
   node "$SKILL_DIR/scripts/capture.mjs" \
     --url="<APP_URL>" \
     --pages="<comma-joined page list>" \
     --breakpoints="<comma-joined widths>" \
     --out=".diffy-skills/uploads/<snapshot-name>" \
     --name="<snapshot-name>"
   ```

Lazy-loaded content is handled automatically: before each full-page capture the script scrolls the page
top→bottom to trigger `loading="lazy"` / IntersectionObserver content, then waits for web fonts and images
to finish. So if below-the-fold content was previously blank, no extra flags are needed.

Optional tuning flags:
- `--delay=<ms>` final settle for animations/transitions *after* content has loaded
- `--wait-selector="<css>"` for app-ready markers
- `--height=<px>` for viewport height before full-page capture
- `--settle=<ms>` max wait for fonts and for images to finish (default `15000`)
- `--no-scroll` disable the auto-scroll pass (use for infinite-scroll pages that never stop growing)

The script writes PNGs and prints the generated `upload.json` path on stdout. Validate that the generated
payload has equal-length `urls`, `breakpoints`, and `files` arrays, then upload that path with:

```bash
diffy screenshot:create-uploaded <PROJECT_ID> .diffy-skills/uploads/<snapshot-name>/upload.json
```

## Output

After upload, write metadata to `.diffy-skills/uploads/<snapshot-name>/metadata.json`:

```json
{
  "projectId": 12345,
  "screenshotId": 67890,
  "snapshotName": "snapshot-20260706-120000",
  "uploadJson": ".diffy-skills/uploads/snapshot-20260706-120000/upload.json",
  "capturedAt": "2026-07-06T12:00:00Z"
}
```

Return:
- screenshot ID
- project ID
- snapshot name
- upload JSON path
- capture source (`running app`)

Stop there. Tell the user to use `compare-screenshots` when they want to compare this screenshot set.

This skill writes PNGs and payloads under `.diffy-skills/`; offer to add `.diffy-skills/` to the repo's
`.gitignore` if it is not already ignored.
