# Decisions

## D-001

- Question: Create file ticket T-002 as drafted? (Make Prepare cwd-proof and add a preflight)
- Choice: Create the ticket as drafted
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

- Question: How should the preflight treat workspace jobs spanning several packages?
- Choice: Run dirty, remote and check_applies per package folder and aggregate the results
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-27

## D-004

- Question: When the Prepare agent's result omits dirty/remote/check_applies on a non-replay run, what should the script do?
- Choice: Treat missing fields on a non-replay run as a failed preflight and finish needs-decision at prepare; use safe defaults only for replay
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-27

## D-005

- Question: Who decides check_applies?
- Choice: The Prepare agent reports it from a file check
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-27

## D-006

- Question: Who computes the dirty overlap: the Prepare agent or the script?
- Choice: The agent returns raw git status --porcelain lines and the script computes the overlap against task-group files, so a simulation can test the rule
- Rationale: none given
- Raised by: spec-review (answered in RingMaster)
- Date: 2026-10-27

## D-007

- Question: Approve this spec as written so the factory can build it?
- Choice: Approve the spec
- Rationale: none given
- Raised by: approval (answered in RingMaster)
- Date: 2026-10-27

## D-008

- Question: Commit or stash these main changes before the build? (24 uncommitted modified files on main, including the files this job edits.)
- Choice: Commit them on main first, then build
- Rationale: none given (answered in RingMaster)
- Raised by: build
- Date: 2026-10-28
