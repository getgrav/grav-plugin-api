<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Plugin\Api\Services\PathContainment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * realpath() returns '\' separators on Windows, so containment has to hold for
 * both separator styles without letting a sibling folder through (#66).
 */
#[CoversClass(PathContainment::class)]
class PathContainmentTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function unixCases(): iterable
    {
        yield 'unix file inside' => ['/var/www/grav/user/media/photo.jpg', '/var/www/grav/user/media', true];
        yield 'unix nested inside' => ['/var/www/grav/user/media/a/b.jpg', '/var/www/grav/user/media', true];
        yield 'unix base with trailing slash' => ['/var/www/grav/user/media/photo.jpg', '/var/www/grav/user/media/', true];
        yield 'unix sibling prefix' => ['/var/www/grav/user/media2/photo.jpg', '/var/www/grav/user/media', false];
        yield 'unix sibling dash' => ['/var/www/grav/user/media-private/x.jpg', '/var/www/grav/user/media', false];
        yield 'unix parent' => ['/var/www/grav/user', '/var/www/grav/user/media', false];
        yield 'unix outside' => ['/etc/passwd', '/var/www/grav/user/media', false];
        yield 'unix filesystem root base' => ['/etc/passwd', '/', true];
        // A backslash is a filename character on Unix, not a separator
        yield 'unix backslash sibling' => ['/var/www/grav/user/media\\evil/x.jpg', '/var/www/grav/user/media', false];
        yield 'unix empty path' => ['', '/var/www', false];
        yield 'unix empty base' => ['/var/www/a', '', false];
    }

    /** Windows, as realpath() returns it (the #66 report). @return iterable<string, array{string, string, bool}> */
    public static function windowsCases(): iterable
    {
        yield 'windows file inside' => ['C:\\xampp\\htdocs\\grav\\user\\media\\side-view.jpg', 'C:\\xampp\\htdocs\\grav\\user\\media', true];
        yield 'windows nested inside' => ['C:\\grav\\user\\media\\a\\b.jpg', 'C:\\grav\\user\\media', true];
        yield 'windows base with trailing separator' => ['C:\\grav\\user\\media\\a.jpg', 'C:\\grav\\user\\media\\', true];
        yield 'windows sibling prefix' => ['C:\\grav\\user\\media2\\a.jpg', 'C:\\grav\\user\\media', false];
        yield 'windows sibling dash' => ['C:\\grav\\user\\media-private\\a.jpg', 'C:\\grav\\user\\media', false];
        yield 'windows other drive' => ['D:\\grav\\user\\media\\a.jpg', 'C:\\grav\\user\\media', false];
        yield 'windows drive root base' => ['C:\\grav\\a.jpg', 'C:\\', true];

        // Mixed: a realpath() result against a locator path, either way round
        yield 'mixed backslash path, slash base' => ['C:\\grav\\user\\media\\a.jpg', 'C:/grav/user/media', true];
        yield 'mixed slash path, backslash base' => ['C:/grav/user/media/a.jpg', 'C:\\grav\\user\\media', true];
        yield 'mixed sibling prefix' => ['C:\\grav\\user\\media2\\a.jpg', 'C:/grav/user/media', false];
    }

    #[Test]
    #[DataProvider('unixCases')]
    public function unixContainment(string $path, string $base, bool $expected): void
    {
        self::assertSame($expected, PathContainment::isWithin($path, $base, false, '/'));
    }

    #[Test]
    #[DataProvider('windowsCases')]
    public function windowsContainment(string $path, string $base, bool $expected): void
    {
        self::assertSame($expected, PathContainment::isWithin($path, $base, false, '\\'));
    }

    #[Test]
    public function theBaseItselfCountsOnlyWhenAllowed(): void
    {
        self::assertFalse(PathContainment::isWithin('/var/www/grav/user', '/var/www/grav/user', false, '/'));
        self::assertTrue(PathContainment::isWithin('/var/www/grav/user', '/var/www/grav/user', true, '/'));
        self::assertTrue(PathContainment::isWithin('C:\\grav\\user\\', 'C:\\grav\\user', true, '\\'));
        self::assertTrue(PathContainment::isWithin('C:\\grav\\user', 'C:/grav/user/', true, '\\'));
        self::assertFalse(PathContainment::isWithin('C:\\grav\\user2', 'C:\\grav\\user', true, '\\'));
    }
}
