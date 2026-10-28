# Build: Recover a build group that returns no result

## Group 2

Stopped before any code change; nothing committed on `ring-master/2026-10-27-1101-recover-build-group-no-result--g2`, no tasks ticked.

- Files: none changed.
- Tests: none written or run.
- Why: the job branch was cut from `main` at 88d5f36, which has no replay mode, no `buildVariant`, no `finish()`, no integrate step and no group worktrees (none in `src/`, `resources/` or `tests/`). They exist only as uncommitted edits in the main checkout (24 files). Criteria 3 (replay retry, "continues to the integrate/gate steps") and 6 (variant path) target that code, and the spec's precondition ("the build must start from a clean main") was not met. Also, the workflow script cannot read files, so the `tasks.md` cross-check in criterion 3 needs a design choice. Both are returned as decisions.
- Deviations: none.

## Group 1

Committed as f94609b on `ring-master/2026-10-27-1101-recover-build-group-no-result--g1`. Tasks 1.1 to 1.4 ticked; 1.5 (full `vendor/bin/pest`) left to the gate, as the build prompt said not to run the suite.

- Files: `src/Config.php` (implementer and reworker `max_turns` 40 to 80), `src/Render/Template.php` (fallback `?? 80`), `resources/agents/implementer.md` (step 4 turn-budget rule), `README.md` (example shows 80, plus a paragraph saying a `max_turns` already set in `ring-master.yml` is unchanged), `CHANGELOG.md` (0.8.0 note), `tests/Unit/ConfigTest.php`, and `tests/Feature/SyncTest.php` (outside the group's file list: it pinned `maxTurns: 40` in the rendered implementer agent, now `maxTurns: 80`).
- Tests: five new cases in `tests/Unit/ConfigTest.php`: the 80 defaults for both roles; an override in `ring-master.yml` is kept; the `Template::vars` fallback (role with `max_turns: null`) equals `Config::defaults()`; the README example's implementer and reworker `max_turns` equal the defaults and 80, and the README carries the "stays as it is" sentence; `implementer.md` step 4 contains the key phrases of criterion 8. Ran only `ConfigTest` and `SyncTest` (35 passed) and `pint --test` on the changed PHP files (passed).
- Deviations: none from standards. One file outside the list (`tests/Feature/SyncTest.php`), needed to keep the suite green.
