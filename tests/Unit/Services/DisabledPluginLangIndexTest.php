<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Grav;
use Grav\Common\Yaml;
use Grav\Plugin\Api\Services\DisabledPluginLangIndex;
use Grav\Plugin\Api\Services\TranslationSourceIndex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DisabledPluginLangIndex::class)]
class DisabledPluginLangIndexTest extends TestCase
{
    private string $tmp = '';

    /** @var array<string, bool> */
    private array $enabled = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/grav-i18ndisabled-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/system/languages', 0777, true);
        mkdir($this->tmp . '/user/plugins', 0777, true);
        mkdir($this->tmp . '/user/themes', 0777, true);
    }

    protected function tearDown(): void
    {
        I18nTestFixture::rrmdir($this->tmp);
        Grav::resetInstance();
    }

    #[Test]
    public function only_keys_no_enabled_source_ships_are_disabled_only(): void
    {
        file_put_contents($this->tmp . '/system/languages/en.yaml', Yaml::dump(['SHARED' => 'core']));
        $this->plugin('admin', ['PLUGIN_ADMIN' => ['OLD' => 'Old'], 'SHARED' => 'admin'], enabled: false);
        $this->plugin('login', ['PLUGIN_LOGIN' => ['BTN' => 'Sign in']], enabled: true);

        $index = $this->index(new I18nMemoryCache());

        $this->assertSame(['PLUGIN_ADMIN.OLD'], $index->disabledOnlyKeys('en'));
        $this->assertTrue($index->isDisabledOnly('PLUGIN_ADMIN.OLD', 'en'));
        $this->assertFalse($index->isDisabledOnly('SHARED', 'en'), 'core also ships it');
        $this->assertFalse($index->isDisabledOnly('PLUGIN_LOGIN.BTN', 'en'));
        $this->assertFalse($index->isDisabledOnly('NOT.A.KEY', 'en'));
    }

    #[Test]
    public function a_warm_request_reads_the_list_from_the_cache(): void
    {
        $this->plugin('admin', ['PLUGIN_ADMIN' => ['OLD' => 'Old']], enabled: false);
        $cache = new I18nMemoryCache();

        $this->index($cache)->disabledOnlyKeys('en');
        $this->assertCount(1, $cache->keysStartingWith('api-i18n-disabled-'));

        // Enabling the plugin changes the fingerprint, so the cached list is not reused.
        $this->enabled['admin'] = true;
        $this->assertSame([], $this->index($cache)->disabledOnlyKeys('en'));
    }

    private function index(object $cache): DisabledPluginLangIndex
    {
        Grav::resetInstance();
        $grav = Grav::instance();
        $grav['locator'] = new I18nFakeLocator($this->tmp);
        $grav['config'] = new I18nFakeConfig($this->enabled, '');
        $grav['cache'] = $cache;

        return new DisabledPluginLangIndex($grav, new TranslationSourceIndex($grav));
    }

    /** @param array<mixed> $data */
    private function plugin(string $slug, array $data, bool $enabled): void
    {
        $dir = $this->tmp . "/user/plugins/{$slug}/languages";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/en.yaml", Yaml::dump($data));
        $this->enabled[$slug] = $enabled;
    }
}
