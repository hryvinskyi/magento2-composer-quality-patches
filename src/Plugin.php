<?php
/**
 * Copyright (c) 2025. Volodymyr Hryvinskyi.  All rights reserved.
 * @author: <mailto:volodymyr@hryvinskyi.com>
 * @github: <https://github.com/hryvinskyi>
 */

declare(strict_types=1);

namespace Hryvinskyi\MagentoComposerQualityPatches;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Hryvinskyi\MagentoComposerQualityPatches\Installer\PatchInstaller;

/**
 * Composer plugin for automatic Magento quality patches installation
 *
 * This plugin integrates with Composer to automatically apply Magento quality patches
 * from magento/quality-patches package.
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @inheritDoc
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        $io->write('<info>Quality Patches Plugin activated</info>', true, IOInterface::VERBOSE);
    }

    /**
     * @inheritDoc
     */
    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // No deactivation needed
    }

    /**
     * @inheritDoc
     */
    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // No cleanup needed
    }

    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstallOrUpdate',
            ScriptEvents::POST_UPDATE_CMD => 'onPostInstallOrUpdate',
        ];
    }

    /**
     * Handle post-install and post-update events
     *
     * @param Event $event The Composer event
     * @return void
     */
    public function onPostInstallOrUpdate(Event $event): void
    {
        $composer = $event->getComposer();
        $io = $event->getIO();

        try {
            $installer = new PatchInstaller($composer, $io);
            $installer->install();
        } catch (\Throwable $e) {
            $io->writeError(sprintf(
                '<error>Quality Patches Plugin Error: %s</error>',
                $e->getMessage()
            ));

            if ($io->isVeryVerbose()) {
                $io->writeError($e->getTraceAsString());
            }

            // Never throw - don't break composer operations
        }
    }
}
