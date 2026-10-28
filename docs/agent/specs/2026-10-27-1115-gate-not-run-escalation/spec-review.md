# Spec review

## Round 1

Changed in spec.md. The open questions were left untouched and are now listed under "Risks and open questions".

- AC1: `not_run` is read as `(gate && gate.not_run) || []` everywhere, so older, mocked or resumed gate results do not throw (risk).
- AC2: the helper shell-quotes the tree, is idempotent, and gets a unit-style test for a composer script, an exec binary, already-rewritten input and an awkward path. The script/binary decision rule and the handling of other commands stay open (scope, risk, testability).
- AC3: `not_run` is limited to commands that did not start, with the exact refusal text in `reason`. Tests assert on the built gate prompt strings (contains `composer --working-dir=<tree>`, no `vendor/bin/`, names `not_run`) rather than on agent behaviour (risk, testability).
- AC4: unchanged, and marked as an open question. Its source and trust boundary cannot be defined until the user decides whether it stays (scope, risk, testability).
- AC5: the payload is strings in the existing `reasons` array with a `gate could not run:` prefix, plus the package name for workspace jobs. No new `finish` field. The check sits after the merge and before the `gate.passed` branch, for a non-null gate only (risk, testability).
- AC7 and AC9: an empty `not_run: []` must not escalate. Each simulation lists what it asserts: no rework or commit call, `redRounds` 0, `red` unchanged, finish status and stage (testability).
- AC10 (new): variant gates, findings plus `not_run`, and `passed: true` with `not_run` are deferred to the open questions. The design notes the variant fallback must not crash on an empty or ungated list (scope, risk).
- Design: the workspace merge prefixes the package name onto each command.
- Out of scope: added non-gate stages, how an escalated job resumes, and the `finish` schema.
- AC8: factory.md also documents the reason prefix.

Not changed, because they depend on the open questions: precedence of `not_run` over findings or `passed`, variant eligibility and the all-variants-`not_run` outcome, the done-definition source, and the helper's handling of non-composer commands.

### Second pass (decided D-003 to D-007 applied, open questions still untouched)

- AC1: restated as behaviour (no field, null gate, `not_run: null` complete without throwing); entries coerced with `String(x ?? '')` and reasons truncated to 500 characters; a malformed-entry simulation (risk, testability).
- AC3: lists the three prompt builders (variant, single, workspace per-package); asserts the substrings `composer --working-dir=`, `not_run`, `did not start` and `exact refusal text`; the no-`vendor/bin/` check runs on a `vendor/bin/pest` fixture and confirms `common` has none (risk, testability).
- AC5: one reason per entry in gate order; `<command>` is the form the gate reported (rewritten); example string given (testability).
- AC6: `red` dropped from the assertion; checked through `gate_red_rounds` and no `gate red N rounds` reason (testability).
- AC8: factory.md also states the state an escalated job is left in and the Composer requirement; CHANGELOG entry required; a cheap factory.md assertion added (risk, testability).
- AC9: simulation list extended with real findings plus `not_run`, `passed: true` plus `not_run`, round 2 after a red round, one variant disqualified while another proceeds, all variants `not_run`, and the AC1 cases; the assertions apply to the escalating cases (scope, risk, testability).
- AC10: variant escalation stage is `gate` (not `variants`), uses the same reason format with a `variant <n>:` prefix on the command.
- Design: stale "open question" sentence replaced with D-003; a separate `not_run` variant list keeps the empty-`gated` case from falling into the `!gated.length` branch; gate prompt says a started command is never `not_run`.
- Out of scope: T-005 added. shape.md: done-definition bullet removed from In scope and moved to Out of scope.
- Risks: the "Open: none" line replaced by the four open questions, listed unanswered; Composer-requirement, mislabelled-`not_run` and variant-worktree risks added. Addendum rewritten to say the AC9 list is updated.

Not changed, because they are open questions: compound/prefixed/`-d` command handling, how the helper is exposed to tests, the exact quoting and idempotence rule (so AC2's exact input/output table is still missing), and the result when all variants are `not_run` and asks are pending (no simulation for that combination). Variant worktree cleanup is left undecided.
