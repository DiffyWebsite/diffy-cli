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

The skill sources what to screenshot from `project:get` — no config file. Fields used:
- `breakpoints` — array of viewport widths.
- `urls` — the page list. Entries may be absolute URLs (`https://prod.site/faq`) or paths (`/faq`); the
  capture script uses only the path portion and re-bases it onto the local dev URL.
- `production` / `staging` / `development` — environment base URLs (used to suggest a default local URL).

## Upload local images -> a screenshot set

```bash
diffy screenshot:create-uploaded <PROJECT_ID> <upload.json>   # prints SCREENSHOT_ID
```

`upload.json` is **generated automatically** by the skill's `scripts/capture.mjs` — you never author it.
Shape (index-aligned arrays — `files[i]` is the screenshot of `urls[i]` at `breakpoints[i]`):

```json
{
  "snapshotName": "baseline-20260701-120000",
  "urls":        ["/", "/", "/about", "/about"],
  "breakpoints": [375, 1280, 375, 1280],
  "files":       ["/abs/home-375.png", "/abs/home-1280.png", "/abs/about-375.png", "/abs/about-1280.png"]
}
```

- `snapshotName` — auto-generated label (e.g. `baseline-<timestamp>`); internal, not user-facing.
- `files` — absolute paths to the PNGs the capture script just wrote; validated to exist by the API SDK.
- Rules enforced by `Screenshot::createUpload`: `snapshotName` non-empty; `urls`/`breakpoints`/`files`
  present and **equal length**.

The uploaded set is a self-contained snapshot; the project's own configured URLs/breakpoints aren't used at
upload time — the diff is purely between the two uploaded image sets. The skill still reads them from
`project:get` so the captured pages/breakpoints match what the project expects.

> Why `create-uploaded` and not `screenshot:create-folder`: `create-folder` derives the URL slug from the
> filename, so `home-375.png` and `home-1280.png` become two *different* URLs. `create-uploaded` lets one
> page appear at several breakpoints, which is what we want for a diff.

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
