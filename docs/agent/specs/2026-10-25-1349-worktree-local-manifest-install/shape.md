# Install job worktree dependencies from composer.local.json — Shaping Notes

## Scope

Change to Ringside's factory setup (ticket T-001). A per-job worktree (`build.worktree.mode: per-job`, path `../{package}--{spec}`) must end up with the same dependencies as the main checkout, in one allowed command, so `vendor/bin/ringside check` and the rest of the gate run inside it.

- Package root has `composer.local.json`: copy it (and `composer.local.lock` when present) into the worktree, then install with `COMPOSER=composer.local.json`. Path repositories (`../Ringside`, `../Standards`, `../Keystone`) resolve the same from a sibling worktree, so the manifest is copied as is.
- No `composer.local.json`: `composer install --no-interaction`, as today.
- Ringside runs the copy and install itself (proc_open with an env, not an agent-run `cp` or `COMPOSER=… composer` prefix, both refused by RingMaster's runner).
- The factory's single-package prepare step calls that command instead of `config.build.worktree.setup`; workspace jobs keep `ringside worktrees <spec> --setup` with their `local:install` detection unchanged.
- Never runs a package's `composer local:install` (Atrium's regenerates `composer.local.json` and drops the ringside/standards entries).
- Tests: manifest and lock copied, install runs with `COMPOSER=composer.local.json`; plain `composer install` fallback; a configured custom `setup` still honoured (D-002).
- PR notes whether `runner.allowed_tools` needs a new entry (`Bash(composer *)`, `Bash(vendor/bin/*)` already allowed; a `vendor/bin/ringside …` call is covered by the latter).

Out of scope: Atrium's `bin/composer-local.php`; reinstalling the existing Atrium worktree (done when that job resumes).

## Decisions

- D-001: ticket created as drafted (T-001).
- D-002: `build.worktree.setup` gets a new default `auto` (composer.local.json when present, else `composer install --no-interaction`); an explicit command string still runs as is.
- D-003: standards are architecture/configuration and testing/test-layers.
- D-004: `ringside worktrees <spec> --setup` extends to single packages: it creates or reuses the per-job worktree (`build.worktree.path`), then installs per D-002. The factory's per-job prepare step calls it instead of running `git worktree add` and the setup command itself.

## Context

- `src/Console/WorktreesCommand.php`: `worktrees <spec> [--setup|--remove]`, workspace-only today (fails with "No workspace.packages…" for a single package). Runs setup via `Shell::run(preg_split(...))`, so no env vars or shell syntax possible.
- `src/Shell.php`: `run(array $command, string $cwd)`; no env parameter yet — needs one (or a sibling method) to pass `COMPOSER`.
- `src/Config.php:104-109`: `build.worktree` defaults `mode: per-job`, `path: ../{package}--{spec}`, `setup: composer install --no-interaction`.
- `src/Workspace/Workspace.php:69`: workspace packages pick `composer local:install --no-interaction` when the script exists — the behaviour this ticket must not reuse for per-job worktrees.
- `src/Specs/Job.php:64`: computes `job.worktree` from `build.worktree.path`.
- `resources/workflows/ring-master-factory.js:150-158`: prepare agent; per-job branch tells it to create the worktree and run `${config.build.worktree.setup}` itself.
- `../Atrium/composer.local.json` + `composer.local.lock`: real case; `refactor-circus/ringside` and `standards` only as `*@dev` path repos there.
- Product (`../docs/agent/product/ring-master.md`): runner allows only listed commands and refuses compound ones (phase 4 findings) — the reason this must be one Ringside command. Ringside stays plain PHP and usable without the app.

## Standards

Confirmed (D-003):

- `architecture/configuration` — the `build.worktree.setup` default/value changes; hard default on read, fail loud on bad value.
- `testing/test-layers` — command wiring tested at the console layer, copy/install behaviour once where it lives.

Most other workspace standards (actions, models, http, domain modules) target Laravel packages and don't apply to Ringside's plain-PHP CLI.
