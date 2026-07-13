---
name: compare-environments
description: >-
  Compare two environments of a Diffy project server-side with project:compare — Diffy screenshots both
  environments on its own servers and produces a visual-regression diff, returning a diff ID. Use when the
  user wants to compare prod vs staging, prod vs a custom/PR/UAT URL, or two arbitrary URLs remotely,
  without capturing anything locally. For comparing a locally running app, use diffy-local's visual-diff.
allowed-tools: Bash, Read, Write
---

# compare-environments

Compare two environments of a Diffy project entirely on Diffy's servers and return a `DIFF_ID`. Diffy
screenshots both sides remotely and diffs them in a single command — nothing is captured on this machine.
This is the remote analog of `diffy-local`'s `visual-diff`.

For exact command syntax and result fields, consult `reference/diffy-cli-remote.md` in this skill folder.

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
- `ENV1` and `ENV2` — the two environments to compare. Each is one of `production`, `staging`,
  `development`, or `custom` (short aliases `prod`, `stage`, `dev` are accepted). Treat `ENV1` as the
  baseline/before side and `ENV2` as the after side unless the user says otherwise.

If either environment is missing, ask which two to compare. Confirm the configured environment URLs are set
with `diffy project:get <PROJECT_ID>` when in doubt.

## Compare

Configured environments (URLs stored on the project):

```bash
diffy project:compare <PROJECT_ID> <ENV1> <ENV2> --wait --name="<diff-name>"
```

When a side is a one-off `custom` URL, pass `--env1Url` / `--env2Url` (with optional basic auth
`--env1User`/`--env1Pass`, `--env2User`/`--env2Pass`):

```bash
# production vs a PR/UAT build
diffy project:compare <PROJECT_ID> prod custom --env2Url="https://pr-123.example.com" --wait --name="PR 123"

# two arbitrary URLs, with a GitHub check tied to a commit
diffy project:compare <PROJECT_ID> custom custom \
  --env1Url="https://site-a.com" --env2Url="https://site-b.com" \
  --commit-sha="29b872765b21387b7adfd67fd16b7f11942e1a56" --wait
```

Notes:
- The command prints the `DIFF_ID` to stdout — capture it with `$(...)`:
  `DIFF_ID=$(diffy project:compare <PROJECT_ID> prod stage --wait)`.
- `--wait` polls every 10s until the diff completes (default `--max-wait=1200` seconds). Without it the
  diff runs in the background and Diffy notifies by email/Slack; the ID is still returned immediately.
- `--name` labels the diff in the Diffy UI. If the user gives a name use it exactly; otherwise use a
  concise generated one, e.g. `prod vs stage 2026-07-06`.
- `--commit-sha=<sha>` attaches a GitHub check for the given commit (CI use).
- To reuse an already-captured screenshot set for a side instead of re-shooting it, use the `existing`
  environment with `--screenshot1=<id>` / `--screenshot2=<id>`, e.g.
  `diffy project:compare <PROJECT_ID> existing prod --screenshot1=<ID> --wait`.

## Output

Write metadata to `.diffy-skills/diffs/last-diff.json`:

```json
{
  "projectId": 12345,
  "env1": "production",
  "env2": "staging",
  "diffId": 333,
  "name": "prod vs stage 2026-07-06",
  "waited": true,
  "createdAt": "2026-07-06T12:00:00Z"
}
```

Return:
- diff ID
- project ID
- the two environments compared (and any custom URLs)
- whether the command waited

Stop there. Tell the user to use `get-diff-info <DIFF_ID>` for the changed-page/breakpoint breakdown and
the shareable report link.

This skill writes metadata under `.diffy-skills/`; offer to add `.diffy-skills/` to the repo's `.gitignore`
if it is not already ignored.
