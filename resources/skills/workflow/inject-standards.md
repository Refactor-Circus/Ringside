---
name: ring-master-inject-standards
description: Load specific Ring Master standards into context by key, category or tag.
disable-model-invocation: true
argument-hint: "[keys, categories or tags, e.g. testing/pest-style architecture]"
---
{{marker}}

# Inject standards

Standards skills already load by category when Claude works in a matching area. Use this to load an exact set on purpose.

1. Read `{{index_path}}`.
2. Match `$ARGUMENTS` against standard keys, categories and tags. With no arguments, ask which area the user is working on and suggest matches from the index.
3. Read each matched file at its indexed `path` and confirm the list (key and source) to the user in one line each.

Follow the loaded standards for the rest of the task.
