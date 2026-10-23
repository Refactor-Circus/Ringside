<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Render;

use RefactorCircus\Ringside\Project;
use stdClass;

/**
 * Merges ringside's hooks into .claude/settings.json. An entry is ringside's
 * when its command points at ringside-hook; every other entry is left as is.
 */
final class HookWriter
{
    public const string FILE = '.claude/settings.json';

    public const string SCRIPT = 'refactor-circus/ringside/bin/ringside-hook';

    public function write(Project $project): bool
    {
        $existing = $project->read(self::FILE);
        $settings = $existing === null ? [] : json_decode($existing, true);

        if (! is_array($settings)) {
            return false;
        }

        $hooks = is_array($settings['hooks'] ?? null) ? $settings['hooks'] : [];

        foreach (['PostToolUse', 'SessionStart'] as $event) {
            $hooks[$event] = array_values(array_filter(
                is_array($hooks[$event] ?? null) ? $hooks[$event] : [],
                static fn (mixed $entry): bool => ! self::ours($entry),
            ));
        }

        $script = '$CLAUDE_PROJECT_DIR/'.$project->vendorDir.'/'.self::SCRIPT;

        $format = $project->config->nullableString('hooks.post_edit_format.command');

        if ($project->config->bool('hooks.post_edit_format.enabled') && $format !== null) {
            $hooks['PostToolUse'][] = [
                'matcher' => 'Edit|Write',
                'hooks' => [['type' => 'command', 'command' => 'php "'.$script.'" post-edit \''.$format.'\'']],
            ];
        }

        if ($project->config->bool('hooks.session_start_summary')) {
            $hooks['SessionStart'][] = [
                'matcher' => 'startup|resume',
                'hooks' => [['type' => 'command', 'command' => 'sh -c \'[ -f "'.$script.'" ] && php "'.$script.'" session-start || echo "ringside: vendor/ is missing; run composer install"\'']],
            ];
        }

        $hooks = array_filter($hooks, static fn (mixed $entries): bool => $entries !== []);

        if ($hooks === [] && $existing === null) {
            return false;
        }

        if ($hooks === []) {
            unset($settings['hooks']);
        } else {
            $settings['hooks'] = $hooks;
        }

        return $project->write(self::FILE, json_encode($settings === [] ? new stdClass : $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    private static function ours(mixed $entry): bool
    {
        foreach (is_array($entry) && is_array($entry['hooks'] ?? null) ? $entry['hooks'] : [] as $hook) {
            if (is_array($hook) && is_string($hook['command'] ?? null) && str_contains($hook['command'], 'ringside-hook')) {
                return true;
            }
        }

        return false;
    }
}
