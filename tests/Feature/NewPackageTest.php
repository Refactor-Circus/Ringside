<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Scaffold\PackageScaffolder;
use RefactorCircus\Ringside\Scaffold\WorkspaceConfig;
use RefactorCircus\Ringside\Shell;

function scaffoldWorkspace(string $config = ''): Project
{
    $root = fixtureProject(['ring-master.yml' => "workspace:\n  packages: [Keystone]   # keep this comment\n".$config])->root;
    writeFixture($root, 'Keystone/composer.json', json_encode(['name' => 'acme/keystone']));
    Shell::run(['git', 'config', 'user.name', 'Test Author'], $root);
    Shell::run(['git', 'config', 'user.email', 'author@example.com'], $root);
    Shell::run(['git', 'init', '-q'], $root);
    Shell::run(['git', 'config', 'user.name', 'Test Author'], $root);
    Shell::run(['git', 'config', 'user.email', 'author@example.com'], $root);

    return new Project($root);
}

function fakeLaravel(Project $project): string
{
    // Stands in for `laravel package <Name> ...`: writes the folder and records its arguments.
    $bin = $project->path('fake-laravel');
    file_put_contents($bin, implode("\n", [
        '#!/bin/sh',
        'mkdir -p "$2"',
        'echo \'{"name": "x"}\' > "$2/composer.json"',
        'printf \'%s\n\' "$@" > "$2/args.txt"',
        '',
    ]));
    chmod($bin, 0o755);

    return $bin;
}

it('plans a laravel package with the workspace vendor, namespace and features', function () {
    $plan = (new PackageScaffolder(scaffoldWorkspace()))->plan('PennantPlus', ['features' => ['config', 'migrations']]);

    expect($plan['composer'])->toBe('acme/pennant-plus')
        ->and($plan['namespace'])->toBe('Acme\\PennantPlus')
        ->and($plan['command'])->toContain('package', 'PennantPlus', '--package-name=acme/pennant-plus', '--vendor-namespace=Acme', '--class-name=PennantPlus', '--author-email=author@example.com', '--config', '--migrations');
});

it('rejects names that are not StudlyCase, and unknown features', function () {
    $scaffolder = new PackageScaffolder(scaffoldWorkspace());

    expect(fn () => $scaffolder->plan('ledger'))->toThrow(RuntimeException::class, 'StudlyCase')
        ->and(fn () => $scaffolder->plan('Ledger', ['features' => ['wings']]))->toThrow(RuntimeException::class, 'Unknown package features: wings');
});

it('creates a laravel package with the laravel installer, commits it and adds it to the workspace', function () {
    $project = scaffoldWorkspace();
    writeFixture($project->root, 'ring-master.yml', (string) $project->read('ring-master.yml')."new_package:\n  laravel_bin: ".fakeLaravel($project)."\n");
    $project = new Project($project->root);

    (new PackageScaffolder($project))->create('Ledger');

    expect((string) $project->read('Ledger/args.txt'))->toContain("package\nLedger\n--no-interaction\n--package-name=acme/ledger")
        ->and(json_decode((string) $project->read('Ledger/composer.json'), true)['require'])->toBe(['php' => '^8.5'])
        ->and(Shell::run(['git', 'status', '--porcelain'], $project->path('Ledger'))[1])->toBe('')
        ->and(Shell::run(['git', 'log', '--oneline'], $project->path('Ledger'))[1])->toContain('Scaffold acme/ledger')
        ->and(Shell::run(['git', 'branch', '--show-current'], $project->path('Ledger'))[1])->toBe('main')
        ->and((string) $project->read('ring-master.yml'))->toContain('packages: [Keystone, Ledger]   # keep this comment')
        ->and((new Project($project->root))->config->list('workspace.packages'))->toBe(['Keystone', 'Ledger']);
});

it('creates a plain PHP package with composer init', function () {
    $project = scaffoldWorkspace();

    (new PackageScaffolder($project))->create('Toolbox', ['type' => 'php', 'description' => 'Small tools']);
    $manifest = json_decode((string) $project->read('Toolbox/composer.json'), true);

    expect($manifest['name'])->toBe('acme/toolbox')
        ->and($manifest['description'])->toBe('Small tools')
        ->and($manifest['require']['php'])->toBe('^8.5')
        ->and($manifest['autoload']['psr-4'])->toHaveKey('Acme\\Toolbox\\')
        ->and(Shell::run(['git', 'log', '--oneline'], $project->path('Toolbox'))[1])->toContain('Scaffold acme/toolbox');
})->skipOnWindows();

it('refuses to overwrite an existing folder', function () {
    $project = scaffoldWorkspace();
    mkdir($project->path('Ledger'));

    (new PackageScaffolder($project))->create('Ledger', ['type' => 'php']);
})->throws(RuntimeException::class, 'already exists');

it('adds packages to block-style lists too', function () {
    $project = fixtureProject(['ring-master.yml' => "workspace:\n  packages:\n    - Keystone\n"]);

    (new WorkspaceConfig($project))->addPackage('Ledger');

    expect((new Project($project->root))->config->list('workspace.packages'))->toBe(['Keystone', 'Ledger']);
});
