# Tasks: Gate reports commands it could not run and escalates once

## Group 1: cwd-proof command helper module
depends on: none
files: resources/workflows/gate-command.js, tests/workflows/factory.test.mjs
criteria: 2

- [x] 1.1 Write tests in `tests/workflows/factory.test.mjs` importing the helper directly: composer script, `exec` binary (`vendor/bin/x`), pass-through (`node --test`), already-rewritten input (idempotent), tree path with spaces and quotes (POSIX single-quote, `'` becomes `'\''`), compound command (`&&`, `;`, `|` segments each rewritten), env-prefixed command unchanged, command with existing `-d` / `--working-dir` unchanged
- [x] 1.2 Implement the helper in `resources/workflows/gate-command.js` (D-007, D-008, D-010, D-011) and export it
- [ ] 1.3 Run vendor/bin/pest and `composer test`; all green

## Group 2: `not_run` channel and gate escalation in the factory workflow
depends on: 1
files: resources/workflows/ring-master-factory.js, tests/workflows/factory.test.mjs
criteria: 1, 2, 3, 5, 6, 7, 9, 10

- [x] 2.1 Write tests: AC1 cases (no `not_run` field, null gate, `not_run: null`, malformed entry with no `undefined` in `reasons`); AC3 prompt-string assertions for the variant, single-package and workspace per-package gate prompts (`composer --working-dir=`, `not_run`, `did not start`, `exact refusal text`, no `vendor/bin/` with a `vendor/bin/pest` verify fixture, `common` text free of `vendor/bin/`); simulations 9(a) to 9(j) including AC6 `gate_red_rounds` checks and AC7 (red gate still reworks, `not_run: []` does not escalate)
- [x] 2.2 Add `not_run` to the `GATE` schema; coerce entries on read (`String(n.command ?? '')`, reason truncated to 500 chars)
- [x] 2.3 Build `gateVariant` and `runGate` commands through the helper; update every gate prompt (did-not-start rule, exact refusal text, no `vendor/bin/` by path)
- [x] 2.4 Merge `not_run` across workspace packages with the package prefix; escalate `finish({status: 'escalated', stage: 'gate', reasons})` before the `gate.passed` branch without touching `red`/`redRounds`, rework or commit
- [x] 2.5 Variants: disqualify a `not_run` variant (prefix `variant <n>:`), keep a separate list, escalate at stage `gate` when none is eligible (pending asks carried, D-009), never reach the `gated.slice().sort(...)[0].n` fallback or judge-skip on an empty list
- [ ] 2.6 Run vendor/bin/pest and `composer test`; all green

## Group 3: documentation and changelog
depends on: 2
files: resources/skills/workflow/factory.md, CHANGELOG.md, tests/workflows/factory.test.mjs
criteria: 8

- [x] 3.1 Write a test in `tests/workflows/factory.test.mjs` that reads `resources/skills/workflow/factory.md` and checks it contains `not_run` and `gate could not run:`
- [x] 3.2 Document in `factory.md`: the `not_run` channel, the `gate could not run:` reason prefix, the escalation, the state an escalated job is left in, and the Composer requirement of the rewrite
- [x] 3.3 Add a `CHANGELOG.md` entry for the `not_run` channel and for the rewrite and its Composer requirement
- [ ] 3.4 Run vendor/bin/pest and `composer test`; all green

## Group 4: Rework 1: fix the -d nit
depends on: 1, 2
files: resources/workflows/gate-command.js, tests/workflows/factory.test.mjs
criteria: 2

- [x] 4.1 Fix the `-d` nit (user note from RingMaster: "fix the -d nit"): the rewrite's handling of an existing `-d` / `--working-dir` in a command (task 1.1: command with existing `-d` / `--working-dir` unchanged); add or adjust a test, then run vendor/bin/pest and `composer test`; all green
