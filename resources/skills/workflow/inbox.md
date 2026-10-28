---
name: ring-master-inbox
description: Work through RingMaster's queue: apply the user's answers so stopped jobs resume, then claim queued tasks and ship them, sending every question to RingMaster instead of asking here.
disable-model-invocation: true
argument-hint: "[--once | --answers | --task=<id> --session=<name>]"
---
{{marker}}

# Inbox

RingMaster holds the user's queue and the answers they gave there. You reach it through its MCP server, `ring-master`: `queue-status`, `pending-answers`, `next-task`, `claim-task`, `heartbeat`, `link-spec`, `release-task`, `propose-task`, `precedents`. If those tools are not available, stop and tell the user to add the server once:

```bash
claude mcp add --scope user ring-master -- php {{board_app}}/artisan mcp:start ring-master
```

(and set `board: { app: <that folder> }` in `ring-master.yml` for the session summary).

Pass this folder's absolute path as `project_path` (a workspace root covers its packages), and use one session name on every call: the folder name and a short random suffix.

RingMaster's runner starts headless sessions with one of two arguments; follow it exactly and stop when that part is done:

- `--answers`: do step 1 only, which includes running each stage the answers unblock, unattended, until it stops on its next question or the PR is open.
- `--task=<id> --session=<name>`: the runner already claimed that task for you under that session name. Skip `next-task` and `claim-task`: call `heartbeat` for it first (its response is the task, with `ship_input` and `project.path`), then do step 2 for that task only, and don't loop.

## 1. Apply answers first

Call `pending-answers`. For each spec it lists, in that spec's `project_path`:

- **decisions**: invoke the `ring-master-decide` skill for that spec (Skill tool) and give it these answers instead of asking: each becomes the next `D-xxx` entry (choice = `answer`, rationale = `note`, raised by = `source`, "answered in RingMaster"), comes out of `questions.yml`, and the stage named by its `source` runs again (step 6 there).
- **escalations**: `Approve as is` → invoke `ring-master-approve` (the user accepted it in RingMaster; don't ask again). `Send back with a note` → invoke `ring-master-rework` with the note. `Rebuild with variants` → invoke `ring-master-rework` with `--variants` and the note, if any: the factory builds the job several ways at once and keeps the best.

Apply answers even when nothing is queued: they are what unblocks stopped jobs. Relaunching the stage is part of applying them; it runs unattended (below).

## 2. Take the next task

Call `next-task`. If there is none, say the queue is empty and stop. Otherwise `claim-task`, change to the task's `project.path`, and invoke the `ring-master-ship` skill with the Skill tool, with the task's `ship_input` as its arguments, unattended. The task's `kind` already says whether `ship_input` is an idea, a ticket or a spec, so ship doesn't need `ringside ref` to classify it:

- As soon as the job's spec folder exists, call `link-spec` with its id. From then on the task follows the spec's status.
- Call `heartbeat` when each stage starts, with the stage as `message`, so the claim stays alive and the timeline shows progress.
- Ship stopping because a stage waits on the user, or because the PR is open, is the normal end: leave the task as it is.
- If the task's `resume_note` is set, do what it says first (it names a job this task waited on that is now done, and how to bring its change in).
- If the work cannot be done as asked, `release-task` with `failed: true` and the reason. If you have to stop for any other reason, `release-task` with the reason: a task without a spec goes back to the queue, one with a spec is paused until the user resumes it, so say what they must fix first.

## Follow-up jobs

When a job needs a separate one first (a fix in another package, a release, anything the user decided belongs in its own job), never start it yourself and never do it inside this job. Call `propose-task` with the task that needs it (your claimed task, or, applying answers, the spec's `task_ids` from `pending-answers`), the other package's folder name as `project`, a title, a `body` written as the task for a fresh session, and the `reason`. The user accepts or declines it in RingMaster's Inbox; your task waits, blocked, and resumes by itself once the follow-up is done or its PR is merged. The other way round, for work that must come after your job (a package your change breaks, which the factory's impact check reports): pass `waits_for_this: true` and `blocks: false`, so the follow-up waits for your task's PR to merge and your job goes on. Then `release-task` with the reason if you hold a claim, and stop work on that job.

Every stage is a skill you invoke with the Skill tool; this one is the only one the user has to start.

## Unattended mode

The user is not watching this session; RingMaster is where they answer. Wherever a stage would ask them anything (AskUserQuestion, confirming the standards list, approving the spec):

1. Make sure the spec folder exists first (create it as the stage would, with the status it would have), so the question has a home.
2. Write each question to the spec's `questions.yml` (format in `/ring-master-decide`) with concrete options, your recommendation (the exact text of one option, not a letter), the evidence in one line, and `source:` the stage to come back to: `ticket`, `shape`, `spec-review`, `approval`, `tasks` or `build`. Ask everything that stage needs in one go. Check each against `precedents` first (below).
3. Set `status: needs-decision` in `meta.yml`, call `heartbeat` with "Waiting on you: <first question>", and move on to the next task.

{{precedent}}

Run one command per Bash call (no `;`, `&&` or pipes): a session started by RingMaster's runner may only run the commands it allows, and a compound command is refused as a whole.

Never switch branches, commit or stash in a package's main checkout: other jobs build against it. A task that is not a factory job (a Dependabot fix, a small change) works in a worktree of its own beside the package (`git worktree add ../<Package>--<slug> -b <branch> origin/{{base_branch}}`, or the PR's branch), removed once it is merged.

Never answer an architectural question yourself, never merge, and never push or create anything outside the repo that the stage would not (unattended, the ticket stage creates its ticket as drafted without asking; see `/ring-master-ticket`).

## Loop

Repeat 1 and 2 until `pending-answers` and `next-task` both come back empty, or stop after one pass with `--once`. Under `/loop`, this drains RingMaster hands-off.
