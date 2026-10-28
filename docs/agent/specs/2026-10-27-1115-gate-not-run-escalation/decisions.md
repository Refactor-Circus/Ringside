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
