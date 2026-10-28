VERDICT: PASS

# Gate, round 2

Worktree: /Users/jay/Projects/packages/Ringside--2026-10-27-1059-prepare-cwd-proof-preflight

- `pest` (composer --working-dir exec pest): 171 passed, 636 assertions, including WorkflowScriptsTest (node simulations, preflight wording).
- `pint --test`: passed.
- `composer --working-dir=... exec ringside check`: check passed.

Note: a bare `vendor/bin/pest` call ran in the session cwd (Cortex) because the agent shell resets cwd; the gate was re-run against the worktree via `composer --working-dir`.

## Changed files
- resources/skills/workflow/factory.md
- resources/workflows/ring-master-factory.js
- tests/Feature/WorkflowScriptsTest.php
- tests/workflows/factory.test.mjs

## Criteria (tests in tests/workflows/factory.test.mjs unless noted)
1. "no prompt runs vendor/bin/ringside; every Prepare, variant, group-worktree and removal call goes through composer --working-dir, never after a cd"; "the gate runs ringside check in the job worktree through composer, never by path".
2. Same test as 1 (never-cd instruction).
3. "the PREPARED schema accepts dirty, remote and check_applies, and a clean preflight builds".
4. "the Prepare prompt runs the preflight as separate Bash calls ahead of worktrees --setup, and holds back --setup on dirt or no remote"; "a replay has no preflight and is never stopped by one"; dirty-overlap tests (D-006): "dirt on a file a task group lists...", "an untracked directory that holds a listed file...", "spec-folder files outside the job's own folder...", "dirt that matches no listed file...".
5. "dirt on a file a task group lists stops at prepare with the dirty ASK and the files"; "an untracked directory that holds a listed file stops at prepare (directory prefix)"; Prepare prompt test from 4.
6. "no origin remote stops at prepare with its ASK when the job opens a PR"; "a job that never opens a PR ignores a missing remote"; "dirty main and no remote raise only the dirty ASK".
7. "check_applies false drops ringside check from both gate prompts and logs it".
8. "a clean preflight keeps ringside check in both gate prompts".
9. tests/Feature/WorkflowScriptsTest.php "it describes the Prepare preflight in the factory skill".
10. All of the above plus "a non-replay Prepare result that omits a preflight field is a failed preflight" (D-004) and "a workspace job runs the preflight in each package folder and aggregates the results" (D-003).
11. pest green (171 passed).
