---
name: ring-master-write-spec
description: Write spec.md with numbered acceptance criteria and standards.md for a shaped Ring Master spec, then mark it approved.
disable-model-invocation: true
argument-hint: "[spec id; defaults to the newest shaping spec]"
---
{{marker}}

# Write the spec

Pick the spec: `$ARGUMENTS` when given, otherwise the newest folder in `{{specs_dir}}/` whose `meta.yml` has `status: shaping`. Read its `shape.md`, `references.md` and, if present, `decisions.md` (final answers; follow them). When `meta.yml` has a `ticket:`, link it at the top of `spec.md`.

## standards.md

List the standards that apply, without copying their content (agents read the files when needed):

```markdown
# Standards for <Feature>

| Standard | File | Source | Why it applies |
| --- | --- | --- | --- |
| architecture/actions-events | vendor/refactor-circus/standards/standards/architecture/actions-events.md | refactor-circus/standards@1.0.0 | New CreateImportAction |
```

Take path and source from `{{index_path}}`. Then fill `meta.yml` `standards:` with each standards package's version and commit, read from `vendor/composer/installed.json` (`version`, first 12 characters of `source.reference` or `dist.reference`):

```yaml
standards:
  refactor-circus/standards:
    version: 1.0.0
    reference: 3f9c2ab41e0d
```

## spec.md

```markdown
# <Feature>

## Goal
One or two sentences: the outcome, for whom.

## Acceptance criteria
1. Numbered, observable, testable statements. Each one will be proven by a test or a code reference in /ring-master-verify-spec.

## Design
Components, data, public API, config, events. Follow the listed standards; name any place the design departs from one and why.

## Out of scope
What this spec deliberately does not do.

## Risks and open questions
```

Keep it as short as the work allows. Every acceptance criterion must be checkable.

## New packages

If the work needs a package that doesn't exist yet (workspaces only), list it in `meta.yml`. Creating a package is an architectural decision, so every field the user hasn't settled in `decisions.md` stays open for `/ring-master-spec-review` to ask about:

```yaml
new_packages:
  - name: Ledger                 # StudlyCase folder and class name
    type: laravel                # laravel (from `laravel package`) or php (from `composer init`)
    description: Double-entry ledger for suite packages
    features: [config, migrations, facade]
    depends_on: [Keystone]       # workspace packages it requires
```

`/ring-master-factory` creates each one with `vendor/bin/ringside new-package` before the build.

## Approve

Show the user the acceptance criteria and the standards list, then set `status: written` in `meta.yml`. Next step: `/ring-master-spec-review`, which critiques the spec, asks the design questions and gets the user's approval. (If spec review is turned off, ask the user to approve here and set `status: approved`.)
