<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Migrator;
use RefactorCircus\Ringside\Project;

function legacyProject(): Project
{
    return fixtureProject([
        'agent-os/standards/index.yml' => "testing:\n  pest-style:\n    description: \"Same as vendor\"\nimport:\n  csv:\n    description: \"CSV rules\"\narchitecture:\n  actions-events:\n    description: \"Old copy\"\n",
        'agent-os/standards/testing/pest-style.md' => "# Pest Style\n\nUse it().\n",
        'agent-os/standards/import/csv.md' => "# CSV\n\nRules.\n",
        'agent-os/standards/architecture/actions-events.md' => "# Actions & Events\n\nOld wording.\n",
        'agent-os/specs/2026-10-01-1218-audit-log/shape.md' => "# Audit Log — Shaping Notes\n",
        'agent-os/specs/2026-10-01-1218-audit-log/plan.md' => "# Plan\n",
        'agent-os/product/mission.md' => "# Mission\n",
        '.claude/commands/agent-os/shape-spec.md' => "old\n",
        '.agents/commands/agent-os/shape-spec.md' => "old\n",
        '.agents/skills/package-testing/SKILL.md' => "keep\n",
    ]);
}

it('plans without touching anything on a dry run', function () {
    $project = legacyProject();

    $log = implode("\n", (new Migrator)->migrate($project, dryRun: true));

    expect($log)->toContain('Would drop agent-os/standards/testing/pest-style.md (identical to refactor-circus/standards@0.1.0)', 'Would move agent-os/standards/import/csv.md', 'Would delete .claude/commands/agent-os/')
        ->and($project->exists('agent-os/standards/import/csv.md'))->toBeTrue();
});

it('moves standards, specs and product docs and removes the Agent OS install', function () {
    $project = legacyProject();

    (new Migrator)->migrate($project);

    expect($project->exists('agent-os'))->toBeFalse()
        ->and($project->exists('docs/agent/standards/testing/pest-style.md'))->toBeFalse()
        ->and($project->read('docs/agent/standards/import/csv.md'))->toBe("---\ndescription: 'CSV rules'\n---\n# CSV\n\nRules.\n")
        ->and($project->read('docs/agent/standards/architecture/actions-events.md'))->toContain('overrides: refactor-circus/standards', 'Old wording.')
        ->and($project->read('docs/agent/specs/2026-10-01-1218-audit-log/plan.md'))->toBe("# Plan\n")
        ->and($project->read('docs/agent/specs/2026-10-01-1218-audit-log/meta.yml'))->toContain('title: \'Audit Log\'', 'status: imported', "created: '2026-10-01'")
        ->and($project->read('docs/agent/product/mission.md'))->toBe("# Mission\n")
        ->and($project->exists('.claude/commands/agent-os'))->toBeFalse()
        ->and($project->exists('.agents/commands'))->toBeFalse()
        ->and($project->read('.agents/skills/package-testing/SKILL.md'))->toBe("keep\n");
});

it('follows renames a global package declares, so old copies become overrides of the new keys', function () {
    $project = fixtureProject(
        files: ['agent-os/standards/architecture/actions-events.md' => "# Old actions\n"],
        standards: ['actions/actions-events' => "# Actions & Events\n"],
        extra: ['renames' => ['architecture/actions-events' => 'actions/actions-events']],
    );

    $log = implode("\n", (new Migrator)->migrate($project));

    expect($log)->toContain('-> docs/agent/standards/actions/actions-events.md (overrides refactor-circus/standards@0.1.0; review it)')
        ->and($project->exists('docs/agent/standards/architecture/actions-events.md'))->toBeFalse();
});
