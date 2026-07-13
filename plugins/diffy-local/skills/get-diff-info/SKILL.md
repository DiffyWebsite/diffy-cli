---
name: get-diff-info
description: >-
  Fetch and summarize Diffy diff status, result JSON, changed percentage, per-page and per-breakpoint
  differences, JUnit output, or recent diff lists. Use when the user asks for diff info, diff results,
  changed pages, percent changed, report link, or whether a Diffy diff is complete.
allowed-tools: Bash, Read, Write
---

# get-diff-info

Read and summarize Diffy diff information only. Do not create projects, update settings, upload
screenshots, or create new diffs in this skill.

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

## Resolve the Diff

Preferred input: `DIFF_ID`.

If the user gives a project but not a diff ID, list recent diffs:

```bash
diffy diff:list <PROJECT_ID> 0
```

This prints a rendered text table (not JSON) with columns `id, changes, state, jobs, estimate,
sharedUrl`. Read the diff ID from the `id` column.

Use the latest diff only when the user clearly asked for the latest one. Otherwise ask which `DIFF_ID` to
inspect.

## Commands

Status:

```bash
diffy diff:get-status <DIFF_ID>
```

This prints `1` when the diff is complete and nothing (empty output) when it is not — empty output is
not an error, just "not finished yet." For a reliable completion check, read the `state` field from the
JSON result below.

Top-line changed percentage:

```bash
diffy diff:get-changes-percent <DIFF_ID>
```

Full JSON result:

```bash
diffy diff:get-result <DIFF_ID> --format=json
```

JUnit report, when requested:

```bash
diffy diff:get-result <DIFF_ID> --format=junit-xml
```

Save JSON result to `.diffy-skills/diffs/<DIFF_ID>.json` before parsing when the user asks for a report or
when the result is large.

## Summarize JSON Result

Parse these fields from the JSON result:
- `result`: overall percentage of pages changed. `0` means no pages changed.
- `diffSharedUrl`: shareable visual report link.
- `name`: diff name.
- `state`: numeric Diffy state (completed states: 2, 3, 4, 7).
- `diffs`: nested map of `diffs[url][breakpoint]`, where each item may contain `percentageChanges`.

Return:
- diff ID and name
- completion state
- overall changed percentage
- report link, if present
- count of changed page/breakpoint views
- table of non-zero entries sorted by `percentageChanges` descending

Suggested table columns:

```text
URL | Breakpoint | Changed
/pricing | 375 | 12.4%
/ | 1280 | 1.1%
```

If there are no non-zero entries, say that no visual changes were reported.

If the diff is not complete, return the current status and tell the user to retry this same skill later.
Do not create another diff unless the user explicitly asks for a new comparison.

If you saved the JSON result, it lives under `.diffy-skills/`; offer to add `.diffy-skills/` to the repo's
`.gitignore` if it is not already ignored.
