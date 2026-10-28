# Standards for Recover a build group that returns no result

| Standard | File | Source | Why it applies |
| --- | --- | --- | --- |
| architecture/configuration | vendor/refactor-circus/standards/standards/architecture/configuration.md | refactor-circus/standards@dev-main | `max_turns` defaults live in `Config.php`, documented in the README |
| testing/test-layers | vendor/refactor-circus/standards/standards/testing/test-layers.md | refactor-circus/standards@dev-main | Config default is a unit concern; the workflow behaviour is a simulation |
| tooling/node-test | docs/agent/standards/tooling/node-test.md | local | The null-once simulation extends `tests/workflows/factory.test.mjs` |
| tooling/verification-gate | docs/agent/standards/tooling/verification-gate.md | local | Done means the full gate (`vendor/bin/pest`, format, `ringside check`) passes |
