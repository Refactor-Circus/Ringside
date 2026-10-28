<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Specs;

use RefactorCircus\Ringside\Impact;
use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Providers\ProviderRegistry;
use RefactorCircus\Ringside\Standards\StandardsResolver;
use RefactorCircus\Ringside\Workspace\Workspace;
use RefactorCircus\Ringside\Worktree;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Everything a workflow needs for one spec, as plain data: workflow scripts
 * cannot read files, so `ringside job <spec> --json` becomes their `args`.
 */
final class Job
{
    /** Most variants a job may be built as at once. */
    public const int MAX_VARIANTS = 4;

    /**
     * $replay builds the job again from scratch on its own branch and
     * worktree (`--replay`), every task group undone, so an evaluation can
     * score a factory run without touching the real job.
     *
     * @return array<string, mixed>
     */
    public function build(Project $project, string $spec, bool $replay = false): array
    {
        $dir = $this->dir($project, $spec);
        $meta = Yaml::parse((string) $project->read($dir.'/meta.yml')) ?? [];
        $meta = is_array($meta) ? $meta : [];
        $parser = new TaskParser;
        $groups = $parser->groups((string) $project->read($dir.'/tasks.md'));

        if ($replay) {
            $groups = array_map(static fn (array $group): array => [
                ...$group,
                'done' => false,
                'tasks' => array_map(static fn (array $task): array => [...$task, 'done' => false], $group['tasks']),
            ], $groups);
        }

        $waves = $parser->waves($groups);
        $resolution = (new StandardsResolver)->resolve($project);
        $providers = new ProviderRegistry($project);
        $package = basename($project->root);
        $id = basename($dir);

        $workspace = new Workspace($project);
        $changed = array_values(array_unique(array_filter(array_column($groups, 'package'))));

        if ($workspace->enabled()) {
            foreach ($groups as $group) {
                if (! $group['done'] && ! in_array($group['package'], $workspace->packages(), true)) {
                    throw new RuntimeException("Group {$group['n']} needs a `package:` line naming one of the workspace packages (".implode(', ', $workspace->packages()).'). A new package must be created first: list it under new_packages in meta.yml and run vendor/bin/ringside new-package.');
                }
            }
        }

        $described = $workspace->enabled() ? $workspace->describe($changed) : null;
        $impact = Impact::consumers(
            $project,
            $described === null ? [Impact::name($project->root)] : array_values(array_map(static fn (array $p): string => $p['name'], $described)),
            $described === null ? [] : array_keys($described),
        );

        $index = [];

        foreach ($resolution->standards as $key => $standard) {
            $index[$key] = ['path' => $standard->path, 'kind' => $standard->kind()];
        }

        return [
            'spec' => [
                'id' => $id,
                'dir' => $dir,
                'title' => is_string($meta['title'] ?? null) ? $meta['title'] : $id,
                'status' => is_string($meta['status'] ?? null) ? $meta['status'] : 'unknown',
                'ticket' => $meta['ticket'] ?? null,
                'standards' => $this->standardPaths((string) $project->read($dir.'/standards.md'), $index),
            ],
            'job' => [
                'branch' => 'ring-master/'.$id.($replay ? Worktree::REPLAY : ''),
                'worktree' => strtr($project->config->string('build.worktree.path'), ['{package}' => $package, '{spec}' => $id]).($replay ? Worktree::REPLAY : ''),
                'root' => $project->root,
            ],
            'replay' => $replay,
            // Absolute folder of the workflow scripts this Ringside ships: the factory imports its helper modules from it (D-013).
            'workflows_dir' => dirname(__DIR__, 2).'/resources/workflows',
            // How many times over the build runs, the judge keeping the best; 1 builds once.
            'variants' => $this->variants($project, $meta, $workspace->enabled()),
            'brief' => is_file($project->path($dir.'/brief.md')) ? $dir.'/brief.md' : null,
            'waves' => $waves,
            'warnings' => $parser->overlaps($waves),
            'decisions' => (new Decisions)->parse((string) $project->read($dir.'/decisions.md')),
            'index' => $index,
            'reviewers' => $project->config->reviewers(),
            'workspace' => $workspace->enabled() ? [
                'dir' => $workspace->jobDir($id),
                'packages' => $described,
            ] : null,
            // The impact gate: packages that require the changed ones, tested against the job before it approves.
            'impact' => [
                'enabled' => $project->config->bool('impact.enabled') && $impact !== [],
                'consumers' => $impact,
            ],
            'host' => $providers->host(),
            'config' => $project->config->values,
        ];
    }

    /**
     * `variants:` in meta.yml, else `build.variants`, between 1 and
     * MAX_VARIANTS. Only a single package with a worktree per job builds
     * variants (each needs a worktree of its own); anything else builds once.
     *
     * @param  array<mixed>  $meta
     */
    private function variants(Project $project, array $meta, bool $workspace): int
    {
        if ($workspace || $project->config->string('build.worktree.mode') !== 'per-job') {
            return 1;
        }

        $value = is_numeric($meta['variants'] ?? null) ? (int) $meta['variants'] : $project->config->int('build.variants');

        return max(1, min(self::MAX_VARIANTS, $value));
    }

    public function dir(Project $project, string $spec): string
    {
        $specs = $project->agentPath('specs');

        foreach ([$spec, $specs.'/'.$spec] as $candidate) {
            if (is_dir($project->path($candidate)) && is_file($project->path($candidate.'/meta.yml'))) {
                return trim($candidate, '/');
            }
        }

        $matches = glob($project->path($specs).'/*'.$spec.'*', GLOB_ONLYDIR) ?: [];

        if (count($matches) === 1) {
            return $specs.'/'.basename($matches[0]);
        }

        throw new RuntimeException(count($matches) > 1 ? "`{$spec}` matches several specs; use the full id." : "No spec `{$spec}` in {$specs}/.");
    }

    /**
     * Standard file paths a spec's standards.md names, by key or path, resolved through the index.
     *
     * @param  array<string, array{path: string, kind: string}>  $index
     * @return list<string>
     */
    private function standardPaths(string $standards, array $index): array
    {
        $paths = [];

        foreach ($index as $key => $entry) {
            if (str_contains($standards, $key) || str_contains($standards, $entry['path'])) {
                $paths[] = $entry['path'];
            }
        }

        return $paths;
    }
}
