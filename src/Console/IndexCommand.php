<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Review;
use RefactorCircus\Ringside\Sync;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'index', description: 'Record a reviewed override with --accept, then rebuild the index and skills')]
final class IndexCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('accept', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Standard key (e.g. testing/pest) whose override you reviewed against the current vendor file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->project();
        $accept = (array) $input->getOption('accept');

        try {
            foreach ($accept as $key) {
                $output->writeln((new Review)->accept($project, (string) $key));
            }
        } catch (RuntimeException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return self::FAILURE;
        }

        // A full sync, not just the index: the index carries the fingerprint,
        // and a fresh fingerprint over stale skills would hide them from sync.
        $output->writeln((new Sync)->run($project)->summary());

        return self::SUCCESS;
    }
}
