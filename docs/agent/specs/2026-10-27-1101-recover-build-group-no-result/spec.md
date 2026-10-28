# Recover a build group that returns no result

Ticket: [T-003](../../tickets/T-003.md)

## Goal

A build agent that ends without returning its result (usually out of turns) no longer escalates the whole job. The factory salvages the uncommitted work, retries only what is unfinished, and escalates only if that also fails. Alongside recovery, the turn budget is raised (implementer.md wording, `max_turns` 40 to 80) to prevent the null result in the first place. For whoever runs the factory.

## Acceptance criteria

1. When a Build-loop group agent returns null, the workflow runs one agent labelled `salvage g<n>` (agentType `ring-master-implementer`, role `reworker`, schema `BUILT`). A null group result produces a `salvage g<n>` call, and no `finish(escalated)` happens until the salvage and any retry have both returned.
2. The salvage prompt tells the agent to run `git -C <tree> status --porcelain` and `git -C <tree> diff` in that group's tree, compare them with the group's tasks, run the test command with `composer --working-dir=<tree>`, commit what passes as `<spec>: group <n> <name> (salvaged)`, tick only the finished tasks, add a `## Group <n> (salvaged)` note to `build.md`, and return `done` with the finished task ids. Before touching anything it checks for `.git/index.lock` and an in-progress merge or rebase, and if it finds one it stops and returns `blocked` instead of forcing. The simulation captures the salvage prompt and asserts it contains each of these fragments, with `<tree>`, `<spec>`, `<n>` and `<name>` interpolated.
3. The remaining tasks are the group's tasks minus the salvage's returned done ids intersected with the group's task ids; outside replay mode `tasks.md` is re-read as a cross-check, and if the two disagree the larger unfinished set is retried. An empty or bogus id list therefore retries every task not provably done. If no tasks remain there is no retry. Otherwise the workflow runs the group build once more, listing only the remaining ids (the `build` closure takes an optional task-id parameter that replaces its `todo` filter, in replay and non-replay mode). If salvage and retry succeed, the job continues to the integrate/gate steps without escalating. A near-limit implementer reports unfinished work by returning `done` for the finished tasks only (D-003); `blocked` is never used for it, so a normal build result that leaves tasks undone is retried the same way, with the remaining tasks computed as above.
4. A salvage or retry that returns null escalates with `a build agent failed`, as today, and the escalation `built` payload still includes the null entry for that group. When every group agent in a wave returned null (probable outage or rate limit) no salvage runs and the job escalates straight away with the same reason.
5. Decisions (`decisions`) and `blocked` results from a salvage or retry are handled like those of a normal build result (needs-decision / escalate with the blocked reasons).
6. The variant path (`buildVariant`) recovers a null result the same way (salvage in the variant's worktree, one retry of the unfinished tasks) before the variant is counted as failed.
7. (Dropped, D-005: the rework agents are not recovered; see Out of scope.)
8. `resources/agents/implementer.md` step 4 says: keep your last few turns for committing and returning your result; close to the turn limit, commit what passes and return `done` for the finished tasks only, without using `blocked` for the rest; never end without returning your result.
9. The default `max_turns` of `implementer` and `reworker` in `src/Config.php` is 80, as is the fallback in `src/Render/Template.php`; the README config example shows 80.
10. Node simulations in `tests/workflows/factory.test.mjs` assert on the recorded agent calls (label `salvage g<n>`, agentType, role/model/max_turns, schema) and cover: a build agent returns null once then succeeds and the run reaches the gate; two nulls in a row still escalate, with the null in `built`; replay mode (the retry prompt lists only the remaining ids); the non-replay retry prompt lists only the remaining ids; a salvage returning a bogus or empty id list; a salvage returning `blocked`; a salvage returning `decisions`; all groups null (no salvage, straight escalation); the variant path (criterion 6); and a null rework is still ignored with no salvage call (criterion 7 as dropped). Each of criteria 1 to 6 has at least one of these.
11. `tests/Unit/ConfigTest.php` pins the 80 defaults, a unit test asserts the `Template.php` fallback matches `Config.php`, a test asserts the README example shows 80, and a test (or `ringside check` rule) asserts the key phrases of `implementer.md` step 4.
12. `CHANGELOG.md` notes the new `max_turns` defaults and that values a consumer published or overrode are unchanged; the README example text says the same.
13. `vendor/bin/pest` passes.

## Design

- One helper in `ring-master-factory.js`, `recover(label, result, salvagePrompt, retry)`: if `result` is non-null return it; otherwise run the salvage agent, and if tasks remain run `retry(remainingTaskIds)`. Returns the final result or null. The Build loop and `buildVariant` go through it; rework calls do not (D-005). Tasks still not done are computed as in criterion 3.
- The retry reuses the existing `build` closure, which gains an optional second parameter (task ids) that replaces its `todo` filter in the prompt. Without it the closure would re-list the original tasks (`group.tasks` carries `done` from the start of the run, and in replay every task is `todo`). The helper always passes the ids explicitly.
- Done ids come from the salvage agent's self-report, so the helper validates them as in criterion 3 and fails safe toward retrying.
- The salvage prompt starts by checking for `.git/index.lock` and an in-progress merge or rebase, because a null result may be an API error, timeout or killed process rather than out-of-turns. For workspace jobs it uses `command(group.package,'test')` and `treeFor(group.package)`. Its handling when several groups share one tree is an open question.
- The salvage agent runs on the `reworker` role so it gets that role's model and `max_turns`.
- `max_turns` stays a role setting in `Config.php` per `architecture/configuration`; only the defaults change. `Template.php` keeps its hard fallback aligned with it.
- No new config keys.

## Out of scope

Recovering a null result from the review-loop rework agents (today's behaviour stays: the null is ignored and the next review round re-reports what is left), null results from non-build agents (reviewer, planner, integrate, fixer, gate steps), a dedicated salvage role or config key, the salvage itself running out of turns beyond criterion 4, other escalations, changing `escalate_model` behaviour, more than one retry, merging.

## Risks and open questions

- A salvage agent may commit half-working code if tests pass only partly; it is told to commit only what passes, and the gate still runs afterwards.
- Doubling `max_turns` raises worst-case cost per group; accepted in the ticket.
- Salvage cost: up to three agents per group, across a whole wave.
- Precondition: the working tree has unrelated edits to `ring-master-factory.js`, `Config.php` and tests (see shape.md); the build must start from a clean main.
- Replay mode does not tick `tasks.md`; the helper must rely on the salvage's returned ids there.

## Addendum 2026-10-27 (D-003, D-004)

- D-003: criteria 3 and 8 are updated above. No `unfinished:` convention, no BUILT schema change.
- D-004: the salvage is scoped to the group's `files` list. It runs `git -C <tree> status --porcelain -- <files>` and `git -C <tree> diff -- <files>`, commits only those paths, and so leaves other groups' uncommitted work in a shared tree alone. It skips the test run when the build skipped it (`testsInGroup` false). Criterion 2 is read with these two changes, and the simulation asserts the scoped paths and the skipped test step.
- D-005: criterion 7 is dropped. Rework agents keep today's behaviour and rework recovery is out of scope. No open questions remain.

## Addendum 2026-10-28 (D-009)

`ringside check` does not apply to Ringside (tooling/verification-gate). The gate prompts in `resources/workflows/ring-master-factory.js` stop running it for the Ringside package, and a finding asking for `ringside sync` output on the job branch is dropped.
