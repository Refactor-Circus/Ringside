<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Config;

it('merges ring-master.yml over the defaults', function () {
    $config = fixtureProject(['ring-master.yml' => "build:\n  parallel: true\ntargets: [agents]\n"])->config;

    expect($config->bool('build.parallel'))->toBeTrue()
        ->and($config->int('build.max_concurrency'))->toBe(2)
        ->and($config->list('targets'))->toBe(['agents'])
        ->and($config->agentDir())->toBe('docs/agent')
        ->and($config->globalStandards())->toBe([['package' => 'refactor-circus/standards', 'strict' => true]]);
});

it('merges each reviewer over the reviewer role', function () {
    $config = fixtureProject(['ring-master.yml' => "review:\n  reviewers: [code, { name: compat, model: opus }]\n"])->config;

    expect($config->reviewers())->toBe([
        ['model' => 'sonnet', 'effort' => 'medium', 'name' => 'code'],
        ['model' => 'opus', 'effort' => 'medium', 'name' => 'compat'],
    ]);
});

it('never lets architectural findings be fixed automatically', function () {
    $problems = fixtureProject(['ring-master.yml' => "review:\n  auto_fix: [architectural, blocker]\n"])->config->validate();

    expect(implode("\n", $problems))->toContain('cannot include `architectural`');
});

it('rejects unknown keys, roles, severities and bad round limits', function () {
    $problems = implode("\n", fixtureProject(['ring-master.yml' => "colour: blue\nroles:\n  wizard: {}\nreview:\n  rework_threshold: huge\n  rounds: { max: 1, min: 2 }\n"])->config->validate());

    expect($problems)->toContain('unknown key `colour`', 'unknown role `wizard`', '`review.rework_threshold` must be', '`review.rounds` needs');
});

it('accepts the defaults', function () {
    expect(fixtureProject()->config->validate())->toBe([]);
});

it('defaults build.worktree.setup to auto', function () {
    expect(Config::defaults()['build']['worktree']['setup'])->toBe('auto')
        ->and(fixtureProject()->config->worktreeSetup())->toBe('auto');
});

it('reads an absent, null or empty build.worktree.setup as auto', function (string $yaml) {
    expect(fixtureProject(['ring-master.yml' => $yaml])->config->worktreeSetup())->toBe('auto');
})->with([
    'absent' => "build:\n  worktree:\n    mode: per-job\n",
    'null' => "build:\n  worktree:\n    setup: null\n",
    'empty' => "build:\n  worktree:\n    setup: ''\n",
]);

it('reads an explicit build.worktree.setup command as is', function () {
    $config = fixtureProject(['ring-master.yml' => "build:\n  worktree:\n    setup: 'touch setup-ran'\n"])->config;

    expect($config->worktreeSetup())->toBe('touch setup-ran');
});

it('fails loud on a non-string build.worktree.setup', function (string $value) {
    $config = fixtureProject(['ring-master.yml' => "build:\n  worktree:\n    setup: {$value}\n"])->config;

    expect(fn () => $config->worktreeSetup())->toThrow(RuntimeException::class, 'build.worktree.setup');
})->with([
    'list' => '[composer, install]',
    'map' => '{ run: composer }',
    'number' => '5',
    'boolean' => 'true',
]);

it('builds once by default, with judge, briefer and variant roles ready for more', function () {
    $config = fixtureProject()->config;

    expect($config->int('build.variants'))->toBe(1)
        ->and($config->int('build.escalation_variants'))->toBe(3)
        ->and($config->role('judge'))->toBe(['model' => 'opus', 'effort' => 'high'])
        ->and($config->role('briefer'))->toBe(['model' => 'sonnet', 'effort' => 'low'])
        ->and($config->role('variant')['models'])->toBe(['opus', 'opus', 'sonnet', 'opus']);
});

it('rejects a variant count outside 1 to 4', function (string $yaml) {
    expect(implode("\n", fixtureProject(['ring-master.yml' => $yaml])->config->validate()))->toContain('must be a whole number from 1 to 4');
})->with(["build:\n  variants: 5\n", "build:\n  variants: 0\n", "build:\n  escalation_variants: lots\n"]);
