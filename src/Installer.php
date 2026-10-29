<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside;

use RefactorCircus\Ringside\Render\Template;
use RefactorCircus\Ringside\Specs\Locator;
use RefactorCircus\Ringside\Standards\FrontMatter;
use RefactorCircus\Ringside\Standards\Hash;
use RefactorCircus\Ringside\Standards\Layer;
use RefactorCircus\Ringside\Standards\StandardsResolver;
use Symfony\Component\Yaml\Yaml;

/**
 * First-time setup. Every step is idempotent: re-running install changes
 * nothing that is already in place.
 */
final class Installer
{
    public function __construct(private readonly StandardsResolver $resolver = new StandardsResolver) {}

    /**
     * @return list<string> what was done, one line per action
     */
    public function install(Project $project, bool $overwritePint = false, bool $migrate = true, bool $seed = false, bool $preset = true, bool $pint = true): array
    {
        $log = [];

        if ($migrate && (new AgentKitMigrator)->needed($project)) {
            $log = (new AgentKitMigrator)->migrate($project);
            $project = new Project($project->root);
        }

        if (! $project->exists(Config::FILE)) {
            [$contents, $notes] = $this->initialConfig($project);
            $project->write(Config::FILE, $contents);
            $log = ['Wrote '.Config::FILE.' (detected settings only; every other key uses its default)', ...$notes];
            $project = new Project($project->root);
        }

        foreach (['standards', 'product', 'specs'] as $dir) {
            $path = $project->agentPath($dir);

            if (! is_dir($project->path($path))) {
                $project->write($path.'/.gitkeep', '');
                $log[] = "Created {$path}/";
            }
        }

        $feedback = $project->agentPath('standards-feedback.md');

        if (! $project->exists($feedback)) {
            $project->write($feedback, Template::render('standards-feedback.md', []));
            $log[] = "Wrote {$feedback}";
        }

        if ($migrate && is_dir($project->path(Migrator::LEGACY_DIR))) {
            $log = [...$log, ...(new Migrator($this->resolver))->migrate($project)];
        }

        if ($seed) {
            $log = [...$log, ...$this->seed($project)];
        }

        $log = [
            ...$log,
            ...($preset ? $this->presets($project) : ['Skipped the arch preset (--no-preset).']),
            ...($pint ? $this->pint($project, $overwritePint) : ['Skipped pint.json (--no-pint).']),
            ...$this->gitignore($project),
        ];

        $result = (new Sync($this->resolver))->run($project, force: true);
        $log[] = $result->summary();

        if ($project->config->nullableString('commands.verify') === null) {
            $log[] = 'Warning: commands.verify is not set, so the gate can only map acceptance criteria. Set it in '.Config::FILE.'.';
        }

        $log[] = 'Workflow agents use your permission rules. To avoid prompts mid-run, allow: Bash(composer *), Bash(git *), Bash(vendor/bin/*), Edit, Write, Workflow';

        return $log;
    }

    /**
     * ring-master.yml with only what install detected; global standards are
     * offered from installed packages that declare `extra.ring-master`.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function initialConfig(Project $project): array
    {
        $values = (new Detector)->detect($project);
        $notes = [];
        $candidates = $this->resolver->candidates($project);

        if ($candidates !== []) {
            $values['standards']['global'] = array_map(static fn (InstalledPackage $p): array => ['package' => $p->name, 'strict' => true], $candidates);
            $notes[] = 'Using '.implode(', ', array_map(static fn (InstalledPackage $p): string => $p->name, $candidates)).' as global standards (installed with an extra.ring-master key); remove it from standards.global to opt out.';
        }

        $header = "# ringside configuration. Every key is optional; only detected settings are written here.\n"
            ."# Full schema and defaults: vendor/refactor-circus/ringside/README.md\n";

        return [$values === [] ? $header : $header."\n".Yaml::dump($values, 4, 2, Yaml::DUMP_NULL_AS_TILDE), $notes];
    }

    /**
     * Copies seed standards (package-level starters a global package ships,
     * such as a test style) into docs/agent/standards/ when not there yet.
     *
     * @return list<string>
     */
    private function seed(Project $project): array
    {
        $log = [];

        foreach ($this->resolver->resolve($project)->packageLayers() as $layer) {
            $package = $layer->package;
            $dir = $package === null ? null : ($package->ringMaster()['seeds'] ?? null);

            if ($package === null || ! is_string($dir)) {
                continue;
            }

            foreach (Layer::markdownFiles($project->path($package->path.'/'.$dir)) as $key) {
                $target = $project->agentPath('standards/'.$key.'.md');

                if ($project->exists($target)) {
                    continue;
                }

                $contents = (string) $project->read($package->path.'/'.$dir.'/'.$key.'.md');
                $project->write($target, FrontMatter::merge($contents, ['seeded_from' => $package->label()]));
                $log[] = "Seeded {$target} from {$package->name}";
            }
        }

        return $log;
    }

    /**
     * @return list<string>
     */
    private function presets(Project $project): array
    {
        $log = [];

        foreach ($this->resolver->resolve($project)->packageLayers() as $layer) {
            $preset = $layer->package?->ringMaster()['pest-preset'] ?? null;

            if (! is_array($preset) || ! is_string($preset['class'] ?? null) || ! is_string($preset['name'] ?? null)) {
                continue;
            }

            if (((new Detector)->pestMajor($project) ?? 0) < 3) {
                $log[] = "Skipped the {$preset['name']} arch preset: it needs Pest 3 or later.";

                continue;
            }

            $pest = (string) $project->read('tests/Pest.php');

            if (! str_contains($pest, $preset['class'])) {
                $project->write('tests/Pest.php', self::registerPreset($pest, ltrim($preset['class'], '\\')));
                $log[] = "Registered the {$preset['name']} arch preset in tests/Pest.php";
            }

            $arch = $project->read('tests/ArchTest.php');
            $call = "arch()->preset()->{$preset['name']}();";

            if ($arch === null) {
                $project->write('tests/ArchTest.php', "<?php\n\ndeclare(strict_types=1);\n\narch()->preset()->php();\n\narch()->preset()->security();\n\n{$call}\n");
                $log[] = 'Wrote tests/ArchTest.php';
            } elseif (! str_contains($arch, "->{$preset['name']}()")) {
                $project->write('tests/ArchTest.php', rtrim($arch)."\n\n{$call}\n");
                $log[] = "Added {$call} to tests/ArchTest.php";
            }
        }

        if ($log !== []) {
            self::format($project, ['tests/Pest.php', 'tests/ArchTest.php']);
        }

        return $log;
    }

    /**
     * Adds `use <class>;` among the file's imports and a short
     * `<Class>::register();` call at the end, as Pint's laravel preset wants.
     */
    public static function registerPreset(string $pest, string $class): string
    {
        $short = substr((string) strrchr('\\'.$class, '\\'), 1);
        $pest = $pest === '' ? "<?php\n\ndeclare(strict_types=1);\n" : $pest;
        $import = "use {$class};";

        preg_match_all('/^use [^;(]+;$/m', $pest, $uses, PREG_OFFSET_CAPTURE);
        $last = $uses[0] === [] ? null : $uses[0][array_key_last($uses[0])];

        if ($last !== null) {
            $pest = substr_replace($pest, $last[0]."\n".$import, $last[1], strlen($last[0]));
        } else {
            $pest = (string) preg_replace('/^(declare\(strict_types=1\);)$/m', "$1\n\n".$import, $pest, 1, $count);
            $pest = $count === 0 ? $pest."\n".$import."\n" : $pest;
        }

        return rtrim($pest)."\n\n{$short}::register();\n";
    }

    /**
     * Runs the package's own Pint on files install changed, so they pass lint:check.
     *
     * @param  list<string>  $files
     */
    private static function format(Project $project, array $files): void
    {
        $pint = $project->path($project->vendorDir.'/bin/pint');
        $files = array_values(array_filter($files, $project->exists(...)));

        if (! is_file($pint) || $files === []) {
            return;
        }

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            [PHP_BINARY, $pint, ...array_map($project->path(...), $files)],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            $project->root,
        );

        if (is_resource($process)) {
            proc_close($process);
        }
    }

    /**
     * @return list<string>
     */
    private function pint(Project $project, bool $overwrite): array
    {
        $source = (new Checker($this->resolver))->pintSource($project);

        if ($source === null || Hash::file($project->path('pint.json')) === Hash::file($project->path($source))) {
            return [];
        }

        if ($project->exists('pint.json') && ! $overwrite) {
            return ["pint.json differs from {$source}; kept the project copy (re-run with --pint to replace it)"];
        }

        $project->write('pint.json', (string) $project->read($source));

        return ["Copied pint.json from {$source}"];
    }

    /**
     * @return list<string>
     */
    private function gitignore(Project $project): array
    {
        $specs = Locator::relative($project);
        $lines = [
            '/'.Sync::indexPath($project),
            '/'.Sync::summaryPath($project),
            // Spec folders are committed with their job; a session's scratch files and a replay's reports in them are not.
            "/{$specs}/*/.*",
            "/{$specs}/**/*.tmp.*",
            "/{$specs}/*/*body*.md",
            "/{$specs}/*/replay/",
        ];

        if (! $project->config->bool('generated.commit')) {
            foreach (self::claudeDirs($project) as $dir) {
                $lines[] = "/{$dir}/skills/ring-master-*/";
                $lines[] = "/{$dir}/agents/ring-master-*.md";
            }
        }

        $existing = (string) $project->read('.gitignore');
        $present = array_map(trim(...), explode("\n", $existing));
        $missing = array_values(array_diff($lines, $present));

        if ($missing === []) {
            return [];
        }

        $project->write('.gitignore', rtrim($existing)."\n\n# ringside generated files\n".implode("\n", $missing)."\n");

        return ['Added '.count($missing).' line(s) to .gitignore'];
    }

    /**
     * `.claude`, plus its symlink target when it is one (the suite links
     * `.claude -> .agents`): git ignores by the real path, not the link.
     *
     * @return list<string>
     */
    private static function claudeDirs(Project $project): array
    {
        $link = $project->path('.claude');

        if (! is_link($link)) {
            return ['.claude'];
        }

        $target = trim(str_replace('\\', '/', (string) readlink($link)), '/');

        return str_contains($target, '/') || $target === '' ? ['.claude'] : ['.claude', $target];
    }
}
