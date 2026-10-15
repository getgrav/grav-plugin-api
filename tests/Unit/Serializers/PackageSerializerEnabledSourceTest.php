<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Serializers;

use Grav\Plugin\Api\Serializers\PackageSerializer;
use Grav\Plugin\Api\Tests\Unit\Services\ShadowsFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Installed plugins carry `enabled_source` when their live `enabled` comes from
 * a layer above base, so the admin can mark a toggle that a save to base would
 * not change (getgrav/grav-plugin-sync-mercure#1).
 */
#[CoversClass(PackageSerializer::class)]
class PackageSerializerEnabledSourceTest extends TestCase
{
    private ShadowsFixture $site;

    protected function setUp(): void
    {
        $this->site = new ShadowsFixture();
        $this->site->write('user/plugins/mercure/mercure.yaml', "enabled: true\n");
        $this->site->write('user/plugins/plain/plain.yaml', "enabled: true\n");
        $this->site->write('user/env/example.com/config/plugins/mercure.yaml', "enabled: false\n");
    }

    protected function tearDown(): void
    {
        $this->site->cleanup();
    }

    #[Test]
    public function a_plugin_disabled_by_the_active_environment_names_it(): void
    {
        $this->site->bootEnvironment('example.com');
        $this->site->grav(['plugins' => ['mercure' => ['enabled' => false], 'plain' => ['enabled' => true]]]);

        $data = $this->installed('mercure');

        self::assertFalse($data['enabled']);
        self::assertSame('environment', $data['enabled_source']['source']);
        self::assertSame('example.com', $data['enabled_source']['environment']);
        self::assertStringEndsWith(
            'user/env/example.com/config/plugins/mercure.yaml',
            $data['enabled_source']['file'],
        );
    }

    #[Test]
    public function a_plugin_enabled_by_a_server_variable_names_the_server(): void
    {
        $this->site->serverVar('GRAV_CONFIG__plugins__plain__enabled', 'false');
        $this->site->grav(['plugins' => ['plain' => ['enabled' => false]]]);

        self::assertSame(
            ['source' => 'server', 'environment' => null, 'file' => null],
            $this->installed('plain')['enabled_source'],
        );
    }

    #[Test]
    public function a_plugin_enabled_in_base_has_a_null_source(): void
    {
        $this->site->bootEnvironment('example.com');
        $this->site->grav(['plugins' => ['plain' => ['enabled' => true]]]);

        $data = $this->installed('plain');

        self::assertArrayHasKey('enabled_source', $data);
        self::assertNull($data['enabled_source']);
    }

    #[Test]
    public function themes_and_repository_packages_do_not_carry_it(): void
    {
        $this->site->bootEnvironment('example.com');
        $this->site->grav(['system' => ['pages' => ['theme' => 'quark']]]);
        $serializer = new PackageSerializer();

        $theme = $serializer->serialize((object) ['slug' => 'quark'], ['type' => 'theme', 'installed' => true]);
        $remote = $serializer->serialize((object) ['slug' => 'mercure'], ['type' => 'plugin']);

        self::assertArrayNotHasKey('enabled_source', $theme);
        self::assertArrayNotHasKey('enabled_source', $remote);
    }

    /**
     * @return array<string, mixed>
     */
    private function installed(string $slug): array
    {
        return (new PackageSerializer())->serialize((object) ['slug' => $slug], ['type' => 'plugin', 'installed' => true]);
    }
}
