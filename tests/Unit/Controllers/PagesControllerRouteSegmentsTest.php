<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The route a page is created or copied to is made of page folder names: its
 * last segment becomes the new folder, so `.` and `..` are refused.
 */
#[CoversClass(PagesController::class)]
class PagesControllerRouteSegmentsTest extends TestCase
{
    private function assertPageRoute(string $route): void
    {
        (new ReflectionMethod(PagesController::class, 'assertPageRoute'))->invoke(null, $route);
    }

    /** @return array<string, array{string}> */
    public static function invalidRoutes(): array
    {
        return [
            'parent of the root' => ['/..'],
            'parent of a page' => ['/blog/..'],
            'current folder' => ['/blog/.'],
            'dot segment in the parent' => ['/blog/../post'],
            'hidden folder' => ['/blog/.post'],
            'backslash' => ['/blog/..\\post'],
            'null byte' => ["/blog/post\0"],
        ];
    }

    #[Test]
    #[DataProvider('invalidRoutes')]
    public function refuses_a_route_that_is_not_folder_names(string $route): void
    {
        $this->expectException(ValidationException::class);
        $this->assertPageRoute($route);
    }

    /** @return array<string, array{string}> */
    public static function validRoutes(): array
    {
        return [
            'top level' => ['/blog'],
            'nested' => ['/blog/my-post'],
            'order prefix' => ['/blog/01.post'],
            'module' => ['/home/_hero'],
            'dots inside a name' => ['/docs/v1.2'],
        ];
    }

    #[Test]
    #[DataProvider('validRoutes')]
    public function accepts_page_folder_names(string $route): void
    {
        $this->assertPageRoute($route);
        $this->addToAssertionCount(1);
    }
}
