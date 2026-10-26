# Standards for Install job worktree dependencies from composer.local.json

| Standard | File | Source | Why it applies |
| --- | --- | --- | --- |
| architecture/configuration | vendor/refactor-circus/standards/standards/architecture/configuration.md | refactor-circus/standards@dev-main | `build.worktree.setup` gets a new `auto` default; hard default on read, fail loud on a bad value |
| testing/test-layers | vendor/refactor-circus/standards/standards/testing/test-layers.md | refactor-circus/standards@dev-main | Copy/install behaviour tested once where it lives; the console command and factory prompt test only their wiring |
