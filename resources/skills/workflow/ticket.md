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
4. Create the spec folder `{{specs_dir}}/<id>/` (`<id>` follows `{{spec_id_format}}` with the current local time and a slug of the title) with `meta.yml`:

   ```yaml
   id: <id>
   title: <title>
   status: ticketed
   created: <YYYY-MM-DD>
   ticket: { provider: <name>, key: <key>, url: <url> }
   ```

5. Next: `/ring-master-shape-spec <id>`.

{{tracker_adapters}}
