<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Workspace;

use RefactorCircus\Ringside\Config;
use RefactorCircus\Ringside\Detector;
use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Shell;
use RefactorCircus\Ringside\Standards\Layer;
use RuntimeException;

/**
 * A folder of sibling packages, each its own git repo, worked on together.
 * A job that spans packages gets its own folder: a worktree for every package
 * it changes and a link to the main checkout of every other one, so local
 * path repositories (`../<Package>`) resolve to the job's versions.
 */
final class Workspace
{
    public function __construct(private readonly Project $root) {}

    public function enabled(): bool
    {
        return $this->packages() !== [];
    }

    /**
     * Package folder names, as configured in `workspace.packages`.
     *
     * @return list<string>
     */
    public function packages(): array
    {
        return array_values(array_filter(
            $this->root->config->list('workspace.packages'),
            fn (string $name): bool => is_file($this->root->path($name.'/composer.json')),
        ));
    }

    public function jobDir(string $spec): string
    {
        return trim($this->root->config->string('workspace.jobs_dir'), '/').'/'.$spec;
    }

    /**
     * Each package's commands: its own ring-master.yml when it has one, else
     * what install would detect from its composer scripts.
     *
     * @param  list<string>  $packages
     * @return array<string, array{name: string, dir: string, commands: array<string, ?string>, setup: string, standards: list<string>}>
     */
    public function describe(array $packages): array
    {
        $described = [];

        foreach ($this->ordered($packages) as $package) {
            $project = new Project($this->root->path($package));
            $detected = $project->exists(Config::FILE) ? [] : (new Detector)->detect($project);
            $commands = [...(array) $project->config->get('commands'), ...(array) ($detected['commands'] ?? [])];
            $manifest = json_decode((string) $project->read('composer.json'), true);
            $scripts = is_array($manifest) && is_array($manifest['scripts'] ?? null) ? $manifest['scripts'] : [];

            $described[$package] = [
                'name' => is_array($manifest) && is_string($manifest['name'] ?? null) ? $manifest['name'] : $package,
                'dir' => $package,
                'commands' => array_map(static fn (mixed $c): ?string => is_string($c) && $c !== '' ? $c : null, $commands),
                'setup' => isset($scripts['local:install']) ? 'composer local:install --no-interaction' : 'composer install --no-interaction',
                // The package's own standards, which override the global ones by key.
                'standards' => array_map(
                    static fn (string $key): string => $package.'/'.$project->agentPath('standards/'.$key.'.md'),
                    Layer::markdownFiles($project->path($project->agentPath('standards'))),
                ),
            ];
        }

        return $described;
    }

    /**
     * Packages in dependency order: a package comes after every workspace
     * package it requires, so gates and PRs run bottom-up.
     *
     * @param  list<string>  $packages
     * @return list<string>
     */
    public function ordered(array $packages): array
    {
        $names = [];
        $requires = [];

        foreach ($this->packages() as $package) {
            $manifest = json_decode((string) $this->root->read($package.'/composer.json'), true);
            $names[$package] = is_array($manifest) && is_string($manifest['name'] ?? null) ? $manifest['name'] : $package;
            $requires[$package] = is_array($manifest) ? array_keys([...(array) ($manifest['require'] ?? []), ...(array) ($manifest['require-dev'] ?? [])]) : [];
        }

        $byName = array_flip($names);
        $ordered = [];
        $visit = function (string $package, array $path) use (&$visit, &$ordered, $requires, $byName, $packages): void {
            if (in_array($package, $ordered, true) || in_array($package, $path, true)) {
                return;
            }

            foreach ($requires[$package] ?? [] as $name) {
                if (isset($byName[$name]) && in_array($byName[$name], $packages, true)) {
                    $visit($byName[$name], [...$path, $package]);
                }
            }

            $ordered[] = $package;
        };

        foreach ($packages as $package) {
            $visit($package, []);
        }

        return $ordered;
    }

    /**
     * Creates (or reuses) the job folder: worktrees on `$branch` for the
     * packages the job changes, links for the rest of the workspace.
     *
     * @param  list<string>  $changed
     * @return array{dir: string, worktrees: array<string, string>, linked: list<string>}
     */
    public function prepare(string $spec, array $changed, string $branch, string $base): array
    {
        $dir = $this->jobDir($spec);
        $absolute = $this->root->path($dir);

        if (! is_dir($absolute)) {
            mkdir($absolute, 0o755, true);
        }

        $worktrees = [];
        $linked = [];

        foreach ($this->packages() as $package) {
            $target = $absolute.'/'.$package;

            if (in_array($package, $changed, true)) {
                if (! is_dir($target)) {
                    $repo = $this->root->path($package);
                    [$exists] = Shell::run(['git', 'rev-parse', '--verify', '--quiet', $branch], $repo);
                    $command = $exists === 0
                        ? ['git', 'worktree', 'add', $target, $branch]
                        : ['git', 'worktree', 'add', '-b', $branch, $target, $base];
                    [$code, $out] = Shell::run($command, $repo);

                    if ($code !== 0) {
                        throw new RuntimeException("Could not create the {$package} worktree: {$out}");
                    }
                }

                $worktrees[$package] = $dir.'/'.$package;
            } elseif (! file_exists($target)) {
                symlink(str_repeat('../', substr_count($dir, '/') + 1).$package, $target);
                $linked[] = $package;
            } else {
                $linked[] = $package;
            }
        }

        return ['dir' => $dir, 'worktrees' => $worktrees, 'linked' => $linked];
    }

    /**
     * Removes the job folder: worktrees through git, links by unlinking.
     *
     * @return list<string>
     */
    public function remove(string $spec): array
    {
        $absolute = $this->root->path($this->jobDir($spec));
        $log = [];

        foreach ($this->packages() as $package) {
            $target = $absolute.'/'.$package;

            if (is_link($target)) {
                unlink($target);
            } elseif (is_dir($target)) {
                [$code, $out] = Shell::run(['git', 'worktree', 'remove', $target], $this->root->path($package));
                $log[] = $code === 0 ? "Removed the {$package} worktree" : "Kept the {$package} worktree: {$out}";
            }
        }

        if (is_dir($absolute) && array_diff((array) scandir($absolute), ['.', '..']) === []) {
            rmdir($absolute);
        }

        return $log;
    }
}
