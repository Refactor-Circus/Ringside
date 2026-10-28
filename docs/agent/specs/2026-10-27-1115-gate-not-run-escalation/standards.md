# Standards for Gate reports commands it could not run and escalates once

There is no `docs/agent/standards.index.yml` in this repo; paths come from `docs/agent/standards/`. Local, no standards package, so `meta.yml` `standards:` stays empty.

| Standard | File | Source | Why it applies |
| --- | --- | --- | --- |
| tooling/verification-gate | docs/agent/standards/tooling/verification-gate.md | local (proposed) | Gate commands run as `composer --working-dir=<tree> ...`; this spec makes that uniform |
| tooling/node-test | docs/agent/standards/tooling/node-test.md | local (proposed) | New simulations in `tests/workflows/factory.test.mjs` |
| shell/error-output | docs/agent/standards/shell/error-output.md | local (proposed) | A refused command surfaces as `not_run`, not as a finding |
| testing/test-layers | none (confirmed in D-002, no file in `docs/agent/standards/`) | n/a | Workflow simulations for the new path; nothing to read |
