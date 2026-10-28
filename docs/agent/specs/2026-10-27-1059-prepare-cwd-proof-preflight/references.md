# References

- `resources/workflows/ring-master-factory.js:327` and `:418` — existing `composer --working-dir=<path> exec ringside` calls, the pattern to apply everywhere. Note the comment "not vendor/bin/ringside by path, which the runner refuses".
- `resources/workflows/ring-master-factory.js:183-190` — Prepare prompt, `PREPARED` schema at line 60; where the preflight fields go.
- `resources/workflows/ring-master-factory.js:216`, `:287`, `:308` — variant, group and merge-back calls still using `vendor/bin/ringside`.
- `resources/workflows/ring-master-factory.js:234`, `:327` — where the gate runs `ringside check`; the `check_applies` switch goes here.
- `resources/skills/workflow/factory.md` — prose mirror of the script.
- `tests/workflows/factory.test.mjs` — simulations to extend (dirty main, no remote, `check_applies` false).
- `docs/agent/specs/2026-10-25-1349-worktree-local-manifest-install/post-mortem.md` — follow-up 3 and the decisions that motivated this.
