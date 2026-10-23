<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside;

use Symfony\Component\Yaml\Yaml;

/**
 * ring-master.yml merged over the defaults. Every key is optional.
 */
final readonly class Config
{
    public const string FILE = 'ring-master.yml';

    /** @var list<string> */
    public const array SEVERITIES = ['architectural', 'blocker', 'major', 'minor', 'nit'];

    /** @var list<string> */
    public const array ROLES = ['prepare', 'implementer', 'reworker', 'gate', 'reviewer', 'triage', 'approver', 'critic', 'analyst', 'discoverer'];

    /** @var list<string> */
    public const array REVIEWERS = ['code', 'security', 'standards', 'compat', 'ui', 'ux'];

    /**
     * @param  array<string, mixed>  $values
     */
    private function __construct(public array $values, public string $raw) {}

    public static function load(string $root): self
    {
        $file = $root.'/'.self::FILE;
        $raw = is_file($file) ? (string) file_get_contents($file) : '';
        $parsed = $raw === '' ? [] : Yaml::parse($raw);

        return new self(self::merge(self::defaults(), is_array($parsed) ? $parsed : []), $raw);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'path' => 'docs/agent',
            'spec_id_format' => '{date}-{time}-{slug}',
            'targets' => ['claude'],
            'generated' => ['commit' => false],
            'commands' => [
                'test' => 'vendor/bin/pest',
                'verify' => 'vendor/bin/pest',
                'format' => 'vendor/bin/pint',
                'format_check' => 'vendor/bin/pint --test',
                'browser_test' => null,
            ],
            'standards' => [
                'global' => [],
                'exclude' => [],
            ],
            'tickets' => [
                'default' => 'auto',
                'providers' => [],
            ],
            'host' => [
                'provider' => 'auto',
                'base' => 'main',
                'draft_pr' => false,
            ],
            'stages' => [
                'ticket' => true,
                'shape' => true,
                'spec_review' => ['enabled' => true, 'critics' => ['scope', 'risk', 'testability'], 'rounds' => 1],
                'tasks' => true,
                'factory' => true,
                'post_mortem' => 'on_merge',
            ],
            'roles' => [
                'prepare' => ['model' => 'haiku', 'effort' => 'low'],
                'implementer' => ['effort' => 'high', 'max_turns' => 40],
                'reworker' => ['effort' => 'high', 'max_turns' => 40],
                'gate' => ['model' => 'sonnet', 'effort' => 'low'],
                'reviewer' => ['model' => 'sonnet', 'effort' => 'medium'],
                'triage' => ['model' => 'haiku', 'effort' => 'low', 'enabled' => false],
                'approver' => ['model' => 'sonnet', 'enabled' => false],
                'critic' => ['model' => 'sonnet', 'effort' => 'medium'],
                'analyst' => ['model' => 'sonnet', 'effort' => 'medium'],
                'discoverer' => ['effort' => 'high'],
            ],
            'review' => [
                'reviewers' => ['code', 'security', 'standards', 'compat'],
                'rounds' => ['max' => 3, 'min' => 1],
                'rework_threshold' => 'minor',
                'auto_fix' => ['blocker', 'major', 'minor', 'nit'],
                'stop_when_no_progress' => 2,
                'gate' => ['run_first' => true, 'max_consecutive_red' => 3, 'criteria_mapping' => true],
            ],
            'escalation' => [
                'architectural_paths' => ['composer.json', 'config/**', 'database/migrations/**', '**/Contracts/**', '.github/**'],
                'fix_others_first' => true,
            ],
            'build' => [
                'parallel' => false,
                'max_concurrency' => 2,
                'worktree' => [
                    'mode' => 'per-job',
                    'path' => '../{package}--{spec}',
                    'setup' => 'composer install --no-interaction',
                    'cleanup' => 'on_close',
                ],
            ],
            'discovery' => [
                'enabled' => true,
                'sources' => ['feedback', 'findings', 'decisions', 'pr_comments'],
                'project' => ['mode' => 'ask'],
                'global' => ['mode' => 'queue', 'repo' => null, 'min_evidence' => 1],
            ],
            'skills' => true,
            'hooks' => [
                'post_edit_format' => ['enabled' => true, 'command' => 'vendor/bin/pint {file}'],
                'session_start_summary' => true,
            ],
            'pint' => ['allow_diff' => false],
            'workspace' => ['packages' => [], 'jobs_dir' => '.ring-master/jobs'],
            'new_package' => [
                'type' => 'laravel',                 // laravel: `laravel package`; php: `composer init`
                'vendor' => null,                    // composer vendor, e.g. acme; default: the workspace's own
                'namespace' => null,                 // root namespace prefix, e.g. Acme; default: from vendor
                'features' => ['config'],            // laravel package flags: config, routes, views, translations, migrations, assets, commands, facade, boost-skill
                'laravel_bin' => 'laravel',
                'php' => '^8.5',                     // the PHP constraint every new package requires
            ],
        ];
    }

    public function agentDir(): string
    {
        return trim($this->string('path'), '/');
    }

    public function string(string $key): string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : '';
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->get($key);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function int(string $key): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<string>
     */
    public function list(string $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? array_values(array_map(strval(...), array_filter($value, is_scalar(...)))) : [];
    }

    /**
     * Configured global standards packages, in order: `standards.global` entries
     * are either a package name or `{package, strict}`.
     *
     * @return list<array{package: string, strict: bool}>
     */
    public function globalStandards(): array
    {
        $entries = $this->get('standards.global');
        $packages = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_string($entry)) {
                $packages[] = ['package' => $entry, 'strict' => true];
            } elseif (is_array($entry) && is_string($entry['package'] ?? null)) {
                $packages[] = ['package' => $entry['package'], 'strict' => (bool) ($entry['strict'] ?? true)];
            }
        }

        return $packages;
    }

    /**
     * Configured reviewers as `{name, model?, effort?}`, merged over the reviewer role.
     *
     * @return list<array<string, mixed>>
     */
    public function reviewers(): array
    {
        $role = $this->role('reviewer');
        $reviewers = [];

        foreach (is_array($this->get('review.reviewers')) ? (array) $this->get('review.reviewers') : [] as $entry) {
            $entry = is_string($entry) ? ['name' => $entry] : (is_array($entry) ? $entry : []);

            if (is_string($entry['name'] ?? null)) {
                $reviewers[] = [...$role, ...$entry];
            }
        }

        return $reviewers;
    }

    /**
     * @return array<string, mixed>
     */
    public function role(string $name): array
    {
        $role = $this->get('roles.'.$name);

        return is_array($role) ? $role : [];
    }

    /**
     * Dot-path lookup: `review.rounds.max`.
     */
    public function get(string $key): mixed
    {
        $value = $this->values;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Problems with the configuration; empty means it is valid.
     *
     * @return list<string>
     */
    public function validate(): array
    {
        $problems = [];
        $parsed = $this->raw === '' ? [] : Yaml::parse($this->raw);

        foreach (array_keys(is_array($parsed) ? $parsed : []) as $key) {
            if ($key === 'dashboard') {
                $problems[] = 'ring-master.yml: `dashboard` was removed in 0.2 (the RingMaster app shows the board); delete it.';
            } elseif (! array_key_exists((string) $key, self::defaults())) {
                $problems[] = "ring-master.yml: unknown key `{$key}`.";
            }
        }

        foreach ($this->list('review.auto_fix') as $severity) {
            if ($severity === 'architectural') {
                $problems[] = 'ring-master.yml: `review.auto_fix` cannot include `architectural`; architectural decisions are always yours.';
            } elseif (! in_array($severity, self::SEVERITIES, true)) {
                $problems[] = "ring-master.yml: unknown severity `{$severity}` in `review.auto_fix`.";
            }
        }

        $threshold = $this->string('review.rework_threshold');

        if (! in_array($threshold, array_slice(self::SEVERITIES, 1), true)) {
            $problems[] = "ring-master.yml: `review.rework_threshold` must be one of blocker, major, minor, nit (got `{$threshold}`).";
        }

        $max = $this->int('review.rounds.max');
        $min = $this->int('review.rounds.min');

        if ($max < 1 || $min < 0 || $min > $max) {
            $problems[] = "ring-master.yml: `review.rounds` needs max >= 1 and 0 <= min <= max (got min {$min}, max {$max}).";
        }

        foreach (array_keys(is_array($this->get('roles')) ? (array) $this->get('roles') : []) as $role) {
            if (! in_array((string) $role, self::ROLES, true)) {
                $problems[] = "ring-master.yml: unknown role `{$role}`.";
            }
        }

        if (! in_array($this->string('stages.post_mortem'), ['on_merge', 'on_pr', 'manual', 'off'], true)) {
            $problems[] = 'ring-master.yml: `stages.post_mortem` must be on_merge, on_pr, manual or off.';
        }

        $format = $this->nullableString('hooks.post_edit_format.command');

        if ($format !== null && str_contains($format, "'")) {
            $problems[] = 'ring-master.yml: `hooks.post_edit_format.command` cannot contain single quotes.';
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<mixed>  $override
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $key = (string) $key;
            $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($base[$key])
                ? self::merge($base[$key], $value)
                : $value;
        }

        return $base;
    }
}
