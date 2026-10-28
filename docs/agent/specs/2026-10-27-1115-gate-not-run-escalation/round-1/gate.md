VERDICT: PASS

Gate run in /Users/jay/Projects/packages/Ringside--2026-10-27-1115-gate-not-run-escalation:
- pest: 170 passed (631 assertions), including WorkflowScriptsTest (node --test).
- pint --test: passed.

Acceptance criteria (tests in tests/workflows/factory.test.mjs):
1. AC1: 9j tests "a gate without not_run, with not_run: null, or a null gate behaves as before", "a malformed not_run entry is coerced..."; GATE schema in resources/workflows/ring-master-factory.js.
2. AC2: cwdProof tests (composer script, vendor/bin exec --, pass-through, idempotent, quoting, compound, env prefix, existing working dir, -d); helper in resources/workflows/gate-command.js.
3. AC3: prompt tests for single-package, workspace per-package and variant gates.
4. AC4: removed (T-005).
5. AC5: "a gate that could not run a command escalates at stage gate in round 1", "workspace not_run reasons keep package order...".
6. AC6: same tests plus "not_run in round 2 after a red round..." (gate_red_rounds).
7. AC7: "a gate that ran everything and failed still counts a red round", "an empty not_run never escalates".
8. AC8: "the factory skill documents the not_run channel..."; resources/skills/workflow/factory.md and CHANGELOG.md changed.
9. AC9: tests (a) to (j) all present, see 9a to 9j named tests.
10. AC10: 9e, 9f, 9h, 9i tests.

Changed files: CHANGELOG.md, resources/skills/workflow/factory.md, resources/workflows/gate-command.js, resources/workflows/ring-master-factory.js, src/Specs/Job.php, tests/Feature/JobTest.php, tests/workflows/factory.test.mjs, tests/workflows/harness.mjs
