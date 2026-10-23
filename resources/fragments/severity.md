## Severity rubric

Every finding gets exactly one severity. The severity decides what happens next, so pick the lowest one that is true.

| Severity | Use it when | What happens |
| --- | --- | --- |
| `architectural` | the change picks or changes a public contract, database schema, config shape, event or API surface, dependency set, or module boundary; creates a new package; or chooses between two viable designs the spec and decisions don't settle | the run stops and the user decides; never fixed automatically |
| `blocker` | tests, static analysis or the format check fail; an acceptance criterion is unmet; a security hole; data loss | fixed automatically |
| `major` | a correctness bug; a missing test for a criterion; a clear break of a standard (fix: follow the standard) | fixed automatically |
| `minor` | a convention slip, naming, duplication, readability | fixed automatically |
| `nit` | preference or polish | never starts a round; listed in the PR |

Rules:

- A question you can't settle goes in `decisions` (question, options, your recommendation, evidence), never in `findings`.
- Breaking a standard is `major`, fixed by following it. Use category `standard-deviation` only when following the standard is impossible or wrong for this case; that makes it a decision for the user.
- Decisions already recorded in `decisions.md` are final. Never raise them again.
- Every finding names its files, the standard key if one applies, and the fix.
