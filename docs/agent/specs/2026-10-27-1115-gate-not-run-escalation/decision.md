# Factory decision: needs-decision

Run `wf_3ad6c8ee-abf` (2026-10-28), stage `review`, round 1, gate red rounds 0.

## Reasons

- Rework 1 (the `-d` nit) was built: task 4.1 is ticked. The compat reviewer raised one design question, below. No findings and no nits are left.

## Questions

- Q-017 (compat): the factory workflow now throws at start when `args.workflows_dir` is missing. Keep the hard failure (recommended), or fall back to a path taken from the script's own location?
