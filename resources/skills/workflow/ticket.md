---
name: ring-master-ticket
description: Shape an idea into a ticket with the user and create it in the configured tracker. Runs as a stage of a Ring Master job (from /ring-master-ship or /ring-master-inbox) or when the user asks; never start it on your own.
argument-hint: "[idea]"
---
{{marker}}

# Create a ticket

Default tracker: **{{default_tracker}}**.

1. From `$ARGUMENTS`, draft a ticket: title (under 70 characters), problem, desired outcome, acceptance hints, out of scope. Ask one or two questions with AskUserQuestion only where the idea is unclear.
2. Show the draft; adjust until the user confirms. Unattended (run by RingMaster's runner or `/ring-master-inbox`, which say so), skip the confirmation and create it as drafted: never stop to ask whether to create the ticket. Stop with a question only when the idea is too unclear to draft at all.
3. Create it with the tracker adapter's `create` (below). Keep the returned key and URL.
4. Create the spec: `vendor/bin/ringside spec new "<title>" --status=ticketed --json`. It makes `<id>` (`{{spec_id_format}}`), the job worktree and branch that hold the spec from now on, and the spec folder with a starting `meta.yml`, and prints them. Add the ticket to that `meta.yml` (at the printed `path`), so it reads:

   ```yaml
   id: <id>
   title: <title>
   status: ticketed
   created: <YYYY-MM-DD HH:MM>
   ticket: { provider: <name>, key: <key>, url: <url> }
   ```

5. Commit it: `vendor/bin/ringside spec commit <id> "ticketed"`. Next: `/ring-master-shape-spec <id>`.

{{spec_record}}

{{tracker_adapters}}
