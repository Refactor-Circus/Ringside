<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Shell;
use RefactorCircus\Ringside\Specs\Job;
use RefactorCircus\Ringside\Specs\Locator;
use RefactorCircus\Ringside\Worktree;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * A spec's folder lives in its job worktree, on the job branch, from the
 * moment it is created, and every stage commits it there: the PR carries the
 * spec, its decisions and its reports, and git keeps how they changed.
 */
#[AsCommand(name: 'spec', description: 'Create a spec in its own job worktree (new), print where a spec lives (path), commit its record (commit), or reopen a merged one to record its post-mortem (reopen)')]
final class SpecCommand extends Command
{
    private const array ACTIONS = ['new', 'path', 'commit', 'reopen'];

    private const int SLUG_LENGTH = 40;

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, implode(', ', self::ACTIONS))
            ->addArgument('spec', InputArgument::REQUIRED, 'new: the title (or slug) of the spec; otherwise the spec id, or a unique part of it')
            ->addArgument('message', InputArgument::OPTIONAL, 'commit: what the stage did, e.g. "shaped" (the commit message is "<id>: <message>")')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'new: the first status in meta.yml', 'shaping')
            ->addOption('json', null, InputOption::VALUE_NONE, 'new, path and reopen: print JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->project();
        $action = (string) $input->getArgument('action');
        $spec = (string) $input->getArgument('spec');

        try {
            $result = match ($action) {
                'new' => $this->create($project, $spec, (string) $input->getOption('status')),
                'path' => $this->where($project, $spec),
                'commit' => $this->commit($project, $spec, (string) $input->getArgument('message')),
                'reopen' => $this->reopen($project, $spec),
                default => throw new RuntimeException("Unknown action `{$action}`; use one of ".implode(', ', self::ACTIONS).'.'),
            };
        } catch (RuntimeException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return self::FAILURE;
        }

        $output->writeln(match (true) {
            isset($result['done']) => $result['done'],
            (bool) $input->getOption('json') => (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            default => $result['path'],
        });

        return self::SUCCESS;
    }

    /**
     * A new spec folder with a starting meta.yml, in a new job worktree on
     * the job branch (or in the package itself when it builds without one).
     *
     * @return array{id: string, path: string, worktree: ?string, branch: ?string}
     */
    private function create(Project $project, string $title, string $status): array
    {
        $slug = trim(substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-'), 0, self::SLUG_LENGTH), '-');

        if ($slug === '') {
            throw new RuntimeException('The spec needs a title with at least one letter or digit.');
        }

        $id = strtr($project->config->string('spec_id_format'), ['{date}' => date('Y-m-d'), '{time}' => date('Hi'), '{slug}' => $slug]);

        if (Locator::find($project, $id) !== null) {
            throw new RuntimeException("Spec {$id} already exists.");
        }

        $worktree = null;
        $branch = null;
        $root = $project->root;

        if ($project->config->string('build.worktree.mode') === 'per-job') {
            $branch = Job::branch($id);
            $worktree = Worktree::prepare($project, Job::worktree($project, $id), $branch, $project->config->string('host.base'));
            $root = $worktree;
        }

        $path = $root.'/'.Locator::relative($project).'/'.$id;

        if (! is_dir($path)) {
            mkdir($path, 0o755, true);
        }

        file_put_contents($path.'/meta.yml', Yaml::dump(['id' => $id, 'title' => $title, 'status' => $status, 'created' => date('Y-m-d H:i')]));

        return ['id' => $id, 'path' => realpath($path) ?: $path, 'worktree' => $worktree === null ? null : (realpath($worktree) ?: $worktree), 'branch' => $branch];
    }

    /**
     * @return array{id: string, path: string, worktree: ?string, branch: ?string}
     */
    private function where(Project $project, string $spec): array
    {
        $id = basename((new Job)->dir($project, $spec));
        $worktree = Locator::worktree($project, $id);

        return [
            'id' => $id,
            'path' => (string) Locator::find($project, $id),
            'worktree' => $worktree,
            'branch' => $worktree === null ? null : $this->branch($worktree),
        ];
    }

    /**
     * Commits the spec folder, and nothing else, on the branch of the
     * checkout that holds it. A spec still on the main checkout's base
     * branch is never committed there: other jobs build against it.
     *
     * @return array{done: string}
     */
    private function commit(Project $project, string $spec, string $message): array
    {
        $where = $this->where($project, $spec);
        $checkout = $where['worktree'] ?? $project->root;
        $dir = Locator::relative($project).'/'.$where['id'];

        if ($where['worktree'] === null && $this->branch($project->root) === $project->config->string('host.base')) {
            return ['done' => "{$where['id']} is on the main checkout, not in a job worktree; nothing committed."];
        }

        Shell::run(['git', 'add', '--all', '--', $dir], $checkout);
        [$staged] = Shell::run(['git', 'diff', '--cached', '--quiet', '--', $dir], $checkout);

        if ($staged === 0) {
            return ['done' => "Nothing new to commit for {$where['id']}."];
        }

        $message = trim($message) === '' ? 'spec record' : trim($message);
        [$code, $out] = Shell::run(['git', 'commit', '--quiet', '-m', "{$where['id']}: {$message}", '--', $dir], $checkout, withStderr: true);

        if ($code !== 0) {
            throw new RuntimeException("Could not commit {$dir} in {$checkout}: {$out}");
        }

        return ['done' => "Committed {$dir} on {$this->branch($checkout)}."];
    }

    /**
     * A worktree on `ring-master/<id>-record` from the base branch, where a
     * merged job's post-mortem and final status are written and committed
     * for a small PR of their own (the job branch is merged by then).
     *
     * @return array{id: string, path: string, worktree: ?string, branch: ?string}
     */
    private function reopen(Project $project, string $spec): array
    {
        $id = basename((new Job)->dir($project, $spec));
        $base = $project->config->string('host.base');
        $branch = Job::branch($id).Locator::RECORD;
        // The record starts from the remote base when there is one: the merge is there before main is pulled.
        Shell::run(['git', 'fetch', '--quiet', 'origin', $base], $project->root);
        [$remote] = Shell::run(['git', 'rev-parse', '--verify', '--quiet', 'origin/'.$base], $project->root);
        $worktree = Worktree::prepare($project, Job::worktree($project, $id).Locator::RECORD, $branch, $remote === 0 ? 'origin/'.$base : $base);
        $path = $worktree.'/'.Locator::relative($project).'/'.$id;

        if (! is_file($path.'/meta.yml')) {
            Shell::run(['git', 'worktree', 'remove', '--force', $worktree], $project->root);
            Shell::run(['git', 'branch', '-D', $branch], $project->root);

            throw new RuntimeException("Spec {$id} is not on {$base} yet, so its record cannot be reopened: merge its PR first.");
        }

        return ['id' => $id, 'path' => realpath($path) ?: $path, 'worktree' => realpath($worktree) ?: $worktree, 'branch' => $branch];
    }

    private function branch(string $checkout): string
    {
        [, $branch] = Shell::run(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $checkout);

        return $branch;
    }
}
