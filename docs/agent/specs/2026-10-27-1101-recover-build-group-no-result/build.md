# Build: Recover a build group that returns no result

## Group 2

Stopped before any code change; nothing committed on `ring-master/2026-10-27-1101-recover-build-group-no-result--g2`, no tasks ticked.

- Files: none changed.
- Tests: none written or run.
- Why: the job branch was cut from `main` at 88d5f36, which has no replay mode, no `buildVariant`, no `finish()`, no integrate step and no group worktrees (none in `src/`, `resources/` or `tests/`). They exist only as uncommitted edits in the main checkout (24 files). Criteria 3 (replay retry, "continues to the integrate/gate steps") and 6 (variant path) target that code, and the spec's precondition ("the build must start from a clean main") was not met. Also, the workflow script cannot read files, so the `tasks.md` cross-check in criterion 3 needs a design choice. Both are returned as decisions.
- Deviations: none.

## Group 1

Committed as 213968d on `ring-master/2026-10-27-1101-recover-build-group-no-result--g1`, now based on the job branch at f2f488d (first committed as f94609b on 88d5f36, before D-007 moved the job branch). Tasks 1.1 to 1.4 ticked; 1.5 (full `vendor/bin/pest`) left to the gate, as the build prompt said not to run the suite.

- Rebase onto f2f488d: `src/Config.php` and `README.md` conflicted with main's new `integrator` role and `escalate_model` keys. Resolved by keeping main's lines and raising `implementer` and `reworker` `max_turns` to 80. The CHANGELOG note had auto-merged into 0.8.0; moved it to 0.10.0, the top unreleased section (nothing after v0.7.4 is tagged).

- Files: `src/Config.php` (implementer and reworker `max_turns` 40 to 80), `src/Render/Template.php` (fallback `?? 80`), `resources/agents/implementer.md` (step 4 turn-budget rule), `README.md` (example shows 80, plus a paragraph saying a `max_turns` already set in `ring-master.yml` is unchanged), `CHANGELOG.md` (0.10.0 note), `tests/Unit/ConfigTest.php`, and `tests/Feature/SyncTest.php` (outside the group's file list: it pinned `maxTurns: 40` in the rendered implementer agent, now `maxTurns: 80`).
- Tests: five new cases in `tests/Unit/ConfigTest.php`: the 80 defaults for both roles; an override in `ring-master.yml` is kept; the `Template::vars` fallback (role with `max_turns: null`) equals `Config::defaults()`; the README example's implementer and reworker `max_turns` equal the defaults and 80, and the README carries the "stays as it is" sentence; `implementer.md` step 4 contains the key phrases of criterion 8. Ran only `ConfigTest` and `SyncTest` (35 passed) and `pint --test` on the changed PHP files (passed). After the rebase I ran them again with `composer --working-dir=<g1 tree> exec -- pest tests/Unit/ConfigTest.php tests/Feature/SyncTest.php`: 41 passed (150 assertions), and `pint --test` on the four PHP files passed. A bare `vendor/bin/pest` after `cd` ran the main checkout instead (36 tests, without the new ones), so use `--working-dir`.
- Deviations: none from standards. One file outside the list (`tests/Feature/SyncTest.php`), needed to keep the suite green.

## Group 2

Rerun after D-007 (job branch rebased onto f2f488d) and D-008. This replaces the stopped note above. Committed as dad08b3 on `ring-master/2026-10-27-1101-recover-build-group-no-result--g2`. Tasks 2.1 to 2.4 are ticked. 2.5 (full `vendor/bin/pest`) is left to the gate, as the build prompt said not to run the suite.

- Files: `resources/workflows/ring-master-factory.js`, `tests/workflows/factory.test.mjs`.
- Code:
  - `build(group, ids)` and `buildVariant(n, ids)` take optional task ids that replace the `todo` filter, in replay and non-replay mode. A retry is labelled `group <n> retry` / `variant <n> retry`, and its prompt says earlier work is already committed. The wave pipeline calls `g => build(g)`, so the pipeline's index argument is never read as ids.
  - `recover(label, result, salvagePrompt, retry, { ids, ticked, stage })`: a null result runs `salvage <label>` (agentType `ring-master-implementer`, `reworker` role, schema `BUILT`). A non-null result that is blocked or has open decisions comes back unchanged. A result that leaves ids undone gets the retry without a salvage (D-003).
  - `remaining()` computes what is left: the group's todo ids minus the done ids that are group ids. Outside replay it makes one read-only `tasks g<n>` call (`prepare` role, D-008), and the larger unfinished set wins, so a null cross-check retries everything. The retry runs only if ids remain. A null salvage or retry gives null, so the null stays in `built` and the job escalates `a build agent failed`.
  - The Build loop collects every group's first result before recovering any. With more than one group in the wave and all of them null, it logs and escalates straight away with `built` all nulls.
  - The salvage prompts carry the lock/merge/rebase check, file-scoped `status --porcelain -- <files>` and `diff -- <files>` (D-004), the test run through `composer --working-dir=<tree>` (skipped when `testsInGroup` is false), the `(salvaged)` commit message, ticking only finished tasks (not in replay), the `## Group <n> (salvaged)` note and returning `done` ids.
  - The variant salvage (`salvage v<n>`) works in `vTree(n)` and has no `tasks.md` cross-check, since variants never tick it.
  - Rework calls are unchanged.
- Tests: 13 new simulations in `tests/workflows/factory.test.mjs`:
  - null, then salvage, cross-check and retry reach the gate (labels, agentType, reworker model/effort, BUILT schema, non-replay retry lists only the remaining ids)
  - a null salvage or retry escalates with `built: [null]`
  - the replay retry lists only the remaining ids, with no cross-check
  - bogus, empty and disputed done lists
  - nothing left means no retry
  - a normal result with undone tasks is retried without a salvage
  - a salvage or retry that returns `blocked`
  - a salvage or retry that returns `decisions`
  - an all-null wave escalates with no salvage
  - the salvage prompt fragments
  - the parallel group-tree salvage skips the test step
  - the variant salvage and retry
  - a null rework is still ignored

  The two existing variant tests now return `done: ['1.1', '2.1']`, so a finished variant is not retried. I ran only `node --test tests/workflows/factory.test.mjs`: 47 passed.
- Deviations: none from standards. Interpretations:
  - The all-null shortcut applies only to waves of more than one group. Otherwise a one-group wave could never be salvaged (criterion 1 against criterion 4).
  - A normal result that claims every id done is trusted without the `tasks.md` cross-check, so a clean build costs no extra agent.
  - `max_turns` cannot be asserted in the simulations, because the script passes only model and effort. The salvage uses the `ring-master-implementer` agent definition, like the rework calls.
- Risk: under D-003, a task the implementer is told not to do, such as "Run vendor/bin/pest" in a parallel group, is never reported done. It always costs one retry agent per group. The retry does not escalate.
- Re-dispatch for task 1.5: no code change and no new commit. 213968d is still the tip of the g1 branch, its parent f2f488d is the job branch tip, and the tree is clean. `composer --working-dir=<g1 tree> exec -- pest tests/Unit/ConfigTest.php tests/Feature/SyncTest.php`: 41 passed (150 assertions). Task 1.5 (full `vendor/bin/pest`) stays unticked for the gate, because the build prompt again said not to run the suite.

## Rework round 1

Committed as 03e8c85 on `ring-master/2026-10-27-1101-recover-build-group-no-result`.

- Major (variant retry on a normal result): `recover()` takes a new `retryUndone` option, off by default. Only the Build loop's `recoverGroup` passes `retryUndone: true`, so a non-null group result that leaves tasks undone is still retried (D-003). `buildVariant` now recovers only a null result: a variant's prompt never asks it to list finished ids, so a normal variant result with `done: []` is kept as it is. A variant salvage still feeds its own `done` ids into the retry, as before.
- Test: new simulation "a variant that returns a normal result is kept as it is, even with an empty done list". Both variants return `{ ...built, done: [] }`. It asserts there is no `salvage v<n>`, `variant <n> retry` or `tasks v<n>` call, that both variants are gated, and that the judge's pick is adopted. It failed on the old script ("no retry for a variant that returned a result") and passes now.
- Nit (quoting): the salvage's scoped `git status --porcelain -- <files>` and `git diff -- <files>` now single-quote each path (an embedded `'` becomes `'\''`). The two salvage-prompt simulations assert the quoted form.
- Nit (`ringside check`): no change. D-013 still holds. There is still no `ring-master.yml` in Ringside, so `tooling/verification-gate` keeps the check out of the gate on purpose. The main checkout now has untracked `.claude/`, `docs/agent/standards.index.yml` and `standards.summary.md`. If Ringside adopts its own workflow, that standard says to drop the exclusion.
- Files: `resources/workflows/ring-master-factory.js`, `tests/workflows/factory.test.mjs`.
- Gate: `composer --working-dir=<job worktree> test` ran phpstan (no errors, config from the worktree), pint (passed) and pest: 175 passed (645 assertions), including `WorkflowScriptsTest`. On its own, `node --test tests/workflows/factory.test.mjs` passed 48 of 48.
- Deviations: none.

## Rework round 2

No commit. The one finding (blocker, `ringside check`: "Generated files are stale or missing") was not applied, and the question went back as a decision.

- Why: the job worktree tracks no generated files (no `.claude/`, `AGENTS.md`, `docs/agent/standards.index.yml` or `standards.summary.md` in git, on this branch or on `main`). `composer --working-dir=<job worktree> exec ringside -- sync -v` writes 32 new files, including `.claude/settings.json` with hooks. Committing them, as the finding asks, would make Ringside adopt its own workflow. `tooling/verification-gate` (an applicable standard, from D-013) keeps `ringside check` out of this repo's gate and says not to run `sync` to make it pass. There is still no `ring-master.yml`, so the standard's premise holds. The check fails the same way without this job's change, because the generated files have never been committed.
- What I did: ran the sync in the worktree to confirm the cause (the check then passed), then removed the 4 untracked paths it created (`git clean` scoped to them). The worktree is back to its clean state at 03e8c85.
- Side effect: my first `vendor/bin/ringside sync` ran in the main checkout, because the shell cwd does not carry over between calls. It rewrote only the untracked, generated `docs/agent/standards.index.yml` there. No tracked file changed.
- Gate: `composer --working-dir=<job worktree> test` ran phpstan (no errors, config from the worktree), pint (passed) and pest: 175 passed (645 assertions).
- Files: none in the worktree.
- Deviations: none. The conflict between the finding and `tooling/verification-gate` is returned as a decision.

## Group 3

Rework 1 for D-009. Committed as f6fb253 on `ring-master/2026-10-27-1101-recover-build-group-no-result`. Tasks 3.1 to 3.3 are ticked. As the build prompt said, I did not run the test suite: the gate runs it (3.3).

- Files: `resources/workflows/ring-master-factory.js`, `tests/workflows/factory.test.mjs`.
- Code: a new `ringsideCheck` flag is false when the job root's folder is `Ringside` (case-insensitive, `/` or `\` separators, a trailing separator ignored). Job.php names the package the same way, with `basename($project->root)`, so the job args and their contract do not change. When the flag is false, the variant gate (`gate v<n>`) and the single-package gate (`gate r<n>`) leave out the `composer --working-dir=<tree> exec ringside check` step. Workspace gates already left it out, and that is unchanged. The generated-files finding is dropped (D-009): nothing in the job commits `ringside sync` output.
- Tests: two new simulations in `tests/workflows/factory.test.mjs`:
  - "the gate of the Ringside package never runs ringside check; other packages still do". With the roots `/work/Ringside`, `C:\work\Ringside` and `/work/ringside/`, the job is approved and `gate r1` runs only verify and format_check, with no `ringside check`. With `/work/pkg`, `/work/Ringside-app` and `/work/Keystone`, it still runs `composer --working-dir=/work/pkg--import exec ringside check`.
  - "the variant gates of the Ringside package never run ringside check; other packages still do". For a Ringside root, `gate v1`, `gate v2` and `gate r1` have no `ringside check`. For another package, `gate v2` still runs it in `/work/pkg--import--v2`.
  - The existing test "the gate runs ringside check in the job worktree through composer" still covers the non-Ringside default.
- Deviations: none.
