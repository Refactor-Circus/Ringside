# References

- `resources/workflows/ring-master-factory.js:301` — the `a build agent failed` escalation to replace with salvage and retry.
- `resources/workflows/ring-master-factory.js` Build loop (around `:287`), `buildVariant` and the rework path — the three places to recover.
- `src/Config.php:103,105` — implementer and reworker `max_turns` (40 → 80).
- `tests/Unit/ConfigTest.php` — config defaults test.
- `resources/agents/` implementer prompt (`implementer.md`), step 4 — add the turn-budget rule.
- `tests/workflows/factory.test.mjs` — simulation harness to extend with a null-once build agent.
