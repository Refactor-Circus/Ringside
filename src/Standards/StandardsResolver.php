<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Standards;

use RefactorCircus\Ringside\InstalledPackage;
use RefactorCircus\Ringside\Project;

final class StandardsResolver
{
    public const string PACKAGE = 'refactor-circus/ringside';

    public function resolve(Project $project): Resolution
    {
        $installed = InstalledPackage::all($project);
        $byName = [];

        foreach ($installed as $package) {
            $byName[$package->name] = $package;
        }

        $layers = [];
        $missing = [];

        foreach ($project->config->globalStandards() as $entry) {
            if (isset($byName[$entry['package']])) {
                $layers[] = Layer::fromPackage($project, $byName[$entry['package']], $entry['strict']);
            } else {
                $missing[] = $entry['package'];
            }
        }

        $layers[] = Layer::local($project);
        $standards = [];
        $paths = [];

        foreach ($layers as $layer) {
            foreach ($layer->standards as $key => $standard) {
                $standards[$key] = isset($standards[$key])
                    ? $standard->overriding($standards[$key], $standard->reviewedHash)
                    : $standard;
            }

            foreach ($layer->paths as $category => $globs) {
                $paths[$category] = array_values(array_unique([...$paths[$category] ?? [], ...$globs]));
            }
        }

        foreach ($project->config->list('standards.exclude') as $key) {
            unset($standards[$key]);
        }

        ksort($standards);

        return new Resolution(
            layers: $layers,
            standards: Layer::withFallbackDescriptions($project, $standards),
            paths: $paths,
            fingerprint: $this->fingerprint($project, $installed, $layers),
            missing: $missing,
        );
    }

    /**
     * Installed packages that declare `extra.ring-master`: candidates for
     * `standards.global`, which `install` offers but never adds on its own.
     *
     * @return list<InstalledPackage>
     */
    public function candidates(Project $project): array
    {
        return array_values(array_filter(
            InstalledPackage::all($project),
            static fn (InstalledPackage $package): bool => $package->ringMaster() !== [] && $package->name !== self::PACKAGE,
        ));
    }

    /**
     * Everything that can change the generated output: ringside's own
     * version and templates, each standards package's version and commit,
     * ring-master.yml, and the local standards tree.
     *
     * @param  list<InstalledPackage>  $installed
     * @param  list<Layer>  $layers
     */
    private function fingerprint(Project $project, array $installed, array $layers): string
    {
        $self = array_values(array_filter($installed, static fn (InstalledPackage $p): bool => $p->name === self::PACKAGE))[0] ?? null;
        $parts = [
            $self === null ? 'dev' : $self->label().'#'.$self->reference,
            Hash::tree(dirname(__DIR__, 2).'/resources'),
            $project->config->raw,
        ];

        foreach ($layers as $layer) {
            $parts[] = $layer->source.'#'.$layer->package?->reference;
        }

        $parts[] = Hash::tree($project->path($project->agentPath('standards')));

        return substr(hash('xxh128', implode("\n", $parts)), 0, 12);
    }
}
