# Gate reports commands it could not run and escalates once — Shaping Notes

## Scope

Ticket T-004. When a gate command is refused by the runner or runs in the wrong checkout, the gate agent reports it as a blocker finding. The loop counts a red round and sends empty rework (Atrium 2026-10-25-0120: 3 red rounds, 3 empty commits).

In scope:

- The `GATE` schema (`resources/workflows/ring-master-factory.js:73`) gets a `not_run` channel for commands the gate could not run.
- A helper rewrites gate commands cwd-proof (`composer --working-dir=<tree> [exec] ...`), used by `gateVariant` (line 233) and `runGate` (line 327).
- The spec's done-definition commands run every round.
- A non-empty `not_run` escalates at once: stage `gate`, no red round counted, no rework, no commit.
- `resources/skills/workflow/factory.md` documents the fix.
- Workflow simulations in `tests/workflows/factory.test.mjs` cover it.

Out of scope: the `Config` verify default, runner allowlist changes, merging.

## Decisions

- D-001: ticket created as drafted (T-004).
- D-002: standards list confirmed in RingMaster (see Standards below).

## Context

- Today a refused command becomes a `blocker` finding, which feeds `classify` (line 127), `red` rounds (line 386) and a rework agent with nothing to fix.
- Overlaps with spec 2026-10-27-1059-prepare-cwd-proof-preflight (T-002), which makes `ringside` calls cwd-proof in Prepare and groups; this spec covers the gate commands and the `not_run` escalation. Both edit `ring-master-factory.js`, `factory.md` and `factory.test.mjs`: build them one after the other.
- Several files in this repo's working tree are already modified; the build must start from a clean main.
- Evidence: Atrium 2026-10-25-0120 (wf_0088f0e6-06a), Roster 2026-10-23-2015 and Ringside 2026-10-25-1349 post-mortems.

## Standards

Confirmed by D-002 (keys not verified against `standards.index.yml`, which is not in `docs/agent`):

- `testing/test-layers` and `tooling/node-test`: workflow simulations for the new path.
- `tooling/verification-gate`: what the gate runs and how it reports.
- `shell/error-output`: a refused command surfaces as `not_run`, not as a finding.
