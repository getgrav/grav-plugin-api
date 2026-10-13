<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The `select` type of the configurable `media_metadata.fields`: options are
 * kept on the field definition and in the read response, only an option key
 * (or an empty value) can be written, and a select without options is dropped.
 */
#[CoversClass(MediaController::class)]
class MediaMetadataSelectFieldTest extends TestCase
{
    private const OPTIONS = [
        'generated' => 'AI generated',
        'altered' => 'AI altered',
        'none' => 'No AI',
    ];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/grav_api_meta_select_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** @param list<array<string, mixed>>|null $fields */
    private function controller(?array $fields): MediaController
    {
        $config = new Config(['plugins' => ['api' => ['media_metadata' => $fields === null ? [] : ['fields' => $fields]]]]);
        TestHelper::createMockGrav(['config' => $config]);

        return new MediaController(Grav::instance(), $config);
    }

    private function call(MediaController $controller, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($controller, $method))->invoke($controller, ...$args);
    }

    /** @return array<string, mixed> */
    private function field(MediaController $controller, string $key): array
    {
        foreach ($this->call($controller, 'getMetadataFieldDefs') as $def) {
            if ($def['key'] === $key) {
                return $def;
            }
        }
        $this->fail("No field definition for '$key'.");
    }

    #[Test]
    public function select_field_keeps_its_options(): void
    {
        $controller = $this->controller([
            ['key' => 'ai_status', 'label' => 'AI status', 'type' => 'select', 'options' => self::OPTIONS],
        ]);

        $def = $this->field($controller, 'ai_status');
        $this->assertSame('select', $def['type']);
        $this->assertSame(self::OPTIONS, $def['options']);
    }

    #[Test]
    public function non_select_fields_do_not_gain_options(): void
    {
        $controller = $this->controller([
            ['key' => 'alt', 'type' => 'text', 'options' => ['a' => 'A']],
            ['key' => 'tags', 'type' => 'tags'],
        ]);

        $this->assertSame(['key' => 'alt', 'label' => 'alt', 'type' => 'text'], $this->field($controller, 'alt'));
        $this->assertSame('tags', $this->field($controller, 'tags')['type']);
    }

    #[Test]
    public function select_without_options_is_dropped(): void
    {
        foreach ([null, [], 'generated', ['' => 'Blank', 'x' => ['nested']]] as $options) {
            $controller = $this->controller([
                ['key' => 'ai_status', 'type' => 'select', 'options' => $options],
                ['key' => 'alt', 'type' => 'text'],
            ]);

            $keys = array_column($this->call($controller, 'getMetadataFieldDefs'), 'key');
            $this->assertSame(['alt'], $keys);
        }
    }

    #[Test]
    public function unknown_type_still_falls_back_to_text(): void
    {
        $controller = $this->controller([
            ['key' => 'rating', 'type' => 'slider', 'options' => ['1' => 'One']],
        ]);

        $def = $this->field($controller, 'rating');
        $this->assertSame('text', $def['type']);
        $this->assertArrayNotHasKey('options', $def);
    }

    #[Test]
    public function option_labels_are_cleaned_and_blank_labels_use_the_value(): void
    {
        $controller = $this->controller([
            ['key' => 'ai_status', 'type' => 'select', 'options' => ['a' => '<b>Alpha</b>', 'b' => '', 'c' => 7]],
        ]);

        $this->assertSame(['a' => 'Alpha', 'b' => 'b', 'c' => '7'], $this->field($controller, 'ai_status')['options']);
    }

    #[Test]
    public function valid_option_key_is_accepted(): void
    {
        $controller = $this->controller(null);

        $this->assertSame('altered', $this->call($controller, 'sanitizeMetadataValue', 'altered', 'select', 2000, self::OPTIONS));
        $this->assertSame('altered', $this->call($controller, 'sanitizeMetadataValue', '  altered ', 'select', 2000, self::OPTIONS));
    }

    #[Test]
    public function empty_value_clears_a_select(): void
    {
        $controller = $this->controller(null);

        $this->assertSame('', $this->call($controller, 'sanitizeMetadataValue', '', 'select', 2000, self::OPTIONS));
        $this->assertSame('', $this->call($controller, 'sanitizeMetadataValue', null, 'select', 2000, self::OPTIONS));
    }

    #[Test]
    public function numeric_option_keys_are_accepted(): void
    {
        $controller = $this->controller(null);

        $this->assertSame('1', $this->call($controller, 'sanitizeMetadataValue', 1, 'select', 2000, [0 => 'No', 1 => 'Yes']));
    }

    #[Test]
    public function value_outside_the_options_is_rejected(): void
    {
        $controller = $this->controller(null);

        $this->expectException(ValidationException::class);
        $this->call($controller, 'sanitizeMetadataValue', 'Generated', 'select', 2000, self::OPTIONS);
    }

    #[Test]
    public function array_value_is_rejected_for_a_select(): void
    {
        $controller = $this->controller(null);

        $this->expectException(ValidationException::class);
        $this->call($controller, 'sanitizeMetadataValue', ['generated'], 'select', 2000, self::OPTIONS);
    }

    #[Test]
    public function text_sanitizing_is_unchanged(): void
    {
        $controller = $this->controller(null);

        $this->assertSame('a b', $this->call($controller, 'sanitizeMetadataValue', "<i>a</i>\n b", 'text', 2000));
    }

    #[Test]
    public function meta_response_returns_options_with_the_field(): void
    {
        $controller = $this->controller([
            ['key' => 'ai_status', 'label' => 'AI status', 'type' => 'select', 'options' => self::OPTIONS],
            ['key' => 'alt', 'type' => 'text'],
        ]);
        $file = $this->dir . '/photo.jpg';
        file_put_contents($file . '.meta.yaml', "ai_status: altered\n");

        $response = $this->call($controller, 'buildMetaResponse', $file, 'photo.jpg');

        $select = $response['fields'][0];
        $this->assertSame('select', $select['type']);
        $this->assertSame('altered', $select['value']);
        $this->assertSame(self::OPTIONS, (array) $select['options']);
        $this->assertSame('{"generated":"AI generated","altered":"AI altered","none":"No AI"}', json_encode($select['options']));
        $this->assertArrayNotHasKey('options', $response['fields'][1]);
    }

    #[Test]
    public function numeric_option_keys_serialize_as_an_object(): void
    {
        $controller = $this->controller([
            ['key' => 'rating', 'type' => 'select', 'options' => [0 => 'No', 1 => 'Yes']],
        ]);

        $response = $this->call($controller, 'buildMetaResponse', $this->dir . '/photo.jpg', 'photo.jpg');

        $this->assertSame('{"0":"No","1":"Yes"}', json_encode($response['fields'][0]['options']));
    }

    #[Test]
    public function writing_applies_the_options_and_rejects_others(): void
    {
        $controller = $this->controller([
            ['key' => 'ai_status', 'type' => 'select', 'options' => self::OPTIONS],
        ]);
        $file = $this->dir . '/photo.jpg';

        $this->call($controller, 'applyMetaWrite', $file, ['ai_status' => 'generated']);
        $this->assertStringContainsString('ai_status: generated', (string) file_get_contents($file . '.meta.yaml'));

        try {
            $this->call($controller, 'applyMetaWrite', $file, ['ai_status' => 'bogus']);
            $this->fail('An unknown option should be rejected.');
        } catch (ValidationException) {
            $this->assertStringContainsString('ai_status: generated', (string) file_get_contents($file . '.meta.yaml'));
        }

        $this->call($controller, 'applyMetaWrite', $file, ['ai_status' => '']);
        $this->assertFileDoesNotExist($file . '.meta.yaml');
    }
}
