---
name: remote-screenshot
description: >-
  Trigger Diffy to capture a screenshot set of a project environment on Diffy's own servers (no local
  browser, no Playwright) with screenshot:create, and return a screenshot ID. Use when the user asks to
  screenshot production/staging/development or a live custom URL remotely, take Diffy screenshots of an
  environment, or set a new remote baseline. Not for capturing a locally running app — that is diffy-local.
allowed-tools: Bash, Read, Write
---

# remote-screenshot

Ask Diffy to screenshot a project **environment** on its servers and return a `SCREENSHOT_ID`. Nothing is
captured on this machine — no Playwright, no local dev server. Do not create a diff or summarize diff
results in this skill.

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
- `ENVIRONMENT` — one of `production`, `staging`, `development`, or `custom` (short aliases `prod`, `stage`,
  `dev` are accepted).

If `ENVIRONMENT` is missing, ask which one to shoot. For `production`/`staging`/`development` the URL comes
from the project's own settings (`diffy project:get <PROJECT_ID>` to confirm the environment URLs are set);
this skill does not need a local URL.

## Capture Remotely

For a configured environment:

```bash
diffy screenshot:create <PROJECT_ID> <ENVIRONMENT> --wait
```

For a one-off `custom` URL (not stored on the project), pass `--envUrl` (required for `custom`) plus
optional HTTP basic-auth credentials:

```bash
diffy screenshot:create <PROJECT_ID> custom --envUrl="https://example.com" --wait
diffy screenshot:create <PROJECT_ID> custom --envUrl="https://example.com" --envUser="user" --envPass="secret" --wait
```

Notes:
- The command prints the `SCREENSHOT_ID` to stdout — capture it with `$(...)`:
  `SCREENSHOT_ID=$(diffy screenshot:create <PROJECT_ID> production --wait)`.
- `--wait` polls every 10s until Diffy finishes capturing (default `--max-wait=1200` seconds). Without it
  the command returns immediately and Diffy keeps working in the background; the ID is still valid.
- `custom` without `--envUrl` errors — always supply the URL for `custom`.

### Setting a baseline instead

If the user wants this run to become the project's baseline (rather than just a screenshot set to diff
later), use `screenshot:create-baseline`, which captures remotely **and** marks the result as the baseline
set. `--wait` defaults to on here:

```bash
diffy screenshot:create-baseline <PROJECT_ID> <ENVIRONMENT>
```

## Output

Write metadata to `.diffy-skills/screenshots/<ENVIRONMENT>-<timestamp>.json`:

```json
{
  "projectId": 12345,
  "screenshotId": 67890,
  "environment": "production",
  "baseline": false,
  "capturedAt": "2026-07-06T12:00:00Z"
}
```

Return:
- screenshot ID
- project ID
- environment (and custom URL if used)
- whether it was set as baseline
- whether the command waited

Stop there. Tell the user to use `compare-screenshots` to diff this screenshot set against another ID, or
`compare-environments` to compare two environments in one shot.

This skill writes metadata under `.diffy-skills/`; offer to add `.diffy-skills/` to the repo's `.gitignore`
if it is not already ignored.
