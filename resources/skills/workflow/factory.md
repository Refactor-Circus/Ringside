---
name: ring-master-factory
description: Build an approved Ring Master spec with the factory workflow, then open the PR, ask the user's design questions, or report the escalation.
disable-model-invocation: true
argument-hint: "[spec id; defaults to the newest approved spec]"
---
{{marker}}

# Factory

1. Pick the spec: `$ARGUMENTS`, or the newest `approved` spec in `{{specs_dir}}/`. It needs `tasks.md`; if it's missing, run `/ring-master-create-tasks` first.
2. If `meta.yml` lists `new_packages`, create each one that doesn't exist yet: `vendor/bin/ringside new-package <name> --type=<type> --description="<description>" --feature=<f>...` (one `--feature` per feature). It runs `laravel package` (or `composer init`), makes the first commit on the base branch and adds the package to the workspace. Stop and show the error if it fails; never create a package the spec doesn't list.
2b. Run `vendor/bin/ringside job <spec> --json`. Stop and show the error if it fails (for example a dependency cycle in `tasks.md`). Show any `warnings`.
3. Set `status: building` in `meta.yml`, and comment on the ticket that the build started (tracker adapter `comment`).
4. Launch the Workflow tool with `scriptPath` = the absolute path of `{{workflows_dir}}/ring-master-factory.js` under the package root and `args` = the JSON object. Review rounds: up to {{rounds_max}}, stopping early when only nits remain. If workflows are unavailable (`disableWorkflows`), say so and fall back to `/ring-master-implement` then `/ring-master-verify-spec`.
5. Append the run to `meta.yml` `runs:` (`workflow`, `run_id`, `result`, `round`, today's date), and write `decision.md` with the status, reasons and nits.
6. Then, by `status`:
   - **approved**: draft the PR title (`<ticket key>: <spec title>`) and body (goal, acceptance criteria with their proving tests from the last `gate.md`, remaining nits, link to the ticket). Push the branch and open the PR with the host adapter, draft = {{draft_pr}}, base `{{base_branch}}`. Link the ticket (`link`, and `transition` to in-review). Set `status: in-review` and `pr:` in `meta.yml`.
   - **needs-decision**: set `status: needs-decision`, save the questions to `decision.md`, and run `/ring-master-decide`.
   - **escalated**: set `status: escalated`, show the reasons and findings, and offer `/ring-master-approve` or `/ring-master-rework`.

**Workspace jobs** (the JSON has `workspace`): the spec spans several packages, each its own repo. `workspace.packages` lists them in dependency order, and each has its own worktree in `workspace.dir`. On **approved**, for each package in that order: push `<branch>` from its worktree and open a PR in that package's repository with its host adapter. Each PR body names the job, the ticket, and the other PRs ("depends on <PR>" for packages it requires). Merge them in the same order. Record them all under `pr:` in `meta.yml` as a list. Remove the job folder after the post-mortem with `vendor/bin/ringside worktrees <spec> --remove`.

The workflow never pushes. Pushing and every tracker or host call happen here, in the user's session, where their credentials and MCP connections are live.

{{host_adapter}}

{{tracker_adapters}}
