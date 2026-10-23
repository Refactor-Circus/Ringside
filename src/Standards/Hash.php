<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Standards;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Line-ending-insensitive hashes, so a checkout with CRLF endings on Windows
 * hashes the same as one on macOS or Linux.
 */
final class Hash
{
    public static function contents(string $contents): string
    {
        return substr(hash('xxh128', str_replace("\r\n", "\n", $contents)), 0, 12);
    }

    public static function file(string $file): ?string
    {
        return is_file($file) ? self::contents((string) file_get_contents($file)) : null;
    }

    public static function tree(string $dir): string
    {
        if (! is_dir($dir)) {
            return 'none';
        }

        $entries = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $entries[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir))).':'.self::file($file->getPathname());
            }
        }

        sort($entries);

        return self::contents(implode("\n", $entries));
    }
}
