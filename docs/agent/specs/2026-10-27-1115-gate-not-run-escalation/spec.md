# Gate reports commands it could not run and escalates once

Ticket: [T-004](../../tickets/T-004.md)

## Goal

When the factory gate cannot run a command (the runner refuses it, or it runs in the wrong checkout), it says so in a separate `not_run` channel and the factory escalates once at stage `gate`, instead of counting a red round and sending a rework agent nothing to fix. For whoever runs the factory.

## Acceptance criteria

1. The `GATE` schema in `resources/workflows/ring-master-factory.js` has an optional `not_run` array of `{command, reason}` objects for commands the gate could not run. Gate objects without the field (older, mocked or resumed results), a null gate and a gate with `not_run: null` complete without throwing and behave as before; an existing simulation, or one added for this, covers each of the three. (The workflow reads the field as `(gate && gate.not_run) || []`.) Entries are coerced when read: `String(n.command ?? '')` and `String(n.reason ?? '')`, with each reason truncated to 500 characters. One simulation uses a malformed entry (missing command, non-string reason) and asserts no `undefined` appears in `reasons`.
2. One helper rewrites a gate command so it is cwd-proof (`composer --working-dir=<tree> ...`, with `exec` for a binary); `gateVariant` and `runGate` both build their commands through it, and no gate prompt tells the agent to run `vendor/bin/...` by path. The tree path is shell-quoted, and the helper is idempotent (already-rewritten input is returned unchanged). The rule (D-007): a command starting with `vendor/bin/` is rewritten to `composer --working-dir=<tree> exec <binary> args`; a command starting with `composer ` gets `--working-dir=<tree>` injected; anything else (for example `node --test`) is passed through unchanged and reported in `not_run` only if the runner refuses it (D-006). Compound commands (D-008): each `&&`, `;` or `|` segment is rewritten by the same rule; a command with an env prefix (`ENV=1 composer ...`) or one that already carries `--working-dir` / `-d` is left unchanged. Quoting (D-011): the tree is POSIX single-quoted (`'` becomes `'\''`), and a command already containing `--working-dir=` is returned unchanged (idempotent). The helper lives in a small separate module that the workflow script and the test both import (D-010). A unit-style test in `tests/workflows/factory.test.mjs` covers the helper's input and output forms: a composer script, an `exec` binary, a pass-through command, a command already rewritten, a tree path with spaces or quotes, a compound command, an env-prefixed command and one with an existing `-d`/`--working-dir`.
3. The gate prompts tell the agent to report a command in `not_run` only when it did not start (runner refusal, wrong checkout), never when it ran and failed, and to put the attempted command and the exact refusal text in `reason`. A test asserts on the prompt strings the workflow builds, for every gate prompt builder: the variant gate, the single-package gate and the workspace per-package gate. Each captured gate prompt contains the substrings `composer --working-dir=`, `not_run`, and a fixed phrase for the instruction (`did not start`) plus one for the refusal text (`exact refusal text`). The no-`vendor/bin/` assertion runs on a fixture whose configured verify command is `vendor/bin/pest`, so the rewrite is what is proven; the test also confirms the shared `common` text contains no `vendor/bin/`.
4. Removed (D-005): running the done-definition commands every round moved to ticket [T-005](../../tickets/T-005.md). The number is kept so the other criteria keep theirs.
5. When a round's gate returns a non-empty `not_run` (any package in a workspace job), the workflow returns `finish({status: 'escalated', stage: 'gate', reasons})` in that round. `reasons` has one string per `not_run` entry, in the order the gate returned them (package order for workspace jobs). Each is `gate could not run: <command>: <reason>`, with the package name prefixed to the command for workspace jobs (`<package>: <command>`). `<command>` is the command exactly as the gate reported it, which is the cwd-proof (rewritten) form the prompt told it to run; the reason is taken verbatim after the second `: ` even when it contains colons. Example: `gate could not run: Atrium: composer --working-dir=/tmp/t exec pest: runner refused: not in allowlist`. No new top-level field is added to the `finish` result. The check runs only when the gate is non-null, directly after the workspace merge and before the `gate.passed` branch, so the existing null-gate-counts-red fallback is unchanged.
6. That escalation does not increment `red` or `redRounds`, starts no rework agent and makes no commit. `red` is local, so it is verified indirectly: `gate_red_rounds` in the `finish` result keeps the value it had before the round (0 in round 1) and `reasons` contains no `gate red N rounds` entry.
7. A gate that runs everything and fails still counts a red round as before. A gate with `not_run: []` (empty) does not escalate.
8. `resources/skills/workflow/factory.md` documents the `not_run` channel, the `gate could not run:` reason prefix and the escalation next to the gate description. It also says what state the escalated job is left in (work and commits from earlier rounds are kept, no rework ran in the escalating round), and notes the Composer requirement of the rewrite (see Risks). `CHANGELOG.md` has an entry for the `not_run` channel and for the rewrite and its Composer requirement. A cheap assertion in `factory.test.mjs` reads `resources/skills/workflow/factory.md` and checks that it contains `not_run` and `gate could not run:`.
9. `tests/workflows/factory.test.mjs` has simulations for: (a) `not_run` escalating at round 1; (b) a workspace job where one package reports `not_run` (asserting the package name in the reason); (c) a red gate with no `not_run` still reworking; (d) `not_run: []` not escalating; (e) real findings plus a non-empty `not_run` escalating with no rework (D-004); (f) `passed: true` with a non-empty `not_run` escalating; (g) a round-2 case: a red round first, then `not_run`, escalating with `gate_red_rounds` still at its earlier value; (h) variants, 2 variants where one reports `not_run`: it is disqualified, the other proceeds, and the judge is skipped (`gated.length === 1`); (i) variants, all variants report `not_run`: the job escalates at stage `gate` without reaching the `gated.slice().sort(...)[0].n` fallback or the judge-skip, and a second case where some variants also have pending asks: the result is still the `gate` escalation with the `gate could not run:` reasons first and the pending decisions carried along (D-009); (j) the AC1 cases (no `not_run` field, null gate, `not_run: null`) and the malformed entry. Each escalating simulation (a, b, e, f, g, i) asserts: the agent call log has no rework and no commit call, `finish` received `status: 'escalated'`, `stage: 'gate'` and the `gate could not run:` reasons, and `gate_red_rounds` is as AC6 states. `composer test` passes.
10. A gate that returns real findings together with a non-empty `not_run` in the same round escalates at stage `gate` as in AC5: `not_run` takes precedence and no rework runs (D-004). A variant gate that reports `not_run` disqualifies only that variant, and the job escalates at stage `gate` if no variant is eligible (D-003); the stage is `gate`, not the `variants` stage of the existing "no variant built and gated" escalation. Variant escalation uses the same `gate could not run: <command>: <reason>` format, with the variant prefixed to the command like the package is for workspaces (`variant <n>: <command>`). `passed: true` with a non-empty `not_run` escalates like any other non-empty `not_run`. Simulations (e), (f), (h) and (i) in AC9 cover these.

## Design

- `GATE.properties.not_run`: `{ type: 'array', items: { type: 'object', required: ['command', 'reason'], properties: { command: string, reason: string } } }`.
- Helper next to `command(pkg, key)`: takes a tree and a command and returns the `composer --working-dir=<tree>` form. Used at `gateVariant` (line ~233) and `runGate` (line ~326).
- After `runGate` and the workspace merge (which concatenates `not_run`, prefixing each command with its package: `(g.not_run || []).map(n => ({...n, command: `${p}: ${n.command}`}))`), check `not_run` before the `red`/review branch and return the escalation. Variant gates (`gateVariant`) report `not_run` in the same field. Per D-003 a non-empty `not_run` disqualifies only that variant, and the job escalates at stage `gate` if no variant is eligible. The build keeps a separate list of `not_run` variants so the empty-`gated` case emits the stage `gate` escalation (not `gate: null` folded into `!gated.length`), and so the escalation never reaches the `gated.slice().sort(...)[0].n` fallback or the `gated.length === 1` judge-skip with an empty or ungated list.
- The gate prompt states that a command that started and exited non-zero is never `not_run`. The agent-reported channel fails closed (never a false pass).
- Follows `tooling/verification-gate` (composer `--working-dir`), `tooling/node-test` (explicit test file list) and `shell/error-output`. No departures.

## Out of scope

- Stages other than `gate` (review, final checks).
- How an escalated job is resumed.
- Changing the `finish` result schema (the escalation uses the existing `reasons` array).
- The `Config` verify default.
- Runner allowlist changes.
- Merging.
- Running the done-definition commands every round (T-005, D-005).
- Making `ringside` calls in Prepare and build groups cwd-proof (spec 2026-10-27-1059-prepare-cwd-proof-preflight, T-002). Both specs edit the same three files: build them one after the other, from a clean main.

## Risks and open questions

Open: none.

Answered as D-003 to D-011: variant `not_run`, precedence over findings, T-005 split, the rewrite rule and the pass-through, compound/prefixed commands (D-008), the all-variants-`not_run` result with pending asks (D-009: escalate at stage `gate` with the `gate could not run:` reasons first and carry the pending decisions in the result), the helper module (D-010) and the quoting rule (D-011).

Risks:

- Rewriting `vendor/bin/<x>` to `composer --working-dir=<tree> exec <x>` needs a `composer.json` and a Composer binary in the tree. A consumer without them now sees a `gate` escalation instead of a rework. AC8 puts this in `CHANGELOG.md` and `factory.md`.
- A gate agent that mislabels a real failure as `not_run` stops the job instead of reworking, with no retry; in round 2 and later the rework commits already exist. This fails closed and AC8 documents the state the job is left in; the gate prompt (AC3) says a started command is never `not_run`.
- Disqualified variant worktrees and branches: whether they are cleaned up is not decided here.
- `not_run` is self-reported by the gate agent. AC3 narrows it to commands that did not start and requires the refusal text, but a stubbed-agent simulation cannot prove a real agent obeys; the prompt-string checks are the testable part.
- The working tree has unrelated uncommitted changes in these files; the build must start from a clean main.

## Addendum 2026-10-28

Decisions D-004 to D-007 applied: AC4 removed and split into T-005; AC2 states the rewrite rule and the pass-through; AC10 states the real-findings-plus-`not_run` and variant behaviour. The real-findings-plus-`not_run` and variant simulations are now in AC9's list.

## Addendum 2026-10-28 (D-016)

The rewrite rule is `vendor/bin/<x> args` becomes `composer --working-dir='<tree>' exec -- <x> args`, with `--` so Composer does not parse the binary's own options (such as `--test`). Wherever AC2, D-007 and the AC5 example say `exec <binary>`, read `exec -- <binary>`. No code change.
