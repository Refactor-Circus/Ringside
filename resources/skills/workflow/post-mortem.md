---
name: ring-master-post-mortem
description: Run the post-mortem for a finished Ring Master job and walk the user through the standards proposals it produces. Runs as a stage of a Ring Master job (from /ring-master-ship or /ring-master-inbox) or when the user asks; never start it on your own.
argument-hint: "[spec id]"
---
{{marker}}

# Post-mortem

Post-mortem stage: **{{stage_post_mortem}}**. Global standards: {{global_standards}}.

1. Pick the spec: `$ARGUMENTS`, or the newest `merged` spec. Its job branch is merged, so reopen its record first: `vendor/bin/ringside spec reopen <id> --json` makes a worktree on `ring-master/<id>-record` from `{{base_branch}}` and prints the spec's `path` there. Write everything this stage writes in that worktree (the standards and proposals below too, at the same paths inside it), and nothing in the main checkout. If the spec's status is still `in-review`, set it to `merged` there.
2. In this session, collect: the PR's review comments (host adapter `pr_comments`), the ticket's comments (tracker adapter `comments`), the `runs:` in `meta.yml`, the keys of proposals already queued in `{{agent_dir}}/standards-proposals/`, and rejected proposals from earlier `post-mortem.md` files ("Rejected proposals" sections).
3. Run `vendor/bin/ringside job <spec> --json`, add `comments: {pr, ticket}`, `runs`, `existing`, `rejected` to that object, and launch the Workflow tool with `scriptPath` = the absolute path of `{{workflows_dir}}/ring-master-post-mortem.js` and those `args`.
4. Show the summary from `post-mortem.md` in a few lines.
5. For each proposal, ask the user to accept, edit or reject it (AskUserQuestion; one at a time, with the evidence):
   - `target: project`: write `{{standards_dir}}/<key>.md` with front matter `description`, `tags`, `kind`, `status: proposed`, `from_spec: <id>`, `evidence: [...]`. When it amends a global standard, also add `overrides: <package>`. Commit the accepted ones on the record branch (discovery mode `{{discovery_project_mode}}`).
   - `target: global`: write `{{agent_dir}}/standards-proposals/global/<key>.md` with the text and evidence (discovery mode `{{discovery_global_mode}}`). With mode `pr` or `issue`, run `/ring-master-propose <key>`.
   - Record rejected ones in `post-mortem.md` under "Rejected proposals", so they aren't proposed again.
6. Set `status: closed`, commit the record (`vendor/bin/ringside spec commit <id> "post-mortem"`), push `ring-master/<id>-record` and open a PR with the host adapter, base `{{base_branch}}`: "<spec id>: post-mortem", its body the summary and the accepted standards. Comment the metrics and the post-mortem link on the ticket. Leave the record worktree: it holds the spec until that PR merges, and RingMaster's janitor removes it then (`vendor/bin/ringside cleanup` lists it).

{{spec_record}}

{{host_adapter}}

{{tracker_adapters}}
