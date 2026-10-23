<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Providers;

/**
 * A ticket tracker or code host, described by a markdown adapter whose
 * front matter names it and whose body tells an agent how to run each operation.
 */
final readonly class Provider
{
    /**
     * @param  list<string>  $kinds  tracker, host
     * @param  list<string>  $claims  regexes for refs this provider recognises
     * @param  array<string, mixed>  $requires
     */
    public function __construct(
        public string $name,
        public array $kinds,
        public array $claims,
        public array $requires,
        public string $body,
        public string $path,
    ) {}

    public function is(string $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }

    public function claims(string $ref): bool
    {
        foreach ($this->claims as $pattern) {
            if (self::matches($pattern, $ref)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An invalid pattern in a project adapter counts as no match, not a crash.
     */
    public static function matches(string $pattern, string $subject): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match('~'.str_replace('~', '\~', $pattern).'~', $subject) === 1;
        } finally {
            restore_error_handler();
        }
    }
}
