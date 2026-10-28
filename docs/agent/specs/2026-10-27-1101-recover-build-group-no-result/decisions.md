# Decisions

## D-001

- Question: Create file ticket "Recover a build group that returns no result" from the task-body draft, then move on to shaping?
- Choice: Create the ticket and continue
- Rationale: none given
- Raised by: ticket (answered in RingMaster)
- Date: 2026-10-27
- Note: filed as T-003, because T-002 went to the prepare-cwd-proof-preflight job in the same pass.

## D-002

- Question: Which standards apply to this spec?
- Choice: architecture/configuration + testing/test-layers + tooling/node-test + tooling/verification-gate
- Rationale: none given
- Raised by: shape (answered in RingMaster)
- Date: 2026-10-27

## D-003

- Question: How should a near-limit implementer report unfinished tasks, given the workflow escalates on any non-null blocked result?
- Choice: Return done for the finished tasks only; the workflow computes the remaining tasks as the group's tasks minus done and retries them, without using blocked
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-27

## D-004

- Question: How should the salvage agent behave when several groups of a parallel wave share one working tree?
- Choice: Scope the salvage to the group's files list, and skip the test run when the build skipped it (testsInGroup false)
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-27

## D-005

- Question: Should the review-loop rework agents (criterion 7) get the same null-result salvage and retry, given a rework works on findings, not task ids?
- Choice: Drop criterion 7: keep today's behaviour (a null rework is ignored and the next review round re-reports what is left); list rework recovery under Out of scope
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-27

## D-006

- Question: Approve this spec as written so the factory can build it?
- Choice: Approve the spec
- Rationale: none given
- Raised by: approval (answered in RingMaster)
- Date: 2026-10-27

## D-007

- Question: The job branch was cut from main at 88d5f36, which has no replay mode, buildVariant, finish(), integrate step or group worktrees. Those exist only as uncommitted edits in the main checkout, but criteria 3 and 6 target them. How should group 2 proceed?
- Choice: Commit the in-progress main edits to main first (as their own change), rebase the job branch onto it, then rerun group 2
- Rationale: none given
- Raised by: build (answered in RingMaster)
- Date: 2026-10-27

## D-008

- Question: Criterion 3 says that outside replay mode 'tasks.md is re-read as a cross-check' against the salvage's done ids, and the larger unfinished set wins. The factory script never reads files (only agents do), and the BUILT schema may not change (D-003). Where does the second source come from?
- Choice: Add a small read-only agent call per salvaged group (e.g. label 'tasks g<n>', prepare role) that reads tasks.md and returns the group's ticked task ids; the script takes the larger unfinished set
- Rationale: none given
- Raised by: build (answered in RingMaster)
- Date: 2026-10-27
