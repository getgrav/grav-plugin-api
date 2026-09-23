<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Controllers\AuthController;
use Grav\Plugin\Api\Controllers\BootController;
use Grav\Plugin\Api\Controllers\ContextPanelController;
use Grav\Plugin\Api\Controllers\FloatingWidgetController;
use Grav\Plugin\Api\Controllers\GpmController;
use Grav\Plugin\Api\Controllers\MenubarController;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Controllers\PreferencesController;
use Grav\Plugin\Api\Controllers\SidebarController;
use Grav\Plugin\Api\Controllers\SystemController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;

/**
 * GET /admin-next/boot returns each part exactly as its own endpoint's `data`,
 * and a part the caller may not read (or one that fails) lands in `errors`
 * without taking the rest down.
 */
#[CoversClass(BootController::class)]
class BootControllerTest extends TestCase
{
    private string $cacheDir;
    private Config $config;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/api-boot-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0775, true);

        $this->config = new Config(['plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']]]);
        $grav = TestHelper::createMockGrav([
            'config' => $this->config,
            'permissions' => new Permissions(),
            'language' => new BootTestLanguage(),
            'locator' => new BootTestLocator($this->cacheDir),
        ]);

        $grav->addListener('onApiMenubarItems', static function (Event $event): void {
            $event['items'] = [
                ['id' => 'warm', 'plugin' => 'warm-cache', 'action' => 'warm', 'label' => 'Warm'],
                ['id' => 'deploy', 'plugin' => 'git-sync', 'action' => 'sync', 'authorize' => 'api.system.write'],
            ];
        });
        $grav->addListener('onApiSidebarItems', static function (Event $event): void {
            $event['items'] = [
                ['id' => 'low', 'label' => 'Low', 'priority' => 1],
                ['id' => 'high', 'label' => 'High', 'priority' => 9, 'authorize' => 'api.pages.read'],
            ];
        });
        $grav->addListener('onApiFloatingWidgets', static function (Event $event): void {
            $event['widgets'] = [['id' => 'chat', 'routes' => ['users/', '', 7]]];
        });
        $grav->addListener('onApiContextPanels', static function (Event $event): void {
            $event['panels'] = [['id' => 'seo', 'priority' => 2]];
        });
    }

    protected function tearDown(): void
    {
        Grav::resetInstance();
        foreach (array_reverse($this->tree($this->cacheDir)) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->cacheDir);
    }

    #[Test]
    public function each_part_is_byte_identical_to_its_endpoint(): void
    {
        $request = $this->request($this->editor());
        $boot = (string) (new BootTestController(Grav::instance(), $this->config))->show($request)->getBody();

        $endpoints = [
            'preferences' => fn () => (new PreferencesController(Grav::instance(), $this->config))->show($request),
            'me' => fn () => (new AuthController(Grav::instance(), $this->config))->me($request),
            'menubar' => fn () => (new MenubarController(Grav::instance(), $this->config))->items($request),
            'sidebar' => fn () => (new SidebarController(Grav::instance(), $this->config))->items($request),
            'floating_widgets' => fn () => (new FloatingWidgetController(Grav::instance(), $this->config))->items($request),
            'context_panels' => fn () => (new ContextPanelController(Grav::instance(), $this->config))->items($request),
            'custom_fields' => fn () => (new BootTestGpmController(Grav::instance(), $this->config))->allCustomFields($request),
            'languages' => fn () => (new PagesController(Grav::instance(), $this->config))->siteLanguages($request),
        ];

        foreach ($endpoints as $part => $endpoint) {
            $body = (string) $endpoint()->getBody();
            self::assertStringStartsWith('{"data":', $body, $part);
            $data = substr($body, strlen('{"data":'), -1);

            self::assertStringContainsString('"' . $part . '":' . $data, $boot, "boot's {$part} differs from its endpoint");
        }

        $decoded = json_decode($boot, true);
        self::assertSame(array_merge(array_keys($endpoints), ['translations']), array_keys($decoded['data']));
        self::assertSame([], $decoded['errors']);
        self::assertStringEndsWith(',"errors":{}}', $boot, 'errors is an object even when empty');
    }

    #[Test]
    public function translations_checksum_follows_query_then_admin_language_then_site_default(): void
    {
        $controller = new BootTestController(Grav::instance(), $this->config);
        $part = fn (ServerRequestInterface $r): array => json_decode((string) $controller->show($r)->getBody(), true)['data']['translations'];

        // The user's admin language (preferences), normalized like /translations/{lang}.
        self::assertSame(['lang' => 'fr-FR', 'checksum' => 'sum:fr-FR'], $part($this->request($this->editor(['adminLanguage' => 'fr']))));

        // ?lang= wins.
        self::assertSame(['lang' => 'de-DE', 'checksum' => 'sum:de-DE'], $part($this->request($this->editor(['adminLanguage' => 'fr']), ['lang' => 'de'])));

        // A malformed code falls back to the site default, exactly as /translations does.
        self::assertSame(['lang' => 'es-ES', 'checksum' => 'sum:es-ES'], $part($this->request($this->editor(), ['lang' => '../etc'])));
    }

    #[Test]
    public function a_part_the_user_may_not_read_goes_to_errors_and_the_rest_still_arrive(): void
    {
        // api.access only: /languages needs api.pages.read.
        $user = TestHelper::createMockUser('viewer', ['access' => ['api' => ['access' => true]]]);
        $body = json_decode((string) (new BootTestController(Grav::instance(), $this->config))->show($this->request($user))->getBody(), true);

        self::assertArrayNotHasKey('languages', $body['data']);
        self::assertSame(['languages' => ['status' => 403, 'title' => 'Forbidden']], $body['errors']);
        foreach (['preferences', 'me', 'menubar', 'sidebar', 'floating_widgets', 'context_panels', 'custom_fields', 'translations'] as $part) {
            self::assertArrayHasKey($part, $body['data'], $part);
        }
        // The sidebar item that needs api.pages.read is filtered, as on /sidebar/items.
        self::assertSame(['low'], array_column($body['data']['sidebar'], 'id'));
    }

    #[Test]
    public function a_failing_part_is_reported_without_failing_the_response(): void
    {
        $controller = new BootTestController(Grav::instance(), $this->config);
        $controller->failGpm = true;

        $response = $controller->show($this->request($this->editor()));
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['custom_fields' => ['status' => 500, 'title' => 'Internal Server Error']], $body['errors']);
        self::assertArrayNotHasKey('custom_fields', $body['data']);
        self::assertArrayHasKey('languages', $body['data']);
    }

    #[Test]
    public function a_caller_without_api_access_is_refused_outright(): void
    {
        $user = TestHelper::createMockUser('nobody', ['access' => ['site' => ['login' => true]]]);

        $this->expectException(ForbiddenException::class);
        (new BootTestController(Grav::instance(), $this->config))->show($this->request($user));
    }

    /**
     * @param array<string, mixed> $preferences
     */
    private function editor(array $preferences = []): UserInterface
    {
        return TestHelper::createMockUser('editor', [
            'access' => ['api' => ['access' => true, 'pages' => ['read' => true]]],
            'admin_next' => ['preferences' => $preferences],
        ]);
    }

    /**
     * @param array<string, string> $query
     */
    private function request(UserInterface $user, array $query = []): ServerRequestInterface
    {
        return TestHelper::createMockRequest(queryParams: $query, attributes: ['api_user' => $user]);
    }

    /** @return list<string> */
    private function tree(string $dir): array
    {
        $paths = [];
        foreach (glob($dir . '/*') ?: [] as $path) {
            $paths[] = $path;
            if (is_dir($path)) {
                $paths = array_merge($paths, $this->tree($path));
            }
        }

        return $paths;
    }
}

/**
 * The real boot controller, with the two parts that need a full Grav install
 * (GPM's package scan and the translations dictionary build) stood in for.
 */
final class BootTestController extends BootController
{
    public bool $failGpm = false;

    protected function controller(string $class): AbstractApiController
    {
        if ($class === GpmController::class) {
            $gpm = new BootTestGpmController($this->grav, $this->config);
            $gpm->fail = $this->failGpm;

            return $gpm;
        }
        if ($class === SystemController::class) {
            return new BootTestSystemController($this->grav, $this->config);
        }

        return parent::controller($class);
    }
}

final class BootTestGpmController extends GpmController
{
    public bool $fail = false;

    public function allCustomFieldsData(ServerRequestInterface $request): array
    {
        $this->requirePermission($request, 'api.access');
        if ($this->fail) {
            throw new \RuntimeException('GPM exploded');
        }

        return ['color-picker' => ['slug' => 'colors', 'kind' => 'plugins']];
    }

    public function allCustomFields(ServerRequestInterface $request): ResponseInterface
    {
        return \Grav\Plugin\Api\Response\ApiResponse::create($this->allCustomFieldsData($request));
    }
}

final class BootTestSystemController extends SystemController
{
    public function translationsChecksum(string $lang, ?string $prefix = null): string
    {
        return 'sum:' . $lang;
    }
}

final class BootTestLanguage
{
    public function enabled(): bool { return true; }

    /** @return list<string> */
    public function getLanguages(): array { return ['es', 'en']; }

    public function getDefault(): string { return 'es'; }

    public function getActive(): ?string { return null; }

    public function getLanguage(): string { return 'es'; }

    public function translate($key, $languages = null, bool $arraySupport = false)
    {
        return $key;
    }
}

final class BootTestLocator
{
    public function __construct(private readonly string $base) {}

    public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false
    {
        return str_starts_with($uri, 'cache://') ? $this->base . '/' . substr($uri, strlen('cache://')) : false;
    }

    /** @return array<int, string> */
    public function findResources(string $uri, bool $absolute = true, bool $all = false): array
    {
        return [];
    }
}
