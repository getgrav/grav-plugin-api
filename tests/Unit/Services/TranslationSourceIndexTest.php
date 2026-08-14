<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Grav;
use Grav\Plugin\Api\Services\TranslationSourceIndex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationSourceIndex::class)]
class TranslationSourceIndexTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/grav-i18nsrc-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/system/languages', 0777, true);
        mkdir($this->tmp . '/user/languages', 0777, true);
        mkdir($this->tmp . '/user/plugins', 0777, true);
        mkdir($this->tmp . '/user/themes', 0777, true);
    }

    protected function tearDown(): void
    {
        I18nTestFixture::rrmdir($this->tmp);
        Grav::resetInstance();
    }

    #[Test]
    public function it_attributes_each_key_to_the_source_that_ships_it(): void
    {
        $this->writeSystem('en', ['SITE' => ['TITLE' => 'Core Title']]);
        $this->writePlugin('login', 'en', ['PLUGIN_LOGIN' => ['BTN' => 'Sign in']], enabled: true);
        $this->writeTheme('quark', 'en', ['THEME_QUARK' => ['NAV' => 'Primary']], active: true);

        $index = $this->index()->index('en');

        $this->assertSame('system:core', $index['SITE.TITLE']['owner']);
        $this->assertSame('plugin:login', $index['PLUGIN_LOGIN.BTN']['owner']);
        $this->assertSame('theme:quark', $index['THEME_QUARK.NAV']['owner']);
        $this->assertSame('Primary', $index['THEME_QUARK.NAV']['value']);
    }

    #[Test]
    public function the_active_theme_outranks_core_and_plugins(): void
    {
        // Mirrors runtime: Themes::loadLanguages() merges after the compiled set.
        $this->writeSystem('en', ['SHARED' => 'from core']);
        $this->writePlugin('login', 'en', ['SHARED' => 'from plugin'], enabled: true);
        $this->writeTheme('quark', 'en', ['SHARED' => 'from theme'], active: true);

        $index = $this->index()->index('en');

        $this->assertSame('from theme', $index['SHARED']['value']);
        $this->assertSame('theme:quark', $index['SHARED']['owner']);
        $this->assertSame(
            ['plugin:login', 'system:core', 'theme:quark'],
            $index['SHARED']['providers'],
            'providers are ordered lowest to highest precedence'
        );
    }

    #[Test]
    public function core_outranks_plugins(): void
    {
        $this->writeSystem('en', ['SHARED' => 'from core']);
        $this->writePlugin('login', 'en', ['SHARED' => 'from plugin'], enabled: true);

        $this->assertSame('from core', $this->index()->index('en')['SHARED']['value']);
    }

    #[Test]
    public function an_inactive_theme_never_wins_a_contested_key(): void
    {
        // Only the active theme's languages are merged at runtime, so an
        // installed-but-unused theme must not decide what a key says.
        $this->writePlugin('login', 'en', ['SHARED' => 'from plugin'], enabled: true);
        $this->writeTheme('antimatter', 'en', ['SHARED' => 'from inactive theme'], active: false);

        $index = $this->index()->index('en');

        $this->assertSame('from plugin', $index['SHARED']['value']);
        $this->assertSame('plugin:login', $index['SHARED']['owner']);
        $this->assertContains('theme:antimatter', $index['SHARED']['providers']);
    }

    #[Test]
    public function a_disabled_plugin_never_wins_a_contested_key(): void
    {
        $this->writePlugin('enabled-one', 'en', ['SHARED' => 'live'], enabled: true);
        $this->writePlugin('disabled-one', 'en', ['SHARED' => 'stale'], enabled: false);

        $this->assertSame('live', $this->index()->index('en')['SHARED']['value']);
    }

    #[Test]
    public function it_reads_the_single_file_multi_language_form(): void
    {
        // Older plugins and themes ship one languages.yaml keyed by code.
        file_put_contents(
            $this->makeDir($this->tmp . '/user/themes/quark2') . '/languages.yaml',
            "en:\n  THEME_QUARK2:\n    NAV: Primary\nfr:\n  THEME_QUARK2:\n    NAV: Principal\n"
        );
        $this->writeBlueprint($this->tmp . '/user/themes/quark2', 'Quark 2');

        $index = $this->index(activeTheme: 'quark2');

        $this->assertSame('Primary', $index->index('en')['THEME_QUARK2.NAV']['value']);
        $this->assertSame('Principal', $index->index('fr')['THEME_QUARK2.NAV']['value']);
        $this->assertContains('fr', $index->languages());
    }

    #[Test]
    public function shipped_value_ignores_this_sites_overrides(): void
    {
        $this->writeTheme('quark', 'en', ['THEME_QUARK' => ['NAV' => 'Primary']], active: true);
        $this->writeUser('en', ['THEME_QUARK' => ['NAV' => 'Main menu']]);

        $index = $this->index();

        $this->assertSame('Primary', $index->shippedValue('THEME_QUARK.NAV', 'en'));
    }

    #[Test]
    public function shipped_value_is_null_for_a_key_only_this_site_defines(): void
    {
        $this->writeUser('en', ['MADE' => ['UP' => 'nothing ships this']]);

        $this->assertNull($this->index()->shippedValue('MADE.UP', 'en'));
    }

    #[Test]
    public function an_override_only_key_is_not_a_known_key(): void
    {
        $this->writeSystem('en', ['REAL' => 'yes']);
        $this->writeUser('en', ['TYPO' => 'oops']);

        $index = $this->index();

        $this->assertTrue($index->isKnownKey('REAL'));
        $this->assertFalse($index->isKnownKey('TYPO'));
    }

    #[Test]
    public function language_codes_are_reported_exactly_as_they_appear_on_disk(): void
    {
        // Core and themes use short codes, admin2 uses BCP 47. Both are real,
        // separate top-level keys in the compiled set.
        $this->writeSystem('en', ['A' => '1']);
        $this->writePlugin('admin2', 'en-US', ['B' => '2'], enabled: true);

        $this->assertSame(['en', 'en-US'], $this->index()->languages());
    }

    #[Test]
    public function only_scalar_leaves_are_indexed(): void
    {
        $this->writeSystem('en', ['LIST' => ['a', 'b'], 'TEXT' => 'hello', 'NUM' => 42]);

        $index = $this->index()->index('en');

        $this->assertSame('hello', $index['TEXT']['value']);
        $this->assertSame('42', $index['NUM']['value']);
        $this->assertArrayNotHasKey('LIST', $index);
    }

    #[Test]
    public function namespaces_are_grouped_by_first_segment(): void
    {
        $this->writePlugin('login', 'en', [
            'PLUGIN_LOGIN' => ['A' => '1', 'B' => '2'],
            'OTHER' => ['C' => '3'],
        ], enabled: true);

        $this->assertSame(
            ['OTHER' => 1, 'PLUGIN_LOGIN' => 2],
            $this->index()->namespacesForProvider('plugin:login', 'en')
        );
    }

    #[Test]
    public function a_malformed_language_file_is_skipped_not_fatal(): void
    {
        $this->writeSystem('en', ['GOOD' => 'value']);
        $dir = $this->makeDir($this->tmp . '/user/plugins/broken/languages');
        file_put_contents("{$dir}/en.yaml", "\tnot: [valid");

        $index = $this->index()->index('en');

        $this->assertSame('value', $index['GOOD']['value']);
    }

    // ─── fixture helpers ──────────────────────────────────────────────

    private function index(?string $activeTheme = null): TranslationSourceIndex
    {
        Grav::resetInstance();
        $grav = Grav::instance();
        $grav['locator'] = new I18nFakeLocator($this->tmp);
        $grav['config'] = new I18nFakeConfig($this->enabledPlugins, $activeTheme ?? $this->activeTheme);
        $grav['cache'] = new I18nFakeCache();

        return new TranslationSourceIndex($grav);
    }

    /** @var array<string, bool> */
    private array $enabledPlugins = [];

    private string $activeTheme = '';

    /** @param array<mixed> $data */
    private function writeSystem(string $lang, array $data): void
    {
        file_put_contents(
            $this->tmp . "/system/languages/{$lang}.yaml",
            \Grav\Common\Yaml::dump($data)
        );
    }

    /** @param array<mixed> $data */
    private function writeUser(string $lang, array $data): void
    {
        file_put_contents(
            $this->tmp . "/user/languages/{$lang}.yaml",
            \Grav\Common\Yaml::dump($data)
        );
    }

    /** @param array<mixed> $data */
    private function writePlugin(string $slug, string $lang, array $data, bool $enabled): void
    {
        $dir = $this->makeDir($this->tmp . "/user/plugins/{$slug}/languages");
        file_put_contents("{$dir}/{$lang}.yaml", \Grav\Common\Yaml::dump($data));
        $this->writeBlueprint(dirname($dir), ucfirst($slug));
        $this->enabledPlugins[$slug] = $enabled;
    }

    /** @param array<mixed> $data */
    private function writeTheme(string $slug, string $lang, array $data, bool $active): void
    {
        $dir = $this->makeDir($this->tmp . "/user/themes/{$slug}/languages");
        file_put_contents("{$dir}/{$lang}.yaml", \Grav\Common\Yaml::dump($data));
        $this->writeBlueprint(dirname($dir), ucfirst($slug));
        if ($active) {
            $this->activeTheme = $slug;
        }
    }

    private function writeBlueprint(string $dir, string $name): void
    {
        file_put_contents("{$dir}/blueprints.yaml", "name: {$name}\n");
    }

    private function makeDir(string $path): string
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        return $path;
    }
}
