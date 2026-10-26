# Spec review

## Pass 1

### scope

- minor: the ticket's test requirements (manifest and lock copied, plain-install fallback) and the PR note on `runner.allowed_tools` were not acceptance criteria. Changed: criterion 13 now lists the required tests; new criterion 14 requires the PR note.
- minor: README line 143 shows the old `setup` default in the `build.worktree` example. Changed: the Docs bullet names it.
- nit: criterion 8 (non-per-job mode fails) and the `--remove` refusal for single packages are small additions beyond the ticket. Kept: they stop the command misbehaving; no change.

### risk

- major: `Shell::run` reads stdout to EOF before touching stderr, so a child that fills the stderr pipe (composer install does) hangs forever. Changed: the Shell design bullet requires draining both pipes without blocking.
- major: `Shell::run` discards stderr, so criterion 7 (print the command's output on failure) shows nothing for most composer failures. Open decision (stderr), raised below.
- major: `Project::path` mangles an absolute `build.worktree.path` (`root/` + path). Changed: `Worktree::prepare` uses an absolute path as is.
- major: "reuse when the folder exists" would treat a stale or foreign folder as a ready worktree. Changed: reuse only when `<path>/.git` exists; otherwise git's refusal is surfaced.
- minor: a failed `copy()` of the manifest or lock could be ignored. Changed: reported as a failure, exit 1.
- minor: the fallback install could inherit a `COMPOSER` variable from the caller. Changed: the spec requires it not to (or to document it).
- minor: whitespace around an explicit `setup` string breaks `preg_split`. Changed: trim first.
- minor: backward compat for projects with the old explicit `setup` value, and an ungitignored copied manifest. Changed: both listed under Risks.
- open decisions: stderr handling in `Shell::run`; whether a rerun overwrites an existing manifest in the worktree.

### testability

- major: the spec never said how composer install is verified without a real install, so criteria 3, 4, 6 and 7 had no observable assertion. Changed: new Testing section with a fake `composer` on PATH that logs cwd, args and `COMPOSER`; criterion 13 now enumerates what is covered.
- major: criterion 4's "no COMPOSER override" was untestable as written (inherit-or-document). Changed: the fallback passes `COMPOSER=''` and a test sets a caller `COMPOSER` to prove it.
- major: criterion 6 (never `local:install`) and criterion 10 (pipe draining, env not leaking) had no named proof. Changed: Testing names the `local:install` fixture script and log check, and a >64 KB stderr child test in `tests/Unit/ShellTest.php`.
- minor: no console test pattern exists in the repo and git fixture helper `packageRepo()` is local to WorkspaceTest. Changed: command tests run `bin/ringside` in a subprocess (as JobTest does); `packageRepo()` moves to `tests/Pest.php`; layers per test file named.
- minor: criterion 7 would depend on the open stderr decision. Changed: the fake prints failure text to stdout.
- minor: criterion 11 had no negative assertion. Changed: the workflow test also asserts no `git worktree add` or configured setup string in the prompt.
- nit: criterion 14 is a PR statement, not testable. Changed: marked as checked in review.

## Round 1

Changed in spec.md:

- D-006 written in: criterion 3 and `Worktree::setup` copy each manifest file only when absent in the worktree. New criterion 4 (rerun leaves an existing manifest/lock untouched, install still runs) with a pre-seeded test. Stale-manifest trade-off added to Risks. Why: unconditional copy contradicted the final decision and would overwrite in-job edits.
- D-005 written in: `Shell::run(array $command, string $cwd, array $env = [], bool $withStderr = false)` in Design and criterion 12 (default off, git callers unchanged, only setup/install turns it on, pipes always drained). Criterion 8 requires stderr in the failure output. The spec-review.md pointer is gone. Fake composer now writes a stderr marker on failure; tests assert it in `Worktree::setup` and command output; ShellTest covers the flag on and off. Why: the decided behaviour was untested and the spec pointed at a non-source.
- Env merge uses explicit key assignment instead of spread.
- `Worktree::prepare` rejects a branch or path starting with `-` (with test).
- Exact wording fixed: criterion 1 output `<path> on <branch>[, set up with <description>]`; mode refusal contains `does not use a worktree`; new criterion 10 for `--remove` refusal contains `--remove is not supported`.
- New criterion 9 and test for an existing non-worktree folder (RuntimeException / exit 1 with git's output).
- Fake composer prints `<unset>` when COMPOSER is unset or empty.
- Old criterion 13 (meta) folded into a sentence after the list; old 14 (PR note) moved to a new "PR notes" section. Criteria renumbered 1 to 14.
- Drain test uses a deterministic bounded timeout with a clear failure message.
- Out of scope gains: non-sibling custom paths, gitignoring copied manifests, workspace packages ignoring `auto`. Verified `Workspace` picks its own setup per package and never reads `build.worktree.setup`; new criterion 14 pins that and the test covers it.
- Docs: CHANGELOG must state the default changed for all consumers who never set the key and what `auto` does with a `composer.local.json`.
- Criterion 13 (factory prompt): on non-zero exit return ready=false with the output, no manual repair or fallback commands; workflow test checks the wording.

Left alone (open questions): behaviour when the worktree is on a different branch than `ring-master/<spec>` (no branch check added), and whether an empty or non-string `build.worktree.setup` reads silently as `auto` (criterion 11 unchanged).

## Pass 3: recheck D-007, D-008

The ringside binary could not run in this session, so a single `ring-master-critic` did the recheck instead of the workflow.

- D-007 landed: criterion 2 now fails with exit 1 when the existing worktree is on another branch or a detached HEAD, naming the actual and expected branch. `Worktree::prepare` checks `git branch --show-current` before reusing. Testing adds a switched-branch case.
- D-008 landed: criterion 11 now reads absent or empty as `auto` and fails loud on a non-string value, naming the key. `Config` has no fail-loud helper (`string()` casts scalars, and a list cast to `''` would read as `auto`). Design now names a new `Config::worktreeSetup(): string` that throws a `RuntimeException`, and `WorktreesCommand` surfaces it as exit 1. Testing covers null, the non-string kinds, and one command-level case.

## Pass 3: not asked (max_passes reached)

- Where the fail-loud check for `build.worktree.setup` lives. Options: only in `Config::worktreeSetup()`, which throws when the key is read; or also in `Config::validate()`, so `ringside check` flags it up front. Recommendation: only in `Config::worktreeSetup()` (validate can be added later without a contract change).
