<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Standards;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RefactorCircus\Ringside\InstalledPackage;
use RefactorCircus\Ringside\Project;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * One source of standards: a standards package, or the project's own
 * docs/agent/standards/ folder.
 */
final readonly class Layer
{
    /**
     * @param  array<string, Standard>  $standards
     * @param  array<string, list<string>>  $paths
     */
    public function __construct(
        public string $name,
        public string $source,
        public array $standards,
        public int $priority = 0,
        public ?InstalledPackage $package = null,
        public array $paths = [],
        public bool $strict = false,
    ) {}

    public static function fromPackage(Project $project, InstalledPackage $package, bool $strict = false): self
    {
        $config = $package->ringMaster();
        $packageKind = is_string($config['kind'] ?? null) ? $config['kind'] : null;
        $root = $package->path.'/'.trim(self::string($config, 'root', 'standards'), '/');
        $index = self::readIndex($project->path($package->path.'/'.self::string($config, 'index', 'index.yml')));
        $standards = [];

        foreach (self::markdownFiles($project->path($root)) as $key) {
            $entry = $index[$key] ?? [];

            $standards[$key] = new Standard(
                key: $key,
                path: $root.'/'.$key.'.md',
                source: $package->label(),
                description: self::string($entry, 'description', ''),
                tags: self::strings($entry['tags'] ?? []),
                enforcedBy: self::strings($entry['enforced_by'] ?? []),
                kind: is_string($entry['kind'] ?? null) ? $entry['kind'] : $packageKind,
            );
        }

        $paths = [];

        foreach (is_array($config['paths'] ?? null) ? $config['paths'] : [] as $category => $globs) {
            $paths[(string) $category] = self::strings($globs);
        }

        return new self(
            name: $package->name,
            source: $package->label(),
            standards: $standards,
            priority: is_numeric($config['priority'] ?? null) ? (int) $config['priority'] : 50,
            package: $package,
            paths: $paths,
            strict: $strict,
        );
    }

    public static function local(Project $project): self
    {
        $root = $project->agentPath('standards');
        $standards = [];

        foreach (self::markdownFiles($project->path($root)) as $key) {
            $path = $root.'/'.$key.'.md';
            [$meta] = FrontMatter::parse((string) $project->read($path));

            $standards[$key] = new Standard(
                key: $key,
                path: $path,
                source: 'local',
                description: self::string($meta, 'description', ''),
                tags: self::strings($meta['tags'] ?? []),
                enforcedBy: self::strings($meta['enforced_by'] ?? []),
                overrides: is_scalar($meta['overrides'] ?? null) ? (string) $meta['overrides'] : null,
                reviewedHash: is_scalar($meta['reviewed_hash'] ?? null) ? (string) $meta['reviewed_hash'] : null,
                kind: is_string($meta['kind'] ?? null) ? $meta['kind'] : null,
            );
        }

        return new self(name: 'local', source: 'local', standards: $standards, priority: PHP_INT_MAX);
    }

    /**
     * Accepts the flat `category/name: {...}` format and Agent OS's nested
     * `category: {name: {...}}` format.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function readIndex(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $parsed = Yaml::parseFile($file);
        $index = [];

        foreach (is_array($parsed) ? $parsed : [] as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if (array_key_exists('description', $value)) {
                $index[(string) $key] = $value;

                continue;
            }

            foreach ($value as $name => $entry) {
                if (is_array($entry)) {
                    $index[$key.'/'.$name] = $entry;
                }
            }
        }

        return $index;
    }

    /**
     * Standard keys (`category/name`) for every .md file under a directory.
     *
     * @return list<string>
     */
    public static function markdownFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $keys = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'md') {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                $keys[] = substr($relative, 0, -3);
            }
        }

        sort($keys);

        return $keys;
    }

    /**
     * Fills an empty description from the file's first heading. Runs after
     * layering, so a local override with no description inherits the vendor one.
     *
     * @param  array<string, Standard>  $standards
     * @return array<string, Standard>
     */
    public static function withFallbackDescriptions(Project $project, array $standards): array
    {
        foreach ($standards as $key => $standard) {
            if ($standard->description !== '') {
                continue;
            }

            [, $body] = FrontMatter::parse((string) $project->read($standard->path));

            $standards[$key] = $standard->with(description: FrontMatter::firstHeading($body) ?? $key);
        }

        return $standards;
    }

    /**
     * @param  array<mixed>  $values
     */
    private static function string(array $values, string $key, string $default): string
    {
        return is_scalar($values[$key] ?? null) ? (string) $values[$key] : $default;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_map(strval(...), array_filter($values, is_scalar(...)))) : [];
    }
}
