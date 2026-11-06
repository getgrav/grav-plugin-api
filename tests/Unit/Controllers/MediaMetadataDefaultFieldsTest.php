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

/**
 * The built-in metadata fields stay in the editor when `media_metadata.fields`
 * lists other fields, unless an entry removes them (getgrav/grav-plugin-api#76).
 */
#[CoversClass(MediaController::class)]
class MediaMetadataDefaultFieldsTest extends TestCase
{
    private const DEFAULT_KEYS = ['alt', 'title', 'caption', 'description', 'tags'];

    /** @param mixed $fields */
    private function defs(mixed $fields): array
    {
        $config = new Config(['plugins' => ['api' => ['media_metadata' => $fields === null ? [] : ['fields' => $fields]]]]);
        TestHelper::createMockGrav(['config' => $config]);
        $controller = new MediaController(Grav::instance(), $config);

        return (new ReflectionMethod($controller, 'getMetadataFieldDefs'))->invoke($controller);
    }

    /** @return list<string> */
    private function keys(mixed $fields): array
    {
        return array_column($this->defs($fields), 'key');
    }

    #[Test]
    public function no_config_gives_the_built_in_fields(): void
    {
        $this->assertSame(self::DEFAULT_KEYS, $this->keys(null));
        $this->assertSame(self::DEFAULT_KEYS, $this->keys([]));
    }

    #[Test]
    public function a_custom_field_is_added_to_the_built_in_ones(): void
    {
        $this->assertSame(
            ['credit', 'alt', 'title', 'caption', 'description', 'tags'],
            $this->keys([['key' => 'credit', 'label' => 'Credit', 'type' => 'text']])
        );
    }

    #[Test]
    public function a_hand_written_list_after_grav_merges_it_by_position_keeps_every_built_in_field(): void
    {
        // `- { key: custom }` in user/config/plugins/api.yaml reaches the
        // controller as the shipped list with position 0 overwritten.
        $merged = [
            ['key' => 'custom', 'label' => 'Custom', 'type' => 'text'],
            ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
            ['key' => 'caption', 'label' => 'Caption', 'type' => 'textarea'],
            ['key' => 'description', 'label' => 'Description', 'type' => 'textarea'],
            ['key' => 'tags', 'label' => 'Tags', 'type' => 'tags'],
        ];

        $this->assertSame(['custom', 'title', 'caption', 'description', 'tags', 'alt'], $this->keys($merged));
    }

    #[Test]
    public function an_entry_with_a_built_in_key_replaces_it(): void
    {
        $defs = $this->defs([['key' => 'caption', 'label' => 'Credit line', 'type' => 'text']]);
        $byKey = array_column($defs, null, 'key');

        $this->assertSame(['caption', 'alt', 'title', 'description', 'tags'], array_column($defs, 'key'));
        $this->assertSame('Credit line', $byKey['caption']['label']);
        $this->assertSame('text', $byKey['caption']['type']);
    }

    #[Test]
    public function a_reordered_list_keeps_its_order(): void
    {
        $this->assertSame(
            ['tags', 'alt', 'title', 'caption', 'description'],
            $this->keys([['key' => 'tags', 'type' => 'tags'], ['key' => 'alt'], ['key' => 'title'], ['key' => 'caption', 'type' => 'textarea'], ['key' => 'description', 'type' => 'textarea']])
        );
    }

    #[Test]
    public function remove_takes_a_built_in_field_out_wherever_it_appears(): void
    {
        // Position 0 says remove, the shipped `tags` survives at position 4.
        $merged = [
            ['key' => 'tags', 'remove' => true],
            ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
            ['key' => 'caption', 'label' => 'Caption', 'type' => 'textarea'],
            ['key' => 'description', 'label' => 'Description', 'type' => 'textarea'],
            ['key' => 'tags', 'label' => 'Tags', 'type' => 'tags'],
        ];

        $this->assertSame(['title', 'caption', 'description', 'alt'], $this->keys($merged));
    }

    #[Test]
    public function remove_false_from_the_form_toggle_keeps_the_field(): void
    {
        $this->assertSame(self::DEFAULT_KEYS, $this->keys([['key' => 'alt', 'type' => 'text', 'remove' => false]]));
        $this->assertSame(['title', 'alt', 'caption', 'description', 'tags'], $this->keys([['key' => 'title', 'remove' => 0], ['key' => 'alt', 'remove' => '0']]));
    }

    #[Test]
    public function removing_every_field_leaves_the_editor_empty(): void
    {
        $this->assertSame([], $this->keys(array_map(static fn (string $k): array => ['key' => $k, 'remove' => true], self::DEFAULT_KEYS)));
    }

    #[Test]
    public function an_admin_saved_delta_keyed_by_position_still_adds_its_field(): void
    {
        // Admin2 saves an added row as a position delta (`fields: { 5: {...} }`),
        // which Grav lays over the shipped list.
        $merged = array_map(static fn (string $k): array => ['key' => $k], self::DEFAULT_KEYS);
        $merged[5] = ['key' => 'custom', 'label' => 'Custom', 'type' => 'text'];

        $this->assertSame(['alt', 'title', 'caption', 'description', 'tags', 'custom'], $this->keys($merged));
    }

    #[Test]
    public function reserved_keys_are_still_rejected(): void
    {
        $this->assertSame(self::DEFAULT_KEYS, $this->keys([['key' => 'width'], ['key' => 'mime']]));
    }
}
