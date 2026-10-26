# Build: Install job worktree dependencies from composer.local.json

## Group 1

Shell env and stderr capture. Commit 61dc2c4 on `ring-master/2026-10-25-1349-worktree-local-manifest-install`.

**Files**

- `src/Shell.php`: `run(array $command, string $cwd, array $env = [], bool $withStderr = false)`. A non-empty `$env` is merged over `getenv()` by explicit key assignment and passed as proc_open's `env_vars`; an empty one passes `null` (inherit). Both pipes are set non-blocking and drained together with `stream_select`; if `stream_select` cannot watch the pipes (Windows), it falls back to sequential blocking reads, as before. The output is trimmed stdout, with trimmed stderr appended on a new line when `$withStderr` is true. Existing callers are unchanged.
- `tests/Unit/ShellTest.php`: new.

**Tests** (criterion 12), with a `PHP_BINARY -r` child:

- inherits the parent env when no env is passed
- merges the given env over the parent (kept, overridden and added variables, `PATH` still there)
- no leak into the parent or a later call
- `['COMPOSER' => '']` overrides a caller's `COMPOSER=other.json` (the child sees it as `<unset>`)
- `$withStderr` off returns stdout only; on returns `out\nerr` (and stderr alone when stdout is empty)
- a child writing 256 KB to stderr finishes, with the flag both off and on. The child sets a 10 second limit on its own non-blocking stderr writes and prints `deadlock: ...` if it runs out, so a regression fails in about 10 s instead of hanging (checked against the old `Shell::run`: both cases failed in 10.05 s). `skipOnWindows()`.

`composer test` (phpstan, pint --test, pest): 103 passed.

**Deviations**

- None from the spec. One note: as far as I know from php-src, proc_open leaves out env entries with empty values, so `['COMPOSER' => '']` may remove `COMPOSER` from the child's environment instead of setting it to an empty string. Composer treats both as unset, and the test accepts either one (`getenv('COMPOSER') ?: '<unset>'`).

## Group 2

Config `build.worktree.setup` default `auto`. Commit 1be3979 on `ring-master/2026-10-25-1349-worktree-local-manifest-install`.

**Files**

- `src/Config.php`: the `build.worktree.setup` default is now `auto`, with an inline comment saying what `auto` does. New `Config::worktreeSetup(): string` reads `get('build.worktree.setup')`. It returns `auto` when the value is null, empty or only whitespace, and the configured string otherwise. Any other type (list, map, int, float, bool) throws a `RuntimeException`: "ring-master.yml: `build.worktree.setup` must be a string (`auto` or a command), got <type>." (D-008).
- `tests/Unit/ConfigTest.php`: new cases.

**Tests** (criterion 11)

- the default is `auto`, both in `Config::defaults()` and when read through `worktreeSetup()`
- absent (a `build.worktree` block without `setup`), `null` and `''` each read `auto` (dataset)
- an explicit command (`touch setup-ran`) comes back unchanged
- a list, map, number and boolean each make `worktreeSetup()` throw a `RuntimeException` whose message contains `build.worktree.setup` (dataset)

`composer test` (phpstan, pint --test, pest): 112 passed.

**Deviations**

- None from the spec. Two notes:
  - A setup string that is only whitespace also reads as `auto`. The spec only says "empty", but `preg_split` would turn a blank string into an empty command, so `auto` is the safer reading.
  - Until group 5 changes it, the factory's single-package prepare prompt (`resources/workflows/ring-master-factory.js:154`) interpolates `config.build.worktree.setup`, so with the new default it would tell the agent to run `auto`. Group 5 replaces that prompt (criterion 13). No workflow test asserts the old string, so nothing breaks in the meantime.

## Group 5

Factory prepare prompt and docs. Commit 6c975a8 on `ring-master/2026-10-25-1349-worktree-local-manifest-install`.

**Files**

- `resources/workflows/ring-master-factory.js`: the per-job single-package branch of the prepare prompt now says: from `job.root`, run `vendor/bin/ringside worktrees <spec> --setup`, which creates or reuses the worktree at `job.worktree` on `job.branch` and installs its dependencies. It returns ready=true only on exit 0, with `worktree` set to the path the command prints. On a non-zero exit it returns ready=false with the command output in detail, does not repair anything by hand, and never creates the worktree with git itself or falls back to any other command. The prompt no longer uses `config.build.worktree.setup`. The workspace branch and the non-worktree branch are unchanged.
- `tests/workflows/factory.test.mjs`: a new single-package per-job case.
- `README.md`: the `build.worktree` default shows `setup: auto` with a comment. A new paragraph under Configuration says what `auto` does (copies `composer.local.json`/`.lock` when absent, `COMPOSER=composer.local.json`, path repositories resolve, plain `composer install` otherwise, any other value runs as a command). The CLI row for `worktrees` now covers single packages, with `--remove` for workspaces only.
- `CHANGELOG.md`: two 0.8.0 entries (0.8.0 is not tagged yet). The first covers single-package `worktrees [--setup]` and the factory prepare agent now using it. The second covers the `auto` default: it was `composer install --no-interaction`, so every consumer that never set the key changes behaviour; with `auto`, a package installs from `composer.local.json` with its sibling path repositories; to get `auto`, delete an explicit `setup`; a non-string value now fails; workspace jobs are unchanged.

**Tests** (criterion 13)

- The single-package per-job prepare prompt matches `` From /work/pkg, run `vendor/bin/ringside worktrees 2026-10-23-1430-import --setup` ``. It contains the ready=true-only-on-exit-0, ready=false-with-output, no-manual-repair and no-fallback wording. It contains neither `git worktree add`, nor the old "create or reuse a git worktree" text, nor the configured `setup` string (a distinct `composer install --no-interaction --prefer-dist`, so the check can't pass by accident).
- The workspace case (`ringside worktrees … --setup` in the workspace prompt) is unchanged and still passes.

`node --test tests/workflows/`: 24 passed. `vendor/bin/pest` (run in the worktree through `composer --working-dir=… test:unit`): 112 passed. No PHP files changed in this group, so phpstan and pint are unaffected.

**Deviations**

- Criterion 13 says the agent must not fall back to "`git worktree add` or other commands", but task 5.1 says the prompt must not contain `git worktree add`. The prompt meets both by forbidding it without the literal command: "never create the worktree with git yourself or fall back to any other command".
- The new prompt depends on the single-package `worktrees` command from groups 3 and 4, which were not built when this group was committed. A factory run on this branch before they land would get ready=false from prepare (the command currently fails with "No workspace.packages…") and escalate rather than build.

## Group 3

Worktree prepare and setup. Commit fcfae11 on `ring-master/2026-10-25-1349-worktree-local-manifest-install`.

**Files**

- `src/Worktree.php`: new `RefactorCircus\Ringside\Worktree`, a final class with static methods (like `Shell`, since `Project` is passed in) and a public `Worktree::AUTO = 'auto'` constant.
  - `prepare(Project $project, string $path, string $branch, string $base): string`. It first rejects a branch, path or base that starts with `-` (`RuntimeException`, nothing run). `$path` is used as is when absolute (`/…` or `C:\…`), otherwise it is resolved with `Project::path`. If `$path/.git` exists, it runs `git branch --show-current` there. When that equals `$branch`, the worktree is reused and the path returned. Otherwise it throws a `RuntimeException` that names the actual branch (or "a detached HEAD") and the expected one, and leaves the worktree alone. Without `.git` it runs `git worktree add <path> <branch>` when `refs/heads/<branch>` exists, else `git worktree add -b <branch> <path> <base>`, from the package root. On failure it throws `RuntimeException` "Could not create the worktree at … on …: <git output>". It returns the resolved path.
  - `setup(Project $project, string $path, string $setup): array{0: int, 1: string, 2: string}`. With `auto` (or blank), it copies `composer.local.json` and `composer.local.lock` from the package root, each only when the file exists there and nothing by that name is in the worktree. It then runs `composer install --no-interaction` in the worktree with `$withStderr = true`. `COMPOSER` is `composer.local.json` when the worktree has that file, else `''`. The description is `` `COMPOSER=composer.local.json composer install --no-interaction` `` or `` `composer install --no-interaction` ``. Any other value is trimmed, `preg_split` on whitespace and run in the worktree with stderr, and described as `` `<command>` ``. If a `copy()` fails it returns `[1, "Could not copy <from> to <to>: <PHP warning>", "copying <file> into the worktree"]` and does not install. The warning is caught by a scoped error handler, not `@`, because PHPUnit reports `@`-suppressed warnings.
  - The exit code is the child's own (non-zero on failure). The command in group 4 maps any non-zero code to exit 1.
- `tests/Pest.php`: `packageRepo()` moved here from WorkspaceTest (with a docblock and a `(string)` cast on `json_encode`).
- `tests/Feature/WorkspaceTest.php`: `packageRepo()` removed.
- `tests/Feature/WorktreeTest.php`: new.

**Tests** (criteria 2 to 9; 1 and 8's command level are group 4's). Real temp git repos (package `Pkg`, whose composer.json has a `local:install` script, and a sibling worktree `Pkg--demo`). A fake `composer` sits first on `PATH`. It logs `pwd -P|$*|${COMPOSER:-<unset>}`, prints `fake composer stdout`, and exits with `FAKE_COMPOSER_EXIT` (writing `fake composer stderr` when that is non-zero). `PATH`, `COMPOSER` and `FAKE_COMPOSER_EXIT` are saved in `beforeEach` and restored in `afterEach`. Both `describe` blocks are `skipOnWindows()`.

- prepare: new branch from `main` (absolute path and a `../Pkg--demo` path relative to the package): the branch is checked out, points at `main`, and shows in `git worktree list`
- prepare: an existing `ring-master/…` branch with its own commit is checked out (its file is in the worktree)
- prepare: rerun reuses the worktree: same path, `git worktree list` unchanged, an in-job file kept (criterion 2)
- prepare: worktree switched to `other`, or to a detached HEAD, throws `RuntimeException` naming `` `other` `` / `detached HEAD` and the job branch. HEAD and branch are unchanged afterwards (criterion 2, D-007)
- prepare: a non-empty plain folder at the path throws with git's `already exists`, and the folder is untouched (criterion 9)
- prepare: a branch, path or base starting with `-` throws. A fake `git` put on `PATH` for this test proves git never ran
- setup `auto` with manifest and lock: both copied byte for byte, one install from the worktree with args `install --no-interaction` and `COMPOSER=composer.local.json`, description checked (criterion 3)
- setup `auto` with a manifest but no lock: no lock appears in the worktree
- setup `auto` when the worktree has its own differing manifest and lock: both unchanged, and the install still ran with `COMPOSER=composer.local.json` (criterion 4, D-006)
- setup `auto` without a manifest, with the caller's `COMPOSER=other`: the log shows `<unset>` and nothing was copied (criterion 5)
- setup `touch setup-ran`: the marker is in the worktree, no manifest copied, composer never ran (criterion 6)
- `local:install` never runs, with and without a manifest (criterion 7)
- `FAKE_COMPOSER_EXIT=1` returns exit 1, and the output holds both the stdout and stderr markers (criterion 8)
- an unreadable `composer.local.lock` (chmod 000) returns exit 1 naming `composer.local.lock`, and composer never ran. It is skipped when the file stays readable (root)

`composer test` (phpstan, pint --test, pest): 131 passed.

**Deviations**

- `prepare` runs `git worktree add` with `$withStderr = true`. Git writes its refusals ("already exists", "is already used by worktree") to stderr. Without the flag the exception would carry an empty message, and criteria 8 and 9 ("with git's output") could not be met. This conflicts with D-005's "only the setup/install call turns it on" and criterion 12's "the setup/install call is the only caller", so it is raised as a decision. `git rev-parse` and `git branch --show-current` stay stdout only. Turning the flag off breaks the plain-folder test.
- `prepare` also rejects a `$base` starting with `-`. The spec names only the branch and the path, but the base reaches the same `git worktree add` argument list from `host.base`.
- `auto` chooses `COMPOSER=composer.local.json` based on the worktree after the copy, not on the package root. The two only differ when the worktree has its own `composer.local.json` and the package has none. In that case the worktree's manifest is installed.
- A blank `$setup` is treated as `auto` (Config already maps blank to `auto`; this is a guard against an empty command).

## Group 4

`worktrees` command for single packages. Commit 55e553b on `ring-master/2026-10-25-1349-worktree-local-manifest-install`.

**Files**

- `src/Console/WorktreesCommand.php`: when `workspace.packages` is not set, `execute` hands off to a new private `single()` method instead of failing with "No workspace.packages…". The steps, in order:
  1. If `build.worktree.mode` is not `per-job`, print "build.worktree.mode is `<mode>`, so this package does not use a worktree per job; …" and exit 1.
  2. If `--remove` is given, print "--remove is not supported in a single package; build.worktree.cleanup removes the job worktree." and exit 1.
  3. Build the job. With `--setup`, read `Config::worktreeSetup()` before the worktree is created, so a bad value changes nothing.
  4. Call `Worktree::prepare` with the raw `job.worktree` (a relative path resolves against the package root), `job.branch` and `host.base`.
  5. Any `RuntimeException` from the steps above (unknown spec, bad setup value, wrong branch, git refusal) is printed as `<error>` and the command exits 1.
  6. The path is canonicalised with `realpath()` and printed as `<path> on <branch>`. With `--setup`, `Worktree::setup` runs first and the line gets `, set up with <description>`. If setup fails, the command prints `<error><description> failed</error>`, then the output (stderr included), and exits 1.

  The workspace branch is unchanged. The command description now covers single packages, `--setup` says a single package uses `build.worktree.setup`, and `--remove` is marked workspaces only.
- `tests/Feature/WorktreesCommandTest.php`: new.

**Tests** (criteria 1, 2, 8, 9, 10, 11, 14). Wiring only, through `bin/ringside worktrees` in a subprocess (`Shell::run` with stderr, and the fake composer's bin dir prepended to `PATH` through the env argument, so the test process's env is never changed). The single-package fixture is `fixtureProject` with one spec, committed to its own git repo on `main`. The helpers have their own names (`worktreesPackage`, `worktreesFakeComposer`, `ringsideWorktrees`), so the file does not depend on WorktreeTest's global functions or on load order. Both `describe` blocks are `skipOnWindows()`.

- `worktrees demo` exits 0 and prints exactly `<realpath of ../{package}--{spec}> on ring-master/<spec>`. The worktree is on that branch and composer never ran (criterion 1)
- `--setup` prints exactly the same line followed by ``, set up with `composer install --no-interaction` `` (criterion 1)
- a rerun with `--setup` exits 0 with the same path line (criterion 2)
- a worktree switched to `other` exits 1, and the output names `` `other` `` and the job branch (criterion 2)
- a plain folder at the path exits 1 with git's `already exists` (criterion 9)
- `FAKE_COMPOSER_EXIT=1` exits 1. The output has `` `composer install --no-interaction` failed `` and the fake's stderr marker, with no `set up with` line. With `--ansi`, the failure line carries Symfony's `<error>` style (`\e[37;41m`) (criterion 8)
- `build.worktree.mode: none` exits 1 with `does not use a worktree`, and no worktree is created (criterion 10)
- `--remove` exits 1 with `--remove is not supported` (criterion 10)
- `build.worktree.setup: [composer, install]` with `--setup` exits 1 naming `build.worktree.setup`, and no worktree is created (criterion 11)
- workspace (Keystone with a `composer.local.json`, Atrium with `local:install`), with `--setup` and the default `auto`: the exact old output (`Keystone: .ring-master/jobs/<spec>/Keystone on … , set up with `composer install --no-interaction``, the Atrium line with `composer local:install --no-interaction`, `Linked: none`). The fake log shows `install --no-interaction` and `local:install --no-interaction`, both with `COMPOSER` unset, and nothing is copied into the Keystone worktree (criterion 14)

`composer test` (phpstan, pint --test, pest): 142 passed.

**Deviations**

- None from the spec. Two notes:
  - The printed path is `realpath()` of what `Worktree::prepare` returns. Without it, a relative `job.worktree` would print as `<root>/../<package>--<spec>`. On macOS, temp paths print as `/private/var/…`.
  - With `--setup`, a non-string `build.worktree.setup` is checked before `Worktree::prepare` runs, so a bad config fails without creating the worktree. Without `--setup`, the key is not read.

## Rework round 1

**Finding (blocker, gate):** `ringside check` reports "Generated files are stale or missing. Run: vendor/bin/ringside sync".

**Not fixed. This needs a decision, so nothing was committed this round.**

- The failure was already there before this job and has nothing to do with this branch. `bin/ringside check` fails with the same message when run against the main Ringside checkout on `main`. The workspace's `vendor/bin/ringside check` fails the same way when pointed at the Ringside folder.
- Ringside does not use itself. Its repo tracks no `ring-master.yml`, `docs/agent/standards.index.yml`, `.claude/` or `CLAUDE.md`, and `refactor-circus/standards` is not installed in its `vendor/`. `Sync::upToDate` needs the index fingerprint, `standards.summary.md` and `.claude/agents/ring-master-implementer.md`, and none of them exist.
- Running `sync` here would set up Ringside to use its own workflow. That means new tracked files: the index, summary, skills, subagents, a managed block in `CLAUDE.md`, and `.claude/settings.json` hooks. The hooks would point at `vendor/refactor-circus/ringside/bin/ringside-hook`, which does not exist in Ringside's own checkout, so sessions in this repo would get broken hooks. That changes the repo's config and module boundary, so it is not something to fix automatically.
- `vendor/bin/pest`: 142 passed (473 assertions). Code and tests are unchanged.

## Rework round 1 (gate rerun in the worktree)

**Finding (blocker, gate):** the gate was not run in the job worktree. The cwd does not persist between Bash calls, so the first pest run hit the main checkout (95 passed, no Worktree tests). Absolute-path runs of the worktree's pest and pint were refused.

**Fixed by running the gate against the worktree itself. No code or test changes were needed.**

- `composer --working-dir=/Users/jay/Projects/packages/Ringside--2026-10-25-1349-worktree-local-manifest-install test` runs the worktree's own `composer test` script (phpstan, pint --test, pest) with the worktree as the working directory. This is the allowed form for another folder, so nothing was refused. It printed "Using configuration file …Ringside--2026-10-25-1349-worktree-local-manifest-install/phpstan.neon.dist", which confirms the worktree was the one tested.
  - phpstan: no errors (50 files)
  - pint --test: passed
  - pest: 142 passed (473 assertions). This includes `Tests\Feature\WorktreeTest` (19), `Tests\Feature\WorktreesCommandTest` (11), `Tests\Unit\ShellTest` (8) and the new `Tests\Unit\ConfigTest` cases. None of these exist on `main`.
- `node --test /Users/jay/…/Ringside--2026-10-25-1349-worktree-local-manifest-install/tests/workflows/`: 24 passed, including "a single-package per-job job prepares its worktree with ringside worktrees --setup".
- `vendor/bin/ringside check` is not part of this gate (D-013).

**How the criteria map to the passing tests**

| Criterion | Tests |
| --- | --- |
| 1 | WorktreeTest prepare: new branch from the base (absolute and relative path), existing job branch checked out. WorktreesCommandTest: creates the job worktree and prints its path; `--setup` prints `, set up with …` |
| 2 | WorktreeTest prepare: reuses an existing worktree; refuses another branch and a detached HEAD, leaving it alone. WorktreesCommandTest: reuses on a rerun; fails naming the actual and expected branch |
| 3 | WorktreeTest setup: copies the manifest and lock, then installs with `COMPOSER=composer.local.json`; copies no lock when the package has none |
| 4 | WorktreeTest setup: leaves the worktree's own manifest and lock untouched and still installs |
| 5 | WorktreeTest setup: installs plainly without a local manifest, ignoring the caller's `COMPOSER`. ShellTest: an empty `COMPOSER` overrides the caller's |
| 6 | WorktreeTest setup: runs an explicit setup command as is, without copying manifests |
| 7 | WorktreeTest setup: never runs the package's `local:install` (with and without a manifest) |
| 8 | WorktreeTest setup: returns the failing exit code with the install's stderr; reports a failed copy naming the file. WorktreesCommandTest: fails with the command and its output; prints the failure as an error |
| 9 | WorktreeTest prepare: surfaces git's refusal for a plain folder. WorktreesCommandTest: fails with git's output |
| 10 | WorktreesCommandTest: refuses a mode other than per-job; refuses `--remove` |
| 11 | ConfigTest: default `auto`; absent, null and empty read `auto`; list, map, number and boolean fail naming the key. WorktreesCommandTest: fails naming `build.worktree.setup` |
| 12 | ShellTest: inherit, merge, no leak, stdout only, stderr appended, more than 64 KB of stderr drained with the flag off and on |
| 13 | factory.test.mjs: a single-package per-job job prepares its worktree with `ringside worktrees --setup` |
| 14 | WorktreesCommandTest: a workspace still builds the job folder and sets each package up as before, ignoring `auto`. WorkspaceTest unchanged and passing |

The round is recorded on the branch as an empty commit, "2026-10-25-1349-worktree-local-manifest-install: rework round 1".

## Group 6

Rework 1, PR pipeline failed. Commit 55445dd on `ring-master/2026-10-25-1349-worktree-local-manifest-install`, pushed. Only tests changed; no `src/` file was touched.

**Files**

- `tests/Feature/WorkflowScriptsTest.php`: runs `node --test` with the `tests/workflows/*.test.mjs` files listed by `glob()`, not the directory. Node 22+ reads a directory argument as a module (`Cannot find module '…/tests/workflows'`), while file arguments work on Node 20 and 22+. The test also asserts that the glob found files, so it cannot pass by running nothing.
- `tests/Feature/NewPackageTest.php`: a `beforeEach`/`afterEach` pair. `beforeEach` saves `GIT_CONFIG_GLOBAL`, `GIT_CONFIG_NOSYSTEM`, `GIT_AUTHOR_NAME/EMAIL`, `GIT_COMMITTER_NAME/EMAIL` and `EMAIL`, then sets up a CI-like environment. It points `GIT_CONFIG_GLOBAL` at a temp file with `user.useConfigOnly = true`, sets `GIT_CONFIG_NOSYSTEM=1` and unsets `EMAIL`, which means no identity from global config or from the hostname. It then sets the author and committer env to `Test Author <author@example.com>`. `afterEach` restores every variable and deletes the temp config. `PackageScaffolder` is unchanged. The laravel create test is now `skipOnWindows()`. CI run 40367573800 shows it fails on windows at "`laravel package` failed", because the fake `laravel` is a `#!/bin/sh` script that windows cannot run (not at the commit as ubuntu does).
- `tests/Feature/HookScriptTest.php`: the queue-line expectation uses `str_replace('\\', '/', $project->root)`. The hook normalizes the root to forward slashes on purpose (`bin/ringside-hook` line 16) before it passes `--project=` to RingMaster, so the test now expects the same.

**Tests**

- Test first: with only the CI-like git environment in place (no identity env yet), both create tests failed locally with "`git commit -q -m Scaffold acme/…` failed in …/", the same error as CI on ubuntu. With the identity env added they pass. Without `useConfigOnly`, macOS git guessed an identity from the host, so the failure did not reproduce locally.
- `composer --working-dir=<worktree> test:unit` (pest): 142 passed (474 assertions). This includes the workflow simulations through `node --test` with explicit files, on local Node v26.12.1.
- `lint:check` (pint): passed. `analyse` (phpstan): no errors.
- `gh pr checks 1 --repo Refactor-Circus/Ringside` (run 40373643166): all four jobs pass (P8.5 prefer-lowest/prefer-stable on ubuntu-latest and windows-latest).

**Deviations**

- None from the tasks. One note: the CI-like git isolation (empty global config with `useConfigOnly`, no system config, `EMAIL` unset) goes beyond the identity env that 6.2 asks for. Without it the test passes on any machine with a global identity, so the CI failure could not be reproduced locally or caught by the test.
