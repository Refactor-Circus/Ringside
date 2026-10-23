# Changelog

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
