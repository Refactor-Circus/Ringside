<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Sync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sync', description: 'Rebuild the standards index, skills, implementer subagent, hooks and the AGENTS.md block')]
final class SyncCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Render even when the fingerprint is unchanged');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new Sync)->run($this->project(), force: (bool) $input->getOption('force'));

        $output->writeln($result->summary());

        if ($output->isVerbose()) {
            $this->lines($output, $result->written);
        }

        return self::SUCCESS;
    }
}
