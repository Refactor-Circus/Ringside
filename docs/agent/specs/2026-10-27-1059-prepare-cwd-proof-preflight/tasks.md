# Tasks: Make Prepare cwd-proof and add a preflight

## Group 1: Factory script preflight and cwd-proof ringside calls
depends on: none
files: resources/workflows/ring-master-factory.js, tests/workflows/factory.test.mjs
criteria: 1, 2, 3, 4, 5, 6, 7, 8, 10

- [ ] 1.1 Write node simulations in tests/workflows/factory.test.mjs that capture every agent prompt and log() call: no prompt contains `vendor/bin/ringside`; the Prepare, variant, group-worktree, `--remove-variants` and `--remove-groups` prompts use `composer --working-dir=<root> exec ringside --` and the never-`cd` instruction; the Prepare prompt has the exact preflight commands ahead of `worktrees --setup` and the do-not-run-`--setup` rule; the schema accepts `dirty`, `remote`, `check_applies`; replay has no preflight and is never stopped by it
- [ ] 1.2 Write simulations for the stops and gate flag: non-empty `dirty` (exact path, directory prefix, spec-folder files outside the job's own folder, own-folder entries ignored, group with no files) finishes needs-decision at `prepare` with the exact ASK and file list and no build or group call; `remote` false stops with its ASK only when the job opens a PR; dirty plus no remote raises only the dirty ASK; non-replay result omitting a field is a failed preflight; replay and legacy results default to the safe values; `check_applies` false drops `ringside check` from both gate prompts and logs it; a clean preflight keeps `ringside check` in both; a workspace job aggregates per-package results
- [ ] 1.3 Implement a ringside-invocation helper in ring-master-factory.js and route every Prepare, variant, group-worktree and removal call through it; add the never-`cd` line to those prompts
- [ ] 1.4 Extend the PREPARED schema and the Prepare prompt (preflight as separate Bash calls, per package folder for workspace jobs, raw porcelain lines in `dirty`, `ready=false` without `--setup` on dirty or missing remote); add the script's dirty-overlap computation, the dirty and remote needs-decision stops, and the `gateCheck` flag that removes `ringside check` from both gate prompts with a log line
- [ ] 1.5 Run `npm`/node test command for tests/workflows and vendor/bin/pest; all green

## Group 2: factory.md preflight docs
depends on: 1
files: resources/skills/workflow/factory.md, tests/Feature/WorkflowScriptsTest.php
criteria: 9, 11

- [ ] 2.1 Write the WorkflowScriptsTest assertion that factory.md names `dirty`, `remote`, `check_applies`, the two needs-decision outcomes (dirty main, no remote) and the dropped `ringside check`
- [ ] 2.2 Describe the preflight in the prepare stage of factory.md
- [ ] 2.3 Run vendor/bin/pest; all green
