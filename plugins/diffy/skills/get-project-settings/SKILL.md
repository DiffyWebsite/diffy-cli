---
name: get-project-settings
description: >-
  Show an existing Diffy project's settings as YAML. Use when the user asks for project details,
  project settings, project config, "details on project <id>", or to view/get/show/export a project's
  configuration. Displays the exportable YAML (the dashboard "Export" format), never raw JSON.
allowed-tools: Bash, Read, Write
---

# get-project-settings

Read-only. Show an existing Diffy project's settings **as YAML** — the same grouped `basic`/`advanced`
format the dashboard "Export" button downloads (and that `project:update` accepts). Never print the raw
JSON from `project:get` to the user; the user finds it unreadable.

Do not create, update, or delete projects here, and do not take screenshots or create diffs.

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

## Inputs

Required:
- `PROJECT_ID` (e.g. from "details on project 30553" → `30553`).

## Show the settings

Print the project's export YAML:

```bash
diffy project:get-yaml <PROJECT_ID>
```

Show the YAML output to the user verbatim (in a `yaml` code block). This is the readable, exportable
representation. Do not convert it to JSON or a prose table unless the user explicitly asks.

### Fallback for older CLIs

`project:get-yaml` was added to diffy-cli; if the installed CLI reports the command is not defined, call
the underlying endpoint directly (it returns the identical YAML):

```bash
KEY=$(grep '^key:' ~/.diffy-cli/diffy-cli.yaml | awk '{print $2}')
TOKEN=$(curl -s -X POST https://app.diffy.website/api/auth/key \
  -H "Content-Type: application/json" -d "{\"key\":\"$KEY\"}" \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["token"])')
curl -s https://app.diffy.website/api/projects/<PROJECT_ID>/settings/download/yaml \
  -H "Authorization: Bearer $TOKEN"
```

(Only use the fallback if `project:get-yaml` is unavailable. If the account uses a non-default Diffy host,
substitute its API base URL.)

## Notes

- The YAML groups settings under `basic` (name, environments, tags, breakpoints, pages, monitoring) and
  `advanced` (mask/remove, delay, scroll, headers, cookies, custom JS/CSS, login, performance,
  stabilization), plus top-level `type` and `notify`.
- To *change* any of these settings, use the `update-project-settings` skill — this YAML is a valid input
  for `diffy project:update <PROJECT_ID> <file.yaml>`.
- If the user explicitly wants the raw JSON instead, run `diffy project:get <PROJECT_ID>`.
