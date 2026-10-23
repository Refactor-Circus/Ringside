---
name: ring-master-shape-spec
description: Shape a new feature or change into a Ring Master spec folder (scope, decisions, references, matched standards).
disable-model-invocation: true
argument-hint: "[what you want to build]"
---
{{marker}}

# Shape a spec

Turn an idea into a spec folder under `{{specs_dir}}/`. Shaping is lightweight: capture scope, decisions and context, not the full design (that is `/ring-master-write-spec`).

Ask the user with the AskUserQuestion tool whenever you need input, and offer concrete options they can confirm or adjust.

## Steps

0. **Ticket.** If `$ARGUMENTS` is a spec id whose `meta.yml` has a `ticket:`, or a ticket ref, fetch the ticket with `/ring-master-ship`'s tracker adapter and use its title and description as the brief. Keep the existing spec folder; don't create a second one.
1. **Scope.** Start from `$ARGUMENTS` when given. Ask one or two clarifying questions only where the scope is unclear: new feature or change, expected outcome, hard constraints.
2. **Product context.** If `{{product_dir}}/` has files, read `mission.md` and `roadmap.md` and note anything this work must align with.
3. **References.** Ask whether similar code exists in this package or a sibling package. Read what they point to and note the patterns worth reusing.
4. **Visuals.** Ask for mockups or screenshots only when the work has a UI surface.
5. **Standards.** Read `{{index_path}}`. Pick the standards whose description or tags fit this work, and confirm the list with the user. Read only the confirmed files; never load the whole index's files.
6. **Folder.** Create `{{specs_dir}}/<id>/` where `<id>` follows `{{spec_id_format}}` with the current local time: `YYYY-MM-DD`, `HHMM`, and a lowercase hyphenated slug of at most 40 characters.

## Write

- `meta.yml`:

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

Tell the user the folder path and that the next step is `/ring-master-write-spec`.
