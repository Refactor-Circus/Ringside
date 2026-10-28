# Tasks: Recover a build group that returns no result

## Group 1: Turn budget (max_turns 80, implementer wording, docs)
depends on: none
files: src/Config.php, src/Render/Template.php, resources/agents/implementer.md, README.md, CHANGELOG.md, tests/Unit/ConfigTest.php
criteria: 8, 9, 11, 12

- [x] 1.1 Write tests: `ConfigTest` pins the 80 defaults for `implementer` and `reworker`; a unit test asserts the `Template.php` fallback equals `Config.php`; a test asserts the README example shows 80; a test (or `ringside check` rule) asserts the key phrases of `implementer.md` step 4
- [x] 1.2 Raise the `implementer` and `reworker` `max_turns` defaults from 40 to 80 in `src/Config.php` and align the fallback in `src/Render/Template.php`
- [x] 1.3 Add the turn-budget rule to `resources/agents/implementer.md` step 4 (keep the last few turns for committing and returning; near the limit commit what passes and return `done` for the finished tasks only, never `blocked`; never end without returning a result)
- [x] 1.4 Update the README config example and text (80; published or overridden values unchanged) and add the CHANGELOG note
- [ ] 1.5 Run vendor/bin/pest; all green

## Group 2: Salvage and retry in the factory workflow
depends on: none
files: resources/workflows/ring-master-factory.js, tests/workflows/factory.test.mjs
criteria: 1, 2, 3, 4, 5, 6, 10

- [x] 2.1 Write node simulations asserting on recorded agent calls (label `salvage g<n>`, agentType, role/model/max_turns, schema): null once then success reaches the gate; two nulls in a row escalate with the null in `built`; replay-mode retry prompt lists only the remaining ids; non-replay retry prompt lists only the remaining ids; salvage with a bogus or empty id list retries every task not provably done; salvage returning `blocked`; salvage returning `decisions`; all groups null (no salvage, straight escalation); variant path recovers a null; a null rework is still ignored with no salvage call; salvage prompt contains each required fragment (lock/merge/rebase check, files-scoped `status --porcelain -- <files>` and `diff -- <files>`, `composer --working-dir=<tree>`, commit message `<spec>: group <n> <name> (salvaged)`, tick finished tasks, `## Group <n> (salvaged)` in `build.md`, return `done` ids) with `<tree>`, `<spec>`, `<n>`, `<name>` interpolated, and the test step skipped when `testsInGroup` is false
- [x] 2.2 Give the `build` closure an optional task-id parameter that replaces its `todo` filter (replay and non-replay)
- [x] 2.3 Add the `recover(label, result, salvagePrompt, retry)` helper: salvage agent `salvage g<n>` (agentType `ring-master-implementer`, role `reworker`, schema `BUILT`), remaining = group tasks minus validated done ids (cross-check `tasks.md` outside replay, larger unfinished set wins), one retry of the remaining ids, no retry when none remain
- [x] 2.4 Route the Build loop and `buildVariant` through `recover`; skip salvage and escalate straight away when every group in a wave is null; escalate `a build agent failed` with the null still in `built` when salvage or retry returns null; handle `decisions` and `blocked` from salvage/retry like a normal build result; leave rework calls unchanged
- [ ] 2.5 Run vendor/bin/pest and `node --test tests/workflows`; all green

## Group 3: Rework 1: gate skips `ringside check` for Ringside (D-009)
depends on: 2
files: resources/workflows/ring-master-factory.js, tests/workflows/factory.test.mjs
criteria: none (D-009 addendum)

- [x] 3.1 Write a test that the gate prompts (variant and single-package) contain no `ringside check` when the package is Ringside, and still contain it for other packages
- [x] 3.2 Make the gate prompts in `ring-master-factory.js` (variant gate and per-package gate) skip `ringside check` for Ringside; the generated-files finding is dropped
- [x] 3.3 Run vendor/bin/pest and `node --test tests/workflows`; all green
