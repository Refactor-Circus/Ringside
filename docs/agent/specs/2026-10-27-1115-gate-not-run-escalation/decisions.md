# Decisions

## D-001

- Question: Create this ticket as drafted? Title: 'Gate reports commands it could not run and escalates once'.
- Choice: Create as drafted
- Rationale: none given
- Raised by: ticket (answered in RingMaster)
- Date: 2026-10-27

## D-002

- Question: Which standards apply to this spec?
- Choice: testing/test-layers + tooling/node-test + tooling/verification-gate + shell/error-output
- Rationale: none given
- Raised by: shape (answered in RingMaster)
- Date: 2026-10-27

## D-003

- Question: When a variant gate reports `not_run`, what should happen?
- Choice: Disqualify only that variant, and escalate at stage `gate` if no variant is eligible
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-27

## D-004

- Question: When a gate returns real failures and a non-empty `not_run` in the same round, what should the factory do?
- Choice: Escalate immediately at stage gate; not_run takes precedence, no rework
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-28

## D-005

- Question: Should the done-definition-every-round change (AC4) stay in this spec?
- Choice: Split it into a separate ticket and remove AC4
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-28

## D-006

- Question: When a gate command is neither a composer script nor a vendor/bin binary, should the helper pass it through unchanged or escalate?
- Choice: Pass it through unchanged and report in not_run only if the runner refuses it
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-28

## D-007

- Question: How should the cwd-proof helper decide between `composer --working-dir=<tree> <script>` and `composer --working-dir=<tree> exec <binary>`?
- Choice: Explicit rule: a command starting with `vendor/bin/` is rewritten to `exec <binary> args`; a command starting with `composer ` gets `--working-dir` injected; anything else is passed through unchanged and left to be reported in `not_run` if refused
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-28

## D-008

- Question: How should the cwd-proof helper treat compound or prefixed gate commands (`a && b`, `;`, pipes, `ENV=1 composer ...`) and commands that already carry a different `-d`/`--working-dir`?
- Choice: Rewrite each `&&`/`;`/`|` segment by the D-007 rule, leave env-prefixed and existing-working-dir commands unchanged, and add all forms to the helper test
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-28

## D-009

- Question: When every variant is disqualified by `not_run` (D-003) and some variants also have pending build decisions (asks), which should the factory return?
- Choice: Escalate at stage `gate` with the `gate could not run:` reasons first; carry the pending decisions in the result
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-28

## D-010

- Question: How should the cwd-proof helper be exposed so tests/workflows/factory.test.mjs can unit-test its input and output forms (AC2)?
- Choice: Export the helper from a small separate module that the workflow script imports and the test imports directly
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-28

## D-011

- Question: What exact shell-quoting and idempotence rule should the helper use for the tree path and already-rewritten commands?
- Choice: POSIX single-quote the tree (`'` becomes `'\''`); a command already containing `--working-dir=` is returned unchanged
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-28

## D-012

- Question: Approve the spec "Gate reports commands it could not run and escalates once" (D-001 to D-011 applied, no open questions) so the factory can build it?
- Choice: Approve
- Rationale: none given
- Raised by: approval (answered in RingMaster)
- Date: 2026-10-28

## D-013

- Question: How should ring-master-factory.js load cwdProof from resources/workflows/gate-command.js (D-010), so that both tests/workflows/harness.mjs and the real Claude Code Workflow runtime work?
- Choice: Dynamic import: the factory does `const { cwdProof } = await import(<workflows dir>/gate-command.js)`, with the absolute workflows dir added to the `ringside job --json` args (src/Specs/Job.php) and passed by the harness
- Rationale: none given
- Raised by: build (answered in RingMaster)
- Date: 2026-10-28

## D-014

- Question: Fresh job worktrees have no ringside-generated files, so `ringside check` fails in the gate until someone runs sync by hand. How should a job worktree get them?
- Choice: Leave `ringside check` out of Ringside's gate, as the proposed tooling/verification-gate standard says (no sync, no setup change)
- Rationale: none given (answered in RingMaster)
- Raised by: build
- Date: 2026-10-28

## D-015

- Question: Tasks 1.3, 2.6 and 3.4 only say to run vendor/bin/pest and composer test, but every build prompt for this job says not to run the suite, so Group 1 has been re-dispatched twice with only 1.3 open. How should these run-the-suite tasks be closed?
- Choice: The gate ticks 1.3, 2.6 and 3.4 once it has run `composer --working-dir=<worktree> test` and that passes, and the build is not re-dispatched for them
- Rationale: none given
- Raised by: build (answered in RingMaster)
- Date: 2026-10-28

## D-016

- Question: Should the spec's rewrite rule be updated to say `exec -- <binary>` (as built) instead of `exec <binary>`?
- Choice: Update the spec to `exec -- <binary>`
- Rationale: none given
- Raised by: build (answered in RingMaster)
- Date: 2026-10-28

## D-017

- Question: The factory workflow now throws at start when args.workflows_dir is missing. Should it keep failing hard, or fall back so args built by an older Ringside, a resumed job or another args builder still run?
- Choice: Keep the hard failure: the CHANGELOG and the error message say to rebuild args with `ringside job --json`
- Rationale: none given
- Raised by: build (answered in RingMaster)
- Date: 2026-10-28
