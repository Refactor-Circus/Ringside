<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RefactorCircus\Ringside\Standards\FrontMatter;
use RefactorCircus\Ringside\Standards\Hash;
use RefactorCircus\Ringside\Standards\Layer;
use RefactorCircus\Ringside\Standards\StandardsResolver;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * Moves an Agent OS install (agent-os/ + its generated commands) into the
 * ringside layout. Moves use rename(), so git records them as renames.
 */
final class Migrator
{
    public const string LEGACY_DIR = 'agent-os';

    /** @var list<string> */
    private const array LEGACY_COMMANDS = ['.claude/commands/agent-os', '.agents/commands/agent-os'];

    public function __construct(private readonly StandardsResolver $resolver = new StandardsResolver) {}

    /**
     * @return list<string> one line per action, prefixed "Would" when dry
     */
    public function migrate(Project $project, bool $dryRun = false): array
    {
        $log = [
            ...$this->standards($project, $dryRun),
            ...$this->specs($project, $dryRun),
            ...$this->moveTree($project, self::LEGACY_DIR.'/product', $project->agentPath('product'), $dryRun),
        ];

        foreach (self::LEGACY_COMMANDS as $dir) {
            if (is_dir($project->path($dir))) {
                $log[] = ($dryRun ? 'Would delete ' : 'Deleted ').$dir.'/';

                if (! $dryRun) {
                    self::removeTree($project->path($dir));
                    self::removeIfEmpty(dirname($project->path($dir)));
                }
            }
        }

        if (! $dryRun) {
            if (is_file($project->path(self::LEGACY_DIR.'/standards/index.yml'))) {
                unlink($project->path(self::LEGACY_DIR.'/standards/index.yml'));
            }

            self::removeEmptyDirs($project->path(self::LEGACY_DIR));
        }

        $left = self::files($project->path(self::LEGACY_DIR));

        if ($dryRun || $left === []) {
            $log[] = $dryRun ? 'Would delete '.self::LEGACY_DIR.'/' : 'Deleted '.self::LEGACY_DIR.'/';
        } else {
            $log[] = 'Kept '.self::LEGACY_DIR.'/: '.count($left).' file(s) had no ringside home: '.implode(', ', array_slice($left, 0, 5));
        }

        return $log;
    }

    /**
     * Local standards become docs/agent/standards/ files with their index
     * entry folded into front matter. A copy identical to a vendor standard
     * is dropped; one that differs stays as an override awaiting review.
     *
     * @return list<string>
     */
    private function standards(Project $project, bool $dryRun): array
    {
        $legacy = self::LEGACY_DIR.'/standards';
        $index = Layer::readIndex($project->path($legacy.'/index.yml'));
        $vendor = [];
        $renames = [];
        $log = [];

        foreach ($this->resolver->resolve($project)->packageLayers() as $layer) {
            $vendor = [...$vendor, ...$layer->standards];
            $declared = $layer->package?->ringMaster()['renames'] ?? [];
            $renames = [...$renames, ...(is_array($declared) ? array_filter($declared, is_string(...)) : [])];
        }

        foreach (Layer::markdownFiles($project->path($legacy)) as $oldKey) {
            // A global package that renamed a standard declares it, so an old local copy still lands as its override.
            $key = $renames[$oldKey] ?? $oldKey;
            $from = $legacy.'/'.$oldKey.'.md';
            $to = $project->agentPath('standards/'.$key.'.md');
            $contents = (string) $project->read($from);
            $match = $vendor[$key] ?? null;

            if ($match !== null && Hash::contents($contents) === Hash::file($project->path($match->path))) {
                $log[] = ($dryRun ? 'Would drop ' : 'Dropped ')."{$from} (identical to {$match->source})";

                if (! $dryRun) {
                    unlink($project->path($from));
                }

                continue;
            }

            if ($project->exists($to)) {
                $log[] = "Skipped {$from}: {$to} already exists";

                continue;
            }

            [$meta] = FrontMatter::parse($contents);
            $entry = $index[$oldKey] ?? [];
            $add = array_filter([
                'description' => $meta['description'] ?? $entry['description'] ?? null,
                'tags' => $meta['tags'] ?? $entry['tags'] ?? null,
                'overrides' => $match === null ? null : strstr($match->source, '@', true),
            ], static fn (mixed $value): bool => $value !== null);

            $log[] = ($dryRun ? 'Would move ' : 'Moved ')."{$from} -> {$to}".($match === null ? '' : " (overrides {$match->source}; review it)");

            if (! $dryRun) {
                $this->move($project, $from, $to);
                $project->write($to, FrontMatter::merge((string) $project->read($to), $add));
            }
        }

        return $log;
    }

    /**
     * @return list<string>
     */
    private function specs(Project $project, bool $dryRun): array
    {
        $log = [];

        foreach (glob($project->path(self::LEGACY_DIR.'/specs').'/*', GLOB_ONLYDIR) ?: [] as $folder) {
            $id = basename($folder);
            $to = $project->agentPath('specs/'.$id);
            $log = [...$log, ...$this->moveTree($project, self::LEGACY_DIR.'/specs/'.$id, $to, $dryRun)];

            if ($dryRun) {
                continue;
            }

            if (! $project->exists($to.'/meta.yml')) {
                [, $body] = FrontMatter::parse((string) ($project->read($to.'/shape.md') ?? $project->read($to.'/plan.md') ?? ''));

                $project->write($to.'/meta.yml', Yaml::dump([
                    'id' => $id,
                    'title' => preg_replace('/\s+—\s+Shaping Notes$/u', '', FrontMatter::firstHeading($body) ?? $id),
                    'status' => 'imported',
                    'created' => preg_match('/^(\d{4}-\d{2}-\d{2})/', $id, $date) ? $date[1] : null,
                    'imported_from' => 'agent-os',
                ]));
            }
        }

        return $log;
    }

    /**
     * @return list<string>
     */
    private function moveTree(Project $project, string $from, string $to, bool $dryRun): array
    {
        if (! is_dir($project->path($from))) {
            return [];
        }

        $log = [];

        foreach (self::files($project->path($from)) as $file) {
            if ($project->exists($to.'/'.$file)) {
                $log[] = "Skipped {$from}/{$file}: {$to}/{$file} already exists";

                continue;
            }

            if (! $dryRun) {
                $this->move($project, $from.'/'.$file, $to.'/'.$file);
            }
        }

        $log[] = ($dryRun ? 'Would move ' : 'Moved ')."{$from}/ -> {$to}/";

        return $log;
    }

    private function move(Project $project, string $from, string $to): void
    {
        $target = $project->path($to);

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0o755, true);
        }

        rename($project->path($from), $target);
    }

    /**
     * @return list<string> paths relative to $dir
     */
    private static function files(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getFilename() !== '.DS_Store') {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            }
        }

        sort($files);

        return $files;
    }

    public static function removeTree(string $dir): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }

    private static function removeEmptyDirs(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }

            if ($file->isFile() && $file->getFilename() === '.DS_Store') {
                unlink($file->getPathname());
            } elseif ($file->isDir()) {
                self::removeIfEmpty($file->getPathname());
            }
        }

        self::removeIfEmpty($dir);
    }

    private static function removeIfEmpty(string $dir): void
    {
        if (is_dir($dir) && array_diff((array) scandir($dir), ['.', '..']) === []) {
            rmdir($dir);
        }
    }
}
