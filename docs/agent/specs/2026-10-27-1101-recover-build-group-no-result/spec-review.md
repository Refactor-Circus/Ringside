# Spec review

## Round 1

Changed in spec.md:

- Goal: names turn-budget prevention (implementer.md, `max_turns`) so criteria 8 and 9 trace to it.
- Criterion 1: reworded to an observable (`salvage g<n>` call, no escalation until salvage and retry return).
- Criterion 2: added the `.git/index.lock` / merge / rebase pre-check and that the simulation asserts the salvage prompt fragments.
- Criterion 3: fixed the source of truth (returned ids intersected with group ids, `tasks.md` cross-check outside replay, fail safe toward retry), empty/bogus list, no retry when nothing remains, `build` takes an optional task-id parameter, and an `unfinished:` blocked entry counts as remaining tasks (conditional on the open near-limit reporting question).
- Criterion 4: spelled out that `built` keeps the null entry; an all-null wave escalates without salvage.
- Criterion 7: spelled out today's null-rework behaviour and that `escalate` is passed through; rework scope left as the open question.
- Criterion 10: expanded to assert agent-call details and cover replay, bogus ids, salvage `blocked`/`decisions`, all-null, variant and rework. New criteria 11 (config, template, README, implementer.md tests) and 12 (CHANGELOG, README note); the gate is now 13.
- Design: `build` parameter, id validation, salvage safety checks, workspace helpers.
- Out of scope: other agent roles, dedicated salvage role/config key, integrate/gate steps.
- Risks: clean-main precondition, salvage cost.

Deliberately left alone (open questions): whether rework is covered and what it does on a null; how a near-limit implementer reports unfinished work (criterion 8 wording unchanged, including the `unfinished:` convention); salvage scoping when groups share a tree in parallel mode (file-scoped status/commit and `testsInGroup` handling are not specified).
