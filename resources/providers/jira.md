---
name: jira
kind: [tracker]
claims: ['^[A-Z][A-Z0-9]+-\d+$', 'atlassian\.net/browse/[A-Z][A-Z0-9]+-\d+']
requires: { mcp: atlassian, env_fallback: ATLASSIAN_API_TOKEN }
---
## Jira (tracker)

Prefer the Atlassian (Rovo) MCP server: load its tools with ToolSearch (query `jira issue`), then use the issue tools it exposes. Use `tickets.providers.jira.site` and `.project` from ring-master.yml. If no Atlassian MCP tools are available, fall back to the Jira Cloud REST API v3 with `curl -u "$ATLASSIAN_EMAIL:$ATLASSIAN_API_TOKEN"`; if neither works, stop and tell the user which one to set up.

| Operation | With the MCP server | REST fallback |
| --- | --- | --- |
| `fetch(ref)` | get the issue by key | `GET /rest/api/3/issue/<KEY>` |
| `create({title, body, labels})` | create an issue in the configured project (type Task unless told otherwise) | `POST /rest/api/3/issue` |
| `comment(key, text)` | add a comment | `POST /rest/api/3/issue/<KEY>/comment` |
| `comments(key)` | list comments | `GET /rest/api/3/issue/<KEY>/comment` |
| `link(key, pr_url)` | add a remote link, or a comment with the PR URL | `POST /rest/api/3/issue/<KEY>/remotelink` |
| `transition(key, state)` | move to the matching workflow status if one exists (`In Progress`, `In Review`, `Done`); skip otherwise | `POST /rest/api/3/issue/<KEY>/transitions` |

Return `{key, url: https://<site>/browse/<KEY>, title, body, labels, status}` for `fetch`, with the description converted to plain markdown.
