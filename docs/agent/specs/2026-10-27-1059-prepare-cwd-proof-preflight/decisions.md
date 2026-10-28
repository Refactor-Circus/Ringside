# Decisions

## D-001

- Question: Create file ticket T-002 as drafted? (Make Prepare cwd-proof and add a preflight)
- Choice: Create the ticket as drafted
- Rationale: none given
- Raised by: ticket (answered in RingMaster)
- Date: 2026-10-27

## D-002

- Question: Which standards apply to this spec?
- Choice: testing/test-layers + tooling/node-test + tooling/verification-gate + shell/error-output
- Rationale: none given
- Raised by: shape (answered in RingMaster)
- Date: 2026-10-27

## D-003

- Question: How should the preflight treat workspace jobs spanning several packages?
- Choice: Run dirty, remote and check_applies per package folder and aggregate the results
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-27

## D-004

- Question: When the Prepare agent's result omits dirty/remote/check_applies on a non-replay run, what should the script do?
- Choice: Treat missing fields on a non-replay run as a failed preflight and finish needs-decision at prepare; use safe defaults only for replay
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-27

## D-005

- Question: Who decides check_applies?
- Choice: The Prepare agent reports it from a file check
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-27

## D-006

- Question: Who computes the dirty overlap: the Prepare agent or the script?
- Choice: The agent returns raw git status --porcelain lines and the script computes the overlap against task-group files, so a simulation can test the rule
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-27

## D-007

- Question: Approve this spec as written so the factory can build it?
- Choice: Approve the spec
- Rationale: none given
- Raised by: approval (answered in RingMaster)
- Date: 2026-10-27

## D-008

- Question: Commit or stash these main changes before the build? (24 uncommitted modified files on main, including the files this job edits.)
- Choice: Commit them on main first, then build
- Rationale: none given (answered in RingMaster)
- Raised by: build
- Date: 2026-10-28

## D-009

- Question: The Windows CI fix (src/Worktree.php and tests/Feature/ImpactTest.php, commit 0a99916) is outside this spec's scope, which says no PHP changes are intended. Should it stay in this job or move to a job of its own?
- Choice: Keep the Windows CI fix in this job and accept the scope change (record it in decisions.md)
- Rationale: none given (answered in RingMaster)
- Raised by: build
- Date: 2026-10-28

## D-010

- Question: Amend project standard tooling/verification-gate: every gate command through composer --working-dir, ringside check only when check_applies on a synced worktree, gate stops needs-decision on out-of-scope files, PR body lists CI-only coverage?
- Choice: Accept as proposed
- Rationale: none given (answered in RingMaster)
- Raised by: post-mortem
- Date: 2026-10-28

## D-011

- Question: Amend project standard testing/ci-portability: one normalizer for git vs PHP paths, split output on \R, realpath over the whole path, PR names skipOnWindows gaps?
- Choice: Accept as proposed
- Rationale: none given (answered in RingMaster)
- Raised by: post-mortem
- Date: 2026-10-28

## D-012

- Question: Add project standard workflow/spec-readiness: main clean and green on every CI entry before approval, open questions settled into criteria text, agent/script split decisions carried into every criterion?
- Choice: Accept as proposed
- Rationale: none given (answered in RingMaster)
- Raised by: post-mortem
- Date: 2026-10-28

## D-013

- Question: Add project standard tooling/impact-check: report consumer failures caused by the consumer's own setup as environmental, separate from broken consumers?
- Choice: Accept as proposed
- Rationale: none given (answered in RingMaster)
- Raised by: post-mortem
- Date: 2026-10-28
