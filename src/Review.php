<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside;

use RefactorCircus\Ringside\Standards\FrontMatter;
use RefactorCircus\Ringside\Standards\Hash;
use RefactorCircus\Ringside\Standards\StandardsResolver;
use RuntimeException;

/**
 * Records that a local override was reviewed against the vendor file it
 * shadows, by writing that file's hash into the override's front matter.
 */
final class Review
{
    public function accept(Project $project, string $key): string
    {
        $standard = (new StandardsResolver)->resolve($project)->standards[$key] ?? null;

        if ($standard === null || ! $standard->isLocal() || $standard->overriddenPath === null) {
            throw new RuntimeException("{$key} is not a local override of a vendor standard.");
        }

        $hash = (string) Hash::file($project->path($standard->overriddenPath));
        $project->write($standard->path, FrontMatter::merge((string) $project->read($standard->path), [
            'overrides' => strstr((string) $standard->overrides, '@', true) ?: $standard->overrides,
            'reviewed_hash' => $hash,
        ]));

        return "Accepted {$key} against {$standard->overrides} ({$hash}).";
    }
}
