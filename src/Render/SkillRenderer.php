<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Render;

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Standards\Resolution;
use RefactorCircus\Ringside\Standards\Standard;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes the workflow skills, one standards skill per category, and the
 * implementer subagent; removes generated ringside skills that no longer apply.
 */
final class SkillRenderer
{
    public const int DESCRIPTION_LIMIT = 1536;

    /** Skills that exist only while their stage is enabled. */
    private const array STAGE_SKILLS = [
        'ring-master-ticket' => 'stages.ticket',
        'ring-master-spec-review' => 'stages.spec_review.enabled',
        'ring-master-factory' => 'stages.factory',
        'ring-master-decide' => 'stages.factory',
        'ring-master-approve' => 'stages.factory',
        'ring-master-rework' => 'stages.factory',
    ];

    /**
     * @return list<string> paths written
     */
    public function render(Project $project, Resolution $resolution): array
    {
        $files = [];

        foreach ($this->workflowSkills($project) as $name => $contents) {
            foreach ($this->skillRoots($project) as $root) {
                $files[$root.'/'.$name.'/SKILL.md'] = $contents;
            }
        }

        if ($project->config->bool('skills')) {
            foreach ($resolution->byCategory() as $category => $standards) {
                $files['.claude/skills/'.self::standardsSkillName($category).'/SKILL.md'] = $this->standardsSkill($project, $category, $standards, $resolution->paths[$category] ?? []);
            }
        }

        foreach ($this->agents($project, $resolution) as $name => $contents) {
            $files['.claude/agents/'.$name.'.md'] = $contents;
        }

        $written = [];

        foreach ($files as $path => $contents) {
            if ($project->write($path, $contents)) {
                $written[] = $path;
            }
        }

        return [...$written, ...$this->prune($project, array_keys($files))];
    }

    /**
     * Reviewer templates: built in, or the package's own in docs/agent/reviewers/<name>.md.
     */
    public static function reviewerTemplate(Project $project, string $name): ?string
    {
        $custom = $project->agentPath('reviewers/'.$name.'.md');

        if ($project->exists($custom)) {
            return $project->path($custom);
        }

        $builtIn = Template::resources('agents/reviewers/'.$name.'.md');

        return is_file($builtIn) ? $builtIn : null;
    }

    public static function standardsSkillName(string $category): string
    {
        return 'ring-master-std-'.preg_replace('/[^a-z0-9-]+/', '-', strtolower($category));
    }

    /**
     * @return array<string, string>
     */
    private function workflowSkills(Project $project): array
    {
        $vars = [...Template::vars($project), 'marker' => Template::marker($project)];
        $skills = [];

        foreach (glob(Template::resources('skills/workflow/*.md')) ?: [] as $file) {
            $name = 'ring-master-'.basename($file, '.md');
            $stage = self::STAGE_SKILLS[$name] ?? null;

            if (($stage !== null && ! $project->config->bool($stage)) || ! $this->conditionalSkillEnabled($project, $name)) {
                continue;
            }

            $skills[$name] = Template::render('skills/workflow/'.basename($file), $vars);
        }

        ksort($skills);

        return $skills;
    }

    /**
     * @param  list<Standard>  $standards
     * @param  list<string>  $paths
     */
    private function standardsSkill(Project $project, string $category, array $standards, array $paths): string
    {
        $meta = [
            'name' => self::standardsSkillName($category),
            'description' => $this->description($category, $standards),
        ];

        if ($paths !== []) {
            $meta['paths'] = $paths;
        }

        $lines = array_map(static fn (Standard $standard): string => sprintf(
            '- `%s` — %s%s',
            $standard->path,
            $standard->key,
            $standard->isLocal() ? ($standard->overrides === null ? ' (local)' : ' (local override)') : '',
        ), $standards);

        return "---\n".Yaml::dump($meta, 2, 2)."---\n"
            .Template::marker($project)."\n\n"
            ."# {$category} standards\n\n"
            ."Read the standards below before writing or changing code in this area. Follow them unless the user says otherwise; when you must deviate, add a row to `{$project->agentPath('standards-feedback.md')}`.\n\n"
            .implode("\n", $lines)."\n";
    }

    /**
     * @param  list<Standard>  $standards
     */
    private function description(string $category, array $standards): string
    {
        $prefix = ucfirst($category).' standards for this package: ';
        $suffix = ' Use when writing, changing or reviewing '.$category.' code.';
        $budget = self::DESCRIPTION_LIMIT - strlen($prefix) - strlen($suffix);
        $body = implode('; ', array_map(static fn (Standard $standard): string => $standard->key.' ('.rtrim($standard->description, '.').')', $standards));

        if (strlen($body) > $budget) {
            $body = rtrim(substr($body, 0, $budget - 3)).'...';
        }

        return $prefix.$body.'.'.$suffix;
    }

    private function conditionalSkillEnabled(Project $project, string $name): bool
    {
        return match ($name) {
            'ring-master-inbox' => Template::boardApp($project) !== null,
            'ring-master-post-mortem' => $project->config->string('stages.post_mortem') !== 'off',
            'ring-master-propose' => $project->config->string('stages.post_mortem') !== 'off' && $project->config->globalStandards() !== [],
            default => true,
        };
    }

    /**
     * The implementer, one subagent per configured reviewer, and the
     * critic, analyst, discoverer and (when enabled) approver roles.
     *
     * @return array<string, string>
     */
    private function agents(Project $project, Resolution $resolution): array
    {
        $config = $project->config;
        $skills = $config->bool('skills')
            ? array_map(self::standardsSkillName(...), array_keys($resolution->byCategory()))
            : [];
        $base = [...Template::vars($project), 'marker' => Template::marker($project), 'skills' => $skills === [] ? '[]' : '['.implode(', ', $skills).']'];
        $model = static fn (array $role): string => is_string($role['model'] ?? null) && $role['model'] !== '' ? "model: {$role['model']}\n" : '';

        $agents = ['ring-master-implementer' => Template::render('agents/implementer.md', [...$base, 'model_line' => $model($config->role('implementer'))])];

        foreach ($config->reviewers() as $reviewer) {
            $name = (string) $reviewer['name'];
            $template = self::reviewerTemplate($project, $name);

            if ($template === null) {
                continue;
            }

            $text = Template::fill((string) file_get_contents($template), [...$base, 'model_line' => $model($reviewer)]);

            if (! str_starts_with($text, '---')) {
                $text = "---\nname: ring-master-reviewer-{$name}\ndescription: Reviews a Ring Master job's diff as the {$name} reviewer.\ntools: Read, Grep, Glob, Bash\n{$model($reviewer)}---\n{$base['marker']}\n\n{$text}\n\n{$base['severity_rubric']}\n";
            }

            $agents['ring-master-reviewer-'.$name] = $text;
        }

        foreach (['critic' => true, 'analyst' => true, 'discoverer' => true, 'approver' => (bool) ($config->role('approver')['enabled'] ?? false)] as $role => $enabled) {
            if ($enabled) {
                $agents['ring-master-'.$role] = Template::render('agents/'.$role.'.md', [...$base, 'model_line' => $model($config->role($role))]);
            }
        }

        return $agents;
    }

    /**
     * @return list<string>
     */
    private function skillRoots(Project $project): array
    {
        $roots = ['.claude/skills'];

        if (in_array('agents', $project->config->list('targets'), true)) {
            $roots[] = '.agents/skills';
        }

        return $roots;
    }

    /**
     * Compares real paths, so a `.claude -> .agents` symlink (the suite's
     * layout) is one directory, not two.
     *
     * @param  list<string>  $keep
     * @return list<string> paths removed
     */
    private function prune(Project $project, array $keep): array
    {
        $kept = array_flip(array_filter(array_map(static fn (string $path): string|false => realpath($project->path($path)), $keep)));
        $roots = [];
        $removed = [];

        foreach (glob($project->path('.claude/agents/ring-master-*.md')) ?: [] as $file) {
            $relative = '.claude/agents/'.basename($file);

            if (! isset($kept[realpath($file)]) && $project->isGenerated($relative)) {
                unlink($file);
                $removed[] = $relative;
            }
        }

        foreach (['.claude/skills', '.agents/skills'] as $root) {
            $real = realpath($project->path($root));

            if ($real !== false) {
                $roots[$real] ??= $root;
            }
        }

        foreach ($roots as $real => $root) {
            foreach (glob($real.'/ring-master-*/SKILL.md') ?: [] as $file) {
                $relative = $root.'/'.basename(dirname($file)).'/SKILL.md';

                if (isset($kept[realpath($file)]) || ! $project->isGenerated($relative)) {
                    continue;
                }

                unlink($file);

                if (array_diff((array) scandir(dirname($file)), ['.', '..']) === []) {
                    rmdir(dirname($file));
                }

                $removed[] = $relative;
            }
        }

        return $removed;
    }
}
