---
description: Run the gate with composer --working-dir on the job worktree, leave ringside check out for Ringside, and wait for green PR checks before reporting approved.
tags: [tooling, gate, ci, factory]
kind: tooling
status: proposed
from_spec: 2026-10-25-1349-worktree-local-manifest-install
evidence:
  - docs/agent/specs/2026-10-25-1349-worktree-local-manifest-install/build.md (rework round 1, Group 6)
  - docs/agent/specs/2026-10-25-1349-worktree-local-manifest-install/round-1/gate.md
  - docs/agent/specs/2026-10-25-1349-worktree-local-manifest-install/round-2/gate.md
  - docs/agent/specs/2026-10-25-1349-worktree-local-manifest-install/decisions.md (D-013)
---

# Verification Gate

The gate runs this package's CI steps against the job worktree, and a job is done only when the PR's CI checks are green.

## Running the gate

- From the main checkout, run `composer --working-dir=<worktree> test` (phpstan, pint --test, pest). The cwd does not carry over between calls, and absolute-path `vendor/bin/pest` runs may be refused. A bare `vendor/bin/pest` silently tests the main checkout.
- Confirm that the worktree is what got tested. phpstan prints the config path from the worktree, and the job's new tests appear in the pest output.
- The gate's scripts match the CI steps: `composer analyse`, `composer lint:check`, `composer test:unit`.

## Not in the gate

- `ringside check` does not apply to Ringside. This repo does not use its own workflow (no `ring-master.yml`, standards index or `.claude/`), so the check fails on `main` too (D-013). Do not run `sync` here to make it pass. If Ringside later adopts its own workflow, drop this section.

## After the push

- The local gate covers one OS and one Node version. CI (`.github/workflows/tests.yml`) covers ubuntu-latest and windows-latest with prefer-lowest and prefer-stable.
- Wait for the PR checks and rework on red before you report the job as approved.

## Example

```bash
composer --working-dir=/Users/jay/Projects/packages/Ringside--<spec> test
gh pr checks <pr> --repo Refactor-Circus/Ringside --watch
```
