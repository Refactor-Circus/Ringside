---
name: ring-master-create-tasks
description: Break an approved Ring Master spec into dependency-ordered task groups in tasks.md, tests first. Runs as a stage of a Ring Master job (from /ring-master-ship or /ring-master-inbox) or when the user asks; never start it on your own.
argument-hint: "[spec id; defaults to the newest approved spec]"
---
{{marker}}

# Create tasks

Pick the spec: `$ARGUMENTS` when given, otherwise the newest folder in `{{specs_dir}}/` whose `meta.yml` has `status: approved`. Read `spec.md` and `standards.md`.

Write `tasks.md` as task groups. A group is a slice one agent can finish in one sitting: usually one component and its tests.

```markdown
# Tasks: <Feature>

## Group 1: <name>
depends on: none
files: src/Domains/Import/Data/CreateImportData.php, tests/Unit/CreateImportDataTest.php
criteria: 1, 2

- [ ] 1.1 Write tests for <behavior>
- [ ] 1.2 Implement <thing>
- [ ] 1.3 Run {{test_command}}; all green

## Group 2: <name>
depends on: 1
files: ...
criteria: 3
```

Rules:

- The first task of every group writes its tests; the last runs `{{test_command}}`.
- In a workspace (ring-master.yml has `workspace.packages`), every group starts with `package: <folder>` naming the one package it changes, either an existing one or one listed under `new_packages` in `meta.yml` (the factory creates those first). Give a new package a first group that wires it into the suite (its dependencies on other workspace packages, `bin/composer-local.php` and the `local:install` scripts if the others have them, the shared arch preset), before groups that build features; split work that touches two packages into two groups, with the dependent package's group depending on the other.
- `depends on:` lists group numbers that must be done first, or `none`.
- `files:` lists every file the group creates or changes. Groups that could run in parallel must not share files.
- `criteria:` names the acceptance criteria from `spec.md` the group proves. Every criterion appears in at least one group.
- Use as few groups as the work allows; do not split a component across groups.

Show the user the group list (name, depends on, criteria). Next step: `/ring-master-implement`.
