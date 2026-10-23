<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Providers\ProviderRegistry;

it('ships github, jira, bitbucket and file adapters', function () {
    expect(array_keys((new ProviderRegistry(fixtureProject()))->all()))->toBe(['bitbucket', 'file', 'github', 'jira']);
});

it('classifies refs with the configured trackers and free text as an idea', function () {
    $registry = new ProviderRegistry(fixtureProject(['ring-master.yml' => "tickets:\n  default: github\n  providers: { github: {}, jira: { site: acme.atlassian.net } }\n"]));

    expect($registry->resolve('#42'))->toBe(['kind' => 'ticket', 'provider' => 'github', 'ref' => '#42'])
        ->and($registry->resolve('PROJ-123')['provider'])->toBe('jira')
        ->and($registry->resolve('https://acme.atlassian.net/browse/PROJ-9')['provider'])->toBe('jira')
        ->and($registry->resolve('add a CSV importer')['kind'])->toBe('idea');
});

it('follows the code host by default: GitHub issues for GitHub, Jira for Bitbucket', function () {
    expect(ProviderRegistry::hostFromRemote('git@github.com:acme/pkg.git'))->toBe('github')
        ->and(ProviderRegistry::hostFromRemote('https://bitbucket.org/acme/pkg.git'))->toBe('bitbucket')
        ->and(ProviderRegistry::hostFromRemote(null))->toBeNull()
        ->and((new ProviderRegistry(fixtureProject(['ring-master.yml' => "host:\n  provider: bitbucket\n"])))->defaultTracker())->toBe('jira')
        ->and((new ProviderRegistry(fixtureProject(['ring-master.yml' => "host:\n  provider: github\n"])))->defaultTracker())->toBe('github');
});

it('lets a project add or replace an adapter', function () {
    $project = fixtureProject(['docs/agent/providers/linear.md' => "---\nname: linear\nkind: [tracker]\nclaims: ['^LIN-\\d+$']\n---\nUse the Linear MCP.\n", 'ring-master.yml' => "tickets:\n  default: linear\n"]);

    expect((new ProviderRegistry($project))->resolve('LIN-7')['provider'])->toBe('linear');
});

it('reports a configured tracker without an adapter', function () {
    expect((new ProviderRegistry(fixtureProject(['ring-master.yml' => "tickets:\n  default: trello\n"])))->problems())
        ->toContain('No tracker adapter named `trello` (tickets.providers).');
});
