---
name: ring-master-propose
description: Send queued global standards proposals to the global standards package's repository as a PR or an issue.
disable-model-invocation: true
argument-hint: "[proposal key | --all]"
---
{{marker}}

# Propose global standards

Global standards packages: {{global_standards}}. Their standards are architectural only; a proposal of any other kind belongs in this package's own standards instead.

1. List the queued files in `{{agent_dir}}/standards-proposals/global/` and pick `$ARGUMENTS` (a key, or `--all`).
2. Run `vendor/bin/ringside propose <key> --repo=<path to a local checkout of the global package>` (ask the user for the path if `discovery.global.repo` isn't set). It copies the proposal into the checkout, adds its index entry with `kind: architectural`, and commits it on `proposal/<package>-<key>`.
3. Push that branch and open a PR on the global package's repository with its host adapter, or, if the user can't push there, open an issue with the proposal text instead. Put the evidence in the body.
4. Move the queued file into `{{agent_dir}}/standards-proposals/sent/` with the PR or issue URL in its front matter.
