# Factory decision: needs-decision

Run `wf_01b24242-e2e` (2026-10-28), stage `prepare`, round 0, gate red rounds 0.

## Reasons

- The main checkout has an uncommitted change the build would touch: `docs/agent/specs/2026-10-27-1101-recover-build-group-no-result/meta.yml` (status `in-review` → `merged`; PR #4 is merged).

## Questions

- Q-018 (build): Commit or stash these main changes before the build? Recommended: commit them on main first, then build (precedent: Atrium, 2026-10-28).
