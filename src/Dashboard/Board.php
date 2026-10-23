<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Dashboard;

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Specs\Decisions;
use RefactorCircus\Ringside\Specs\SpecRepository;
use RefactorCircus\Ringside\Specs\TaskParser;
use Symfony\Component\Yaml\Yaml;

/**
 * Everything the dashboard shows, read from the spec folders on disk. Agents
 * write their reports as they go, so polling this shows a run as it happens.
 */
final class Board
{
    /** Board columns, in order, and the statuses each holds. */
    public const array COLUMNS = [
        'Ticket' => ['ticketed'],
        'Spec' => ['shaping', 'written', 'approved'],
        'Building' => ['building', 'in-progress'],
        'Needs you' => ['needs-decision', 'escalated'],
        'In review' => ['in-review'],
        'Done' => ['merged', 'closed', 'done', 'imported'],
    ];

    /**
     * @param  list<Project>  $projects
     */
    public function __construct(private readonly array $projects) {}

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $projects = [];

        foreach ($this->projects as $i => $project) {
            $projects[] = [
                'id' => $i,
                'name' => self::packageName($project),
                'specs' => array_map(fn (string $dir): array => $this->spec($project, $dir), $this->specDirs($project)),
            ];
        }

        return ['columns' => array_keys(self::COLUMNS), 'projects' => $projects];
    }

    public function project(int $id): ?Project
    {
        return $this->projects[$id] ?? null;
    }

    /**
     * A markdown or YAML file inside one of the project's spec folders, or null
     * for anything else: the dashboard never serves files outside them.
     */
    public function file(int $project, string $relative): ?string
    {
        $root = $this->project($project);

        if ($root === null || ! preg_match('/\.(md|ya?ml)$/', $relative)) {
            return null;
        }

        $specs = realpath($root->path((new SpecRepository)->dir($root)));
        $file = realpath($root->path($relative));

        if ($specs === false || $file === false || ! str_starts_with($file, $specs.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            return null;
        }

        return (string) file_get_contents($file);
    }

    public static function column(string $status): string
    {
        foreach (self::COLUMNS as $column => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $column;
            }
        }

        return 'Spec';
    }

    /**
     * @return list<string> spec folder paths relative to the project root
     */
    private function specDirs(Project $project): array
    {
        $specs = (new SpecRepository)->dir($project);
        $dirs = [];

        foreach (glob($project->path($specs).'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $dirs[] = $specs.'/'.basename($dir);
        }

        rsort($dirs);

        return $dirs;
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(Project $project, string $dir): array
    {
        $meta = Yaml::parse((string) $project->read($dir.'/meta.yml')) ?? [];
        $meta = is_array($meta) ? $meta : [];
        $status = is_string($meta['status'] ?? null) ? $meta['status'] : 'unknown';
        $groups = (new TaskParser)->groups((string) $project->read($dir.'/tasks.md'));
        $tasks = array_merge(...array_map(static fn (array $group): array => $group['tasks'], $groups ?: [['tasks' => []]]));
        $rounds = $this->rounds($project, $dir);
        $decisionFile = (string) $project->read($dir.'/decision.md');

        return [
            'id' => basename($dir),
            'dir' => $dir,
            'title' => is_string($meta['title'] ?? null) ? $meta['title'] : basename($dir),
            'status' => $status,
            'column' => self::column($status),
            'ticket' => is_array($meta['ticket'] ?? null) ? $meta['ticket'] : null,
            'pr' => is_array($meta['pr'] ?? null) ? $meta['pr'] : null,
            'branch' => is_string($meta['branch'] ?? null) ? $meta['branch'] : null,
            'tasks' => ['done' => count(array_filter($tasks, static fn (array $t): bool => $t['done'])), 'total' => count($tasks)],
            'groups' => count($groups),
            'packages' => array_values(array_unique(array_filter(array_column($groups, 'package')))),
            'rounds' => $rounds,
            'decisions' => count((new Decisions)->parse((string) $project->read($dir.'/decisions.md'))),
            'decision' => preg_match('/^\W*(APPROVED|NEEDS-DECISION|ESCALATED)\b/mi', $decisionFile, $m) ? strtolower($m[1]) : null,
            'questions' => $status === 'needs-decision' ? (int) preg_match_all('/^\d+\.\s+\*\*/m', $decisionFile) : 0,
            'files' => $this->files($project, $dir),
            'updated' => $this->updated($project, $dir),
        ];
    }

    /**
     * @return list<array{n: int, reports: list<array{name: string, verdict: ?string, path: string}>}>
     */
    private function rounds(Project $project, string $dir): array
    {
        $rounds = [];

        foreach (glob($project->path($dir).'/round-*', GLOB_ONLYDIR) ?: [] as $roundDir) {
            $reports = [];

            foreach (glob($roundDir.'/*.md') ?: [] as $file) {
                $name = basename($file, '.md');
                $first = (string) strtok((string) file_get_contents($file), "\n");

                $reports[] = [
                    'name' => $name === 'gate' ? 'gate' : (string) preg_replace('/^review-/', '', $name),
                    'verdict' => preg_match('/VERDICT:\s*(PASS|CHANGES)/i', $first, $m) ? strtolower($m[1]) : null,
                    'path' => $dir.'/'.basename($roundDir).'/'.basename($file),
                ];
            }

            usort($reports, static fn (array $a, array $b): int => [$a['name'] !== 'gate', $a['name']] <=> [$b['name'] !== 'gate', $b['name']]);
            $rounds[] = ['n' => (int) substr(basename($roundDir), 6), 'reports' => $reports];
        }

        usort($rounds, static fn (array $a, array $b): int => $a['n'] <=> $b['n']);

        return $rounds;
    }

    /**
     * @return list<string>
     */
    private function files(Project $project, string $dir): array
    {
        $files = [];

        foreach (['meta.yml', 'shape.md', 'spec.md', 'spec-review.md', 'decisions.md', 'standards.md', 'tasks.md', 'build.md', 'decision.md', 'verification.md', 'post-mortem.md', 'references.md'] as $name) {
            if ($project->exists($dir.'/'.$name)) {
                $files[] = $dir.'/'.$name;
            }
        }

        return $files;
    }

    private function updated(Project $project, string $dir): int
    {
        $latest = 0;

        foreach ([...(glob($project->path($dir).'/*') ?: []), ...(glob($project->path($dir).'/round-*/*') ?: [])] as $file) {
            $latest = max($latest, (int) filemtime($file));
        }

        return $latest;
    }

    private static function packageName(Project $project): string
    {
        $manifest = json_decode((string) $project->read('composer.json'), true);

        return is_array($manifest) && is_string($manifest['name'] ?? null) ? $manifest['name'] : basename($project->root);
    }
}
