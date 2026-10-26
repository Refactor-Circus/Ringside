---
description: node --test always gets an explicit list of test files, never a directory (Node 22+ reads a directory as a module).
tags: [tooling, node, testing, ci]
kind: tooling
status: proposed
from_spec: 2026-10-25-1349-worktree-local-manifest-install
evidence:
  - docs/agent/specs/2026-10-25-1349-worktree-local-manifest-install/build.md (Group 5, Group 6, rework round 1)
  - tests/Feature/WorkflowScriptsTest.php
---

# Node Workflow Tests

`node --test` always gets an explicit list of test files, never a directory.

## Rules

- Node 22 and later read a directory argument as a module and fail with `Cannot find module '…/tests/workflows'`. File arguments work on Node 20 and later. CI uses the Node that ships with the runner, not your local version.
- Glob the files (`tests/workflows/*.test.mjs`) and assert that the glob found some, so the test cannot pass by running nothing.
- Skip only when `node` is not installed, and give the reason.
- Gate and build notes that run the workflow tests directly follow the same form: list the files, or run them through `WorkflowScriptsTest`.

## Example

```php
it('passes the workflow simulations (node --test)', function () {
    // Node 22+ reads a directory argument as a module, so name each test file.
    $files = glob(dirname(__DIR__).'/workflows/*.test.mjs') ?: [];
    [$code, $output] = Shell::run(['node', '--test', ...$files], dirname(__DIR__, 2));

    expect($files)->not->toBeEmpty()
        ->and($code)->toBe(0, $output);
})->skip(fn (): bool => Shell::run(['node', '--version'], sys_get_temp_dir())[0] !== 0, 'node is not installed');
```
