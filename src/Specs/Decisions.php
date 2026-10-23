<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Specs;

/**
 * decisions.md: your answers to architectural questions, append-only.
 *
 *     ## D-001: Store imports as one row per file or per line?
 *     - Choice: per file
 *     - Rationale: ...
 *     - Date: 2026-10-24
 */
final class Decisions
{
    /**
     * @return list<array{id: string, question: string, choice: string, rationale: string, date: string}>
     */
    public function parse(string $markdown): array
    {
        $decisions = [];

        foreach (preg_split('/^(?=## D-\d+)/m', $markdown) ?: [] as $section) {
            if (! preg_match('/^## (D-\d+):\s*(.+)$/m', $section, $head)) {
                continue;
            }

            $field = static fn (string $name): string => preg_match('/^- '.$name.':\s*(.+)$/mi', $section, $m) ? trim($m[1]) : '';

            $decisions[] = [
                'id' => $head[1],
                'question' => trim($head[2]),
                'choice' => $field('Choice'),
                'rationale' => $field('Rationale'),
                'date' => $field('Date'),
            ];
        }

        return $decisions;
    }

    public function nextId(string $markdown): string
    {
        preg_match_all('/^## D-(\d+)/m', $markdown, $m);
        $last = $m[1] === [] ? 0 : max(array_map(intval(...), $m[1]));

        return sprintf('D-%03d', $last + 1);
    }
}
