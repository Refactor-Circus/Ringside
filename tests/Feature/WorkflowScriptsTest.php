<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Shell;

it('passes the workflow simulations (node --test)', function () {
    [$code, $output] = Shell::run(['node', '--test', 'tests/workflows/'], dirname(__DIR__, 2));

    expect($code)->toBe(0, $output);
})->skip(fn (): bool => Shell::run(['node', '--version'], sys_get_temp_dir())[0] !== 0, 'node is not installed');

it('keeps every workflow meta block a pure literal with name and description', function () {
    foreach (glob(dirname(__DIR__, 2).'/resources/workflows/*.js') ?: [] as $file) {
        $source = (string) file_get_contents($file);

        expect($source)->toStartWith('export const meta = {')
            ->and($source)->toContain("name: '".basename($file, '.js')."'", 'description: ')
            ->and($source)->not->toContain('Date.now(', 'Math.random(', 'new Date()');
    }
});
