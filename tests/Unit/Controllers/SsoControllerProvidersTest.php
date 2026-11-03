<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\SsoController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;

/**
 * GET /auth/sso/providers reports password_login from the login.password_login
 * setting, which a plugin answering onApiLoginProviders can override. It is
 * always true while no valid provider is offered, so the login screen keeps a
 * way in, and a value that isn't clearly false keeps the form too.
 */
#[CoversClass(SsoController::class)]
class SsoControllerProvidersTest extends TestCase
{
    private const PROVIDER = ['id' => 'github', 'label' => 'GitHub', 'icon' => 'fa-github', 'plugin' => 'login-oauth2'];

    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool}>
     *         login config (empty: key missing), what the plugin sets on the event, expected password_login
     */
    public static function cases(): array
    {
        return [
            'setting off, no provider'               => [['password_login' => false], [], true],
            'setting off, with a provider'           => [['password_login' => false], ['providers' => [self::PROVIDER]], false],
            'setting on, with a provider'            => [['password_login' => true], ['providers' => [self::PROVIDER]], true],
            'setting missing, with a provider'       => [[], ['providers' => [self::PROVIDER]], true],
            'setting off as the string "false"'      => [['password_login' => 'false'], ['providers' => [self::PROVIDER]], false],
            'plugin sets false'                      => [['password_login' => true], ['providers' => [self::PROVIDER], 'password_login' => false], false],
            'plugin sets false, only provider bad'   => [['password_login' => true], ['providers' => [['label' => 'No id']], 'password_login' => false], true],
            'plugin sets the string "false"'         => [['password_login' => true], ['providers' => [self::PROVIDER], 'password_login' => 'false'], false],
            'plugin sets junk'                       => [['password_login' => false], ['providers' => [self::PROVIDER], 'password_login' => 'garbage'], true],
            'plugin sets null'                       => [['password_login' => false], ['providers' => [self::PROVIDER], 'password_login' => null], true],
        ];
    }

    /**
     * @param array<string, mixed> $login
     * @param array<string, mixed> $pluginSets
     */
    #[Test]
    #[DataProvider('cases')]
    public function password_login_follows_the_setting_and_plugins(array $login, array $pluginSets, bool $expected): void
    {
        $data = $this->providers($login, static function (Event $event) use ($pluginSets): void {
            foreach ($pluginSets as $key => $value) {
                $event[$key] = $value;
            }
        });

        self::assertSame($expected, $data['password_login']);
    }

    #[Test]
    public function plugins_see_the_setting_as_a_boolean(): void
    {
        $seen = 'not called';
        $this->providers(['password_login' => 'off'], static function (Event $event) use (&$seen): void {
            $seen = $event['password_login'];
        });

        self::assertFalse($seen);
    }

    #[Test]
    public function invalid_provider_entries_are_dropped(): void
    {
        $data = $this->providers([], static function (Event $event): void {
            $event['providers'] = [self::PROVIDER, ['label' => 'No id'], 'not an array'];
        });

        self::assertSame([self::PROVIDER], $data['providers']);
    }

    /**
     * Call the endpoint with the given login config and onApiLoginProviders
     * listener, and return the response's `data`.
     *
     * @param array<string, mixed> $login
     * @return array<string, mixed>
     */
    private function providers(array $login, callable $listener): array
    {
        $api = $login === [] ? [] : ['login' => $login];
        $config = new Config(['plugins' => ['api' => $api]]);
        $grav = TestHelper::createMockGrav(['config' => $config]);
        $grav->addListener('onApiLoginProviders', $listener);

        $controller = new SsoController(Grav::instance(), $config);
        $response = $controller->providers($this->createMock(ServerRequestInterface::class));

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);

        return $body['data'];
    }
}
