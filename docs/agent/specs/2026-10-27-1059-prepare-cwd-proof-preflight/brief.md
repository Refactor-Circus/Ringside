Risk: medium

## What changed
- Factory script (`resources/workflows/ring-master-factory.js`): every ringside call in the Prepare, variant, group-worktree and removal prompts now goes through one `ringside()` helper, which emits `composer --working-dir=<root> exec ringside -- ...`. Each of those prompts also tells the agent never to `cd` first. No prompt uses `vendor/bin/ringside` any more.
- Preflight: on a non-replay run, Prepare runs `git status --porcelain`, `git remote get-url origin` and a file check (`docs/agent/standards.index.yml`, `ring-master.yml`) as separate Bash calls before `worktrees --setup`. It returns `dirty`, `remote` and `check_applies`, which are new PREPARED schema fields. Workspace jobs run the checks per package folder.
- The script judges the result. It computes the dirty overlap against the task-group files (exact path or directory prefix, plus other spec folders). It stops with `needs-decision` at `prepare` for dirty main, then for a missing remote (only when the job opens a PR). It drops `ringside check` from both gate prompts, and logs it, when `check_applies` is false.
- Skill doc `resources/skills/workflow/factory.md` describes the preflight. `WorkflowScriptsTest` and `tests/workflows/factory.test.mjs` are extended.

## Why
Prepare broke when the session cwd was not the repo, because it called `vendor/bin/ringside` by path. A dirty main, a missing remote or an inapplicable `ringside check` only showed up after the build, which wasted it. Decisions that shaped it:
- D-003: workspace jobs run the preflight per package and aggregate the results.
- D-004: on a non-replay run, a Prepare result that omits a preflight field counts as a failed preflight. Safe defaults apply only on replay.
- D-005: the agent reports `check_applies` from the file check.
- D-006: the agent returns raw porcelain lines and the script computes the overlap.

## Look at
- `resources/workflows/ring-master-factory.js:171`: the `ringside()` and `noCd` helpers and `dirtyOverlap`/`porcelainPaths`. The porcelain parsing, rename handling, prefix matching and workspace `pkg:` prefix handling are the subtle part. Line 197 also has a stray missing space (`=>list`), which is harmless.
- `resources/workflows/ring-master-factory.js:222`: the preflight prompt text and the order of `setupStep`. This is where `--setup` is held back on dirt or no remote.
- `resources/workflows/ring-master-factory.js:239`: the post-Prepare judgement (missing fields, dirty ASK, remote ASK, then `gateCheck`). It includes a second "prepare setup" agent call when the agent held back setup but the script finds nothing blocking.
- `resources/workflows/ring-master-factory.js:326` and `:419`: the gate prompts, where `ringside check` is conditional on `gateCheck`.
- `tests/workflows/factory.test.mjs`: the new simulations, which capture prompts and `log()` calls.

## Tested
The round 2 gate passed: pest 171 passed (636 assertions), pint clean, `ringside check` passed.
- Criteria 1 and 2: "no prompt runs vendor/bin/ringside..." and the gate-by-composer test.
- Criterion 3: the PREPARED schema test.
- Criterion 4: the preflight-ordering test, the replay test, and the dirty-overlap tests (D-006).
- Criterion 5: the dirty ASK tests, including the untracked directory prefix case.
- Criterion 6: the remote ASK tests, the no-PR-job test and the dirty-plus-no-remote test.
- Criterion 7: "check_applies false drops ringside check from both gate prompts and logs it".
- Criterion 8: "a clean preflight keeps ringside check in both gate prompts".
- Criterion 9: `WorkflowScriptsTest` "it describes the Prepare preflight in the factory skill".
- Criterion 10: the omitted-field test (D-004) and the workspace test (D-003).
- Criterion 11: pest green.

## Risks
- Behaviour change for every factory job: a Prepare agent that does not return the three fields on a non-replay run now stops the job at `needs-decision`.
- Expected on this repo: main has uncommitted work touching the same factory files, and the repo has no `ring-master.yml`. The real run therefore exercises only the `check_applies=false` path. The true path is covered by simulations only.
- The porcelain parsing is heuristic (quoted paths, renames). Unusual paths could be missed or over-matched.
- Assumes `composer` is on PATH and resolves the project-local ringside. The remote check does not test reachability or auth.
- Nit: the `=>list` spacing at line 197.
