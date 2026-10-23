<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Specs;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The open architectural questions of one spec, kept in its questions.yml so
 * that both /ring-master-decide and the RingMaster app can read them:
 *
 *     - id: Q-001
 *       question: One row per file or per line?
 *       options: [Per file, Per line]
 *       recommendation: Per line
 *       evidence: src/Import.php:40
 *       source: build            # build | spec-review
 */
final class Questions
{
    public const string FILE = 'questions.yml';

    /**
     * @return list<array{id: string, question: string, options: list<string>, recommendation: ?string, evidence: ?string, source: string}>
     */
    public function parse(string $yaml): array
    {
        try {
            $entries = Yaml::parse($yaml);
        } catch (ParseException) {
            return [];
        }

        $questions = [];

        foreach (is_array($entries) ? array_values($entries) : [] as $i => $entry) {
            if (! is_array($entry) || ! is_string($entry['question'] ?? null) || trim($entry['question']) === '') {
                continue;
            }

            $options = is_array($entry['options'] ?? null) ? $entry['options'] : [];

            $questions[] = [
                'id' => is_string($entry['id'] ?? null) ? $entry['id'] : sprintf('Q-%03d', $i + 1),
                'question' => trim($entry['question']),
                'options' => array_values(array_map(strval(...), array_filter($options, is_scalar(...)))),
                'recommendation' => is_scalar($entry['recommendation'] ?? null) ? (string) $entry['recommendation'] : null,
                'evidence' => is_scalar($entry['evidence'] ?? null) ? (string) $entry['evidence'] : null,
                'source' => is_string($entry['source'] ?? null) ? $entry['source'] : 'build',
            ];
        }

        return $questions;
    }
}
