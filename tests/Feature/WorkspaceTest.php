<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Shell;
use RefactorCircus\Ringside\Specs\Job;
use RefactorCircus\Ringside\Workspace\Workspace;

function packageRepo(string $root, string $name, array $requires = [], array $scripts = []): void
{
    writeFixture($root, "{$name}/composer.json", json_encode(['name' => 'acme/'.strtolower($name), 'require' => $requires, 'scripts' => $scripts]));
    $dir = "{$root}/{$name}";
    Shell::run(['git', 'init', '-q', '-b', 'main'], $dir);
    Shell::run(['git', 'config', 'user.email', 't@t'], $dir);
    Shell::run(['git', 'config', 'user.name', 't'], $dir);
    Shell::run(['git', 'add', '.'], $dir);
    Shell::run(['git', 'commit', '-q', '-m', 'init'], $dir);
}

function workspaceProject(): Project
{
    $root = fixtureProject([
        'ring-master.yml' => "workspace:\n  packages: [Keystone, Atrium, Cortex]\n",
        'docs/agent/specs/2026-10-23-1430-import/meta.yml' => "id: 2026-10-23-1430-import\ntitle: Import\nstatus: approved\n",
        'docs/agent/specs/2026-10-23-1430-import/tasks.md' => "## Group 1: Screen\npackage: Atrium\ndepends on: 2\n- [ ] 1.1 Screen\n## Group 2: Contract\npackage: Keystone\n- [ ] 2.1 Contract\n",
    ])->root;
    packageRepo($root, 'Keystone');
    packageRepo($root, 'Atrium', ['acme/keystone' => '*'], ['local:install' => 'x', 'test:unit' => 'pest']);
    packageRepo($root, 'Cortex', ['acme/keystone' => '*']);
    writeFixture($root, 'Atrium/docs/agent/standards/atrium/ui.md', "# UI\n");

    return new Project($root);
}

it('orders a job\'s packages by their dependencies', function () {
    expect((new Workspace(workspaceProject()))->ordered(['Atrium', 'Keystone']))->toBe(['Keystone', 'Atrium']);
});

it('describes each changed package with its own commands and setup', function () {
    $job = (new Job)->build(workspaceProject(), 'import');

    expect(array_keys($job['workspace']['packages']))->toBe(['Keystone', 'Atrium'])
        ->and($job['workspace']['dir'])->toBe('.ring-master/jobs/2026-10-23-1430-import')
        ->and($job['workspace']['packages']['Atrium']['commands']['test'])->toBe('composer test:unit')
        ->and($job['workspace']['packages']['Atrium']['setup'])->toBe('composer local:install --no-interaction')
        ->and($job['workspace']['packages']['Keystone']['setup'])->toBe('composer install --no-interaction')
        ->and($job['workspace']['packages']['Atrium']['standards'])->toBe(['Atrium/docs/agent/standards/atrium/ui.md'])
        ->and($job['waves'][0][0]['package'])->toBe('Keystone');
});

it('needs a package on every group in a workspace', function () {
    $project = workspaceProject();
    writeFixture($project->root, 'docs/agent/specs/2026-10-23-1430-import/tasks.md', "## Group 1: Loose\n- [ ] 1.1 x\n");

    (new Job)->build($project, 'import');
})->throws(RuntimeException::class, 'needs a `package:` line');

it('builds a job folder: worktrees for changed packages, links to the rest, then removes it', function () {
    $project = workspaceProject();
    $workspace = new Workspace($project);

    $prepared = $workspace->prepare('2026-10-23-1430-import', ['Keystone', 'Atrium'], 'ring-master/2026-10-23-1430-import', 'main');
    $dir = $project->path('.ring-master/jobs/2026-10-23-1430-import');

    expect(array_keys($prepared['worktrees']))->toBe(['Keystone', 'Atrium'])
        ->and($prepared['linked'])->toBe(['Cortex'])
        ->and(Shell::run(['git', 'branch', '--show-current'], $dir.'/Atrium')[1])->toBe('ring-master/2026-10-23-1430-import')
        ->and(is_link($dir.'/Cortex'))->toBeTrue()
        ->and(realpath($dir.'/Cortex'))->toBe(realpath($project->path('Cortex')))
        // A sibling path repo (../Keystone) seen from the Atrium worktree is the job's Keystone.
        ->and(realpath($dir.'/Atrium/../Keystone'))->toBe(realpath($dir.'/Keystone'));

    $again = $workspace->prepare('2026-10-23-1430-import', ['Keystone', 'Atrium'], 'ring-master/2026-10-23-1430-import', 'main');
    expect($again['worktrees'])->toBe($prepared['worktrees']);

    $workspace->remove('2026-10-23-1430-import');
    expect(file_exists($dir))->toBeFalse();
})->skipOnWindows();
