<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Shell;

it('passes the workflow simulations (node --test)', function () {
    // Node 22+ reads a directory argument as a module, so name each test file.
    $files = glob(dirname(__DIR__).'/workflows/*.test.mjs') ?: [];
    [$code, $output] = Shell::run(['node', '--test', ...$files], dirname(__DIR__, 2));

    expect($files)->not->toBeEmpty()
        ->and($code)->toBe(0, $output);
})->skip(fn (): bool => Shell::run(['node', '--version'], sys_get_temp_dir())[0] !== 0, 'node is not installed');

it('describes the Prepare preflight in the factory skill', function () {
    $doc = (string) file_get_contents(dirname(__DIR__, 2).'/resources/skills/workflow/factory.md');
    $preflight = explode("\n\n**", explode('**Preflight**', $doc, 2)[1] ?? '', 2)[0];

    expect($doc)->toContain('**Preflight**')
        ->and($preflight)->toContain('`dirty`', '`remote`', '`check_applies`', 'stage `prepare`')
        ->and($preflight)->toContain('**Dirty main**', 'Commit or stash these main changes before the build?')
        ->and($preflight)->toContain('**No remote**', 'No origin remote found. What is the remote URL for the PR?')
        ->and($preflight)->toContain('needs-decision', 'drop `ringside check`');
});

it('keeps every workflow meta block a pure literal with name and description', function () {
    foreach (glob(dirname(__DIR__, 2).'/resources/workflows/*.js') ?: [] as $file) {
        $source = (string) file_get_contents($file);

        expect($source)->toStartWith('export const meta = {')
            ->and($source)->toContain("name: '".basename($file, '.js')."'", 'description: ')
            ->and($source)->not->toContain('Date.now(', 'Math.random(', 'new Date()');
    }
});
