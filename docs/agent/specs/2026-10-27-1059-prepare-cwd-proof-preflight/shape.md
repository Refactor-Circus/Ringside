# Make Prepare cwd-proof and add a preflight — Shaping Notes

## Scope

Ticket T-002. The factory's Prepare agent runs `vendor/bin/ringside ...` "From job.root", which depends on the session's cwd and has escalated at prepare repeatedly. A dirty main checkout, a missing remote and an inapplicable `ringside check` only surface after the build.

In scope:

- Every `ringside` call in `resources/workflows/ring-master-factory.js` (Prepare, variant and group worktrees, `--remove-groups`, and any other) runs as `composer --working-dir=<job.root> exec ringside -- ...`, never as `vendor/bin/ringside` by path.
- Prompts tell agents never to `cd` into the worktree.
- Prepare runs a preflight and the PREPARED schema grows `dirty`, `remote` and `check_applies`.
- The script stops `needs-decision` at prepare when main is dirty, or when there is no remote and a PR is to be opened. It drops `ringside check` from the gate when `check_applies` is false.
- `resources/skills/workflow/factory.md` and the PREPARED schema mirror this.
- Workflow simulations (`tests/workflows/factory.test.mjs`) and `vendor/bin/pest` pass.

Out of scope: merging, other packages, changing RingMaster.

## Decisions

- D-001: ticket created as drafted (T-002).
- D-002: standards confirmed in RingMaster (below).

## Context

- Precedent for the pattern: line 327 and 418 of the factory script already use `composer --working-dir=... exec ringside`; Prepare, variants (216) and groups (287, 308) still say `vendor/bin/ringside` "From ${job.root}".
- Follow-up 3 of the 2026-10-25-1349-worktree-local-manifest-install post-mortem: D-010/D-011 (dirty main), D-013 (`ringside check` not applicable to the Ringside repo), D-014/Q-012 (no remote) were all found after the build.
- Uncommitted work on main in this repo touches `ring-master-factory.js`, `factory.md` and `tests/workflows/factory.test.mjs`; the build must start from a clean main.

## Standards

Confirmed by the user in RingMaster (D-002); `architecture/configuration` left out, no new config key:

- `testing/test-layers`: simulation tests for the script, feature tests for any PHP.
- `tooling/node-test`: the workflow simulations.
- `tooling/verification-gate`: the gate drops `ringside check` when it does not apply.
- `shell/error-output`: preflight output and failures surface to the agent.
