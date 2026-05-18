<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Exceptions;

/**
 * 403 forbidden, dedicated to the `security.twig_content.*` gate. The
 * `errorTitle` field carries a stable machine-readable reason code that
 * Admin Next can switch on to render the right toast.
 */
class TwigContentForbiddenException extends ApiException
{
    /** Site-wide gate is off; nobody can enable Twig in content. */
    public const REASON_DISABLED = 'TWIG_CONTENT_DISABLED';

    /** Gate is on, but the current user is not allowed to toggle Twig on pages. */
    public const REASON_FORBIDDEN = 'TWIG_CONTENT_FORBIDDEN';

    /** Page already has process.twig:true; the current user cannot edit it. */
    public const REASON_PAGE_FORBIDDEN = 'TWIG_CONTENT_PAGE_FORBIDDEN';

    public function __construct(string $reason, string $detail = '', ?\Throwable $previous = null)
    {
        if ($detail === '') {
            $detail = match ($reason) {
                self::REASON_DISABLED => 'Twig processing in page content is disabled site-wide. An administrator can enable it under Configuration > Security > Twig in Content.',
                self::REASON_FORBIDDEN => "You don't have permission to enable Twig processing on pages.",
                self::REASON_PAGE_FORBIDDEN => "This page has Twig processing enabled in its content. You don't have permission to edit pages with Twig enabled.",
                default => 'Twig in content is not allowed.',
            };
        }

        parent::__construct(403, $reason, $detail, [], $previous);
    }
}
