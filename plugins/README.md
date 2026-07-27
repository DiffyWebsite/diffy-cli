# Claude Code plugin for diffy-cli

This directory holds the Claude Code plugin distributed through the marketplace defined at
[`../.claude-plugin/marketplace.json`](../.claude-plugin/marketplace.json).

## `diffy`

One plugin built on the `diffy` CLI with **two capture modes**:

- **Local** — capture a **running app on your machine** inside Diffy's published
  `diffywebsite2/screenshot-worker` Docker container (the production rendering runtime, run locally) and
  upload it. Requires Docker.
- **Remote** — have **Diffy capture on its own servers** (no local browser or Docker) — screenshot an
  environment or diff two environments server-side.

When a request doesn't say where to capture, Claude asks whether to run **local** or **remote** (on
app.diffy.website). See [`diffy/README.md`](diffy/README.md) for the full skill list.

## Install (once this repo is pushed)

```shell
/plugin marketplace add diffywebsite/diffy-cli
/plugin install diffy@diffy
```

Then the skills are available namespaced, e.g. `/diffy:visual-diff` or `/diffy:compare-environments`.
Claude also invokes them automatically based on your request.

## Local development / testing (no install)

```bash
# from the repo root
claude --plugin-dir ./plugins/diffy
claude plugin validate ./plugins/diffy    # run before publishing / submitting
```

Use `/reload-plugins` inside a session to pick up edits without restarting.
