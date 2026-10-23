<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Render;

use RefactorCircus\Ringside\Project;

/**
 * Owns only the text between the ringside markers in AGENTS.md and
 * CLAUDE.md; the rest of each file stays hand-written.
 */
final class ManagedBlock
{
    public const string START = '<!-- ring-master:start -->';

    public const string END = '<!-- ring-master:end -->';

    /**
     * @return list<string> paths written
     */
    public function write(Project $project): array
    {
        $block = self::START."\n".trim(Template::render('agents-md-block.md', Template::vars($project)))."\n".self::END;
        $targets = array_values(array_filter(['AGENTS.md', 'CLAUDE.md'], $project->exists(...)));
        $written = [];
        $seen = [];

        foreach ($targets === [] ? ['AGENTS.md'] : $targets as $file) {
            $real = realpath($project->path($file)) ?: $project->path($file);

            if (isset($seen[$real])) {
                continue;
            }

            $seen[$real] = true;

            if ($project->write($file, self::upsert((string) $project->read($file), $block))) {
                $written[] = $file;
            }
        }

        return $written;
    }

    public static function upsert(string $contents, string $block): string
    {
        $pattern = '/'.preg_quote(self::START, '/').'.*?'.preg_quote(self::END, '/').'/s';

        if (preg_match($pattern, $contents)) {
            return (string) preg_replace_callback($pattern, static fn (): string => $block, $contents, 1);
        }

        return $contents === '' ? $block."\n" : rtrim($contents)."\n\n".$block."\n";
    }
}
