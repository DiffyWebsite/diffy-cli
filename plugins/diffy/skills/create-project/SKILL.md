---
name: create-project
description: >-
  Create a Diffy project with the diffy CLI from user-provided project details. Use when the user asks to
  create a Diffy project, set up a new project in Diffy, or turn a base URL, pages, and breakpoints into a
  new Diffy project. Requires the user to provide base_url and returns the created project ID.
allowed-tools: Bash, Read, Write
---

# create-project

Create Diffy projects only. Do not update existing project settings, upload screenshots, create diffs, or
summarize diff results in this skill.

Always generate a fresh JSON config from the user's input. Do not use the repo's example JSON or YAML files
as the project creation input.

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
- `base_url`: the production/base URL for the new project, for example `https://www.example.com`. This is
  written to the config as the `production` field (same field name `update-project-settings` uses).

If `base_url` is missing, ask the user for it before creating the config. Do not infer it from examples.

Optional:
- `name`: default to the hostname from `base_url` if omitted.
- `pages` or `urls`: default to only the homepage path `/` if omitted.
- `breakpoints`: default to `[640, 1024, 1200]` if omitted.
- `staging`: default to an empty string if omitted.
- `development`: default to an empty string if omitted.
- `schedule`: omit by default (no monitoring schedule). The create endpoint expects a schedule *string*
  such as `"mon:false, tue:false, wed:false, thu:false, fri:false, sat:false, sun:false"`, so only add this
  field when the user asks for a schedule.

Create a JSON config in `.diffy-skills/project-create/<safe-name>.json`. Do not overwrite an existing
config unless the user asked for that exact file to be replaced.

Generated JSON shape:

```json
{
  "name": "Project name",
  "breakpoints": [640, 1024, 1200],
  "production": "https://www.example.com",
  "staging": "https://staging.example.com",
  "development": "",
  "urls": [
    "https://www.example.com/",
    "https://www.example.com/about"
  ]
}
```

Rules:
- Keep `urls` absolute by resolving each page path against `base_url`.
- Do not create from example/sample config files.
- This skill creates one project per run unless the user explicitly provides several `base_url` values.

## Create

Run:

```bash
diffy project:create <generated-config.json>
```

The command prints created IDs in this form:

```text
[12345] created.
```

Parse and report the numeric project ID(s). Then verify each project:

```bash
diffy project:get <PROJECT_ID>
```

Return a concise result with:
- project ID
- project name
- production/base URL
- pages count
- breakpoints
- config file path used

If creation fails, show the exact CLI error and the config path. Do not retry by changing project settings;
that belongs to `update-project-settings`.

This skill writes its generated config under `.diffy-skills/`; offer to add `.diffy-skills/` to the repo's
`.gitignore` if it is not already ignored.
