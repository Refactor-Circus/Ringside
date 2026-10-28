<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Specs\Job;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'job', description: 'Print the JSON a workflow needs for one spec: waves, decisions, standards, resolved config')]
final class JobCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('spec', InputArgument::REQUIRED, 'Spec id, or a unique part of it')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON (the only format; accepted so the skills can say so)')
            ->addOption('replay', null, InputOption::VALUE_NONE, 'For a replay: every task group undone, on the job branch and worktree with a --replay suffix');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $job = (new Job)->build($this->project(), (string) $input->getArgument('spec'), (bool) $input->getOption('replay'));
        } catch (RuntimeException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return self::FAILURE;
        }

        $output->writeln((string) json_encode($job, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
