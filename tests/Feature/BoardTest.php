<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Specs\Board;

function boardProject(): Project
{
    $spec = 'docs/agent/specs/2026-10-23-1430-import';

    return fixtureProject([
        "{$spec}/meta.yml" => "id: 2026-10-23-1430-import\ntitle: CSV import\nstatus: needs-decision\nticket: { provider: github, key: '#42', url: 'https://github.com/acme/pkg/issues/42' }\n",
        "{$spec}/tasks.md" => "## Group 1: Data\n- [x] 1.1 Tests\n- [ ] 1.2 Data\n",
        "{$spec}/spec.md" => "# CSV import\n",
        "{$spec}/decision.md" => "# NEEDS-DECISION\n\n1. **One row per file or per line?**\n2. **Sync or queued?**\n",
        "{$spec}/decisions.md" => "## D-001: Sync or queued?\n- Choice: queued\n",
        "{$spec}/round-1/gate.md" => "VERDICT: PASS\n",
        "{$spec}/round-1/review-code.md" => "VERDICT: CHANGES\n\n- off by one\n",
        "{$spec}/round-1/review-security.md" => "still writing\n",
        'docs/agent/specs/2026-10-20-0900-older/meta.yml' => "title: Older\nstatus: closed\n",
    ]);
}

it('puts every spec in its column with tasks, rounds and verdicts', function () {
    $board = (new Board([boardProject()]))->data();
    $import = $board['projects'][0]['specs'][0];

    expect($board['columns'])->toBe(['Ticket', 'Spec', 'Building', 'Needs you', 'In review', 'Done'])
        ->and($board['projects'][0]['name'])->toBe('refactor-circus/fixture')
        ->and(array_column($board['projects'][0]['specs'], 'column'))->toBe(['Needs you', 'Done'])
        ->and($import['ticket']['key'])->toBe('#42')
        ->and($import['tasks'])->toBe(['done' => 1, 'total' => 2])
        ->and($import['decision'])->toBe('needs-decision')
        ->and($import['questions'])->toBe(2)
        ->and($import['decisions'])->toBe(1)
        ->and(array_map(static fn (array $r): array => [$r['name'], $r['verdict']], $import['rounds'][0]['reports']))->toBe([['gate', 'pass'], ['code', 'changes'], ['security', null]])
        ->and($import['files'])->toContain('docs/agent/specs/2026-10-23-1430-import/spec.md');
});

it('serves only markdown and yaml inside the spec folders', function () {
    $project = boardProject();
    $board = new Board([$project]);

    expect($board->file(0, 'docs/agent/specs/2026-10-23-1430-import/spec.md'))->toBe("# CSV import\n")
        ->and($board->file(0, 'docs/agent/specs/../../../composer.json'))->toBeNull()
        ->and($board->file(0, 'composer.json'))->toBeNull()
        ->and($board->file(0, 'docs/agent/specs/2026-10-23-1430-import/../../../ring-master.yml'))->toBeNull()
        ->and($board->file(1, 'docs/agent/specs/2026-10-23-1430-import/spec.md'))->toBeNull();
});

it('reads open questions from questions.yml, with their options and recommendation', function () {
    $project = boardProject();
    writeFixture($project->root, 'docs/agent/specs/2026-10-23-1430-import/questions.yml', "- id: Q-001\n  question: One row per file or per line?\n  options: [Per file, Per line]\n  recommendation: Per line\n  source: build\n- question: '  Sync or queued?  '\n- not a question\n");

    $import = (new Board([$project]))->data()['projects'][0]['specs'][0];

    expect($import['questions'])->toBe(2)
        ->and($import['open_questions'][0])->toBe(['id' => 'Q-001', 'question' => 'One row per file or per line?', 'options' => ['Per file', 'Per line'], 'recommendation' => 'Per line', 'evidence' => null, 'source' => 'build'])
        ->and($import['open_questions'][1])->toMatchArray(['id' => 'Q-002', 'question' => 'Sync or queued?', 'options' => []])
        ->and($import['files'])->toContain('docs/agent/specs/2026-10-23-1430-import/questions.yml');
});

it('falls back to counting the questions in decision.md', function () {
    $import = (new Board([boardProject()]))->data()['projects'][0]['specs'][0];

    expect($import['questions'])->toBe(2)->and($import['open_questions'])->toBe([]);
});

it('counts no questions once questions.yml is emptied to []', function () {
    $project = boardProject();
    writeFixture($project->root, 'docs/agent/specs/2026-10-23-1430-import/questions.yml', "[]\n");

    $import = (new Board([$project]))->data()['projects'][0]['specs'][0];

    expect($import['questions'])->toBe(0)->and($import['open_questions'])->toBe([]);
});

it('reads a pr written as a bare url', function () {
    $project = fixtureProject(['docs/agent/specs/2026-10-25-1349-install/meta.yml' => "title: Install\nstatus: in-review\npr: https://github.com/acme/pkg/pull/1\n"]);

    expect((new Board([$project]))->data()['projects'][0]['specs'][0]['pr'])->toBe(['url' => 'https://github.com/acme/pkg/pull/1']);
});
