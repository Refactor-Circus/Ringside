---
name: ring-master-shape-spec
description: Shape a new feature or change into a Ring Master spec folder (scope, decisions, references, matched standards). Runs as a stage of a Ring Master job (from /ring-master-ship or /ring-master-inbox) or when the user asks; never start it on your own.
argument-hint: "[what you want to build]"
---
{{marker}}

# Shape a spec

Turn an idea into a spec folder (`{{specs_dir}}/<id>/`, in the spec's own job worktree). Shaping is lightweight: capture scope, decisions and context, not the full design (that is `/ring-master-write-spec`).

Ask the user with the AskUserQuestion tool whenever you need input, and offer concrete options they can confirm or adjust.

## Steps

0. **Ticket.** If `$ARGUMENTS` is a spec id whose `meta.yml` has a `ticket:`, or a ticket ref, fetch the ticket with `/ring-master-ship`'s tracker adapter and use its title and description as the brief. Keep the existing spec folder; don't create a second one.
1. **Scope.** Start from `$ARGUMENTS` when given. Ask one or two clarifying questions only where the scope is unclear: new feature or change, expected outcome, hard constraints.
   **One package per spec.** A spec changes this package only, and lives in it. When the work also needs changes in other packages, shape only this package's part here, and give each other package its own spec in that package (from `/ring-master-inbox`, `propose-task` for each one, with `waits_for_this: true` when it needs this one merged first, or `blocks: true` when this one needs it first; otherwise tell the user which specs to shape next and in which order). Never plan changes to another package inside this spec.
2. **Product context.** If `{{product_dir}}/` has files, read `mission.md` and `roadmap.md` and note anything this work must align with.
3. **References.** Ask whether similar code exists in this package or a sibling package. Read what they point to and note the patterns worth reusing.
4. **Visuals.** Ask for mockups or screenshots only when the work has a UI surface.
5. **Standards.** Read `{{index_path}}`. Pick the standards whose description or tags fit this work, and confirm the list with the user. Read only the confirmed files; never load the whole index's files.
6. **Folder.** Unless the ticket step already made it, create the spec with `vendor/bin/ringside spec new "<Feature name>" --json`: it makes `<id>` (`{{spec_id_format}}`), the job worktree and branch that hold the spec, and the folder with a starting `meta.yml`, and prints its `path`. Write everything below in that folder.

## Write

- `meta.yml` (keep what `spec new` wrote; add the rest):

  ```yaml
  id: <id>
  title: <Feature name>
  status: shaping
  created: <YYYY-MM-DD>
  ticket: { provider: <name>, key: <key>, url: <url> }   # when there is one
  standards: {}        # filled by /ring-master-write-spec
  ```

- `shape.md`: `# <Feature> — Shaping Notes`, then `## Scope`, `## Decisions`, `## Context` (references, visuals, product alignment), `## Standards` (key + one line on why it applies).
- `references.md`: each reference with location, relevance and the pattern to borrow.
- `visuals/`: only when visuals were provided.

## Finish

Commit the record: `vendor/bin/ringside spec commit <id> "shaped"`. Tell the user the folder path and that the next step is `/ring-master-write-spec`.

{{spec_record}}
