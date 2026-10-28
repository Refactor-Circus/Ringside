<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Render;

use RefactorCircus\Ringside\InstalledPackage;
use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Providers\ProviderRegistry;
use RefactorCircus\Ringside\Standards\StandardsResolver;
use RuntimeException;

final class Template
{
    public static function resources(string $relative = ''): string
    {
        return dirname(__DIR__, 2).'/resources'.($relative === '' ? '' : '/'.$relative);
    }

    /**
     * @param  array<string, string>  $vars
     */
    public static function render(string $relative, array $vars): string
    {
        $file = self::resources($relative);

        if (! is_file($file)) {
            throw new RuntimeException("Missing ringside template {$relative}.");
        }

        return self::fill((string) file_get_contents($file), $vars);
    }

    /**
     * @param  array<string, string>  $vars
     */
    public static function fill(string $text, array $vars): string
    {
        return strtr($text, array_combine(
            array_map(static fn (string $key): string => '{{'.$key.'}}', array_keys($vars)),
            array_values($vars),
        ));
    }

    /**
     * The RingMaster app's folder from `board.app`, with a leading "~"
     * expanded; null when no app is configured.
     */
    public static function boardApp(Project $project): ?string
    {
        $path = $project->config->nullableString('board.app');

        if ($path === null || trim($path) === '') {
            return null;
        }

        if ($path === '~' || str_starts_with($path, '~/')) {
            $home = getenv('HOME');

            if (! is_string($home) || $home === '') {
                $entry = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
                $home = is_array($entry) ? $entry['dir'] : '';
            }

            $path = $home === '' ? $path : rtrim($home, '/').substr($path, 1);
        }

        return rtrim(realpath($path) ?: $path, '/');
    }

    /**
     * @return array<string, string>
     */
    public static function vars(Project $project): array
    {
        $config = $project->config;
        $manifest = json_decode((string) $project->read('composer.json'), true);
        $providers = new ProviderRegistry($project);
        $yes = static fn (bool $on): string => $on ? 'on' : 'off';
        $globals = array_map(static fn (array $entry): string => '`'.$entry['package'].'`', $config->globalStandards());

        $base = [
            'board_app' => self::boardApp($project) ?? '<path to RingMaster>',
            'version' => self::version($project),
            'agent_dir' => $project->agentPath(),
            'standards_dir' => $project->agentPath('standards'),
            'specs_dir' => $project->agentPath('specs'),
            'product_dir' => $project->agentPath('product'),
            'index_path' => $project->agentPath('standards.index.yml'),
            'feedback_path' => $project->agentPath('standards-feedback.md'),
            'workflows_dir' => self::workflowsDir($project),
            'package_name' => is_array($manifest) && is_string($manifest['name'] ?? null) ? $manifest['name'] : basename($project->root),
            'test_command' => $config->string('commands.test'),
            'verify_command' => $config->string('commands.verify'),
            'format_command' => $config->string('commands.format'),
            'format_check_command' => $config->string('commands.format_check'),
            'browser_test_command' => $config->nullableString('commands.browser_test') ?? 'not configured',
            'run_tests' => 'yes',
            'parallel' => $config->bool('build.parallel') ? 'yes' : 'no',
            'max_concurrency' => (string) max(1, $config->int('build.max_concurrency')),
            'max_turns' => (string) max(1, (int) ($config->role('implementer')['max_turns'] ?? 80)),
            'spec_id_format' => $config->string('spec_id_format'),
            'rounds_max' => (string) $config->int('review.rounds.max'),
            'rework_threshold' => $config->string('review.rework_threshold'),
            'draft_pr' => $config->bool('host.draft_pr') ? 'yes' : 'no',
            'base_branch' => $config->string('host.base'),
            'global_standards' => $globals === [] ? 'none configured' : implode(', ', $globals),
            'default_tracker' => $providers->defaultTracker() ?? 'none',
            'stage_ticket' => $yes($config->bool('stages.ticket')),
            'stage_shape' => $yes($config->bool('stages.shape')),
            'stage_spec_review' => $yes($config->bool('stages.spec_review.enabled')),
            'stage_tasks' => $yes($config->bool('stages.tasks')),
            'stage_factory' => $yes($config->bool('stages.factory')),
            'stage_post_mortem' => $config->string('stages.post_mortem'),
            'spec_review_critics' => implode(', ', $config->list('stages.spec_review.critics')),
            'spec_review_rounds' => (string) max(1, $config->int('stages.spec_review.rounds')),
            'spec_review_max_passes' => (string) max(1, $config->int('stages.spec_review.max_passes')),
            'discovery_project_mode' => $config->string('discovery.project.mode'),
            'discovery_global_mode' => $config->string('discovery.global.mode'),
            'severity_rubric' => trim((string) file_get_contents(self::resources('fragments/severity.md'))),
            'precedent' => trim((string) file_get_contents(self::resources('fragments/precedent.md'))),
            'escalation_variants' => (string) max(2, min(4, $config->int('build.escalation_variants'))),
            'model_line' => '',
        ];

        return [
            ...$base,
            'tracker_adapters' => self::adapters($providers, $providers->trackers(), 'tracker', $base),
            'host_adapter' => self::adapters($providers, array_filter([$providers->host()]), 'host', $base),
        ];
    }

    public static function version(Project $project): string
    {
        return InstalledPackage::find($project, StandardsResolver::PACKAGE)->version ?? 'dev';
    }

    public static function marker(Project $project): string
    {
        return '<!-- '.Project::GENERATED_MARKER.' '.self::version($project).'; do not edit. Rebuild with: vendor/bin/ringside sync -->';
    }

    /**
     * Where the shipped workflow scripts live, relative to the package root.
     */
    public static function workflowsDir(Project $project): string
    {
        $self = InstalledPackage::find($project, StandardsResolver::PACKAGE);

        return ($self->path ?? $project->vendorDir.'/refactor-circus/ringside').'/resources/workflows';
    }

    /**
     * @param  array<int, ?string>  $names
     * @param  array<string, string>  $vars
     */
    private static function adapters(ProviderRegistry $providers, array $names, string $kind, array $vars): string
    {
        $sections = [];

        foreach (array_filter($names) as $name) {
            $provider = $providers->get((string) $name);

            $sections[] = $provider === null
                ? "### {$name}\n\nNo `{$name}` adapter is installed. Stop and tell the user to add one or change ring-master.yml."
                : self::fill($provider->body, $vars);
        }

        return $sections === [] ? "No {$kind} is configured for this package." : implode("\n\n", $sections);
    }
}
