<?php

declare(strict_types=1);

namespace BuTing\CliAppUpdater\Composer;

use BuTing\CliAppUpdater\Setup;
use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/** Composer integration only; application runtime does not load Composer classes. */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return ['post-autoload-dump' => 'initialize'];
    }

    public function initialize(): void
    {
        Setup::initialize(dirname(Factory::getComposerFile()));
    }
}
