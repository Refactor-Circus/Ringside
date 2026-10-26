<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Shell;
use RefactorCircus\Ringside\Specs\Job;
use RefactorCircus\Ringside\Workspace\Workspace;
use RefactorCircus\Ringside\Worktree;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'worktrees', description: 'Create the job worktree for a spec: in a single package, one worktree on the job branch; in a workspace, a job folder (a worktree per changed package, links to the rest), or remove it')]
final class WorktreesCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('spec', InputArgument::REQUIRED, 'Spec id, or a unique part of it')
            ->addOption('setup', null, InputOption::VALUE_NONE, 'Install dependencies inside each worktree (a single package uses build.worktree.setup)')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Workspaces only: remove the job folder instead');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->project();
        $workspace = new Workspace($project);

        if (! $workspace->enabled()) {
            return $this->single($project, $input, $output);
        }

        try {
            $job = (new Job)->build($project, (string) $input->getArgument('spec'));

            if ($input->getOption('remove')) {
                $this->lines($output, $workspace->remove($job['spec']['id']));

                return self::SUCCESS;
            }

            $packages = $job['workspace']['packages'];
            $prepared = $workspace->prepare($job['spec']['id'], array_map(strval(...), array_keys($packages)), $job['job']['branch'], $project->config->string('host.base'));
        } catch (RuntimeException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return self::FAILURE;
        }

        foreach ($prepared['worktrees'] as $package => $dir) {
            $line = "{$package}: {$dir} on {$job['job']['branch']}";

            if ($input->getOption('setup')) {
                [$code, $out] = Shell::run(preg_split('/\s+/', $packages[$package]['setup']) ?: [], $project->path($dir));

                if ($code !== 0) {
                    $output->writeln("<error>{$package}: `{$packages[$package]['setup']}` failed</error>");
                    $output->writeln($out);

                    return self::FAILURE;
                }

                $line .= ", set up with `{$packages[$package]['setup']}`";
            }

            $output->writeln($line);
        }

        $output->writeln('Linked: '.(implode(', ', $prepared['linked']) ?: 'none'));

        return self::SUCCESS;
    }

    /**
     * A single package: one worktree at `job.worktree` on the job branch,
     * set up with `build.worktree.setup` on --setup.
     */
    private function single(Project $project, InputInterface $input, OutputInterface $output): int
    {
        $mode = $project->config->string('build.worktree.mode');

        if ($mode !== 'per-job') {
            $output->writeln("<error>build.worktree.mode is `{$mode}`, so this package does not use a worktree per job; run the job in the package itself.</error>");

            return self::FAILURE;
        }

        if ($input->getOption('remove')) {
            $output->writeln('<error>--remove is not supported in a single package; build.worktree.cleanup removes the job worktree.</error>');

            return self::FAILURE;
        }

        try {
            $job = (new Job)->build($project, (string) $input->getArgument('spec'));
            // Read before the worktree exists, so a bad value changes nothing.
            $setup = $input->getOption('setup') ? $project->config->worktreeSetup() : null;
            $branch = (string) $job['job']['branch'];
            // A relative job.worktree resolves against the package root.
            $path = Worktree::prepare($project, (string) $job['job']['worktree'], $branch, $project->config->string('host.base'));
        } catch (RuntimeException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return self::FAILURE;
        }

        $path = realpath($path) ?: $path;
        $line = "{$path} on {$branch}";

        if ($setup !== null) {
            [$code, $out, $ran] = Worktree::setup($project, $path, $setup);

            if ($code !== 0) {
                $output->writeln("<error>{$ran} failed</error>");
                $output->writeln($out);

                return self::FAILURE;
            }

            $line .= ", set up with {$ran}";
        }

        $output->writeln($line);

        return self::SUCCESS;
    }
}
