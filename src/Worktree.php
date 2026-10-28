<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside;

use RuntimeException;

/**
 * A single package's per-job worktree: a checkout of the job branch next to
 * the package, set up so the package's gates run inside it. The single-package
 * counterpart of Workspace::prepare.
 */
final class Worktree
{
    /** The `build.worktree.setup` value that installs from composer.local.json when the package has one. */
    public const string AUTO = 'auto';

    /** The suffix of a replay's worktree path and branch, so a replay never touches the real job's. */
    public const string REPLAY = '--replay';

    /** @var list<string> */
    private const array LOCAL_FILES = ['composer.local.json', 'composer.local.lock'];

    /** @var list<string> */
    private const array INSTALL = ['composer', 'install', '--no-interaction'];

    /**
     * Creates the worktree at $path on $branch (a new branch from $base when
     * it does not exist yet), or reuses it when it is already on $branch.
     * $path is used as is when absolute, else resolved against the package root.
     *
     * @return string the worktree's path
     *
     * @throws RuntimeException when a value starts with `-`, the existing worktree is on another branch, or git fails
     */
    public static function prepare(Project $project, string $path, string $branch, string $base): string
    {
        foreach (['branch' => $branch, 'path' => $path, 'base' => $base] as $name => $value) {
            if (str_starts_with($value, '-')) {
                throw new RuntimeException("Refusing the worktree {$name} `{$value}`: a value starting with `-` would reach git as an option.");
            }
        }

        $absolute = self::absolute($project, $path);

        if (file_exists($absolute.'/.git')) {
            self::assertOnBranch($absolute, $branch);

            return $absolute;
        }

        [$exists] = Shell::run(['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch], $project->root);
        $command = $exists === 0
            ? ['git', 'worktree', 'add', $absolute, $branch]
            : ['git', 'worktree', 'add', '-b', $branch, $absolute, $base];
        // git writes its refusals to stderr, so they are part of the error.
        [$code, $out] = Shell::run($command, $project->root, withStderr: true);

        if ($code !== 0) {
            throw new RuntimeException("Could not create the worktree at {$absolute} on {$branch}: {$out}");
        }

        return $absolute;
    }

    /**
     * Creates (or reuses) one worktree per task group beside the job
     * worktree, `<job worktree>--g<n>` on `<job branch>--g<n>` branched from
     * the job branch, so groups of a parallel wave never share a checkout.
     *
     * @param  list<int>  $groups
     * @return array<int, array{path: string, branch: string}>
     */
    public static function groups(Project $project, string $jobPath, string $jobBranch, array $groups): array
    {
        return self::beside($project, $jobPath, $jobBranch, 'g', $groups);
    }

    /**
     * Creates (or reuses) one worktree per variant of a job built several
     * times over, `<job worktree>--v<n>` on `<job branch>--v<n>` branched from
     * the job branch; the judge's pick is fast-forwarded into the job branch.
     *
     * @param  list<int>  $variants
     * @return array<int, array{path: string, branch: string}>
     */
    public static function variants(Project $project, string $jobPath, string $jobBranch, array $variants): array
    {
        return self::beside($project, $jobPath, $jobBranch, 'v', $variants);
    }

    /**
     * Removes every group worktree of the job and deletes the group branches
     * (their commits are merged into the job branch by then).
     *
     * @return list<string> what was done
     */
    public static function removeGroups(Project $project, string $jobPath, string $jobBranch): array
    {
        return self::removeBeside($project, $jobPath, $jobBranch, '--g', '\d+');
    }

    /**
     * Removes every variant worktree of the job and deletes their branches
     * (the winner's commits are on the job branch by then).
     *
     * @return list<string> what was done
     */
    public static function removeVariants(Project $project, string $jobPath, string $jobBranch): array
    {
        return self::removeBeside($project, $jobPath, $jobBranch, '--v', '\d+');
    }

    /**
     * Removes the worktree and branch of a replay (`<job worktree>--replay`
     * on `<job branch>--replay`, whose own variants come with it).
     *
     * @return list<string> what was done
     */
    public static function removeReplay(Project $project, string $jobPath, string $jobBranch): array
    {
        return [
            ...self::removeVariants($project, $jobPath.self::REPLAY, $jobBranch.self::REPLAY),
            ...self::removeBeside($project, $jobPath, $jobBranch, self::REPLAY, ''),
        ];
    }

    public static function groupBranch(string $jobBranch, int $n): string
    {
        return "{$jobBranch}--g{$n}";
    }

    public static function groupPath(Project $project, string $jobPath, int $n): string
    {
        return self::absolute($project, $jobPath)."--g{$n}";
    }

    public static function variantBranch(string $jobBranch, int $n): string
    {
        return "{$jobBranch}--v{$n}";
    }

    public static function variantPath(Project $project, string $jobPath, int $n): string
    {
        return self::absolute($project, $jobPath)."--v{$n}";
    }

    /**
     * @param  list<int>  $numbers
     * @return array<int, array{path: string, branch: string}>
     */
    private static function beside(Project $project, string $jobPath, string $jobBranch, string $kind, array $numbers): array
    {
        $created = [];

        foreach ($numbers as $n) {
            $branch = "{$jobBranch}--{$kind}{$n}";
            $created[$n] = ['path' => self::prepare($project, self::absolute($project, $jobPath)."--{$kind}{$n}", $branch, $jobBranch), 'branch' => $branch];
        }

        return $created;
    }

    /**
     * Removes the worktrees `<job worktree><suffix><n>` and deletes the
     * branches `<job branch><suffix><n>`, where <n> matches $number.
     *
     * @return list<string> what was done
     */
    private static function removeBeside(Project $project, string $jobPath, string $jobBranch, string $suffix, string $number): array
    {
        $log = [];
        $job = self::absolute($project, $jobPath);
        // git lists real paths (/private/var for /var on macOS), so both spellings count.
        $prefixes = array_unique([$job.$suffix, (realpath(dirname($job)) ?: dirname($job)).'/'.basename($job).$suffix]);
        $pattern = '/'.preg_quote($suffix, '/').$number.'$/';
        [, $list] = Shell::run(['git', 'worktree', 'list', '--porcelain'], $project->root);

        foreach (explode("\n", $list) as $line) {
            $path = str_starts_with($line, 'worktree ') ? substr($line, 9) : '';
            $ours = array_filter($prefixes, static fn (string $prefix): bool => str_starts_with($path, $prefix)) !== [];

            if ($path !== '' && $ours && preg_match($pattern, $path) === 1) {
                [$code, $out] = Shell::run(['git', 'worktree', 'remove', '--force', $path], $project->root, withStderr: true);
                $log[] = $code === 0 ? "Removed {$path}" : "Kept {$path}: {$out}";
            }
        }

        [, $branches] = Shell::run(['git', 'for-each-ref', '--format=%(refname:short)', 'refs/heads/'.$jobBranch.$suffix.($number === '' ? '' : '*')], $project->root);

        foreach (array_filter(explode("\n", $branches)) as $branch) {
            if (preg_match('/'.preg_quote($suffix, '/').$number.'$/', $branch) !== 1) {
                continue;
            }

            [$code, $out] = Shell::run(['git', 'branch', '-D', $branch], $project->root, withStderr: true);
            $log[] = $code === 0 ? "Deleted branch {$branch}" : "Kept branch {$branch}: {$out}";
        }

        Shell::run(['git', 'worktree', 'prune'], $project->root);

        return $log;
    }

    /**
     * Installs the worktree's dependencies. `auto` copies composer.local.json
     * and composer.local.lock from the package root, each only when the
     * worktree has none, then runs `composer install` from the local manifest
     * when the worktree has one, else plain (ignoring any COMPOSER the caller
     * set). Any other value is a command, run in the worktree as is.
     *
     * @return array{0: int, 1: string, 2: string} exit code, output (stderr included), and what ran
     */
    public static function setup(Project $project, string $path, string $setup): array
    {
        $absolute = self::absolute($project, $path);
        $setup = trim($setup);

        if ($setup !== self::AUTO && $setup !== '') {
            [$code, $out] = Shell::run(preg_split('/\s+/', $setup) ?: [], $absolute, withStderr: true);

            return [$code, $out, "`{$setup}`"];
        }

        foreach (self::LOCAL_FILES as $file) {
            $from = $project->path($file);
            $to = $absolute.'/'.$file;

            if (! is_file($from) || file_exists($to)) {
                continue;
            }

            $reason = self::copy($from, $to);

            if ($reason !== null) {
                return [1, "Could not copy {$from} to {$to}: {$reason}", "copying {$file} into the worktree"];
            }
        }

        $local = is_file($absolute.'/'.self::LOCAL_FILES[0]);
        // An empty COMPOSER reads as unset, so a caller's COMPOSER never picks the manifest.
        [$code, $out] = Shell::run(self::INSTALL, $absolute, ['COMPOSER' => $local ? self::LOCAL_FILES[0] : ''], true);
        $command = implode(' ', self::INSTALL);

        return [$code, $out, $local ? '`COMPOSER='.self::LOCAL_FILES[0]." {$command}`" : "`{$command}`"];
    }

    /**
     * Copies $from to $to, returning why it failed (PHP's warning) or null.
     */
    private static function copy(string $from, string $to): ?string
    {
        $reason = null;
        set_error_handler(static function (int $level, string $message) use (&$reason): bool {
            $reason = $message;

            return true;
        });

        try {
            $copied = copy($from, $to);
        } finally {
            restore_error_handler();
        }

        return $copied ? null : ($reason ?? 'copy() failed');
    }

    private static function assertOnBranch(string $worktree, string $branch): void
    {
        [$code, $current] = Shell::run(['git', 'branch', '--show-current'], $worktree);

        if ($code !== 0) {
            throw new RuntimeException("Could not read the branch of the worktree at {$worktree}.");
        }

        if ($current !== $branch) {
            $actual = $current === '' ? 'a detached HEAD' : "`{$current}`";

            throw new RuntimeException("The worktree at {$worktree} is on {$actual}, not `{$branch}`. Check out `{$branch}` there or remove the worktree, then run again.");
        }
    }

    private static function absolute(Project $project, string $path): string
    {
        $absolute = str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;

        return $absolute ? $path : $project->path($path);
    }
}
