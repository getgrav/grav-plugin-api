<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Exceptions;

/**
 * Thrown when a demo account (access.api.demo) attempts an action that demo
 * mode forbids — a write outside the writable allowlist, or a read of
 * path/secret-revealing operational data.
 *
 * Carries the stable `demo_mode_write_blocked` code so Admin Next can render a
 * friendly, localized toast without matching on the human-facing title.
 */
class DemoModeException extends ApiException
{
    public const CODE = 'demo_mode_write_blocked';

    public function __construct(string $detail = 'This is a read-only demo. Changes are disabled.', ?\Throwable $previous = null)
    {
        parent::__construct(403, 'Demo Mode', $detail, [], $previous, self::CODE);
    }
}
