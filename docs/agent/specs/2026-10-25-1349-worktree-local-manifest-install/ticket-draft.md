---
title: Install job worktree dependencies from composer.local.json
labels: [factory, worktrees]
---

## Problem

Per-job factory worktrees (`../{package}--{spec}`) run `build.worktree.setup` (default `composer install --no-interaction`), which installs from the committed `composer.json`. Suite packages such as Atrium only get `refactor-circus/ringside` and `refactor-circus/standards` through the gitignored `composer.local.json` (path repositories to `../Ringside` and `../Standards`, `*@dev` require-dev entries). The worktree therefore has no `vendor/bin/ringside`, the gate's `ringside check` step cannot run (Atrium spec 2026-10-25-0120-stop-unserialize-search-registry, D-011), and the runner refuses ad-hoc workarounds (`cp`, env-prefixed commands, the main checkout's binary).

## Desired outcome

Ringside sets up a per-job worktree the way the main checkout is set up, in one allowed command:

- When the package root has `composer.local.json`, copy it (and `composer.local.lock` when present) into the worktree and install with `COMPOSER=composer.local.json`. Path repositories stay `../Sibling`, which resolve the same from a worktree beside the package.
- Otherwise fall back to `composer install --no-interaction`.
- The factory workflow (`resources/workflows/ring-master-factory.js`) uses that setup for per-job worktrees; workspace jobs keep their existing `local:install` behaviour.

## Acceptance hints

- A Ringside command (e.g. `vendor/bin/ringside worktrees --setup <path>`) or Config default does the copy and install; no agent-run `cp` or env prefixes.
- Does not rely on a package's `composer local:install` (Atrium's regenerates composer.local.json and drops the ringside/standards entries).
- Tests: worktree gets the local manifest (and lock); falls back to plain `composer install` when there is none.
- PR says whether RingMaster's `runner.allowed_tools` needs a new entry (`Bash(composer *)` and `Bash(vendor/bin/*)` are already allowed).
- `vendor/bin/pest` passes.

## Out of scope

- Changing Atrium's `bin/composer-local.php`.
- Reinstalling the existing Atrium worktree `../Atrium--2026-10-25-0120-stop-unserialize-search-registry`: done after this ships, with the new setup, as part of resuming that job.
