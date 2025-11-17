<?php
/**
 * Copyright (c) 2025. Volodymyr Hryvinskyi.  All rights reserved.
 * @author: <mailto:volodymyr@hryvinskyi.com>
 * @github: <https://github.com/hryvinskyi>
 */

declare(strict_types=1);

namespace Hryvinskyi\MagentoComposerQualityPatches\Config;

use Composer\Composer;

/**
 * Configuration reader for quality patches plugin
 *
 * Reads configuration from composer.json extra section
 */
class ConfigReader
{
    private const CONFIG_KEY = 'hryvinskyi-quality-patches';

    /**
     * @param Composer $composer The Composer instance
     */
    public function __construct(
        private readonly Composer $composer
    ) {
    }

    /**
     * Get plugin configuration from composer.json
     *
     * @return array{enabled: bool, patches: array<string>}
     */
    public function getConfig(): array
    {
        $extra = $this->composer->getPackage()->getExtra();
        $config = $extra[self::CONFIG_KEY] ?? [];

        return [
            'enabled' => $config['enabled'] ?? true,
            'patches' => $config['patches'] ?? [],
        ];
    }
}
