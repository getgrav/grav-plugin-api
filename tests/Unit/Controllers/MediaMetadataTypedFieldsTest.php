<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;

/**
 * The typed `number`, `date` and `boolean` types of the configurable
 * `media_metadata.fields`, plus the `required` flag: values are stored as
 * native YAML scalars, read back the same way, and a batch validates the
 * shared field map once before it writes anything.
 */
#[CoversClass(MediaController::class)]
class MediaMetadataTypedFieldsTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private const FIELDS = [
        ['key' => 'rating', 'label' => 'Rating', 'type' => 'number', 'min' => 0, 'max' => 10],
        ['key' => 'taken', 'label' => 'Taken', 'type' => 'date'],
        ['key' => 'featured', 'label' => 'Featured', 'type' => 'boolean'],
        ['key' => 'credit', 'label' => 'Credit', 'type' => 'text', 'required' => true],
        ['key' => 'tags', 'label' => 'Tags', 'type' => 'tags'],
    ];

    private string $dir;
    private MediaController $controller;
    private object $user;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/grav_api_meta_typed_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/photos', 0777, true);
        file_put_contents($this->dir . '/photos/a.jpg', 'a');
        file_put_contents($this->dir . '/photos/b.jpg', 'b');

        $this->controller = $this->siteController(self::FIELDS);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
        Grav::resetInstance();
    }

    /**
     * A controller whose site media root is the temp dir, so batch writes have
     * real files to work on.
     *
     * @param list<array<string, mixed>> $fields
     */
    private function siteController(array $fields): MediaController
    {
        $dir = $this->dir;
        $locator = new class ($dir) {
            public function __construct(private readonly string $dir) {}
            public function findResource(string $uri, bool $absolute = false, bool $first = false): string
            {
                return $this->dir;
            }
        };

        $this->user = TestHelper::createMockUser('admin', [
            'access' => ['api' => ['access' => true, 'super' => true]],
        ]);
        $config = $this->config($fields);
        TestHelper::createMockGrav([
            'config' => $config,
            'locator' => $locator,
            'permissions' => new Permissions(),
            'accounts' => TestHelper::createMockAccounts(['admin' => $this->user]),
        ]);

        return new MediaController(Grav::instance(), $config);
    }

    /** @param list<array<string, mixed>> $fields */
    private function config(array $fields): Config
    {
        return new Config(['plugins' => ['api' => [
            'route' => '/api',
            'version_prefix' => 'v1',
            'media_metadata' => ['fields' => $fields],
        ]]]);
    }

    /** @param list<array<string, mixed>> $fields */
    private function controllerWith(array $fields): MediaController
    {
        $config = $this->config($fields);
        TestHelper::createMockGrav(['config' => $config]);

        return new MediaController(Grav::instance(), $config);
    }

    private function call(MediaController $controller, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($controller, $method))->invoke($controller, ...$args);
    }

    /** @return array<string, mixed> */
    private function field(string $key): array
    {
        foreach ($this->call($this->controller, 'getMetadataFieldDefs') as $def) {
            if ($def['key'] === $key) {
                return $def;
            }
        }
        $this->fail("No field definition for '$key'.");
    }

    private function mediaFile(): string
    {
        return $this->dir . '/photos/photo.jpg';
    }

    /** @return array<string, mixed> */
    private function stored(string $filePath = ''): array
    {
        $path = ($filePath !== '' ? $filePath : $this->mediaFile()) . '.meta.yaml';

        return is_file($path) ? (array) Yaml::parseFile($path) : [];
    }

    private function write(array $input, string $filePath = ''): void
    {
        $this->call($this->controller, 'applyMetaWrite', $filePath !== '' ? $filePath : $this->mediaFile(), $input);
    }

    /** @return array<string, array<string, mixed>> */
    private function fieldsByKey(string $filePath = ''): array
    {
        $response = $this->call($this->controller, 'buildMetaResponse', $filePath !== '' ? $filePath : $this->mediaFile(), 'photo.jpg');

        return array_column($response['fields'], null, 'key');
    }

    private function request(string $body): ServerRequestInterface
    {
        $user = $this->user;
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn (string $name, $default = null) => match ($name) {
                'api_user' => $user,
                'json_body' => json_decode($body, true) ?: [],
                default => $default,
            }
        );
        $request->method('getQueryParams')->willReturn([]);
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $request->method('getBody')->willReturn($stream);

        return $request;
    }

    private function batch(array $files, array $fields): ResponseInterface
    {
        return $this->controller->batchSiteMediaMeta(
            $this->request((string) json_encode(['files' => $files, 'fields' => $fields]))
        );
    }

    #[Test]
    public function typed_field_definitions_keep_their_type(): void
    {
        $this->assertSame('number', $this->field('rating')['type']);
        $this->assertSame('date', $this->field('taken')['type']);
        $this->assertSame('boolean', $this->field('featured')['type']);
    }

    #[Test]
    public function number_bounds_and_the_required_flag_are_carried_on_the_field(): void
    {
        $rating = $this->field('rating');
        $this->assertSame(0, $rating['min']);
        $this->assertSame(10, $rating['max']);

        $this->assertArrayNotHasKey('min', $this->field('taken'));
        $this->assertArrayNotHasKey('max', $this->field('taken'));

        $this->assertTrue($this->field('credit')['required']);
        $this->assertArrayNotHasKey('required', $this->field('taken'));
    }

    #[Test]
    public function bounds_are_only_read_from_number_fields(): void
    {
        $controller = $this->controllerWith([
            ['key' => 'taken', 'type' => 'date', 'min' => 0, 'max' => 10],
            ['key' => 'rating', 'type' => 'number', 'min' => 'nope', 'max' => ''],
        ]);

        $fields = array_column($this->call($controller, 'getMetadataFieldDefs'), null, 'key');
        $this->assertArrayNotHasKey('min', $fields['taken']);
        $this->assertArrayNotHasKey('min', $fields['rating']);
        $this->assertArrayNotHasKey('max', $fields['rating']);
    }

    #[Test]
    public function number_is_stored_as_a_yaml_number(): void
    {
        $this->write(['rating' => 5]);

        $this->assertSame(5, $this->stored()['rating']);
        $this->assertStringContainsString('rating: 5', (string) file_get_contents($this->mediaFile() . '.meta.yaml'));
    }

    #[Test]
    public function numeric_strings_and_decimals_become_numbers(): void
    {
        $this->write(['rating' => '4.5']);

        $this->assertSame(4.5, $this->stored()['rating']);
    }

    #[Test]
    public function non_numeric_value_is_rejected(): void
    {
        $this->write(['rating' => 5]);

        $this->expectException(ValidationException::class);
        try {
            $this->write(['rating' => 'five']);
        } finally {
            // The rejected write never reached the sidecar.
            $this->assertSame(5, $this->stored()['rating']);
        }
    }

    #[Test]
    public function number_outside_the_bounds_is_rejected(): void
    {
        foreach ([11, -1, '10.5'] as $value) {
            try {
                $this->write(['rating' => $value]);
                $this->fail('An out-of-range number should be rejected: ' . var_export($value, true));
            } catch (ValidationException) {
                $this->assertSame([], $this->stored());
            }
        }

        $this->write(['rating' => 0]);
        $this->assertSame(0, $this->stored()['rating']);
        $this->write(['rating' => 10]);
        $this->assertSame(10, $this->stored()['rating']);
    }

    #[Test]
    public function a_number_field_without_bounds_accepts_any_number(): void
    {
        $controller = $this->controllerWith([
            ['key' => 'rating', 'type' => 'number'],
        ]);

        $this->call($controller, 'applyMetaWrite', $this->mediaFile(), ['rating' => 1000000]);

        $this->assertSame(1000000, $this->stored()['rating']);
    }

    #[Test]
    public function empty_number_clears_the_key(): void
    {
        $this->write(['rating' => 5]);
        $this->write(['rating' => '']);

        $this->assertArrayNotHasKey('rating', $this->stored());
        $this->assertFileDoesNotExist($this->mediaFile() . '.meta.yaml');
    }

    #[Test]
    public function date_is_stored_as_a_quoted_yyyy_mm_dd_string(): void
    {
        $this->write(['taken' => '2026-10-19']);

        // Quoted in the file, so it stays a string instead of becoming a timestamp.
        $this->assertStringContainsString("taken: '2026-10-19'", (string) file_get_contents($this->mediaFile() . '.meta.yaml'));
        $this->assertSame('2026-10-19', $this->stored()['taken']);
    }

    #[Test]
    public function date_rejects_an_impossible_day(): void
    {
        $this->expectException(ValidationException::class);
        $this->write(['taken' => '2026-02-30']);
    }

    #[Test]
    public function date_rejects_another_format(): void
    {
        $this->expectException(ValidationException::class);
        $this->write(['taken' => '19/10/2026']);
    }

    #[Test]
    public function date_rejects_a_timestamp_integer_on_write(): void
    {
        // Only the read path turns a hand-written timestamp back into a day;
        // a write still has to send the explicit YYYY-MM-DD string.
        $this->expectException(ValidationException::class);
        $this->write(['taken' => 1792368000]);
    }

    #[Test]
    public function empty_date_clears_the_key(): void
    {
        $this->write(['taken' => '2026-10-19']);
        $this->write(['taken' => '']);

        $this->assertArrayNotHasKey('taken', $this->stored());
    }

    #[Test]
    public function boolean_true_is_stored_and_false_removes_the_key(): void
    {
        $this->write(['featured' => true]);
        $this->assertTrue($this->stored()['featured']);
        $this->assertStringContainsString('featured: true', (string) file_get_contents($this->mediaFile() . '.meta.yaml'));

        $this->write(['featured' => false]);
        $this->assertArrayNotHasKey('featured', $this->stored());
    }

    #[Test]
    public function boolean_accepts_the_string_and_number_forms(): void
    {
        foreach (['1', 'true', 1] as $value) {
            $this->write(['featured' => $value]);
            $this->assertTrue($this->stored()['featured'], 'Failed for ' . var_export($value, true));
        }

        foreach (['0', 'false', 0] as $value) {
            $this->write(['featured' => $value]);
            $this->assertArrayNotHasKey('featured', $this->stored(), 'Failed for ' . var_export($value, true));
        }
    }

    #[Test]
    public function boolean_rejects_anything_else(): void
    {
        $this->expectException(ValidationException::class);
        $this->write(['featured' => 'yes']);
    }

    #[Test]
    public function required_field_cannot_be_sent_empty(): void
    {
        foreach (['', '   '] as $value) {
            try {
                $this->write(['credit' => $value]);
                $this->fail('A required field sent empty should be rejected: ' . var_export($value, true));
            } catch (ValidationException) {
                $this->assertSame([], $this->stored());
            }
        }
    }

    #[Test]
    public function required_field_accepts_a_value(): void
    {
        $this->write(['credit' => 'Jane Doe']);

        $this->assertSame('Jane Doe', $this->stored()['credit']);
    }

    #[Test]
    public function required_field_is_left_alone_when_the_key_is_absent(): void
    {
        $this->write(['credit' => 'Jane Doe']);
        $this->write(['rating' => 3]);

        $this->assertSame('Jane Doe', $this->stored()['credit']);
        $this->assertSame(3, $this->stored()['rating']);
    }

    #[Test]
    public function required_tags_field_rejects_an_empty_list(): void
    {
        $controller = $this->controllerWith([
            ['key' => 'tags', 'type' => 'tags', 'required' => true],
        ]);

        $this->expectException(ValidationException::class);
        $this->call($controller, 'applyMetaWrite', $this->mediaFile(), ['tags' => []]);
    }

    #[Test]
    public function clearing_all_metadata_ignores_required(): void
    {
        $this->write(['credit' => 'Jane Doe']);

        $this->call($this->controller, 'clearMetaFields', $this->mediaFile());

        $this->assertFileDoesNotExist($this->mediaFile() . '.meta.yaml');
    }

    #[Test]
    public function response_returns_a_number_and_a_boolean_as_native_json_types(): void
    {
        file_put_contents($this->mediaFile() . '.meta.yaml', "rating: 5\nfeatured: true\n");

        $fields = $this->fieldsByKey();

        $this->assertSame(5, $fields['rating']['value']);
        $this->assertSame(true, $fields['featured']['value']);
        $this->assertSame('{"key":"rating","label":"Rating","type":"number","value":5,"min":0,"max":10}', json_encode($fields['rating']));
    }

    #[Test]
    public function response_coerces_a_numeric_string_stored_by_hand(): void
    {
        file_put_contents($this->mediaFile() . '.meta.yaml', "rating: '7'\n");

        $this->assertSame(7, $this->fieldsByKey()['rating']['value']);
    }

    #[Test]
    public function response_turns_a_hand_written_timestamp_back_into_its_day(): void
    {
        // Unquoted, so YAML hands it over as the integer 1792368000.
        file_put_contents($this->mediaFile() . '.meta.yaml', "taken: 2026-10-19\n");

        $this->assertSame('2026-10-19', $this->fieldsByKey()['taken']['value']);
    }

    #[Test]
    public function response_passes_through_a_stored_value_that_does_not_fit_the_type(): void
    {
        file_put_contents($this->mediaFile() . '.meta.yaml', "rating: twelve\ntaken: someday\nfeatured: maybe\n");

        $fields = $this->fieldsByKey();

        $this->assertSame('twelve', $fields['rating']['value']);
        $this->assertSame('someday', $fields['taken']['value']);
        $this->assertSame('maybe', $fields['featured']['value']);
    }

    #[Test]
    public function response_carries_the_bounds_and_the_required_flag(): void
    {
        $fields = $this->fieldsByKey();

        $this->assertSame(0, $fields['rating']['min']);
        $this->assertSame(10, $fields['rating']['max']);
        $this->assertArrayNotHasKey('min', $fields['taken']);
        $this->assertTrue($fields['credit']['required']);
        $this->assertArrayNotHasKey('required', $fields['taken']);
    }

    #[Test]
    public function batch_writes_the_shared_fields_to_every_file(): void
    {
        $payload = json_decode((string) $this->batch(
            ['photos/a.jpg', 'photos/b.jpg'],
            ['rating' => '6', 'featured' => true],
        )->getBody(), true)['data'];

        $this->assertSame(2, $payload['successful']);
        foreach (['/photos/a.jpg', '/photos/b.jpg'] as $file) {
            $stored = $this->stored($this->dir . $file);
            $this->assertSame(6, $stored['rating']);
            $this->assertTrue($stored['featured']);
        }
    }

    #[Test]
    public function batch_rejects_an_invalid_field_map_before_writing_any_file(): void
    {
        try {
            $this->batch(['photos/a.jpg', 'photos/b.jpg'], ['rating' => 'six']);
            $this->fail('An invalid shared value should fail the whole batch.');
        } catch (ValidationException) {
            $this->assertFileDoesNotExist($this->dir . '/photos/a.jpg.meta.yaml');
            $this->assertFileDoesNotExist($this->dir . '/photos/b.jpg.meta.yaml');
        }
    }

    #[Test]
    public function batch_rejects_a_required_field_sent_empty_before_writing_any_file(): void
    {
        try {
            $this->batch(['photos/a.jpg', 'photos/b.jpg'], ['credit' => '']);
            $this->fail('A required field sent empty should fail the whole batch.');
        } catch (ValidationException) {
            $this->assertFileDoesNotExist($this->dir . '/photos/a.jpg.meta.yaml');
            $this->assertFileDoesNotExist($this->dir . '/photos/b.jpg.meta.yaml');
        }
    }

    #[Test]
    public function batch_still_reports_a_per_file_problem_in_its_own_row(): void
    {
        $payload = json_decode((string) $this->batch(
            ['photos/missing.jpg', 'photos/a.jpg'],
            ['rating' => 7],
        )->getBody(), true)['data'];

        $this->assertSame(1, $payload['successful']);
        $this->assertSame(1, $payload['failed']);
        $this->assertSame('error', $payload['results'][0]['status']);
        $this->assertSame('success', $payload['results'][1]['status']);
        $this->assertSame(7, $this->stored($this->dir . '/photos/a.jpg')['rating']);
    }
}
