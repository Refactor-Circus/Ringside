# Build: Make Prepare cwd-proof and add a preflight

## Group 1

Commit: `7e9b6aa` on `ring-master/2026-10-27-1059-prepare-cwd-proof-preflight`.

### Files
- `resources/workflows/ring-master-factory.js`
  - `ringside(command)` builds `composer --working-dir=${job.root} exec ringside -- <command>`, and `noCd` holds the never-`cd` line. The Prepare, `prepare setup`, variant-worktree, `--remove-variants` (adopt), group-worktree and `--remove-groups` (integrate) prompts all use them. The gate and impact calls keep their existing form, and the gate's "(not vendor/bin/ringside by path ...)" aside became "(never the ringside binary by path ...)", so no prompt contains `vendor/bin/ringside`.
  - `PREPARED` now accepts `dirty` (string array), `remote` and `check_applies` (booleans).
  - On a non-replay run the Prepare prompt asks for the preflight first, one Bash call per command: `git -C <dir> status --porcelain`, `git -C <dir> remote get-url origin`, then a Read/Glob check for `<dir>/docs/agent/standards.index.yml` and `<dir>/ring-master.yml`. `<dir>` is `job.root`, or each package folder for a workspace job (D-003), whose `dirty` lines carry a `<Package>:` prefix. `dirty` is the raw porcelain lines (D-006), and the agent returns `ready=false` without `worktrees --setup` when `dirty` is non-empty or `remote` is false. A replay's prompt has no preflight.
  - `dirtyOverlap()` turns the porcelain lines into paths (including both sides of a rename and quoted names) and keeps those that match a task group's listed file (an exact path, or a directory that holds it) or sit under the specs folder outside `spec.dir`. Entries inside the job's own spec folder are skipped. In a workspace, each line is matched only against its own package's groups and reported as `<Package>/<path>`.
  - The checks after Prepare run in this order on a non-replay run: any missing field means a failed preflight (D-004) and `needs-decision` at `prepare`; then the dirty ASK, with the files in `evidence` and `result.dirty`; then the remote ASK, only when `opensPr`. Neither ASK is filtered against decisions.md, so a resume re-runs the preflight. On a replay, fields read as the safe values and nothing stops it.
  - `gateCheck` comes from `check_applies`. When it is false, both the variant gate and the group gate leave out `ringside check`, and a log line names the reason, using the agent's `detail` (the missing file) when it gave one.

### Tests
- `tests/workflows/factory.test.mjs`: existing assertions moved to the `exec ringside -- worktrees ...` form, and the default responder returns a clean preflight. New simulations cover:
  - no `vendor/bin/ringside` in any captured prompt, across plain, held-back setup, variant, parallel-group, workspace and replay runs;
  - every ringside code span in the scoped prompts uses the helper form, and each of those prompts has the never-`cd` line;
  - the preflight commands come ahead of `--setup`, with the hold-back rule;
  - the schema;
  - replay: no preflight and never stopped, and a legacy result is safe;
  - dirty stops: exact path, `MM`, rename, directory prefix, other spec folders, own folder ignored;
  - no-overlap dirt and a group with no files let the build go ahead through `prepare setup`;
  - the remote stop, a missing remote ignored when no PR is opened, and dirty plus no remote giving only the dirty ASK;
  - a missing field is a failed preflight, and a failed setup still escalates;
  - `check_applies` false drops `ringside check` from both gates and logs it, and a clean preflight keeps it in both;
  - a workspace job: per-package commands, overlap per package, and the remote stop.
- Not run here: the build prompt said "Do not run the test suite; the gate runs it after the build", so task 1.5 (node tests and `vendor/bin/pest`) is left unticked for the gate.

### Deviations and interpretations
- No departure from the listed standards.
- Because of D-006, the Prepare agent sees only raw porcelain lines and cannot tell which ones matter. So it holds back `--setup` on any dirt, as criterion 5 asks. When the script finds no overlap (or no remote but no PR), it sends a second, preflight-free Prepare call labelled `prepare setup`, which runs the setup step. A clean preflight still makes a single Prepare call, so the happy path is unchanged.
- "The job opens a PR" is `!replay && config.host.provider` being set (the config's host; the default `auto` counts).
- The failed-preflight ASK is "Prepare did not report its preflight. Re-run Prepare and its preflight?", and its evidence names the missing fields. The spec fixes the outcome but not the wording.
- In a workspace job, the gate never ran `ringside check`, so a false `check_applies` there logs nothing.

## Group 2

Commit: `0fb2c46` on `ring-master/2026-10-27-1059-prepare-cwd-proof-preflight`.

### Files
- `resources/skills/workflow/factory.md`: a new **Preflight** paragraph, placed after step 6 and ahead of **Workspace jobs**, describes the workflow's prepare stage. It covers the three preflight commands, each run as its own Bash call (per package folder for a workspace job), and the three fields `dirty`, `remote` and `check_applies`. It also says that `--setup` is held back on dirt or a missing remote, and that ringside calls use the `composer --working-dir=<root> exec ringside --` form. Bullets then cover:
  - **Dirty main**: `needs-decision` at stage `prepare` with "Commit or stash these main changes before the build?". This job's own spec folder is ignored.
  - **No remote**: `needs-decision` at stage `prepare` with "No origin remote found. What is the remote URL for the PR?", only when the job opens a PR. When main is also dirty, only the dirty question is asked.
  - A result missing any of the three fields is a failed preflight (D-004).
  - **Dropped check**: when `check_applies` is false, both gates drop `ringside check`, and the log names the missing file.
  - A closing line says that resuming re-runs the preflight. The question texts match the script word for word.
- `tests/Feature/WorkflowScriptsTest.php`: new test `describes the Prepare preflight in the factory skill`. It takes the **Preflight** section of factory.md, from its heading to the next bold heading, and asserts that the section names `dirty`, `remote`, `check_applies`, stage `prepare`, `needs-decision`, the **Dirty main** and **No remote** outcomes with their exact questions, and "drop `ringside check`".

### Tests
- Written before the factory.md change; the new test fails without the **Preflight** section.
- Not run here: the build prompt said "Do not run the test suite; the gate runs it after the build", so task 2.3 (`vendor/bin/pest`) is left unticked for the gate.

### Deviations and interpretations
- No departure from the listed standards.
- "The prepare stage of factory.md" was read as the workflow's Prepare stage. The skill's numbered steps have no prepare step, so the description is a **Preflight** paragraph next to the other bold workflow-behaviour notes, not a new numbered step.

## Rework round 1

Commit: `98d5d30` on `ring-master/2026-10-27-1059-prepare-cwd-proof-preflight` (empty: no tracked file changed).

### Fixes
- Blocker (gate): `ringside check` failed with "Generated files are stale or missing". The cause was that the fresh job worktree had never been synced, so the generated files didn't exist yet. Nothing in `resources/skills/workflow/factory.md` was wrong. Running `composer --working-dir=<worktree> exec ringside sync` wrote 32 files, all of them untracked: `.claude/`, `AGENTS.md`, `docs/agent/standards.index.yml` and `docs/agent/standards.summary.md`. The main checkout doesn't track these files either, so there was nothing to commit. After the sync, `ringside check` reports "check passed".
- Tasks 1.5 and 2.3 are now ticked. `composer --working-dir=<worktree> test:unit` (`vendor/bin/pest`) passes: 171 tests, 636 assertions, including the node workflow simulations.

### Notes
- The generated files exist only in this worktree and are not committed, so a fresh checkout of the branch fails `ringside check` again until it is synced. The Ringside repo itself has no `ring-master.yml` or `docs/agent/standards.index.yml` in git. With this job's `check_applies` preflight, a later run against this repo reports `check_applies=false` and leaves `ringside check` out of the gate.

## Group 3

Commit: `0a99916` on `ring-master/2026-10-27-1059-prepare-cwd-proof-preflight`.

The failing ImpactTest tests were not added by this job. They came in with `f2f488d` on main, which this branch starts from. CI run 40617420900 (windows-latest, both prefer-lowest and prefer-stable) showed three causes:

1. "finds the sibling packages…": the test built the expected path as `realpath($parent).'/App'`. `Impact::consumers` returns `realpath()` of each folder, which uses `\` on Windows. The code is right, so the test was wrong.
2. "tests a consumer in a throwaway worktree…", "relaxes a released constraint…" and "reports a failing consumer…": the fake `composer` is a `#!/bin/sh` script, and the consumer test commands are `cat` and `false`. On Windows the array-form `proc_open` cannot start the fake, so every install failed. That gave `passed=false`, an empty output (so the manifest decoded to null) and the "installing App against the job failed" summary.
3. "creates a worktree per task group…": a real Windows bug in `Worktree::removeBeside`. git lists worktrees as `C:/Users/...`, but the prefixes were built with `\`, so no group worktree matched. Nothing was removed, and `git branch -D` then refused because each branch was "used by worktree".

### Files
- `src/Worktree.php`: `removeBeside` now compares through a new private `comparable()` helper. It turns `\` into `/` and, on Windows only, folds case. It applies to both the prefixes and each listed path. The `git worktree list --porcelain` output is split on `\R` so a CRLF can't break the `$` match. The listed path still goes to `git worktree remove` exactly as git printed it. The fix covers `--remove-groups`, `--remove-variants` and `--remove-replay`.
- `tests/Feature/ImpactTest.php`:
  - The consumers test now expects `realpath($parent.'/App')` and `realpath($parent.'/Shop')`.
  - The three fake-composer tests are now `->skipOnWindows()`, each with a comment giving the reason.
  - The group-worktree test is unchanged and still runs on Windows, where it checks the `removeBeside` fix.

### Tests
- Tests first: the consumers expectation was rewritten before any code change. The group-worktree test already failed on Windows and is the proof for the src fix. A macOS run can't reproduce a backslash path, because `\` is a valid filename character there.
- `composer analyse` (phpstan): no errors. `composer lint:check` (pint): passed. Both ran against the worktree.
- Not run here: the build prompt said "Do not run the test suite; the gate runs it after the build", so the `vendor/bin/pest` run in 3.1 is left for the gate. The Windows fix can only be confirmed by the PR's windows-latest checks (tooling/verification-gate, "After the push").

### Deviations and interpretations
- No departure from the listed standards. shell/error-output is unchanged: `git worktree list` stays stdout-only because it is parsed as a value.
- "Pass on Windows" was read as pass or skip for the three tests built on a `#!/bin/sh` fake. This matches the repo's existing convention: WorktreeTest `setup`, the WorktreesCommandTest blocks that use a fake composer, and NewPackageTest all `skipOnWindows()` for the same reason.
- Observation, not changed (outside this job): `Impact::run` and `Worktree::setup` start `composer` through the array form of `proc_open`. On Windows that bypasses `cmd.exe`, so a `composer.bat` install of Composer would not be found. Making the impact gate run on Windows would be its own job.

## Rework round 1 (review)

Commit: `5bf06f2` on `ring-master/2026-10-27-1059-prepare-cwd-proof-preflight`.

### Fixes
- Minor (convention): in `resources/workflows/ring-master-factory.js`, `majorKeys` reads `list => list.filter(...)` again, with the space after the arrow. It matches main.

### Not fixed: returned as a decision
- Major (scope): the Group 3 Windows CI fix in `src/Worktree.php` and `tests/Feature/ImpactTest.php` is still in place. The finding says to revert it unless the user accepts the scope change, so whether to keep it is the user's call. Facts that bear on the choice:
  - Group 3 was added to tasks.md by the workflow, from the PR's failing windows-latest checks (run 40617420900).
  - The failing ImpactTest tests came from `f2f488d` on main, not from this job.
  - Reverting turns the PR's Windows checks red again.
  - The spec's Out of scope says no PHP changes are intended.
- Nothing was reverted. The question goes back to the user in `decisions`.

### Tests
- `composer --working-dir=<worktree> test:unit` (`vendor/bin/pest`): 171 passed, 636 assertions, including `WorkflowScriptsTest` and the node simulations.
