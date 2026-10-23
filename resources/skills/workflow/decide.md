---
name: ring-master-decide
description: Ask the user the architectural questions a Ring Master run stopped on, record the answers, and relaunch.
disable-model-invocation: true
argument-hint: "[spec id]"
---
{{marker}}

# Decide

Architectural decisions belong to the user. Never answer them yourself.

1. Pick the spec: `$ARGUMENTS`, or the newest `needs-decision` spec. Read the open questions from `decision.md`.
2. For each question, use AskUserQuestion: the question, each option (the agents' recommendation first, marked recommended), and the evidence in one line. The user may answer with their own option.
3. Append each answer to `decisions.md` as the next `D-xxx` entry (question, choice, rationale, date, raised by). Never edit earlier entries.
4. If an answer changes scope, add a dated "Addendum" section to `spec.md` and, if needed, new tasks to `tasks.md`.
5. Comment the decisions on the ticket (tracker adapter `comment`).
6. Relaunch: a question from spec review goes back to `/ring-master-spec-review`; from the build or review, set `status: approved` and run `/ring-master-factory`. Finished task groups stay ticked, so the build resumes where it stopped.

{{tracker_adapters}}
