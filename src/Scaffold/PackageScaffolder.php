<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Scaffold;

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Shell;
use RuntimeException;

/**
 * Creates a new package folder beside the others: from `laravel package` for
 * a Laravel package, from `composer init` for a plain PHP one; then git init
 * with a first commit on the base branch.
 */
final class PackageScaffolder
{
    /** Flags `laravel package` accepts for optional parts of the skeleton. */
    public const array LARAVEL_FEATURES = ['config', 'routes', 'views', 'translations', 'migrations', 'assets', 'commands', 'facade', 'boost-skill'];

    public function __construct(private readonly Project $root) {}

    /**
     * @param  array{type?: ?string, vendor?: ?string, namespace?: ?string, description?: ?string, features?: ?list<string>}  $options
     * @return array{name: string, dir: string, composer: string, namespace: string, type: string, command: list<string>}
     */
    public function plan(string $name, array $options = []): array
    {
        if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            throw new RuntimeException("Package name `{$name}` must be StudlyCase letters and digits, like Ledger or PennantPlus.");
        }

        $config = $this->root->config;
        $type = $options['type'] ?? $config->string('new_package.type');

        if (! in_array($type, ['laravel', 'php'], true)) {
            throw new RuntimeException("Unknown package type `{$type}`: use laravel or php.");
        }

        $vendor = $options['vendor'] ?? $config->nullableString('new_package.vendor') ?? $this->workspaceVendor();
        $namespace = $options['namespace'] ?? $config->nullableString('new_package.namespace') ?? self::studly($vendor);
        $composer = $vendor.'/'.self::kebab($name);
        $features = $options['features'] ?? $config->list('new_package.features');
        $unknown = array_diff($features, self::LARAVEL_FEATURES);

        if ($unknown !== []) {
            throw new RuntimeException('Unknown package features: '.implode(', ', $unknown).'. Use: '.implode(', ', self::LARAVEL_FEATURES).'.');
        }

        [$author, $email] = $this->author();
        $description = $options['description'] ?? "{$name} package.";

        $command = $type === 'laravel'
            ? [
                $config->string('new_package.laravel_bin'), 'package', $name, '--no-interaction',
                '--package-name='.$composer,
                '--package-name-human='.$name,
                '--package-description='.$description,
                '--vendor-namespace='.$namespace,
                '--class-name='.$name,
                '--author-name='.$author,
                '--author-email='.$email,
                ...array_map(static fn (string $feature): string => '--'.$feature, $features),
            ]
            : [
                'composer', 'init', '--no-interaction',
                '--name='.$composer,
                '--description='.$description,
                '--type=library',
                '--license=MIT',
                '--author='.$author.' <'.$email.'>',
                '--autoload=src/',
            ];

        return ['name' => $name, 'dir' => $name, 'composer' => $composer, 'namespace' => $namespace.'\\'.$name, 'type' => $type, 'command' => $command];
    }

    /**
     * @param  array{type?: ?string, vendor?: ?string, namespace?: ?string, description?: ?string, features?: ?list<string>}  $options
     * @return array{name: string, dir: string, composer: string, namespace: string, type: string, command: list<string>}
     */
    public function create(string $name, array $options = []): array
    {
        $plan = $this->plan($name, $options);
        $target = $this->root->path($plan['dir']);

        if (file_exists($target)) {
            throw new RuntimeException("{$plan['dir']}/ already exists.");
        }

        if ($plan['type'] === 'laravel') {
            [$code, $out] = Shell::run($plan['command'], $this->root->root);

            if ($code !== 0 || ! is_file($target.'/composer.json')) {
                throw new RuntimeException("`laravel package` failed. Is the Laravel installer on your PATH (composer global require laravel/installer)?\n{$out}");
            }
        } else {
            mkdir($target.'/src', 0o755, true);
            mkdir($target.'/tests', 0o755, true);
            [$code, $out] = Shell::run($plan['command'], $target);

            if ($code !== 0) {
                throw new RuntimeException("`composer init` failed:\n{$out}");
            }

            file_put_contents($target.'/.gitignore', "/vendor\ncomposer.lock\n.phpunit.cache\n");
        }

        $base = $this->root->config->string('host.base') ?: 'main';

        foreach ([['git', 'init', '-q', '-b', $base], ['git', 'add', '-A'], ['git', 'commit', '-q', '-m', "Scaffold {$plan['composer']}"]] as $step) {
            [$code, $out] = Shell::run($step, $target);

            if ($code !== 0) {
                throw new RuntimeException('`'.implode(' ', $step)."` failed in {$plan['dir']}/:\n{$out}");
            }
        }

        (new WorkspaceConfig($this->root))->addPackage($plan['dir']);

        return $plan;
    }

    /**
     * The vendor most workspace packages already use, so a new package joins them.
     */
    private function workspaceVendor(): string
    {
        $counts = [];

        foreach ($this->root->config->list('workspace.packages') as $package) {
            $manifest = json_decode((string) $this->root->read($package.'/composer.json'), true);
            $composer = is_array($manifest) && is_string($manifest['name'] ?? null) ? $manifest['name'] : '';

            if (str_contains($composer, '/')) {
                $vendor = strstr($composer, '/', true);
                $counts[$vendor] = ($counts[$vendor] ?? 0) + 1;
            }
        }

        arsort($counts);
        $vendor = array_key_first($counts);

        if ($vendor === null) {
            throw new RuntimeException('Set new_package.vendor in ring-master.yml (or pass --vendor); there is no workspace package to take it from.');
        }

        return (string) $vendor;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function author(): array
    {
        [, $name] = Shell::run(['git', 'config', 'user.name'], $this->root->root);
        [, $email] = Shell::run(['git', 'config', 'user.email'], $this->root->root);

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Set git config user.name and user.email first; the package\'s author comes from them.');
        }

        return [$name, $email];
    }

    public static function kebab(string $studly): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $studly));
    }

    public static function studly(string $kebab): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $kebab)));
    }
}
