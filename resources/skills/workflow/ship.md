---
name: ring-master-ship
description: Take an idea or an existing ticket all the way through Ring Master, from ticket to PR to post-mortem, resuming wherever the job stands. Start it only when the user asks to ship something, or from /ring-master-inbox.
argument-hint: "[idea | ticket key or URL | spec id]"
---
{{marker}}

# Ship

The single entry point. Work out where the job stands, run the next enabled stage, and keep going until a stage needs the user or the job is closed.

Enabled stages in this package: ticket **{{stage_ticket}}**, shape **{{stage_shape}}**, spec review **{{stage_spec_review}}**, tasks **{{stage_tasks}}**, factory **{{stage_factory}}**, post-mortem **{{stage_post_mortem}}**.

## 1. Work out where the job stands

1. If `$ARGUMENTS` names a spec in `vendor/bin/ringside specs` (full or partial id), resume that spec from its `meta.yml` status (table below).
2. Otherwise, unless the caller already said what it is (a RingMaster task's `kind`), run `vendor/bin/ringside ref "$ARGUMENTS"`. It prints JSON: `{"kind": "ticket" | "idea", "provider", "ref"}`.
   - **ticket**: search the specs' `meta.yml` files for that ticket key (`vendor/bin/ringside spec path <id>` prints each folder). Found: resume that spec. Not found: fetch the ticket with the provider's adapter (below), then go to shaping with the ticket as the brief.
   - **idea**: with ticket stage on, run `/ring-master-ticket` first; otherwise go straight to shaping with the idea as the brief.
3. With no arguments, run `vendor/bin/ringside specs` and offer the open specs (AskUserQuestion).

| `meta.yml` status | Next |
| --- | --- |
| `ticketed` | `/ring-master-shape-spec` |
| `shaping` | finish shaping, then `/ring-master-write-spec` |
| `written` | `/ring-master-spec-review` (or set `approved` when spec review is off and the user confirms) |
| `approved` | `/ring-master-create-tasks` if `tasks.md` is missing, then `/ring-master-factory` |
| `building` | the factory stopped mid-run (a resumed job): `/ring-master-factory` again; finished groups stay ticked, so it carries on where it stopped |
| `needs-decision` | `/ring-master-decide` |
| `escalated` | show the reasons; offer `/ring-master-approve` or `/ring-master-rework` |
| `in-review` | check the PR with the host adapter's `pr_status`; merged → `/ring-master-post-mortem`, which reopens the record and sets `merged` first |
| `merged` | `/ring-master-post-mortem` (when the post-mortem stage is `on_merge` or `manual`) |
| `closed` | nothing to do; say so |

## 2. Run stages in order

Invoke each next stage's skill with the Skill tool (for example `ring-master-shape-spec` with the spec id as its arguments), one after another, stopping when a stage needs the user (a question, a sign-off, an escalation) or when the PR is open. Tell the user in one line what stage you're on. Run every enabled stage, however small the job looks: whether a stage is worth it is the user's call, made in `ring-master.yml`, never yours.

## Providers

{{tracker_adapters}}

{{host_adapter}}
