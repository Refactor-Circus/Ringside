# Post-mortem: 2026-10-27-1059-prepare-cwd-proof-preflight

## Summary

The job made the factory's Prepare stage independent of the cwd: every ringside call now runs through `composer --working-dir=<job.root> exec ringside`. It also added a preflight that reports `dirty`, `remote` and `check_applies` before the worktree is created. PR #3 merged on 2026-10-28 with green CI on all four matrix entries.

The preflight worked as designed: run 1 (wf_e86c17e7-1ed) stopped at prepare on a dirty main (D-008). It still cost two factory runs and nine decisions, for four reasons:

- The spec left four design questions open (D-003 to D-006).
- main's windows-latest CI was already red from f2f488d. That forced an out-of-scope PHP fix (Group 3, D-009).
- A fresh worktree was never synced, so `ringside check` failed in the round-1 gate.
- The gate's own bare `vendor/bin/pest` ran in the wrong cwd, which is the same problem this job fixed for Prepare.

## Metrics

- Factory runs: 2 (wf_e86c17e7-1ed needs-decision round 1, wf_79abbe9e-4f7 approved round 2).
- Review rounds: 2. Escalations: 2. Decisions: 9. Deviations: 6.
- Review findings: 1 blocker, 1 major, 1 minor, 0 nits.
- Human PR comments: none. Ticket comments: none.

## Root causes

- **spec_gap**: four open design questions (workspace jobs, omitted fields, who decides `check_applies`, who computes the dirty overlap). They cost a spec-review round and D-003 to D-006. Criteria 4 and 10 still read as open after the addendum. Evidence: spec.md criteria 4 and 10, Addendum 2026-10-27, spec-review.md.
- **spec_gap**: Out of scope said "no PHP changes". CI on windows-latest then failed on ImpactTest, a failure inherited from f2f488d on main. The fix added Group 3 (src/Worktree.php), and review raised it as a major, which cost a rework round and D-009. Nothing checked that main was green before the build. Evidence: build.md Group 3, decisions.md D-009, spec.md Addendum 2026-10-28.
- **spec_gap**: the spec predicted a dirty main, so run 1 stopped for D-008 before any code was built. The preflight did its job, but the wait was avoidable. Evidence: spec.md Risks, decisions.md D-008.
- **spec_gap**: D-006 moved the overlap computation to the script, but criterion 5 still had the agent hold back `--setup`. That forced an unspecced second Prepare call (`prepare setup`), which the build listed as a deviation. Evidence: build.md Group 1 deviations.
- **tooling**: the round-1 gate failed on "Generated files are stale or missing" in an unsynced fresh worktree. The rework was an empty commit (98d5d30) that only ran `ringside sync`. Evidence: build.md Rework round 1, round-1/gate.md.
- **tooling**: in round 2 the gate's bare `vendor/bin/pest` ran in the session cwd (Cortex), and the gate had to be re-run with `composer --working-dir`. Evidence: round-2/gate.md, ring-master-factory.js:234, :327.
- **implementer_error**: a formatting slip (`list =>list.filter`) in `majorKeys`, fixed in 5bf06f2.
- **implementer_error**: three ImpactTest cases are `skipOnWindows`, so the Windows impact path is unverified. macOS cannot reproduce the backslash bug in `Worktree::removeBeside`, so only CI proves the fix.
- **reviewer_noise**: Cortex's KeenInCiTest failure (Cortex does not track composer.lock) was reported as a broken consumer, although the job never touched Cortex. Evidence: decision.md Broken consumers.

## What human review caught

- No human PR or ticket comments. Human input came only through RingMaster answers to questions the factory raised: D-008 (commit main first) and D-009 (keep the Windows fix).
- Gate gap: round 1 passed with PHP files the spec ruled out and only added a note. Review then raised a major.
- Gate gap: the gate cannot run windows-latest, and the skipOnWindows tests leave a changed path unverified there.
- Gate gap: `ringside check` was run on an unsynced worktree, and gate prompts still pass `vendor/bin/pest` without `--working-dir`.
- Ringside has no checked-in `ring-master.yml` or standards index, so the real run only took the `check_applies: false` path. The true path is covered only by simulations.

## Proposed follow-ups

1. Make the gate prompts in ring-master-factory.js (:234, :327) run verify and format through `composer --working-dir=<worktree>`.
2. Sync generated files into a new job worktree at setup, before the gate runs `ringside check`.
3. Have the gate stop with needs-decision when a changed file falls under the spec's Out of scope.
4. Check main's CI on every matrix entry, and that main is clean, before a spec is approved.
5. Report impact-check failures that come from a consumer's own setup as environmental.

## Standards proposals

Discovery run wf_3f7e3d3f-b46 proposed four project standards (`target: project`): two amendments and two new ones. They are queued as Q-001 to Q-004 in questions.yml for an answer in RingMaster. Accepted ones go to `docs/agent/standards/<key>.md` (`status: proposed`, `from_spec` this spec) on branch `ring-master/standards-2026-10-27-1059-prepare-cwd-proof-preflight`. Rejected ones move to "Rejected proposals".

### tooling/verification-gate (tooling, amend)

Rationale: the round-1 rework ran `ringside sync` against the current rule ("Do not run sync here") to clear an unsynced-worktree failure. `check_applies` now decides whether the check applies, instead of a per-repo exception. In round 2 the bare `vendor/bin/pest` mistake happened again because the gate prompts pass `commands.verify` without `--working-dir`. The round-1 gate passed with out-of-scope PHP files and only noted them, which cost a rework round and D-009. Evidence: build.md Rework round 1 (98d5d30), round-1/gate.md, round-2/gate.md, decisions.md D-009, ring-master-factory.js:234 and :327, src/Config.php:68.

````markdown
# Verification Gate

The gate runs this package's CI steps against the job worktree, and a job is done only when the PR's CI checks are green.

## Running the gate

- Run every gate command through composer on the worktree: `composer --working-dir=<worktree> test`, or `composer --working-dir=<worktree> exec pest` for a single tool. The agent shell resets cwd between calls. A bare `vendor/bin/pest` (the default `commands.verify`) tests whatever folder the session started in: the main checkout, or another package such as Cortex. Absolute-path runs may be refused.
- The gate prompts in `ring-master-factory.js` spell the verify and format commands in this form, the same way the Prepare prompts spell ringside calls. "Run X in <worktree>" is not enough.
- Confirm that the worktree is what got tested. phpstan prints the config path from the worktree, and the job's new tests appear in the pest output. If they don't, re-run the gate. Do not report that result.
- The gate's scripts match the CI steps: `composer analyse`, `composer lint:check`, `composer test:unit`.

## ringside check

- Run it only when Prepare reports `check_applies` true, which means the package has both `docs/agent/standards.index.yml` and `ring-master.yml`. When it is false, the gate leaves the check out and the log names the missing file. Ringside has no `ring-master.yml` in git, so its own runs take the false path (D-013).
- The generated files (`.claude/`, `AGENTS.md`, `docs/agent/standards.index.yml`, `docs/agent/standards.summary.md`) are untracked, so a new worktree has none of them. Sync the worktree when it is created. A later "stale" result then means the build changed a source and did not sync again.
- "Generated files are stale or missing" in a worktree that was never synced is a setup gap, not a finding. Sync, re-run the gate and note it. Do not make a rework commit for it.
- When the real run can only take one path of the check (as on Ringside), the PR body says which path only the simulations cover.

## Scope

- Compare `changed_files` with the spec's Out of scope list. If a changed file falls under something the spec rules out, the gate finishes `needs-decision` and names the file and the clause. It does not pass with a note.
- This also applies to rework groups the workflow adds from failing PR checks. Whether to keep the change in the job is the user's call.

## After the push

- The local gate covers one OS and one Node version. CI (`.github/workflows/tests.yml`) covers ubuntu-latest and windows-latest with prefer-lowest and prefer-stable.
- Wait for the PR checks and rework on red before you report the job as approved.
- The PR body lists what only CI proves: fixes that the developer's OS cannot reproduce, and each `skipOnWindows()` test that leaves a changed path untested on Windows.

## Example

```bash
composer --working-dir=/Users/jay/Projects/packages/Ringside--<spec> test
composer --working-dir=/Users/jay/Projects/packages/Ringside--<spec> exec ringside check   # only when check_applies
gh pr checks <pr> --repo Refactor-Circus/Ringside --watch
```
````

### testing/ci-portability (convention, amend)

Rationale: the Windows CI failures inherited from f2f488d had three causes, and the current standard covers only the fake-composer one. The others: a real bug in `Worktree::removeBeside`, where git's forward-slash paths were compared with backslash prefixes, and a test that built its expectation as `realpath($parent).'/App'`. Output split on newlines could also leave a CRLF behind. The new skipOnWindows tests left the impact path unverified on Windows without anyone noting it. Evidence: build.md Group 3 (CI run 40617420900), src/Worktree.php:176-188 and :285-295, tests/Feature/ImpactTest.php.

````markdown
# CI Portability

Every test passes on each entry of the CI matrix in `.github/workflows/tests.yml` (ubuntu-latest and windows-latest, prefer-lowest and prefer-stable), not only on the developer's machine.

## Git

- A test that makes a commit isolates git config. Point `GIT_CONFIG_GLOBAL` at a temp file with `user.useConfigOnly = true`, set `GIT_CONFIG_NOSYSTEM=1`, unset `EMAIL`, then set `GIT_AUTHOR_*` and `GIT_COMMITTER_*` explicitly.
- Without `useConfigOnly`, git guesses an identity from the host, so a clean-runner failure does not reproduce locally.
- Save every variable you change in `beforeEach` and restore it in `afterEach`. When the test runs a subprocess, pass the env through `Shell::run`'s `$env` argument instead, so the test process itself is never changed.

## Shell fakes and POSIX-only behaviour

- A fake binary written as `#!/bin/sh` (fake `composer`, `git`, `laravel`) only runs on POSIX. Mark the test or `describe` block `skipOnWindows()` and add a comment naming the fake.
- Do the same for tests that depend on `chmod`, symlinks or `stream_select` on pipes.
- A skip means that path is untested on Windows. When a job changes code under a skipped test, the PR body names it (tooling/verification-gate, "After the push").

## Paths

- Compare paths in the form the code produces. When the code normalizes to forward slashes (for example `bin/ringside-hook` before it passes `--project=`), the expectation normalizes too: `str_replace('\\', '/', $root)`.
- git prints paths with forward slashes on Windows (`C:/Users/...`), while PHP's `realpath()` gives `C:\Users\...`. When code matches git output against paths it built itself, run both sides through one helper that turns `\` into `/` and folds case on Windows only. Pass git's own path back to git unchanged.
- Split command output on `\R`, not `\n`, so a CRLF cannot break a `$` anchor.
- Build an expected path with `realpath()` over the whole path (`realpath($parent.'/App')`), not `realpath($parent).'/App'`.
- Treat `C:\…` as absolute, as well as `/…`.
- Compare temp paths through `realpath()`, because macOS resolves them to `/private/var/…`.
- macOS cannot reproduce a backslash bug, because `\` is a valid filename character there. The windows-latest check is the proof.

## Example

```php
private static function comparable(string $path): string
{
    $path = str_replace('\\', '/', $path); // git lists C:/…, PHP builds C:\…

    return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
}

foreach (preg_split('/\R/', $list) ?: [] as $line) { /* ... */ }

it('tests a consumer in a throwaway worktree', function () {
    // ...
})->skipOnWindows(); // the fake `composer` is a #!/bin/sh script
```
````

### workflow/spec-readiness (convention, add)

Rationale: a readiness check before the build would have caught three of the job's root causes:

- Four open questions cost a spec-review round and D-003 to D-006, and criteria 4 and 10 still read as open after the addendum.
- main's windows-latest CI was already red, so a scope of "no PHP changes" forced Group 3, a major finding, a rework round and D-009.
- The spec predicted a dirty main, so run 1 stopped for D-008.

Separately, D-006 moved the overlap computation to the script but left criterion 5 unchanged, which forced an unspecced `prepare setup` call. Evidence: spec.md criteria 4, 5 and 10, the Addenda, spec-review.md, decisions.md D-003 to D-006, D-008 and D-009, build.md Group 1 deviations and Group 3.

````markdown
# Spec Readiness

A spec is ready for approval only when the build can start on a clean, green main and every criterion reads as settled.

## Baseline

- Before approval, check main's latest CI run on every matrix entry (`gh run list --branch main`). If it is red, a separate job fixes it first. If it is not fixed first, the spec names the fix in scope. A Windows failure inherited from main must not show up as a scope finding in review.
- If the spec's Risks say main is dirty, commit or stash main before approval, so the first run does not stop at prepare only to ask about it.

## Open questions

- Settle open questions in shape or spec review, not in an addendum after approval.
- An addendum that settles a question also rewrites each criterion it changes. Criterion text must not still say "open question below" or describe a case the decision replaced.

## Moving work between agent and script

- A decision that moves a computation (for example, the script computes the dirty overlap, not the agent) is checked against every criterion that assumed the old split. If the agent can no longer decide something a criterion asks it to decide, the spec adds the extra step (prompt, call label, failure outcome) and a simulation for it. The build should not have to invent it.

## Example

```markdown
5. When `dirty` is non-empty, Prepare returns `ready=false` without `--setup`.
   When the script finds no overlap, it sends a second Prepare call, `prepare setup`,
   which runs only `worktrees --setup`; a failed setup there escalates as today (D-006).
```
````

### tooling/impact-check (tooling, add)

Rationale: decision.md listed Cortex as a broken consumer on a job that never touched Cortex, though it noted the failure looked environmental. That adds noise to the reviewer's decision. A separate heading keeps real consumer breaks visible. Evidence: decision.md "Broken consumers" (Cortex KeenInCiTest cannot read composer.lock).

````markdown
# Impact Check Results

A consumer failure counts as broken by the job only when the job's change causes it. A failure that comes from the consumer's own setup is reported on its own as environmental.

## Rules

- Before you list a consumer under **Broken consumers**, check whether the failure depends on the job. One sign it does not: the failing test reads a file the consumer does not track (such as `composer.lock`), so the file is never in the throwaway impact worktree.
- Put such a failure under **Environmental consumer failures**, with the test, the file and the reason. Do not count it toward the decision.
- If the same consumer fails the same way on every job, the fix belongs in that consumer's own job. Propose that job and do not repeat the note.

## Example

```markdown
## Environmental consumer failures

- Cortex: KeenInCiTest::it_keeps_refactor_circus_keen_in_require_dev_with_a_lock_entry
  cannot read composer.lock (tests/Feature/Compat/KeenInCiTest.php:89). Cortex does not
  track composer.lock, so the impact worktree has none. Not caused by this job.
```
````
