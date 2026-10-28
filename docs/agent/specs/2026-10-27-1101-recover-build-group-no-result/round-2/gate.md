VERDICT: CHANGES

Gate in Ringside--2026-10-27-1101-recover-build-group-no-result:

- pest (via composer exec): 175 passed (645 assertions). PASS
- pint --test: passed. PASS
- ringside check: FAIL. "ringside: 1 problem(s): Generated files are stale or missing. Run: vendor/bin/ringside sync". Blocker: implementer.md changed, so the generated files need a sync, committed on the job branch.

Criteria (tests/workflows/factory.test.mjs unless noted):
1. 'a group that returns no result is salvaged on the reworker role...' and 'a salvage or retry that returns no result escalates...'
2. 'the salvage prompt checks for locks, stays inside the group files, tests through composer and commits as salvaged'; 'a salvage in a parallel group worktree uses that tree and skips the test run the build skipped'
3. 'a replay retry lists only the remaining ids...', 'a bogus, empty or disputed done list...', 'a salvage that leaves nothing to do is not retried', 'a build result that leaves tasks undone retries them without a salvage (D-003)'
4. 'a salvage or retry that returns no result escalates...', 'when every group of a wave returns no result...'
5. 'a salvage that finds a lock or a merge in progress returns blocked...', 'decisions from a salvage or a retry end the run as needs-decision'
6. 'a variant that returns no result is salvaged in its own worktree...', 'a variant that returns a normal result is kept as it is...'
7. Dropped (D-005); 'a rework that returns no result is still ignored, with no salvage'
8. tests/Unit/ConfigTest.php 'tells the implementer to keep turns for committing and returning its result'
9. tests/Unit/ConfigTest.php 'gives the implementer and reworker 80 turns by default', 'falls back to the default implementer max_turns...', 'shows the default max_turns in the README config example'; SyncTest asserts maxTurns: 80
10. The simulations above in factory.test.mjs
11. tests/Unit/ConfigTest.php (tests listed under 8 and 9)
12. CHANGELOG.md and README.md are in the diff; not asserted by a test, not read in this gate
13. pest passes (175 passed)

Changed files: CHANGELOG.md, README.md, resources/agents/implementer.md, resources/workflows/ring-master-factory.js, src/Config.php, src/Render/Template.php, tests/Feature/SyncTest.php, tests/Unit/ConfigTest.php, tests/workflows/factory.test.mjs
