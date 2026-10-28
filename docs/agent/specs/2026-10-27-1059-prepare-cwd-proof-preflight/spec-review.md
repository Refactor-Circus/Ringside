## Round 1

Changed in spec.md:
- AC1 reworded: no prompt runs `vendor/bin/ringside`; Prepare, variant, group and removal calls use `composer --working-dir=${job.root} exec ringside --`; gate prompts keep their worktree dir.
- AC4: named the exact preflight commands, the source of the file list (`tasks.md`), the match rule (exact path or directory prefix), that untracked files count, the own-spec-folder exclusion, and that a group with no files matches nothing.
- AC5: no build or group call; Prepare returns `ready=false` without `worktrees --setup` when dirty or no remote (no leftover worktree or branch); resume re-runs the preflight.
- AC6: exact ASK text; "opens a PR" now follows the script's push condition (not a replay and a host is configured); dirty plus no remote raises only the dirty ASK.
- AC7/8: cover both gate prompts; the log names the missing file where known.
- AC9: names the checkable content, asserted in `WorkflowScriptsTest`.
- AC10 expanded: captured prompts and logs; all ringside prompts, never-cd, preflight command order, schema, ASK text, prefix case, both gates, clean path, replay, legacy omitted fields, workspace prompt.
- Design: safe-default fallback limited to replay or legacy results.
- Out of scope: replay path, `ringside job` JSON and PHP, remote reachability or auth, impact checks. standards.md no longer mentions PHP feature tests.
- Risks: dirty main means this job is expected to need-decision at prepare; dropped `ringside check` here is intended; the true check path is covered only by simulations; composer-on-PATH assumption.

Left alone (open questions): workspace behaviour across packages, non-replay handling of omitted fields, who decides `check_applies`, who computes the `dirty` overlap. Findings that depend on them (script-side deterministic applicability, fail-closed on omitted fields, per-package workspace checks, moving overlap into the script) are deferred to those decisions.
