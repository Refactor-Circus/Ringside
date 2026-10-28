# Standards for Prepare cwd-proof and preflight

This package has no `docs/agent/standards.index.yml`; the standards are the package's own files under `docs/agent/standards/`.

| Standard | File | Source | Why it applies |
| --- | --- | --- | --- |
| testing/test-layers | vendor/refactor-circus/standards/standards/testing/test-layers.md | refactor-circus/standards@dev-main | Simulations test the script; no PHP changes are intended, so no feature tests are added |
| tooling/node-test | docs/agent/standards/tooling/node-test.md | local | Workflow simulations run with `node --test` on an explicit file list |
| tooling/verification-gate | docs/agent/standards/tooling/verification-gate.md | local | The gate drops `ringside check` when it does not apply |
| shell/error-output | docs/agent/standards/shell/error-output.md | local | Preflight output and failures must reach the agent |
