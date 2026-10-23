<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Specs;

final readonly class Spec
{
    /**
     * @param  array<string, string>  $standards
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $status,
        public array $standards,
        public int $openTasks,
        public int $doneTasks,
    ) {}
}
