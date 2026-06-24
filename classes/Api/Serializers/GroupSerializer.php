<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Serializers;

use Grav\Common\Flex\Types\UserGroups\UserGroupObject;

class GroupSerializer implements SerializerInterface
{
    public function serialize(object $resource, array $options = []): array
    {
        /** @var UserGroupObject $resource */
        return [
            'groupname'    => (string) $resource->getProperty('groupname', ''),
            'readableName' => (string) ($resource->getProperty('readableName') ?? ''),
            'description'  => (string) ($resource->getProperty('description') ?? ''),
            'icon'         => (string) ($resource->getProperty('icon') ?? ''),
            'enabled'      => (bool) $resource->getProperty('enabled', true),
            // Cast to object so an empty access map serializes as `{}` and not a
            // JSON array `[]` — the permissions editor treats `[]` as a list and
            // can't add the first permission to it (admin2#58).
            'access'       => (object) ($resource->getProperty('access') ?? []),
        ];
    }

    /**
     * Serialize a plain array entry from groups.yaml (used by the fallback
     * non-Flex listing path, where there is no UserGroupObject yet).
     *
     * @param array<string,mixed> $entry
     */
    public function serializeArray(string $groupname, array $entry): array
    {
        return [
            'groupname'    => $groupname,
            'readableName' => (string) ($entry['readableName'] ?? ''),
            'description'  => (string) ($entry['description'] ?? ''),
            'icon'         => (string) ($entry['icon'] ?? ''),
            'enabled'      => (bool) ($entry['enabled'] ?? true),
            // See serialize(): empty access must be an object, not a JSON array.
            'access'       => (object) ($entry['access'] ?? []),
        ];
    }
}
