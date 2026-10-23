<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside;

/**
 * Runs a command as an argument list (no shell), returning exit code and output.
 */
final class Shell
{
    /**
     * @param  list<string>  $command
     * @return array{0: int, 1: string}
     */
    public static function run(array $command, string $cwd): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);

        if (! is_resource($process)) {
            return [1, ''];
        }

        $output = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), trim($output)];
    }

    public static function gitRemote(string $cwd): ?string
    {
        [$code, $url] = self::run(['git', 'remote', 'get-url', 'origin'], $cwd);

        return $code === 0 && $url !== '' ? $url : null;
    }
}
