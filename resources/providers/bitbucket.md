---
name: bitbucket
kind: [host, tracker]
claims: ['bitbucket\.org/[^/\s]+/[^/\s]+/issues/\d+']
requires: { mcp: atlassian, env_fallback: BITBUCKET_API_TOKEN }
---
## Bitbucket Cloud (host; tracker only when the repo uses Bitbucket issues)

Prefer the Atlassian (Rovo) MCP server: load its Bitbucket tools with ToolSearch (query `bitbucket pull request`). The workspace and repository come from the `origin` remote (`bitbucket.org/<workspace>/<repo>`). If no Bitbucket MCP tools are available, fall back to the REST API 2.0 with `curl -H "Authorization: Bearer $BITBUCKET_API_TOKEN"`; if neither works, stop and tell the user which one to set up.

| Operation | With the MCP server | REST fallback |
| --- | --- | --- |
| `push(branch)` | `git push -u origin <branch>` | same |
| `open_pr({title, body, head, base, draft})` | create a pull request from `head` to `base` | `POST /2.0/repositories/<ws>/<repo>/pullrequests` |
| `pr_status(number)` | get the pull request | `GET .../pullrequests/<id>` (`state`: OPEN, MERGED, DECLINED) |
| `pr_comments(number)` | list pull request comments, with inline path and line | `GET .../pullrequests/<id>/comments` |
| `fetch` / `create` / `comment` / `comments` (issues) | the issue tools, only if the repo has issues on | `.../issues` endpoints |

Bitbucket repos usually track work in Jira: ringside's `tickets.default: auto` picks `jira` for them. Link the ticket by putting its key (`PROJ-123`) in the branch name or PR title, which Jira's Bitbucket integration recognises, and also call the Jira adapter's `link`.
