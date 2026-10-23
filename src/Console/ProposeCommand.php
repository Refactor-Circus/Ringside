<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Discovery\Proposer;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'propose', description: 'Commit queued global standards proposals on a branch of a local checkout of the global package')]
final class ProposeCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::OPTIONAL, 'Proposal key (category/name); omit with --all')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every queued proposal, one branch each')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'Path to the global package checkout (default: discovery.global.repo)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->project();
        $proposer = new Proposer;
        $repo = (string) ($input->getOption('repo') ?? $project->config->nullableString('discovery.global.repo') ?? '');
        $keys = $input->getOption('all') ? $proposer->queued($project) : array_filter([(string) $input->getArgument('key')]);

        if ($repo === '' || $keys === []) {
            $output->writeln($repo === '' ? '<error>Give --repo=<path> or set discovery.global.repo.</error>' : 'Nothing queued in '.$proposer->queueDir($project).'/.');

            return $repo === '' ? self::FAILURE : self::SUCCESS;
        }

        foreach ($keys as $key) {
            try {
                $done = $proposer->propose($project, $key, $repo);
                $output->writeln("{$key}: committed {$done['file']} on {$done['branch']} in {$repo}. Push it and open the PR with the host adapter.");
            } catch (RuntimeException $exception) {
                $output->writeln("<error>{$key}: {$exception->getMessage()}</error>");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
