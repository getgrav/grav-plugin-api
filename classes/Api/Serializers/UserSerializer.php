<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Serializers;

use DateTimeImmutable;
use DateTimeZone;
use Grav\Common\User\Interfaces\UserInterface;

class UserSerializer implements SerializerInterface
{
    public function serialize(object $resource, array $options = []): array
    {
        /** @var UserInterface $resource */
        return [
            'username' => $resource->username,
            'email' => $resource->get('email'),
            'fullname' => $resource->get('fullname'),
            'title' => $resource->get('title'),
            'state' => $resource->get('state', 'enabled'),
            'access' => $resource->get('access', []),
            'avatar_url' => $this->resolveAvatarUrl($resource),
            'twofa_enabled' => (bool) $resource->get('twofa_enabled', false),
            'twofa_secret' => $resource->get('twofa_secret') ? true : false,
            'created' => $this->formatTimestamp($resource->get('created')),
            'modified' => $this->formatTimestamp($resource->get('modified')),
        ];
    }

    /**
     * Resolve the avatar URL for a user.
     * Returns the URL to an uploaded avatar, or null if none exists.
     */
    private function resolveAvatarUrl(UserInterface $resource): ?string
    {
        $avatar = $resource->get('avatar');

        // Avatar is stored as { filename: { name, type, size, path } } or similar
        if (is_array($avatar) && !empty($avatar)) {
            $first = reset($avatar);
            if (is_array($first) && isset($first['path'])) {
                // Path relative to account://
                $locator = \Grav\Common\Grav::instance()['locator'];
                $basePath = $locator->findResource('account://');
                $filePath = $basePath . '/' . $first['path'];

                if (file_exists($filePath)) {
                    // Generate a thumbnail URL via the thumbnail service
                    $cacheDir = $locator->findResource('cache://', true, true) . '/api/thumbnails';
                    $thumbService = new \Grav\Plugin\Api\Services\ThumbnailService($cacheDir, 200);
                    $filename = $thumbService->getThumbnailFilename($filePath);
                    if ($filename) {
                        $thumbService->getThumbnail($filePath);
                        $config = \Grav\Common\Grav::instance()['config'];
                        $route = $config->get('plugins.api.route', '/api');
                        $prefix = $config->get('plugins.api.version_prefix', 'v1');
                        return $route . '/' . $prefix . '/thumbnails/' . $filename;
                    }
                }
            }
        }

        return null;
    }

    private function formatTimestamp(mixed $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === 0) {
            return null;
        }

        return (new DateTimeImmutable('@' . (int) $timestamp))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DateTimeImmutable::ATOM);
    }
}
