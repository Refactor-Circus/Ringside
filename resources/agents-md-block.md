## Ring Master

- Specs, product docs and local standards live in `{{agent_dir}}/`. Shared standards come from `vendor/` through `{{index_path}}`; read the matching standards before changing code.
- Workflow: `/ring-master-shape-spec` → `/ring-master-write-spec` → `/ring-master-create-tasks` → `/ring-master-implement` → `/ring-master-verify-spec`.
- Done means `{{verify_command}}` passes. When work must break a standard, add a row to `{{feedback_path}}`.
- Keep this checkout on `{{base_branch}}`: other packages' jobs build against it. Never switch branches, commit or stash here; do any change that is not a factory job (which makes its own) in a worktree beside the package: `git worktree add ../<Package>--<slug> -b <branch> origin/{{base_branch}}`, removed once it is merged.
