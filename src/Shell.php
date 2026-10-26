<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside;

/**
 * Runs a command as an argument list (no shell), returning exit code and output.
 */
final class Shell
{
    /**
     * Runs $command in $cwd. With no $env the child inherits the parent
     * environment; otherwise $env is merged over it. Stdout and stderr are
     * drained together so a child filling either pipe cannot block; the
     * output is stdout, with stderr appended when $withStderr is true.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env  variables to set in the child; an empty value blanks a parent variable
     * @return array{0: int, 1: string}
     */
    public static function run(array $command, string $cwd, array $env = [], bool $withStderr = false): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            $env === [] ? null : self::environment($env),
        );

        if (! is_resource($process)) {
            return [1, ''];
        }

        [$stdout, $stderr] = self::drain($pipes[1], $pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $output = $withStderr ? trim($stdout)."\n".trim($stderr) : $stdout;

        return [proc_close($process), trim($output)];
    }

    public static function gitRemote(string $cwd): ?string
    {
        [$code, $url] = self::run(['git', 'remote', 'get-url', 'origin'], $cwd);

        return $code === 0 && $url !== '' ? $url : null;
    }

    /**
     * The parent environment with $env assigned key by key (array spread
     * would re-index numeric-looking names).
     *
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private static function environment(array $env): array
    {
        $merged = getenv();

        foreach ($env as $name => $value) {
            $merged[$name] = $value;
        }

        return $merged;
    }

    /**
     * Reads both pipes to EOF as data arrives. Where stream_select cannot
     * watch pipes (Windows), it falls back to reading one after the other.
     *
     * @param  resource  $stdout
     * @param  resource  $stderr
     * @return array{0: string, 1: string}
     */
    private static function drain($stdout, $stderr): array
    {
        $open = [1 => $stdout, 2 => $stderr];
        $buffers = [1 => '', 2 => ''];

        foreach ($open as $pipe) {
            stream_set_blocking($pipe, false);
        }

        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, null) === false) {
                foreach ($open as $fd => $pipe) {
                    stream_set_blocking($pipe, true);
                    $buffers[$fd] .= (string) stream_get_contents($pipe);
                }

                break;
            }

            foreach ($open as $fd => $pipe) {
                if (! in_array($pipe, $read, true)) {
                    continue;
                }

                $chunk = fread($pipe, 65536);

                if ($chunk === false || ($chunk === '' && feof($pipe))) {
                    unset($open[$fd]);

                    continue;
                }

                $buffers[$fd] .= $chunk;
            }
        }

        return [$buffers[1], $buffers[2]];
    }
}
