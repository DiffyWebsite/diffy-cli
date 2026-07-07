# Claude Code plugins for diffy-cli

This directory holds Claude Code plugins distributed through the marketplace defined at
[`../.claude-plugin/marketplace.json`](../.claude-plugin/marketplace.json).

## `diffy`

Visual-regression skills built on the `diffy` CLI — create projects, capture/upload screenshots, and run
visual diffs. See [`diffy/README.md`](diffy/README.md).

## Install (once this repo is pushed)

```shell
/plugin marketplace add diffywebsite/diffy-cli
/plugin install diffy@diffy
```

Then the skills are available namespaced, e.g. `/diffy:visual-diff`. Claude also invokes them
automatically based on your request.

## Local development / testing (no install)

```bash
# from the repo root
claude --plugin-dir ./plugins/diffy
claude plugin validate ./plugins/diffy   # run before publishing / submitting
```

Use `/reload-plugins` inside a session to pick up edits without restarting.
