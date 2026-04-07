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
 */
trait FlexBackend
{
    protected function isFlexEnabled(): bool
    {
        return $this->config->get('plugins.api.flex_backend.enabled', true)
            && isset($this->grav['flex_objects']);
    }

    protected function getFlexDirectory(string $type): ?FlexDirectory
    {
        if (!$this->isFlexEnabled()) {
            return null;
        }

        $flex = $this->grav['flex_objects'];
        $directory = $flex->getDirectory($type);

        return ($directory && $directory->isEnabled()) ? $directory : null;
    }
}
