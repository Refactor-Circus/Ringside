<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Render\Template;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dashboard', description: 'Serve a live board of every job (this package, plus any in dashboard.projects or --project) on 127.0.0.1')]
final class DashboardCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Port (default: dashboard.port, 8765)')
            ->addOption('project', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Another package root to include, e.g. ../Atrium');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->project();
        $extra = array_values(array_map(strval(...), (array) $input->getOption('project')));
        $roots = self::roots($project, [...$project->config->list('dashboard.projects'), ...$extra]);
        $port = (int) ($input->getOption('port') ?? $project->config->int('dashboard.port'));
        // Composer's bin proxy passes the project's autoloader. Reflecting on
        // ClassLoader is not safe: a PHP setup with an auto_prepend_file can load its own copy first.
        $proxied = $GLOBALS['_composer_autoload_path'] ?? null;
        $autoload = is_string($proxied) && is_file($proxied) ? (string) realpath($proxied) : $project->path($project->vendorDir.'/autoload.php');

        $output->writeln(sprintf('ringside dashboard: http://127.0.0.1:%d  (%d package%s; Ctrl+C to stop)', $port, count($roots), count($roots) === 1 ? '' : 's'));

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, Template::resources('dashboard/router.php')],
            [STDIN, STDOUT, STDERR],
            $pipes,
            Template::resources('dashboard'),
            [...getenv(), 'RINGSIDE_AUTOLOAD' => $autoload, 'RINGSIDE_PROJECTS' => (string) json_encode($roots)],
        );

        return is_resource($process) ? proc_close($process) : self::FAILURE;
    }

    /**
     * This package first, then every other root that has a composer.json; duplicates dropped.
     *
     * @param  list<string>  $extra
     * @return list<string>
     */
    public static function roots(Project $project, array $extra): array
    {
        $roots = [$project->root];

        foreach ($extra as $path) {
            $absolute = str_starts_with($path, '/') ? $path : $project->path($path);
            $real = realpath($absolute);

            if ($real !== false && is_file($real.'/composer.json')) {
                $roots[] = $real;
            }
        }

        return array_values(array_unique(array_map(static fn (string $root): string => realpath($root) ?: $root, $roots)));
    }
}
