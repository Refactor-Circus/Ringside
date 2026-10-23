<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Scaffold\PackageScaffolder;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'new-package', description: 'Workspaces: scaffold a new package (laravel package, or composer init for plain PHP), git init it and add it to the workspace')]
final class NewPackageCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'StudlyCase folder and class name, e.g. Ledger')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'laravel (default) or php')
            ->addOption('vendor', null, InputOption::VALUE_REQUIRED, 'Composer vendor (default: the one most workspace packages use)')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Root namespace prefix (default: from the vendor)')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'One-line package description')
            ->addOption('feature', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Laravel skeleton part: config, routes, views, translations, migrations, assets, commands, facade, boost-skill')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan and the command without creating anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $features = array_values(array_map(strval(...), (array) $input->getOption('feature')));
        $options = array_filter([
            'type' => $input->getOption('type'),
            'vendor' => $input->getOption('vendor'),
            'namespace' => $input->getOption('namespace'),
            'description' => $input->getOption('description'),
            'features' => $features === [] ? null : $features,
        ], static fn (mixed $value): bool => $value !== null);

        $scaffolder = new PackageScaffolder($this->project());

        try {
            $plan = $input->getOption('dry-run')
                ? $scaffolder->plan((string) $input->getArgument('name'), $options)
                : $scaffolder->create((string) $input->getArgument('name'), $options);
        } catch (RuntimeException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return self::FAILURE;
        }

        $output->writeln((string) json_encode([...$plan, 'created' => ! $input->getOption('dry-run')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
