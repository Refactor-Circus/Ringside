<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Discovery;

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Shell;
use RefactorCircus\Ringside\Standards\FrontMatter;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Turns a queued global proposal into a commit on a branch of a local
 * checkout of the global standards package. Pushing and opening the PR or
 * issue happen in the skill, through the host adapter.
 */
final class Proposer
{
    public function queueDir(Project $project): string
    {
        return $project->agentPath('standards-proposals/global');
    }

    /**
     * @return list<string> queued proposal keys
     */
    public function queued(Project $project): array
    {
        $dir = $project->path($this->queueDir($project));
        $keys = [];

        foreach (glob($dir.'/{,*/}*.md', GLOB_BRACE) ?: [] as $file) {
            $keys[] = substr(str_replace('\\', '/', substr($file, strlen($dir) + 1)), 0, -3);
        }

        sort($keys);

        return $keys;
    }

    /**
     * @return array{branch: string, file: string}
     */
    public function propose(Project $project, string $key, string $repo): array
    {
        $proposal = $project->read($this->queueDir($project).'/'.$key.'.md');

        if ($proposal === null) {
            throw new RuntimeException("No queued global proposal `{$key}`.");
        }

        [$meta, $body] = FrontMatter::parse($proposal);

        if (($meta['kind'] ?? 'architectural') !== 'architectural') {
            throw new RuntimeException("`{$key}` is kind {$meta['kind']}; global standards are architectural only. Keep it as a package standard.");
        }

        $manifest = is_file($repo.'/composer.json') ? json_decode((string) file_get_contents($repo.'/composer.json'), true) : null;
        $extra = is_array($manifest) && is_array($manifest['extra'] ?? null) ? $manifest['extra'] : [];
        $config = $extra['ring-master'] ?? $extra['ring-master'] ?? null;
        $config = is_array($config) ? $config : null;

        if ($config === null) {
            throw new RuntimeException("{$repo} is not a standards package (no extra.ring-master in composer.json).");
        }

        $package = basename($project->root);
        $branch = 'proposal/'.strtolower($package).'-'.str_replace('/', '-', $key);
        $file = trim(is_string($config['root'] ?? null) ? $config['root'] : 'standards', '/').'/'.$key.'.md';
        $index = is_string($config['index'] ?? null) ? $config['index'] : 'index.yml';

        [$code, $out] = Shell::run(['git', 'checkout', '-b', $branch], $repo);

        if ($code !== 0) {
            throw new RuntimeException("Could not create branch {$branch} in {$repo}: {$out}");
        }

        if (! is_dir(dirname($repo.'/'.$file))) {
            mkdir(dirname($repo.'/'.$file), 0o755, true);
        }

        file_put_contents($repo.'/'.$file, trim($body)."\n");

        $entry = [$key => [
            'description' => (string) ($meta['description'] ?? $key),
            'tags' => is_array($meta['tags'] ?? null) ? array_values($meta['tags']) : [],
            'kind' => 'architectural',
            'enforced_by' => [],
        ]];
        $existing = is_file($repo.'/'.$index) ? (string) file_get_contents($repo.'/'.$index) : '';

        if (! str_contains($existing, "\n{$key}:") && ! str_starts_with($existing, "{$key}:")) {
            file_put_contents($repo.'/'.$index, rtrim($existing)."\n".Yaml::dump($entry, 3, 2));
        }

        Shell::run(['git', 'add', $file, $index], $repo);
        [$code, $out] = Shell::run(['git', 'commit', '-m', "Propose {$key} from {$package}"], $repo);

        if ($code !== 0) {
            throw new RuntimeException("Could not commit in {$repo}: {$out}");
        }

        return ['branch' => $branch, 'file' => $file];
    }
}
