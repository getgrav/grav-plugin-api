<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Plugin\Api\Controllers\UsersController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UsersControllerAccessKeysTest extends TestCase
{
    public static function mergeMaps(): array
    {
        return [
            [[ 'api' => ['<<' => ['super' => true]] ]],
            [[ '<<' => ['api' => ['super' => true]] ]],
            [[ 'api' => ['super' => true], 'other' => ['<<' => []] ]],
        ];
    }

    #[DataProvider('mergeMaps')]
    public function testMergeKeysAreRejected(array $access): void
    {
        $controller = (new \ReflectionClass(UsersController::class))->newInstanceWithoutConstructor();
        $this->expectException(ValidationException::class);
        (new \ReflectionMethod($controller, 'accessGrantsSuper'))->invoke($controller, $access);
    }
}
