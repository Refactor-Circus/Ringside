VERDICT: PASS

Gate (in the job worktree):
- vendor/bin/pest: 170 passed (625 assertions)
- vendor/bin/pint --test: passed
- composer exec ringside check: passed
- node --test tests/workflows: 57 passed, 0 failed

Criteria -> proof (tests/workflows/factory.test.mjs unless noted)
1, 2. "no prompt runs vendor/bin/ringside; every Prepare, variant, group-worktree and removal call goes through composer --working-dir, never after a cd"
3. "the PREPARED schema accepts dirty, remote and check_applies, and a clean preflight builds"
4. "the Prepare prompt runs the preflight as separate Bash calls ahead of worktrees --setup, and holds back --setup on dirt or no remote"; "a replay has no preflight and is never stopped by one"; "dirt that matches no listed file, only the job's own spec folder, or a group with no files lets the build go ahead"; "a non-replay Prepare result that omits a preflight field is a failed preflight"; "a workspace job runs the preflight in each package folder and aggregates the results"
5. "dirt on a file a task group lists stops at prepare with the dirty ASK and the files"; "an untracked directory that holds a listed file stops at prepare (directory prefix)"; "spec-folder files outside the job's own folder stop at prepare; ..."
6. "no origin remote stops at prepare with its ASK when the job opens a PR"; "a job that never opens a PR ignores a missing remote"; "dirty main and no remote raise only the dirty ASK"
7. "check_applies false drops ringside check from both gate prompts and logs it"
8. "a clean preflight keeps ringside check in both gate prompts"
9. resources/skills/workflow/factory.md (preflight section) and tests/Feature/WorkflowScriptsTest.php (asserts `dirty`, `remote`, `check_applies`, needs-decision, drop `ringside check`)
10. The simulations above, incl. "a Prepare that fails its setup with a clean preflight still escalates" and the replay and workspace cases (legacy-shape case superseded by D-004)
11. vendor/bin/pest passes

Changed files:
- resources/skills/workflow/factory.md
- resources/workflows/ring-master-factory.js
- src/Worktree.php
- tests/Feature/ImpactTest.php
- tests/Feature/WorkflowScriptsTest.php
- tests/workflows/factory.test.mjs

Note: src/Worktree.php and tests/Feature/ImpactTest.php changed although the spec says no PHP changes are intended; the gate is green, flagged for the reviewer.
