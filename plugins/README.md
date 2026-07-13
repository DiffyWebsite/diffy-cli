# Claude Code plugins for diffy-cli

This directory holds Claude Code plugins distributed through the marketplace defined at
[`../.claude-plugin/marketplace.json`](../.claude-plugin/marketplace.json). Both are built on the `diffy`
CLI and differ only in **where the screenshots are captured**.

## `diffy-local`

Capture screenshots of a **running app locally** with the `diffy-worker` engine (Diffy's production
screenshot pipeline, run on your machine) and upload them to Diffy — create projects, upload screenshot
sets, and run visual diffs. See [`diffy-local/README.md`](diffy-local/README.md).

## `diffy-remote`

Run screenshots and environment diffs **remotely on Diffy's servers** (no local browser or Playwright) —
screenshot an environment, compare two environments, create projects, and read diffs. See
[`diffy-remote/README.md`](diffy-remote/README.md).

Install whichever fits your workflow (or both).

## Install (once this repo is pushed)

```shell
/plugin marketplace add diffywebsite/diffy-cli
/plugin install diffy-local@diffy
/plugin install diffy-remote@diffy
```

Then the skills are available namespaced, e.g. `/diffy-local:visual-diff` or
`/diffy-remote:compare-environments`. Claude also invokes them automatically based on your request.

## Local development / testing (no install)

```bash
# from the repo root
claude --plugin-dir ./plugins/diffy-local
claude --plugin-dir ./plugins/diffy-remote
claude plugin validate ./plugins/diffy-local    # run before publishing / submitting
claude plugin validate ./plugins/diffy-remote
```

Use `/reload-plugins` inside a session to pick up edits without restarting.
