<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Project;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Output\OutputInterface;

abstract class Command extends SymfonyCommand
{
    protected function project(): Project
    {
        return Project::find((string) getcwd());
    }

    /**
     * @param  list<string>  $lines
     */
    protected function lines(OutputInterface $output, array $lines): void
    {
        foreach ($lines as $line) {
            $output->writeln('  '.$line);
        }
    }
}
