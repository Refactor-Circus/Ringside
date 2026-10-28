# Make Prepare cwd-proof and add a preflight

Ticket: [T-002](../../tickets/T-002.md)

> Addendum 2026-10-28 (D-009): this job also carries the Windows CI fix (commit 0a99916) in `src/Worktree.php` and `tests/Feature/ImpactTest.php`. The "no PHP changes" statement no longer applies to those two files.

## Goal
The factory's Prepare stage works whatever the session's cwd is, and stops before the build, not after it, when main is dirty, there is no remote for the PR, or `ringside check` cannot apply to the package.

## Acceptance criteria
1. No agent prompt the script sends runs `vendor/bin/ringside`; every ringside call in the Prepare, variant, group-worktree, `--remove-variants` and `--remove-groups` prompts is `composer --working-dir=${job.root} exec ringside -- <args>`. The gate prompts keep using the variant or package worktree as their working dir.
2. Each of those prompts tells the agent never to `cd` into the worktree first.
3. The PREPARED schema accepts `dirty` (array of strings), `remote` (boolean) and `check_applies` (boolean).
4. On a non-replay run the Prepare prompt asks for the preflight, as separate Bash calls ahead of `worktrees --setup`, using the exact commands `git -C ${job.root} status --porcelain` and `git -C ${job.root} remote get-url origin`, plus a check for `docs/agent/standards.index.yml` and `ring-master.yml`. `dirty` = porcelain entries (tracked-modified and untracked both count) that match the spec's task-group file lists (from `tasks.md`; a match is an exact path or a directory prefix of a listed path), or that are spec-folder files outside `docs/agent/specs/<id>`; entries inside the job's own spec folder never count. A group that lists no files contributes no matches. `remote` = `git -C ${job.root} remote get-url origin` succeeded; `check_applies` = the package has `docs/agent/standards.index.yml` and `ring-master.yml`. On a replay the prompt contains no preflight. Who computes the overlap, who decides `check_applies`, and how workspace jobs are handled are open questions below and are not fixed here.
5. When `dirty` is non-empty the script finishes `needs-decision` at stage `prepare` with one ASK, "Commit or stash these main changes before the build?", listing the files, and does not start the build (no build or group agent call occurs). On a non-replay run the Prepare prompt tells the agent to return `ready=false` without running `worktrees --setup` when `dirty` is non-empty or the remote is missing, so no worktree or branch is left behind. Resuming after the user's answer re-runs Prepare and its preflight; the answer is not trusted.
6. When `remote` is false and the job opens a PR, the script finishes `needs-decision` at stage `prepare` with one ASK, "No origin remote found. What is the remote URL for the PR?", and does not start the build. The job opens a PR under the same condition the script uses to push and open one (not a replay, and the host in config is set); when it never would, a missing remote is ignored. If both `dirty` and `remote` fail, only the `dirty` ASK is raised.
7. When `check_applies` is false, both gate prompts (the variant gate and the group or workspace gate) omit `ringside check` for the run, and the script logs that it was dropped, naming the missing file where known.
8. When the preflight is clean (remote present, `check_applies` true), behaviour is unchanged: both gate prompts still run `ringside check`.
9. `resources/skills/workflow/factory.md` describes the preflight in its prepare stage, naming the three fields (`dirty`, `remote`, `check_applies`), the two `needs-decision` outcomes (dirty main, no remote) and the dropped `ringside check`. `WorkflowScriptsTest` asserts those names appear.
10. `tests/workflows/factory.test.mjs` simulations capture every agent prompt and `log()` call, and cover:
    - No captured prompt contains `vendor/bin/ringside`; every ringside call in the Prepare, variant, group-worktree and removal prompts (including `--remove-variants` and `--remove-groups`) uses `composer --working-dir=<root> exec ringside --`, and each of those prompts contains the never-`cd` instruction. The assertion is on captured prompt strings, not file text.
    - The Prepare prompt contains the exact preflight commands ahead of `worktrees --setup`, and tells the agent not to run `--setup` when dirty or without a remote.
    - The schema accepts a PREPARED result carrying `dirty`, `remote` and `check_applies`.
    - A non-empty `dirty` finishes `needs-decision` at `prepare` with the exact ASK and file list and no build or group agent call; includes a directory-prefix case.
    - `remote` false finishes `needs-decision` at `prepare` with the exact ASK and no build call; a job that never pushes ignores it; dirty plus no remote raises only the dirty ASK.
    - `check_applies` false gives a gate prompt without `ringside check` for each of the two gate prompts, and the drop is logged.
    - A clean preflight leaves both gate prompts running `ringside check`.
    - A replay has no preflight in its Prepare prompt and is never stopped by it.
    - A PREPARED result omitting the three fields (legacy shape) still builds; the non-replay behaviour for omitted fields is an open question below.
    - A workspace job: the Prepare prompt runs the preflight against `job.root`; the remaining workspace behaviour follows the open question below.
11. `vendor/bin/pest` passes (including `WorkflowScriptsTest`).

## Design
- Script: a small helper builds the ringside invocation (`composer --working-dir=${job.root} exec ringside -- ...`), so no call site spells it out. The impact check and gate already use this form and stay.
- Prepare agent: preflight steps run first, each in its own Bash call (shell/error-output; no compound commands), before `worktrees --setup`. Workspace jobs run the preflight at the workspace root.
- Script after Prepare: check `dirty`, then `remote`, then set a `gateCheck` flag from `check_applies`. The gate prompts (~234 and ~327) add `ringside check` only when the flag is set; otherwise `log()` records the drop and why. A missing field on a replay or legacy result is read as the safe value (not dirty, remote present, check applies), so older Prepare results keep working; what a non-replay run does with omitted fields is an open question below.
- "The job opens a PR" = the same condition the script uses to push and open a PR: not a replay (replays never push or open PRs) and the config names a host. The remote checked is `origin`; the spec does not check that it is reachable or authenticated.
- No departure from the listed standards.

## Out of scope
- Changing RingMaster, other packages or the allowlist.
- Fixing the dirty files, adding a remote, or creating `ring-master.yml` in this repo.
- Merging.
- Changing the replay path beyond skipping the preflight.
- Changing the `ringside job` JSON or any PHP; no PHP changes are intended, so no PHP feature tests are added.
- Per-package remote or auth checking, and changing how impact checks run.

## Risks and open questions
- This repo's main has uncommitted work touching `ring-master-factory.js`, `factory.md` and `factory.test.mjs`. The preflight this spec adds would stop on exactly that; the user must commit or stash it before the build. Expected outcome: this job reaches `needs-decision` at prepare until then, which is the preflight working, not a failure.
- This repo has no `ring-master.yml` or standards index, so `check_applies` will be false when the factory builds this very job, and the dropped `ringside check` there is the intended behaviour, not a failure. The real run exercises only the false path; the true path is covered by the simulations (criteria 8 and 10), and the PR should say so.
- The `composer --working-dir=... exec ringside` form assumes `composer` is on PATH and resolves the project-local ringside.

## Addendum 2026-10-27

Decisions D-003 to D-006 settle the open questions in criteria 4 and 10 and the Design:

- Workspace jobs (D-003): the Prepare agent runs the dirty, remote and `check_applies` checks per package folder and the results are aggregated; the preflight no longer runs at the workspace root only.
- Omitted fields (D-004): on a non-replay run, a PREPARED result missing `dirty`, `remote` or `check_applies` is a failed preflight; the script finishes `needs-decision` at `prepare`. Safe defaults (not dirty, remote present, check applies) apply only on replay. This replaces the legacy-shape-still-builds case in criterion 10 for non-replay runs.
- `check_applies` (D-005): the Prepare agent reports it from the file check.
- Dirty overlap (D-006): the agent returns raw `git status --porcelain` lines in `dirty`; the script computes the overlap against the task-group files (exact path or directory prefix, plus spec-folder files outside the job's own spec folder). Node simulations test that rule directly.
