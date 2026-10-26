---
description: Run a command with withStderr when its output goes into an error message; leave it off when stdout is parsed as a value.
tags: [shell, errors, git, composer]
kind: convention
status: proposed
from_spec: 2026-10-25-1349-worktree-local-manifest-install
evidence:
  - docs/agent/specs/2026-10-25-1349-worktree-local-manifest-install/decisions.md (D-005, D-012)
  - docs/agent/specs/2026-10-25-1349-worktree-local-manifest-install/build.md (Group 3)
  - src/Worktree.php
  - src/Workspace/Workspace.php:151
  - src/Workspace/Workspace.php:186
  - src/Console/CleanupCommand.php:45
  - src/Discovery/Proposer.php:73
  - src/Discovery/Proposer.php:98
  - src/Scaffold/PackageScaffolder.php:113
---

# Command Error Output

When a command's output goes into an error message, run it with `withStderr: true`. When you parse a command's stdout as a value, leave the flag off.

## Rules

- git, composer and the installers write refusals and failures to stderr. Without the flag, the exception or printed line has nothing after the colon.
- Turn the flag on for every call that throws or prints `$out` on a non-zero exit: `git worktree add` and `remove`, `git commit`, `git checkout -b`, `composer install`, configured setup commands and scaffold steps.
- Leave it off where stdout is the result: `git branch --show-current`, `git rev-parse`, `git config user.name`, `git remote get-url`. Appending stderr would corrupt the value.
- If a spec limits the flag to certain callers, check that limit against every criterion that asks for "the command's output" (D-012).

## Example

```php
[$code, $out] = Shell::run($command, $project->root, withStderr: true);

if ($code !== 0) {
    throw new RuntimeException("Could not create the worktree at {$path} on {$branch}: {$out}");
}

[, $current] = Shell::run(['git', 'branch', '--show-current'], $worktree); // a value: stdout only
```
