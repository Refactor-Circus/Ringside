<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Specs\Job;
use RefactorCircus\Ringside\Worktree;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'worktrees', description: 'Create the job worktree for a spec: one worktree on the job branch, plus group, variant or replay worktrees beside it')]
final class WorktreesCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('spec', InputArgument::REQUIRED, 'Spec id, or a unique part of it')
            ->addOption('setup', null, InputOption::VALUE_NONE, 'Install dependencies inside each worktree with build.worktree.setup')
            ->addOption('groups', null, InputOption::VALUE_REQUIRED, 'Create a worktree per task group (comma-separated numbers) beside the job worktree, branched from the job branch')
            ->addOption('remove-groups', null, InputOption::VALUE_NONE, 'Remove the group worktrees and delete their branches')
            ->addOption('variants', null, InputOption::VALUE_REQUIRED, 'Create N worktrees (2 to '.Job::MAX_VARIANTS.') beside the job worktree, <worktree>--v<n> on <branch>--v<n>, each branched from the job branch')
            ->addOption('remove-variants', null, InputOption::VALUE_NONE, 'Remove the variant worktrees and delete their branches')
            ->addOption('replay', null, InputOption::VALUE_NONE, 'Act on the replay worktree (<worktree>--replay on <branch>--replay) instead of the job\'s')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'With --replay: the commit or branch the replay branch starts from (default host.base)')
            ->addOption('remove-replay', null, InputOption::VALUE_NONE, 'Remove the replay worktree, its variants and their branches');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->single($this->project(), $input, $output);
    }

    /**
     * One worktree at `job.worktree` on the job branch, set up with
     * `build.worktree.setup` on --setup.
     */
    private function single(Project $project, InputInterface $input, OutputInterface $output): int
    {
        $mode = $project->config->string('build.worktree.mode');

        if ($mode !== 'per-job') {
            $output->writeln("<error>build.worktree.mode is `{$mode}`, so this package does not use a worktree per job; run the job in the package itself.</error>");

            return self::FAILURE;
        }

        try {
            $replay = (bool) $input->getOption('replay');
            $job = (new Job)->build($project, (string) $input->getArgument('spec'), $replay);
            // Read before the worktree exists, so a bad value changes nothing.
            $setup = $input->getOption('setup') ? $project->config->worktreeSetup() : null;
            $branch = (string) $job['job']['branch'];
            $jobPath = (string) $job['job']['worktree'];

            if ($input->getOption('remove-replay')) {
                $real = (new Job)->build($project, (string) $input->getArgument('spec'));
                $this->lines($output, Worktree::removeReplay($project, (string) $real['job']['worktree'], (string) $real['job']['branch']) ?: ['No replay worktree.']);

                return self::SUCCESS;
            }

            if ($input->getOption('remove-groups')) {
                $this->lines($output, Worktree::removeGroups($project, $jobPath, $branch) ?: ['No group worktrees.']);

                return self::SUCCESS;
            }

            if ($input->getOption('remove-variants')) {
                $this->lines($output, Worktree::removeVariants($project, $jobPath, $branch) ?: ['No variant worktrees.']);

                return self::SUCCESS;
            }

            $from = $input->getOption('from');
            $base = $replay && is_string($from) && $from !== '' ? $from : $project->config->string('host.base');
            // A relative job.worktree resolves against the package root.
            $path = Worktree::prepare($project, $jobPath, $branch, $base);
            // The spec stages made the worktree, maybe days ago: a branch with only the record catches up with base first.
            $refreshed = $replay ? null : Worktree::refresh($path, $base, (string) $job['spec']['dir']);
        } catch (RuntimeException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return self::FAILURE;
        }

        $targets = [$path => $branch];
        $groups = $input->getOption('groups');
        $variants = $input->getOption('variants');

        if (is_string($variants)) {
            $count = is_numeric($variants) ? (int) $variants : 0;

            if ($count < 2 || $count > Job::MAX_VARIANTS) {
                $output->writeln('<error>--variants needs a number from 2 to '.Job::MAX_VARIANTS.', e.g. --variants=3.</error>');

                return self::FAILURE;
            }

            try {
                $targets = [];

                foreach (Worktree::variants($project, $jobPath, $branch, range(1, $count)) as $variant) {
                    $targets[$variant['path']] = $variant['branch'];
                }
            } catch (RuntimeException $exception) {
                $output->writeln('<error>'.$exception->getMessage().'</error>');

                return self::FAILURE;
            }
        }

        if (is_string($groups)) {
            $numbers = array_values(array_unique(array_map(intval(...), array_filter(array_map(trim(...), explode(',', $groups)), is_numeric(...)))));

            if ($numbers === []) {
                $output->writeln('<error>--groups needs task group numbers, e.g. --groups=1,3.</error>');

                return self::FAILURE;
            }

            try {
                $targets = [];

                foreach (Worktree::groups($project, $jobPath, $branch, $numbers) as $group) {
                    $targets[$group['path']] = $group['branch'];
                }
            } catch (RuntimeException $exception) {
                $output->writeln('<error>'.$exception->getMessage().'</error>');

                return self::FAILURE;
            }
        }

        if ($refreshed !== null) {
            $output->writeln($refreshed);
        }

        foreach ($targets as $dir => $on) {
            $dir = realpath($dir) ?: $dir;
            $line = "{$dir} on {$on}";

            if ($setup !== null) {
                [$code, $out, $ran] = Worktree::setup($project, $dir, $setup);

                if ($code !== 0) {
                    $output->writeln("<error>{$ran} failed in {$dir}</error>");
                    $output->writeln($out);

                    return self::FAILURE;
                }

                $line .= ", set up with {$ran}";
            }

            $output->writeln($line);
        }

        return self::SUCCESS;
    }
}
