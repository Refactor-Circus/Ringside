<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Specs;

use RefactorCircus\Ringside\Impact;
use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Providers\ProviderRegistry;
use RefactorCircus\Ringside\Standards\StandardsResolver;
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
        $id = basename($dir);
        // The spec folder wherever it lives: the job worktree, or main once merged.
        $path = Locator::find($project, $id) ?? $project->path($dir);
        $read = static fn (string $name): string => is_file($path.'/'.$name) ? (string) file_get_contents($path.'/'.$name) : '';
        $meta = Yaml::parse($read('meta.yml')) ?? [];
        $meta = is_array($meta) ? $meta : [];
        $parser = new TaskParser;
        $groups = $parser->groups($read('tasks.md'));

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
        $impact = Impact::consumers($project, [Impact::name($project->root)]);

        $index = [];

        foreach ($resolution->standards as $key => $standard) {
            $index[$key] = ['path' => $standard->path, 'kind' => $standard->kind()];
        }

        return [
            'spec' => [
                'id' => $id,
                'dir' => $dir,
                // Absolute: every stage reads and writes the spec here, and commits it with the job.
                'path' => $path,
                'title' => is_string($meta['title'] ?? null) ? $meta['title'] : $id,
                'status' => is_string($meta['status'] ?? null) ? $meta['status'] : 'unknown',
                'ticket' => $meta['ticket'] ?? null,
                'standards' => $this->standardPaths($read('standards.md'), $index),
            ],
            'job' => [
                'branch' => self::branch($id).($replay ? Worktree::REPLAY : ''),
                'worktree' => self::worktree($project, $id).($replay ? Worktree::REPLAY : ''),
                'root' => $project->root,
            ],
            'replay' => $replay,
            // Absolute folder of the workflow scripts this Ringside ships: the factory imports its helper modules from it (D-013).
            'workflows_dir' => dirname(__DIR__, 2).'/resources/workflows',
            // How many times over the build runs, the judge keeping the best; 1 builds once.
            'variants' => $this->variants($project, $meta),
            'brief' => is_file($path.'/brief.md') ? $dir.'/brief.md' : null,
            'waves' => $waves,
            'warnings' => $parser->overlaps($waves),
            'decisions' => (new Decisions)->parse($read('decisions.md')),
            'index' => $index,
            'reviewers' => $project->config->reviewers(),
            // The impact gate: packages that require the changed ones, tested against the job before it approves.
            'impact' => [
                'enabled' => $project->config->bool('impact.enabled') && $impact !== [],
                'consumers' => $impact,
            ],
            'host' => $providers->host(),
            'config' => $project->config->values,
        ];
    }

    /** The job branch of spec $id. */
    public static function branch(string $id): string
    {
        return Locator::BRANCH_PREFIX.$id;
    }

    /**
     * The job worktree of spec $id, from `build.worktree.path`: relative to
     * the package root unless the setting is absolute.
     */
    public static function worktree(Project $project, string $id): string
    {
        return strtr($project->config->string('build.worktree.path'), ['{package}' => basename($project->root), '{spec}' => $id]);
    }

    /**
     * `variants:` in meta.yml, else `build.variants`, between 1 and
     * MAX_VARIANTS. Only a package with a worktree per job builds variants
     * (each needs a worktree of its own); anything else builds once.
     *
     * @param  array<mixed>  $meta
     */
    private function variants(Project $project, array $meta): int
    {
        if ($project->config->string('build.worktree.mode') !== 'per-job') {
            return 1;
        }

        $value = is_numeric($meta['variants'] ?? null) ? (int) $meta['variants'] : $project->config->int('build.variants');

        return max(1, min(self::MAX_VARIANTS, $value));
    }

    /**
     * The spec's folder relative to a checkout root (`docs/agent/specs/<id>`),
     * from its id, that relative path, or a unique part of the id; the folder
     * may be in the job worktree (see Locator).
     */
    public function dir(Project $project, string $spec): string
    {
        $specs = Locator::relative($project);
        $all = Locator::all($project);
        $id = basename(trim($spec, '/'));

        if (isset($all[$id]) && is_file($all[$id].'/meta.yml')) {
            return $specs.'/'.$id;
        }

        $matches = array_values(array_filter(array_keys($all), static fn (string $candidate): bool => str_contains($candidate, $spec)));

        if (count($matches) === 1) {
            return $specs.'/'.$matches[0];
        }

        throw new RuntimeException(count($matches) > 1 ? "`{$spec}` matches several specs; use the full id." : "No spec `{$spec}` in {$specs}/ or a job worktree.");
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
