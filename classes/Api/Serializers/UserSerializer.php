<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Serializers;

use DateTimeImmutable;
use DateTimeZone;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Services\PathContainment;

class UserSerializer implements SerializerInterface
{
    public function serialize(object $resource, array $options = []): array
    {
        /** @var UserInterface $resource */
        $data = [
            'username' => $resource->username,
            'email' => $resource->get('email'),
            'fullname' => $resource->get('fullname'),
            'title' => $resource->get('title'),
            'state' => $resource->get('state', 'enabled'),
            'language' => $resource->get('language', ''),
            'content_editor' => $resource->get('content_editor', ''),
            // Cast to object so an empty access map serializes as `{}` and not a
            // JSON array `[]`, which the permissions editor can't add to (admin2#58).
            'access' => (object) $resource->get('access', []),
            'groups' => array_values(array_filter(
                (array) $resource->get('groups', []),
                'is_string',
            )),
            'avatar_url' => self::resolveAvatarUrl($resource),
            'twofa_enabled' => (bool) $resource->get('twofa_enabled', false),
            'twofa_secret' => $resource->get('twofa_secret') ? true : false,
            // How many passkeys the account holds (the list: GET /users/{u}/passkeys).
            'passkeys' => count(array_filter((array) $resource->get('passkeys', []), 'is_array')),
            'created' => $this->formatTimestamp($resource->get('created')),
            'modified' => $this->formatTimestamp($resource->get('modified')),
        ];

        // A new address the Login plugin is waiting to confirm. Left out when
        // there is none, so other clients see an unchanged resource.
        $pending = (string) $resource->get('email_pending', '');
        if ($pending !== '') {
            $data['email_pending'] = $pending;
        }

        return $data;
    }

    /**
     * Resolve the avatar URL for a user.
     * Returns the URL to an uploaded avatar, or null if none exists.
     */
    public static function resolveAvatarUrl(UserInterface $resource): ?string
    {
        // Flex-backed users can keep avatar metadata relative to their own
        // media folder. Resolve through the user abstraction first so the API
        // does not need to know that storage layout.
        $path = $resource->getAvatarImage()?->get('filepath');
        if (is_string($path) && is_file($path)) {
            return self::thumbnailUrl($path);
        }

        $avatar = $resource->get('avatar');

        // Avatar is stored as { filename: { name, type, size, path } } or similar
        if (is_array($avatar) && !empty($avatar)) {
            $first = reset($avatar);
            if (is_array($first)) {
                $filePath = self::storedAvatarFile($first['path'] ?? null);
                if ($filePath !== null) {
                    return self::thumbnailUrl($filePath);
                }
            }
        }

        return null;
    }

    /**
     * The file a stored avatar `path` points at (relative to the Grav root, e.g.
     * `user/accounts/avatars/file.jpg`), when it is a file inside $folder. The
     * path is data from the account file, so a file anywhere else is never used.
     *
     * @param mixed  $path   The stored `path` value.
     * @param string $folder Locator URI of the folder the file has to be in.
     * @return string|null The resolved path, or null.
     */
    public static function storedAvatarFile(mixed $path, string $folder = 'account://'): ?string
    {
        if (!is_string($path) || $path === '' || str_contains($path, "\0")) {
            return null;
        }

        $base = \Grav\Common\Grav::instance()['locator']->findResource($folder, true);
        $base = is_string($base) ? realpath($base) : false;
        $file = realpath(GRAV_ROOT . '/' . $path);
        if ($base === false || $file === false || !is_file($file) || !PathContainment::isWithin($file, $base)) {
            return null;
        }

        return $file;
    }

    private static function thumbnailUrl(string $filePath): ?string
    {
        $thumbService = \Grav\Plugin\Api\Services\ThumbnailService::forGrav(\Grav\Common\Grav::instance(), 200);
        $filename = $thumbService->thumbnailFilename($filePath);
        if (!$filename) {
            return null;
        }

        $config = \Grav\Common\Grav::instance()['config'];
        $route = $config->get('plugins.api.route', '/api');
        $prefix = $config->get('plugins.api.version_prefix', 'v1');

        return $route . '/' . $prefix . '/thumbnails/' . $filename;
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
