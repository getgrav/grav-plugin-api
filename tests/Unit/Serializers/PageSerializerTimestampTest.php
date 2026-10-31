<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Serializers;

use Grav\Plugin\Api\Serializers\PageSerializer;
use PHPUnit\Framework\TestCase;

final class PageSerializerTimestampTest extends TestCase
{
    private function format(mixed $value): ?string
    {
        return (new \ReflectionMethod(PageSerializer::class, 'formatTimestamp'))->invoke(new PageSerializer(), $value);
    }

    public function testOverlongDateTextIsIgnored(): void
    {
        self::assertNull($this->format('2026-10-31' . str_repeat(' ', 257)));
    }

    public function testOrdinaryDatesAndNumericTimestampsAreRetained(): void
    {
        self::assertSame('2026-10-31T00:00:00+00:00', $this->format('2026-10-31'));
        self::assertSame('2026-10-31T00:00:00+00:00', $this->format('2026-10-31' . str_repeat(' ', 246)));
        self::assertSame('2023-11-14T22:13:20+00:00', $this->format(1700000000));
        self::assertSame('2023-11-14T22:13:20+00:00', $this->format('1700000000'));
        self::assertNull($this->format('invalid date'));
    }
}
