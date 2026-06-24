<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Serializers;

use Grav\Plugin\Api\Serializers\GroupSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An empty access map must serialize to a JSON object (`{}`), not a JSON array
 * (`[]`). PHP can't tell an empty map from an empty list, so without the cast an
 * empty `access` becomes `[]` and the admin permissions editor — which treats a
 * value as a list — can no longer add the first permission to it (admin2#58).
 */
#[CoversClass(GroupSerializer::class)]
class GroupSerializerAccessTest extends TestCase
{
    #[Test]
    public function empty_access_serializes_as_json_object(): void
    {
        $out = (new GroupSerializer())->serializeArray('editors', ['enabled' => true]);

        $this->assertIsObject($out['access']);
        $this->assertSame('{}', json_encode($out['access']));
    }

    #[Test]
    public function populated_access_round_trips_as_object(): void
    {
        $out = (new GroupSerializer())->serializeArray('editors', [
            'access' => ['api' => ['pages' => true, 'media' => false]],
        ]);

        $this->assertSame(
            '{"api":{"pages":true,"media":false}}',
            json_encode($out['access']),
        );
    }
}
