<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Config;
use RefactorCircus\Ringside\Impact;
use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Shell;
use RefactorCircus\Ringside\Specs\Job;
use RefactorCircus\Ringside\Worktree;

const IMPACT_SPEC = '2026-10-27-0900-rename';

/**
 * A folder holding the package under test (`Lib`, composer name
 * refactor-circus/fixture, with one spec) and the packages beside it, each
 * its own git repo on `main`.
 *
 * @param  array<string, array{require?: array<string, string>, test?: string}>  $siblings
 * @return array{0: string, 1: Project}
 */
function impactWorkspace(array $siblings, string $config = ''): array
{
    $parent = sys_get_temp_dir().'/ring-master-impact-'.bin2hex(random_bytes(6));
    mkdir($parent, 0o755, true);
    $lib = fixtureProject([
        'ring-master.yml' => $config,
        'docs/agent/specs/'.IMPACT_SPEC.'/meta.yml' => 'id: '.IMPACT_SPEC."\ntitle: Rename\nstatus: approved\n",
        'docs/agent/specs/'.IMPACT_SPEC.'/tasks.md' => "## Group 1: Rename\n- [ ] 1.1 Rename\n",
    ]);
    rename($lib->root, $parent.'/Lib');
    impactCommit($parent.'/Lib');

    foreach ($siblings as $name => $sibling) {
        writeFixture($parent, "{$name}/composer.json", (string) json_encode(['name' => 'acme/'.strtolower($name), 'require-dev' => $sibling['require'] ?? []]));

        if (isset($sibling['test'])) {
            writeFixture($parent, "{$name}/ring-master.yml", "commands:\n  test: '{$sibling['test']}'\n");
        }

        impactCommit($parent.'/'.$name);
    }

    return [$parent, new Project($parent.'/Lib')];
}

function impactCommit(string $dir): void
{
    foreach ([['init', '-q', '-b', 'main'], ['config', 'user.email', 't@t'], ['config', 'user.name', 't'], ['add', '.'], ['commit', '-q', '-m', 'init']] as $args) {
        Shell::run(['git', ...$args], $dir);
    }
}

/**
 * A temp bin dir whose fake `composer` logs `args|COMPOSER` to composer.log
 * and exits with FAKE_COMPOSER_EXIT (default 0).
 */
function impactFakeComposer(): string
{
    $bin = sys_get_temp_dir().'/ring-master-bin-'.bin2hex(random_bytes(6));
    mkdir($bin, 0o755, true);
    file_put_contents($bin.'/composer', <<<SH
        #!/bin/sh
        printf '%s|%s\\n' "\$*" "\${COMPOSER:-<unset>}" >> '{$bin}/composer.log'
        exit "\${FAKE_COMPOSER_EXIT:-0}"
        SH);
    chmod($bin.'/composer', 0o755);

    return $bin;
}

/**
 * @param  list<string>  $args
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function ringsideImpact(Project $project, string $bin, array $args, array $env = []): array
{
    $env['PATH'] = $bin.PATH_SEPARATOR.getenv('PATH');

    return Shell::run([PHP_BINARY, dirname(__DIR__, 2).'/bin/ringside', ...$args], $project->root, $env);
}

it('finds the sibling packages that require this one, never job worktrees or itself', function () {
    [$parent, $project] = impactWorkspace([
        'App' => ['require' => ['refactor-circus/fixture' => 'dev-main'], 'test' => 'composer test:unit'],
        'Shop' => ['require' => ['refactor-circus/fixture' => '^1.0']],
        'Other' => ['require' => ['acme/else' => '^1.0']],
    ]);
    Worktree::prepare($project, '../Lib--'.IMPACT_SPEC, 'ring-master/'.IMPACT_SPEC, 'main');
    $real = (string) realpath($parent);

    expect(Impact::consumers($project, ['refactor-circus/fixture']))->toBe([
        ['folder' => 'App', 'path' => $real.'/App', 'test' => 'composer test:unit'],
        ['folder' => 'Shop', 'path' => $real.'/Shop', 'test' => 'vendor/bin/pest'],
    ]);
});

it('puts the consumers in the job JSON, and none once impact is off', function () {
    [, $project] = impactWorkspace(['App' => ['require' => ['refactor-circus/fixture' => 'dev-main']]]);

    $impact = (new Job)->build($project, IMPACT_SPEC)['impact'];

    expect($impact['enabled'])->toBeTrue()
        ->and(array_column($impact['consumers'], 'folder'))->toBe(['App']);

    foreach (["impact:\n  consumers: off\n", "impact:\n  enabled: false\n"] as $yaml) {
        expect((new Job)->build(withConfig($project, $yaml), IMPACT_SPEC)['impact'])->toBe(['enabled' => false, 'consumers' => []]);
    }
});

it('takes a configured list of consumer folders instead of looking around', function () {
    [, $project] = impactWorkspace([
        'App' => ['require' => ['refactor-circus/fixture' => 'dev-main']],
        'Shop' => ['require' => ['refactor-circus/fixture' => 'dev-main']],
    ], "impact:\n  consumers: [../Shop]\n");

    expect(array_column(Impact::consumers($project, ['refactor-circus/fixture']), 'folder'))->toBe(['Shop']);
});

it('rejects an impact.consumers that is neither auto, off nor a list', function () {
    expect(Config::load(fixtureProject(['ring-master.yml' => "impact:\n  consumers: sometimes\n"])->root)->validate())
        ->toContain('ring-master.yml: `impact.consumers` must be auto, off or a list of package folders.');
});

it('tests a consumer in a throwaway worktree resolved to the job worktree, and removes it', function () {
    [$parent, $project] = impactWorkspace(['App' => ['require' => ['refactor-circus/fixture' => 'dev-main'], 'test' => 'cat composer.local.json']]);
    $tree = Worktree::prepare($project, '../Lib--'.IMPACT_SPEC, 'ring-master/'.IMPACT_SPEC, 'main');
    $bin = impactFakeComposer();

    [$code, $out] = ringsideImpact($project, $bin, ['impact', IMPACT_SPEC, '--package=App', '--json']);
    $result = json_decode($out, true)['results'][0];
    $manifest = json_decode($result['output'], true);

    expect($code)->toBe(0)
        ->and($result['folder'])->toBe('App')
        ->and($result['passed'])->toBeTrue()
        ->and($manifest['repositories'][0])->toBe(['type' => 'path', 'url' => realpath($tree), 'options' => ['symlink' => true, 'versions' => ['refactor-circus/fixture' => 'dev-main']]])
        ->and((string) file_get_contents($bin.'/composer.log'))->toContain('install --no-interaction|composer.local.json')
        ->and(file_exists($parent.'/App--impact-'.IMPACT_SPEC))->toBeFalse()
        ->and(file_exists($parent.'/App/composer.local.json'))->toBeFalse();
});

it('relaxes a released constraint so the job branch satisfies it, and updates only the job packages from the lock', function () {
    [$parent, $project] = impactWorkspace(['App' => ['require' => ['refactor-circus/fixture' => '^1.0'], 'test' => 'cat composer.local.json']]);
    writeFixture($parent, 'App/composer.local.lock', '{}');
    Worktree::prepare($project, '../Lib--'.IMPACT_SPEC, 'ring-master/'.IMPACT_SPEC, 'main');
    $bin = impactFakeComposer();

    [, $out] = ringsideImpact($project, $bin, ['impact', IMPACT_SPEC, '--json']);
    $manifest = json_decode(json_decode($out, true)['results'][0]['output'], true);

    expect($manifest['require-dev']['refactor-circus/fixture'])->toBe('*@dev')
        ->and($manifest['repositories'][0])->not->toHaveKey('options.versions')
        ->and((string) file_get_contents($bin.'/composer.log'))->toContain('update refactor-circus/fixture --with-dependencies --no-interaction|composer.local.json');
});

it('reports a failing consumer, or one that will not install, without failing itself', function () {
    [$parent, $project] = impactWorkspace(['App' => ['require' => ['refactor-circus/fixture' => 'dev-main'], 'test' => 'false']]);
    Worktree::prepare($project, '../Lib--'.IMPACT_SPEC, 'ring-master/'.IMPACT_SPEC, 'main');
    $bin = impactFakeComposer();

    [$code, $out] = ringsideImpact($project, $bin, ['impact', IMPACT_SPEC, '--json']);
    [, $broken] = ringsideImpact($project, $bin, ['impact', IMPACT_SPEC, '--json'], ['FAKE_COMPOSER_EXIT' => '2']);

    expect($code)->toBe(0)
        ->and(json_decode($out, true)['results'][0])->toMatchArray(['passed' => false, 'exit_code' => 1, 'summary' => 'App: `false` fails against the job (exit 1)'])
        ->and(json_decode($broken, true)['results'][0])->toMatchArray(['passed' => false, 'exit_code' => 2, 'summary' => 'installing App against the job failed'])
        ->and(file_exists($parent.'/App--impact-'.IMPACT_SPEC))->toBeFalse();
});

it('lists the consumers, and refuses to test before the job worktree exists', function () {
    [, $project] = impactWorkspace(['App' => ['require' => ['refactor-circus/fixture' => 'dev-main']]]);
    $bin = impactFakeComposer();

    [$listed, $list] = ringsideImpact($project, $bin, ['impact', IMPACT_SPEC, '--list', '--json']);
    [$code, $out] = ringsideImpact($project, $bin, ['impact', IMPACT_SPEC]);

    expect($listed)->toBe(0)
        ->and(array_column(json_decode($list, true)['consumers'], 'folder'))->toBe(['App'])
        ->and($code)->toBe(1)
        ->and($out)->toContain('does not exist; run `ringside worktrees '.IMPACT_SPEC.'` first');
});

it('creates a worktree per task group from the job branch, and removes them with their branches', function () {
    [$parent, $project] = impactWorkspace([]);
    $bin = impactFakeComposer();
    ringsideImpact($project, $bin, ['worktrees', IMPACT_SPEC]);

    [$code, $out] = ringsideImpact($project, $bin, ['worktrees', IMPACT_SPEC, '--groups=1,3']);
    [, $branch] = Shell::run(['git', 'branch', '--show-current'], $parent.'/Lib--'.IMPACT_SPEC.'--g3');

    expect($code)->toBe(0, $out)
        ->and($out)->toContain('Lib--'.IMPACT_SPEC.'--g1 on ring-master/'.IMPACT_SPEC.'--g1')
        ->and($branch)->toBe('ring-master/'.IMPACT_SPEC.'--g3');

    [$removed, $log] = ringsideImpact($project, $bin, ['worktrees', IMPACT_SPEC, '--remove-groups']);
    [, $left] = Shell::run(['git', 'for-each-ref', '--format=%(refname:short)', 'refs/heads/ring-master/'], $project->root);

    expect($removed)->toBe(0)
        ->and($log)->toContain('Deleted branch ring-master/'.IMPACT_SPEC.'--g1')
        ->and(is_dir($parent.'/Lib--'.IMPACT_SPEC.'--g1'))->toBeFalse()
        ->and(is_dir($parent.'/Lib--'.IMPACT_SPEC))->toBeTrue()
        ->and(trim($left))->toBe('ring-master/'.IMPACT_SPEC);
});
