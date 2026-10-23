<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Specs\Spec;
use RefactorCircus\Ringside\Specs\SpecRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'specs', description: 'List specs with their status, standards version and open tasks')]
final class SpecsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $specs = (new SpecRepository)->all($this->project());

        if ($specs === []) {
            $output->writeln('No specs yet. Start one with /ring-master-shape-spec.');

            return self::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Spec', 'Status', 'Standards', 'Tasks'])
            ->setRows(array_map(static fn (Spec $spec): array => [
                $spec->id,
                $spec->status,
                implode(', ', array_map(static fn (string $name, string $version): string => basename($name).' '.$version, array_keys($spec->standards), $spec->standards)) ?: '-',
                $spec->openTasks + $spec->doneTasks === 0 ? '-' : "{$spec->doneTasks}/".($spec->openTasks + $spec->doneTasks),
            ], $specs))
            ->render();

        return self::SUCCESS;
    }
}
