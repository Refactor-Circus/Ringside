<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Specs;

use RefactorCircus\Ringside\Project;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Every job's state, read from the spec folders on disk (each in its job's
 * worktree, or on main once merged; see Locator); the RingMaster app imports
 * from this. Agents write their reports as they go, so reading it again shows
 * a run as it happens.
 */
final class Board
{
    /** The status of a spec whose meta.yml is not valid YAML. */
    public const string UNREADABLE = 'unreadable';

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
            $worktrees = Locator::worktrees($project);
            $specs = Locator::all($project, $worktrees);
            $projects[] = [
                'id' => $i,
                'name' => self::packageName($project),
                'specs' => array_map(fn (string $id, string $path): array => $this->spec($project, $id, $path, $worktrees[$id] ?? null), array_keys($specs), $specs),
            ];
        }

        return ['columns' => array_keys(self::COLUMNS), 'projects' => $projects];
    }

    public function project(int $id): ?Project
    {
        return $this->projects[$id] ?? null;
    }

    /**
     * A markdown or YAML file inside one of the project's spec folders, named
     * by its path from the checkout root (`docs/agent/specs/<id>/spec.md`,
     * read from wherever that spec lives), or null for anything else: never
     * reads files outside them.
     */
    public function file(int $project, string $relative): ?string
    {
        $root = $this->project($project);
        $prefix = $root === null ? '' : Locator::relative($root).'/';

        if ($root === null || ! preg_match('/\.(md|ya?ml)$/', $relative) || ! str_starts_with(ltrim($relative, '/'), $prefix)) {
            return null;
        }

        [$id, $inside] = array_pad(explode('/', substr(ltrim($relative, '/'), strlen($prefix)), 2), 2, '');
        $folder = Locator::find($root, $id);
        $specs = $folder === null ? false : realpath($folder);
        $file = $specs === false ? false : realpath($specs.'/'.$inside);

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
     * $path is the spec folder wherever it lives ($worktree, when it is in a
     * job worktree); dir, files and report paths
     * stay relative to the checkout root, the same in every checkout.
     *
     * @return array<string, mixed>
     */
    private function spec(Project $project, string $id, string $path, ?string $worktree): array
    {
        $dir = Locator::relative($project).'/'.$id;
        $read = static fn (string $name): ?string => is_file($path.'/'.$name) ? (string) file_get_contents($path.'/'.$name) : null;

        // A meta.yml a session broke must not take the whole board down: the spec reads as unreadable, with why.
        try {
            $meta = Yaml::parse((string) $read('meta.yml')) ?? [];
            $metaError = null;
        } catch (ParseException $e) {
            $meta = [];
            $metaError = $e->getMessage();
        }

        $meta = is_array($meta) ? $meta : [];
        $status = $metaError !== null ? self::UNREADABLE : (is_string($meta['status'] ?? null) ? $meta['status'] : 'unknown');
        $groups = (new TaskParser)->groups((string) $read('tasks.md'));
        $tasks = array_merge(...array_map(static fn (array $group): array => $group['tasks'], $groups ?: [['tasks' => []]]));
        $rounds = $this->rounds($dir, $path);
        $decisionFile = (string) $read('decision.md');
        $questionsFile = $read(Questions::FILE);
        $questions = $questionsFile === null ? [] : (new Questions)->parse($questionsFile);

        return [
            'id' => $id,
            'dir' => $dir,
            'path' => $path,
            // The job worktree that holds the spec, or null once it is only on main.
            'worktree' => $worktree,
            'title' => is_string($meta['title'] ?? null) ? $meta['title'] : $id,
            'status' => $status,
            'meta_error' => $metaError,
            'column' => self::column($status),
            'ticket' => is_array($meta['ticket'] ?? null) ? $meta['ticket'] : null,
            // A bare URL is how a session sometimes writes it; read it as {url}.
            'pr' => match (true) {
                is_array($meta['pr'] ?? null) => $meta['pr'],
                is_string($meta['pr'] ?? null) && $meta['pr'] !== '' => ['url' => $meta['pr']],
                default => null,
            },
            'branch' => is_string($meta['branch'] ?? null) ? $meta['branch'] : null,
            'tasks' => ['done' => count(array_filter($tasks, static fn (array $t): bool => $t['done'])), 'total' => count($tasks)],
            'groups' => count($groups),
            'packages' => array_values(array_unique(array_filter(array_column($groups, 'package')))),
            'rounds' => $rounds,
            'decisions' => count((new Decisions)->parse((string) $read('decisions.md'))),
            'decision' => preg_match('/^\W*(APPROVED|NEEDS-DECISION|ESCALATED)\b/mi', $decisionFile, $m) ? strtolower($m[1]) : null,
            'questions' => $questionsFile !== null ? count($questions) : ($status === 'needs-decision' ? (int) preg_match_all('/^\d+\.\s+\*\*/m', $decisionFile) : 0),
            'open_questions' => $questions,
            'files' => $this->files($dir, $path),
            'updated' => $this->updated($path),
        ];
    }

    /**
     * @return list<array{n: int, reports: list<array{name: string, verdict: ?string, path: string}>}>
     */
    private function rounds(string $dir, string $path): array
    {
        $rounds = [];

        foreach (glob($path.'/round-*', GLOB_ONLYDIR) ?: [] as $roundDir) {
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
    private function files(string $dir, string $path): array
    {
        $files = [];

        foreach (['meta.yml', 'shape.md', 'spec.md', 'spec-review.md', 'decisions.md', 'standards.md', 'tasks.md', 'build.md', 'decision.md', 'questions.yml', 'verification.md', 'post-mortem.md', 'references.md', 'brief.md', 'variants.md'] as $name) {
            if (file_exists($path.'/'.$name)) {
                $files[] = $dir.'/'.$name;
            }
        }

        return $files;
    }

    private function updated(string $path): int
    {
        $latest = 0;

        foreach ([...(glob($path.'/*') ?: []), ...(glob($path.'/round-*/*') ?: [])] as $file) {
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
