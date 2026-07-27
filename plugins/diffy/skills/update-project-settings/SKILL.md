---
name: update-project-settings
description: >-
  Change settings for an existing Diffy project with the diffy CLI. Use when the user asks to update,
  edit, change, or apply Diffy project settings such as pages, URLs, environments, breakpoints, masks,
  delays, headers, cookies, login settings, schedules, or multiple project configs.
allowed-tools: Bash, Read, Write
---

# update-project-settings

Update existing Diffy project settings only. Do not create projects, upload screenshots, create diffs, or
summarize diff results in this skill.

**Never delete projects.** This plugin must not delete a Diffy project (or projects) under any
circumstances — not via the `diffy` CLI, a direct Diffy API/`curl` call, or the Diffy web UI. Deleting is
out of scope even when "updating" settings. If the user asks to delete a project, refuse and tell them to
do it manually in the Diffy dashboard.

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
- `PROJECT_ID`, unless the user provides a multi-project update config for `projects:update`.
- A settings config path, or exact settings changes to apply.

Preferred path:

```bash
diffy project:update <PROJECT_ID> <config.json-or-yaml>
diffy projects:update <multi-project-config.json>
```

When the user describes changes instead of providing a file:
1. Fetch the current settings:
   ```bash
   diffy project:get <PROJECT_ID>
   ```
2. Write a derived config under `.diffy-skills/project-settings/<PROJECT_ID>.json`.
3. Apply only the requested changes while preserving unrelated fields that appear in the fetched settings.
4. Validate JSON before applying. For YAML, preserve the existing file format if the user supplied YAML.

Common JSON fields (the base/production URL is the `production` field, same as `create-project`):

```json
{
  "name": "Project name",
  "breakpoints": [375, 1280, 1920],
  "production": "https://www.example.com",
  "staging": "https://staging.example.com",
  "development": "https://dev.example.com",
  "urls": ["/", "/about", "/pricing"],
  "schedule": {
    "type": ""
  }
}
```

Diffy-exported YAML may use grouped keys such as `basic` and `advanced`; keep that structure when the input
file is YAML.

## Update

For one project:

```bash
diffy project:update <PROJECT_ID> <config.json-or-yaml>
```

Expected success:

```text
Project <PROJECT_ID> updated.
```

For multiple projects:

```bash
diffy projects:update <multi-project-config.json>
```

Verify after updating:

```bash
diffy project:get <PROJECT_ID>
```

Return a concise summary:
- project ID(s)
- config path applied
- changed settings
- verification result

If the command fails, do not attempt a screenshot or diff. Show the exact error and the config path so the
user can correct the settings.

This skill writes derived configs under `.diffy-skills/`; offer to add `.diffy-skills/` to the repo's
`.gitignore` if it is not already ignored.
