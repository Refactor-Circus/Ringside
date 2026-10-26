---
name: ring-master-spec-review
description: Critique a written Ring Master spec with independent critics, fix what isn't architectural, ask the user the design questions, then get their approval. Runs as a stage of a Ring Master job (from /ring-master-ship or /ring-master-inbox) or when the user asks; never start it on your own.
argument-hint: "[spec id; defaults to the newest written spec] [--recheck=D-xxx,...]"
---
{{marker}}

# Spec review

1. Pick the spec: `$ARGUMENTS`, or the newest `{{specs_dir}}/*/meta.yml` with `status: written`.
2. Run `vendor/bin/ringside job <spec> --json` and keep the JSON.
3. Count the pass: set `review_passes` in `meta.yml` to its value plus one (0 when absent).
4. Launch the workflow with the Workflow tool: `scriptPath` = the absolute path of `{{workflows_dir}}/ring-master-spec-review.js` under the package root, `args` = that JSON (as an object, not a string) plus `pass` (the new `review_passes`) and `recheck` (the ids from `--recheck`, `[]` without it). Critics: {{spec_review_critics}}; rounds: {{spec_review_rounds}}. With `--recheck` it only checks that those answered decisions landed in `spec.md`, instead of critiquing the whole spec again. After {{spec_review_max_passes}} pass(es) it stops asking: what it would have asked comes back as `notes`. If workflows are unavailable, do the same yourself with the `ring-master-critic` subagent: one critic at a time, or one recheck of the listed decisions.
5. When it returns:
   - Show the user the non-architectural findings it already fixed (they're in `spec-review.md`).
   - If `notes` is not empty, append them to `spec-review.md` under "## Pass <n>: not asked (max_passes reached)", each with its options and recommendation, and show them to the user with the approval request. They don't block approval.
   - If `decisions` is not empty, write each entry to `questions.yml` with `source: spec-review` (format in `/ring-master-decide`), set `status: needs-decision` in `meta.yml`, and run `/ring-master-decide`. It asks the user, records the answers in `decisions.md` (format below) and updates `spec.md`, then brings you back here.
6. Show the final acceptance criteria and ask the user to approve the spec. On approval set `status: approved` in `meta.yml`. Next: `/ring-master-create-tasks`, then `/ring-master-factory`.

`decisions.md` entry (ids `D-001`, `D-002`, ... in order; never rewrite earlier entries):

```markdown
## D-001: <question>
- Choice: <answer>
- Rationale: <why, in the user's words>
- Date: <YYYY-MM-DD>
- Raised by: spec review (<critic>)
```
