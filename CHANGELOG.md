# Changelog

## 0.8.0

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
