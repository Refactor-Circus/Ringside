---
name: file
kind: [tracker]
claims: ['^T-\d+$']
requires: {}
---
## File tracker (no external system)

Tickets are markdown files in `{{agent_dir}}/tickets/<KEY>.md`, keys `T-001`, `T-002`, ... in order. Use it when there is no remote, or offline.

| Operation | Do |
| --- | --- |
| `fetch(ref)` | read `tickets/<KEY>.md`: front matter `title`, `status`, `labels`; the body is the description |
| `create({title, body, labels})` | write the next key's file with `status: open` | 
| `comment(key, text)` | append `## Comment <date>` and the text |
| `comments(key)` | the `## Comment` sections |
| `link(key, pr_url)` | add `pr: <url>` to the front matter |
| `transition(key, state)` | set `status` |

Return `{key, url: <relative path>, title, body, labels, status}` for `fetch`.
