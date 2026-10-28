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

Rounds stop as soon as only nits remain, or at `review.rounds.max`, or when two rounds make no progress. A role with `escalate_model` moves up to it when things go wrong: the gate after a red gate, the reworker after a red gate or when the same major findings come back a round later. A clean job opens a PR; nothing merges on its own.

With `build.parallel` on, the groups of a wave build at once. In a single package with per-job worktrees, each group gets its own worktree (`<job worktree>--g<n>` on `<branch>--g<n>`), so groups never fight over one checkout, and the integrator merges them into the job branch before the gate.

Before it approves, the impact check runs the tests of every package that requires the changed ones (`impact.consumers`) against the job, each in a throwaway worktree of the consumer installed with the job's code. A failing consumer never stops the job: the PR lists it, and under RingMaster each one becomes a proposed follow-up job that waits for this PR to merge.

A hard job can be built several ways at once. With `build.variants` above 1 (or `variants: N` in the spec's `meta.yml`, up to 4), the factory builds the whole job N times in parallel, each in its own worktree (`<job worktree>--v<n>` on `<branch>--v<n>`) with its own approach (simplest; closest to the existing patterns; most robust; a different approach) and model (`roles.variant.models`). Each is gated, then the `judge` keeps the best (a green gate, every criterion proven, least risk, then the smaller diff). It is fast-forwarded into the job branch and the review rounds go on as usual. Answering an escalation with "Rebuild with variants" (`/ring-master-rework --variants`) does this for one run with `build.escalation_variants` (3). Single packages with per-job worktrees only.

When it approves, the `briefer` writes `brief.md` for whoever reviews the PR: a first line `Risk: low|medium|high`, then **What changed**, **Why**, **Look at** (one to five `path:line` places to read closely), **Tested** and **Risks**. The PR body starts with it.

`/ring-master-factory <spec> --replay[=<commit>]` replays a job as an evaluation: it rebuilds every task group on `<branch>--replay` in `<job worktree>--replay` (from the commit, or the base branch), writes its reports under `<spec>/replay/`, never pushes, opens a PR, proposes a task or touches the spec's status, and ends by writing `<spec>/replay.json` (`status`, `rounds`, `gate_red_rounds`, `findings_left`, `nits`, `variants_winner`, `reasons`). RingMaster uses it to score a change to the factory against real past jobs before you adopt it.

Under RingMaster, every skill that raises an architectural question first asks RingMaster's `precedents` tool for answers you gave to similar ones. A close match (similarity 0.5 or more) becomes the recommendation and the first line of the evidence ("Precedent: you chose …"), and you are still asked: a past answer is never applied for you.

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
  implementer: { model: opus,   effort: high, max_turns: 80 }
  integrator:  { model: opus,   effort: high }                  # merges parallel group worktrees
  reworker:    { model: opus,   effort: high, max_turns: 80, escalate_model: opus }   # escalate_model: null turns escalation off
  gate:        { model: sonnet, effort: low, escalate_model: opus }
  reviewer:    { model: sonnet, effort: medium }
  triage:      { model: haiku,  effort: low, enabled: true }    # dedupes findings when there is more than one
  impact:      { model: sonnet, effort: low }                   # runs each consumer's tests against the job
  variant:     { models: [opus, opus, sonnet, opus] }           # the implementer model of variant 1, 2, 3, 4
  judge:       { model: opus,   effort: high }                  # keeps the best variant
  briefer:     { model: sonnet, effort: low }                   # writes brief.md before the PR
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
  parallel: true                         # groups of a wave build at once (own worktrees in a per-job package)
  max_concurrency: 2
  variants: 1                            # build the whole job this many ways at once (up to 4) and keep the best; meta.yml variants: wins
  escalation_variants: 3                 # what "Rebuild with variants" uses
  worktree: { mode: per-job, path: '../{package}--{spec}', setup: auto, cleanup: on_close }   # auto: composer.local.json when present, else composer install; or a command
impact:
  enabled: true
  consumers: auto                      # auto: folders beside this one (and workspace packages) that require it; a list of folders; off
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

The `implementer` and `reworker` get 80 turns by default (they had 40), so a build group has room to commit and return its result. A `max_turns` your `ring-master.yml` already sets, published from this example or overridden, stays as it is.

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
| `job <spec> --json [--replay]` | the workflow arguments for a spec (`variants`, `brief`, `impact` included); `--replay` for a replay run |
| `specs` | list specs with status and progress |
| `index --accept <key>` | record a reviewed override |
| `propose <key> --repo=<path>` | commit a global standards proposal on a branch of the global package |
| `cleanup [--remove]` | list or remove finished job worktrees |
| `worktrees <spec> [--setup\|--remove\|--groups=1,3\|--remove-groups\|--variants=N\|--remove-variants\|--replay [--from=<commit>]\|--remove-replay]` | create or reuse a job's worktree and, with `--setup`, install its dependencies; `--groups` makes a worktree per task group from the job branch and `--variants=N` one per variant (2 to 4), the `--remove-…` options remove them and their branches; `--replay` acts on the replay worktree instead (from `--from`, default the base branch), `--remove-replay` removes it; workspaces: a folder of package worktrees, which `--remove` removes |
| `impact <spec> [--package=<Folder>] [--list] [--json]` | run each consuming package's tests against the job worktree in a throwaway worktree of its own (exits 0 once the checks ran; the results say which pass), or list the consumers |
| `new-package <Name> [--type] [--feature=...] [--dry-run]` | workspaces: scaffold, git init and register a new package |
| `migrate [--dry-run]` | move an Agent OS install into `docs/agent/` |

Workflow subagents use your permission rules. To avoid prompts mid-run, allow `Bash(composer *)`, `Bash(git *)`, `Bash(vendor/bin/*)`, `Edit`, `Write` and `Workflow`. If workflows are disabled for you, the skills fall back to the sequential `/ring-master-implement` and `/ring-master-verify-spec`.

## Requirements

PHP 8.5+, Composer 2, Claude Code with workflows for the factory, spec review and post-mortem. Node is only needed to run ringside's own workflow tests.
