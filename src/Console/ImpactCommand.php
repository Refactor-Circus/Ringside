<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Impact;
use RefactorCircus\Ringside\Specs\Job;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'impact', description: 'Run the tests of every package that requires this one against a job\'s worktree, each in a throwaway worktree of its own, or list those packages')]
final class ImpactCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('spec', InputArgument::REQUIRED, 'Spec id, or a unique part of it')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Only this consumer (its folder name)')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List the consumers instead of testing them')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON');
    }

    /**
     * Exits 0 whenever the checks ran, passing or not (the results say which);
     * non-zero only when they could not run at all.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->project();
        $json = (bool) $input->getOption('json');

        try {
            $job = (new Job)->build($project, (string) $input->getArgument('spec'));
            $trees = Impact::targets($project, $job);
            // The same consumers `ringside job` lists, typed: every package that requires a changed one, outside the job.
            $consumers = Impact::consumers($project, array_keys($trees));
            $only = $input->getOption('package');

            if (is_string($only)) {
                $consumers = array_values(array_filter($consumers, static fn (array $c): bool => $c['folder'] === $only));

                if ($consumers === []) {
                    throw new RuntimeException("{$only} is not a consumer of this job; `ringside impact {$job['spec']['id']} --list` shows them.");
                }
            }

            if ($input->getOption('list')) {
                $json
                    ? $output->writeln((string) json_encode(['consumers' => $consumers], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))
                    : $this->lines($output, $consumers === [] ? ['none'] : array_map(static fn (array $c): string => "{$c['folder']}: {$c['path']} (`{$c['test']}`)", $consumers));

                return self::SUCCESS;
            }

            foreach ($trees as $tree) {
                if (! is_dir($tree)) {
                    throw new RuntimeException("The job worktree {$tree} does not exist; run `ringside worktrees {$job['spec']['id']}` first.");
                }
            }
        } catch (RuntimeException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return self::FAILURE;
        }

        $results = array_map(static fn (array $consumer): array => Impact::run($consumer, (string) $job['spec']['id'], $trees), $consumers);

        if ($json) {
            $output->writeln((string) json_encode(['results' => $results], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            foreach ($results as $result) {
                $output->writeln(($result['passed'] ? 'PASS ' : 'FAIL ').$result['summary']);

                if (! $result['passed']) {
                    $this->lines($output, explode("\n", $result['output']));
                }
            }

            $output->writeln($results === [] ? 'No package requires this one.' : '');
        }

        return self::SUCCESS;
    }
}
