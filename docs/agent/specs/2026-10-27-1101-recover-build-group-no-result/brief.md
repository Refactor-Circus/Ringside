Risk: medium

## What changed
- Factory workflow (`resources/workflows/ring-master-factory.js`, about 120 lines): a new `recover()` helper. When a Build-loop group agent (or a variant) returns null, it runs one `salvage g<n>` agent on the `reworker` role, works out the unfinished tasks, and retries only those once. The `build` closure takes an optional task-id list. If every group in a wave is null, there is no salvage and the job escalates at once.
- Agent and config defaults: `implementer.md` step 4 now tells the agent to keep its last turns for committing and returning, and to return `done` for the finished tasks only. `max_turns` for `implementer` and `reworker` goes from 40 to 80 in `src/Config.php`, the `Template.php` fallback and the README example.
- Tests and docs: Node simulations in `tests/workflows/factory.test.mjs`, new `ConfigTest` cases, a `SyncTest` tweak, and a CHANGELOG entry.

## Why
A build agent that ran out of turns returned no result, and the whole job escalated even though the work was mostly on disk. Now the factory salvages the uncommitted work, retries what is unfinished, and escalates only if that also fails. The higher turn budget prevents most nulls in the first place.

Decisions that shaped it:
- D-003: unfinished work is reported as `done` for the finished tasks only, never `blocked`. No change to the BUILT schema.
- D-004: the salvage is scoped to the group's `files`, so a shared tree is safe. It skips tests when the build skipped them.
- D-005: rework agents are not recovered.
- D-007: the in-progress main edits were committed first, and the branch was rebased onto them.
- D-008: a small read-only `tasks g<n>` agent re-reads `tasks.md` as the cross-check outside replay.
- D-009: `ringside check` stays out of Ringside's own gate.

## Look at
- `resources/workflows/ring-master-factory.js:213`: `recover()`. Check the remaining-task logic, which takes the larger unfinished set and fails safe toward retrying, and the case where the salvage or retry returns null.
- `resources/workflows/ring-master-factory.js:358`: `salvageGroup`. Read the prompt for the lock and merge guard, the file scoping, and the skipped test step.
- `resources/workflows/ring-master-factory.js:385`: the all-null wave shortcut, and how `recoverGroup` is wired into the pipeline.
- `resources/workflows/ring-master-factory.js:295`: the variant salvage. It recovers only a null result, not `retryUndone`.
- `src/Config.php`: the new 80 defaults. Check they do not change values a consumer has already published.

## Tested
- Criteria 1, 2, 4, 5, 6 and 10: the salvage and retry simulations in `tests/workflows/factory.test.mjs`. They cover null then success, two nulls escalating with the null in `built`, a lock or merge giving `blocked`, `decisions`, all groups null, the variant path, and a null rework still ignored.
- Criterion 3: the replay and non-replay retry simulations, the bogus, empty or disputed done-list case, the nothing-left case, and the D-003 case where tasks are left undone.
- Criteria 8, 9, 11: `tests/Unit/ConfigTest.php` (80 defaults, the `Template.php` fallback, the README example, the implementer step 4 phrases) and `SyncTest` (`maxTurns: 80`).
- Criterion 12: CHANGELOG and README are in the diff, but no test asserts them.
- Criterion 13: pest ran 175 passed, and pint passed.

## Risks
- The last gate report (round 2) says CHANGES, because `ringside check` failed on stale generated files. D-009 resolved this: Ringside does not track those files, and the gate prompt no longer runs `ringside check` for Ringside. The round 2 file was written before that fix and has not been re-run.
- Doubling `max_turns` raises the worst-case cost per group, and a salvage plus a retry can add up to two more agents per group.
- The salvage may commit partly working code. It is told to commit only what passes, and the gate still runs afterwards.
- The done ids come from the agent's self-report. The code validates them and retries when in doubt, but it can over-retry.
- Consumers who have published or overridden `max_turns` keep their values. Only the defaults change.
- Open items: none (nits: 0).
