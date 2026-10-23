<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside;

final readonly class InstalledPackage
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $name,
        public string $version,
        public ?string $reference,
        public string $type,
        public array $extra,
        public string $path,
    ) {}

    /**
     * Reads vendor/composer/installed.json. `path` is relative to the
     * project root and keeps the vendor path, not a symlink's target, so a
     * path-repository checkout resolves to the same index entries as a VCS one.
     *
     * @return list<self>
     */
    public static function all(Project $project): array
    {
        $file = $project->path($project->vendorDir.'/composer/installed.json');

        if (! is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        $packages = is_array($data) ? ($data['packages'] ?? $data) : [];
        $installed = [];

        foreach (is_array($packages) ? $packages : [] as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                continue;
            }

            $installed[] = new self(
                name: $package['name'],
                version: is_string($package['version'] ?? null) ? $package['version'] : 'dev',
                reference: self::reference($package),
                type: is_string($package['type'] ?? null) ? $package['type'] : 'library',
                extra: is_array($package['extra'] ?? null) ? $package['extra'] : [],
                path: self::path($project, $package),
            );
        }

        return $installed;
    }

    public static function find(Project $project, string $name): ?self
    {
        foreach (self::all($project) as $package) {
            if ($package->name === $name) {
                return $package;
            }
        }

        return null;
    }

    public function label(): string
    {
        return $this->name.'@'.$this->version;
    }

    /**
     * @return array<string, mixed>
     */
    public function ringMaster(): array
    {
        $config = $this->extra['ring-master'] ?? $this->extra['ring-master'] ?? null; // ringside: legacy key, read until 1.0

        return is_array($config) ? $config : [];
    }

    /**
     * @param  array<mixed>  $package
     */
    private static function reference(array $package): ?string
    {
        foreach (['source', 'dist'] as $kind) {
            $reference = $package[$kind]['reference'] ?? null;

            if (is_string($reference) && $reference !== '') {
                return substr($reference, 0, 12);
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $package
     */
    private static function path(Project $project, array $package): string
    {
        $installPath = $package['install-path'] ?? null;

        if (! is_string($installPath)) {
            return $project->vendorDir.'/'.$package['name'];
        }

        $segments = [];

        foreach (explode('/', $project->vendorDir.'/composer/'.str_replace('\\', '/', $installPath)) as $segment) {
            match ($segment) {
                '', '.' => null,
                '..' => array_pop($segments),
                default => $segments[] = $segment,
            };
        }

        return implode('/', $segments);
    }
}
