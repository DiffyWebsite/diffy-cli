# diffy CLI — reference for the diffy-remote skills

Condensed cheat-sheet of the exact `diffy` commands and data shapes the **remote** skills rely on. `diffy`
wraps the Diffy cloud visual-comparison API; with these commands Diffy captures the screenshots on **its own
servers** — there is no local browser, no Playwright, and no upload of local PNGs. Everything is scoped to a
numeric **PROJECT_ID**.

Each command prints a single machine-readable value (an ID) to **stdout**, so capture it with `$(...)`.

## Auth (one-time)

```bash
diffy auth:login <API_KEY>          # key from https://app.diffy.website/#/keys
```

Validates the key against the API, then stores it in `~/.diffy-cli/diffy-cli.yaml`. Every other command
reads that file; if it's missing they error telling you to run `auth:login`.

## Read a project's environments, pages & breakpoints

```bash
diffy project:get <PROJECT_ID>          # full project settings as JSON
diffy project:list                       # JSON list of projects (find a PROJECT_ID)
```

Fields relevant here:
- `production` / `staging` / `development` — the environment base URLs Diffy screenshots for those
  environment names. If an environment URL is empty, screenshotting that environment will fail — configure
  it first (see the `update-project-settings` skill) or use a `custom` URL.
- `breakpoints`, `urls` — the widths and pages Diffy captures; managed on the project, not per command.

## Screenshot an environment remotely

```bash
diffy screenshot:create <PROJECT_ID> <ENVIRONMENT> --wait      # prints SCREENSHOT_ID
```

- `<ENVIRONMENT>`: `production` | `staging` | `development` | `custom` (aliases `prod` | `stage` | `dev`).
- `custom` requires `--envUrl="https://…"`; optional HTTP basic auth via `--envUser` / `--envPass`.
- `--wait` polls every 10s until Diffy finishes (default `--max-wait=1200` seconds). Without it the command
  returns the ID immediately and Diffy keeps capturing in the background.

Set the captured run as the project baseline in one step:

```bash
diffy screenshot:create-baseline <PROJECT_ID> <ENVIRONMENT>   # captures remotely, then sets baseline (waits by default)
```

## Compare two environments remotely (screenshot both + diff)

```bash
diffy project:compare <PROJECT_ID> <ENV1> <ENV2> --wait --name="prod vs stage"   # prints DIFF_ID
```

Diffy screenshots both environments and produces the diff server-side. Options:
- `--env1Url` / `--env2Url` — URL for a `custom` side (plus `--env1User`/`--env1Pass`,
  `--env2User`/`--env2Pass` for basic auth).
- `--commit-sha=<sha>` — attach a GitHub check for that commit (CI).
- `--name` — label shown in the Diffy UI.
- `--wait` / `--max-wait=<seconds>` — as above, but for the diff completing.
- `existing` env + `--screenshot1=<id>` / `--screenshot2=<id>` — reuse an already-captured screenshot set
  for a side instead of re-shooting it.

Common shapes:

```bash
diffy project:compare <PROJECT_ID> prod stage --wait
diffy project:compare <PROJECT_ID> prod custom --env2Url="https://pr-123.example.com" --wait --name="PR 123"
diffy project:compare <PROJECT_ID> custom custom --env1Url="https://a.com" --env2Url="https://b.com" --commit-sha="<sha>"
```

## Diff between two screenshot IDs (either flow)

If you already have two `SCREENSHOT_ID`s (e.g. two `screenshot:create` runs), diff them directly instead of
using `project:compare`:

```bash
diffy diff:create <PROJECT_ID> <SCREENSHOT_ID_BEFORE> <SCREENSHOT_ID_AFTER> --wait --name="…"   # prints DIFF_ID
```

(That is what the `compare-screenshots` skill does.)

## Read diff results

```bash
diffy diff:get-changes-percent <DIFF_ID>          # top-line % of pages changed
diffy diff:get-status <DIFF_ID>                    # completed? (prints 1, or empty when not done)
diffy diff:get-result <DIFF_ID> --format=json      # full raw result (parse this)
diffy diff:get-result <DIFF_ID> --format=junit-xml # CI-friendly JUnit report
diffy diff:list <PROJECT_ID> 0                      # table of recent diffs (id, changes, state, sharedUrl)
```

Fields in the `--format=json` output the `get-diff-info` skill uses:
- `result` — percentage of **pages** that changed (0 = "No changes found").
- `diffSharedUrl` — shareable web report link (the visual diff to show the user).
- `name`, `state` — label and completion state (completed states: 2, 3, 4, 7).
- `diffs` — nested map `diffs[url][breakpoint]` where each entry has `percentageChanges`
  (per-page / per-breakpoint change %). Iterate this for the breakdown table.
