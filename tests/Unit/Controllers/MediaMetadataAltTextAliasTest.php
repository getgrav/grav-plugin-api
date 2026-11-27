<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;

/**
 * admin2#227: core renders a sidecar's `alt_text` as the image alt when `alt`
 * is empty (MediaObjectTrait::parsedownElement), and the Media docs' metafile
 * example uses `alt_text`. The metadata editor's Alt Text field reads that key
 * when `alt` is absent and writes the edit back to it, instead of showing it
 * as read-only "Stored metadata" next to an empty Alt Text field.
 */
#[CoversClass(MediaController::class)]
class MediaMetadataAltTextAliasTest extends TestCase
{
    private string $dir;
    private string $file;
    private MediaController $controller;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/grav_api_meta_alt_text_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->file = $this->dir . '/photo.jpg';
        touch($this->file);

        $config = new Config(['plugins' => ['api' => ['media_metadata' => []]]]);
        TestHelper::createMockGrav(['config' => $config]);
        $this->controller = new MediaController(Grav::instance(), $config);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($this->controller, $method))->invoke($this->controller, ...$args);
    }

    /** @param array<string, mixed> $data */
    private function writeSidecar(array $data): void
    {
        file_put_contents($this->file . '.meta.yaml', Yaml::dump($data));
    }

    /** @return array<string, mixed> */
    private function sidecar(): array
    {
        $path = $this->file . '.meta.yaml';

        return is_file($path) ? (array) Yaml::parse((string) file_get_contents($path)) : [];
    }

    /** @return array<string, mixed> */
    private function response(): array
    {
        return $this->call('buildMetaResponse', $this->file, 'photo.jpg');
    }

    private function fieldValue(array $response, string $key): mixed
    {
        foreach ($response['fields'] as $field) {
            if ($field['key'] === $key) {
                return $field['value'];
            }
        }
        $this->fail("No field '$key' in the response.");
    }

    #[Test]
    public function alt_text_fills_the_alt_field_and_leaves_stored_metadata(): void
    {
        $this->writeSidecar(['alt_text' => 'My Alt Text', 'credit' => 'Jo']);

        $response = $this->response();

        $this->assertSame('My Alt Text', $this->fieldValue($response, 'alt'));
        $this->assertSame(['credit' => 'Jo'], $response['extra']);
    }

    #[Test]
    public function editing_alt_updates_alt_text_in_place(): void
    {
        $this->writeSidecar(['alt_text' => 'My Alt Text']);

        $this->call('applyMetaWrite', $this->file, ['alt' => 'New Alt']);

        $this->assertSame(['alt_text' => 'New Alt'], $this->sidecar());
        $this->assertSame('New Alt', $this->fieldValue($this->response(), 'alt'));
    }

    #[Test]
    public function clearing_alt_removes_alt_text(): void
    {
        $this->writeSidecar(['alt_text' => 'My Alt Text', 'credit' => 'Jo']);

        $this->call('applyMetaWrite', $this->file, ['alt' => '']);

        $this->assertSame(['credit' => 'Jo'], $this->sidecar());
    }

    #[Test]
    public function deleting_metadata_clears_alt_text(): void
    {
        $this->writeSidecar(['alt_text' => 'My Alt Text']);

        $this->call('clearMetaFields', $this->file);

        $this->assertFileDoesNotExist($this->file . '.meta.yaml');
    }

    #[Test]
    public function new_alt_is_written_as_alt(): void
    {
        $this->call('applyMetaWrite', $this->file, ['alt' => 'Fresh']);

        $this->assertSame(['alt' => 'Fresh'], $this->sidecar());
    }

    #[Test]
    public function alt_wins_over_alt_text_as_in_core(): void
    {
        // Core renders `alt` first; `alt_text` stays visible as stored metadata.
        $this->writeSidecar(['alt' => 'Rendered', 'alt_text' => 'Ignored by core']);

        $response = $this->response();
        $this->assertSame('Rendered', $this->fieldValue($response, 'alt'));
        $this->assertSame(['alt_text' => 'Ignored by core'], $response['extra']);

        $this->call('applyMetaWrite', $this->file, ['alt' => 'Edited']);
        $this->assertSame(['alt' => 'Edited', 'alt_text' => 'Ignored by core'], $this->sidecar());
    }

    #[Test]
    public function site_listing_exposes_alt_text_as_alt(): void
    {
        $this->writeSidecar(['alt_text' => 'My Alt Text']);

        $item = $this->call('serializeSiteFile', $this->dir, 'photo.jpg', '');

        $this->assertSame('My Alt Text', $item['alt'] ?? null);
    }
    #[Test]
    public function a_configured_alt_text_field_is_not_borrowed(): void
    {
        $config = new Config(['plugins' => ['api' => ['media_metadata' => ['fields' => [
            ['key' => 'alt_text', 'label' => 'Legacy alt', 'type' => 'text'],
        ]]]]]);
        TestHelper::createMockGrav(['config' => $config]);
        $this->controller = new MediaController(Grav::instance(), $config);
        $this->writeSidecar(['alt_text' => 'Own field']);

        $response = $this->response();
        $this->assertSame('', $this->fieldValue($response, 'alt'));
        $this->assertSame('Own field', $this->fieldValue($response, 'alt_text'));

        $this->call('applyMetaWrite', $this->file, ['alt' => 'Separate']);
        $this->assertSame(['alt_text' => 'Own field', 'alt' => 'Separate'], $this->sidecar());
    }
}
