---
name: ring-master-rework
description: Send a Ring Master job back to the factory with the user's note as a must-fix finding. Runs as a stage of a Ring Master job (from /ring-master-ship or /ring-master-inbox) or when the user asks; never start it on your own.
argument-hint: "[spec id] [--variants] [note]"
---
{{marker}}

# Rework

1. Pick the spec and take the note from `$ARGUMENTS`; ask for the note if there is none (with `--variants` a note is optional).
2. **`--variants`** (the user answered an escalation with "Rebuild with variants"): set `variants: {{escalation_variants}}` in `meta.yml` and untick every task in `tasks.md` (`- [x]` back to `- [ ]`), so the factory builds the whole job {{escalation_variants}} ways at once on top of the job branch and its judge keeps the best. The factory clears `variants` when the run returns. With a note, also add it as below.
3. Add the note to `tasks.md` as a new group "Rework <n>: <short title>" (depends on the groups it touches, files if known, the criteria it serves) with the user's note as its task text. A note that is itself an architectural choice goes to `decisions.md` instead.
4. Set `status: approved`, commit the record (`vendor/bin/ringside spec commit <id> "sent back: <short title>"`), and run `/ring-master-factory`. A job sent back from review keeps its branch, worktree and `pr:`; the new commits land on the open PR.
