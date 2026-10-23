<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Checker;
use RefactorCircus\Ringside\Installer;

it('sets up config, folders, preset, pint, gitignore and syncs', function () {
    $project = fixtureProject([
        'tests/Pest.php' => "<?php\n\ndeclare(strict_types=1);\n\nuse App\\Tests\\TestCase;\n\nuses(TestCase::class)->in('Feature');\n",
        'tests/ArchTest.php' => "<?php\n\ndeclare(strict_types=1);\n\narch()->preset()->php();\n",
        '.gitignore' => "/vendor\n",
    ]);

    $log = (new Installer)->install($project);

    expect($project->exists('ring-master.yml'))->toBeTrue()
        ->and($project->exists('docs/agent/specs/.gitkeep'))->toBeTrue()
        ->and($project->read('docs/agent/standards-feedback.md'))->toContain('| Date | Spec |')
        ->and($project->read('tests/Pest.php'))->toBe("<?php\n\ndeclare(strict_types=1);\n\nuse App\\Tests\\TestCase;\nuse RefactorCircus\\Standards\\Pest\\ArchPreset;\n\nuses(TestCase::class)->in('Feature');\n\nArchPreset::register();\n")
        ->and($project->read('tests/ArchTest.php'))->toEndWith("arch()->preset()->php();\n\narch()->preset()->refactorCircus();\n")
        ->and($project->read('pint.json'))->toBe("{\"preset\": \"laravel\"}\n")
        ->and($project->read('.gitignore'))->toContain('/docs/agent/standards.index.yml', '/.claude/skills/ring-master-*/')
        ->and(implode("\n", $log))->toContain('synced', 'allow: Bash(composer *)')
        ->and((new Checker)->check(reload($project)))->toBe([]);
});

it('can leave the tests and pint.json alone', function () {
    $project = fixtureProject(['tests/Pest.php' => "<?php\n"]);

    $log = implode("\n", (new Installer)->install($project, preset: false, pint: false));

    expect($project->read('tests/Pest.php'))->toBe("<?php\n")
        ->and($project->exists('pint.json'))->toBeFalse()
        ->and($log)->toContain('--no-preset', '--no-pint');
});

it('is idempotent', function () {
    $project = fixtureProject(['.gitignore' => "/vendor\n"]);
    (new Installer)->install($project);
    $snapshot = [$project->read('tests/Pest.php'), $project->read('tests/ArchTest.php'), $project->read('.gitignore')];

    (new Installer)->install($project);

    expect([$project->read('tests/Pest.php'), $project->read('tests/ArchTest.php'), $project->read('.gitignore')])->toBe($snapshot);
});

it('keeps a differing pint.json unless told to replace it', function () {
    $project = fixtureProject(['pint.json' => "{\"preset\": \"psr12\"}\n"]);

    expect(implode("\n", (new Installer)->install($project)))->toContain('kept the project copy')
        ->and($project->read('pint.json'))->toBe("{\"preset\": \"psr12\"}\n");

    (new Installer)->install($project, overwritePint: true);

    expect($project->read('pint.json'))->toBe("{\"preset\": \"laravel\"}\n");
});

it('ignores generated files under the real directory when .claude is a symlink', function () {
    $project = fixtureProject(['.gitignore' => "/vendor\n", '.agents/skills/.keep' => '']);
    symlink('.agents', $project->path('.claude'));

    (new Installer)->install($project);

    expect($project->read('.gitignore'))->toContain('/.agents/skills/ring-master-*/', '/.agents/agents/ring-master-*.md');
})->skipOnWindows();

it('adds the preset import after declare when there are no imports', function () {
    expect(Installer::registerPreset("<?php\n\ndeclare(strict_types=1);\n\npest()->in('Unit');\n", 'RefactorCircus\\Standards\\Pest\\ArchPreset'))
        ->toBe("<?php\n\ndeclare(strict_types=1);\n\nuse RefactorCircus\\Standards\\Pest\\ArchPreset;\n\npest()->in('Unit');\n\nArchPreset::register();\n");
});
