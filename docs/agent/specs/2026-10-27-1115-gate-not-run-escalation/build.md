# Build: Gate reports commands it could not run and escalates once

## Group 1

Commit `94297ec` on `ring-master/2026-10-27-1115-gate-not-run-escalation`. Tasks 1.1 and 1.2 are done. 1.3 is left for the gate: this build was told not to run the suite.

### Files

- `resources/workflows/gate-command.js` (new): exports `cwdProof(tree, command)`. It is pure and self-contained, with no imports and no globals. It starts with a literal `meta` block (`name: 'gate-command'`) because `tests/Feature/WorkflowScriptsTest.php` requires one in every `resources/workflows/*.js`; the description says it is a helper, not a workflow to launch.
- `tests/workflows/factory.test.mjs`: imports the helper directly (D-010) and adds eight tests. They cover a composer script, a `vendor/bin` binary, pass-through, idempotence, tree paths with spaces, quotes and `$`, compound `&&`/`||`/`;`/`|` commands (plus a separator inside quotes that must not split), env prefixes, and an existing `-d`/`-d<dir>`/`--working-dir <dir>`/`--working-dir=<dir>`.

### Behaviour (D-007, D-008, D-011)

- `vendor/bin/<x> args` becomes `composer --working-dir='<tree>' exec -- <x> args`.
- `composer args` becomes `composer --working-dir='<tree>' args`.
- Anything else is returned unchanged, including `./vendor/bin/...`, a bare `composer`, `undefined` and `''`.
- Each top-level `&&`, `||`, `;` and `|` segment is rewritten on its own. Separators inside single or double quotes are ignored, and joining the parts gives back the input exactly.
- An env-prefixed segment is left unchanged. So is a composer segment carrying `-d`, `-d<dir>`, `--working-dir` or `--working-dir=`.
- A command containing `--working-dir=` anywhere is returned whole and unchanged (D-011). That makes the helper idempotent, because every rewrite adds that string.
- The tree is always POSIX single-quoted (`'` becomes `'\''`).

### Tests

Not run (per the build prompt). Checked by hand: `node --check` on the test file passes, and importing the module from Node 26 with no `package.json` loads it as ESM with no warning and gives the expected forms.

### Deviations

- **`exec --` instead of `exec`.** AC2 and D-007 write the binary form as `composer --working-dir=<tree> exec <binary> args`, but Composer reads options after the binary itself. `composer --working-dir=<wt> exec pint --bogus-option-check` exits 1 with `The "--bogus-option-check" option does not exist.`, and `exec pint --version` prints Composer's version. The default `format_check` (`vendor/bin/pint --test`) would therefore break. With `exec -- pint --version` the options go to the binary (`Pint 1.32.1`). The helper always emits `exec -- <binary>`. AC3's `composer --working-dir=` substring and AC5's reported-command format are unaffected.
- **`-d` counts as a working dir only on composer segments.** D-008 leaves commands with "a different `-d`/`--working-dir`" unchanged. On a `vendor/bin` binary, `-d` is the binary's own flag (`pest -d memory_limit=1G` sets an ini value), not a working dir. Leaving such a command unchanged would run it in the wrong checkout, so it is rewritten. Any `--working-dir=` still returns the whole command unchanged (D-011).

### Notes for group 2

- `tests/workflows/harness.mjs` runs a workflow by stripping `export const meta = ` and evaluating the rest as an `AsyncFunction` body. A static `import { cwdProof } from './gate-command.js'` in `ring-master-factory.js` is a syntax error there, and it is not known whether the Claude Code Workflow runtime resolves imports relative to the script. Group 2 has to pick how the factory loads the helper so that both the harness and the real runtime work. The helper has no dependencies, so any loading approach works.
- `cwdProof(tree, undefined)` returns `undefined`, so `command(pkg, 'format_check') ? ... : ''` style checks still work when wrapped.

### Re-dispatch for task 1.3 (2026-10-27)

The group was sent back with only 1.3 open ("Run vendor/bin/pest and `composer test`; all green"), and the same prompt said not to run the test suite. The worktree is clean at `94297ec`. The helper and tests were checked again against AC2 and D-007, D-008, D-010 and D-011, and nothing changed, so there is no new commit. 1.3 stays unticked until the gate runs `composer --working-dir=<worktree> test` (verification-gate) and it passes.

## Group 2

Commit `1700a9e` on `ring-master/2026-10-27-1115-gate-not-run-escalation`. Tasks 2.1 to 2.5 are done. 2.6 is left for the gate: this build was told not to run the suite.

### Files

- `resources/workflows/ring-master-factory.js`:
  - Loads `cwdProof` with `await import()` from `args.workflows_dir` (D-013). The path becomes a `file://` URL first, because Windows CI cannot import a bare `C:\...` path. A missing `workflows_dir` throws an error that names the fix.
  - `GATE.properties.not_run`: an array of `{command, reason}`, both required.
  - `notRun(gate)` reads the field as an array or `[]`. It coerces each entry with `String((n && n.command) ?? '')` and `String((n && n.reason) ?? '').slice(0, 500)`, so a null entry cannot throw either.
  - `couldNotRun(n, prefix)` formats `gate could not run: <prefix><command>: <reason>`.
  - `notRunRule` is one shared text in every gate prompt. It contains `not_run`, `did not start` and `exact refusal text`, says a command that started and then failed is never `not_run`, and has no `vendor/bin/`.
  - `gateVariant` and `runGate` build verify, format_check and `ringside check` through `cwdProof`. The old "(not vendor/bin/ringside by path ...)" text is gone, so the prompts contain no `vendor/bin/`.
  - Workspace merge: `not_run` is concatenated in package order, each command prefixed `<Package>: `.
  - After the merge and before the `gate.passed` branch, a non-empty `not_run` on a non-null gate returns `finish({status: 'escalated', stage: 'gate', round, reasons})`. It does not touch `red` or `redRounds`, and no rework or review runs.
  - Variants: a variant whose gate reports `not_run` is logged and disqualified, and is kept in a separate `disqualified` list. `gated` holds only eligible variants. If none is eligible and some were disqualified, the job escalates at stage `gate` with `gate could not run: variant <n>: ...` reasons, plus `decisions` when asks are pending (D-009). This happens before the `fallback` sort and the judge.
- `tests/workflows/factory.test.mjs`: simulations 9(a) to 9(j), plus a workspace ordering case (reasons with colons). The AC1 cases are no field, `not_run: null`, a null gate, a null package gate in a workspace, and a malformed entry with a 600-character reason. The AC3 prompt tests cover the single-package, workspace per-package and variant gates with `vendor/bin/pest` and `vendor/bin/pint --test` fixtures, and check that `common` has no `vendor/bin/`. The shared `assertGateEscalation` checks status, stage, reasons, `gate_red_rounds`, no `gate red N rounds` and no `undefined` in reasons, no rework in the escalating round, and no agent after the escalating gate. The existing ringside-check prompt test now expects the quoted `--working-dir='/work/pkg--import'` form.

### Files outside the group's list (D-013)

- `src/Specs/Job.php`: adds `'workflows_dir' => dirname(__DIR__, 2).'/resources/workflows'` to the `ringside job --json` args. This is the absolute folder of the Ringside that builds the args. It does not use `Template::workflowsDir`, which gives a path relative to the project and falls back to a vendor path that does not exist when Ringside runs in its own repo.
- `tests/Feature/JobTest.php`: asserts that `workflows_dir` is set and contains `gate-command.js`.
- `tests/workflows/harness.mjs`: `baseArgs()` passes `workflows_dir` (D-013).

### Tests

Not run (per the build prompt). Checked by hand: `node --check` passes on the test file, and the factory compiles as the harness's `AsyncFunction` body.

### Deviations

- None from the standards. The `ringside check` line in both gate prompts now also goes through `cwdProof`, so its tree is single-quoted like the verify command (AC2 quoting). Its text changed from `--working-dir=/work/pkg--import` to `--working-dir='/work/pkg--import'`.
- The escalation result includes `round`, an existing field that the other stage-`gate` escalation also returns. No new top-level field is added.

## Group 3

Commit `b479744` on `ring-master/2026-10-27-1115-gate-not-run-escalation`. Tasks 3.1 to 3.3 are done. 3.4 is left for the gate: this build was told not to run the suite.

### Files

- `tests/workflows/factory.test.mjs`: imports `readFileSync` from `node:fs` and adds one test (AC8). It reads `resources/skills/workflow/factory.md` relative to the test file (`new URL(..., import.meta.url)`) and asserts it contains `not_run` and `gate could not run:`.
- `resources/skills/workflow/factory.md`:
  - The **escalated** outcome in step 6 now says that a stage `gate` escalation with `gate could not run:` reasons means a command did not run, not that the code failed.
  - A new **Gate** section right after the numbered steps covers:
    - The rewrite: `composer` gets `--working-dir='<worktree>'`, and `vendor/bin/<x>` becomes `exec -- <x>`. Other commands, env-prefixed ones and ones that already name a working dir run as written.
    - The Composer requirement: a `composer.json` and a Composer binary in the worktree, or the job escalates at stage `gate` instead of reworking.
    - The `not_run` channel: a command that did not start, with the exact refusal text. A command that started and failed is never `not_run`.
    - The escalation: at once, at stage `gate`, ahead of findings or a pass. There is one `gate could not run: <command>: <reason>` reason per entry, with the `<package>: ` and `variant <n>: ` prefixes. Variant disqualification carries pending decisions.
    - The state the job is left in: the worktree, branch and earlier rounds' commits are kept. The escalating round runs no rework and makes no commit, and `gate_red_rounds` is unchanged.
- `CHANGELOG.md`: two entries under 0.10.0, one for the `not_run` channel and its escalation and one for the cwd-proof rewrite (`gate-command.js`), its Composer requirement and the new `workflows_dir` job arg (D-013).

### Tests

Not run (per the build prompt). `node --check` passes on the test file.

### Deviations

- None from the standards.
- The CHANGELOG entries go under `## 0.10.0` rather than a new heading. The newest tag is `v0.7.4`, so 0.8.0 to 0.10.0 are all unreleased, and 0.10.0 is the open section.
- The example reason in `factory.md` uses the form the helper really produces (`--working-dir='/tmp/t' exec -- pest`, see the Group 1 deviation). The spec's example uses the unquoted form without `--`.

## Rework round 1

### Finding: `ringside check` reports stale or missing generated files (blocker, gate)

- Cause: the job worktree is a fresh checkout, and the generated files had never been synced there. These are `.claude/skills/ring-master-*`, `.claude/agents/ring-master-*`, `.claude/settings.json`, `AGENTS.md`, `docs/agent/standards.index.yml` and `docs/agent/standards.summary.md`. This repo has never tracked them: `git log --all` shows no history for any of them, and the main checkout keeps them as untracked files. The branch's change to `resources/skills/workflow/factory.md` did not cause the failure.
- Fix: ran `composer --working-dir=<worktree> exec ringside sync` in the worktree (32 files written). `ringside check` now passes there.
- Not done: committing the generated files, which the finding suggested. Ringside's own installer keeps them out of git unless `generated.commit` is set (`Installer::gitignore`). Committing them here would also collide with the untracked copies in the main checkout when the branch merges. They stay as untracked synced output in the worktree, where the gate runs. How fresh worktrees should get these files from now on is returned as a decision.
- Commit: an empty commit, `2026-10-27-1115-gate-not-run-escalation: rework round 1`, marks the round, since no tracked file changed.

### Tests

- `vendor/bin/pest` (in the worktree): 170 passed (631 assertions).
- `ringside check` (in the worktree): passed.

## Group 1

Second re-dispatch for task 1.3 (2026-10-27). No new commit: the worktree is clean at `0e39315`, apart from the untracked synced files from rework round 1.

### Files

- No changes. `resources/workflows/gate-command.js` and its tests in `tests/workflows/factory.test.mjs` were checked again against AC2 and D-007, D-008, D-010 and D-011. They are unchanged since `94297ec`.

### Tests

- Not run. The prompt again said not to run the suite, and 1.3 is the instruction to run it, so 1.3 stays unticked.
- Results already recorded for the code this group owns:
  - Round 1 gate: `vendor/bin/pest` passed (170 tests, which include `WorkflowScriptsTest` running the node simulations and the eight `cwdProof` tests), and `vendor/bin/pint --test` passed.
  - Rework round 1: `vendor/bin/pest` passed (170 tests).
- Nothing records `composer analyse` (phpstan), so `composer test` as a whole has no recorded pass. Group 1 only adds JavaScript, which phpstan does not analyse.
- How to close the run-the-suite tasks (1.3, 2.6, 3.4) is returned as a decision, so this group is not sent back a third time.

### Deviations

- None.

## Group 1

Third re-dispatch for task 1.3 (2026-10-27). No new commit: the worktree is clean at `0e39315`, apart from the untracked synced files from rework round 1. `resources/workflows/gate-command.js` has not changed since `94297ec`.

### Files

- No changes.

### Tests

- Not run. The prompt said not to run the suite.
- D-015 settles the open task. The gate ticks 1.3, 2.6 and 3.4 once `composer --working-dir=<worktree> test` passes, and the build is not re-dispatched for them. So 1.3 stays unticked here for the gate to close, and the decision is not raised again.

### Deviations

- None.

## Group 2

Re-dispatch for task 2.6 (2026-10-27). No new commit. The worktree is clean at `0e39315`, apart from the untracked synced files from rework round 1. Tasks 2.1 to 2.5 were done in `1700a9e`, and `resources/workflows/ring-master-factory.js` and the group 2 tests in `tests/workflows/factory.test.mjs` have not changed since.

### Files

- No changes.

### Tests

- Not run. The prompt said not to run the suite.
- Under D-015 the gate ticks 2.6 once `composer --working-dir=<worktree> test` passes, and the build is not re-dispatched for it. So 2.6 stays unticked here for the gate to close, and the decision is not raised again.

### Deviations

- None.

## Group 3

Re-dispatch for task 3.4 (2026-10-27). No new commit. The worktree is clean at `0e39315`, apart from the untracked synced files from rework round 1. Tasks 3.1 to 3.3 were done in `b479744`, and `resources/skills/workflow/factory.md`, `CHANGELOG.md` and the AC8 test in `tests/workflows/factory.test.mjs` have not changed since.

### Files

- No changes. Checked again against AC8:
  - `factory.md` has the **Gate** section. It covers the `not_run` channel, the `gate could not run:` prefix, the escalation, the state the escalated job is left in and the Composer requirement.
  - `CHANGELOG.md` 0.10.0 has entries for the `not_run` channel and for the rewrite and its Composer requirement.
  - The test `the factory skill documents the not_run channel and the gate could not run: reasons (AC8)` reads `factory.md` and checks for both strings.

### Tests

- Not run. The prompt said not to run the suite.
- Under D-015 the gate ticks 3.4 once `composer --working-dir=<worktree> test` passes, and the build is not re-dispatched for it. So 3.4 stays unticked here for the gate to close, and the decision is not raised again.

### Deviations

- None.

## Group 1

Fourth re-dispatch for task 1.3 (2026-10-27). No new commit. The worktree is clean at `0e39315`, apart from the untracked synced files from rework round 1. `resources/workflows/gate-command.js` and its tests have not changed since `94297ec`.

### Files

- No changes.

### Tests

- Not run. The prompt said not to run the suite.
- Under D-015 the gate ticks 1.3 once `composer --working-dir=<worktree> test` passes, and the build should not be re-dispatched for it. 1.3 stays unticked for the gate to close. This re-dispatch goes against D-015, so the orchestrator's re-dispatch logic is not applying it.

### Deviations

- None.

## Group 4

Commit `b6b9b73` on `ring-master/2026-10-27-1115-gate-not-run-escalation`. Task 4.1 is done: this fixes the nit noted in `decision.md` ("fix the -d nit"). The suite was not run (per the build prompt). The gate runs it.

### Files

- `resources/workflows/gate-command.js`: `namesWorkingDir` used to treat any composer argument starting with `-d` as a working dir. That meant an option such as `-dev` stopped the rewrite and left the command running in the wrong checkout. Now it counts:
  - `-d` on its own (the dir is the next token);
  - `-d` followed by a path: `/`, `.`, `~`, a quote, `$`, or a `/` further on (`SHORT_WORKING_DIR = /^-d(?:$|[/.~'"$]|\S*\/)/`);
  - `--working-dir` and `--working-dir=<dir>`, as before.
  It also looks only at Composer's own arguments, the tokens before a bare `--`. Anything after `--` belongs to the script, so `composer test -- -d memory_limit=1G` is now rewritten as well.
- `tests/workflows/factory.test.mjs`:
  - The existing "already names a working dir" test now also covers `-d./rel`, `-d../rel`, `-d~/pkg`, `-d'/my pkg'`, `-d"/my pkg"`, `-dsub/pkg` and a trailing `-d /other`. All of them stay unchanged.
  - A new test, `cwdProof counts only -d or -d followed by a path as a working dir, and nothing after --`, checks that `composer test -dev`, `composer show -direct`, `composer test -- -d memory_limit=1G`, `composer test -- -d/tmp` and `composer test -- --working-dir /tmp` all get `--working-dir='<tree>'`.

### Tests

- Not run (per the build prompt). `node --check` passes on both files. Under D-015 the gate's `composer --working-dir=<worktree> test` covers the run-the-suite part of 4.1, so 4.1 is ticked.

### Deviations

- None from the standards.
- Scope: "followed by a path" is a heuristic. A bare relative `-dpkg` with no `/`, `.` or `~` is now rewritten rather than left alone. Symfony Console would read it as working dir `pkg`, so the command ends up with two working-dir options, and Composer uses the one it parses last. This case was not in the spec, the decisions or the tests. `-dpkg/` or `-d pkg` still mark it as a working dir.
