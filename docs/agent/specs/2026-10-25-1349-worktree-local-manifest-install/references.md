# References

| Location | Relevance | Pattern to borrow |
| --- | --- | --- |
| `src/Console/WorktreesCommand.php` | Existing `worktrees --setup` for workspace jobs | Option handling, per-package setup loop, `<error>` + output on failure, exit codes |
| `src/Workspace/Workspace.php:123-167` (`prepare`) | Creates or reuses worktrees on a branch | `git worktree add [-b] <target> <branch|base>` with reuse when the target exists |
| `src/Workspace/Workspace.php:54-79` (`describe`) | Chooses a setup command from the package's composer scripts | Detection keyed on what the package root has |
| `src/Shell.php` | Argument-list process runner | Extend with an env (proc_open's `$env_vars`) instead of shell strings |
| `src/Config.php:101-110` | `build.worktree` defaults | Where a new default or mode value lands |
| `src/Specs/Job.php:64` | `job.worktree` path | Source of the target folder for a single-package job |
| `resources/workflows/ring-master-factory.js:147-159` | Prepare phase prompt | Workspace branch already delegates to one `vendor/bin/ringside` call; per-job branch should too |
| `tests/Feature/WorkspaceTest.php`, `tests/Feature/JobTest.php`, `tests/workflows/factory.test.mjs` | Existing worktree/job tests | Temp git repos as fixtures; workflow prompt assertions |
| `../Atrium/composer.local.json`, `../Atrium/composer.local.lock` | Real local manifest | Path repos `../Ringside`, `../Standards`, `../Keystone`; `*@dev` require-dev |
