<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Shell;
use RefactorCircus\Ringside\Specs\Locator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'cleanup', description: 'List job worktrees whose spec is closed or gone, and remove them with --remove')]
final class CleanupCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('remove', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Remove these specs\' worktrees (no value: every orphaned one)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->project();
        $worktrees = [];

        // Each job worktree holds its spec, so its status is read there.
        foreach (Locator::worktrees($project) as $spec => $path) {
            try {
                $meta = Yaml::parseFile($path.'/'.Locator::relative($project).'/'.$spec.'/meta.yml');
            } catch (ParseException) {
                $meta = [];
            }

            $worktrees[$spec] = ['path' => $path, 'status' => is_array($meta) ? (string) ($meta['status'] ?? 'unknown') : 'unknown'];
        }

        $remove = (array) $input->getOption('remove');
        $all = $input->hasParameterOption('--remove') && array_filter($remove) === [];
        $orphans = array_filter($worktrees, static fn (array $w): bool => in_array($w['status'], ['closed', 'done'], true));

        foreach ($worktrees as $spec => $worktree) {
            $exists = is_dir($worktree['path']);
            $wanted = in_array($spec, $remove, true) || ($all && isset($orphans[$spec]));

            if ($wanted && $exists) {
                [$code, $out] = Shell::run(['git', 'worktree', 'remove', $worktree['path']], $project->root);
                $output->writeln($code === 0 ? "Removed {$worktree['path']} ({$spec})" : "<error>Could not remove {$worktree['path']}: {$out}</error>");
            } elseif ($exists) {
                $output->writeln(sprintf('%s  %s  %s', $worktree['path'], $worktree['status'], isset($orphans[$spec]) ? 'orphaned' : 'active'));
            }
        }

        return self::SUCCESS;
    }
}
