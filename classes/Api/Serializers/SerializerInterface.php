<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Serializers;

interface SerializerInterface
{
    public function serialize(object $resource, array $options = []): array;
}
