<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use RefactorCircus\Ringside\Standards\StandardsResolver;
use Throwable;

/**
 * Runs `sync` after every install and update in a package that requires
 * ringside directly. A failure is reported, never fatal to Composer.
 */
final class Plugin implements EventSubscriberInterface, PluginInterface
{
    private ?Composer $composer = null;

    private ?IOInterface $io = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'sync',
            ScriptEvents::POST_UPDATE_CMD => 'sync',
        ];
    }

    public function sync(Event $event): void
    {
        $composer = $this->composer ?? $event->getComposer();
        $io = $this->io ?? $event->getIO();
        $root = $composer->getPackage();

        if (! isset($root->getDevRequires()[StandardsResolver::PACKAGE]) && ! isset($root->getRequires()[StandardsResolver::PACKAGE])) {
            return;
        }

        try {
            $config = $composer->getConfig()->getConfigSource()->getName();
            $root = dirname((string) realpath($config));
            $migrator = new AgentKitMigrator;
            $migrated = $migrator->needed(new Project($root));

            if ($migrated) {
                foreach ($migrator->migrate(new Project($root)) as $line) {
                    $io->write('ringside: '.$line);
                }
            }

            $result = (new Sync)->run(new Project($root), force: $migrated);
            $io->write('<info>'.$result->summary().'</info>');
        } catch (Throwable $exception) {
            $io->writeError('<warning>ringside: sync failed: '.$exception->getMessage().'. Run vendor/bin/ringside sync to retry.</warning>');
        }
    }
}
