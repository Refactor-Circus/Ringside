<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Shell;

/**
 * Runs `php -r $code` through Shell::run from the temp dir.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function runPhp(string $code, array $env = [], bool $withStderr = false): array
{
    return Shell::run([PHP_BINARY, '-r', $code], sys_get_temp_dir(), $env, $withStderr);
}

/**
 * A child that writes $bytes to stderr, then "done" to stdout. It gives up
 * after a fixed 10 second bound and prints "deadlock" instead, so a parent
 * that stops draining stderr fails the test rather than hanging it.
 */
function stderrFlood(int $bytes): string
{
    return <<<PHP
        stream_set_blocking(STDERR, false);
        \$data = str_repeat('e', {$bytes});
        \$deadline = microtime(true) + 10;
        while (\$data !== '' && microtime(true) < \$deadline) {
            \$written = fwrite(STDERR, \$data);
            if (\$written === false || \$written === 0) { usleep(10000); continue; }
            \$data = substr(\$data, \$written);
        }
        echo \$data === '' ? 'done' : 'deadlock: stderr was not drained within 10 seconds';
        PHP;
}

it('inherits the parent environment when no env is passed', function () {
    putenv('RINGSIDE_SHELL_INHERIT=parent');

    try {
        expect(runPhp('echo getenv("RINGSIDE_SHELL_INHERIT");'))->toBe([0, 'parent']);
    } finally {
        putenv('RINGSIDE_SHELL_INHERIT');
    }
});

it('merges the given env over the parent environment', function () {
    putenv('RINGSIDE_SHELL_KEEP=kept');
    putenv('RINGSIDE_SHELL_OVERRIDE=before');

    try {
        [$code, $out] = runPhp(
            'echo getenv("RINGSIDE_SHELL_KEEP"), "|", getenv("RINGSIDE_SHELL_OVERRIDE"), "|", getenv("RINGSIDE_SHELL_ADDED"), "|", getenv("PATH") === false ? "no-path" : "path";',
            ['RINGSIDE_SHELL_OVERRIDE' => 'after', 'RINGSIDE_SHELL_ADDED' => 'added'],
        );

        expect($code)->toBe(0)
            ->and($out)->toBe('kept|after|added|path');
    } finally {
        putenv('RINGSIDE_SHELL_KEEP');
        putenv('RINGSIDE_SHELL_OVERRIDE');
    }
});

it('does not leak a passed env into the parent or later calls', function () {
    runPhp('echo getenv("RINGSIDE_SHELL_LEAK");', ['RINGSIDE_SHELL_LEAK' => 'leaked']);

    expect(getenv('RINGSIDE_SHELL_LEAK'))->toBeFalse()
        ->and(runPhp('echo getenv("RINGSIDE_SHELL_LEAK") === false ? "unset" : "leaked";'))->toBe([0, 'unset']);
});

it('lets an empty COMPOSER override the caller\'s COMPOSER', function () {
    $previous = getenv('COMPOSER');
    putenv('COMPOSER=other.json');

    try {
        expect(runPhp('echo getenv("COMPOSER") ?: "<unset>";'))->toBe([0, 'other.json'])
            ->and(runPhp('echo getenv("COMPOSER") ?: "<unset>";', ['COMPOSER' => '']))->toBe([0, '<unset>']);
    } finally {
        putenv($previous === false ? 'COMPOSER' : "COMPOSER={$previous}");
    }
});

it('returns stdout only when stderr is not requested', function () {
    expect(runPhp('fwrite(STDOUT, "out\n"); fwrite(STDERR, "err\n"); exit(3);'))->toBe([3, 'out']);
});

it('appends stderr to the output when requested', function () {
    expect(runPhp('fwrite(STDOUT, "out\n"); fwrite(STDERR, "err\n"); exit(3);', withStderr: true))->toBe([3, "out\nerr"])
        ->and(runPhp('fwrite(STDERR, "only err\n");', withStderr: true))->toBe([0, 'only err']);
});

it('drains a child writing more than 64 KB to stderr without deadlocking', function (bool $withStderr) {
    [$code, $out] = runPhp(stderrFlood(256 * 1024), withStderr: $withStderr);

    expect($code)->toBe(0)
        ->and(str_starts_with($out, 'done'))->toBeTrue("Shell::run deadlocked: {$out}");

    if ($withStderr) {
        expect($out)->toBe("done\n".str_repeat('e', 256 * 1024));
    }
})->with(['stderr discarded' => false, 'stderr captured' => true])->skipOnWindows();
