<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Shell;

/**
 * A throwaway consuming package with refactor-circus/standards "installed"
 * under vendor/ the way Composer would, plus optional extra files.
 *
 * @param  array<string, string>  $files  project-relative path => contents
 * @param  array<string, string>  $standards  vendor standard key => contents
 */
function fixtureProject(array $files = [], ?array $standards = null, array $extra = []): Project
{
    $root = sys_get_temp_dir().'/ring-master-'.bin2hex(random_bytes(6));
    mkdir($root, 0o755, true);

    $standards ??= [
        'testing/pest-style' => "# Pest Style\n\nUse it().\n",
        'architecture/actions-events' => "# Actions & Events\n\nExecute then handle.\n",
    ];

    $vendor = 'vendor/refactor-circus/standards';
    $index = '';

    foreach ($standards as $key => $contents) {
        writeFixture($root, "{$vendor}/standards/{$key}.md", $contents);
        $index .= "{$key}:\n  description: \"About {$key}\"\n  tags: [".strtok($key, '/')."]\n  kind: architectural\n  enforced_by: []\n";
    }

    writeFixture($root, "{$vendor}/index.yml", $index);
    writeFixture($root, "{$vendor}/pint.json", "{\"preset\": \"laravel\"}\n");

    writeFixture($root, 'composer.json', json_encode(['name' => 'refactor-circus/fixture', 'require-dev' => ['refactor-circus/ringside' => '^0.1']], JSON_PRETTY_PRINT));
    writeFixture($root, 'vendor/composer/installed.json', json_encode(['packages' => [
        [
            'name' => 'refactor-circus/standards',
            'version' => '0.1.0',
            'type' => 'phpstan-extension',
            'source' => ['reference' => 'abc123def4567890'],
            'install-path' => '../refactor-circus/standards',
            'extra' => ['ring-master' => [
                'priority' => 10,
                'root' => 'standards',
                'index' => 'index.yml',
                'pint' => 'pint.json',
                'pest-preset' => ['class' => 'RefactorCircus\\Standards\\Pest\\ArchPreset', 'name' => 'refactorCircus'],
                'paths' => ['testing' => ['tests/**']],
                ...$extra,
            ]],
        ],
        [
            'name' => 'pestphp/pest',
            'version' => 'v5.3.1',
            'type' => 'library',
            'install-path' => '../pestphp/pest',
        ],
        [
            'name' => 'refactor-circus/ringside',
            'version' => '0.1.0',
            'type' => 'composer-plugin',
            'install-path' => '../refactor-circus/ringside',
        ],
    ], 'dev' => true], JSON_PRETTY_PRINT));

    $files['ring-master.yml'] = GLOBAL_CONFIG.($files['ring-master.yml'] ?? '');

    foreach ($files as $path => $contents) {
        writeFixture($root, $path, $contents);
    }

    return new Project($root);
}

const GLOBAL_CONFIG = "standards:\n  global: [refactor-circus/standards]\n";

/**
 * Replaces the fixture's ring-master.yml, keeping the global standards line.
 */
function withConfig(Project $project, string $yaml): Project
{
    writeFixture($project->root, 'ring-master.yml', GLOBAL_CONFIG.$yaml);

    return new Project($project->root);
}

function writeFixture(string $root, string $path, string $contents): void
{
    $file = $root.'/'.$path;

    if (! is_dir(dirname($file))) {
        mkdir(dirname($file), 0o755, true);
    }

    file_put_contents($file, $contents);
}

/**
 * A package folder `$root/$name` with a composer.json, committed as the
 * first commit of its own git repo on `main`.
 *
 * @param  array<string, string>  $requires
 * @param  array<string, string>  $scripts
 */
function packageRepo(string $root, string $name, array $requires = [], array $scripts = []): void
{
    writeFixture($root, "{$name}/composer.json", (string) json_encode(['name' => 'acme/'.strtolower($name), 'require' => $requires, 'scripts' => $scripts]));
    $dir = "{$root}/{$name}";
    Shell::run(['git', 'init', '-q', '-b', 'main'], $dir);
    Shell::run(['git', 'config', 'user.email', 't@t'], $dir);
    Shell::run(['git', 'config', 'user.name', 't'], $dir);
    Shell::run(['git', 'add', '.'], $dir);
    Shell::run(['git', 'commit', '-q', '-m', 'init'], $dir);
}

/**
 * Re-reads ring-master.yml after a test changes it.
 */
function reload(Project $project): Project
{
    return new Project($project->root);
}
