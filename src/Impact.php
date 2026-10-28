<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside;

use RuntimeException;

/**
 * The impact gate: the packages that require the ones a job changes, and
 * their tests run against the job's code. Each consumer gets a throwaway
 * detached worktree at its base branch, installed with the changed packages
 * resolved to the job's worktrees, and is removed again whatever happens.
 */
final class Impact
{
    /** Lines of test output kept for a result. */
    public const int TAIL = 60;

    public const string MANIFEST = 'composer.local.json';

    public const string LOCK = 'composer.local.lock';

    /**
     * Packages that require any of $names, per `impact.consumers`: `auto`
     * looks at the folders beside the project root (and the workspace's
     * packages), a list names the folders, `off` (or `impact.enabled: false`)
     * finds none. Only main git checkouts count: job worktrees beside them
     * (whose `.git` is a file) never do.
     *
     * @param  list<string>  $names  composer names of the changed packages
     * @param  list<string>  $exclude  folders that are part of the job itself
     * @return list<array{folder: string, path: string, test: string}>
     */
    public static function consumers(Project $project, array $names, array $exclude = []): array
    {
        $setting = $project->config->get('impact.consumers');

        if (! $project->config->bool('impact.enabled') || $setting === 'off' || $setting === false || $names === []) {
            return [];
        }

        $candidates = is_array($setting)
            ? array_map(static fn (mixed $path): string => self::absolute($project, (string) $path), array_filter($setting, is_scalar(...)))
            : self::nearby($project);
        $root = realpath($project->root) ?: $project->root;
        $consumers = [];

        foreach (array_unique($candidates) as $path) {
            $path = realpath($path) ?: $path;
            $folder = basename($path);

            if ($path === $root || in_array($folder, $exclude, true) || isset($consumers[$folder]) || ! is_dir($path.'/.git')) {
                continue;
            }

            $manifest = self::manifest($path.'/composer.json');
            $requires = [...(array) ($manifest['require'] ?? []), ...(array) ($manifest['require-dev'] ?? [])];

            if (array_intersect($names, array_map(strval(...), array_keys($requires))) === []) {
                continue;
            }

            $consumers[$folder] = ['folder' => $folder, 'path' => $path, 'test' => Config::load($path)->string('commands.test') ?: 'vendor/bin/pest'];
        }

        ksort($consumers);

        return array_values($consumers);
    }

    /**
     * The job's package as composer name => the job worktree that holds its
     * new code (the package itself when it builds without a job worktree).
     *
     * @param  array<string, mixed>  $job  the output of Job::build
     * @return array<string, string>
     */
    public static function targets(Project $project, array $job): array
    {
        $tree = $project->config->string('build.worktree.mode') === 'per-job' ? self::absolute($project, (string) $job['job']['worktree']) : $project->root;

        return [self::name($project->root) => realpath($tree) ?: $tree];
    }

    /**
     * The composer name of the package at $root, or its folder name.
     */
    public static function name(string $root): string
    {
        $name = self::manifest($root.'/composer.json')['name'] ?? null;

        return is_string($name) ? $name : basename($root);
    }

    /**
     * Runs one consumer's tests against the job: $trees maps each changed
     * package's composer name to the job worktree that holds its new code.
     *
     * @param  array{folder: string, path: string, test: string}  $consumer
     * @param  array<string, string>  $trees
     * @return array{folder: string, path: string, test: string, passed: bool, exit_code: int, summary: string, output: string}
     */
    public static function run(array $consumer, string $spec, array $trees): array
    {
        $path = $consumer['path'];
        $scratch = dirname($path).'/'.$consumer['folder'].'--impact-'.$spec;
        $result = static fn (bool $passed, int $code, string $summary, string $output): array => [...$consumer, 'passed' => $passed, 'exit_code' => $code, 'summary' => $summary, 'output' => self::tail($output)];

        // A scratch worktree left by a run that was killed goes first.
        self::remove($path, $scratch);
        $base = Config::load($path)->string('host.base') ?: 'main';
        [$remote] = Shell::run(['git', 'rev-parse', '--verify', '--quiet', 'refs/remotes/origin/'.$base], $path);
        [$code, $out] = Shell::run(['git', 'worktree', 'add', '--detach', $scratch, $remote === 0 ? 'origin/'.$base : $base], $path, withStderr: true);

        if ($code !== 0) {
            return $result(false, $code, "could not create a worktree of {$consumer['folder']} at {$base}", $out);
        }

        try {
            self::writeManifest($path, $scratch, $trees);
            $install = is_file($scratch.'/'.self::LOCK)
                ? ['composer', 'update', ...array_keys($trees), '--with-dependencies', '--no-interaction']
                : ['composer', 'install', '--no-interaction'];
            [$code, $out] = Shell::run($install, $scratch, ['COMPOSER' => self::MANIFEST], true);

            if ($code !== 0) {
                return $result(false, $code, "installing {$consumer['folder']} against the job failed", $out);
            }

            [$code, $out] = Shell::run(preg_split('/\s+/', trim($consumer['test'])) ?: [], $scratch, ['COMPOSER' => self::MANIFEST], true);

            return $code === 0
                ? $result(true, 0, "{$consumer['folder']}: `{$consumer['test']}` passes against the job", $out)
                : $result(false, $code, "{$consumer['folder']}: `{$consumer['test']}` fails against the job (exit {$code})", $out);
        } catch (RuntimeException $exception) {
            return $result(false, 1, $exception->getMessage(), '');
        } finally {
            self::remove($path, $scratch);
        }
    }

    /**
     * The consumer's composer.local.json (or composer.json) in the scratch
     * worktree, with a path repository for each changed package put first,
     * so Composer takes it from the job worktree. A dev-* constraint is kept
     *
     * as the path package's version; any other one is relaxed to `*@dev`,
     * which the job branch's version satisfies. The local lock comes along
     * when there is one, so only the changed packages are updated.
     *
     * @param  array<string, string>  $trees
     */
    private static function writeManifest(string $consumer, string $scratch, array $trees): void
    {
        $source = is_file($consumer.'/'.self::MANIFEST) ? $consumer.'/'.self::MANIFEST : $consumer.'/composer.json';
        $manifest = self::manifest($source);

        if ($manifest === []) {
            throw new RuntimeException("{$source} is not valid JSON.");
        }

        $repositories = [];

        foreach ($trees as $name => $tree) {
            $repository = ['type' => 'path', 'url' => $tree, 'options' => ['symlink' => true]];

            foreach (['require', 'require-dev'] as $section) {
                $constraint = $manifest[$section][$name] ?? null;

                if (! is_string($constraint)) {
                    continue;
                }

                if (str_starts_with($constraint, 'dev-')) {
                    $repository['options']['versions'] = [$name => $constraint];
                } else {
                    $manifest[$section][$name] = '*@dev';
                }
            }

            $repositories[] = $repository;
        }

        $manifest['repositories'] = [...$repositories, ...array_values((array) ($manifest['repositories'] ?? []))];
        file_put_contents($scratch.'/'.self::MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        foreach ([self::LOCK, 'composer.lock'] as $lock) {
            if (is_file($consumer.'/'.$lock)) {
                copy($consumer.'/'.$lock, $scratch.'/'.self::LOCK);

                break;
            }
        }
    }

    /**
     * Folders beside the project root, plus the workspace's packages.
     *
     * @return list<string>
     */
    private static function nearby(Project $project): array
    {
        $folders = glob(dirname(realpath($project->root) ?: $project->root).'/*', GLOB_ONLYDIR) ?: [];

        foreach ($project->config->list('workspace.packages') as $package) {
            $folders[] = $project->path($package);
        }

        return $folders;
    }

    private static function remove(string $repo, string $scratch): void
    {
        if (file_exists($scratch)) {
            Shell::run(['git', 'worktree', 'remove', '--force', $scratch], $repo);
        }

        Shell::run(['git', 'worktree', 'prune'], $repo);
    }

    /**
     * @return array<string, mixed>
     */
    private static function manifest(string $file): array
    {
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private static function tail(string $output): string
    {
        return implode("\n", array_slice(explode("\n", trim($output)), -self::TAIL));
    }

    private static function absolute(Project $project, string $path): string
    {
        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1 ? $path : $project->path($path);
    }
}
