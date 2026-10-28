<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Specs\Job;

function jobProject(): Project
{
    return fixtureProject([
        'docs/agent/specs/2026-10-23-1430-import/meta.yml' => "id: 2026-10-23-1430-import\ntitle: Import\nstatus: approved\nticket: { provider: github, key: '#42' }\n",
        'docs/agent/specs/2026-10-23-1430-import/tasks.md' => "## Group 1: Data\nfiles: src/A.php\n- [ ] 1.1 Tests\n## Group 2: Command\ndepends on: 1\n- [ ] 2.1 Command\n",
        'docs/agent/specs/2026-10-23-1430-import/standards.md' => "| testing/pest-style | ... |\n",
        'docs/agent/specs/2026-10-23-1430-import/decisions.md' => "## D-001: Sync or queued?\n- Choice: queued\n- Rationale: big files\n- Date: 2026-10-24\n",
        'ring-master.yml' => "review:\n  reviewers: [code, { name: compat, model: opus }]\n",
    ]);
}

it('builds the workflow args for a spec from its files and the config', function () {
    $job = (new Job)->build(jobProject(), 'import');

    expect($job['spec']['id'])->toBe('2026-10-23-1430-import')
        ->and($job['spec']['standards'])->toBe(['vendor/refactor-circus/standards/standards/testing/pest-style.md'])
        ->and($job['job']['branch'])->toBe('ring-master/2026-10-23-1430-import')
        ->and($job['job']['worktree'])->toEndWith('--2026-10-23-1430-import')
        ->and(array_map(static fn (array $w): array => array_column($w, 'n'), $job['waves']))->toBe([[1], [2]])
        ->and($job['decisions'][0])->toMatchArray(['id' => 'D-001', 'question' => 'Sync or queued?', 'choice' => 'queued'])
        ->and($job['index']['testing/pest-style']['kind'])->toBe('architectural')
        ->and(array_column($job['reviewers'], 'model', 'name'))->toBe(['code' => 'sonnet', 'compat' => 'opus'])
        ->and($job['config']['review']['rounds']['max'])->toBe(3)
        ->and($job['workflows_dir'])->toBe(dirname(__DIR__, 2).'/resources/workflows')
        ->and(is_file($job['workflows_dir'].'/gate-command.js'))->toBeTrue();
});

it('says which spec it could not find', function () {
    (new Job)->build(jobProject(), 'nope');
})->throws(RuntimeException::class, 'No spec `nope`');

it('accepts the --json flag the skills use', function () {
    $project = jobProject();
    $process = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/bin/ringside', 'job', 'import', '--json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project->root);
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0)
        ->and(json_decode($out, true)['spec']['id'])->toBe('2026-10-23-1430-import');
});

it('builds once by default, as many variants as meta.yml or build.variants ask (at most 4), and never in a workspace', function () {
    $project = jobProject();
    expect((new Job)->build($project, 'import')['variants'])->toBe(1);

    $project = withConfig($project, "build:\n  variants: 3\n");
    expect((new Job)->build($project, 'import')['variants'])->toBe(3);

    writeFixture($project->root, 'docs/agent/specs/2026-10-23-1430-import/meta.yml', "id: 2026-10-23-1430-import\ntitle: Import\nstatus: approved\nvariants: 9\n");
    expect((new Job)->build(reload($project), 'import')['variants'])->toBe(4);

    $shared = withConfig($project, "build:\n  variants: 3\n  worktree: { mode: shared }\n");
    writeFixture($project->root, 'docs/agent/specs/2026-10-23-1430-import/meta.yml', "id: 2026-10-23-1430-import\ntitle: Import\nstatus: approved\n");
    expect((new Job)->build($shared, 'import')['variants'])->toBe(1);
});

it('replays a job on its own branch and worktree with every task group undone', function () {
    $project = jobProject();
    writeFixture($project->root, 'docs/agent/specs/2026-10-23-1430-import/tasks.md', "## Group 1: Data\n- [x] 1.1 Tests\n## Group 2: Command\ndepends on: 1\n- [ ] 2.1 Command\n");

    $real = (new Job)->build(reload($project), 'import');
    $replay = (new Job)->build(reload($project), 'import', replay: true);

    expect(array_map(static fn (array $w): array => array_column($w, 'n'), $real['waves']))->toBe([[2]])
        ->and($real['replay'])->toBeFalse()
        ->and($replay['replay'])->toBeTrue()
        ->and(array_map(static fn (array $w): array => array_column($w, 'n'), $replay['waves']))->toBe([[1], [2]])
        ->and($replay['waves'][0][0]['tasks'][0]['done'])->toBeFalse()
        ->and($replay['job']['branch'])->toBe('ring-master/2026-10-23-1430-import--replay')
        ->and($replay['job']['worktree'])->toEndWith('--2026-10-23-1430-import--replay');
});

it('points at brief.md once the factory wrote one', function () {
    $project = jobProject();
    expect((new Job)->build($project, 'import')['brief'])->toBeNull();

    writeFixture($project->root, 'docs/agent/specs/2026-10-23-1430-import/brief.md', "Risk: low\n");
    expect((new Job)->build($project, 'import')['brief'])->toBe('docs/agent/specs/2026-10-23-1430-import/brief.md');
});
