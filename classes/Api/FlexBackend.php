<?php

declare(strict_types=1);

namespace Grav\Plugin\Api;

use Grav\Framework\Flex\FlexDirectory;

/**
 * Trait for controllers that optionally use Flex-Objects backend
 * for listing/search operations.
 *
 * When enabled (default), listing endpoints use flex directories for
 * indexed search, filtering, sorting, and pagination. When disabled
 * or unavailable, controllers fall back to regular Grav services.
 *
 * Config keys: plugins.api.flex_backend.pages, plugins.api.flex_backend.accounts
 */
trait FlexBackend
{
    /**
     * Map flex directory types to their config keys.
     */
    private const FLEX_CONFIG_MAP = [
        'pages' => 'pages',
        'user-accounts' => 'accounts',
    ];

    protected function getFlexDirectory(string $type): ?FlexDirectory
    {
        $configKey = self::FLEX_CONFIG_MAP[$type] ?? $type;
        if (!$this->config->get('plugins.api.flex_backend.' . $configKey, true)) {
            return null;
        }

        if (!isset($this->grav['flex_objects'])) {
            return null;
        }

        $flex = $this->grav['flex_objects'];
        $directory = $flex->getDirectory($type);

        return ($directory && $directory->isEnabled()) ? $directory : null;
    }
}
