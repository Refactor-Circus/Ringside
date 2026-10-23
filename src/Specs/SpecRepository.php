<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Specs;

use RefactorCircus\Ringside\Project;
use Symfony\Component\Yaml\Yaml;

final class SpecRepository
{
    public const array STATUSES = ['ticketed', 'shaping', 'written', 'approved', 'building', 'needs-decision', 'escalated', 'in-review', 'merged', 'closed', 'in-progress', 'done', 'imported'];

    public function dir(Project $project): string
    {
        return $project->agentPath('specs');
    }

    /**
     * @return list<Spec>
     */
    public function all(Project $project): array
    {
        $specs = [];

        foreach (glob($project->path($this->dir($project)).'/*', GLOB_ONLYDIR) ?: [] as $folder) {
            $id = basename($folder);
            $metaFile = $folder.'/meta.yml';
            $meta = is_file($metaFile) ? Yaml::parseFile($metaFile) : [];
            $meta = is_array($meta) ? $meta : [];
            $tasks = is_file($folder.'/tasks.md') ? (string) file_get_contents($folder.'/tasks.md') : '';

            $specs[] = new Spec(
                id: $id,
                title: is_string($meta['title'] ?? null) ? $meta['title'] : $id,
                status: is_string($meta['status'] ?? null) ? $meta['status'] : 'unknown',
                standards: self::standards($meta['standards'] ?? []),
                openTasks: (int) preg_match_all('/^\s*- \[ \]/m', $tasks),
                doneTasks: (int) preg_match_all('/^\s*- \[x\]/mi', $tasks),
            );
        }

        usort($specs, static fn (Spec $a, Spec $b): int => strcmp($b->id, $a->id));

        return $specs;
    }

    /**
     * @return array<string, string>
     */
    private static function standards(mixed $standards): array
    {
        $versions = [];

        foreach (is_array($standards) ? $standards : [] as $name => $value) {
            $versions[(string) $name] = is_array($value)
                ? trim((is_scalar($value['version'] ?? null) ? (string) $value['version'] : '?').' '.(is_scalar($value['reference'] ?? null) ? (string) $value['reference'] : ''))
                : (is_scalar($value) ? (string) $value : '?');
        }

        return $versions;
    }
}
