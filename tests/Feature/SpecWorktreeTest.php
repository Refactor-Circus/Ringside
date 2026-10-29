<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Shell;
use RefactorCircus\Ringside\Specs\Board;
use RefactorCircus\Ringside\Specs\Job;
use RefactorCircus\Ringside\Specs\Locator;
use RefactorCircus\Ringside\Specs\SpecRepository;
use RefactorCircus\Ringside\Worktree;

// A spec lives in its job worktree from the moment it is created, so every
// stage's record is committed on the job branch and the PR carries it.

/**
 * A package committed as the first commit of its own git repo on `main`,
 * with $files (a merged spec, say) in that commit.
 *
 * @param  array<string, string>  $files
 */
function specPackage(array $files = []): Project
{
    $project = fixtureProject($files);

    foreach ([['init', '-q', '-b', 'main'], ['config', 'user.email', 't@t'], ['config', 'user.name', 't'], ['add', '.'], ['commit', '-q', '-m', 'init']] as $args) {
        Shell::run(['git', ...$args], $project->root);
    }

    return $project;
}

/**
 * Runs `bin/ringside spec ...` from $cwd; returns the exit code and output.
 *
 * @param  list<string>  $args
 * @return array{0: int, 1: string}
 */
function ringsideSpec(string $cwd, array $args): array
{
    return Shell::run([PHP_BINARY, dirname(__DIR__, 2).'/bin/ringside', 'spec', ...$args], $cwd, [], true);
}

/**
 * @return array{id: string, path: string, worktree: ?string, branch: ?string}
 */
function newSpec(Project $project, string $title = 'CSV import'): array
{
    [$code, $out] = ringsideSpec($project->root, ['new', $title, '--json']);
    expect($code)->toBe(0);

    return json_decode($out, true, flags: JSON_THROW_ON_ERROR);
}

function git(string $dir, string ...$args): string
{
    return Shell::run(['git', ...$args], $dir)[1];
}

describe('spec new', function () {
    it('creates the spec in its own job worktree on the job branch, never on main', function () {
        $project = specPackage();

        $spec = newSpec($project);
        $main = dirname((string) realpath($project->root));

        expect($spec['id'])->toMatch('/^\d{4}-\d{2}-\d{2}-\d{4}-csv-import$/')
            ->and($spec['branch'])->toBe('ring-master/'.$spec['id'])
            ->and($spec['worktree'])->toBe($main.'/'.basename($project->root).'--'.$spec['id'])
            ->and($spec['path'])->toBe($spec['worktree'].'/docs/agent/specs/'.$spec['id'])
            ->and((string) file_get_contents($spec['path'].'/meta.yml'))->toContain("title: 'CSV import'", 'status: shaping')
            ->and(git($spec['worktree'], 'branch', '--show-current'))->toBe($spec['branch'])
            ->and(is_dir($project->path('docs/agent/specs/'.$spec['id'])))->toBeFalse();
    });

    it('creates it in the package itself when the package builds without job worktrees', function () {
        $project = specPackage(['ring-master.yml' => "build:\n  worktree:\n    mode: none\n"]);

        $spec = newSpec($project);

        expect($spec['worktree'])->toBeNull()
            ->and($spec['path'])->toBe(realpath($project->root).'/docs/agent/specs/'.$spec['id']);
    });

    it('starts at the status it is given', function () {
        $project = specPackage();

        [, $path] = ringsideSpec($project->root, ['new', 'Bulk export', '--status=ticketed']);

        expect((string) file_get_contents($path.'/meta.yml'))->toContain('status: ticketed');
    });

    it('refuses a title with nothing to slug', function () {
        [$code, $out] = ringsideSpec(specPackage()->root, ['new', '!!!']);

        expect($code)->toBe(1)->and($out)->toContain('at least one letter or digit');
    });
})->skipOnWindows();

describe('finding a spec', function () {
    it('finds a spec in its job worktree through the board, the repository and the job', function () {
        $project = specPackage();
        $spec = newSpec($project);
        file_put_contents($spec['path'].'/spec.md', "# CSV import\n");
        file_put_contents($spec['path'].'/tasks.md', "## Group 1: Data\n- [ ] 1.1 Data\n");

        $board = new Board([$project]);
        $listed = $board->data()['projects'][0]['specs'][0];
        $job = (new Job)->build($project, 'csv-import');

        expect($listed)->toMatchArray(['id' => $spec['id'], 'dir' => 'docs/agent/specs/'.$spec['id'], 'path' => $spec['path'], 'worktree' => $spec['worktree'], 'status' => 'shaping'])
            ->and($listed['tasks'])->toBe(['done' => 0, 'total' => 1])
            ->and($board->file(0, 'docs/agent/specs/'.$spec['id'].'/spec.md'))->toBe("# CSV import\n")
            ->and($board->file(0, 'docs/agent/specs/'.$spec['id'].'/../../../../composer.json'))->toBeNull()
            ->and(array_map(static fn ($s): string => $s->id, (new SpecRepository)->all($project)))->toBe([$spec['id']])
            ->and($job['spec']['path'])->toBe($spec['path'])
            ->and($job['spec']['dir'])->toBe('docs/agent/specs/'.$spec['id'])
            ->and($job['waves'])->toHaveCount(1);
    });

    it('lists the merged specs on main beside the ones in worktrees, the worktree copy winning', function () {
        $project = specPackage(['docs/agent/specs/2026-10-20-0900-older/meta.yml' => "title: Older\nstatus: closed\n"]);
        $spec = newSpec($project);
        // A merged spec reopened in its job worktree reads from there, not main.
        Worktree::prepare($project, '../'.basename($project->root).'--older', 'ring-master/2026-10-20-0900-older', 'main');
        file_put_contents(dirname((string) realpath($project->root)).'/'.basename($project->root).'--older/docs/agent/specs/2026-10-20-0900-older/meta.yml', "title: Older\nstatus: merged\n");

        $statuses = array_column((new Board([$project]))->data()['projects'][0]['specs'], 'status', 'id');

        expect($statuses)->toBe([$spec['id'] => 'shaping', '2026-10-20-0900-older' => 'merged']);
    });

    it('never takes a spec from a group, variant or replay worktree', function () {
        $project = specPackage();
        $spec = newSpec($project);
        git($spec['worktree'], 'add', '.');
        git($spec['worktree'], 'commit', '-q', '-m', 'record');
        $job = Job::worktree($project, $spec['id']);
        Worktree::groups($project, $job, $spec['branch'], [1]);
        Worktree::variants($project, $job, $spec['branch'], [1]);
        Worktree::prepare($project, $job.Worktree::REPLAY, $spec['branch'].Worktree::REPLAY, $spec['branch']);

        expect(Locator::worktrees($project))->toBe([$spec['id'] => $spec['worktree']]);
    });

    it('prints where a spec lives', function () {
        $project = specPackage();
        $spec = newSpec($project);

        [$code, $out] = ringsideSpec($project->root, ['path', 'csv-import']);

        expect($code)->toBe(0)->and($out)->toBe($spec['path']);
    });
})->skipOnWindows();

describe('spec commit', function () {
    it('commits the spec folder alone on the job branch, leaving scratch files and other work out', function () {
        $project = specPackage(['.gitignore' => "/docs/agent/specs/*/.*\n/docs/agent/specs/**/*.tmp.*\n/docs/agent/specs/*/*body*.md\n/docs/agent/specs/*/replay/\n"]);
        $spec = newSpec($project);
        file_put_contents($spec['path'].'/shape.md', "# Shape\n");
        file_put_contents($spec['path'].'/pr-body.md', "scratch\n");
        file_put_contents($spec['path'].'/comment.tmp.md', "scratch\n");
        file_put_contents($spec['worktree'].'/README.md', "unrelated\n");

        [$code, $out] = ringsideSpec($project->root, ['commit', 'csv-import', 'shaped']);

        expect($code)->toBe(0)
            ->and($out)->toBe('Committed docs/agent/specs/'.$spec['id'].' on '.$spec['branch'].'.')
            ->and(git($spec['worktree'], 'log', '-1', '--format=%s'))->toBe($spec['id'].': shaped')
            ->and(explode("\n", git($spec['worktree'], 'show', '--name-only', '--format=', 'HEAD')))->toBe(['docs/agent/specs/'.$spec['id'].'/meta.yml', 'docs/agent/specs/'.$spec['id'].'/shape.md'])
            ->and(git($spec['worktree'], 'status', '--porcelain'))->toBe('?? README.md')
            ->and(git($project->root, 'log', '-1', '--format=%s', 'main'))->toBe('init');
    });

    it('says so when there is nothing new', function () {
        $project = specPackage();
        newSpec($project);
        ringsideSpec($project->root, ['commit', 'csv-import', 'shaped']);

        [$code, $out] = ringsideSpec($project->root, ['commit', 'csv-import', 'again']);

        expect($code)->toBe(0)->and($out)->toContain('Nothing new to commit');
    });

    it('never commits a spec on the main checkout\'s base branch', function () {
        $project = specPackage(['docs/agent/specs/2026-10-20-0900-older/meta.yml' => "status: shaping\n"]);
        file_put_contents($project->path('docs/agent/specs/2026-10-20-0900-older/shape.md'), "# Shape\n");

        [$code, $out] = ringsideSpec($project->root, ['commit', 'older', 'shaped']);

        expect($code)->toBe(0)
            ->and($out)->toContain('is on the main checkout')
            ->and(git($project->root, 'log', '-1', '--format=%s'))->toBe('init');
    });
})->skipOnWindows();

describe('spec reopen', function () {
    it('reopens a merged spec on a record branch from base, which then wins', function () {
        $project = specPackage();
        $spec = newSpec($project);
        ringsideSpec($project->root, ['commit', 'csv-import', 'shaped']);
        git($project->root, 'merge', '-q', '--ff-only', $spec['branch']);

        [$code, $out] = ringsideSpec($project->root, ['reopen', 'csv-import', '--json']);
        $record = json_decode($out, true);

        expect($code)->toBe(0)
            ->and($record['branch'])->toBe($spec['branch'].'-record')
            ->and($record['worktree'])->toBe($spec['worktree'].'-record')
            ->and(Locator::worktrees($project))->toBe([$spec['id'] => $spec['worktree'].'-record'])
            ->and(Locator::find($project, $spec['id']))->toBe($record['path']);
    });

    it('refuses a spec that is not on base yet and leaves nothing behind', function () {
        $project = specPackage();
        $spec = newSpec($project);

        [$code, $out] = ringsideSpec($project->root, ['reopen', 'csv-import']);

        expect($code)->toBe(1)
            ->and($out)->toContain('is not on main yet')
            ->and(is_dir($spec['worktree'].'-record'))->toBeFalse()
            ->and(git($project->root, 'branch', '--list', $spec['branch'].'-record'))->toBe('');
    });
})->skipOnWindows();

describe('refresh', function () {
    it('rebases a branch holding only its spec record onto base', function () {
        $project = specPackage();
        $spec = newSpec($project);
        ringsideSpec($project->root, ['commit', 'csv-import', 'shaped']);
        file_put_contents($project->path('README.md'), "later\n");
        git($project->root, 'add', 'README.md');
        git($project->root, 'commit', '-q', '-m', 'later on main');

        expect(Worktree::refresh($spec['worktree'], 'main', 'docs/agent/specs/'.$spec['id']))->toBe('Rebased onto main')
            ->and(is_file($spec['worktree'].'/README.md'))->toBeTrue()
            ->and(git($spec['worktree'], 'log', '-1', '--format=%s'))->toBe($spec['id'].': shaped')
            ->and(Worktree::refresh($spec['worktree'], 'main', 'docs/agent/specs/'.$spec['id']))->toBeNull();
    });

    it('leaves a branch with code changes, or uncommitted work, as it is', function () {
        $project = specPackage();
        $spec = newSpec($project);
        ringsideSpec($project->root, ['commit', 'csv-import', 'shaped']);
        git($project->root, 'commit', '-q', '--allow-empty', '-m', 'later on main');
        file_put_contents($spec['worktree'].'/src.php', "<?php\n");

        expect(Worktree::refresh($spec['worktree'], 'main', 'docs/agent/specs/'.$spec['id']))->toBeNull();

        git($spec['worktree'], 'add', 'src.php');
        git($spec['worktree'], 'commit', '-q', '-m', 'code');

        expect(Worktree::refresh($spec['worktree'], 'main', 'docs/agent/specs/'.$spec['id']))->toBeNull();
    });
})->skipOnWindows();
