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
