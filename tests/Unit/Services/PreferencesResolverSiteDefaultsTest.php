<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Services\PreferencesResolver;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PreferencesResolver::class)]
class PreferencesResolverSiteDefaultsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/grav_api_prefs_' . uniqid();
        mkdir($this->root . '/user/config', 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/user/config/admin-next.yaml');
        @rmdir($this->root . '/user/config');
        @rmdir($this->root . '/user');
        @rmdir($this->root);
        Grav::resetInstance();
    }

    #[Test]
    public function saving_site_defaults_merges_instead_of_replacing(): void
    {
        $resolver = $this->resolver();

        $resolver->saveSitePreferences(['accentHue' => 120, 'fontFamily' => 'inter']);
        $resolver->saveSitePreferences(['fontSize' => 'large']);

        $site = $resolver->sitePreferences();
        self::assertSame(120, $site['accentHue']);
        self::assertSame('inter', $site['fontFamily']);
        self::assertSame('large', $site['fontSize']);
    }

    #[Test]
    public function null_clears_a_saved_site_default(): void
    {
        $resolver = $this->resolver();

        $resolver->saveSitePreferences(['accentHue' => 120, 'fontFamily' => 'inter']);
        $resolver->saveSitePreferences(['accentHue' => null]);

        $site = $resolver->sitePreferences();
        self::assertSame(271, $site['accentHue']);
        self::assertSame('inter', $site['fontFamily']);
    }

    #[Test]
    public function the_sidebar_credit_shows_until_a_site_turns_it_off(): void
    {
        $resolver = $this->resolver();

        self::assertTrue($resolver->siteBranding()['showCredit']);

        $resolver->saveSiteBranding(array_replace($resolver->siteBranding(), ['showCredit' => false]));
        self::assertFalse($resolver->siteBranding()['showCredit']);
        self::assertTrue($resolver->siteBranding()['showPoweredBy']);

        $resolver->saveSiteBranding(array_replace($resolver->siteBranding(), ['showCredit' => 'true']));
        self::assertTrue($resolver->siteBranding()['showCredit']);
    }

    #[Test]
    public function dark_shade_defaults_to_graphite(): void
    {
        $resolver = $this->resolver();

        self::assertSame('graphite', $resolver->defaultPreferences()['darkShade']);
        self::assertSame('graphite', $resolver->sitePreferences()['darkShade']);
    }

    #[Test]
    public function a_site_can_default_to_another_dark_shade(): void
    {
        $resolver = $this->resolver();

        $resolver->saveSitePreferences(['darkShade' => 'midnight']);
        self::assertSame('midnight', $resolver->sitePreferences()['darkShade']);

        $resolver->saveSitePreferences(['darkShade' => 'zinc']);
        self::assertSame('zinc', $resolver->sitePreferences()['darkShade']);

        $resolver->saveSitePreferences(['darkShade' => null]);
        self::assertSame('graphite', $resolver->sitePreferences()['darkShade']);
    }

    #[Test]
    public function an_unknown_site_dark_shade_is_dropped_and_falls_back_to_graphite(): void
    {
        $resolver = $this->resolver();

        $resolver->saveSitePreferences(['darkShade' => 'zinc']);
        $resolver->saveSitePreferences(['darkShade' => 'neon']);

        self::assertSame('graphite', $resolver->sitePreferences()['darkShade']);

        // A bad value that reached the file some other way reads back as Graphite too.
        file_put_contents($this->root . '/user/config/admin-next.yaml', "ui:\n  defaults:\n    darkShade: neon\n");
        self::assertSame('graphite', $resolver->sitePreferences()['darkShade']);
    }

    #[Test]
    public function a_user_dark_shade_overrides_the_site_default(): void
    {
        $resolver = $this->resolver();
        $resolver->saveSitePreferences(['darkShade' => 'zinc']);
        $user = TestHelper::createMockUser();

        self::assertSame('zinc', $resolver->resolve($user, false)['effective']['darkShade']);

        $resolver->saveUserPreferences($user, ['darkShade' => 'midnight']);
        $payload = $resolver->resolve($user, false);
        self::assertSame('midnight', $payload['effective']['darkShade']);
        self::assertSame('midnight', $payload['user']['darkShade']);
        self::assertSame('zinc', $payload['site']['darkShade']);

        // null removes the override and the site default applies again.
        $resolver->saveUserPreferences($user, ['darkShade' => null]);
        self::assertSame('zinc', $resolver->resolve($user, false)['effective']['darkShade']);
    }

    #[Test]
    public function an_unknown_user_dark_shade_is_not_saved(): void
    {
        $resolver = $this->resolver();
        $user = TestHelper::createMockUser();

        $resolver->saveUserPreferences($user, ['darkShade' => 'midnight']);
        $resolver->saveUserPreferences($user, ['darkShade' => 'neon']);

        self::assertSame('midnight', $resolver->resolve($user, false)['effective']['darkShade']);
    }

    #[Test]
    public function an_unknown_dark_shade_already_in_an_account_file_is_ignored(): void
    {
        $resolver = $this->resolver();
        $resolver->saveSitePreferences(['darkShade' => 'zinc']);
        $user = TestHelper::createMockUser();
        $user->set('admin_next', ['preferences' => ['darkShade' => 'neon']]);

        self::assertSame('zinc', $resolver->resolve($user, false)['effective']['darkShade']);
    }

    #[Test]
    public function help_mode_defaults_to_tooltip(): void
    {
        $resolver = $this->resolver();

        self::assertSame('tooltip', $resolver->defaultPreferences()['helpMode']);
        self::assertSame('tooltip', $resolver->sitePreferences()['helpMode']);
        self::assertSame('tooltip', $resolver->resolve(TestHelper::createMockUser(), false)['effective']['helpMode']);
    }

    #[Test]
    public function a_site_can_default_to_inline_help(): void
    {
        $resolver = $this->resolver();

        $resolver->saveSitePreferences(['helpMode' => 'inline']);
        self::assertSame('inline', $resolver->sitePreferences()['helpMode']);
        self::assertSame('inline', $resolver->resolve(TestHelper::createMockUser(), false)['effective']['helpMode']);

        $resolver->saveSitePreferences(['helpMode' => null]);
        self::assertSame('tooltip', $resolver->sitePreferences()['helpMode']);
    }

    #[Test]
    public function an_unknown_site_help_mode_is_dropped_and_falls_back_to_tooltip(): void
    {
        $resolver = $this->resolver();

        $resolver->saveSitePreferences(['helpMode' => 'inline']);
        $resolver->saveSitePreferences(['helpMode' => 'popup']);

        self::assertSame('tooltip', $resolver->sitePreferences()['helpMode']);

        // A bad value that reached the file some other way reads back as tooltip.
        file_put_contents($this->root . '/user/config/admin-next.yaml', "ui:\n  defaults:\n    helpMode: popup\n");
        self::assertSame('tooltip', $resolver->sitePreferences()['helpMode']);
        self::assertSame('tooltip', $resolver->resolve(TestHelper::createMockUser(), false)['effective']['helpMode']);
    }

    #[Test]
    public function a_user_help_mode_overrides_the_site_default(): void
    {
        $resolver = $this->resolver();
        $resolver->saveSitePreferences(['helpMode' => 'inline']);
        $user = TestHelper::createMockUser();

        self::assertSame('inline', $resolver->resolve($user, false)['effective']['helpMode']);

        $resolver->saveUserPreferences($user, ['helpMode' => 'tooltip']);
        $payload = $resolver->resolve($user, false);
        self::assertSame('tooltip', $payload['effective']['helpMode']);
        self::assertSame('tooltip', $payload['user']['helpMode']);
        self::assertSame('inline', $payload['site']['helpMode']);

        // null removes the override and the site default applies again.
        $resolver->saveUserPreferences($user, ['helpMode' => null]);
        self::assertSame('inline', $resolver->resolve($user, false)['effective']['helpMode']);
    }

    #[Test]
    public function a_user_can_choose_inline_help_over_the_tooltip_default(): void
    {
        $resolver = $this->resolver();
        $user = TestHelper::createMockUser();

        // Nothing set anywhere: tooltip. The user opts into inline, then clears it.
        self::assertSame('tooltip', $resolver->resolve($user, false)['effective']['helpMode']);

        $resolver->saveUserPreferences($user, ['helpMode' => 'inline']);
        self::assertSame('inline', $resolver->resolve($user, false)['effective']['helpMode']);

        $resolver->saveUserPreferences($user, ['helpMode' => null]);
        self::assertSame('tooltip', $resolver->resolve($user, false)['effective']['helpMode']);
    }

    #[Test]
    public function an_unknown_user_help_mode_is_not_saved(): void
    {
        $resolver = $this->resolver();
        $user = TestHelper::createMockUser();

        $resolver->saveUserPreferences($user, ['helpMode' => 'inline']);
        $resolver->saveUserPreferences($user, ['helpMode' => 'popup']);

        self::assertSame('inline', $resolver->resolve($user, false)['effective']['helpMode']);
    }

    #[Test]
    public function an_unknown_help_mode_already_in_an_account_file_is_ignored(): void
    {
        $resolver = $this->resolver();
        $resolver->saveSitePreferences(['helpMode' => 'inline']);
        $user = TestHelper::createMockUser();
        $user->set('admin_next', ['preferences' => ['helpMode' => 'popup']]);

        self::assertSame('inline', $resolver->resolve($user, false)['effective']['helpMode']);

        // With no site value either, the built-in tooltip default applies.
        $resolver->saveSitePreferences(['helpMode' => null]);
        self::assertSame('tooltip', $resolver->resolve($user, false)['effective']['helpMode']);
    }

    #[Test]
    public function branding_urls_follow_the_user_stream(): void
    {
        self::assertSame('/user/media/admin-next/logo.svg', $this->resolver('user')->brandingMediaUrl('logo.svg'));
        self::assertSame('/user/sites/blog/media/admin-next/logo.svg', $this->resolver('user/sites/blog')->brandingMediaUrl('../logo.svg'));
        // A user folder outside the webroot has no public URL; keep the default.
        self::assertSame('/user/media/admin-next/logo.svg', $this->resolver('/srv/grav-user')->brandingMediaUrl('logo.svg'));
        self::assertSame('', $this->resolver()->brandingMediaUrl(''));
    }

    #[Test]
    public function the_sidebar_keeps_its_built_in_order_until_someone_sets_one(): void
    {
        $resolver = $this->resolver();

        self::assertSame(['order' => [], 'hidden' => [], 'shortcuts' => []], $resolver->sitePreferences()['sidebarLayout']);
        self::assertSame(['order' => [], 'hidden' => [], 'shortcuts' => []], $resolver->resolve(TestHelper::createMockUser(), false)['effective']['sidebarLayout']);
    }

    #[Test]
    public function a_site_sidebar_layout_keeps_only_valid_unique_ids(): void
    {
        $resolver = $this->resolver();

        $resolver->saveSitePreferences(['sidebarLayout' => [
            'order' => ['dashboard', 'plugin:kahunacart', 'pages', 'pages', 'bad id', 42, 'divider'],
            'hidden' => ['media', '<script>', 'plugins'],
            'shortcuts' => [
                'plugin:kahunacart' => ['orders', 'products', 'orders', '../x', 7, 'section-licenses'],
                'plugin:empty' => [],
                'pages' => ['not-a-plugin'],
                'plugin:bad id' => ['orders'],
                'plugin:not-a-list' => 'orders',
            ],
            'extra' => ['ignored'],
        ]]);

        self::assertSame([
            'order' => ['dashboard', 'plugin:kahunacart', 'pages', 'divider'],
            'hidden' => ['media', 'plugins'],
            'shortcuts' => [
                'plugin:kahunacart' => ['orders', 'products', 'section-licenses'],
                'plugin:empty' => [],
            ],
        ], $resolver->sitePreferences()['sidebarLayout']);

        $resolver->saveSitePreferences(['sidebarLayout' => null]);
        self::assertSame(['order' => [], 'hidden' => [], 'shortcuts' => []], $resolver->sitePreferences()['sidebarLayout']);
    }

    #[Test]
    public function a_user_sidebar_layout_overrides_the_site_one_until_reset(): void
    {
        $resolver = $this->resolver();
        $site = ['order' => ['plugin:kahunacart', 'dashboard'], 'hidden' => [], 'shortcuts' => []];
        $mine = ['order' => ['pages', 'dashboard'], 'hidden' => ['tools'], 'shortcuts' => ['plugin:kahunacart' => ['orders']]];
        $resolver->saveSitePreferences(['sidebarLayout' => $site]);
        $user = TestHelper::createMockUser();

        self::assertSame($site, $resolver->resolve($user, false)['effective']['sidebarLayout']);

        $resolver->saveUserPreferences($user, ['sidebarLayout' => $mine]);
        $payload = $resolver->resolve($user, false);
        self::assertSame($mine, $payload['effective']['sidebarLayout']);
        self::assertSame($site, $payload['site']['sidebarLayout']);

        $resolver->saveUserPreferences($user, ['sidebarLayout' => null]);
        self::assertSame($site, $resolver->resolve($user, false)['effective']['sidebarLayout']);
    }

    #[Test]
    public function a_user_sidebar_layout_that_is_not_a_list_is_not_saved(): void
    {
        $resolver = $this->resolver();
        $user = TestHelper::createMockUser();

        $resolver->saveUserPreferences($user, ['sidebarLayout' => 'pages,dashboard']);

        self::assertArrayNotHasKey('sidebarLayout', $resolver->resolve($user, false)['user']);
    }

    #[Test]
    public function a_user_remembers_which_sidebar_items_are_folded(): void
    {
        $resolver = $this->resolver();
        $user = TestHelper::createMockUser();

        self::assertSame([], $resolver->resolve($user, false)['effective']['sidebarFolded']);

        $resolver->saveUserPreferences($user, ['sidebarFolded' => ['plugin:kahunacart', 'plugin:kahunacart', 'bad id', 7, 'plugin:helpdesk']]);
        self::assertSame(['plugin:kahunacart', 'plugin:helpdesk'], $resolver->resolve($user, false)['effective']['sidebarFolded']);

        $resolver->saveUserPreferences($user, ['sidebarFolded' => 'plugin:kahunacart']);
        self::assertSame(['plugin:kahunacart', 'plugin:helpdesk'], $resolver->resolve($user, false)['effective']['sidebarFolded']);

        $resolver->saveUserPreferences($user, ['sidebarFolded' => []]);
        self::assertSame([], $resolver->resolve($user, false)['effective']['sidebarFolded']);
    }

    private function resolver(string $userRelative = 'user'): PreferencesResolver
    {
        $root = $this->root;
        $locator = new class ($root, $userRelative) {
            public function __construct(private readonly string $root, private readonly string $userRelative)
            {
            }

            public function findResource(string $uri, bool $absolute = true, bool $create = false): string|false
            {
                return match ($uri) {
                    'user://' => $absolute ? $this->root . '/user' : $this->userRelative,
                    'user://config' => $this->root . '/user/config',
                    default => false,
                };
            }
        };

        $grav = TestHelper::createMockGrav(['locator' => $locator, 'config' => new Config([])]);

        return new PreferencesResolver($grav);
    }
}
