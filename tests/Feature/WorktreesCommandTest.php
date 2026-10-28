<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Shell;

// Wiring only: what Worktree::prepare and Worktree::setup do is proven in
// WorktreeTest. These tests drive `bin/ringside worktrees` in a subprocess.

const WORKTREES_SPEC = '2026-10-25-1349-demo';
const WORKTREES_BRANCH = 'ring-master/'.WORKTREES_SPEC;

/**
 * A single package (no workspace.packages) holding one spec, committed as the
 * first commit of its own git repo on `main`.
 */
function worktreesPackage(string $config = ''): Project
{
    $project = fixtureProject([
        'ring-master.yml' => $config,
        'docs/agent/specs/'.WORKTREES_SPEC.'/meta.yml' => 'id: '.WORKTREES_SPEC."\ntitle: Demo\nstatus: approved\n",
        'docs/agent/specs/'.WORKTREES_SPEC.'/tasks.md' => "## Group 1: Demo\n- [ ] 1.1 Demo\n",
    ]);

    foreach ([['init', '-q', '-b', 'main'], ['config', 'user.email', 't@t'], ['config', 'user.name', 't'], ['add', '.'], ['commit', '-q', '-m', 'init']] as $args) {
        Shell::run(['git', ...$args], $project->root);
    }

    return $project;
}

/**
 * Where `job.worktree` (`../{package}--{spec}`) puts the package's worktree.
 */
function worktreesPath(Project $project): string
{
    return dirname((string) realpath($project->root)).'/'.basename($project->root).'--'.WORKTREES_SPEC;
}

/**
 * A temp bin dir with a fake `composer` that appends `args|COMPOSER` to
 * composer.log there and exits with FAKE_COMPOSER_EXIT (default 0), writing
 * a stderr marker when it fails.
 */
function worktreesFakeComposer(): string
{
    $bin = sys_get_temp_dir().'/ring-master-bin-'.bin2hex(random_bytes(6));
    mkdir($bin, 0o755, true);
    file_put_contents($bin.'/composer', <<<SH
        #!/bin/sh
        printf '%s|%s\\n' "\$*" "\${COMPOSER:-<unset>}" >> '{$bin}/composer.log'
        code="\${FAKE_COMPOSER_EXIT:-0}"
        if [ "\$code" != 0 ]; then echo 'fake composer stderr' >&2; fi
        exit "\$code"
        SH);
    chmod($bin.'/composer', 0o755);

    return $bin;
}

/**
 * Runs `bin/ringside worktrees ...` from $cwd with the fake composer first on
 * PATH; returns the exit code and stdout plus stderr.
 *
 * @param  list<string>  $args
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function ringsideWorktrees(string $cwd, string $bin, array $args, array $env = []): array
{
    $env['PATH'] = $bin.PATH_SEPARATOR.getenv('PATH');

    return Shell::run([PHP_BINARY, dirname(__DIR__, 2).'/bin/ringside', 'worktrees', ...$args], $cwd, $env, true);
}

beforeEach(function () {
    $this->bin = worktreesFakeComposer();
});

describe('a single package', function () {
    beforeEach(function () {
        $this->project = worktreesPackage();
        $this->path = worktreesPath($this->project);
    });

    it('creates the job worktree and prints its path and branch', function () {
        [$code, $out] = ringsideWorktrees($this->project->root, $this->bin, ['demo']);

        expect($code)->toBe(0)
            ->and($out)->toBe("{$this->path} on ".WORKTREES_BRANCH)
            ->and(Shell::run(['git', 'branch', '--show-current'], $this->path)[1])->toBe(WORKTREES_BRANCH)
            ->and(file_exists($this->bin.'/composer.log'))->toBeFalse();
    });

    it('sets the worktree up with --setup and says how', function () {
        [$code, $out] = ringsideWorktrees($this->project->root, $this->bin, ['demo', '--setup']);

        expect($code)->toBe(0)
            ->and($out)->toBe("{$this->path} on ".WORKTREES_BRANCH.', set up with `composer install --no-interaction`')
            ->and(file_exists($this->bin.'/composer.log'))->toBeTrue();
    });

    it('reuses the worktree on a rerun', function () {
        ringsideWorktrees($this->project->root, $this->bin, ['demo']);

        [$code, $out] = ringsideWorktrees($this->project->root, $this->bin, ['demo', '--setup']);

        expect($code)->toBe(0)
            ->and($out)->toStartWith("{$this->path} on ".WORKTREES_BRANCH);
    });

    it('fails with the message when the worktree is on another branch', function () {
        ringsideWorktrees($this->project->root, $this->bin, ['demo']);
        Shell::run(['git', 'checkout', '-q', '-b', 'other'], $this->path);

        [$code, $out] = ringsideWorktrees($this->project->root, $this->bin, ['demo']);

        expect($code)->toBe(1)
            ->and($out)->toContain('`other`')->toContain(WORKTREES_BRANCH);
    });

    it('fails with git\'s output when a plain folder holds the path', function () {
        writeFixture($this->path, 'notes.txt', "not a worktree\n");

        [$code, $out] = ringsideWorktrees($this->project->root, $this->bin, ['demo']);

        expect($code)->toBe(1)
            ->and($out)->toContain('already exists');
    });

    it('fails with the command and its output when setup fails', function () {
        [$code, $out] = ringsideWorktrees($this->project->root, $this->bin, ['demo', '--setup'], ['FAKE_COMPOSER_EXIT' => '1']);

        expect($code)->toBe(1)
            ->and($out)->toContain('`composer install --no-interaction` failed')
            ->and($out)->toContain('fake composer stderr')
            ->and($out)->not->toContain('set up with');
    });

    it('prints the failure as an error', function () {
        [, $out] = ringsideWorktrees($this->project->root, $this->bin, ['demo', '--setup', '--ansi'], ['FAKE_COMPOSER_EXIT' => '1']);

        // Symfony's <error> style: white on red.
        expect($out)->toContain("\e[37;41m`composer install --no-interaction` failed");
    });

    it('refuses a worktree mode other than per-job', function () {
        $project = withConfig($this->project, "build:\n  worktree:\n    mode: none\n");

        [$code, $out] = ringsideWorktrees($project->root, $this->bin, ['demo']);

        expect($code)->toBe(1)
            ->and($out)->toContain('does not use a worktree')
            ->and(file_exists($this->path))->toBeFalse();
    });

    it('refuses --remove', function () {
        [$code, $out] = ringsideWorktrees($this->project->root, $this->bin, ['demo', '--remove']);

        expect($code)->toBe(1)
            ->and($out)->toContain('--remove is not supported');
    });

    it('fails naming the key when build.worktree.setup is not a string', function () {
        $project = withConfig($this->project, "build:\n  worktree:\n    setup: [composer, install]\n");

        [$code, $out] = ringsideWorktrees($project->root, $this->bin, ['demo', '--setup']);

        expect($code)->toBe(1)
            ->and($out)->toContain('build.worktree.setup')
            ->and(file_exists($this->path))->toBeFalse();
    });
})->skipOnWindows();

describe('a workspace', function () {
    it('still builds the job folder and sets each package up its own way, ignoring auto', function () {
        $root = fixtureProject([
            'ring-master.yml' => "workspace:\n  packages: [Keystone, Atrium]\n",
            'docs/agent/specs/'.WORKTREES_SPEC.'/meta.yml' => 'id: '.WORKTREES_SPEC."\ntitle: Demo\nstatus: approved\n",
            'docs/agent/specs/'.WORKTREES_SPEC.'/tasks.md' => "## Group 1: Contract\npackage: Keystone\n- [ ] 1.1 Contract\n## Group 2: Screen\npackage: Atrium\ndepends on: 1\n- [ ] 2.1 Screen\n",
        ])->root;
        packageRepo($root, 'Keystone');
        packageRepo($root, 'Atrium', ['acme/keystone' => '*'], ['local:install' => 'x']);
        writeFixture($root, 'Keystone/composer.local.json', "{}\n");
        $dir = '.ring-master/jobs/'.WORKTREES_SPEC;

        // A caller's COMPOSER would reach the workspace install; clear it so the log shows only what ringside set.
        [$code, $out] = ringsideWorktrees($root, $this->bin, ['demo', '--setup'], ['COMPOSER' => '']);

        expect($code)->toBe(0)
            ->and($out)->toBe(implode("\n", [
                "Keystone: {$dir}/Keystone on ".WORKTREES_BRANCH.', set up with `composer install --no-interaction`',
                "Atrium: {$dir}/Atrium on ".WORKTREES_BRANCH.', set up with `composer local:install --no-interaction`',
                'Linked: none',
            ]))
            ->and(file($this->bin.'/composer.log', FILE_IGNORE_NEW_LINES))->toBe([
                'install --no-interaction|<unset>',
                'local:install --no-interaction|<unset>',
            ])
            ->and(file_exists("{$root}/{$dir}/Keystone/composer.local.json"))->toBeFalse();
    });
})->skipOnWindows();

describe('variants and replays', function () {
    beforeEach(function () {
        $this->project = worktreesPackage();
        $this->path = worktreesPath($this->project);
    });

    it('makes a worktree per variant beside the job worktree, from the job branch, and removes them with their branches', function () {
        [$code, $out] = ringsideWorktrees($this->project->root, $this->bin, ['demo', '--variants=2']);

        expect($code)->toBe(0)
            ->and($out)->toBe("{$this->path}--v1 on ".WORKTREES_BRANCH."--v1\n{$this->path}--v2 on ".WORKTREES_BRANCH.'--v2')
            ->and(Shell::run(['git', 'branch', '--show-current'], $this->path.'--v2')[1])->toBe(WORKTREES_BRANCH.'--v2');

        [$code, $out] = ringsideWorktrees($this->project->root, $this->bin, ['demo', '--remove-variants']);

        expect($code)->toBe(0)
            ->and($out)->toContain('Deleted branch '.WORKTREES_BRANCH.'--v1')
            ->and(is_dir($this->path.'--v1'))->toBeFalse()
            ->and(is_dir($this->path))->toBeTrue()
            ->and(Shell::run(['git', 'branch', '--list', WORKTREES_BRANCH.'--v*'], $this->project->root)[1])->toBe('');
    });

    it('refuses a variant count outside 2 to 4', function (string $count) {
        [$code, $out] = ringsideWorktrees($this->project->root, $this->bin, ['demo', "--variants={$count}"]);

        expect($code)->toBe(1)->and($out)->toContain('--variants needs a number from 2 to 4');
    })->with(['1', '5', 'many']);

    it('replays on its own worktree and branch from the given commit, never the job\'s, and removes it', function () {
        Shell::run(['git', 'commit', '-q', '--allow-empty', '-m', 'later'], $this->project->root);
        [, $first] = Shell::run(['git', 'rev-parse', 'HEAD~1'], $this->project->root);

        [$code, $out] = ringsideWorktrees($this->project->root, $this->bin, ['demo', '--replay', '--from='.$first]);

        expect($code)->toBe(0)
            ->and($out)->toBe("{$this->path}--replay on ".WORKTREES_BRANCH.'--replay')
            ->and(Shell::run(['git', 'rev-parse', 'HEAD'], $this->path.'--replay')[1])->toBe($first)
            ->and(is_dir($this->path))->toBeFalse();

        ringsideWorktrees($this->project->root, $this->bin, ['demo', '--replay', '--variants=2']);
        [$code, $out] = ringsideWorktrees($this->project->root, $this->bin, ['demo', '--remove-replay']);

        expect($code)->toBe(0)
            ->and(is_dir($this->path.'--replay'))->toBeFalse()
            ->and(is_dir($this->path.'--replay--v1'))->toBeFalse()
            ->and(Shell::run(['git', 'branch', '--list', WORKTREES_BRANCH.'--replay*'], $this->project->root)[1])->toBe('');
    });

    it('refuses variants and replays in a workspace', function () {
        $root = fixtureProject([
            'ring-master.yml' => "workspace:\n  packages: [Keystone]\n",
            'docs/agent/specs/'.WORKTREES_SPEC.'/meta.yml' => 'id: '.WORKTREES_SPEC."\ntitle: Demo\nstatus: approved\n",
            'docs/agent/specs/'.WORKTREES_SPEC.'/tasks.md' => "## Group 1: Contract\npackage: Keystone\n- [ ] 1.1 Contract\n",
        ])->root;
        packageRepo($root, 'Keystone');

        [$code, $out] = ringsideWorktrees($root, $this->bin, ['demo', '--replay']);

        expect($code)->toBe(1)->and($out)->toContain('a workspace job builds once');
    });
})->skipOnWindows();
