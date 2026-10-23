<?php

declare(strict_types=1);

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
