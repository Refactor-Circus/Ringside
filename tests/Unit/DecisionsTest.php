<?php

declare(strict_types=1);

use RefactorCircus\Ringside\Specs\Decisions;

it('parses decisions and numbers the next one', function () {
    $md = "# Decisions\n\n## D-001: Sync or queued?\n- Choice: queued\n- Rationale: big files\n- Date: 2026-10-24\n\n## D-002: One table or two?\n- Choice: one\n";
    $decisions = new Decisions;

    expect($decisions->parse($md))->toHaveCount(2)
        ->and($decisions->parse($md)[1])->toMatchArray(['id' => 'D-002', 'question' => 'One table or two?', 'choice' => 'one'])
        ->and($decisions->nextId($md))->toBe('D-003')
        ->and($decisions->nextId(''))->toBe('D-001');
});
