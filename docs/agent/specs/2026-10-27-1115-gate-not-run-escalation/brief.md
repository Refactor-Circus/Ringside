Risk: medium

## What changed
- Gate (`resources/workflows/ring-master-factory.js`): the `GATE` schema gains an optional `not_run` array of `{command, reason}`. Entries are coerced when read, so older, null or malformed gates behave as before. A non-empty `not_run` (single package, any workspace package, or all variants) returns `finish({status: 'escalated', stage: 'gate', reasons})` with `gate could not run: <command>: <reason>` reasons. It does not count a red round, start a rework agent or commit. A variant that reports `not_run` is disqualified alone, and the job escalates only if none is left (pending asks are carried).
- New module `resources/workflows/gate-command.js` exports `cwdProof(tree, command)`. It rewrites `vendor/bin/x` to `composer --working-dir='<tree>' exec -- x` and injects `--working-dir` into `composer ...` commands. It is idempotent, quotes the tree path, and handles compound commands, env prefixes and existing `-d`. Variant and single-package gates build their commands through it. The workspace per-package gate prompt shares the single-package builder (`runGate`) and the same `notRunRule` text.
- `src/Specs/Job.php` adds `workflows_dir` to the `ringside job --json` args. The factory loads the helper with a dynamic import from there (D-013).
- Docs: `factory.md` and `CHANGELOG.md` describe the channel, the reason prefix, the state of an escalated job and the Composer requirement.
- Tests: `factory.test.mjs` gains the helper unit tests, prompt-string tests and simulations (a) to (j). `harness.mjs` and `JobTest.php` are adjusted for `workflows_dir`.

## Why
The gate used to count a red round when the runner refused a command or it ran in the wrong checkout. The rework agent then had nothing to fix. Now the gate reports those commands separately and the factory escalates once (T-004). Decisions that shaped it:
- D-003: a `not_run` variant is disqualified alone.
- D-004: `not_run` takes precedence over real findings.
- D-005: running the done-definition commands every round moved to T-005.
- D-006 to D-008, D-011: the rewrite and pass-through rules.
- D-009: all variants `not_run` plus pending asks escalates at stage `gate`.
- D-010, D-013: a separate helper module, loaded by dynamic import.
- D-016: `exec --` form.

## Look at
- `resources/workflows/gate-command.js:26`: `cwdProof`, the segment splitting, quoting and idempotence rules. A mistake here changes every gate command.
- `resources/workflows/ring-master-factory.js:24`: the `workflows_dir` requirement and the dynamic import. It throws at start when the arg is missing (D-017).
- `resources/workflows/ring-master-factory.js:263`: variant disqualification and the all-variants-`not_run` path, which must not reach the `gated` sort or judge-skip fallbacks.
- `resources/workflows/ring-master-factory.js:368`: the escalation check, placed after the workspace merge and before the `gate.passed`/red branch, and the reason format.
- `resources/workflows/ring-master-factory.js:195`: `notRunRule`, the prompt text the gate agent has to obey.

## Tested
- AC1: the `9j` tests (no field, null gate, `not_run: null`, malformed entry).
- AC2: the `cwdProof` unit tests.
- AC3: prompt-string tests for the variant, single-package and workspace gates, including the `vendor/bin/pest` fixture.
- AC5 to AC7: the round-1, workspace, round-2, red-gate and empty-`not_run` simulations.
- AC8: the factory skill doc assertion.
- AC9 and AC10: simulations (a) to (j), including variants (h) and (i).
- Gate round 1 passed: pest 170 passed (631 assertions), including `WorkflowScriptsTest` (node --test), and `pint --test` passed.

## Risks
- Changed workflow arg contract: `workflows_dir` is now required. Args from an older Ringside or a resumed job fail at start until rebuilt with `ringside job --json` (D-017, in the CHANGELOG).
- The `vendor/bin` rewrite needs a `composer.json` and Composer in the tree. A consumer without them now gets a `gate` escalation instead of a rework.
- `not_run` is self-reported by the gate agent. A mislabelled real failure stops the job with no retry. In round 2 and later, earlier rework commits are kept. A test cannot prove a real agent follows the prompt.
- Open: whether disqualified variant worktrees and branches are cleaned up is not decided here.
- No finding is left and no architectural path changed (`composer.json`, config, migrations, Contracts, `.github`). No package consumer (Cortex) is broken that I could see. The diff is moderate: 461 insertions across 8 files, mostly tests (nits: 0).
