<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Specs\TaskParser;

$tasks = <<<'MD'
# Tasks: Import

## Group 1: Data
depends on: none
files: src/Data/ImportData.php, tests/Unit/ImportDataTest.php
criteria: 1

- [x] 1.1 Write tests
- [x] 1.2 Implement ImportData

## Group 2: Importer
depends on: 1
files: src/Importer.php
criteria: 2, 3

- [ ] 2.1 Write tests
- [ ] 2.2 Implement

## Group 3: Command
depends on: 1
files: src/Importer.php

- [ ] 3.1 Implement command
MD;

it('reads groups, dependencies, files, criteria and task state', function () use ($tasks) {
    $groups = (new TaskParser)->groups($tasks);

    expect($groups)->toHaveCount(3)
        ->and($groups[0]['done'])->toBeTrue()
        ->and($groups[1]['depends_on'])->toBe([1])
        ->and($groups[1]['criteria'])->toBe([2, 3])
        ->and($groups[1]['files'])->toBe(['src/Importer.php'])
        ->and($groups[1]['tasks'][0])->toBe(['id' => '2.1', 'text' => 'Write tests', 'done' => false]);
});

it('orders unfinished groups into waves and skips finished ones', function () use ($tasks) {
    $parser = new TaskParser;
    $waves = $parser->waves($parser->groups($tasks));

    expect(array_map(static fn (array $wave): array => array_column($wave, 'n'), $waves))->toBe([[2, 3]])
        ->and($parser->overlaps($waves))->toBe(['Groups 2 and 3 both touch src/Importer.php; they will not run in parallel.']);
});

it('rejects dependency cycles', function () {
    $parser = new TaskParser;
    $parser->waves($parser->groups("## Group 1: A\ndepends on: 2\n- [ ] 1.1 x\n## Group 2: B\ndepends on: 1\n- [ ] 2.1 y\n"));
})->throws(RuntimeException::class, 'dependency cycle');

it('rejects a dependency on a group that does not exist', function () {
    $parser = new TaskParser;
    $parser->waves($parser->groups("## Group 1: A\ndepends on: 7\n- [ ] 1.1 x\n"));
})->throws(RuntimeException::class, 'group 7');
