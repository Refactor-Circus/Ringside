# Gate reports commands it could not run and escalates once

Ticket: [T-004](../../tickets/T-004.md)

## Goal

When the factory gate cannot run a command (the runner refuses it, or it runs in the wrong checkout), it says so in a separate `not_run` channel and the factory escalates once at stage `gate`, instead of counting a red round and sending a rework agent nothing to fix. For whoever runs the factory.

## Acceptance criteria

1. The `GATE` schema in `resources/workflows/ring-master-factory.js` has an optional `not_run` array of `{command, reason}` objects for commands the gate could not run. Everywhere the workflow reads it, it reads `(gate && gate.not_run) || []`, so gate objects without the field (older, mocked or resumed results) never throw.
2. One helper rewrites a gate command so it is cwd-proof (`composer --working-dir=<tree> ...`, with `exec` for a binary); `gateVariant` and `runGate` both build their commands through it, and no gate prompt tells the agent to run `vendor/bin/...` by path. The tree path is shell-quoted, and the helper is idempotent (already-rewritten input is returned unchanged). The rule (D-007): a command starting with `vendor/bin/` is rewritten to `composer --working-dir=<tree> exec <binary> args`; a command starting with `composer ` gets `--working-dir=<tree>` injected; anything else (for example `node --test`) is passed through unchanged and reported in `not_run` only if the runner refuses it (D-006). A unit-style test in `tests/workflows/factory.test.mjs` covers the helper's input and output forms: a composer script, an `exec` binary, a pass-through command, a command already rewritten, and a tree path with spaces or quotes.
3. The gate prompts tell the agent to report a command in `not_run` only when it did not start (runner refusal, wrong checkout), never when it ran and failed, and to put the attempted command and the exact refusal text in `reason`. A test asserts on the prompt strings the workflow builds: the captured gate prompts contain `composer --working-dir=<tree>`, contain no `vendor/bin/`, and name the `not_run` channel.
4. Removed (D-005): running the done-definition commands every round moved to ticket [T-005](../../tickets/T-005.md). The number is kept so the other criteria keep theirs.
5. When a round's gate returns a non-empty `not_run` (any package in a workspace job), the workflow returns `finish({status: 'escalated', stage: 'gate', reasons})` in that round. Each `reasons` entry is a string `gate could not run: <command>: <reason>`, with the package name prefixed to the command for workspace jobs (`<package>: <command>`). No new top-level field is added to the `finish` result. The check runs only when the gate is non-null, directly after the workspace merge and before the `gate.passed` branch, so the existing null-gate-counts-red fallback is unchanged.
6. That escalation does not increment `red` or `redRounds`, starts no rework agent and makes no commit.
7. A gate that runs everything and fails still counts a red round as before. A gate with `not_run: []` (empty) does not escalate.
8. `resources/skills/workflow/factory.md` documents the `not_run` channel, the `gate could not run:` reason prefix and the escalation next to the gate description.
9. `tests/workflows/factory.test.mjs` has simulations for: `not_run` escalating at round 1; a workspace job where one package reports `not_run` (asserting the package name in the reason); a red gate with no `not_run` still reworking; `not_run: []` not escalating. Each escalation simulation asserts: the agent call log has no rework and no commit call, `redRounds` is 0, `red` is unchanged, and `finish` received `status: 'escalated'`, `stage: 'gate'` and the `gate could not run:` reasons. `composer test` passes.
10. A gate that returns real findings together with a non-empty `not_run` in the same round escalates at stage `gate` as in AC5: `not_run` takes precedence and no rework runs (D-004). A simulation covers it, asserting no rework and no commit call. A variant gate that reports `not_run` disqualifies only that variant, and the job escalates at stage `gate` if no variant is eligible (D-003). `passed: true` with a non-empty `not_run` escalates like any other non-empty `not_run`.

## Design

- `GATE.properties.not_run`: `{ type: 'array', items: { type: 'object', required: ['command', 'reason'], properties: { command: string, reason: string } } }`.
- Helper next to `command(pkg, key)`: takes a tree and a command and returns the `composer --working-dir=<tree>` form. Used at `gateVariant` (line ~233) and `runGate` (line ~326).
- After `runGate` and the workspace merge (which concatenates `not_run`, prefixing each command with its package: `(g.not_run || []).map(n => ({...n, command: `${p}: ${n.command}`}))`), check `not_run` before the `red`/review branch and return the escalation. Variant gates (`gateVariant`) report `not_run` in the same field; what a non-empty `not_run` does to a variant (disqualify it, or escalate) is an open question. Whichever is chosen must not reach the `gated.slice().sort(...)[0].n` fallback or the `gated.length === 1` judge-skip with an empty or ungated list.
- Follows `tooling/verification-gate` (composer `--working-dir`), `tooling/node-test` (explicit test file list) and `shell/error-output`. No departures.

## Out of scope

- Stages other than `gate` (review, final checks).
- How an escalated job is resumed.
- Changing the `finish` result schema (the escalation uses the existing `reasons` array).
- The `Config` verify default.
- Runner allowlist changes.
- Merging.
- Making `ringside` calls in Prepare and build groups cwd-proof (spec 2026-10-27-1059-prepare-cwd-proof-preflight, T-002). Both specs edit the same three files: build them one after the other, from a clean main.

## Risks and open questions

Open: none. Answered as D-003 to D-007.

Risks:

- `not_run` is self-reported by the gate agent. AC3 narrows it to commands that did not start and requires the refusal text, but a stubbed-agent simulation cannot prove a real agent obeys; the prompt-string checks are the testable part.
- The working tree has unrelated uncommitted changes in these files; the build must start from a clean main.

## Addendum 2026-10-28

Decisions D-004 to D-007 applied: AC4 removed and split into T-005; AC2 states the rewrite rule and the pass-through; AC10 states the real-findings-plus-`not_run` and variant behaviour. Add the real-findings-plus-`not_run` simulation to AC9's list.
