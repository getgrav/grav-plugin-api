<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Controllers\SidebarController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(SidebarController::class)]
class SidebarControllerTranslationTest extends TestCase
{
    #[Test]
    public function translates_plugin_label_with_the_users_admin_language(): void
    {
        $language = new SidebarTranslationTestLanguage();

        $user = $this->createMock(UserInterface::class);
        $user->method('get')->willReturnCallback(
            static fn($key, mixed $default = null) => match ($key) {
                'access.api.super' => true,
                'admin_next' => ['preferences' => ['adminLanguage' => 'en-US']],
                default => $default,
            },
        );

        $grav = $this->createMock(Grav::class);
        $grav->method('offsetGet')->willReturnCallback(
            static fn($key) => match ($key) {
                'language' => $language,
                'locator' => new SidebarTranslationTestLocator(),
                default => null,
            },
        );
        $grav->method('fireEvent')->willReturnCallback(
            static function ($name, $event): object {
                if ($name === 'onApiSidebarItems') {
                    $event['items'] = [[
                        'id' => 'example',
                        'label' => 'PLUGIN_EXAMPLE.ITEMS',
                    ]];
                }

                return $event;
            },
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn($name, $default = null) => $name === 'api_user' ? $user : $default,
        );

        $response = (new SidebarController($grav, new Config([])))->items($request);
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame('Example items', $payload['data'][0]['label']);
    }
}

final class SidebarTranslationTestLocator
{
    public function findResource(string $uri, bool $absolute = true, bool $create = false): ?string
    {
        return null;
    }
}

final class SidebarTranslationTestLanguage
{
    public function translate($key, $languages = null, bool $arraySupport = false)
    {
        if ($key === 'ICU.PLUGIN_EXAMPLE.ITEMS' && $languages[0] === 'en-US') {
            return 'Example items';
        }

        return $key;
    }

    public function getLanguage(): string
    {
        return 'ru';
    }
}
