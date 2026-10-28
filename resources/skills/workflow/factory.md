---
name: ring-master-factory
description: Build an approved Ring Master spec with the factory workflow, then open the PR, ask the user's design questions, or report the escalation. Runs as a stage of a Ring Master job (from /ring-master-ship or /ring-master-inbox) or when the user asks; never start it on your own.
argument-hint: "[spec id; defaults to the newest approved spec] [--replay[=<commit>]]"
---
{{marker}}

# Factory

1. Pick the spec: `$ARGUMENTS`, or the newest `approved` spec in `{{specs_dir}}/`. A `building` spec is a run that stopped part-way: start it again the same way; ticked groups are skipped. It needs `tasks.md`; if it's missing, run `/ring-master-create-tasks` first.
2. If `meta.yml` lists `new_packages`, create each one that doesn't exist yet: `vendor/bin/ringside new-package <name> --type=<type> --description="<description>" --feature=<f>...` (one `--feature` per feature). It runs `laravel package` (or `composer init`), makes the first commit on the base branch and adds the package to the workspace. Stop and show the error if it fails; never create a package the spec doesn't list.
2b. Run `vendor/bin/ringside job <spec> --json` (with `--replay` in replay mode, below). Stop and show the error if it fails (for example a dependency cycle in `tasks.md`). Show any `warnings`.
3. Set `status: building` in `meta.yml`, and comment on the ticket that the build started (tracker adapter `comment`).
4. Launch the Workflow tool with `scriptPath` = the absolute path of `{{workflows_dir}}/ring-master-factory.js` under the package root and `args` = the JSON object. Review rounds: up to {{rounds_max}}, stopping early when only nits remain. If workflows are unavailable (`disableWorkflows`), say so and fall back to `/ring-master-implement` then `/ring-master-verify-spec`.
5. Append the run to `meta.yml` `runs:` (`workflow`, `run_id`, `result`, `round`, `variants_winner` when set, today's date), and write `decision.md` with the status, reasons and nits. If `meta.yml` has `variants:` (a rebuild with variants), remove it: it is for one run.
6. Then, by `status`:
   - **approved**: draft the PR title (`<ticket key>: <spec title>`) and body: when the result has `brief`, start with the contents of that `brief.md` (its first line `Risk: <level>` as **Risk: <level>**), then the acceptance criteria with their proving tests from the last `gate.md`, remaining nits, and the link to the ticket; without one, the goal first. Push the branch and open the PR with the host adapter, draft = {{draft_pr}}, base `{{base_branch}}`. When `meta.yml` already has `pr:` (a job sent back from review), push to that PR and update its body instead of opening another. Link the ticket (`link`, and `transition` to in-review). Set `status: in-review` and `pr:` in `meta.yml`.
     - **Broken consumers** (`impact` is not empty): the job still ships, but those packages fail their tests against it. Add a "Breaks" section to the PR body listing each consumer and its `summary`, and write them to `decision.md`. Then, when RingMaster's `ring-master` MCP tools are available (a RingMaster job), call `propose-task` once per broken consumer: `project` = its `folder`, `title` "Adapt <folder> to <spec title>", `body` = what broke (the `summary`, then the `output`) and that the fix must work with this job's PR once it merges, `reason` "<folder>'s tests fail against <spec id>", `waits_for_this: true` (the follow-up waits for this task's PR to merge) and `blocks: false` (this job goes on to review). Without RingMaster, show the user the broken consumers with their output and suggest a follow-up job in each.
   - **needs-decision**: set `status: needs-decision`, write every question to `questions.yml` with `source: build` (format in `/ring-master-decide`; keep entries already there; check each against precedent first, below), list them in `decision.md`, and run `/ring-master-decide`.
   - **escalated**: set `status: escalated`, show the reasons and findings, and offer `/ring-master-approve`, `/ring-master-rework`, or `/ring-master-rework --variants` (build it {{escalation_variants}} ways at once and keep the best). In RingMaster these are the answers `Approve as is`, `Send back with a note` and `Rebuild with variants`. A stage `gate` escalation whose reasons start with `gate could not run:` means the gate could not run a command (see **Gate** below): nothing in the code failed, so show those reasons first.

**Gate**: each round the gate agent runs the verify command, the format check and `ringside check` against the job worktree. The workflow rewrites each command so it names its own checkout: `composer <args>` becomes `composer --working-dir='<worktree>' <args>`, and `vendor/bin/<x> <args>` becomes `composer --working-dir='<worktree>' exec -- <x> <args>`. Any other command (`node --test ...`) runs as written, and so does one with an env prefix (`ENV=1 composer ...`) or one that already names a working dir (`-d`, `--working-dir`). The rewrite needs a `composer.json` and a Composer binary in the worktree: without them a `vendor/bin/` command cannot start, and the job escalates at stage `gate` instead of reworking.

A command that did not start (the runner refused it, or it ran in the wrong checkout) goes in the gate's `not_run` channel, with the command and the exact refusal text. A command that started and failed is never `not_run`: it is a red gate, reworked as before. A non-empty `not_run` escalates the job at once with stage `gate`, even when the gate also has findings or passed. There is one reason per entry, in the order the gate gave them: `gate could not run: <command>: <reason>`, with `<package>: ` before the command in a workspace job (for example `gate could not run: Atrium: composer --working-dir='/tmp/t' exec -- pest: runner refused: not in allowlist`). With variants, a variant whose gate could not run is dropped and the others go on; when none is left, the job escalates at stage `gate` with `variant <n>: ` before each command, and carries any pending build questions as `decisions`.

The escalated job keeps its worktree, its branch and the work and commits of earlier rounds. In the escalating round no rework ran and nothing was committed, and that round is not a red round: `gate_red_rounds` keeps the value it had before it. Fix what stopped the command (the runner's allowlist, the worktree's Composer setup) before you send the job back.

**Workspace jobs** (the JSON has `workspace`): the spec spans several packages, each its own repo. `workspace.packages` lists them in dependency order, and each has its own worktree in `workspace.dir`. On **approved**, for each package in that order: push `<branch>` from its worktree and open a PR in that package's repository with its host adapter. Each PR body names the job, the ticket, and the other PRs ("depends on <PR>" for packages it requires). Merge them in the same order. Record them all under `pr:` in `meta.yml` as a list. Remove the job folder after the post-mortem with `vendor/bin/ringside worktrees <spec> --remove`.

**Parallel groups** (`build.parallel` on, a single package with `build.worktree.mode: per-job`): each group of a wave builds in its own worktree (`<job worktree>--g<n>` on `<branch>--g<n>`) and an integrator merges them into the job branch before the gate. An `escalated` result with stage `integrate` lists the `conflicts`; the group worktrees stay for you to look at until `vendor/bin/ringside worktrees <spec> --remove-groups`.

**Variants** (`variants` in the job JSON above 1: `meta.yml` `variants:`, else `build.variants`; a single package with per-job worktrees only): the factory builds the whole job that many ways at once, each in `<job worktree>--v<n>` on `<branch>--v<n>` with its own approach and model (`roles.variant.models`), gates each, and the `judge` keeps one: it is fast-forwarded into the job branch, the rest are removed (`vendor/bin/ringside worktrees <spec> --remove-variants`), and the review rounds go on as usual. The result's `variants_winner` names it; the judge's reasons are in `variants.md` in the spec folder.

**Brief**: on approval the `briefer` writes `brief.md` in the spec folder for the human reviewer: a first line `Risk: low|medium|high`, then `## What changed`, `## Why`, `## Look at` (one to five `path:line` places to read closely), `## Tested` and `## Risks`. RingMaster ranks PRs by it.

**Replay mode** (`--replay`, optionally `--replay=<commit>` to start from that commit instead of `{{base_branch}}`): an evaluation run, used by RingMaster to score a change to the factory against real past jobs. Run `vendor/bin/ringside job <spec> --json --replay` and launch the workflow with that JSON plus `replay: true` and `replay_from: <commit>` (or null). It rebuilds every task group on `<branch>--replay` in `<job worktree>--replay`, writes its reports under `<spec folder>/replay/`, and skips the impact check and the brief. In replay mode skip steps 2, 3, 5 and 6 entirely: never push, never open or update a PR, never comment on the ticket, never propose tasks or call any RingMaster MCP tool, and never change `meta.yml`, `tasks.md`, `questions.yml` or `decision.md`. Instead, write `<spec folder>/replay.json` from the result:

```json
{"status": "approved", "rounds": 2, "gate_red_rounds": 1, "findings_left": 0, "nits": 3, "variants_winner": null, "reasons": ["gate green; only nits left"]}
```

(`findings_left` and `nits` are counts; `rounds` is the result's `rounds`, or its `round`, or 0 when it ended before review), then remove the replay worktree with `vendor/bin/ringside worktrees <spec> --remove-replay`.

{{precedent}}

The workflow never pushes. Pushing and every tracker or host call happen here, in the user's session, where their credentials and MCP connections are live.

{{host_adapter}}

{{tracker_adapters}}
