<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Grav;
use Grav\Plugin\Api\Services\ConfigDiffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConfigDiffer's GRAV_CONFIG__* environment-override handling.
 *
 * These mirror Grav core's InitializeProcessor resolution: values injected via
 * env vars (typically from a .env file) win at runtime and must never be
 * written back to a YAML config file on save.
 */
class ConfigDifferEnvOverrideTest extends TestCase
{
    private ConfigDiffer $differ;

    /** @var list<string> */
    private array $touched = [];

    protected function setUp(): void
    {
        Grav::resetInstance();
        $this->differ = new ConfigDiffer(Grav::instance());
        $this->clearVar('GRAV_CONFIG');
    }

    protected function tearDown(): void
    {
        foreach ($this->touched as $name) {
            $this->clearVar($name);
        }
        $this->clearVar('GRAV_CONFIG');
    }

    // ---------- environmentOverrideKeys() ----------

    #[Test]
    public function override_keys_are_empty_when_switch_is_off(): void
    {
        $this->setVar('GRAV_CONFIG__plugins__email__enabled', 'true');

        $this->assertSame([], $this->differ->environmentOverrideKeys());
    }

    #[Test]
    public function override_keys_resolve_double_underscores_to_dots(): void
    {
        $this->setVar('GRAV_CONFIG', 'true');
        $this->setVar('GRAV_CONFIG__plugins__email__mailer__smtp__password', 'secret');

        $this->assertSame(
            ['plugins.email.mailer.smtp.password'],
            $this->differ->environmentOverrideKeys(),
        );
    }

    #[Test]
    public function override_keys_apply_aliases_for_hyphenated_paths(): void
    {
        $this->setVar('GRAV_CONFIG', 'true');
        $this->setVar('GRAV_CONFIG_ALIAS__TRANSLATIONSERVICE', 'plugins.translation-service');
        $this->setVar('GRAV_CONFIG__TRANSLATIONSERVICE__anthropic__api_key', 'sk-ant-123');

        $this->assertSame(
            ['plugins.translation-service.anthropic.api_key'],
            $this->differ->environmentOverrideKeys(),
        );
    }

    // ---------- stripEnvironmentOverrides() ----------

    #[Test]
    public function strip_removes_a_scoped_env_key_and_prunes_empty_parents(): void
    {
        $this->setVar('GRAV_CONFIG', 'true');
        $this->setVar('GRAV_CONFIG_ALIAS__TRANSLATIONSERVICE', 'plugins.translation-service');
        $this->setVar('GRAV_CONFIG__TRANSLATIONSERVICE__anthropic__api_key', 'sk-ant-123');

        $data = [
            'enabled' => true,
            'anthropic' => ['api_key' => 'sk-ant-123', 'model' => 'opus'],
        ];

        // api_key is dropped; model (and its parent) survive.
        $this->assertSame(
            ['enabled' => true, 'anthropic' => ['model' => 'opus']],
            $this->differ->stripEnvironmentOverrides($data, 'plugins/translation-service'),
        );
    }

    #[Test]
    public function strip_prunes_a_subtree_that_empties_out(): void
    {
        $this->setVar('GRAV_CONFIG', 'true');
        $this->setVar('GRAV_CONFIG_ALIAS__TRANSLATIONSERVICE', 'plugins.translation-service');
        $this->setVar('GRAV_CONFIG__TRANSLATIONSERVICE__anthropic__api_key', 'sk-ant-123');

        $data = ['enabled' => true, 'anthropic' => ['api_key' => 'sk-ant-123']];

        $this->assertSame(
            ['enabled' => true],
            $this->differ->stripEnvironmentOverrides($data, 'plugins/translation-service'),
        );
    }

    #[Test]
    public function strip_is_a_noop_when_switch_is_off(): void
    {
        $this->setVar('GRAV_CONFIG__plugins__email__mailer__smtp__password', 'secret');

        $data = ['mailer' => ['smtp' => ['password' => 'secret']]];

        $this->assertSame($data, $this->differ->stripEnvironmentOverrides($data, 'plugins/email'));
    }

    #[Test]
    public function strip_ignores_keys_outside_the_scope(): void
    {
        $this->setVar('GRAV_CONFIG', 'true');
        $this->setVar('GRAV_CONFIG__plugins__other__token', 'xyz');

        $data = ['token' => 'mine'];

        // The env key targets plugins.other, not plugins.email — leave it alone.
        $this->assertSame($data, $this->differ->stripEnvironmentOverrides($data, 'plugins/email'));
    }

    #[Test]
    public function strip_returns_empty_when_the_whole_scope_is_env_provided(): void
    {
        $this->setVar('GRAV_CONFIG', 'true');
        $this->setVar('GRAV_CONFIG__system', 'whatever');

        $this->assertSame([], $this->differ->stripEnvironmentOverrides(['x' => 1], 'system'));
    }

    #[Test]
    public function strip_is_scope_agnostic_for_core_and_flex_scopes(): void
    {
        $this->setVar('GRAV_CONFIG', 'true');
        $this->setVar('GRAV_CONFIG__system__cache__enabled', 'false');
        $this->setVar('GRAV_CONFIG__flex__accounts__timeout', '60');

        // Core 'system' scope: cache.enabled is stripped, the emptied cache
        // subtree is pruned, pages survives.
        $this->assertSame(
            ['pages' => ['theme' => 'quark']],
            $this->differ->stripEnvironmentOverrides(
                ['pages' => ['theme' => 'quark'], 'cache' => ['enabled' => false]],
                'system',
            ),
        );

        // 'flex/accounts' scope maps to the flex.accounts config key.
        $this->assertSame(
            ['name' => 'flex'],
            $this->differ->stripEnvironmentOverrides(
                ['name' => 'flex', 'timeout' => '60'],
                'flex/accounts',
            ),
        );
    }

    private function setVar(string $name, string $value): void
    {
        $this->touched[] = $name;
        putenv("$name=$value");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    private function clearVar(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}
