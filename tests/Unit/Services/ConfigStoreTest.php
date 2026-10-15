<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Grav;
use Grav\Common\Yaml;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Services\ConfigStore;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ConfigStore is the public version of ConfigController's target/read/write
 * logic, so plugins with their own settings endpoints save to the layer the
 * Admin2 environment picker selected instead of the config:// overlay.
 */
#[CoversClass(ConfigStore::class)]
class ConfigStoreTest extends TestCase
{
    private ShadowsFixture $site;

    protected function setUp(): void
    {
        $this->site = new ShadowsFixture();
        $this->site->write('user/plugins/mercure/mercure.yaml', "enabled: true\nhub: https://hub.test\nretries: 3\n");
        mkdir($this->site->root . '/user/env/example.com/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->site->cleanup();
    }

    // ---------- targetFor() ----------

    #[Test]
    public function a_missing_header_targets_the_booted_environment_when_it_has_a_config_dir(): void
    {
        $this->site->bootEnvironment('example.com');
        $store = new ConfigStore($this->site->grav());

        self::assertSame('example.com', $store->targetFor(TestHelper::createMockRequest('PATCH', '/x')));
    }

    #[Test]
    public function a_missing_header_targets_base_when_the_booted_environment_has_no_config_dir(): void
    {
        $this->site->bootEnvironment('production.example.org');
        $store = new ConfigStore($this->site->grav());

        self::assertNull($store->targetFor(TestHelper::createMockRequest('PATCH', '/x')));
    }

    #[Test]
    public function a_missing_header_targets_base_when_no_environment_is_booted(): void
    {
        $store = new ConfigStore($this->site->grav());

        self::assertNull($store->targetFor(TestHelper::createMockRequest('PATCH', '/x')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function baseHeaders(): array
    {
        return ['default sentinel' => ['default'], 'base' => ['base'], 'empty' => [''], 'whitespace' => ['  ']];
    }

    #[Test]
    #[DataProvider('baseHeaders')]
    public function a_base_header_targets_base_even_when_an_environment_is_booted(string $header): void
    {
        $this->site->bootEnvironment('example.com');
        $store = new ConfigStore($this->site->grav());

        $request = TestHelper::createMockRequest('PATCH', '/x', ['X-Config-Environment' => $header]);
        self::assertNull($store->targetFor($request));
    }

    #[Test]
    public function a_named_header_wins_over_the_booted_environment(): void
    {
        $this->site->bootEnvironment('example.com');
        $store = new ConfigStore($this->site->grav());

        $request = TestHelper::createMockRequest('PATCH', '/x', ['X-Config-Environment' => 'staging.example.com']);
        // Existence is checked by writeDir(), not here.
        self::assertSame('staging.example.com', $store->targetFor($request));
    }

    #[Test]
    public function an_invalid_header_is_rejected(): void
    {
        $store = new ConfigStore($this->site->grav());

        $this->expectException(ValidationException::class);
        $store->targetFor(TestHelper::createMockRequest('PATCH', '/x', ['X-Config-Environment' => '../etc']));
    }

    // ---------- writeDir() / filePath() ----------

    #[Test]
    public function write_dir_is_base_user_config_or_the_existing_environment(): void
    {
        $store = new ConfigStore($this->site->grav());

        self::assertSame($this->site->root . '/user/config', $store->writeDir(null));
        self::assertSame($this->site->root . '/user/env/example.com/config', $store->writeDir('example.com'));
    }

    #[Test]
    public function write_dir_rejects_a_missing_environment_without_creating_it(): void
    {
        $store = new ConfigStore($this->site->grav());

        try {
            $store->writeDir('missing.example.com');
            self::fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
        }
        self::assertDirectoryDoesNotExist($this->site->root . '/user/env/missing.example.com');
    }

    #[Test]
    public function file_path_maps_each_scope_kind_under_the_target(): void
    {
        $store = new ConfigStore($this->site->grav());
        $root = $this->site->root;

        self::assertSame($root . '/user/config/site.yaml', $store->filePath('site', null));
        self::assertSame($root . '/user/config/plugins/mercure.yaml', $store->filePath('plugins/mercure', null));
        self::assertSame($root . '/user/env/example.com/config/themes/quark.yaml', $store->filePath('themes/quark', 'example.com'));
    }

    #[Test]
    public function file_path_rejects_an_unknown_scope(): void
    {
        $store = new ConfigStore($this->site->grav());

        $this->expectException(NotFoundException::class);
        $store->filePath('no-such-scope', null);
    }

    // ---------- effective() ----------

    #[Test]
    public function effective_merges_the_layers_beneath_and_at_the_target(): void
    {
        $this->site->write('user/config/plugins/mercure.yaml', "retries: 5\n");
        $this->site->write('user/env/example.com/config/plugins/mercure.yaml', "enabled: false\n");
        $store = new ConfigStore($this->site->grav());

        self::assertSame(['enabled' => true, 'hub' => 'https://hub.test', 'retries' => 5], $store->effective('plugins/mercure', null));
        self::assertSame(['enabled' => false, 'hub' => 'https://hub.test', 'retries' => 5], $store->effective('plugins/mercure', 'example.com'));
    }

    // ---------- save() ----------

    #[Test]
    public function saving_to_base_writes_only_the_delta_against_the_defaults(): void
    {
        $store = new ConfigStore($this->site->grav());

        $store->save('plugins/mercure', ['enabled' => true, 'hub' => 'https://hub.test', 'retries' => 7], null);

        self::assertSame(['retries' => 7], $this->yaml('user/config/plugins/mercure.yaml'));
        self::assertDirectoryDoesNotExist($this->site->root . '/user/env/example.com/config/plugins');
    }

    #[Test]
    public function saving_to_an_environment_writes_only_the_delta_against_base(): void
    {
        $this->site->write('user/config/plugins/mercure.yaml', "retries: 5\n");
        $store = new ConfigStore($this->site->grav());

        $store->save('plugins/mercure', ['enabled' => false, 'hub' => 'https://hub.test', 'retries' => 5], 'example.com');

        // retries: 5 matches base, so it stays out of the overlay; base is untouched.
        self::assertSame(['enabled' => false], $this->yaml('user/env/example.com/config/plugins/mercure.yaml'));
        self::assertSame(['retries' => 5], $this->yaml('user/config/plugins/mercure.yaml'));
    }

    #[Test]
    public function saving_nothing_but_inherited_values_creates_no_placeholder_file(): void
    {
        $store = new ConfigStore($this->site->grav());

        $store->save('plugins/mercure', ['enabled' => true, 'hub' => 'https://hub.test', 'retries' => 3], 'example.com');
        $store->save('plugins/mercure', ['enabled' => true, 'hub' => 'https://hub.test', 'retries' => 3], null);

        self::assertDirectoryDoesNotExist($this->site->root . '/user/env/example.com/config/plugins');
        self::assertFileDoesNotExist($this->site->root . '/user/config/plugins/mercure.yaml');
    }

    #[Test]
    public function saving_to_a_missing_environment_writes_nothing(): void
    {
        $store = new ConfigStore($this->site->grav());

        try {
            $store->save('plugins/mercure', ['retries' => 9], 'missing.example.com');
            self::fail('Expected a ValidationException');
        } catch (ValidationException) {
        }
        self::assertDirectoryDoesNotExist($this->site->root . '/user/env/missing.example.com');
        self::assertFileDoesNotExist($this->site->root . '/user/config/plugins/mercure.yaml');
    }

    #[Test]
    public function server_variable_values_are_never_written(): void
    {
        $this->site->serverVar('GRAV_CONFIG__plugins__mercure__hub', 'https://prod-hub');
        $store = new ConfigStore($this->site->grav(['plugins' => ['mercure' => ['hub' => 'https://prod-hub']]]));

        $store->save('plugins/mercure', ['enabled' => true, 'hub' => 'https://prod-hub', 'retries' => 4], null);

        self::assertSame(['retries' => 4], $this->yaml('user/config/plugins/mercure.yaml'));
    }

    #[Test]
    public function saving_clears_the_standard_cache(): void
    {
        $grav = $this->site->grav();
        $cache = new class {
            /** @var list<string> */
            public array $cleared = [];

            public function clearCache(string $remove = 'standard'): array
            {
                $this->cleared[] = $remove;
                return [];
            }
        };
        $grav['cache'] = $cache;

        (new ConfigStore($grav))->save('plugins/mercure', ['retries' => 8], null);

        self::assertSame(['standard'], $cache->cleared);
    }

    #[Test]
    public function the_live_config_follows_a_save_to_the_booted_layer_only(): void
    {
        $data = ['enabled' => true, 'hub' => 'https://hub.test', 'retries' => 6];

        // Booted under base: a base save updates the live config, an env save doesn't.
        $grav = $this->site->grav(['plugins' => ['mercure' => ['retries' => 3]]]);
        (new ConfigStore($grav))->save('plugins/mercure', ['retries' => 9] + $data, 'example.com');
        self::assertSame(3, $grav['config']->get('plugins.mercure.retries'));
        (new ConfigStore($grav))->save('plugins/mercure', $data, null);
        self::assertSame(6, $grav['config']->get('plugins.mercure.retries'));

        // Booted under example.com: only a save to example.com updates it.
        $this->site->bootEnvironment('example.com');
        $grav = $this->site->grav(['plugins' => ['mercure' => ['retries' => 3]]]);
        (new ConfigStore($grav))->save('plugins/mercure', ['retries' => 11] + $data, null);
        self::assertSame(3, $grav['config']->get('plugins.mercure.retries'));
        (new ConfigStore($grav))->save('plugins/mercure', ['retries' => 12] + $data, 'example.com');
        self::assertSame(12, $grav['config']->get('plugins.mercure.retries'));
    }

    /**
     * @return array<mixed>
     */
    private function yaml(string $relative): array
    {
        $path = $this->site->root . '/' . $relative;
        self::assertFileExists($path);
        $data = Yaml::parse((string) file_get_contents($path));
        return is_array($data) ? $data : [];
    }
}
