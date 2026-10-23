<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Shell;
use RefactorCircus\Ringside\Specs\Job;
use RefactorCircus\Ringside\Workspace\Workspace;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'worktrees', description: 'Workspaces: create the job folder for a spec (a worktree per changed package, links to the rest), or remove it')]
final class WorktreesCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('spec', InputArgument::REQUIRED, 'Spec id, or a unique part of it')
            ->addOption('setup', null, InputOption::VALUE_NONE, 'Run each changed package\'s install inside its worktree')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the job folder instead');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->project();
        $workspace = new Workspace($project);

        if (! $workspace->enabled()) {
            $output->writeln('<error>No workspace.packages in ring-master.yml; this is a single package, which uses one worktree per job.</error>');

            return self::FAILURE;
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
}
