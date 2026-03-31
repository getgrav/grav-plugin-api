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
            'twofa_enabled' => (bool) $resource->get('twofa_enabled', false),
            'twofa_secret' => $resource->get('twofa_secret') ? true : false,
            'created' => $this->formatTimestamp($resource->get('created')),
            'modified' => $this->formatTimestamp($resource->get('modified')),
        ];
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
