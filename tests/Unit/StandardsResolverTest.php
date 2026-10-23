<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Standards\Layer;
use RefactorCircus\Ringside\Standards\StandardsResolver;

it('resolves vendor standards from the index with their source', function () {
    $standard = (new StandardsResolver)->resolve(fixtureProject())->standards['testing/pest-style'];

    expect($standard->path)->toBe('vendor/refactor-circus/standards/standards/testing/pest-style.md')
        ->and($standard->source)->toBe('refactor-circus/standards@0.1.0')
        ->and($standard->description)->toBe('About testing/pest-style')
        ->and($standard->tags)->toBe(['testing']);
});

it('lets a local file override the vendor standard at the same key', function () {
    $project = fixtureProject(['docs/agent/standards/testing/pest-style.md' => "---\nreviewed_hash: abc\n---\n# Local pest\n"]);

    $standard = (new StandardsResolver)->resolve($project)->standards['testing/pest-style'];

    expect($standard->path)->toBe('docs/agent/standards/testing/pest-style.md')
        ->and($standard->source)->toBe('local')
        ->and($standard->overrides)->toBe('refactor-circus/standards@0.1.0')
        ->and($standard->overriddenPath)->toBe('vendor/refactor-circus/standards/standards/testing/pest-style.md')
        ->and($standard->reviewedHash)->toBe('abc')
        ->and($standard->description)->toBe('About testing/pest-style');
});

it('adds local-only standards, described by front matter or first heading', function () {
    $project = fixtureProject([
        'docs/agent/standards/import/csv.md' => "---\ndescription: CSV rules\ntags: [import]\n---\n# CSV\n",
        'docs/agent/standards/import/batches.md' => "# Batch sizes\n\nRules.\n",
    ]);

    $standards = (new StandardsResolver)->resolve($project)->standards;

    expect($standards['import/csv']->description)->toBe('CSV rules')
        ->and($standards['import/csv']->overrides)->toBeNull()
        ->and($standards['import/batches']->description)->toBe('Batch sizes');
});

it('drops excluded keys', function () {
    $project = fixtureProject(['ring-master.yml' => "  exclude:\n    - testing/pest-style\n"]);

    expect((new StandardsResolver)->resolve($project)->standards)->not->toHaveKey('testing/pest-style');
});

it('changes the fingerprint when a local standard changes', function () {
    $project = fixtureProject(['docs/agent/standards/import/csv.md' => "# CSV\n"]);
    $before = (new StandardsResolver)->resolve($project)->fingerprint;

    writeFixture($project->root, 'docs/agent/standards/import/csv.md', "# CSV v2\n");

    expect((new StandardsResolver)->resolve($project)->fingerprint)->not->toBe($before);
});

it('reads both flat and Agent OS nested index formats', function () {
    $project = fixtureProject([
        'flat.yml' => "a/b:\n  description: Flat\n",
        'nested.yml' => "a:\n  b:\n    description: Nested\n",
    ]);

    expect(Layer::readIndex($project->path('flat.yml')))->toBe(['a/b' => ['description' => 'Flat']])
        ->and(Layer::readIndex($project->path('nested.yml')))->toBe(['a/b' => ['description' => 'Nested']]);
});

it('carries category paths from the standards package', function () {
    expect((new StandardsResolver)->resolve(fixtureProject())->paths)->toBe(['testing' => ['tests/**']]);
});
