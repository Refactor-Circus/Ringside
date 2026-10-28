# Decision

Status: needs-decision at build (run wf_d373ca28-4c8, 2026-10-27).

## Reasons

- Group 1 (turn budget) built: tasks 1.1–1.4 done, committed as f94609b on `ring-master/2026-10-27-1101-recover-build-group-no-result--g1`. Its changed tests passed (35 tests, 121 assertions) and pint is clean. Task 1.5 (the full suite) is left for the gate. One deviation: it also changed `tests/Feature/SyncTest.php` (`maxTurns: 40` → `80`), which is outside the group's file list, because raising the default broke that assertion.
- Group 2 (salvage and retry) was blocked before any code change. The job branch was cut from main at 88d5f36, which has none of the code criteria 3 and 6 target (replay mode, `buildVariant`, `finish()`, the integrate step, group worktrees). That code exists only as uncommitted edits in the main checkout (24 files). The spec's precondition, "the build must start from a clean main", was not met.

## Open questions (questions.yml, source: build)

- **Q-007**: how group 2 should proceed when the base lacks the uncommitted factory code. Recommended: commit the in-progress main edits to main first as their own change, rebase the job branch onto that, then rerun group 2.
- **Q-008**: where the `tasks.md` cross-check of the salvage agent's done ids comes from, given the script never reads files and BUILT can't change (D-003). Recommended: a small read-only agent call per salvaged group that returns the ticked ids.

No precedents matched either question.

## Nits

None.
