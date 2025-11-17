<?php
/**
 * Copyright (c) 2025. Volodymyr Hryvinskyi.  All rights reserved.
 * @author: <mailto:volodymyr@hryvinskyi.com>
 * @github: <https://github.com/hryvinskyi>
 */

declare(strict_types=1);

namespace Hryvinskyi\MagentoComposerQualityPatches\Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Hryvinskyi\MagentoComposerQualityPatches\Config\ConfigReader;
use Symfony\Component\Process\Process;

/**
 * Main patch installer that applies patches from magento/quality-patches
 */
class PatchInstaller
{
    private const MAGENTO_PATCHES_BIN = 'vendor/bin/magento-patches';
    private const PROCESS_TIMEOUT = 600;

    private readonly string $basePath;

    /**
     * @param Composer $composer The Composer instance
     * @param IOInterface $io The IO interface for output
     */
    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io
    ) {
        $this->basePath = getcwd() ?: '.';
    }

    /**
     * Install quality patches from available sources
     *
     * @return void
     */
    public function install(): void
    {
        // Read configuration
        $configReader = new ConfigReader($this->composer);
        $config = $configReader->getConfig();

        if (!$config['enabled']) {
            $this->io->write('<comment>Quality Patches plugin is disabled in configuration</comment>', true, IOInterface::VERBOSE);
            return;
        }

        $this->io->write('<info>Checking for available Magento patches...</info>');

        $hasQualityPatches = $this->hasQualityPatches();

        if (!$hasQualityPatches) {
            $this->io->write(
                '<comment>No patch packages found. Install magento/quality-patches to enable automated patching.</comment>'
            );
            return;
        }

        // Apply Quality Patches
        if (!empty($config['patches'])) {
            $this->applyQualityPatches($config);
        } else {
            $this->io->write('<comment>No patches specified in configuration. Add patches to "extra.hryvinskyi-quality-patches.patches" in composer.json</comment>');
        }

        $this->io->write('<info>Patch application completed</info>');
    }

    /**
     * Check if magento/quality-patches is installed
     *
     * @return bool True if available
     */
    private function hasQualityPatches(): bool
    {
        $binaryPath = $this->basePath . '/' . self::MAGENTO_PATCHES_BIN;
        return file_exists($binaryPath) && is_executable($binaryPath);
    }

    /**
     * Apply quality patches using magento-patches
     *
     * @param array{enabled: bool, patches: array<string>} $config Plugin configuration
     * @return void
     */
    private function applyQualityPatches(array $config): void
    {
        $this->io->write('<info>Applying Magento Quality patches...</info>');

        $patches = $config['patches'];

        if (empty($patches)) {
            return;
        }

        $this->io->write(sprintf('<info>Configured %d patch(es) to apply</info>', count($patches)));

        // Apply each patch (magento-patches will check if already applied)
        $successfullyApplied = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($patches as $patchId) {
            try {
                $this->applyPatch($patchId);
                $successfullyApplied++;
                $this->io->write(sprintf('  ✓ Applied: %s', $patchId));
            } catch (\RuntimeException $e) {
                $errorMessage = $e->getMessage();

                // Check if patch is already applied
                if (stripos($errorMessage, 'already applied') !== false || stripos($errorMessage, 'already installed') !== false) {
                    $skipped++;
                    $this->io->write(sprintf('  - Already applied: %s', $patchId), true, IOInterface::VERBOSE);
                } elseif ($this->isPatchNotFoundError($errorMessage)) {
                    $skipped++;
                    $this->io->write(sprintf('  ⊘ Skipped (not available): %s', $patchId), true, IOInterface::VERBOSE);
                } else {
                    $failed++;
                    $this->io->writeError(sprintf('  ✗ Failed: %s - %s', $patchId, $errorMessage));
                }
            }
        }

        // Summary
        if ($successfullyApplied > 0) {
            $this->io->write(sprintf('<info>Successfully applied %d patch(es)</info>', $successfullyApplied));
        }
        if ($skipped > 0) {
            $this->io->write(sprintf('<comment>Skipped %d patch(es) (already applied or not available)</comment>', $skipped), true, IOInterface::VERBOSE);
        }
        if ($failed > 0) {
            $this->io->writeError(sprintf('<error>Failed to apply %d patch(es)</error>', $failed));
        }
    }

    /**
     * Get list of recommended patches from patches-info.json
     *
     * @return array<string> List of patch IDs
     */
    private function getRecommendedPatches(): array
    {
        $patchesInfoFile = $this->basePath . '/vendor/magento/quality-patches/patches-info.json';

        if (!file_exists($patchesInfoFile)) {
            $this->io->write('<comment>patches-info.json not found</comment>', true, IOInterface::VERY_VERBOSE);
            return [];
        }

        $content = file_get_contents($patchesInfoFile);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['patches'])) {
            return [];
        }

        // Get current Magento version
        $magentoVersion = $this->getMagentoVersion();
        if ($magentoVersion === null) {
            $this->io->writeError('<warning>Could not detect Magento version</warning>');
            return [];
        }

        $this->io->write(
            sprintf('Detected Magento version: %s', $magentoVersion),
            true,
            IOInterface::VERBOSE
        );

        // Filter patches for current version
        $recommendedPatches = [];
        foreach ($data['patches'] as $patch) {
            if (!isset($patch['id']) || !isset($patch['releases'])) {
                continue;
            }

            // Ensure patch ID is a string
            $patchId = is_array($patch['id']) ? (string) reset($patch['id']) : (string) $patch['id'];

            // Skip deprecated patches
            if (isset($patch['deprecated']) && $patch['deprecated']) {
                continue;
            }

            // Ensure releases is an array
            $releases = is_array($patch['releases']) ? $patch['releases'] : [$patch['releases']];

            // Check if patch is for current Magento version
            if (in_array($magentoVersion, $releases, true)) {
                // Check if patch has requirements
                if (isset($patch['require'])) {
                    // Skip patches with requirements for now (could be extended later)
                    $this->io->write(
                        sprintf('Skipping %s (has dependencies)', $patchId),
                        true,
                        IOInterface::DEBUG
                    );
                    continue;
                }

                $recommendedPatches[] = $patchId;
            }
        }

        return $recommendedPatches;
    }

    /**
     * Get current Magento version from installed packages
     *
     * @return string|null The Magento version (e.g., "2.4.6-p13")
     */
    private function getMagentoVersion(): ?string
    {
        $repositoryManager = $this->composer->getRepositoryManager();
        $localRepo = $repositoryManager->getLocalRepository();

        $magentoPackages = [
            'magento/product-community-edition',
            'magento/product-enterprise-edition',
            'magento/magento2-base',
        ];

        foreach ($magentoPackages as $packageName) {
            $packages = $localRepo->findPackages($packageName);
            if (!empty($packages)) {
                $package = reset($packages);
                $version = $package->getPrettyVersion();

                // Normalize version (remove v prefix, keep only major.minor.patch-pX format)
                $version = preg_replace('/^v?(\d+\.\d+\.\d+(?:-p\d+)?).*/', '$1', $version);

                return $version;
            }
        }

        return null;
    }

    /**
     * Apply a single quality patch
     *
     * @param string $patchId The patch ID to apply
     * @return void
     * @throws \RuntimeException If patch application fails
     */
    private function applyPatch(string $patchId): void
    {
        $command = [
            $this->basePath . '/' . self::MAGENTO_PATCHES_BIN,
            'apply',
            '--no-interaction',
            $patchId
        ];

        $this->executeCommand($command, $patchId);
    }

    /**
     * Execute a command using Symfony Process
     *
     * @param array<string> $command The command to execute
     * @param string $description Description for logging
     * @return void
     * @throws \RuntimeException If command fails
     */
    private function executeCommand(array $command, string $description): void
    {
        $this->io->write(
            sprintf('Executing: %s', implode(' ', $command)),
            true,
            IOInterface::DEBUG
        );

        $process = new Process($command, $this->basePath, null, null, self::PROCESS_TIMEOUT);

        $process->run(function ($type, $buffer) {
            if ($this->io->isVeryVerbose()) {
                if (Process::ERR === $type) {
                    $this->io->writeError($buffer, false);
                } else {
                    $this->io->write($buffer, false);
                }
            }
        });

        if (!$process->isSuccessful()) {
            // Get error message from process output
            $errorOutput = trim($process->getErrorOutput());
            $output = trim($process->getOutput());
            $message = !empty($errorOutput) ? $errorOutput : $output;

            // Throw RuntimeException with clean message
            throw new \RuntimeException($message ?: 'Command failed');
        }
    }

    /**
     * Check if error message indicates patch not found
     *
     * @param string $message The error message
     * @return bool True if patch not found
     */
    private function isPatchNotFoundError(string $message): bool
    {
        $patterns = [
            "weren't found",
            "not found",
            "does not exist",
            "cannot find",
        ];

        foreach ($patterns as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if message is expected (no patches to apply, already applied, etc.)
     *
     * @param string $message The message
     * @return bool True if expected message
     */
    private function isExpectedMessage(string $message): bool
    {
        $patterns = [
            'no patches',
            'already applied',
            'nothing to apply',
            'up to date',
        ];

        foreach ($patterns as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

}
