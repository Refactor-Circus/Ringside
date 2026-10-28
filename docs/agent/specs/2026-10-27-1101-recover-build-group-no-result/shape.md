# Recover a build group that returns no result — Shaping Notes

## Scope

Ticket T-003. When an implementer ends without calling StructuredOutput (typically out of turns), `agent()` returns null and the factory escalates `a build agent failed` (`resources/workflows/ring-master-factory.js:301`). Seen on the Cortex, Roster and Atrium jobs.

In scope:

- A salvage agent plus one retry of the unfinished tasks, in the Build loop and in the `buildVariant` and rework paths.
- A turn-budget rule in `implementer.md` step 4.
- `implementer` and `reworker` `max_turns` raised from 40 to 80 in `src/Config.php` (lines 103 and 105), with `tests/Unit/ConfigTest.php` updated.
- A node simulation in `tests/workflows/factory.test.mjs` where a build agent returns null once and the job still completes.

Out of scope: other escalations, merging.

## Decisions

- D-001: ticket created (filed as T-003, since T-002 went to the prepare-cwd-proof-preflight job).
- D-002: standards confirmed in RingMaster (below).

## Context

- Today a null result from any build agent means `finish({status: 'escalated', reasons: ['a build agent failed']})` with no attempt to recover. Work the agent did is left in the worktree.
- The salvage agent should inspect the group's worktree and `tasks.md`, then report which tasks are done; the retry runs only the unfinished ones. A second null escalates as before.
- Several files in this repo's working tree are already modified (`Config.php`, `ring-master-factory.js`, tests); the build must start from a clean main.

## Standards

Confirmed by the user in RingMaster (D-002):

- `architecture/configuration`: the `max_turns` defaults live in `Config.php`.
- `testing/test-layers` and `tooling/node-test`: unit test for the config, node simulation for the workflow.
- `tooling/verification-gate`.
