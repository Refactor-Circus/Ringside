<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\AgentKitMigrator;
use RefactorCircus\Ringside\Migrator;
use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Sync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate', description: 'Move an agent-kit install to the Ring Master names, and an Agent OS install (agent-os/) into docs/agent/')]
final class MigrateCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan without moving anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->project();

        $agentKit = (new AgentKitMigrator)->needed($project);
        $agentOs = is_dir($project->path(Migrator::LEGACY_DIR));

        if (! $agentKit && ! $agentOs) {
            $output->writeln('Nothing to migrate: no agent-kit install and no '.Migrator::LEGACY_DIR.'/ folder.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        if ($agentKit) {
            $this->lines($output, (new AgentKitMigrator)->migrate($project, $dryRun));
            $project = new Project($project->root);
        }

        if ($agentOs) {
            $this->lines($output, (new Migrator)->migrate($project, $dryRun));
        }

        if (! $dryRun) {
            $output->writeln((new Sync)->run($project, force: true)->summary());
        }

        return self::SUCCESS;
    }
}
