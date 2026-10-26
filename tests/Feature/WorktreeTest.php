<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Shell;
use RefactorCircus\Ringside\Worktree;

const WORKTREE_BRANCH = 'ring-master/2026-10-25-1349-demo';

/**
 * Package `Pkg` in its own temp folder: a git repo on `main` whose
 * composer.json has a `local:install` script, so a test can prove it never runs.
 */
function worktreePackage(): Project
{
    $root = sys_get_temp_dir().'/ring-master-'.bin2hex(random_bytes(6));
    mkdir($root, 0o755, true);
    packageRepo($root, 'Pkg', [], ['local:install' => 'composer-local-install']);

    return new Project($root.'/Pkg');
}

/**
 * The job worktree's path: a sibling of the package, as `job.worktree` is.
 */
function worktreePath(Project $project): string
{
    return dirname($project->root).'/Pkg--demo';
}

/**
 * Puts a fake `composer` first on PATH. Each run appends `cwd|args|COMPOSER`
 * to bin/composer.log (COMPOSER as `<unset>` when unset or empty), prints a
 * stdout marker and exits with FAKE_COMPOSER_EXIT (default 0); a failing run
 * also writes a stderr marker. Returns the bin dir.
 */
function fakeComposer(): string
{
    $bin = sys_get_temp_dir().'/ring-master-bin-'.bin2hex(random_bytes(6));
    mkdir($bin, 0o755, true);
    file_put_contents($bin.'/composer', <<<SH
        #!/bin/sh
        printf '%s|%s|%s\\n' "\$(pwd -P)" "\$*" "\${COMPOSER:-<unset>}" >> '{$bin}/composer.log'
        echo 'fake composer stdout'
        code="\${FAKE_COMPOSER_EXIT:-0}"
        if [ "\$code" != 0 ]; then echo 'fake composer stderr' >&2; fi
        exit "\$code"
        SH);
    chmod($bin.'/composer', 0o755);
    putenv('PATH='.$bin.PATH_SEPARATOR.getenv('PATH'));

    return $bin;
}

/**
 * The fake composer's runs, oldest first.
 *
 * @return list<array{cwd: string, args: string, composer: string}>
 */
function composerRuns(string $bin): array
{
    $log = $bin.'/composer.log';
    $lines = is_file($log) ? (array) file($log, FILE_IGNORE_NEW_LINES) : [];

    return array_map(static function (string $line): array {
        [$cwd, $args, $composer] = explode('|', $line);

        return ['cwd' => $cwd, 'args' => $args, 'composer' => $composer];
    }, array_values($lines));
}

function currentBranch(string $dir): string
{
    return Shell::run(['git', 'branch', '--show-current'], $dir)[1];
}

beforeEach(function () {
    $this->savedEnv = ['PATH' => getenv('PATH'), 'COMPOSER' => getenv('COMPOSER'), 'FAKE_COMPOSER_EXIT' => getenv('FAKE_COMPOSER_EXIT')];
    $this->bin = fakeComposer();
    $this->project = worktreePackage();
    $this->path = worktreePath($this->project);
});

afterEach(function () {
    foreach ($this->savedEnv as $name => $value) {
        putenv($value === false ? $name : "{$name}={$value}");
    }
});

describe('prepare', function () {
    it('creates the worktree on a new branch from the base', function (bool $relative) {
        $path = $relative ? '../Pkg--demo' : $this->path;

        $prepared = Worktree::prepare($this->project, $path, WORKTREE_BRANCH, 'main');

        expect(realpath($prepared))->toBe(realpath($this->path))
            ->and(currentBranch($this->path))->toBe(WORKTREE_BRANCH)
            ->and(Shell::run(['git', 'rev-parse', WORKTREE_BRANCH], $this->project->root)[1])
            ->toBe(Shell::run(['git', 'rev-parse', 'main'], $this->project->root)[1])
            ->and(Shell::run(['git', 'worktree', 'list'], $this->project->root)[1])->toContain(realpath($this->path));
    })->with(['absolute path' => false, 'path relative to the package' => true]);

    it('checks out the job branch when it already exists', function () {
        $root = $this->project->root;
        Shell::run(['git', 'checkout', '-q', '-b', WORKTREE_BRANCH], $root);
        writeFixture($root, 'on-branch.txt', "job work\n");
        Shell::run(['git', 'add', '.'], $root);
        Shell::run(['git', 'commit', '-q', '-m', 'job work'], $root);
        Shell::run(['git', 'checkout', '-q', 'main'], $root);

        Worktree::prepare($this->project, $this->path, WORKTREE_BRANCH, 'main');

        expect(currentBranch($this->path))->toBe(WORKTREE_BRANCH)
            ->and(is_file($this->path.'/on-branch.txt'))->toBeTrue();
    });

    it('reuses an existing worktree on the job branch', function () {
        Worktree::prepare($this->project, $this->path, WORKTREE_BRANCH, 'main');
        writeFixture($this->path, 'in-job.txt', "kept\n");
        $before = Shell::run(['git', 'worktree', 'list'], $this->project->root)[1];

        $again = Worktree::prepare($this->project, $this->path, WORKTREE_BRANCH, 'main');

        expect($again)->toBe($this->path)
            ->and(Shell::run(['git', 'worktree', 'list'], $this->project->root)[1])->toBe($before)
            ->and(is_file($this->path.'/in-job.txt'))->toBeTrue();
    });

    it('refuses an existing worktree on another branch and leaves it there', function (array $switch, string $actual) {
        Worktree::prepare($this->project, $this->path, WORKTREE_BRANCH, 'main');
        Shell::run(['git', 'checkout', '-q', ...$switch], $this->path);
        $head = Shell::run(['git', 'rev-parse', 'HEAD'], $this->path)[1];

        try {
            Worktree::prepare($this->project, $this->path, WORKTREE_BRANCH, 'main');
            $this->fail('prepare reused a worktree on the wrong branch');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toContain($actual)->toContain(WORKTREE_BRANCH);
        }

        expect(currentBranch($this->path))->toBe($switch === ['--detach'] ? '' : 'other')
            ->and(Shell::run(['git', 'rev-parse', 'HEAD'], $this->path)[1])->toBe($head);
    })->with([
        'another branch' => [['-b', 'other'], '`other`'],
        'a detached HEAD' => [['--detach'], 'detached HEAD'],
    ]);

    it('surfaces git\'s refusal when a plain folder holds the path', function () {
        writeFixture($this->path, 'notes.txt', "not a worktree\n");

        expect(fn () => Worktree::prepare($this->project, $this->path, WORKTREE_BRANCH, 'main'))
            ->toThrow(RuntimeException::class, 'already exists');

        expect(file_exists($this->path.'/.git'))->toBeFalse()
            ->and(file_get_contents($this->path.'/notes.txt'))->toBe("not a worktree\n");
    });

    it('rejects a branch, path or base starting with a dash before git runs', function (string $path, string $branch, string $base) {
        file_put_contents($this->bin.'/git', "#!/bin/sh\necho \"\$*\" >> '{$this->bin}/git.log'\nexit 1\n");
        chmod($this->bin.'/git', 0o755);

        expect(fn () => Worktree::prepare($this->project, $path, $branch, $base))
            ->toThrow(RuntimeException::class, 'starting with `-`');

        expect(file_exists($this->bin.'/git.log'))->toBeFalse();
    })->with([
        'branch' => ['../Pkg--demo', '--orphan', 'main'],
        'path' => ['-b', WORKTREE_BRANCH, 'main'],
        'base' => ['../Pkg--demo', WORKTREE_BRANCH, '--detach'],
    ]);
})->skipOnWindows();

describe('setup', function () {
    beforeEach(function () {
        Worktree::prepare($this->project, $this->path, WORKTREE_BRANCH, 'main');
    });

    it('copies the local manifest and lock, then installs from them', function () {
        writeFixture($this->project->root, 'composer.local.json', "{\"repositories\": [{\"type\": \"path\", \"url\": \"../Sibling\"}]}\n");
        writeFixture($this->project->root, 'composer.local.lock', "{\"packages\": []}\n");

        [$code, $out, $ran] = Worktree::setup($this->project, $this->path, 'auto');

        expect($code)->toBe(0)
            ->and($out)->toContain('fake composer stdout')
            ->and($ran)->toBe('`COMPOSER=composer.local.json composer install --no-interaction`')
            ->and(file_get_contents($this->path.'/composer.local.json'))->toBe(file_get_contents($this->project->path('composer.local.json')))
            ->and(file_get_contents($this->path.'/composer.local.lock'))->toBe(file_get_contents($this->project->path('composer.local.lock')))
            ->and(composerRuns($this->bin))->toBe([
                ['cwd' => realpath($this->path), 'args' => 'install --no-interaction', 'composer' => 'composer.local.json'],
            ]);
    });

    it('copies no lock when the package has none', function () {
        writeFixture($this->project->root, 'composer.local.json', "{}\n");

        [$code] = Worktree::setup($this->project, $this->path, 'auto');

        expect($code)->toBe(0)
            ->and(is_file($this->path.'/composer.local.json'))->toBeTrue()
            ->and(file_exists($this->path.'/composer.local.lock'))->toBeFalse()
            ->and(composerRuns($this->bin)[0]['composer'])->toBe('composer.local.json');
    });

    it('leaves the worktree\'s own manifest and lock untouched and still installs', function () {
        writeFixture($this->project->root, 'composer.local.json', "{\"package\": true}\n");
        writeFixture($this->project->root, 'composer.local.lock', "{\"package\": true}\n");
        writeFixture($this->path, 'composer.local.json', "{\"worktree\": true}\n");
        writeFixture($this->path, 'composer.local.lock', "{\"worktree\": true}\n");

        [$code] = Worktree::setup($this->project, $this->path, 'auto');

        expect($code)->toBe(0)
            ->and(file_get_contents($this->path.'/composer.local.json'))->toBe("{\"worktree\": true}\n")
            ->and(file_get_contents($this->path.'/composer.local.lock'))->toBe("{\"worktree\": true}\n")
            ->and(composerRuns($this->bin))->toBe([
                ['cwd' => realpath($this->path), 'args' => 'install --no-interaction', 'composer' => 'composer.local.json'],
            ]);
    });

    it('installs plainly without a local manifest, ignoring the caller\'s COMPOSER', function () {
        putenv('COMPOSER=other');

        [$code, , $ran] = Worktree::setup($this->project, $this->path, 'auto');

        expect($code)->toBe(0)
            ->and($ran)->toBe('`composer install --no-interaction`')
            ->and(file_exists($this->path.'/composer.local.json'))->toBeFalse()
            ->and(composerRuns($this->bin))->toBe([
                ['cwd' => realpath($this->path), 'args' => 'install --no-interaction', 'composer' => '<unset>'],
            ]);
    });

    it('runs an explicit setup command as is, without copying manifests', function () {
        writeFixture($this->project->root, 'composer.local.json', "{}\n");

        [$code, , $ran] = Worktree::setup($this->project, $this->path, 'touch setup-ran');

        expect($code)->toBe(0)
            ->and($ran)->toBe('`touch setup-ran`')
            ->and(is_file($this->path.'/setup-ran'))->toBeTrue()
            ->and(file_exists($this->path.'/composer.local.json'))->toBeFalse()
            ->and(composerRuns($this->bin))->toBe([]);
    });

    it('never runs the package\'s local:install', function (bool $manifest) {
        if ($manifest) {
            writeFixture($this->project->root, 'composer.local.json', "{}\n");
        }

        Worktree::setup($this->project, $this->path, 'auto');

        $runs = composerRuns($this->bin);

        expect($runs)->toHaveCount(1)
            ->and($runs[0]['args'])->not->toContain('local:install');
    })->with(['with a local manifest' => true, 'without one' => false]);

    it('returns the failing exit code with the install\'s stderr', function () {
        putenv('FAKE_COMPOSER_EXIT=1');

        [$code, $out] = Worktree::setup($this->project, $this->path, 'auto');

        expect($code)->toBe(1)
            ->and($out)->toContain('fake composer stdout')
            ->and($out)->toContain('fake composer stderr');
    });

    it('reports a failed copy as a failed setup naming the file, without installing', function () {
        writeFixture($this->project->root, 'composer.local.json', "{}\n");
        writeFixture($this->project->root, 'composer.local.lock', "{}\n");
        $lock = $this->project->path('composer.local.lock');
        chmod($lock, 0o000);

        try {
            if (is_readable($lock)) {
                $this->markTestSkipped('An unreadable file is still readable here (running as root).');
            }

            [$code, $out] = Worktree::setup($this->project, $this->path, 'auto');
        } finally {
            chmod($lock, 0o644);
        }

        expect($code)->toBe(1)
            ->and($out)->toContain('composer.local.lock')
            ->and(composerRuns($this->bin))->toBe([]);
    });
})->skipOnWindows();
