---
name: ring-master-inbox
description: Work through RingMaster's queue: apply the user's answers so stopped jobs resume, then claim queued tasks and ship them, sending every question to RingMaster instead of asking here.
disable-model-invocation: true
argument-hint: "[--once | --answers | --task=<id> --session=<name>]"
---
{{marker}}

# Inbox

RingMaster holds the user's queue and the answers they gave there. You reach it through its MCP server, `ring-master`: `queue-status`, `pending-answers`, `next-task`, `claim-task`, `heartbeat`, `link-spec`, `release-task`. If those tools are not available, stop and tell the user to add the server once:

```bash
claude mcp add --scope user ring-master -- php {{board_app}}/artisan mcp:start ring-master
```

(and set `board: { app: <that folder> }` in `ring-master.yml` for the session summary).

Pass this folder's absolute path as `project_path` (a workspace root covers its packages), and use one session name on every call: the folder name and a short random suffix.

RingMaster's runner starts headless sessions with one of two arguments; follow it exactly and stop when that part is done:

- `--answers`: do step 1 only.
- `--task=<id> --session=<name>`: the runner already claimed that task for you under that session name. Skip `next-task` and `claim-task`: call `heartbeat` for it first (its response is the task, with `ship_input` and `project.path`), then do step 2 for that task only, and don't loop.

## 1. Apply answers first

Call `pending-answers`. For each spec it lists, in that spec's `project_path`:

- **decisions**: follow `/ring-master-decide` for that spec with these answers instead of asking: each becomes the next `D-xxx` entry (choice = `answer`, rationale = `note`, raised by = `source`, "answered in RingMaster"), comes out of `questions.yml`, and the stage named by its `source` runs again (step 6 there).
- **escalations**: `Approve as is` → follow `/ring-master-approve` (the user accepted it in RingMaster; don't ask again). `Send back with a note` → follow `/ring-master-rework` with the note.

Apply answers even when nothing is queued: they are what unblocks stopped jobs. Any stage you relaunch here also runs unattended (below).

## 2. Take the next task

Call `next-task`. If there is none, say the queue is empty and stop. Otherwise `claim-task`, change to the task's `project.path`, and follow `/ring-master-ship` with the task's `ship_input` as its argument, unattended:

- As soon as the job's spec folder exists, call `link-spec` with its id. From then on the task follows the spec's status.
- Call `heartbeat` when each stage starts, with the stage as `message`, so the claim stays alive and the timeline shows progress.
- Ship stopping because a stage waits on the user, or because the PR is open, is the normal end: leave the task as it is.
- If the work cannot be done as asked, `release-task` with `failed: true` and the reason. If you have to stop for any other reason, `release-task` with the reason so another session can take it.

## Unattended mode

The user is not watching this session; RingMaster is where they answer. Wherever a stage would ask them anything (AskUserQuestion, confirming a ticket draft, confirming the standards list, approving the spec):

1. Make sure the spec folder exists first (create it as the stage would, with the status it would have), so the question has a home.
2. Write each question to the spec's `questions.yml` (format in `/ring-master-decide`) with concrete options, your recommendation, the evidence in one line, and `source:` the stage to come back to: `ticket`, `shape`, `spec-review`, `approval`, `tasks` or `build`. Ask everything that stage needs in one go.
3. Set `status: needs-decision` in `meta.yml`, call `heartbeat` with "Waiting on you: <first question>", and move on to the next task.

Never answer an architectural question yourself, never merge, and never push or create anything outside the repo that the stage would not (a ticket waits for its `ticket` answer before it is created).

## Loop

Repeat 1 and 2 until `pending-answers` and `next-task` both come back empty, or stop after one pass with `--once`. Under `/loop`, this drains RingMaster hands-off.
