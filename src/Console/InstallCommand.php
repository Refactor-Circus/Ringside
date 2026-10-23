<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Installer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'install', description: 'Set up ringside in this package: config, docs/agent/, arch preset, pint.json, .gitignore, then sync')]
final class InstallCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('pint', null, InputOption::VALUE_NONE, 'Replace pint.json with the standards copy when they differ')
            ->addOption('no-migrate', null, InputOption::VALUE_NONE, 'Leave an existing agent-os/ folder alone')
            ->addOption('seed', null, InputOption::VALUE_NONE, 'Copy the global packages\' seed standards into docs/agent/standards/')
            ->addOption('no-preset', null, InputOption::VALUE_NONE, 'Leave tests/Pest.php and tests/ArchTest.php alone (e.g. while the standards package is installed locally only)')
            ->addOption('no-pint', null, InputOption::VALUE_NONE, 'Never copy pint.json from the standards package');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->lines($output, (new Installer)->install(
            $this->project(),
            overwritePint: (bool) $input->getOption('pint'),
            migrate: ! $input->getOption('no-migrate'),
            seed: (bool) $input->getOption('seed'),
            preset: ! $input->getOption('no-preset'),
            pint: ! $input->getOption('no-pint'),
        ));

        return self::SUCCESS;
    }
}
