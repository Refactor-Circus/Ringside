# Tasks: Install job worktree dependencies from composer.local.json

## Group 1: Shell env and stderr capture
depends on: none
files: src/Shell.php, tests/Unit/ShellTest.php
criteria: 12

- [x] 1.1 Write tests in tests/Unit/ShellTest.php: env merge via explicit key assignment, no env leak into later calls, `['COMPOSER' => '']` overriding a caller's `COMPOSER`, `$withStderr` off (stdout only) and on (stderr appended) with a `php -r` child, and a child writing more than 64 KB to stderr finishing under a fixed timeout (fails with a deadlock message instead of hanging)
- [x] 1.2 Change `Shell::run` to `run(array $command, string $cwd, array $env = [], bool $withStderr = false)`; drain stdout and stderr together (`stream_select` or non-blocking reads); keep git callers unchanged
- [x] 1.3 Run vendor/bin/pest; all green

## Group 2: Config `build.worktree.setup` default `auto`
depends on: none
files: src/Config.php, tests/Unit/ConfigTest.php
criteria: 11

- [x] 2.1 Write tests in tests/Unit/ConfigTest.php: default `auto`; absent, null and empty read `auto`; list, map, number and boolean each make `worktreeSetup()` throw a `RuntimeException` naming `build.worktree.setup`
- [x] 2.2 Set the `build.worktree.setup` default to `auto` and add `Config::worktreeSetup(): string`
- [x] 2.3 Run vendor/bin/pest; all green

## Group 3: Worktree prepare and setup
depends on: 1
files: src/Worktree.php, tests/Feature/WorktreeTest.php, tests/Pest.php, tests/Feature/WorkspaceTest.php
criteria: 2, 3, 4, 5, 6, 7, 8, 9

- [x] 3.1 Move `packageRepo()` from tests/Feature/WorkspaceTest.php to tests/Pest.php
- [x] 3.2 Write tests in tests/Feature/WorktreeTest.php with real temp git repos and the fake `composer` on `PATH` (logs cwd, args, `COMPOSER`; exit from `FAKE_COMPOSER_EXIT`; stderr marker on failure; `skipOnWindows()`; env restored in `afterEach`): new branch from base and existing branch; reuse on same branch; `RuntimeException` naming actual and expected branch when on another branch (worktree untouched); plain folder at the path surfaces git's refusal; branch or path starting with `-` rejected before git runs; `auto` with manifest and lock copies them and installs with `COMPOSER=composer.local.json`; existing worktree manifest and lock left untouched on rerun; `auto` without manifest installs with `COMPOSER` `<unset>` even when the caller sets `COMPOSER=other`; explicit command runs as is with no copy; `local:install` never runs; non-zero install returns exit 1 with the stderr marker; failed `copy()` reported as exit 1 naming the file
- [x] 3.3 Implement `src/Worktree.php`: `prepare(Project, string $path, string $branch, string $base): string` and `setup(Project, string $path, string $setup): array{0: int, 1: string, 2: string}`
- [x] 3.4 Run vendor/bin/pest; all green

## Group 4: `worktrees` command for single packages
depends on: 2, 3
files: src/Console/WorktreesCommand.php, tests/Feature/WorktreesCommandTest.php
criteria: 1, 2, 8, 9, 10, 11, 14

- [x] 4.1 Write wiring tests in tests/Feature/WorktreesCommandTest.php through `bin/ringside worktrees` in a subprocess: exact `<path> on <branch>` line (and `, set up with <description>` with `--setup`); rerun exit 0; wrong-branch, non-worktree folder and failed setup exit 1 with the output; mode other than `per-job` prints `does not use a worktree`; `--remove` prints `--remove is not supported`; `build.worktree.setup` set to a list exits 1 naming the key; a workspace project still takes the workspace path and ignores `auto`
- [x] 4.2 Add the single-package branch to `WorktreesCommand` (per-job check, `--remove` refusal, job build, `Worktree::prepare` with `job.worktree` resolved against the project root, `Worktree::setup` with `Config::worktreeSetup()` on `--setup`); update the command description
- [x] 4.3 Run vendor/bin/pest; all green

## Group 5: Factory prepare prompt and docs
depends on: none
files: resources/workflows/ring-master-factory.js, tests/workflows/factory.test.mjs, README.md, CHANGELOG.md
criteria: 13

- [x] 5.1 Add a single-package per-job case to tests/workflows/factory.test.mjs: prompt matches `/ringside worktrees <spec> --setup/`, has the ready=false-on-failure and no-manual-repair wording, and no longer contains `git worktree add` or the configured `setup` string; the workspace case stays as is
- [x] 5.2 Change the single-package per-job prepare prompt in resources/workflows/ring-master-factory.js
- [x] 5.3 Update README (`build.worktree` example: `setup: auto`, single-package `worktrees`) and CHANGELOG (default changed for consumers that never set the key; `auto` installs from `composer.local.json` with its path repositories; delete an explicit `setup` to get `auto`)
- [x] 5.4 Run the workflow tests (tests/workflows/*.test.mjs) and vendor/bin/pest; all green

## Group 6: Rework 1, PR pipeline failed
depends on: 1, 5
files: tests/Feature/WorkflowScriptsTest.php, tests/Feature/NewPackageTest.php, tests/Feature/HookScriptTest.php
criteria: 13

User's note (sent back from review in RingMaster): "PR pipeline failed". The `tests` workflow on PR #1 (run 40367573800) fails on all four P8.5 jobs (ubuntu and windows, prefer-lowest and prefer-stable) with the same three tests, which also fail on `main` (run 40367477977); the PR must go green anyway:

- [x] 6.1 WorkflowScriptsTest: `node --test tests/workflows/` fails on CI's Node 22 with `Cannot find module '.../tests/workflows'` (a directory argument is taken as a module there). Pass the `tests/workflows/*.test.mjs` files explicitly (list them with `glob()` in PHP) so it works on Node 20 and 22+
- [x] 6.2 NewPackageTest (ubuntu: both create tests; windows: the laravel one): `git commit -q -m Scaffold acme/...` fails in the new package repo because CI has no global git identity and `scaffoldWorkspace()` only configures the workspace root repo. Give the scaffold commits an identity in the test (for example `GIT_AUTHOR_NAME`/`GIT_AUTHOR_EMAIL`/`GIT_COMMITTER_NAME`/`GIT_COMMITTER_EMAIL` set with `putenv` and restored in `afterEach`), without changing `PackageScaffolder`. Also check the windows laravel case: the fake `laravel` is a `#!/bin/sh` script, which windows cannot run; skip that test on windows like the plain PHP one if it cannot pass there
- [x] 6.3 HookScriptTest "adds RingMaster's queue line" (windows only): the hook prints the root as `C:/Users/...` while the test expects `$project->root` with backslashes. Normalize separators in the expectation (or skip on windows if the hook's forward-slash output is intended)
- [x] 6.4 Run vendor/bin/pest and the workflow tests; all green. After pushing, check the PR's checks with `gh pr checks 1 --repo Refactor-Circus/Ringside`
