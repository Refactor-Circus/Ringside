---
name: github
kind: [tracker, host]
claims: ['^#\d+$', '^[\w.-]+/[\w.-]+#\d+$', 'github\.com/[^/\s]+/[^/\s]+/(issues|pull)/\d+']
requires: { cli: gh, auth: 'gh auth status' }
---
## GitHub (tracker + host)

Use the `gh` CLI from the package root; it infers the repository from the `origin` remote. Check `gh auth status` once; if it fails, stop and tell the user to run `gh auth login`.

| Operation | Command | Return |
| --- | --- | --- |
| `fetch(ref)` | `gh issue view <number> --json number,url,title,body,labels,state` (`#42`, `owner/repo#42` add `--repo owner/repo`, or the URL) | `{key: "#<number>", url, title, body, labels: [names], status: state}` |
| `create({title, body, labels})` | `gh issue create --title <title> --body-file <tmp> [--label <l>]...` | `{key: "#<number>", url}` from the printed URL |
| `comment(key, text)` | `gh issue comment <number> --body-file <tmp>` | `{url}` |
| `comments(key)` | `gh issue view <number> --json comments` | `[{author, body, at}]` |
| `link(key, pr_url)` | put `Closes #<number>` in the PR body; nothing else needed | `{ok: true}` |
| `transition(key, state)` | `in-review`: no-op; `done`: `gh issue close <number>` when the PR merges | `{ok}` or `{skipped}` |
| `push(branch)` | `git push -u origin <branch>` | `{ok}` |
| `open_pr({title, body, head, base, draft})` | `gh pr create --head <head> --base <base> --title <title> --body-file <tmp> [--draft]` | `{url, number}` |
| `pr_status(number)` | `gh pr view <number> --json state,mergedAt,statusCheckRollup` | `{state: open|merged|closed, checks}` |
| `pr_comments(number)` | `gh api repos/{owner}/{repo}/pulls/<number>/comments` and `gh pr view <number> --json reviews` | `[{author, body, path, line}]` |

Write long bodies to a temporary file and pass `--body-file`, never inline, so quoting can't break them.
