# Changelog

## 0.10.0

- Variants: the factory can build a whole job several ways at once and keep the best. `build.variants` (default 1: off) or a spec's `meta.yml` `variants:` (wins; capped at 4) builds it N times in parallel, each in `<job worktree>--v<n>` on `<branch>--v<n>` (`ringside worktrees <spec> --variants=N [--setup]`, `--remove-variants`), with its own approach hint and model (`roles.variant.models`, default `[opus, opus, sonnet, opus]`). Each variant is gated; the new `judge` role (opus, high) picks one from the gate results, criteria coverage and diffs (falling back to the best gate result), it is fast-forwarded into the job branch, and the review rounds go on. The result carries `variants_winner`; the judge's reasons go to `variants.md`. Single packages with per-job worktrees only: a workspace job logs it and builds once. `ringside job --json` gives the count as `variants`.
- "Rebuild with variants": an escalation can now be answered with that option (RingMaster offers it). `/ring-master-rework --variants` sets `variants: <build.escalation_variants>` (default 3) in `meta.yml`, unticks the task groups and relaunches the factory, which clears `variants` when the run returns.
- Reviewer brief: on approval the new `briefer` role (sonnet, low) writes `brief.md` in the spec folder, first line `Risk: low|medium|high`, then `## What changed`, `## Why`, `## Look at` (one to five `path:line` places), `## Tested` and `## Risks`. The PR body starts with it, the result carries `brief: {risk, path}`, and `ringside job --json` gives its path as `brief` (or null).
- Replay mode, the hook RingMaster's factory evaluations use: `/ring-master-factory <spec> --replay[=<commit>]` and the workflow args `replay: true, replay_from`. `ringside job <spec> --json --replay` marks every task group undone and moves the job to `<branch>--replay` in `<job worktree>--replay`, which `ringside worktrees <spec> --replay [--from=<commit>]` creates and `--remove-replay` removes (with its variants). The run writes its reports under `<spec>/replay/`, skips the impact check and the brief, never pushes, opens or updates a PR, proposes tasks, calls RingMaster or changes the spec's `meta.yml`, `tasks.md`, `questions.yml` or `decision.md`, and ends with `<spec>/replay.json`: `{status, rounds, gate_red_rounds, findings_left, nits, variants_winner, reasons}` (`findings_left` and `nits` are counts). Every factory result now carries `replay`, `gate_red_rounds` and `variants_winner`.
- Precedent: under RingMaster, spec review, the factory, `/ring-master-decide` and `/ring-master-inbox` check each architectural question against RingMaster's new `precedents` MCP tool before writing it to `questions.yml`. A match with similarity 0.5 or more becomes the `recommendation` (the option matching the past answer) and the first line of `evidence` ("Precedent: you chose <answer> for <question> in <project> on <date>"); the question is still asked, never answered for you. Without RingMaster the check is skipped.
- The board lists `brief.md` and `variants.md` among a spec's files, so RingMaster can show the reviewer brief and rank PRs by its risk.
- The `implementer` and `reworker` roles default to `max_turns: 80` (it was 40), and the implementer is told to keep its last few turns for committing and returning its result: close to the limit it commits what passes and returns `done` for the finished tasks only, never `blocked`. Build agents had run out of turns and returned nothing, which escalated the whole job. A `max_turns` you published into `ring-master.yml` or overrode there is unchanged; delete it to get 80.

## 0.9.0

- The impact check: before the factory approves, every package that requires the changed ones runs its tests against the job. New `ringside impact <spec> [--package=] [--list] [--json]` builds a throwaway detached worktree of each consumer at its base branch, installs it from its `composer.local.json` (or `composer.json`) with the changed packages resolved to the job worktree, runs its `commands.test` and removes the worktree. Consumers come from `impact.consumers` (`auto`: folders beside the package, plus workspace packages, whose composer.json requires it; a list; `off`), and `ringside job --json` lists them under `impact`. A broken consumer never fails the job: the result's `impact` lists it, the PR names it, and under RingMaster `/ring-master-factory` proposes a follow-up job in it that waits for this PR (`propose-task` with `waits_for_this`). New role `impact` (sonnet).
- Model escalation: `roles.<role>.escalate_model` (default `opus` for the gate and the reworker, the roles that run again each round; `null` turns it off). The gate runs on it after a red gate; the reworker after a red gate or once the same major findings repeat between rounds, a round before the run would stop for no progress.
- Parallel groups get their own worktrees: with `build.parallel` in a single per-job package, each group of a multi-group wave builds in `<job worktree>--g<n>` on `<branch>--g<n>` (`ringside worktrees <spec> --groups=1,3 [--setup]`), so groups may share files, and the new `integrator` role (opus) merges them into the job branch, runs the tests and removes them (`--remove-groups`). A failed merge escalates with stage `integrate`. Workspace jobs and single-group waves build as before.
- `build.parallel` defaults to `true` (it was `false`): the groups of a wave build at once, up to `build.max_concurrency` (2), each in its own worktree in a per-job package; in a shared tree only groups whose `files:` don't overlap run together. Set `build: { parallel: false }` for one at a time.
- The triage role is on by default (`roles.triage.enabled: true`, haiku): when a round has more than one finding, it merges duplicates before rework. Set it to `false` to skip it.

## 0.8.0

- Every part of the workflow names its model. The implementer, reworker and discoverer default to `opus` (they ran on the session's model), and five new roles set the model of the skills a session runs, written as `model:` in each generated `SKILL.md`: `orchestrator` (sonnet: ship, inbox, factory, spec review, decide, approve, rework, post-mortem, verify), `ticketer` (sonnet), `shaper` (opus: shape-spec, plan-product), `writer` (opus: write-spec) and `planner` (opus: create-tasks). Set `roles.<role>.model` in `ring-master.yml` to change one; standards skills keep the session's model.
- `ringside worktrees <spec> [--setup]` works in a single package with `build.worktree.mode: per-job`: it creates or reuses the job worktree on `ring-master/<spec>` (failing when the folder is on another branch) and, with `--setup`, installs its dependencies. The factory's prepare agent now runs it instead of `git worktree add` and the setup command by hand, and returns ready=false with the output when it fails. `--remove` stays workspace-only.
- `build.worktree.setup` defaults to `auto` (it was `composer install --no-interaction`), so every project that never set the key changes behaviour: with `auto`, a package that has a `composer.local.json` installs its job worktrees from that manifest (copied in with its `composer.local.lock`, `COMPOSER=composer.local.json`), including its sibling path repositories; without one it runs a plain `composer install`. An explicit command still runs as is: delete the key from `ring-master.yml` to get `auto`. A non-string value now fails loud. Workspace jobs keep choosing `composer local:install` or `composer install` per package.

- Spec review converges. After you answer its questions, `/ring-master-decide` relaunches it with `--recheck=<D-ids>`: one critic checks those answers landed in `spec.md` instead of a fresh full critique (which kept finding new questions in an ever more detailed spec). New `stages.spec_review.max_passes` (default 2, counted in `meta.yml` `review_passes`): past it, what the critics would ask goes into `spec-review.md` as non-blocking notes and the spec goes to approval.
- Factory agents are told to run one command per Bash call (`cd` on its own, `git -C`, `composer --working-dir`) and to retry a refused command split up: under RingMaster's runner a compound command is refused whole, and the prepare agent gave up on its first `cd … && …`.
- The factory gate runs `composer --working-dir=<worktree> exec ringside check` instead of `vendor/bin/ringside check`: the gate agent works from the main checkout, the runner refuses the worktree's binary by absolute path, so the gate went red three rounds running on a check that passed.
- A question's `recommendation` must be the exact text of one option (workflow schemas, `/ring-master-decide` and `/ring-master-inbox` say so); agents had written `A`, which RingMaster showed as an extra, near-empty option.
- The board reads a `pr:` written as a bare URL (as `{url}`); RingMaster missed such PRs, so a task waiting on one never resumed after the merge.
- A job sent back from review (`/ring-master-rework` on an `in-review` spec) keeps its branch and PR: the factory and `/ring-master-approve` push to the PR in `meta.yml` `pr:` and update its body instead of opening a second one.
- `/ring-master-inbox` proposes follow-up jobs with RingMaster's new `propose-task` tool (a fix in another package, say) instead of stopping with "queue this yourself": the user accepts it in the Inbox, and the waiting task resumes once it is done, following the task's `resume_note`.

## 0.7.4

- `/ring-master-ship` resumes a `building` spec (a factory run that stopped part-way) by running the factory again; ticked task groups are skipped. RingMaster resumes such jobs once their worktree has been quiet for a while.

## 0.7.3

- `/ring-master-ship` runs every enabled stage, however small the job looks (a test session skipped spec review on its own judgement).

## 0.7.2

- `/ring-master-decide` empties `questions.yml` to `[]` instead of deleting it (runner sessions can't run `rm`), and `/ring-master-inbox --answers` says plainly that it relaunches the stages the answers unblock.

## 0.7.1

- `/ring-master-inbox` tells sessions to run one command per Bash call (a runner session's allowlist refuses compound commands whole) and lets ship skip `ringside ref` when a RingMaster task already gives its kind.

## 0.7.0

- Stage skills can be chained: `/ring-master-ship` and `/ring-master-inbox` now invoke them with the Skill tool, which Claude Code refuses for skills marked `disable-model-invocation`. Every stage skill (ticket, shape, write, spec review, tasks, factory, implement, verify, decide, approve, rework, post-mortem, inject-standards) and ship itself are model-invocable, and their descriptions say they run only inside a Ring Master job the user started or when the user asks. `/ring-master-inbox`, discover-standards, plan-product and propose stay user-only.

## 0.6.0

- `/ring-master-inbox` exists in every project (sessions RingMaster's runner starts in a package need it there) and takes the runner's arguments: `--answers` (apply answers only) and `--task=<id> --session=<name>` (ship that one already-claimed task).

## 0.5.0

- `board.app` points a project at the RingMaster app. Sessions then start with RingMaster's one-line queue summary, and `/ring-master-inbox` applies the answers given there, then claims queued tasks over RingMaster's MCP server and ships them unattended: every question a stage would ask goes to `questions.yml` with the stage to return to as `source` (`ticket`, `shape`, `spec-review`, `approval`, `tasks`, `build`).
- `/ring-master-decide` and `/ring-master-approve` take answers given in RingMaster without asking again.

## 0.4.0

- Open architectural questions live in the spec's `questions.yml` (id, question, options, recommendation, evidence, source), written by the factory and spec review and cleared by `/ring-master-decide`, so RingMaster can show and answer them. Spec review now hands its questions to `/ring-master-decide` too.
- `Board` reads `questions.yml` (`open_questions`, and `questions` counts it); older specs still count the questions in `decision.md`.

## 0.3.0

- Requires PHP 8.5.
- `new-package` pins every new package to `new_package.php` (default `^8.5`), whatever the skeleton ships with.

## 0.2.0

- Removed `ringside dashboard` and the `dashboard` config key: the RingMaster app is the board now. `check` names the key if it is still in `ring-master.yml`.
- `Board` (every job's state read from the spec folders) moved to `RefactorCircus\Ringside\Specs\Board` for the app to import from.
- `new-package` adds the package to `workspace.packages` only.

## 0.1.0

- First release as Ringside, Ring Master's per-repo plugin; continues agent-kit 0.5.0.
- Renamed throughout: package `refactor-circus/ringside`, CLI `vendor/bin/ringside`, config `ring-master.yml`, skills and agents `/ring-master-*`, jobs in `.ring-master/`, branches `ring-master/<spec>`, package key `extra.ring-master` (`extra.agent-kit` still read).
- Migrates agent-kit installs automatically on sync and install, or with `ringside migrate [--dry-run]`; `check` flags one left behind.

# agent-kit history

### 0.5.0

- `ringside new-package`: scaffold a new workspace package with the Laravel installer's `laravel package` command (or `composer init` for plain PHP), git init it with a first commit, and add it to `workspace.packages` and the dashboard.
- Specs can list `new_packages` in `meta.yml`; creating a package is an architectural decision, so spec review asks about every unsettled detail, and the factory creates the packages before building.

### 0.4.0

- Workspaces: specs in a parent folder can span packages. Task groups name their package; each job gets a folder of worktrees for the packages it changes and links to the rest, so sibling path repositories resolve to the job's versions. Each package is built and gated with its own commands, in dependency order, with one PR per package. New `worktrees` command.
- The post-edit hook formats with the nearest package's own formatter (0.3.1).

### 0.3.0

- `ringside dashboard`: a live job board across one or more packages, served on 127.0.0.1 from the spec folders, with stage columns, per-reviewer verdicts, task progress, open questions, and a reader for each job's files. Deep links with `#job=<project>:<spec dir>`.

### 0.2.0

- Full flow: ticket, spec review, factory, decisions, post-mortem and standards discovery, with nine new skills and three workflows (`ring-master-spec-review`, `ring-master-factory`, `ring-master-post-mortem`) launched by path from `resources/workflows/`.
- Severity rubric (`architectural`, `blocker`, `major`, `minor`, `nit`): architectural findings always go to the user; everything else is fixed automatically; review rounds stop when only nits remain.
- Configurable review rounds, reviewers (built in and custom), and a model, effort and turn limit per role.
- Ticket trackers and code hosts as adapters: GitHub, Jira, Bitbucket and file out of the box; projects can add their own.
- Global standards are optional and explicit (`standards.global`); strict packages must ship architectural standards only; every standard has a `kind`.
- New commands: `ref`, `job`, `propose`, `cleanup`; `install --seed` and detection of commands, formatter and host.
- Public, stack-neutral defaults; no Refactor Circus assumptions in ringside itself.

### 0.1.0

- Composer plugin that syncs on install and update and does nothing when the fingerprint is unchanged.
- Standards resolution from every package with `extra.ring-master`, plus local overrides with reviewed hashes.
- Eight workflow skills, a standards skill per category, the `ring-master-implementer` subagent, Claude Code hooks and a managed `AGENTS.md` block.
- CLI: `install`, `sync`, `index --accept`, `check`, `specs`, `migrate`.
- Agent OS migration from `agent-os/`.
