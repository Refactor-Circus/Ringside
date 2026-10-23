<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Specs;

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Providers\ProviderRegistry;
use RefactorCircus\Ringside\Standards\StandardsResolver;
use RefactorCircus\Ringside\Workspace\Workspace;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Everything a workflow needs for one spec, as plain data: workflow scripts
 * cannot read files, so `ringside job <spec> --json` becomes their `args`.
 */
final class Job
{
    /**
     * @return array<string, mixed>
     */
    public function build(Project $project, string $spec): array
    {
        $dir = $this->dir($project, $spec);
        $meta = Yaml::parse((string) $project->read($dir.'/meta.yml')) ?? [];
        $meta = is_array($meta) ? $meta : [];
        $parser = new TaskParser;
        $groups = $parser->groups((string) $project->read($dir.'/tasks.md'));
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
                'branch' => 'ring-master/'.$id,
                'worktree' => strtr($project->config->string('build.worktree.path'), ['{package}' => $package, '{spec}' => $id]),
                'root' => $project->root,
            ],
            'waves' => $waves,
            'warnings' => $parser->overlaps($waves),
            'decisions' => (new Decisions)->parse((string) $project->read($dir.'/decisions.md')),
            'index' => $index,
            'reviewers' => $project->config->reviewers(),
            'workspace' => $workspace->enabled() ? [
                'dir' => $workspace->jobDir($id),
                'packages' => $workspace->describe($changed),
            ] : null,
            'host' => $providers->host(),
            'config' => $project->config->values,
        ];
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
