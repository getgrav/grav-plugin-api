<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Plugin\Api\Services\ConfigSecretMasker;
use Grav\Plugin\Api\Services\ConfigShadows;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A site with `enabled: false` in its hostname overlay enabled the plugin under
 * "Default": the save went to base, reported success, and the overlay kept the
 * plugin off (getgrav/grav-plugin-sync-mercure#1). ConfigShadows names the
 * values a layer above the write target decides, so the admin can say so.
 */
#[CoversClass(ConfigShadows::class)]
class ConfigShadowsTest extends TestCase
{
    private ShadowsFixture $site;

    protected function setUp(): void
    {
        $this->site = new ShadowsFixture();
        $this->site->write('user/plugins/mercure/mercure.yaml', "enabled: true\nhub: https://hub.test\nsmtp:\n  password: ''\n");
        $this->site->write(
            'user/env/example.com/config/plugins/mercure.yaml',
            "enabled: false\nsmtp:\n  password: hunter2\ntopics: [a, b]\n",
        );
    }

    protected function tearDown(): void
    {
        $this->site->cleanup();
    }

    #[Test]
    public function a_base_target_lists_every_leaf_the_active_environment_file_sets(): void
    {
        $this->site->bootEnvironment('example.com');
        $shadows = new ConfigShadows($this->site->grav());

        $entries = $shadows->forScope('plugins/mercure', null);

        self::assertSame(['enabled', 'smtp.password', 'topics'], array_column($entries, 'path'));
        $enabled = $entries[0];
        self::assertSame('environment', $enabled['source']);
        self::assertSame('example.com', $enabled['environment']);
        self::assertStringEndsWith('user/env/example.com/config/plugins/mercure.yaml', $enabled['file']);
        self::assertFalse($enabled['value']);
        self::assertSame(['a', 'b'], $entries[2]['value']);
    }

    #[Test]
    public function secrets_in_the_environment_file_are_masked(): void
    {
        $this->site->bootEnvironment('example.com');
        $entries = (new ConfigShadows($this->site->grav()))->forScope('plugins/mercure', null);

        $byPath = array_column($entries, 'value', 'path');
        self::assertSame(ConfigSecretMasker::SENTINEL, $byPath['smtp.password']);
        self::assertStringNotContainsString('hunter2', (string) json_encode($entries));
    }

    #[Test]
    public function a_value_equal_to_base_is_still_listed(): void
    {
        // It still wins over the next save to base.
        $this->site->write('user/env/example.com/config/plugins/mercure.yaml', "hub: https://hub.test\n");
        $this->site->bootEnvironment('example.com');

        $entries = (new ConfigShadows($this->site->grav()))->forScope('plugins/mercure', null);

        self::assertSame(['hub'], array_column($entries, 'path'));
    }

    #[Test]
    public function the_environment_target_itself_has_no_environment_shadows(): void
    {
        $this->site->bootEnvironment('example.com');
        $shadows = new ConfigShadows($this->site->grav());

        self::assertSame([], $shadows->forScope('plugins/mercure', 'example.com'));
    }

    #[Test]
    public function another_environment_target_has_no_environment_shadows(): void
    {
        mkdir($this->site->root . '/user/env/staging.test/config', 0777, true);
        $this->site->bootEnvironment('example.com');

        self::assertSame([], (new ConfigShadows($this->site->grav()))->forScope('plugins/mercure', 'staging.test'));
    }

    #[Test]
    public function no_active_environment_means_nothing_is_shadowed(): void
    {
        $this->site->bootEnvironment(null);

        self::assertSame([], (new ConfigShadows($this->site->grav()))->forScope('plugins/mercure', null));
    }

    #[Test]
    public function an_active_environment_without_a_file_for_the_scope_shadows_nothing(): void
    {
        $this->site->bootEnvironment('example.com');

        self::assertSame([], (new ConfigShadows($this->site->grav()))->forScope('plugins/other', null));
    }

    #[Test]
    public function core_scopes_are_covered_too(): void
    {
        $this->site->write('system/config/site.yaml', "title: Grav\n");
        $this->site->write('user/env/example.com/config/site.yaml', "title: Staging\nauthor:\n  name: Ops\n");
        $this->site->bootEnvironment('example.com');

        $entries = (new ConfigShadows($this->site->grav()))->forScope('site', null);

        self::assertSame(['title', 'author.name'], array_column($entries, 'path'));
        self::assertSame('Staging', $entries[0]['value']);
    }

    #[Test]
    public function a_server_variable_is_listed_for_any_target_with_its_live_value(): void
    {
        $this->site->serverVar('GRAV_CONFIG__plugins__mercure__hub', 'https://prod-hub');
        $grav = $this->site->grav(['plugins' => ['mercure' => ['hub' => 'https://prod-hub']]]);

        foreach ([null, 'example.com'] as $target) {
            $entries = (new ConfigShadows($grav))->forScope('plugins/mercure', $target);
            self::assertSame([[
                'path' => 'hub',
                'source' => 'server',
                'environment' => null,
                'file' => null,
                'value' => 'https://prod-hub',
            ]], $entries, 'target ' . var_export($target, true));
        }
    }

    #[Test]
    public function a_server_variable_wins_over_the_environment_file_and_is_masked(): void
    {
        $this->site->serverVar('GRAV_CONFIG__plugins__mercure__smtp__password', 'from-env-var');
        $this->site->bootEnvironment('example.com');
        $grav = $this->site->grav(['plugins' => ['mercure' => ['smtp' => ['password' => 'from-env-var']]]]);

        $entries = (new ConfigShadows($grav))->forScope('plugins/mercure', null);

        $password = array_values(array_filter($entries, static fn (array $e): bool => $e['path'] === 'smtp.password'));
        self::assertCount(1, $password);
        self::assertSame('server', $password[0]['source']);
        self::assertSame(ConfigSecretMasker::SENTINEL, $password[0]['value']);
        // The env file's other leaves are still listed.
        self::assertContains('enabled', array_column($entries, 'path'));
    }

    #[Test]
    public function server_variables_outside_the_scope_are_ignored(): void
    {
        $this->site->serverVar('GRAV_CONFIG__plugins__mercurex__hub', 'x');
        $grav = $this->site->grav(['plugins' => ['mercurex' => ['hub' => 'x']]]);

        self::assertSame([], (new ConfigShadows($grav))->forScope('plugins/mercure', null));
    }

    // ---------- pluginEnabledSource() ----------

    #[Test]
    public function enabled_set_by_the_active_environment_is_reported(): void
    {
        $this->site->bootEnvironment('example.com');

        $source = (new ConfigShadows($this->site->grav()))->pluginEnabledSource('mercure');

        self::assertSame('environment', $source['source']);
        self::assertSame('example.com', $source['environment']);
        self::assertStringEndsWith('user/env/example.com/config/plugins/mercure.yaml', $source['file']);
    }

    #[Test]
    public function enabled_set_by_a_server_variable_is_reported_ahead_of_the_environment(): void
    {
        $this->site->serverVar('GRAV_CONFIG__plugins__mercure__enabled', 'true');
        $this->site->bootEnvironment('example.com');
        $grav = $this->site->grav(['plugins' => ['mercure' => ['enabled' => true]]]);

        self::assertSame(
            ['source' => 'server', 'environment' => null, 'file' => null],
            (new ConfigShadows($grav))->pluginEnabledSource('mercure'),
        );
    }

    #[Test]
    public function enabled_from_base_has_no_source(): void
    {
        // The env file sets other keys, not `enabled`.
        $this->site->write('user/env/example.com/config/plugins/mercure.yaml', "hub: https://other\n");
        $this->site->bootEnvironment('example.com');
        $shadows = new ConfigShadows($this->site->grav());

        self::assertNull($shadows->pluginEnabledSource('mercure'));
        self::assertNull($shadows->pluginEnabledSource('not-in-env'));
    }

    #[Test]
    public function enabled_without_an_active_environment_has_no_source(): void
    {
        $this->site->bootEnvironment(null);

        self::assertNull((new ConfigShadows($this->site->grav()))->pluginEnabledSource('mercure'));
    }
}
