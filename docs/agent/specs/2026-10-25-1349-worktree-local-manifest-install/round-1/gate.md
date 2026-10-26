VERDICT: PASS

Gate (run in the job worktree via `composer --working-dir=... test`): phpstan OK, pint passed, pest 142 passed (474 assertions), including WorkflowScriptsTest (node workflow tests).
`vendor/bin/ringside check`: not run, per D-013 (pre-existing failure, not part of this job's gate).

## Criteria mapping
1. WorktreesCommandTest "a single package: creates the job worktree and prints its path"; WorktreeTest prepare new-branch and existing-branch cases.
2. WorktreeTest "reuses an existing worktree on the job branch", "refuses an existing worktree on another branch..." (two cases); WorktreesCommandTest "reuses on a rerun", "fails with the message when the worktree is on...".
3. WorktreeTest "setup: copies the local manifest and lock, then installs from t..."; "copies no lock when the package has none".
4. WorktreeTest "leaves the worktree's own manifest and lock untouched...".
5. WorktreeTest "installs plainly without a local manifest, ignoring the...".
6. WorktreeTest "runs an explicit setup command as is, without copying manifests".
7. WorktreeTest "never runs the package's local:install" (two datasets).
8. WorktreeTest "returns the failing exit code with the install's stderr"; WorktreesCommandTest "fails with the command and its output when setup...", "prints the failure as an error".
9. WorktreeTest "surfaces git's refusal when a plain folder holds the path"; WorktreesCommandTest "fails with git's output when a plain folder...".
10. WorktreesCommandTest "refuses a worktree mode other than per-job", "refuses --remove".
11. ConfigTest default, absent/null/empty datasets, explicit command, non-string datasets (list, map, number, boolean); WorktreesCommandTest "fails naming the key when build.worktree.setup...".
12. ShellTest: inherits env, merges env, no leak, empty COMPOSER override, stdout only, stderr appended, 64 KB stderr drain (two cases).
13. tests/workflows/factory.test.mjs single-package prepare prompt case (passes within WorkflowScriptsTest); prompt in resources/workflows/ring-master-factory.js.
14. WorktreesCommandTest "a workspace: still builds the job folder and sets each package..."; WorkspaceTest (4 cases).

## Changed files
CHANGELOG.md, README.md, resources/workflows/ring-master-factory.js, src/Config.php, src/Console/WorktreesCommand.php, src/Shell.php, src/Worktree.php, tests/Feature/HookScriptTest.php, tests/Feature/NewPackageTest.php, tests/Feature/WorkflowScriptsTest.php, tests/Feature/WorkspaceTest.php, tests/Feature/WorktreeTest.php, tests/Feature/WorktreesCommandTest.php, tests/Pest.php, tests/Unit/ConfigTest.php, tests/Unit/ShellTest.php, tests/workflows/factory.test.mjs

## Note
Individual criterion mapping is by test names from the pest output; direct `vendor/bin/pest` and `vendor/bin/pint --test` could not run with the worktree as cwd (cd does not persist, absolute-path invocation was refused), so `composer test` (phpstan + pint + pest) was run instead.
