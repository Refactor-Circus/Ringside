VERDICT: PASS

Worktree: /Users/jay/Projects/packages/Ringside--2026-10-27-1101-recover-build-group-no-result

## Gate
- `pest` (via composer exec in the worktree): 175 passed, 645 assertions.
- `pint --test`: passed.
- `ringside check`: not run for Ringside (D-009, tooling/verification-gate).

## Criteria
1. tests/workflows/factory.test.mjs: "a group that returns no result is salvaged on the reworker role, the rest retried, and the run reaches the gate".
2. Same file: "the salvage prompt checks for locks, stays inside the group files, tests through composer and commits as salvaged" and "a salvage in a parallel group worktree uses that tree and skips the test run the build skipped" (D-004).
3. "a replay retry lists only the remaining ids...", "a bogus, empty or disputed done list retries every task not provably done", "a salvage that leaves nothing to do is not retried", "a build result that leaves tasks undone retries them without a salvage (D-003)".
4. "a salvage or retry that returns no result escalates, with the null still in built" and "when every group of a wave returns no result, the job escalates straight away without a salvage".
5. "decisions from a salvage or a retry end the run as needs-decision" and "a salvage that finds a lock or a merge in progress returns blocked...".
6. "a variant that returns no result is salvaged in its own worktree and retried before it counts as failed" and "a variant that returns a normal result is kept as it is...".
7. Dropped; "a rework that returns no result is still ignored, with no salvage".
8. tests/Unit/ConfigTest.php: "it tells the implementer to keep turns for committing and returning its result".
9. ConfigTest: "it gives the implementer and reworker 80 turns by default", "it falls back to the default implementer max_turns when the role se...", "it shows the default max_turns in the README config example".
10. The factory.test.mjs tests listed above.
11. ConfigTest tests listed in 8 and 9.
12. CHANGELOG.md and README.md in the diff.
13. pest passes.

## Changed files
CHANGELOG.md, README.md, resources/agents/implementer.md, resources/workflows/ring-master-factory.js, src/Config.php, src/Render/Template.php, tests/Feature/SyncTest.php, tests/Unit/ConfigTest.php, tests/workflows/factory.test.mjs
