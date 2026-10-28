---
name: ring-master-decide
description: Ask the user the architectural questions a Ring Master run stopped on, record the answers, and relaunch. Runs as a stage of a Ring Master job (from /ring-master-ship or /ring-master-inbox) or when the user asks; never start it on your own.
argument-hint: "[spec id]"
---
{{marker}}

# Decide

Architectural decisions belong to the user. Never answer them yourself.

1. Pick the spec: `$ARGUMENTS`, or the newest `needs-decision` spec. Read the open questions from `questions.yml` (older specs: from `decision.md`). If `questions.yml` is `[]` while the status is still `needs-decision`, the answers were recorded but the job never relaunched: skip to step 6 and relaunch from the newest `D-xxx` entries' raised-by.
2. For each question, use AskUserQuestion: the question, each option (the agents' recommendation first, marked recommended), and the evidence in one line. The user may answer with their own option. When `/ring-master-inbox` brings answers the user gave in RingMaster, use those as given and don't ask again.
3. Append each answer to `decisions.md` as the next `D-xxx` entry (question, choice, rationale, date, raised by). Never edit earlier entries. Then rewrite `questions.yml` without the answered entries (with the Write tool; `[]` when none are left).
4. If an answer changes scope, add a dated "Addendum" section to `spec.md` and, if needed, new tasks to `tasks.md`.
5. Comment the decisions on the ticket (tracker adapter `comment`).
6. Relaunch by each question's `source`, the stage it came from: `ticket` (create the ticket as drafted, or redraft) and `shape` go back to `/ring-master-shape-spec`'s next step; `spec-review` goes back to `/ring-master-spec-review <spec> --recheck=<the D-ids you just recorded>` (status `written`), which checks that those answers landed instead of reviewing the whole spec again; `approval` sets `status: approved` when the answer approves the spec, or revises `spec.md` and asks again; `tasks` goes back to `/ring-master-create-tasks`; `build` sets `status: approved` and runs `/ring-master-factory`. Finished task groups stay ticked, so the build resumes where it stopped.

`questions.yml` holds the open questions so that RingMaster can show them too:

```yaml
- id: Q-001                      # Q-001, Q-002, ... in the order raised
  question: One row per file or per line?
  options: [Per file, Per line]  # plain text, no A./B. labels
  recommendation: Per line       # the exact text of one option, never a letter or number
  evidence: src/Import.php:40    # where it came up, one line
  source: build                  # the stage to return to: ticket | shape | spec-review | approval | tasks | build
```

Quote every value that holds `#`, `: `, a comma or brackets (`question: "Rebase PR #8 first?"`), and write options containing any of them as a block list (`options:` then one `- "..."` per line): in a `[...]` list ` #` starts a comment and a comma splits the option, and a file that does not parse shows in RingMaster only as an error.

{{precedent}}

Asking here, show a precedent the evidence names first, with its option marked recommended; the user still chooses.

{{tracker_adapters}}
