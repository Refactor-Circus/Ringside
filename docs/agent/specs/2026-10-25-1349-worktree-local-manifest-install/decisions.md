# Decisions

## D-001 Create the ticket as drafted

- **Question:** Create the ticket as drafted in ticket-draft.md (file tracker, next key T-001)?
- **Choice:** Create as drafted
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-25
- **Raised by:** ticket

## D-002 How `build.worktree.setup` selects the local-manifest install

- **Question:** How should `build.worktree.setup` select the local-manifest install?
- **Choice:** New default `auto` (composer.local.json when present, else composer install); an explicit command string still runs as is
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-25
- **Raised by:** shape

## D-003 Standards for this spec

- **Question:** Apply these standards to this spec?
- **Choice:** architecture/configuration, testing/test-layers
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-25
- **Raised by:** shape

## D-004 Where the per-job worktree setup command lives

- **Question:** Where should the per-job worktree setup command live?
- **Choice:** Extend `ringside worktrees <spec> --setup` to single packages (creates or reuses the per-job worktree, then installs)
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-25
- **Raised by:** shape

## D-005 How setup failures capture stderr

- **Question:** Shell::run discards stderr, so a failed setup (criterion 7) shows almost nothing from composer. How should stderr be captured?
- **Choice:** Opt-in parameter on Shell::run (e.g. bool $withStderr = false); only the setup/install call turns it on
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-25
- **Raised by:** spec-review

## D-006 Existing local manifests on a `--setup` rerun

- **Question:** On a rerun of `worktrees --setup`, what happens when composer.local.json or composer.local.lock already exists in the worktree?
- **Choice:** Copy only when the file is absent in the worktree
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-25
- **Raised by:** spec-review

## D-007 Existing worktree on a different branch

- **Question:** When the single-package worktree folder exists but is on a different branch than ring-master/<spec>, what should `worktrees` do?
- **Choice:** Fail with exit 1 and a message naming the actual and expected branch
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-25
- **Raised by:** spec-review

## D-008 Empty or non-string `build.worktree.setup`

- **Question:** Is an empty or non-string build.worktree.setup read silently as `auto`, or does it fail loud as the configuration standard requires?
- **Choice:** Fail loud on a non-string value; empty reads as `auto`
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-25
- **Raised by:** spec-review

## D-009 Spec approval

- **Question:** Approve the spec (14 acceptance criteria in spec.md, D-007 and D-008 now written in) so tasks and the factory can run?
- **Choice:** Approve the spec as written
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-26
- **Raised by:** approval

## D-010 Uncommitted main edits before the build

- **Question:** The main checkout has uncommitted edits to files this job also changes (src/Config.php, resources/workflows/ring-master-factory.js, README.md, CHANGELOG.md), and the job worktree branches from committed main. How should the build start?
- **Choice:** I commit the pending changes to main first, then the factory builds
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-26
- **Raised by:** build

## D-011 Who commits the pending main changes

- **Question:** Per D-010 you commit the pending main changes before the build, but Ringside main still has them uncommitted (13 modified files plus untracked docs/). Have you committed them so the factory can build?
- **Choice:** You commit (the agent commits the pending changes to main, then the factory builds)
- **Rationale:** (none given; answered in RingMaster). Done in 5abf90e (the 13 modified files, after pest and node workflow tests passed); the spec and ticket files under docs/ stay uncommitted as job state.
- **Date:** 2026-10-26
- **Raised by:** build

## D-012 stderr on `git worktree add`

- **Question:** git writes its refusals ("already exists", "is already used by worktree") to stderr. Criteria 8 and 9 say a failed `git worktree add` must surface git's output, but D-005 and criterion 12 say only the setup/install call turns on Shell::run's $withStderr. Should Worktree::prepare pass $withStderr = true on its `git worktree add` call?
- **Choice:** Yes: prepare passes $withStderr = true for `git worktree add` only, and criterion 12's 'only caller' is read as 'existing git callers unchanged'
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-26
- **Raised by:** build

## D-013 Pre-existing `ringside check` failure in the gate

- **Question:** The `ringside check` gate fails in the Ringside repo because Ringside has never been set up to use its own workflow. The failure is already there on main and is not caused by this job. How should this job's gate handle it?
- **Choice:** Treat `ringside check` as not applicable to the Ringside repo: drop it from this package's gate (or mark the failure as pre-existing) and ship on pest and pint
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-26
- **Raised by:** build

## D-014 Finishing without a git remote

- **Question:** The factory approved the job (gate green, all four reviewers found nothing), but the Ringside repo has no git remote and no host is configured, so the branch ring-master/2026-10-25-1349-worktree-local-manifest-install cannot be pushed and no PR can be opened. How should this job finish?
- **Choice:** Use git@github.com:Refactor-Circus/RingMaster.git for RingMaster and git@github.com:Refactor-Circus/Ringside.git for Ringside; set both up and push
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-26
- **Raised by:** build

## D-015 Project standard testing/ci-portability

- **Question:** Add project standard testing/ci-portability (git-config isolation in tests that commit, skipOnWindows for /bin/sh fakes, path normalization)? Full text in post-mortem.md, "Standards proposals".
- **Choice:** Accept as proposed
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-26
- **Raised by:** post-mortem

## D-016 Project standard tooling/node-test

- **Question:** Add project standard tooling/node-test (node --test always gets an explicit file list, never a directory)? Full text in post-mortem.md, "Standards proposals".
- **Choice:** Accept as proposed
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-26
- **Raised by:** post-mortem

## D-017 Project standard tooling/verification-gate

- **Question:** Add project standard tooling/verification-gate (run the gate via composer --working-dir on the worktree, ringside check excluded for Ringside, wait for gh pr checks before approving)? Full text in post-mortem.md, "Standards proposals".
- **Choice:** Accept as proposed
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-26
- **Raised by:** post-mortem

## D-018 Project standard shell/error-output

- **Question:** Add project standard shell/error-output (withStderr when output is reported, off when stdout is parsed as a value)? Full text in post-mortem.md, "Standards proposals".
- **Choice:** Accept as proposed
- **Rationale:** (none given; answered in RingMaster)
- **Date:** 2026-10-26
- **Raised by:** post-mortem
