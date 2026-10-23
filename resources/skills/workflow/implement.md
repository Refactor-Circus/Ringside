---
name: ring-master-implement
description: Implement a Ring Master spec's task groups with one fresh-context subagent per group, running tests after each. Runs as a stage of a Ring Master job (from /ring-master-ship or /ring-master-inbox) or when the user asks; never start it on your own.
argument-hint: "[spec id] [group number]"
---
{{marker}}

# Implement

Pick the spec from `$ARGUMENTS` (or the newest `approved` / `in-progress` spec in `{{specs_dir}}/`) and optionally one group number. Read `meta.yml`, `tasks.md` and `standards.md`. Set `status: in-progress` in `meta.yml` before the first group.

Settings for this package: run tests after each group: **{{run_tests}}**, parallel waves: **{{parallel}}** (max {{max_concurrency}} at once).

## Order

Group the unfinished task groups into waves by `depends on:`. A group is ready when every group it depends on has all boxes ticked.

## Each group

1. Dispatch the `ring-master-implementer` subagent with: the spec folder path, the group's text from `tasks.md`, the `spec.md` sections for its `criteria:`, and the standard file paths from `standards.md` that apply. Do not paste whole files the subagent can read itself.
2. **Sequential** (parallel waves: no): tell the subagent to run `{{test_command}}` itself and finish green.
   **Parallel** (parallel waves: yes): dispatch up to {{max_concurrency}} ready groups whose `files:` do not overlap, tell each **not** to run tests, then run `{{test_command}}` yourself once the wave returns. Running tests inside parallel subagents races on Testbench's shared `bootstrap/cache`.
   Always work in this tree; never use a worktree (`vendor/` is not in it).
3. Review the report and the diff. If tests fail, fix them or send the group back with the failing output. Never tick a box for work that is not done.
4. Tick the group's boxes in `tasks.md`.
5. For every reported deviation, add a row to `{{feedback_path}}`: date, spec id, standard key, what was done instead, why.

When the user named a single group, stop after it. Otherwise continue until every group is done, then run `/ring-master-verify-spec`.

If a subagent reports it is blocked, stop and show the user the failing output and what was tried.
