<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Specs;

use RuntimeException;

/**
 * Reads tasks.md into groups and orders them into waves by `depends on:`.
 *
 *     ## Group 2: CSV importer
 *     package: Atrium          (workspaces only: the package folder it changes)
 *     depends on: 1
 *     files: src/Import/CsvImporter.php, tests/Import/CsvImporterTest.php
 *     criteria: 2, 3
 *
 *     - [ ] 2.1 Write tests for header mapping
 */
final class TaskParser
{
    /**
     * @return list<array{n: int, name: string, package: ?string, depends_on: list<int>, files: list<string>, criteria: list<int>, tasks: list<array{id: string, text: string, done: bool}>, done: bool}>
     */
    public function groups(string $markdown): array
    {
        $groups = [];
        $current = null;

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^##\s+Group\s+(\d+)\s*[:.\-]?\s*(.*)$/i', $line, $m)) {
                if ($current !== null) {
                    $groups[] = $current;
                }

                $current = ['n' => (int) $m[1], 'name' => trim($m[2]), 'package' => null, 'depends_on' => [], 'files' => [], 'criteria' => [], 'tasks' => [], 'done' => false];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^package:\s*(\S+)/i', trim($line), $m)) {
                $current['package'] = $m[1];
            } elseif (preg_match('/^depends on:\s*(.*)$/i', trim($line), $m)) {
                $current['depends_on'] = self::numbers($m[1]);
            } elseif (preg_match('/^files:\s*(.*)$/i', trim($line), $m)) {
                $current['files'] = array_values(array_filter(array_map(trim(...), explode(',', $m[1]))));
            } elseif (preg_match('/^criteria:\s*(.*)$/i', trim($line), $m)) {
                $current['criteria'] = self::numbers($m[1]);
            } elseif (preg_match('/^\s*- \[([ xX])\]\s*(\S+)?\s*(.*)$/', $line, $m)) {
                $current['tasks'][] = ['id' => $m[2], 'text' => trim($m[3]), 'done' => strtolower($m[1]) === 'x'];
            }
        }

        if ($current !== null) {
            $groups[] = $current;
        }

        foreach ($groups as $i => $group) {
            $groups[$i]['done'] = $group['tasks'] !== [] && array_filter($group['tasks'], static fn (array $task): bool => ! $task['done']) === [];
        }

        return $groups;
    }

    /**
     * Unfinished groups in dependency order: each wave holds groups whose
     * dependencies are done or in an earlier wave.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<list<array<string, mixed>>>
     */
    public function waves(array $groups): array
    {
        $byNumber = [];

        foreach ($groups as $group) {
            $byNumber[(int) $group['n']] = $group;
        }

        foreach ($groups as $group) {
            foreach ((array) $group['depends_on'] as $dependency) {
                if (! isset($byNumber[(int) $dependency])) {
                    throw new RuntimeException("Group {$group['n']} depends on group {$dependency}, which does not exist.");
                }
            }
        }

        $settled = array_keys(array_filter($byNumber, self::built(...)));
        $pending = array_filter($byNumber, static fn (array $group): bool => ! self::built($group));
        $waves = [];

        while ($pending !== []) {
            $wave = array_filter($pending, static fn (array $group): bool => array_diff((array) $group['depends_on'], $settled) === []);

            if ($wave === []) {
                throw new RuntimeException('tasks.md has a dependency cycle between groups '.implode(', ', array_keys($pending)).'.');
            }

            ksort($wave);
            $waves[] = array_values($wave);
            $settled = [...$settled, ...array_keys($wave)];
            $pending = array_diff_key($pending, $wave);
        }

        return $waves;
    }

    /**
     * Whether a group needs no more building: every task is ticked except, at
     * most, the one that only runs the test suite. The gate runs the suite
     * every round, and with parallel builds the build agents are told not to,
     * so that task alone must never send the group back to be built again.
     *
     * @param  array<string, mixed>  $group
     */
    public static function built(array $group): bool
    {
        /** @var list<array{id: string, text: string, done: bool}> $tasks */
        $tasks = $group['tasks'] ?? [];

        return $tasks !== [] && array_filter($tasks, static fn (array $task): bool => ! $task['done'] && ! self::runsTests($task['text'])) === [];
    }

    /**
     * A task that only runs the tests ("Run composer test; all green"), as
     * create-tasks ends every group with.
     */
    public static function runsTests(string $text): bool
    {
        return preg_match('/^\W*run\b/i', $text) === 1
            && preg_match('/\b(tests?|suite|pest|phpunit|verify)\b/i', $text) === 1
            && preg_match('/\b(write|implement|add|fix|change|update|create)\b/i', $text) === 0;
    }

    /**
     * Pairs of groups in the same wave that list the same file; they must not run in parallel.
     *
     * @param  list<list<array<string, mixed>>>  $waves
     * @return list<string>
     */
    public function overlaps(array $waves): array
    {
        $problems = [];

        foreach ($waves as $wave) {
            $owners = [];

            foreach ($wave as $group) {
                foreach ((array) $group['files'] as $file) {
                    if (isset($owners[$file])) {
                        $problems[] = "Groups {$owners[$file]} and {$group['n']} both touch {$file}; they will not run in parallel.";
                    }

                    $owners[$file] = $group['n'];
                }
            }
        }

        return $problems;
    }

    /**
     * @return list<int>
     */
    private static function numbers(string $text): array
    {
        preg_match_all('/\d+/', $text, $m);

        return array_map(intval(...), $m[0]);
    }
}
