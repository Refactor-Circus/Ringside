---
description: Tests pass on every CI matrix entry; isolate git config in tests that commit, skipOnWindows() for /bin/sh fakes, normalize paths the way the code does.
tags: [testing, ci, portability, git, windows]
kind: convention
status: proposed
from_spec: 2026-10-25-1349-worktree-local-manifest-install
evidence:
  - docs/agent/specs/2026-10-25-1349-worktree-local-manifest-install/build.md (Group 6)
  - tests/Feature/NewPackageTest.php
  - tests/Feature/HookScriptTest.php
  - .github/workflows/tests.yml
---

# CI Portability

Every test passes on each entry of the CI matrix in `.github/workflows/tests.yml` (ubuntu-latest and windows-latest, prefer-lowest and prefer-stable), not only on the developer's machine.

## Git

- A test that makes a commit isolates git config. Point `GIT_CONFIG_GLOBAL` at a temp file with `user.useConfigOnly = true`, set `GIT_CONFIG_NOSYSTEM=1`, unset `EMAIL`, then set `GIT_AUTHOR_*` and `GIT_COMMITTER_*` explicitly.
- Without `useConfigOnly`, git guesses an identity from the host, so a clean-runner failure does not reproduce locally.
- Save every variable you change in `beforeEach` and restore it in `afterEach`. When the test runs a subprocess, pass the env through `Shell::run`'s `$env` argument instead, so the test process itself is never changed.

## Shell fakes and POSIX-only behaviour

- A fake binary written as `#!/bin/sh` (fake `composer`, `git`, `laravel`) only runs on POSIX. Mark the test or `describe` block `skipOnWindows()` and add a comment naming the fake.
- Do the same for tests that depend on `chmod`, symlinks or `stream_select` on pipes.

## Paths

- Compare paths in the form the code produces. When the code normalizes to forward slashes (for example `bin/ringside-hook` before it passes `--project=`), the expectation normalizes too: `str_replace('\\', '/', $root)`.
- Treat `C:\…` as absolute, as well as `/…`.
- Compare temp paths through `realpath()`, because macOS resolves them to `/private/var/…`.

## Example

```php
beforeEach(function () {
    $this->gitConfig = (string) tempnam(sys_get_temp_dir(), 'gitconfig-');
    file_put_contents($this->gitConfig, "[user]\n\tuseConfigOnly = true\n");
    putenv("GIT_CONFIG_GLOBAL={$this->gitConfig}");
    putenv('GIT_CONFIG_NOSYSTEM=1');
    putenv('EMAIL');
    putenv('GIT_AUTHOR_NAME=Test Author'); // plus _EMAIL and GIT_COMMITTER_*
});

it('scaffolds a laravel package', function () {
    // ...
})->skipOnWindows(); // the fake `laravel` is a #!/bin/sh script
```
