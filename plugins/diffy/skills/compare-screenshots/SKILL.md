---
name: compare-screenshots
description: >-
  Create a Diffy visual diff from two existing screenshot set IDs with diff:create. Use when the user asks
  to compare screenshots, compare two uploaded screenshot sets, create a diff from screenshot IDs, or get a
  diff ID for before/after screenshots.
allowed-tools: Bash, Read, Write
---

# compare-screenshots

Create a diff from two screenshot set IDs and return a `DIFF_ID`. Do not upload screenshots or do detailed
diff result analysis in this skill.

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

Required:
- `PROJECT_ID`
- first screenshot ID
- second screenshot ID

If screenshot IDs are missing, inspect recent screenshot sets:

```bash
diffy screenshot:list <PROJECT_ID> --limit=10
```

This prints a PHP `var_export` dump, not JSON. Read each set's ID from its `'id' => <number>` line
rather than trying to parse the output as JSON.

Ask only for the missing ID(s). Treat the first ID as the baseline/before screenshot and the second ID as
the after/current screenshot unless the user says otherwise.

## Compare

Use `--wait` by default unless the user explicitly asks to start the diff asynchronously.

```bash
diffy diff:create <PROJECT_ID> <SCREENSHOT_ID_BEFORE> <SCREENSHOT_ID_AFTER> --wait --name="<diff-name>"
```

The command prints the `DIFF_ID`.

Naming:
- If the user provides a name, use it exactly.
- Otherwise use a concise generated name, for example `Uploaded screenshots 2026-07-06`.

Wait behavior:
- `--wait` polls until Diffy completes or fails.
- `--max-wait=<seconds>` can be added when the user needs a custom timeout.
- If the command returns before completion, use `get-diff-info` later to check status and results.

## Output

Write metadata to `.diffy-skills/diffs/last-diff.json`:

```json
{
  "projectId": 12345,
  "beforeScreenshotId": 111,
  "afterScreenshotId": 222,
  "diffId": 333,
  "name": "Uploaded screenshots 2026-07-06",
  "createdAt": "2026-07-06T12:00:00Z"
}
```

Return:
- diff ID
- project ID
- before screenshot ID
- after screenshot ID
- whether the command waited

Stop there. Tell the user to use `get-diff-info` for the detailed changed-page report.

This skill writes metadata under `.diffy-skills/`; offer to add `.diffy-skills/` to the repo's `.gitignore`
if it is not already ignored.
