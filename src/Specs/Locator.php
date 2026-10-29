<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Specs;

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Shell;

/**
 * Where each spec folder is. A job's spec lives in its own worktree, on the
 * job branch, from the moment it is created, so the record is committed with
 * the job; the main checkout holds only the specs already merged. A spec in a
 * worktree wins over its merged copy on main.
 */
final class Locator
{
    /** Every job branch starts with this. */
    public const string BRANCH_PREFIX = 'ring-master/';

    /** The suffix of the branch that records a merged job's post-mortem. */
    public const string RECORD = '-record';

    /** Group, variant and replay worktrees build a job; they never hold its record. */
    private const string BUILD_SUFFIX = '/--(?:replay|[gv]\d+)$/';

    /**
     * $worktrees, when given, is what worktrees() returned, so a caller that
     * needs both runs git once.
     *
     * @param  array<string, string>|null  $worktrees
     * @return array<string, string> spec id => absolute folder, newest id first
     */
    public static function all(Project $project, ?array $worktrees = null): array
    {
        $specs = [];

        foreach (glob($project->path(self::relative($project)).'/*', GLOB_ONLYDIR) ?: [] as $folder) {
            $specs[basename($folder)] = $folder;
        }

        foreach ($worktrees ?? self::worktrees($project) as $id => $worktree) {
            $specs[$id] = rtrim($worktree, '/').'/'.self::relative($project).'/'.$id;
        }

        krsort($specs);

        return $specs;
    }

    public static function find(Project $project, string $id): ?string
    {
        return self::all($project)[$id] ?? null;
    }

    /**
     * The worktree that holds a spec, or null when the spec is only on main.
     */
    public static function worktree(Project $project, string $id): ?string
    {
        return self::worktrees($project)[$id] ?? null;
    }

    /**
     * Job worktrees that hold their own spec folder: a linked worktree on
     * `ring-master/<id>` (or `ring-master/<id>-record`) with the folder of
     * spec <id> in it.
     *
     * @return array<string, string> spec id => absolute worktree path
     */
    public static function worktrees(Project $project): array
    {
        [$code, $list] = Shell::run(['git', 'worktree', 'list', '--porcelain'], $project->root);

        if ($code !== 0) {
            return [];
        }

        $found = [];
        $records = [];
        $main = null;

        foreach (preg_split('/\R\R/', trim($list)) ?: [] as $entry) {
            if (! preg_match('/^worktree (.+)$/m', $entry, $path)) {
                continue;
            }

            // git lists the main checkout first.
            if ($main === null) {
                $main = $path[1];

                continue;
            }

            if (! preg_match('~^branch refs/heads/'.preg_quote(self::BRANCH_PREFIX, '~').'(.+)$~m', $entry, $branch) || preg_match(self::BUILD_SUFFIX, $branch[1]) === 1) {
                continue;
            }

            $record = str_ends_with($branch[1], self::RECORD);
            $id = $record ? substr($branch[1], 0, -strlen(self::RECORD)) : $branch[1];

            // A record worktree is newer than the merged job's own, which the janitor has yet to remove.
            if (is_file($path[1].'/'.self::relative($project).'/'.$id.'/meta.yml') && ($record || ! isset($records[$id]))) {
                $found[$id] = $path[1];
                $records[$id] = $record;
            }
        }

        return $found;
    }

    /** The specs folder relative to any checkout of the package. */
    public static function relative(Project $project): string
    {
        return $project->agentPath('specs');
    }
}
