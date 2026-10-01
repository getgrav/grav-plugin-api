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
    public function help_mode_defaults_to_inline(): void
    {
        $resolver = $this->resolver();

        self::assertSame('inline', $resolver->defaultPreferences()['helpMode']);
        self::assertSame('inline', $resolver->sitePreferences()['helpMode']);
    }

    #[Test]
    public function a_site_can_default_to_tooltip_help(): void
    {
        $resolver = $this->resolver();

        $resolver->saveSitePreferences(['helpMode' => 'tooltip']);
        self::assertSame('tooltip', $resolver->sitePreferences()['helpMode']);

        $resolver->saveSitePreferences(['helpMode' => null]);
        self::assertSame('inline', $resolver->sitePreferences()['helpMode']);
    }

    #[Test]
    public function an_unknown_site_help_mode_is_dropped_and_falls_back_to_inline(): void
    {
        $resolver = $this->resolver();

        $resolver->saveSitePreferences(['helpMode' => 'tooltip']);
        $resolver->saveSitePreferences(['helpMode' => 'popup']);

        self::assertSame('inline', $resolver->sitePreferences()['helpMode']);

        // A bad value that reached the file some other way reads back as inline too.
        file_put_contents($this->root . '/user/config/admin-next.yaml', "ui:\n  defaults:\n    helpMode: popup\n");
        self::assertSame('inline', $resolver->sitePreferences()['helpMode']);
    }

    #[Test]
    public function a_user_help_mode_overrides_the_site_default(): void
    {
        $resolver = $this->resolver();
        $resolver->saveSitePreferences(['helpMode' => 'tooltip']);
        $user = TestHelper::createMockUser();

        self::assertSame('tooltip', $resolver->resolve($user, false)['effective']['helpMode']);

        $resolver->saveUserPreferences($user, ['helpMode' => 'inline']);
        $payload = $resolver->resolve($user, false);
        self::assertSame('inline', $payload['effective']['helpMode']);
        self::assertSame('inline', $payload['user']['helpMode']);
        self::assertSame('tooltip', $payload['site']['helpMode']);

        // null removes the override and the site default applies again.
        $resolver->saveUserPreferences($user, ['helpMode' => null]);
        self::assertSame('tooltip', $resolver->resolve($user, false)['effective']['helpMode']);
    }

    #[Test]
    public function an_unknown_user_help_mode_is_not_saved(): void
    {
        $resolver = $this->resolver();
        $user = TestHelper::createMockUser();

        $resolver->saveUserPreferences($user, ['helpMode' => 'tooltip']);
        $resolver->saveUserPreferences($user, ['helpMode' => 'popup']);

        self::assertSame('tooltip', $resolver->resolve($user, false)['effective']['helpMode']);
    }

    #[Test]
    public function an_unknown_help_mode_already_in_an_account_file_is_ignored(): void
    {
        $resolver = $this->resolver();
        $resolver->saveSitePreferences(['helpMode' => 'tooltip']);
        $user = TestHelper::createMockUser();
        $user->set('admin_next', ['preferences' => ['helpMode' => 'popup']]);

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
