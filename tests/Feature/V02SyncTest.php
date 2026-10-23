<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Checker;
use RefactorCircus\Ringside\Installer;
use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Sync;

it('renders one subagent per configured reviewer plus the roles, with their models', function () {
    $project = fixtureProject(['ring-master.yml' => "review:\n  reviewers: [code, { name: compat, model: opus }]\nroles:\n  approver: { enabled: true }\n"]);
    (new Sync)->run($project);

    expect($project->read('.claude/agents/ring-master-reviewer-compat.md'))->toContain('model: opus', '## Severity rubric')
        ->and($project->read('.claude/agents/ring-master-reviewer-code.md'))->toContain('model: sonnet')
        ->and($project->exists('.claude/agents/ring-master-reviewer-security.md'))->toBeFalse()
        ->and($project->exists('.claude/agents/ring-master-approver.md'))->toBeTrue()
        ->and($project->read('.claude/agents/ring-master-critic.md'))->toContain('model: sonnet');
});

it('renders a custom reviewer from docs/agent/reviewers', function () {
    $project = fixtureProject([
        'docs/agent/reviewers/perf.md' => "Look for N+1 queries and unbounded loops.\n",
        'ring-master.yml' => "review:\n  reviewers: [perf]\n",
    ]);
    (new Sync)->run($project);

    expect($project->read('.claude/agents/ring-master-reviewer-perf.md'))->toContain("name: ring-master-reviewer-perf\n", 'N+1 queries', '## Severity rubric');
});

it('drops the skills of disabled stages', function () {
    $project = fixtureProject(['ring-master.yml' => "stages:\n  ticket: false\n  post_mortem: off\n"]);
    (new Sync)->run($project);

    expect($project->exists('.claude/skills/ring-master-ticket/SKILL.md'))->toBeFalse()
        ->and($project->exists('.claude/skills/ring-master-post-mortem/SKILL.md'))->toBeFalse()
        ->and($project->exists('.claude/skills/ring-master-factory/SKILL.md'))->toBeTrue();
});

it('inlines only the configured provider adapters into the skills', function () {
    $project = fixtureProject(['ring-master.yml' => "host:\n  provider: bitbucket\ntickets:\n  providers: { jira: { site: acme.atlassian.net } }\n"]);
    (new Sync)->run($project);
    $factory = (string) $project->read('.claude/skills/ring-master-factory/SKILL.md');

    expect($factory)->toContain('## Jira (tracker)', '## Bitbucket Cloud', 'ring-master-factory.js')->not->toContain('## GitHub (tracker + host)');
});

it('passes the configured formatter to the post-edit hook', function () {
    $project = fixtureProject(['ring-master.yml' => "hooks:\n  post_edit_format: { command: 'vendor/bin/php-cs-fixer fix {file}' }\n"]);
    (new Sync)->run($project);

    expect((string) $project->read('.claude/settings.json'))->toContain("post-edit 'vendor/bin/php-cs-fixer fix {file}'");
});

it('fails check when a strict global package ships a non-architectural standard', function () {
    $project = fixtureProject(standards: ['testing/pest-style' => "# Pest\n"]);
    writeFixture($project->root, 'vendor/refactor-circus/standards/index.yml', "testing/pest-style:\n  description: Pest\n  kind: convention\n");
    writeFixture($project->root, 'pint.json', "{\"preset\": \"laravel\"}\n");
    (new Sync)->run($project);

    expect(implode("\n", (new Checker)->check($project)))->toContain('is strict, so every standard must be kind: architectural; testing/pest-style is convention');
});

it('reports a configured global package that is not installed', function () {
    $project = withConfig(fixtureProject(), "  # replaced below\n");
    writeFixture($project->root, 'ring-master.yml', "standards:\n  global: [acme/standards]\n");

    expect(implode("\n", (new Checker)->check(reload($project))))->toContain('acme/standards is configured in standards.global but not installed');
});

it('works with no global standards at all', function () {
    $project = fixtureProject();
    writeFixture($project->root, 'ring-master.yml', "standards:\n  global: []\n");
    $result = (new Sync)->run(reload($project));

    expect($result->resolution->standards)->toBe([])
        ->and($project->exists('.claude/skills/ring-master-propose/SKILL.md'))->toBeFalse()
        ->and($project->exists('.claude/skills/ring-master-ship/SKILL.md'))->toBeTrue();
});

it('seeds package standards from the global package on request, without overwriting', function () {
    $project = fixtureProject(extra: ['seeds' => 'seeds']);
    writeFixture($project->root, 'vendor/refactor-circus/standards/seeds/testing/style.md', "---\nkind: convention\n---\n# Style\n");
    writeFixture($project->root, 'docs/agent/standards/testing/keep.md', "# Mine\n");

    $log = implode("\n", (new Installer)->install($project, seed: true));

    expect($log)->toContain('Seeded docs/agent/standards/testing/style.md from refactor-circus/standards')
        ->and($project->read('docs/agent/standards/testing/style.md'))->toContain('seeded_from: refactor-circus/standards@0.1.0', 'kind: convention');
});

it('writes a short ring-master.yml from what it detects on first install', function () {
    $root = fixtureProject()->root;
    unlink($root.'/ring-master.yml');
    writeFixture($root, 'composer.json', json_encode(['name' => 'acme/pkg', 'scripts' => ['test' => 'x', 'test:unit' => 'y', 'lint' => 'z', 'lint:check' => 'w']]));

    $log = implode("\n", (new Installer)->install(new Project($root)));
    $yaml = (string) file_get_contents($root.'/ring-master.yml');

    expect($yaml)->toContain('test: \'composer test:unit\'', 'verify: \'composer test\'', 'refactor-circus/standards')
        ->and($log)->toContain('as global standards');
});
