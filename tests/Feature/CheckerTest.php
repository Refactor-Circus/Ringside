<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Checker;
use RefactorCircus\Ringside\Review;
use RefactorCircus\Ringside\Sync;

it('passes on a synced project with a matching pint.json', function () {
    $project = fixtureProject(['pint.json' => "{\"preset\": \"laravel\"}\n"]);
    (new Sync)->run($project);

    expect((new Checker)->check($project))->toBe([]);
});

it('fails when generated files are stale', function () {
    $project = fixtureProject(['pint.json' => "{\"preset\": \"laravel\"}\n"]);

    expect((new Checker)->check($project))->toHaveCount(1)
        ->and((new Checker)->check($project)[0])->toContain('stale');
});

it('fails on an unreviewed override until it is accepted', function () {
    $project = fixtureProject([
        'pint.json' => "{\"preset\": \"laravel\"}\n",
        'docs/agent/standards/testing/pest-style.md' => "# Our pest style\n",
    ]);
    (new Sync)->run($project);

    expect(implode("\n", (new Checker)->check($project)))->toContain('without a reviewed_hash');

    (new Review)->accept($project, 'testing/pest-style');
    (new Sync)->run($project);

    expect((new Checker)->check($project))->toBe([])
        ->and($project->read('docs/agent/standards/testing/pest-style.md'))->toContain("overrides: refactor-circus/standards\nreviewed_hash: ");
});

it('fails when the vendor file changes after review', function () {
    $project = fixtureProject([
        'pint.json' => "{\"preset\": \"laravel\"}\n",
        'docs/agent/standards/testing/pest-style.md' => "# Our pest style\n",
    ]);
    (new Review)->accept($project, 'testing/pest-style');
    writeFixture($project->root, 'vendor/refactor-circus/standards/standards/testing/pest-style.md', "# Pest Style v2\n");
    (new Sync)->run($project);

    expect(implode("\n", (new Checker)->check($project)))->toContain('changed since');
});

it('fails when an override names a vendor standard that does not exist', function () {
    $project = fixtureProject([
        'pint.json' => "{\"preset\": \"laravel\"}\n",
        'docs/agent/standards/testing/gone.md' => "---\noverrides: refactor-circus/standards\n---\n# Gone\n",
    ]);
    (new Sync)->run($project);

    expect(implode("\n", (new Checker)->check($project)))->toContain('no vendor standard `testing/gone`');
});

it('fails on pint.json drift unless allowed', function () {
    $project = fixtureProject(['pint.json' => "{\"preset\": \"psr12\"}\n"]);
    (new Sync)->run($project);

    expect(implode("\n", (new Checker)->check($project)))->toContain('pint.json differs');

    $project = withConfig($project, "pint:\n  allow_diff: true\n");
    (new Sync)->run($project);

    expect((new Checker)->check($project))->toBe([]);
});

it('refuses to accept a key that is not an override', function () {
    (new Review)->accept(fixtureProject(), 'testing/pest-style');
})->throws(RuntimeException::class, 'not a local override');
