<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Discovery\Proposer;
use RefactorCircus\Ringside\Shell;

function standardsRepo(): string
{
    $repo = sys_get_temp_dir().'/ring-master-repo-'.bin2hex(random_bytes(4));
    writeFixture($repo, 'composer.json', json_encode(['name' => 'acme/standards', 'extra' => ['ring-master' => ['root' => 'standards', 'index' => 'index.yml']]]));
    writeFixture($repo, 'index.yml', "models/models:\n  description: Models\n  kind: architectural\n");
    Shell::run(['git', 'init', '-q', '-b', 'main'], $repo);
    Shell::run(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'add', '.'], $repo);
    Shell::run(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'init'], $repo);
    Shell::run(['git', 'config', 'user.email', 't@t'], $repo);
    Shell::run(['git', 'config', 'user.name', 't'], $repo);

    return $repo;
}

it('commits a queued architectural proposal on a branch of the global package', function () {
    $project = fixtureProject(['docs/agent/standards-proposals/global/models/eager-loading.md' => "---\ndescription: Eager-load relations a resource serializes\ntags: [models]\nkind: architectural\n---\n# Eager loading\n\nLoad what you serialize.\n"]);
    $repo = standardsRepo();

    $done = (new Proposer)->propose($project, 'models/eager-loading', $repo);

    expect($done['branch'])->toStartWith('proposal/')
        ->and((string) file_get_contents($repo.'/standards/models/eager-loading.md'))->toBe("# Eager loading\n\nLoad what you serialize.\n")
        ->and((string) file_get_contents($repo.'/index.yml'))->toContain("models/eager-loading:\n  description: 'Eager-load relations a resource serializes'", 'kind: architectural')
        ->and(Shell::run(['git', 'log', '--oneline', '-1'], $repo)[1])->toContain('Propose models/eager-loading');
});

it('refuses to send a non-architectural proposal to the global standards', function () {
    $project = fixtureProject(['docs/agent/standards-proposals/global/testing/style.md' => "---\nkind: convention\n---\n# Style\n"]);

    (new Proposer)->propose($project, 'testing/style', standardsRepo());
})->throws(RuntimeException::class, 'architectural only');
