<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Providers;

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Render\Template;
use RefactorCircus\Ringside\Shell;
use RefactorCircus\Ringside\Standards\FrontMatter;

/**
 * The built-in adapters in resources/providers plus any the project adds in
 * docs/agent/providers; a project adapter with the same name wins.
 */
final class ProviderRegistry
{
    /** @var array<string, Provider>|null */
    private ?array $providers = null;

    public function __construct(private readonly Project $project) {}

    /**
     * @return array<string, Provider>
     */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [];

        foreach ([Template::resources('providers'), $this->project->path($this->project->agentPath('providers'))] as $dir) {
            foreach (glob($dir.'/*.md') ?: [] as $file) {
                $provider = self::load($file);

                if ($provider !== null) {
                    $providers[$provider->name] = $provider;
                }
            }
        }

        ksort($providers);

        return $this->providers = $providers;
    }

    public function get(string $name): ?Provider
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Trackers configured under `tickets.providers`, default first.
     *
     * @return list<string>
     */
    public function trackers(): array
    {
        $configured = $this->project->config->get('tickets.providers');
        $names = array_map(strval(...), array_keys(is_array($configured) ? $configured : []));
        $default = $this->defaultTracker();

        return array_values(array_unique(array_filter([$default, ...$names], static fn (?string $name): bool => $name !== null && $name !== 'none')));
    }

    public function defaultTracker(): ?string
    {
        $default = $this->project->config->string('tickets.default');

        if ($default !== 'auto') {
            return $default === 'none' ? null : $default;
        }

        return match ($this->host()) {
            'github' => 'github',
            'bitbucket' => 'jira',
            default => 'file',
        };
    }

    public function host(): ?string
    {
        $host = $this->project->config->string('host.provider');

        return $host === 'auto' ? self::hostFromRemote(Shell::gitRemote($this->project->root)) : ($host === '' ? null : $host);
    }

    public static function hostFromRemote(?string $remote): ?string
    {
        return match (true) {
            $remote === null => null,
            str_contains($remote, 'github.com') => 'github',
            str_contains($remote, 'bitbucket.org') => 'bitbucket',
            default => null,
        };
    }

    /**
     * Classifies `/ring-master-ship` input: a ticket ref one of the configured
     * trackers claims (default tracker first), or free text, which is an idea.
     *
     * @return array{kind: string, provider: ?string, ref: string}
     */
    public function resolve(string $input): array
    {
        $input = trim($input);

        foreach ($this->trackers() as $name) {
            $provider = $this->get($name);

            if ($provider !== null && ! str_contains($input, ' ') && $provider->claims($input)) {
                return ['kind' => 'ticket', 'provider' => $name, 'ref' => $input];
            }
        }

        return ['kind' => 'idea', 'provider' => null, 'ref' => $input];
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->trackers() as $name) {
            if (! $this->get($name)?->is('tracker')) {
                $problems[] = "No tracker adapter named `{$name}` (tickets.providers).";
            }
        }

        $host = $this->host();

        if ($host !== null && ! $this->get($host)?->is('host')) {
            $problems[] = "No host adapter named `{$host}` (host.provider).";
        }

        return $problems;
    }

    private static function load(string $file): ?Provider
    {
        [$meta, $body] = FrontMatter::parse((string) file_get_contents($file));

        if (! is_string($meta['name'] ?? null)) {
            return null;
        }

        $strings = static fn (mixed $value): array => is_array($value) ? array_values(array_map(strval(...), array_filter($value, is_scalar(...)))) : [];

        return new Provider(
            name: $meta['name'],
            kinds: $strings($meta['kind'] ?? []),
            claims: $strings($meta['claims'] ?? []),
            requires: is_array($meta['requires'] ?? null) ? $meta['requires'] : [],
            body: trim($body),
            path: $file,
        );
    }
}
