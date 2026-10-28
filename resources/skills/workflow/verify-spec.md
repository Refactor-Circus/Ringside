---
name: ring-master-verify-spec
description: Verify a Ring Master spec against its acceptance criteria and the full quality gate; mark it done only when everything passes. Runs as a stage of a Ring Master job (from /ring-master-ship or /ring-master-inbox) or when the user asks; never start it on your own.
argument-hint: "[spec id; defaults to the newest in-progress spec]"
---
{{marker}}

# Verify the spec

Pick the spec: `$ARGUMENTS` when given, otherwise the newest `in-progress` spec in `vendor/bin/ringside specs`; `vendor/bin/ringside spec path <id>` prints its folder. Read `spec.md` and `tasks.md`.

Run every check and record the result. Do not stop at the first failure.

| Check | How | Passes when |
| --- | --- | --- |
| Acceptance criteria | For each numbered criterion, find the test (file + test name) or code reference that proves it | every criterion has proof |
| Tasks | `tasks.md` | every box is ticked |
| Full gate | `{{verify_command}}` | exit 0 |
| Standards drift | `vendor/bin/ringside check` | exit 0 |

Write `verification.md`:

```markdown
# Verification: <Feature>

Date: <YYYY-MM-DD> · Result: PASS | FAIL

| Check | Result | Detail |
| --- | --- | --- |
| Criterion 1: <short text> | pass | tests/Feature/ImportTest.php "it imports a valid csv" |
| Full gate | fail | 2 PHPStan errors (output below) |

## Failures
<command output for every failing check>

## Tasks to reopen
- [ ] 2.2 <why>
```

When every check passes, set `status: done` in `meta.yml`. When anything fails, leave `status: in-progress`, untick the tasks to reopen in `tasks.md`, and tell the user what failed and how to resume (`/ring-master-implement <spec> <group>`). Either way, commit the record: `vendor/bin/ringside spec commit <id> "verified"` (or "verification failed").

{{spec_record}}
