<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Render\ManagedBlock;
use RefactorCircus\Ringside\Sync;

it('writes the index, summary, skills, subagent, hooks and managed block', function () {
    $project = fixtureProject(['AGENTS.md' => "# Hand-written\n\nKeep me.\n"]);

    $result = (new Sync)->run($project);

    expect($result->written)->toContain('docs/agent/standards.index.yml', 'docs/agent/standards.summary.md', '.claude/settings.json', 'AGENTS.md')
        ->and(glob($project->path('.claude/skills/ring-master-*/SKILL.md')))->toHaveCount(19)
        ->and($project->read('.claude/skills/ring-master-std-testing/SKILL.md'))->toContain("paths:\n  - 'tests/**'", 'vendor/refactor-circus/standards/standards/testing/pest-style.md')
        ->and($project->read('.claude/skills/ring-master-shape-spec/SKILL.md'))->toContain('disable-model-invocation: true', 'docs/agent/specs/')->not->toContain('{{')
        ->and($project->read('.claude/agents/ring-master-implementer.md'))->toContain('skills: [ring-master-std-architecture, ring-master-std-testing]', 'maxTurns: 40')
        ->and($project->read('AGENTS.md'))->toStartWith("# Hand-written\n\nKeep me.\n")->toContain(ManagedBlock::START, '/ring-master-shape-spec');
});

it('is a no-op when nothing changed', function () {
    $project = fixtureProject();
    (new Sync)->run($project);

    expect((new Sync)->run($project)->written)->toBe([]);
});

it('re-renders after a local standard is added', function () {
    $project = fixtureProject();
    (new Sync)->run($project);

    writeFixture($project->root, 'docs/agent/standards/import/csv.md', "# CSV\n");

    expect((new Sync)->run($project)->written)->toContain('.claude/skills/ring-master-std-import/SKILL.md');
});

it('prunes generated standards skills whose category is gone', function () {
    $project = fixtureProject(['docs/agent/standards/import/csv.md' => "# CSV\n"]);
    (new Sync)->run($project);

    unlink($project->path('docs/agent/standards/import/csv.md'));
    (new Sync)->run($project);

    expect($project->exists('.claude/skills/ring-master-std-import/SKILL.md'))->toBeFalse();
});

it('never touches hand-written skills', function () {
    $project = fixtureProject(['.claude/skills/ring-master-mine/SKILL.md' => "---\nname: ring-master-mine\n---\nMine.\n"]);

    (new Sync)->run($project);

    expect($project->read('.claude/skills/ring-master-mine/SKILL.md'))->toBe("---\nname: ring-master-mine\n---\nMine.\n");
});

it('keeps other hooks and replaces only its own', function () {
    $project = fixtureProject(['.claude/settings.json' => json_encode(['permissions' => ['allow' => ['Bash(ls)']], 'hooks' => ['PostToolUse' => [
        ['matcher' => 'Write', 'hooks' => [['type' => 'command', 'command' => 'echo mine']]],
        ['matcher' => 'Edit', 'hooks' => [['type' => 'command', 'command' => 'php old/ringside-hook post-edit']]],
    ]]])]);

    (new Sync)->run($project);
    $settings = json_decode((string) $project->read('.claude/settings.json'), true);
    $commands = array_map(static fn (array $entry): string => $entry['hooks'][0]['command'], $settings['hooks']['PostToolUse']);

    expect($settings['permissions'])->toBe(['allow' => ['Bash(ls)']])
        ->and($commands)->toHaveCount(2)
        ->and($commands[0])->toBe('echo mine')
        ->and($commands[1])->toContain('$CLAUDE_PROJECT_DIR/vendor/refactor-circus/ringside/bin/ringside-hook')
        ->and($settings['hooks']['SessionStart'][0]['matcher'])->toBe('startup|resume');
});

it('removes its hooks when they are turned off', function () {
    $project = fixtureProject();
    (new Sync)->run($project);

    (new Sync)->run(withConfig($project, "hooks:\n  post_edit_format: { enabled: false }\n  session_start_summary: false\n"));

    expect(json_decode((string) $project->read('.claude/settings.json'), true))->not->toHaveKey('hooks');
});

it('mirrors workflow skills into .agents/skills when targeted', function () {
    $project = fixtureProject(['ring-master.yml' => "targets: [claude, agents]\n"]);

    (new Sync)->run($project);

    expect($project->exists('.agents/skills/ring-master-implement/SKILL.md'))->toBeTrue()
        ->and($project->exists('.agents/skills/ring-master-std-testing/SKILL.md'))->toBeFalse();
});

it('updates the managed block in place on later syncs', function () {
    $block = ManagedBlock::START."\nold\n".ManagedBlock::END;

    expect(ManagedBlock::upsert("# A\n\n{$block}\n\n# B\n", ManagedBlock::START."\nnew\n".ManagedBlock::END))
        ->toBe("# A\n\n".ManagedBlock::START."\nnew\n".ManagedBlock::END."\n\n# B\n");
});

it('keeps the session summary short', function () {
    $project = fixtureProject();
    (new Sync)->run($project);

    expect(str_word_count((string) $project->read('docs/agent/standards.summary.md')))->toBeLessThan(80);
});

it('keeps its skills when .claude is a symlink to .agents', function () {
    $project = fixtureProject(['.agents/skills/package-testing/SKILL.md' => "keep\n"]);
    symlink('.agents', $project->path('.claude'));

    (new Sync)->run($project);
    (new Sync)->run($project, force: true);

    expect($project->exists('.agents/skills/ring-master-implement/SKILL.md'))->toBeTrue()
        ->and($project->exists('.agents/skills/ring-master-std-testing/SKILL.md'))->toBeTrue()
        ->and($project->read('.agents/skills/package-testing/SKILL.md'))->toBe("keep\n");
})->skipOnWindows();

it('writes the managed block once when CLAUDE.md links to AGENTS.md', function () {
    $project = fixtureProject(['AGENTS.md' => "# Rules\n"]);
    symlink('AGENTS.md', $project->path('CLAUDE.md'));

    (new Sync)->run($project);

    expect(substr_count((string) $project->read('AGENTS.md'), ManagedBlock::START))->toBe(1)
        ->and(is_link($project->path('CLAUDE.md')))->toBeTrue();
})->skipOnWindows();

it('names the verify command in the session summary', function () {
    $project = fixtureProject(['ring-master.yml' => "commands:\n  verify: 'composer test'\n"]);
    (new Sync)->run($project);

    expect($project->read('docs/agent/standards.summary.md'))->toContain('Done means `composer test` passes.');
});

it('renders /ring-master-inbox only when a RingMaster app is configured, pointing at it', function () {
    $project = fixtureProject();
    (new Sync)->run($project);

    expect($project->exists('.claude/skills/ring-master-inbox/SKILL.md'))->toBeFalse()
        ->and((string) $project->read(Sync::summaryPath($project)))->not->toContain('ring-master-app');

    $project = withConfig($project, "board: { app: /opt/ring-master/ }\n");
    (new Sync)->run($project, force: true);

    expect((string) $project->read('.claude/skills/ring-master-inbox/SKILL.md'))->toContain('php /opt/ring-master/artisan mcp:start ring-master')
        ->and((string) $project->read(Sync::summaryPath($project)))->toContain("# ring-master-app: /opt/ring-master\n");
});
