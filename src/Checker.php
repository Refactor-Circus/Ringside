<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside;

use RefactorCircus\Ringside\Providers\ProviderRegistry;
use RefactorCircus\Ringside\Render\SkillRenderer;
use RefactorCircus\Ringside\Standards\Hash;
use RefactorCircus\Ringside\Standards\StandardsResolver;

/**
 * The CI gate: generated output is current, every override has been
 * reviewed against the vendor file it shadows, and pint.json matches.
 */
final class Checker
{
    public function __construct(private readonly StandardsResolver $resolver = new StandardsResolver) {}

    /**
     * @return list<string> problems; empty means the check passed
     */
    public function check(Project $project): array
    {
        $resolution = $this->resolver->resolve($project);
        $problems = $project->config->validate();

        if ((new AgentKitMigrator)->needed($project)) {
            $problems[] = 'An agent-kit install is still here; run vendor/bin/ringside migrate.';
        }

        foreach ($resolution->missing as $package) {
            $problems[] = "Global standards package {$package} is configured in standards.global but not installed.";
        }

        foreach ($resolution->packageLayers() as $layer) {
            if (! $layer->strict) {
                continue;
            }

            foreach ($layer->standards as $standard) {
                if (! $standard->isArchitectural()) {
                    $problems[] = "{$layer->name} is strict, so every standard must be kind: architectural; {$standard->key} is {$standard->kind()}.";
                }
            }
        }

        $problems = [...$problems, ...(new ProviderRegistry($project))->problems()];

        foreach ($project->config->reviewers() as $reviewer) {
            if (SkillRenderer::reviewerTemplate($project, (string) $reviewer['name']) === null) {
                $problems[] = "Reviewer `{$reviewer['name']}` has no template: add {$project->agentPath('reviewers/'.$reviewer['name'].'.md')} or remove it from review.reviewers.";
            }
        }

        if (! (new Sync($this->resolver))->upToDate($project, $resolution)) {
            $problems[] = 'Generated files are stale or missing. Run: vendor/bin/ringside sync';
        }

        foreach ($resolution->standards as $standard) {
            if (! $standard->isLocal() || $standard->overrides === null) {
                continue;
            }

            if ($standard->overriddenPath === null) {
                $problems[] = "{$standard->path} declares `overrides: {$standard->overrides}` but no vendor standard `{$standard->key}` exists.";

                continue;
            }

            $current = Hash::file($project->path($standard->overriddenPath));

            if ($standard->reviewedHash === null) {
                $problems[] = "{$standard->path} overrides {$standard->overriddenPath} without a reviewed_hash. Review it, then run: vendor/bin/ringside index --accept {$standard->key}";
            } elseif ($standard->reviewedHash !== $current) {
                $problems[] = "{$standard->overriddenPath} changed since {$standard->path} was reviewed. Re-review, then run: vendor/bin/ringside index --accept {$standard->key}";
            }
        }

        $pint = $this->pintSource($project);

        if ($pint !== null && ! $project->config->bool('pint.allow_diff') && Hash::file($project->path('pint.json')) !== Hash::file($project->path($pint))) {
            $problems[] = "pint.json differs from {$pint}. Copy it with: vendor/bin/ringside install --pint (or set pint.allow_diff: true).";
        }

        return $problems;
    }

    /**
     * The canonical pint.json from the highest-priority standards package that ships one.
     */
    public function pintSource(Project $project): ?string
    {
        $source = null;

        foreach ($this->resolver->resolve($project)->packageLayers() as $layer) {
            $package = $layer->package;

            if ($package === null) {
                continue;
            }

            $pint = $package->ringMaster()['pint'] ?? null;

            if (is_string($pint) && $project->exists($package->path.'/'.$pint)) {
                $source = $package->path.'/'.$pint;
            }
        }

        return $source;
    }
}
