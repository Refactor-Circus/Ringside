<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside;

use RefactorCircus\Ringside\Providers\ProviderRegistry;

/**
 * What `install` can learn about a package so ring-master.yml starts short:
 * commands from composer scripts or vendor/bin, the formatter, the code host.
 */
final class Detector
{
    /**
     * @return array<string, mixed> ring-master.yml values that differ from the defaults
     */
    public function detect(Project $project): array
    {
        $manifest = json_decode((string) $project->read('composer.json'), true);
        $scripts = is_array($manifest) && is_array($manifest['scripts'] ?? null) ? $manifest['scripts'] : [];
        $bin = static fn (string $tool): bool => $project->exists($project->vendorDir.'/bin/'.$tool);
        $runner = $bin('pest') ? 'vendor/bin/pest' : ($bin('phpunit') ? 'vendor/bin/phpunit' : null);
        $formatter = $bin('pint') ? 'pint' : ($bin('php-cs-fixer') ? 'php-cs-fixer' : null);

        $commands = [
            'test' => isset($scripts['test:unit']) ? 'composer test:unit' : $runner,
            'verify' => isset($scripts['test']) ? 'composer test' : $runner,
            'format' => isset($scripts['lint']) ? 'composer lint' : match ($formatter) {
                'pint' => 'vendor/bin/pint',
                'php-cs-fixer' => 'vendor/bin/php-cs-fixer fix',
                default => null,
            },
            'format_check' => isset($scripts['lint:check']) ? 'composer lint:check' : match ($formatter) {
                'pint' => 'vendor/bin/pint --test',
                'php-cs-fixer' => 'vendor/bin/php-cs-fixer fix --dry-run',
                default => null,
            },
            'browser_test' => isset($scripts['test:browser']) ? 'composer test:browser' : null,
        ];

        $detected = [];
        $defaults = Config::defaults();

        foreach ($commands as $key => $command) {
            if ($command !== ($defaults['commands'][$key] ?? null)) {
                $detected['commands'][$key] = $command;
            }
        }

        $hook = match ($formatter) {
            'pint' => null,
            'php-cs-fixer' => ['enabled' => true, 'command' => 'vendor/bin/php-cs-fixer fix {file}'],
            default => ['enabled' => false, 'command' => null],
        };

        if ($hook !== null) {
            $detected['hooks']['post_edit_format'] = $hook;
        }

        $host = ProviderRegistry::hostFromRemote(Shell::gitRemote($project->root));

        if ($host === 'bitbucket') {
            $detected['tickets'] = ['providers' => ['jira' => ['site' => null, 'project' => null]]];
        }

        if ($host === null) {
            $detected['tickets'] = ['default' => 'file', 'providers' => ['file' => []]];
        }

        return $detected;
    }

    public function pestMajor(Project $project): ?int
    {
        $pest = InstalledPackage::find($project, 'pestphp/pest');

        return $pest !== null && preg_match('/^v?(\d+)/', $pest->version, $matches) ? (int) $matches[1] : null;
    }
}
