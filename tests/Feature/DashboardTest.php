<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Console\DashboardCommand;
use RefactorCircus\Ringside\Dashboard\Board;
use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Render\Template;

function dashboardProject(): Project
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
    $board = (new Board([dashboardProject()]))->data();
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
    $project = dashboardProject();
    $board = new Board([$project]);

    expect($board->file(0, 'docs/agent/specs/2026-10-23-1430-import/spec.md'))->toBe("# CSV import\n")
        ->and($board->file(0, 'docs/agent/specs/../../../composer.json'))->toBeNull()
        ->and($board->file(0, 'composer.json'))->toBeNull()
        ->and($board->file(0, 'docs/agent/specs/2026-10-23-1430-import/../../../ring-master.yml'))->toBeNull()
        ->and($board->file(1, 'docs/agent/specs/2026-10-23-1430-import/spec.md'))->toBeNull();
});

it('combines several packages, this one first, dropping duplicates and non-packages', function () {
    $a = dashboardProject();
    $b = fixtureProject();
    $roots = DashboardCommand::roots($a, [$b->root, $a->root, '/no/such/place']);

    expect($roots)->toBe([realpath($a->root), realpath($b->root)]);
});

it('serves the page and the API from the router', function () {
    $project = dashboardProject();
    $port = random_int(20000, 40000);
    $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:'.$port, Template::resources('dashboard/router.php')],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        Template::resources('dashboard'),
        [...getenv(), 'RINGSIDE_AUTOLOAD' => $autoload, 'RINGSIDE_PROJECTS' => json_encode([$project->root])],
    );

    try {
        $get = static function (string $path) use ($port): ?string {
            for ($i = 0; $i < 50; $i++) {
                set_error_handler(static fn (): bool => true);
                $body = file_get_contents("http://127.0.0.1:{$port}{$path}");
                restore_error_handler();

                if ($body !== false) {
                    return $body;
                }

                usleep(100_000);
            }

            return null;
        };

        expect($get('/'))->toContain('<title>Ring Master jobs</title>')
            ->and(json_decode((string) $get('/api/board'), true)['projects'][0]['specs'][0]['title'])->toBe('CSV import')
            ->and($get('/api/file?project=0&path='.rawurlencode('docs/agent/specs/2026-10-23-1430-import/spec.md')))->toBe("# CSV import\n");
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
})->skipOnWindows();
