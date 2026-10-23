---
name: ring-master-rework
description: Send a Ring Master job back to the factory with the user's note as a must-fix finding.
disable-model-invocation: true
argument-hint: "[spec id] [note]"
---
{{marker}}

# Rework

1. Pick the spec and take the note from `$ARGUMENTS`; ask for the note if there is none.
2. Add the note to `tasks.md` as a new group "Rework <n>: <short title>" (depends on the groups it touches, files if known, the criteria it serves) with the user's note as its task text. A note that is itself an architectural choice goes to `decisions.md` instead.
3. Set `status: approved` and run `/ring-master-factory`.
