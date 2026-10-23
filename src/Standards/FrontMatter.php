<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Standards;

use Symfony\Component\Yaml\Yaml;

final class FrontMatter
{
    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    public static function parse(string $contents): array
    {
        if (! preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n?(.*)\z/s', $contents, $matches)) {
            return [[], $contents];
        }

        $meta = Yaml::parse($matches[1]);

        return [is_array($meta) ? $meta : [], $matches[2]];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function write(array $meta, string $body): string
    {
        if ($meta === []) {
            return $body;
        }

        return "---\n".Yaml::dump($meta, 2, 2)."---\n".ltrim($body, "\n");
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function merge(string $contents, array $meta): string
    {
        [$existing, $body] = self::parse($contents);

        return self::write([...$existing, ...$meta], $body);
    }

    public static function firstHeading(string $body): ?string
    {
        return preg_match('/^#\s+(.+)$/m', $body, $matches) ? trim($matches[1]) : null;
    }
}
