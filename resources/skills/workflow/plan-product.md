---
name: ring-master-plan-product
description: Write or update this package's product docs (mission, roadmap, tech stack) under the Ring Master product folder.
disable-model-invocation: true
---
{{marker}}

# Plan the product

Create or update `{{product_dir}}/mission.md`, `roadmap.md` and `tech-stack.md` for `{{package_name}}`.

1. Read `composer.json`, `README.md` and the `src/` tree. Read existing product docs first if they exist; update them rather than starting over.
2. Ask the user (AskUserQuestion) for anything the code cannot tell you: who the package is for, the problem it solves, what is next. Offer a draft answer they can confirm.
3. Write:
   - `mission.md`: who it is for, the problem, what makes it different. Under a page.
   - `roadmap.md`: `## Now`, `## Next`, `## Later`, each a short list. Link a spec folder when one exists.
   - `tech-stack.md`: PHP and Laravel versions, key dependencies and suite packages it builds on, test and quality tooling (`{{verify_command}}`).

Keep each file short; specs carry the detail.
