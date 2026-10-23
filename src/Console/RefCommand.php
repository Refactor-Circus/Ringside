<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Console;

use RefactorCircus\Ringside\Providers\ProviderRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ref', description: 'Classify /ring-master-ship input as a ticket ref (and which tracker) or an idea; prints JSON')]
final class RefCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('input', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'A ticket key or URL, or free text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $text = implode(' ', (array) $input->getArgument('input'));
        $output->writeln((string) json_encode((new ProviderRegistry($this->project()))->resolve($text), JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
