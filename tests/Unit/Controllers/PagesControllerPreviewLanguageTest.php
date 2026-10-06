<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for getgrav/grav-plugin-admin2#188.
 *
 * `POST /pages/{route}/preview-token` hands the admin the route its preview
 * loads. On a multi-language site that route has to carry the URL prefix the
 * front end serves the page's language under, and it has to be the route the
 * page has in THAT language: a French page with its own slug is
 * `/fr/typographie`, never the default page's `/typography` and never an
 * unprefixed `/typographie` (which the front end resolves in the default
 * language and 404s).
 */
#[CoversClass(PagesController::class)]
class PagesControllerPreviewLanguageTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . '/grav_api_preview_lang_' . bin2hex(random_bytes(4));
        mkdir($this->configDir . '/plugins', 0777, true);
        file_put_contents(
            $this->configDir . '/plugins/api-private.php',
            "<?php\nreturn 'preview-language-test-secret-long-enough-for-hs256-and-up-1234567890';\n"
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->configDir . '/plugins/api-private.php');
        @rmdir($this->configDir . '/plugins');
        @rmdir($this->configDir);
        Grav::resetInstance();
    }

    /**
     * @return array<string, array{0: bool, 1: ?string, 2: string}> includeDefault, ?lang, expected route
     */
    public static function languageCases(): array
    {
        return [
            'default language, prefix hidden, no lang' => [false, null, '/typography'],
            'default language, prefix hidden, lang=en' => [false, 'en', '/typography'],
            'default language, prefix shown, no lang' => [true, null, '/en/typography'],
            'default language, prefix shown, lang=en' => [true, 'en', '/en/typography'],
            'translation, prefix hidden for default' => [false, 'fr', '/fr/typographie'],
            'translation, prefix shown for default' => [true, 'fr', '/fr/typographie'],
        ];
    }

    #[Test]
    #[DataProvider('languageCases')]
    public function the_route_carries_the_language_prefix_and_the_translated_route(
        bool $includeDefault,
        ?string $lang,
        string $expected,
    ): void {
        $route = $lang === 'fr' ? '/typographie' : '/typography';

        self::assertSame($expected, $this->previewRoute($route, $lang, $includeDefault, true));
    }

    #[Test]
    public function a_module_previews_inside_its_parent_with_the_language_prefix(): void
    {
        // The module's own route is /modtest/_mod; the page to load is its parent.
        self::assertSame(
            '/fr/modtest',
            $this->previewRoute('/modtest/_mod', 'fr', false, true, '/modtest'),
        );
    }

    #[Test]
    public function a_single_language_site_gets_the_plain_route(): void
    {
        self::assertSame('/typography', $this->previewRoute('/typography', null, false, false));
    }

    #[Test]
    public function the_requested_language_does_not_stay_active_after_the_call(): void
    {
        $grav = $this->grav(false, true);
        $language = $grav['language'];
        $language->setActive('en');

        $this->callPreviewToken($grav, '/typographie', 'fr', '/typographie');

        self::assertSame('en', $language->getActive());
    }

    /**
     * Run the real previewToken() against stubbed pages and return the `route`
     * it answers with.
     */
    private function previewRoute(
        string $requestedRoute,
        ?string $lang,
        bool $includeDefault,
        bool $multiLang,
        ?string $targetRoute = null,
    ): string {
        $grav = $this->grav($includeDefault, $multiLang);

        return $this->callPreviewToken($grav, $requestedRoute, $lang, $targetRoute ?? $requestedRoute);
    }

    private function grav(bool $includeDefault, bool $multiLang): Grav
    {
        $config = new Config([
            'plugins' => ['api' => ['allow_draft_preview' => true, 'preview_token_ttl' => 300]],
            'system' => ['languages' => [
                'supported' => $multiLang ? ['en', 'fr'] : [],
                'default_lang' => 'en',
                'include_default_lang' => $includeDefault,
            ]],
        ]);

        $locator = new class ($this->configDir) {
            public function __construct(private readonly string $dir) {}

            public function findResource(string $uri, bool $absolute = false, bool $first = false): string
            {
                return $this->dir;
            }
        };

        $grav = TestHelper::createMockGrav([
            'config' => $config,
            'permissions' => new Permissions(),
            'locator' => $locator,
            // Language::setActive() logs the switch to the debugger.
            'debugger' => new class {
                public function addMessage(mixed $message, string $label = 'info'): void {}
            },
        ]);
        $grav['language'] = new Language($grav);

        return $grav;
    }

    private function callPreviewToken(Grav $grav, string $requestedRoute, ?string $lang, string $targetRoute): string
    {
        // The page asked for. Its own route is what the token is pinned to.
        $page = $this->page($requestedRoute, false);
        // A module renders inside its parent; an ordinary page is its own target.
        $target = $targetRoute === $requestedRoute ? $page : $this->page($targetRoute, false);
        if ($target !== $page) {
            $page = $this->page($requestedRoute, true, $target);
        }

        $grav['pages'] = new class ($page) {
            public function __construct(private readonly PageInterface $page) {}
            public function enablePages(): void {}
            public function reset(): void {}
            public function find(string $route): ?PageInterface { return $this->page; }
            public function instances(): array { return []; }
        };

        $user = TestHelper::createMockUser('tester', ['access' => ['api' => ['login' => true, 'super' => true]]]);
        $request = TestHelper::createMockRequest(
            method: 'POST',
            path: '/api/v1/pages' . $requestedRoute . '/preview-token',
            queryParams: $lang === null ? [] : ['lang' => $lang],
            attributes: ['api_user' => $user, 'route_params' => ['route' => ltrim($requestedRoute, '/')]],
        );

        $controller = new PagesController($grav, $grav['config']);
        $response = $controller->previewToken($request);
        $body = json_decode((string) $response->getBody(), true);

        return $body['data']['route'];
    }

    private function page(string $route, bool $isModule, ?PageInterface $parent = null): PageInterface
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('route')->willReturn($route);
        $page->method('path')->willReturn('/pages/' . trim($route, '/'));
        $page->method('isModule')->willReturn($isModule);
        $page->method('root')->willReturn(false);
        $page->method('parent')->willReturn($parent);
        $page->method('menu')->willReturn('Mod');

        return $page;
    }
}
