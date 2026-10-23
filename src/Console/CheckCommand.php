<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Checker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'check', description: 'CI gate: generated files current, overrides reviewed, pint.json matches the standards copy')]
final class CheckCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $problems = (new Checker)->check($this->project());

        if ($problems === []) {
            $output->writeln('ringside: check passed.');

            return self::SUCCESS;
        }

        $output->writeln('<error>ringside: '.count($problems).' problem(s)</error>');
        $this->lines($output, $problems);

        return self::FAILURE;
    }
}
