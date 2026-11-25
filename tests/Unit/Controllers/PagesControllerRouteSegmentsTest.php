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
 *
 * `|` ends the folder path in a Flex page storage key, so a folder named with
 * one drops out of the page index, and the descendant checks of a delete
 * never see what is under it. It is refused in every new folder name: the
 * route of a create or copy, the slug of a move, and a batch copy's name.
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
            'pipe' => ['/docs/guide|x'],
            'pipe with an order prefix' => ['/docs/01.guide|x'],
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

    /** @return array<string, array{mixed}> */
    public static function invalidSegments(): array
    {
        return [
            'pipe' => ['guide|x'],
            'slash' => ['a/b'],
            'backslash' => ['a\\b'],
            'parent' => ['..'],
            'empty' => [''],
            'not a string' => [['x']],
        ];
    }

    #[Test]
    #[DataProvider('invalidSegments')]
    public function a_moved_or_copied_folder_name_is_one_plain_segment(mixed $value): void
    {
        $controller = (new \ReflectionClass(PagesController::class))->newInstanceWithoutConstructor();

        $this->expectException(ValidationException::class);
        (new ReflectionMethod(PagesController::class, 'assertSinglePathSegment'))->invoke($controller, $value, 'slug');
    }

    #[Test]
    #[DataProvider('validRoutes')]
    public function accepts_page_folder_names(string $route): void
    {
        $this->assertPageRoute($route);
        $this->addToAssertionCount(1);
    }
}
