VERDICT: PASS

Gate (worktree Ringside--2026-10-25-1349-worktree-local-manifest-install):
- pest: 142 passed (473 assertions), includes workflow node tests.
- pint --test: passed.
- phpstan: no errors.
- ringside check: fails "Generated files are stale or missing" - pre-existing per D-013, not a finding.

Criteria mapping:
1. WorktreeTest prepare (new branch from base / existing branch); WorktreesCommandTest "creates the job worktree and prints its path"
2. WorktreeTest "reuses an existing worktree", "refuses ... another branch" (x2); WorktreesCommandTest "reuses the worktree on a rerun", "fails with the message when the worktree is on..."
3. WorktreeTest "copies the local manifest and lock, then installs from..."; WorktreesCommandTest "sets the worktree up with --setup"
4. WorktreeTest "leaves the worktree's own manifest and lock untouched..."
5. WorktreeTest "installs plainly without a local manifest, ignoring the..."
6. WorktreeTest "runs an explicit setup command as is, without copying manifests"
7. WorktreeTest "never runs the package's local:install" (2 datasets)
8. WorktreeTest "returns the failing exit code with the install's stderr"; WorktreesCommandTest "fails with the command and its output...", "prints the failure as an error"
9. WorktreeTest "surfaces git's refusal when a plain folder..."; WorktreesCommandTest "fails with git's output when a plain folder..."
10. WorktreesCommandTest "refuses a worktree mode other than per-job", "refuses --remove"
11. ConfigTest default/absent/null/empty/explicit and non-string datasets; WorktreesCommandTest "fails naming the key when build.worktree.setup..."
12. ShellTest env inherit/merge/no-leak/stderr off and on/64 KB drain
13. tests/workflows/factory.test.mjs single-package prepare prompt case (run via WorkflowScriptsTest)
14. WorktreesCommandTest "a workspace -> still builds the job folder and sets each package..."; WorkspaceTest

Changed files: CHANGELOG.md, README.md, resources/workflows/ring-master-factory.js, src/Config.php, src/Console/WorktreesCommand.php, src/Shell.php, src/Worktree.php, tests/Feature/WorkspaceTest.php, tests/Feature/WorktreeTest.php, tests/Feature/WorktreesCommandTest.php, tests/Pest.php, tests/Unit/ConfigTest.php, tests/Unit/ShellTest.php, tests/workflows/factory.test.mjs
