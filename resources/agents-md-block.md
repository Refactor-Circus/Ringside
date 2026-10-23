## Ring Master

- Specs, product docs and local standards live in `{{agent_dir}}/`. Shared standards come from `vendor/` through `{{index_path}}`; read the matching standards before changing code.
- Workflow: `/ring-master-shape-spec` → `/ring-master-write-spec` → `/ring-master-create-tasks` → `/ring-master-implement` → `/ring-master-verify-spec`.
- Done means `{{verify_command}}` passes. When work must break a standard, add a row to `{{feedback_path}}`.
