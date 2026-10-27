# Ringside

Ringside is the per-repo half of **Ring Master**: a spec-driven Claude Code workflow for PHP packages and projects: idea or ticket → spec → review → build → review rounds and gates → pull request → post-mortem → standards discovery. Architectural decisions are always yours; everything below that is fixed automatically.

Ringside is a Composer plugin and works on its own. The optional [RingMaster](https://github.com/Refactor-Circus/RingMaster) app adds a live board, a task queue and an inbox for the questions that need you. Shared standards come from Composer packages and are read from `vendor/`, never copied. Specs, product docs and your project's own standards live in your repo under `docs/agent/`.

## Install

```bash
composer require --dev refactor-circus/ringside
vendor/bin/ringside install
```

Allow the plugin when Composer asks (or add `"refactor-circus/ringside": true` to `config.allow-plugins`). From then on, every `composer install` and `composer update` re-syncs, and writes nothing when nothing changed.

`install` writes a short `ring-master.yml` with what it detects (your composer scripts, test runner, formatter and git host), creates `docs/agent/`, and adds the generated paths to `.gitignore`. If an installed package declares `extra.ring-master`, it is offered as global standards. An existing Agent OS install (`agent-os/`) is migrated. `install --seed` copies a global package's starter standards into your project.

### Coming from agent-kit

Ringside replaces `refactor-circus/agent-kit`. Swap the dependency (`composer remove --dev refactor-circus/agent-kit && composer require --dev refactor-circus/ringside`); the next sync, `install` or `vendor/bin/ringside migrate` renames `agent-kit.yml` to `ring-master.yml` and `.agent-kit/` to `.ring-master/`, replaces the generated `/agent-kit-*` skills, agents and hooks with `/ring-master-*` ones, and updates the `AGENTS.md` markers and `.gitignore`. Global standards packages may keep `extra.agent-kit` until 1.0; new ones use `extra.ring-master`.

## The flow

| Stage | Skill | You |
| --- | --- | --- |
| Entry | `/ring-master-ship <idea \| ticket \| spec>` | runs every enabled stage in order and resumes where a job stands |
| Ticket | `/ring-master-ticket` | confirm the ticket |
| Spec | `/ring-master-shape-spec`, `/ring-master-write-spec` | shape scope and acceptance criteria |
| Spec review | `/ring-master-spec-review` | answer the design questions; approve the spec |
| Tasks | `/ring-master-create-tasks` | — |
| Factory | `/ring-master-factory` | nothing, unless it asks |
| Decisions | `/ring-master-decide` | answer architectural questions; the run resumes |
| Escalations | `/ring-master-approve`, `/ring-master-rework` | accept as is, or send back with a note |
| Post-mortem | `/ring-master-post-mortem` | accept, edit or reject standards proposals |
| Global proposals | `/ring-master-propose` | review the PR on the global standards repo |
| RingMaster | `/ring-master-inbox` | queue work and answer questions in RingMaster instead |

Spec review, the factory and the post-mortem are [Claude Code workflows](https://code.claude.com/docs/en/workflows): scripts that orchestrate subagents in the background and return one result. They ship in `resources/workflows/` and the skills launch them by path. A workflow can't ask you anything mid-run, so each architectural question ends the run; your answer goes into the spec's `decisions.md` and the next run picks it up. After you answer spec review's questions it only rechecks that the answers landed, and after `max_passes` reviews it stops asking and lists what's left as notes for your approval. Tracker and host calls always run in your session, where your credentials and MCP connections are.

### The factory

Each round starts with the gate (your verify command, the format check, `ringside check`, and every acceptance criterion mapped to a test). A red gate is fixed automatically before reviewers spend time on broken code. Reviewers run in parallel and return findings with a severity:

| Severity | Handling |
| --- | --- |
| `architectural` | never fixed automatically; the run stops with `needs-decision` |
| `blocker`, `major`, `minor` | fixed automatically |
| `nit` | never starts a round; listed in the PR |

Rounds stop as soon as only nits remain, or at `review.rounds.max`, or when two rounds make no progress. A clean job opens a PR; nothing merges on its own.

## Workspaces

To work across several packages at once, install ringside in the folder that holds them and list them:

```yaml
# ring-master.yml in the parent folder
workspace:
  packages: [Keystone, Atrium, Cortex]
```

A spec there can change several packages: each task group names its package (`package: Atrium`). The factory gives the job its own folder (`.ring-master/jobs/<spec>/`) with a worktree on the job branch for every package it changes and a link to the main checkout of every other one, so a package's local path repositories (`../Keystone`) resolve to the job's versions. Each package is built, tested and gated with its own commands, in dependency order, and the job ends with one PR per package. `ringside worktrees <spec> [--setup|--remove]` manages the job folder.

A spec can also create a package. List it under `new_packages` in the spec's `meta.yml` (name, type, features, dependencies); spec review asks you to settle each of those, since a new package is an architectural decision. The factory then runs `ringside new-package`, which scaffolds a Laravel package with the Laravel installer's `laravel package` command (or a plain PHP one with `composer init`), commits it on `main` and adds it to the workspace, before building. Publishing its repository stays your step.

## RingMaster

[RingMaster](https://github.com/Refactor-Circus/RingMaster) is the optional app for all of this: a board of every job across your workspaces, a queue you add ideas, tickets and specs to, and an inbox where you answer the questions jobs stop on. Point a project (or a workspace root) at it:

```yaml
board: { app: ~/Projects/RingMaster }
```

and add its MCP server to Claude Code once:

```bash
claude mcp add --scope user ring-master -- php ~/Projects/RingMaster/artisan mcp:start ring-master
```

Sessions then start with a line like "RingMaster: 2 queued, 1 need you", and `/ring-master-inbox` works through it: it applies the answers you gave in RingMaster so stopped jobs resume, then claims queued tasks and ships them. Run unattended this way, every question a stage would ask you goes to RingMaster instead (into the spec's `questions.yml`), and the session moves on. Under `/loop` it drains the queue hands-off.

## Standards

| Layer | Holds | Lives in |
| --- | --- | --- |
| Global (optional) | architectural standards shared by many packages | Composer packages listed in `standards.global`, read from `vendor/` |
| Project | this project's own standards; may override a global one by key | `docs/agent/standards/` |

A global standards package is any Composer package with an `extra.ring-master` key and an `index.yml` (`category/name: {description, tags, kind, enforced_by}`). With `strict: true`, `ringside check` requires every global standard to be `kind: architectural`. A package may also ship a Pint config, a Pest arch preset and seed standards; ringside wires those in. [`refactor-circus/standards`](https://github.com/Refactor-Circus/Standards) is one such package.

Each standards category becomes a Claude Code skill, so Claude loads the right standards while it works. Overriding a global standard locally is fine; `check` asks you to review the override again whenever the global file changes (`ringside index --accept <key>`).

## Configuration

Every key in `ring-master.yml` is optional. The defaults:

```yaml
path: docs/agent
commands:                              # null skips a step
  test: vendor/bin/pest                # inside implementers
  verify: vendor/bin/pest              # the gate; e.g. composer test
  format: vendor/bin/pint
  format_check: vendor/bin/pint --test
  browser_test: null                   # setting it enables the ui / ux reviewers
standards:
  global: []                           # e.g. [{ package: acme/standards, strict: true }]
  exclude: []
tickets:
  default: auto                        # auto: GitHub repo -> github, Bitbucket repo -> jira, no remote -> file
  providers: {}                        # e.g. jira: { site: acme.atlassian.net, project: PKG }
host: { provider: auto, base: main, draft_pr: false }
stages:
  ticket: true
  shape: true
  spec_review: { enabled: true, critics: [scope, risk, testability], rounds: 1, max_passes: 2 }
  tasks: true
  factory: true
  post_mortem: on_merge                # on_merge | on_pr | manual | off
roles:                                 # model, effort, max_turns per role; every role names its model
  orchestrator: { model: sonnet }      # skills: ship, inbox, factory, spec review, decide, approve, rework, post-mortem, verify
  ticketer:    { model: sonnet }       # skill: ticket
  shaper:      { model: opus }         # skills: shape-spec, plan-product
  writer:      { model: opus }         # skill: write-spec
  planner:     { model: opus }         # skill: create-tasks
  prepare:     { model: haiku,  effort: low }
  implementer: { model: opus,   effort: high, max_turns: 40 }
  reworker:    { model: opus,   effort: high, max_turns: 40 }
  gate:        { model: sonnet, effort: low }
  reviewer:    { model: sonnet, effort: medium }
  triage:      { model: haiku,  effort: low, enabled: false }
  approver:    { model: sonnet, enabled: false }
  critic:      { model: sonnet, effort: medium }
  analyst:     { model: sonnet, effort: medium }
  discoverer:  { model: opus,   effort: high }
review:
  reviewers: [code, security, standards, compat]   # or { name: compat, model: opus }; custom: docs/agent/reviewers/<name>.md
  rounds: { max: 3, min: 1 }
  rework_threshold: minor
  auto_fix: [blocker, major, minor, nit]           # architectural is never allowed here
  stop_when_no_progress: 2
  gate: { run_first: true, max_consecutive_red: 3, criteria_mapping: true }
escalation:
  architectural_paths: [composer.json, config/**, database/migrations/**, '**/Contracts/**', .github/**]
  fix_others_first: true
build:
  parallel: false
  max_concurrency: 2
  worktree: { mode: per-job, path: '../{package}--{spec}', setup: auto, cleanup: on_close }   # auto: composer.local.json when present, else composer install; or a command
discovery:
  enabled: true
  project: { mode: ask }               # ask | branch | pr
  global: { mode: queue, repo: null, min_evidence: 1 }   # queue | pr | issue
hooks:
  post_edit_format: { enabled: true, command: 'vendor/bin/pint {file}' }
  session_start_summary: true
workspace: { packages: [], jobs_dir: .ring-master/jobs }   # set packages to work across several from a parent folder
board: { app: null }                     # the RingMaster app's folder; enables /ring-master-inbox and the session summary
new_package: { type: laravel, vendor: null, namespace: null, features: [config], laravel_bin: laravel, php: '^8.5' }
```

`ringside check` validates the file.

With `build.worktree.mode: per-job`, the factory sets up a single package's job worktree with `ringside worktrees <spec> --setup`. `setup: auto` copies the package's `composer.local.json` (and `composer.local.lock`) into the worktree when it has one, leaving a copy the worktree already holds alone, and runs `composer install` with `COMPOSER=composer.local.json`, so its local path repositories (`../Keystone`) resolve as in the main checkout; without one it runs a plain `composer install`. Any other `setup` value is a command run in the worktree as is.

## Trackers and hosts

Built-in adapters: `github` (tracker and host, via `gh`), `jira` (tracker, via the Atlassian MCP server or REST), `bitbucket` (host, via the Atlassian MCP server or REST), and `file` (tracker, markdown files in the repo). Add your own by dropping an adapter into `docs/agent/providers/<name>.md`: front matter `name`, `kind`, `claims` (regexes for its refs), and a body that tells the agent how to run each operation.

## CLI

| Command | Does |
| --- | --- |
| `install [--seed] [--pint] [--no-migrate]` | first-time setup |
| `sync [--force]` | rebuild the index, skills, subagents, hooks and the `AGENTS.md` block |
| `check` | CI gate: config valid, generated files current, overrides reviewed, global standards architectural, adapters and reviewers present |
| `ref <input>` | classify input as a ticket ref or an idea (JSON) |
| `job <spec> --json` | the workflow arguments for a spec |
| `specs` | list specs with status and progress |
| `index --accept <key>` | record a reviewed override |
| `propose <key> --repo=<path>` | commit a global standards proposal on a branch of the global package |
| `cleanup [--remove]` | list or remove finished job worktrees |
| `worktrees <spec> [--setup\|--remove]` | create or reuse a job's worktree and, with `--setup`, install its dependencies; workspaces: a folder of package worktrees, which `--remove` removes |
| `new-package <Name> [--type] [--feature=...] [--dry-run]` | workspaces: scaffold, git init and register a new package |
| `migrate [--dry-run]` | move an Agent OS install into `docs/agent/` |

Workflow subagents use your permission rules. To avoid prompts mid-run, allow `Bash(composer *)`, `Bash(git *)`, `Bash(vendor/bin/*)`, `Edit`, `Write` and `Workflow`. If workflows are disabled for you, the skills fall back to the sequential `/ring-master-implement` and `/ring-master-verify-spec`.

## Requirements

PHP 8.5+, Composer 2, Claude Code with workflows for the factory, spec review and post-mortem. Node is only needed to run ringside's own workflow tests.
