<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Specs;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The open architectural questions of one spec, kept in its questions.yml (`[]`
 * once all are answered) so
 * that both /ring-master-decide and the RingMaster app can read them:
 *
 *     - id: Q-001
 *       question: One row per file or per line?
 *       options: [Per file, Per line]
 *       recommendation: Per line
 *       evidence: src/Import.php:40
 *       source: build            # the stage to return to: ticket | shape | spec-review | approval | tasks | build
 *
 * A file that is not valid YAML reads as one question saying so, so the job
 * never waits on questions nobody can see.
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
        } catch (ParseException $e) {
            return [[
                'id' => 'Q-YAML',
                'question' => "This job's questions.yml is not valid YAML ({$e->getMessage()}), so its questions cannot be shown. Answer what to do, or fix the file and rescan.",
                'options' => [],
                'recommendation' => null,
                'evidence' => mb_substr($yaml, 0, 2000),
                'source' => 'build',
            ]];
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
