# Post-mortem: 2026-10-25-1349-worktree-local-manifest-install

## Summary

The job made per-job worktrees install from `composer.local.json` (new `build.worktree.setup` default `auto`) and extended `ringside worktrees <spec> --setup` to single packages. The factory built it in 6 task groups: Shell stderr/env, Config, Worktree, command, factory prompt and docs, then Rework 1. The gate went green and all four reviewers found nothing. The job still cost three needs-decision stops and one human send-back ("PR pipeline failed").

No review finding cost a round. The costs came from three places:

- Repo state: uncommitted main changes, no git remote, and `ringside check` failing on a repo that never used its own workflow.
- A spec conflict: D-005 and criterion 12 said only the setup/install call may use stderr capture, but criteria 8 and 9 needed it on `git worktree add`.
- Local-only verification: three tests that also failed on `main` were only caught by CI after the human sent the job back.

## Metrics

- Factory runs: 4 (wf_77f93bd7-07f needs-decision round 0, wf_5b193a7c-a8a needs-decision round 1, wf_1b766faa-213 approved round 2, wf_2ac544ae-b89 approved round 1 of Rework 1).
- Gate rounds: round-1 PASS, round-2 PASS, and the Rework 1 round PASS. Pest went 103, 112, 131, then 142 passed (474 assertions at the end).
- Decisions: 14 (D-001 to D-014). Spec stage: 8 (D-001 to D-008). Build stage: 6 (D-009 to D-014).
- Build-stage escalations: 5 (D-010, D-011, D-012, D-013, D-014). Spec-stage questions: 2 (D-006, D-007) plus D-005 and D-008, all raised by spec-review.
- Deviations: 7 recorded in build.md. Four are notes, and one (D-012) became a decision.
- Review findings: 0 blockers or majors, and no nits in the final decision.
- Human send-backs: 1. PR review comments: none.
- Spec review: 3 passes, with 4 major and 8 minor findings fixed in pass 1.

## What went well

- spec-review.md pass 1 caught real design faults before any code existed:
  - A `Shell::run` pipe deadlock (stdout read to EOF before stderr).
  - `Project::path` mangling absolute paths.
  - Reuse of a stale or foreign folder as if it were a worktree.
  - Untestable criteria, which got a fake `composer` on PATH.
  The build then needed no rework for any of these.
- The `ShellTest` drain test is bounded to about 10 s, so a regression fails instead of hanging. It was checked against the old `Shell::run`.
- Criterion-to-test mapping was explicit in `round-1/gate.md` and `round-2/gate.md`, so every one of the 14 criteria had a named test.
- Group 6 used test-first discipline. The CI failure was reproduced locally by adding `useConfigOnly` and no identity before the fix.
- The factory stopped and asked on D-012 and D-013 instead of silently widening scope or running `sync` on Ringside.

## What cost rounds

1. Dirty main (D-010, D-011): the job worktree branches from committed main, but main held 13 uncommitted files that overlapped this job. This took two stops, because D-010 was answered "I commit" and nothing was committed.
2. Spec conflict (D-012): D-005 plus criterion 12 ("setup/install call is the only caller") contradicted criteria 8 and 9. Git writes its refusals to stderr, so `Worktree::prepare` could not meet them without the flag.
3. Gate that cannot pass (D-013): `ringside check` failed on `main` too, because Ringside tracks no `ring-master.yml`, standards index or `.claude/`. Rework round 1 in build.md was spent diagnosing this and committed nothing. A second blocker, the gate being run in the wrong cwd (pest hit the main checkout, 95 passed), was fixed by `composer --working-dir=<worktree> test`.
4. No remote (D-014): the approved run could not open a PR. Q-012 needed the human to supply the two GitHub URLs.
5. CI-only failures (Rework 1, group 6): after the PR opened, three tests failed on CI:
   - `tests/Feature/WorkflowScriptsTest.php`: `node --test tests/workflows/` fails on Node 22+, which reads a directory as a module.
   - `tests/Feature/NewPackageTest.php`: no git identity on CI. It also needed a `skipOnWindows()` for the `#!/bin/sh` fake `laravel`.
   - `tests/Feature/HookScriptTest.php`: Windows backslash root against the hook's forward-slash normalization.

   All three also failed on `main`.

## Root causes

- **spec_gap** (D-012): D-005 and criterion 12 were written as "only the setup/install call turns on stderr" without checking criteria 8 and 9. Spec-review pass 3 did not cross-check criteria against each other. Evidence: build.md Group 3 deviation, decisions.md D-012.
- **spec_gap** (D-013): the spec and gate assumed `ringside check` applies to Ringside itself. Nothing checked the package had a standards index. Evidence: build.md "Rework round 1", decisions.md D-013, and the same failure note in `round-2/gate.md`.
- **tooling** (D-010, D-011, D-014): the factory has no preflight for a dirty main overlapping the job's files, or for a missing git remote or host, so both surfaced only after the build or approval. The ship flow also did not commit pending work when told to ("I commit").
- **tooling** (cwd): the gate cannot rely on `cd` persisting, and absolute-path pest/pint invocations were refused. Evidence: build.md "gate rerun in the worktree", the Note in `round-1/gate.md`. The workaround is `composer --working-dir`.
- **standards_gap** (CI failures): `testing/test-layers` (D-003) does not require cross-platform and cross-Node portability. The gate runs only on the developer's macOS with Node 26 and a global git identity. The P8.5 matrix (prefer-lowest/stable, ubuntu/windows) is only seen after the push. The same blind spot hid three pre-existing failures on `main`.
- **implementer_error** (minor): the prompt text in group 5 was committed before groups 3 and 4 existed (task order 1, 2, 5, 3, 4), so an intermediate commit would break a factory run. This is documented in build.md and did no harm.
- **reviewer_noise**: none. The final reviewers raised nothing.

## What human review caught

- The only human intervention on quality was "PR pipeline failed". The factory's gate and four reviewers were green, but CI was red on three tests (Node 22 directory argument, missing git identity on a clean runner, Windows paths and `/bin/sh` fake). The gate ran only locally, so CI behaviour was invisible to it.
- The human also had to supply the remote URLs (D-014) and the "commit pending main" choice (D-010, D-011).
- No inline PR comments were left by people.

## Proposed follow-ups

1. Add a gate or reviewer step that reads the repo's CI workflow (matrix of OS, PHP, Node and dependency set) and flags tests that depend on the local environment: git identity, Node version, path separators, `/bin/sh`. At minimum, run the gate with a clean git env (empty global config with `useConfigOnly`, `EMAIL` unset), as the NewPackageTest fix does.
2. After the push, have the factory wait for `gh pr checks` and rework on red CI before declaring approved. Rework 1 did this manually.
3. Add a preflight to the factory for:
   - a dirty main that overlaps the spec's files,
   - a missing git remote or host,
   - `ringside check` applicability, meaning a missing standards index.
   Resolve these before the build, not after approval.
4. In spec-review, add a cross-criteria consistency check: a restriction like "only caller" must be tested against every criterion that needs the restricted capability.
5. Add portability notes to `testing/test-layers` and `architecture/configuration`: pass explicit file lists to `node --test`, isolate git config in tests that commit, and `skipOnWindows()` for shell fakes.
6. Decide whether Ringside should use its own workflow (run `sync`, with its hook path problem), or exclude it formally in the factory gate config so D-013 is not re-asked.

## Standards proposals

Discovery run wf_e8731e39-6fb proposed four project standards (all `target: project`, `action: add`). All four were accepted as proposed in RingMaster (D-015 to D-018, 2026-10-26) and committed in f02e74c on `ring-master/standards-2026-10-25-1349-worktree-local-manifest-install` (not pushed). None were rejected. Accepted ones go to `docs/agent/standards/<key>.md` (`status: proposed`, `from_spec` this spec) on branch `ring-master/standards-2026-10-25-1349-worktree-local-manifest-install`; rejected ones move to "Rejected proposals".

### testing/ci-portability (convention)

Rationale: the gate and all four reviewers were green, but CI went red on three tests that also failed on main (ambient git identity, POSIX-only fakes and paths on windows-latest). `testing/test-layers` says nothing about portability. Evidence: build.md Group 6, decision.md Rework 1, tests/Feature/NewPackageTest.php, tests/Feature/HookScriptTest.php, .github/workflows/tests.yml.

````markdown
# CI Portability

Every test passes on each entry of the CI matrix in `.github/workflows/tests.yml` (ubuntu-latest and windows-latest, prefer-lowest and prefer-stable), not only on the developer's machine.

## Git

- A test that makes a commit isolates git config. Point `GIT_CONFIG_GLOBAL` at a temp file with `user.useConfigOnly = true`, set `GIT_CONFIG_NOSYSTEM=1`, unset `EMAIL`, then set `GIT_AUTHOR_*` and `GIT_COMMITTER_*` explicitly.
- Without `useConfigOnly`, git guesses an identity from the host, so a clean-runner failure does not reproduce locally.
- Save every variable you change in `beforeEach` and restore it in `afterEach`. When the test runs a subprocess, pass the env through `Shell::run`'s `$env` argument instead, so the test process itself is never changed.

## Shell fakes and POSIX-only behaviour

- A fake binary written as `#!/bin/sh` (fake `composer`, `git`, `laravel`) only runs on POSIX. Mark the test or `describe` block `skipOnWindows()` and add a comment naming the fake.
- Do the same for tests that depend on `chmod`, symlinks or `stream_select` on pipes.

## Paths

- Compare paths in the form the code produces. When the code normalizes to forward slashes (for example `bin/ringside-hook` before it passes `--project=`), the expectation normalizes too: `str_replace('\\', '/', $root)`.
- Treat `C:\…` as absolute, as well as `/…`.
- Compare temp paths through `realpath()`, because macOS resolves them to `/private/var/…`.

## Example

```php
beforeEach(function () {
    $this->gitConfig = (string) tempnam(sys_get_temp_dir(), 'gitconfig-');
    file_put_contents($this->gitConfig, "[user]\n\tuseConfigOnly = true\n");
    putenv("GIT_CONFIG_GLOBAL={$this->gitConfig}");
    putenv('GIT_CONFIG_NOSYSTEM=1');
    putenv('EMAIL');
    putenv('GIT_AUTHOR_NAME=Test Author'); // plus _EMAIL and GIT_COMMITTER_*
});

it('scaffolds a laravel package', function () {
    // ...
})->skipOnWindows(); // the fake `laravel` is a #!/bin/sh script
```
````

### tooling/node-test (tooling)

Rationale: WorkflowScriptsTest ran `node --test tests/workflows/`, passed locally on Node 26 and failed on CI's Node 22; build notes used the directory form too. Evidence: build.md Group 5, Group 6 and rework round 1, tests/Feature/WorkflowScriptsTest.php.

````markdown
# Node Workflow Tests

`node --test` always gets an explicit list of test files, never a directory.

## Rules

- Node 22 and later read a directory argument as a module and fail with `Cannot find module '…/tests/workflows'`. File arguments work on Node 20 and later. CI uses the Node that ships with the runner, not your local version.
- Glob the files (`tests/workflows/*.test.mjs`) and assert that the glob found some, so the test cannot pass by running nothing.
- Skip only when `node` is not installed, and give the reason.
- Gate and build notes that run the workflow tests directly follow the same form: list the files, or run them through `WorkflowScriptsTest`.

## Example

```php
it('passes the workflow simulations (node --test)', function () {
    // Node 22+ reads a directory argument as a module, so name each test file.
    $files = glob(dirname(__DIR__).'/workflows/*.test.mjs') ?: [];
    [$code, $output] = Shell::run(['node', '--test', ...$files], dirname(__DIR__, 2));

    expect($files)->not->toBeEmpty()
        ->and($code)->toBe(0, $output);
})->skip(fn (): bool => Shell::run(['node', '--version'], sys_get_temp_dir())[0] !== 0, 'node is not installed');
```
````

### tooling/verification-gate (tooling)

Rationale: rework round 1 found the gate had run pest against the main checkout (fixed with `composer --working-dir`); `ringside check` failed on main because Ringside never adopted its own workflow (D-013); the gate passed while CI was red. If Ringside later adopts its own workflow, drop "Not in the gate". Evidence: build.md rework round 1 and Group 6, round-1/gate.md, round-2/gate.md, decisions.md D-013.

````markdown
# Verification Gate

The gate runs this package's CI steps against the job worktree, and a job is done only when the PR's CI checks are green.

## Running the gate

- From the main checkout, run `composer --working-dir=<worktree> test` (phpstan, pint --test, pest). The cwd does not carry over between calls, and absolute-path `vendor/bin/pest` runs may be refused. A bare `vendor/bin/pest` silently tests the main checkout.
- Confirm that the worktree is what got tested. phpstan prints the config path from the worktree, and the job's new tests appear in the pest output.
- The gate's scripts match the CI steps: `composer analyse`, `composer lint:check`, `composer test:unit`.

## Not in the gate

- `ringside check` does not apply to Ringside. This repo does not use its own workflow (no `ring-master.yml`, standards index or `.claude/`), so the check fails on `main` too (D-013). Do not run `sync` here to make it pass.

## After the push

- The local gate covers one OS and one Node version. CI (`.github/workflows/tests.yml`) covers ubuntu-latest and windows-latest with prefer-lowest and prefer-stable.
- Wait for the PR checks and rework on red before you report the job as approved.

## Example

```bash
composer --working-dir=/Users/jay/Projects/packages/Ringside--<spec> test
gh pr checks <pr> --repo Refactor-Circus/Ringside --watch
```
````

### shell/error-output (convention)

Rationale: D-005 limited stderr capture to the setup/install call, but criteria 8 and 9 needed git's refusals, causing the D-012 stop. Older callers still drop stderr from reported errors: Workspace::prepare and ::remove (src/Workspace/Workspace.php:151, :186), CleanupCommand (src/Console/CleanupCommand.php:45), Proposer (src/Discovery/Proposer.php:73, :98), PackageScaffolder (src/Scaffold/PackageScaffolder.php:113-117). Evidence: decisions.md D-005, D-012, build.md Group 3, src/Worktree.php.

````markdown
# Command Error Output

When a command's output goes into an error message, run it with `withStderr: true`. When you parse a command's stdout as a value, leave the flag off.

## Rules

- git, composer and the installers write refusals and failures to stderr. Without the flag, the exception or printed line has nothing after the colon.
- Turn the flag on for every call that throws or prints `$out` on a non-zero exit: `git worktree add` and `remove`, `git commit`, `git checkout -b`, `composer install`, configured setup commands and scaffold steps.
- Leave it off where stdout is the result: `git branch --show-current`, `git rev-parse`, `git config user.name`, `git remote get-url`. Appending stderr would corrupt the value.
- If a spec limits the flag to certain callers, check that limit against every criterion that asks for "the command's output" (D-012).

## Example

```php
[$code, $out] = Shell::run($command, $project->root, withStderr: true);

if ($code !== 0) {
    throw new RuntimeException("Could not create the worktree at {$path} on {$branch}: {$out}");
}

[, $current] = Shell::run(['git', 'branch', '--show-current'], $worktree); // a value: stdout only
```
````
