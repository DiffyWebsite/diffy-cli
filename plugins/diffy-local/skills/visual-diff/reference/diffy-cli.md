# diffy CLI — reference for the `visual-diff` skill

Condensed cheat-sheet of the exact `diffy` commands and data shapes this skill relies on. `diffy` wraps the
Diffy cloud visual-comparison API. Everything is scoped to a numeric **PROJECT_ID**.

Each command prints a single machine-readable value (an ID) to **stdout**, so capture it with `$(...)`.

## Auth (one-time)

```bash
diffy auth:login <API_KEY>          # key from https://app.diffy.website/#/keys
```

Validates the key against the API, then stores it in `~/.diffy-cli/diffy-cli.yaml`. Every other command
reads that file; if it's missing they error telling you to run `auth:login`.

## Read a project's pages & breakpoints

```bash
diffy project:get <PROJECT_ID>          # full project settings as JSON
diffy project:list                       # JSON list of projects (find a PROJECT_ID)
```

You do **not** pass pages/breakpoints to the capture step — the diffy-worker engine reads them from the
project itself. `project:get`/`project:list` are only useful here to find a `PROJECT_ID` or to suggest a
default local URL from the `production`/`staging`/`development` fields.

## Capture a running app locally + upload -> a screenshot set

```bash
SCREENSHOT_ID=$(node "$PLUGIN_DIR/scripts/run-worker.mjs" \
  --project-id=<PROJECT_ID> --url="http://localhost:3000" --name="baseline-<timestamp>")
```

`run-worker.mjs` delegates to the `diffy-worker` project's local runner (`diffy-screenshots.js`), which
uses Diffy's **production** rendering pipeline: it fetches the project's pages, breakpoints, and advanced
settings, re-bases each page onto `--url`, captures every page × breakpoint on the host with Playwright,
uploads the set, and returns a `SCREENSHOT_ID`. Progress goes to stderr; stdout is just the numeric ID.

- The worker locates its checkout via `DIFFY_WORKER_DIR` (or a `diffy-worker/` / `../diffy-worker/`
  sibling), reads the API key from `~/.diffy-cli/diffy-cli.yaml`, and uploads via the same
  `create-custom-snapshot` endpoint the CLI's `screenshot:create-uploaded` uses — so the returned ID is a
  normal Diffy screenshot ID you feed straight into `diff:create`.
- Because capture and upload happen inside the worker, the plugin never builds an `upload.json` by hand
  and never calls `diffy screenshot:create-uploaded` for this flow.

> `diffy screenshot:create-uploaded <PROJECT_ID> <upload.json>` still exists in the CLI for pre-built image
> sets, but the local flow no longer uses it — the worker owns capture + upload end to end.

## Create a diff between two screenshot sets

```bash
diffy diff:create <PROJECT_ID> <SCREENSHOT_ID_BEFORE> <SCREENSHOT_ID_AFTER> --wait --name="Claude UI changes"
# prints DIFF_ID
```

- `--wait` polls every 10s until the diff completes (default `--max-wait=1200` seconds).
- `--name` is an optional label shown in the Diffy UI.

## Read diff results

```bash
diffy diff:get-changes-percent <DIFF_ID>          # top-line % of pages changed
diffy diff:get-status <DIFF_ID>                    # completed? (prints 1, or empty when not done)
diffy diff:get-result <DIFF_ID> --format=json      # full raw result (parse this)
diffy diff:get-result <DIFF_ID> --format=junit-xml # CI-friendly JUnit report
```

Fields in the `--format=json` output (`$diff->data`) the skill uses:

- `result` — percentage of **pages** that changed (0 = "No changes found").
- `diffSharedUrl` — shareable web report link (the visual diff to show the user).
- `name`, `state` — label and completion state (completed states: 2, 3, 4, 7).
- `diffs` — nested map `diffs[url][breakpoint]` where each entry has `percentageChanges`
  (per-page / per-breakpoint pixel-area change %). Iterate this for the breakdown table.

## Other handy commands (not required by the core flow)

```bash
diffy screenshot:list <PROJECT_ID> --limit=1       # inspect recent screenshot sets (PHP var_export dump)
diffy diff:list <PROJECT_ID> 0                      # table of recent diffs (id, changes, state, sharedUrl)
```
