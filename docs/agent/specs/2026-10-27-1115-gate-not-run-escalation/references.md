# References

## resources/workflows/ring-master-factory.js

- Relevance: `GATE` schema (73), `classify` (127), `gateVariant` (233), `runGate` (327), red-round escalation (386).
- Pattern to borrow: the gate already uses `composer --working-dir=<tree> exec ringside check`; extend that to every gate command through one helper.

## tests/workflows/factory.test.mjs

- Relevance: node simulations of the workflow.
- Pattern to borrow: stub the gate agent result and assert the `finish` payload.

## resources/skills/workflow/factory.md

- Relevance: prose mirror of the script.
- Pattern to borrow: document the `not_run` channel next to the gate description.

## docs/agent/specs/2026-10-27-1059-prepare-cwd-proof-preflight

- Relevance: sibling job making `ringside` calls cwd-proof elsewhere in the script.
- Pattern to borrow: `composer --working-dir=<tree> exec ringside --`.
