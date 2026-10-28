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
