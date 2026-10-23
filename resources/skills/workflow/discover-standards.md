---
name: ring-master-discover-standards
description: Find conventions in this package's code (and logged deviations) and write them up as local Ring Master standards.
disable-model-invocation: true
argument-hint: "[area to focus on, e.g. testing or src/Domains/Import]"
---
{{marker}}

# Discover standards

Turn the conventions this package actually follows into local standards under `{{standards_dir}}/`.

1. Read `{{index_path}}` so you do not duplicate a shared standard, and read `{{feedback_path}}`: repeated deviations are candidates for a changed or new standard.
2. Study the code in `$ARGUMENTS` (or ask which area). Look for patterns that repeat across several files and that a newcomer would get wrong: naming, layering, error handling, test structure.
3. Propose the candidates to the user (AskUserQuestion), one line each, and ask which to write.
4. Write each as `{{standards_dir}}/<category>/<name>.md`:

   ```markdown
   ---
   description: One line, specific enough to match on (max ~300 characters)
   tags: [category, topic]
   kind: convention    # architectural | convention | tooling
   ---
   # <Title>

   The rule in one sentence.

   - Concrete rules with the reason where it is not obvious.
   - A short code example from this package.
   ```

   To change a shared standard for this package only, use the same `<category>/<name>` path as the vendor file and add `overrides: <standards package>` to the front matter; then run `vendor/bin/ringside index --accept <category>/<name>` once you have reviewed it.
5. Run `vendor/bin/ringside sync` so the index and skills pick the new files up.

Package standards apply to this package only. An architectural rule that would help every package belongs in the global standards ({{global_standards}}): write it to `{{agent_dir}}/standards-proposals/global/<key>.md` and use `/ring-master-propose`.
