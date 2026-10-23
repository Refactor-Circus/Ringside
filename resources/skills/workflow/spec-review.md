---
name: ring-master-spec-review
description: Critique a written ringside spec with independent critics, fix what isn't architectural, ask the user the design questions, then get their approval.
disable-model-invocation: true
argument-hint: "[spec id; defaults to the newest written spec]"
---
{{marker}}

# Spec review

1. Pick the spec: `$ARGUMENTS`, or the newest `{{specs_dir}}/*/meta.yml` with `status: written`.
2. Run `vendor/bin/ringside job <spec> --json` and keep the JSON.
3. Launch the workflow with the Workflow tool: `scriptPath` = the absolute path of `{{workflows_dir}}/ring-master-spec-review.js` under the package root, `args` = that JSON (as an object, not a string). Critics: {{spec_review_critics}}; rounds: {{spec_review_rounds}}. If workflows are unavailable, run the critics yourself one at a time with the `ring-master-critic` subagent.
4. When it returns:
   - Show the user the non-architectural findings it already fixed (they're in `spec-review.md`).
   - For each entry in `decisions`, ask the user with AskUserQuestion: the question, the options, and the critic's recommendation first. Append each answer to `decisions.md` (format below), then update `spec.md` to match.
5. Show the final acceptance criteria and ask the user to approve the spec. On approval set `status: approved` in `meta.yml`. Next: `/ring-master-create-tasks`, then `/ring-master-factory`.

`decisions.md` entry (ids `D-001`, `D-002`, ... in order; never rewrite earlier entries):

```markdown
## D-001: <question>
- Choice: <answer>
- Rationale: <why, in the user's words>
- Date: <YYYY-MM-DD>
- Raised by: spec review (<critic>)
```
