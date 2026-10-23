<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Scaffold;

use RefactorCircus\Ringside\Config;
use RefactorCircus\Ringside\Project;
use Symfony\Component\Yaml\Yaml;

/**
 * Adds a package to `workspace.packages` and `dashboard.projects` in
 * ring-master.yml, keeping the file's comments when the lists are written
 * inline (`packages: [A, B]`).
 */
final class WorkspaceConfig
{
    public function __construct(private readonly Project $root) {}

    public function addPackage(string $package): void
    {
        $yaml = (string) $this->root->read(Config::FILE);

        foreach (['workspace' => 'packages', 'dashboard' => 'projects'] as $section => $key) {
            $yaml = $this->append($yaml, $section, $key, $package);
        }

        $this->root->write(Config::FILE, $yaml);
    }

    private function append(string $yaml, string $section, string $key, string $package): string
    {
        $parsed = $yaml === '' ? [] : Yaml::parse($yaml);
        $current = is_array($parsed) ? ($parsed[$section][$key] ?? null) : null;

        if (is_array($current) && in_array($package, $current, true)) {
            return $yaml;
        }

        $inline = '/^(\s+'.$key.':\s*\[)([^\]]*)\]/m';
        $inSection = '/^'.$section.':\s*\n((?:[ \t]+.*\n|\n)*)/m';

        if (preg_match($inSection, $yaml, $block) && preg_match($inline, $block[1])) {
            $updated = (string) preg_replace_callback($inline, static fn (array $m): string => $m[1].(trim($m[2]) === '' ? $package : rtrim($m[2]).', '.$package).']', $block[1], 1);

            return str_replace($block[0], $section.":\n".$updated, $yaml);
        }

        // Block-style or missing list: rewrite that section only.
        $parsed = is_array($parsed) ? $parsed : [];
        $parsed[$section][$key] = [...(is_array($current) ? $current : []), $package];

        return Yaml::dump($parsed, 4, 2);
    }
}
