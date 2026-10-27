<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The page-media counterpart of `POST /media/batch/meta`: the shared field
 * map is validated once before anything is written, then each filename is
 * handled on its own (a missing or invalid name gets its own result row), and
 * every sidecar keeps the keys that are not being written.
 */
#[CoversClass(MediaController::class)]
class MediaPageBatchMetadataTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private const FIELDS = [
        ['key' => 'rating', 'label' => 'Rating', 'type' => 'number', 'min' => 0, 'max' => 10],
        ['key' => 'credit', 'label' => 'Credit', 'type' => 'text', 'required' => true],
        ['key' => 'tags', 'label' => 'Tags', 'type' => 'tags'],
    ];

    private string $dir;
    private string $pageDir;
    private Grav $grav;
    private MediaController $controller;
    private object $user;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/grav_api_page_batch_' . bin2hex(random_bytes(4));
        $this->pageDir = $this->dir . '/pages/01.photos';
        mkdir($this->pageDir, 0777, true);

        file_put_contents($this->pageDir . '/a.jpg', 'a');
        file_put_contents($this->pageDir . '/b.jpg', 'b');
        // A hand-written sidecar: the batch overwrites `rating`, keeps `Lat`.
        file_put_contents($this->pageDir . '/a.jpg.meta.yaml', "Lat: '41.9'\nrating: '4'\n");

        $this->controller = $this->pageController(self::FIELDS);
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

    /** @param list<array<string, mixed>> $fields */
    private function pageController(array $fields): MediaController
    {
        $pageDir = $this->pageDir;
        $page = $this->createMock(PageInterface::class);
        $page->method('path')->willReturn($pageDir);
        $page->method('rawRoute')->willReturn('/photos');
        $page->method('route')->willReturn('/photos');

        $pages = $this->createMock(Pages::class);
        $pages->method('enablePages');
        $pages->method('find')->willReturnCallback(
            static fn (string $route): ?PageInterface => $route === '/photos' ? $page : null
        );

        $this->user = TestHelper::createMockUser('admin', [
            'access' => ['api' => ['access' => true, 'super' => true]],
        ]);
        $config = new Config(['plugins' => ['api' => [
            'route' => '/api',
            'version_prefix' => 'v1',
            'media_metadata' => ['fields' => $fields],
        ]]]);

        $this->grav = TestHelper::createMockGrav([
            'config' => $config,
            'pages' => $pages,
            'permissions' => new Permissions(),
            'accounts' => TestHelper::createMockAccounts(['admin' => $this->user]),
        ]);

        return new MediaController($this->grav, $config);
    }

    /** @return array<string, mixed> */
    private function stored(string $filename): array
    {
        $path = $this->pageDir . '/' . $filename . '.meta.yaml';

        return is_file($path) ? (array) Yaml::parseFile($path) : [];
    }

    private function request(array $body): ServerRequestInterface
    {
        return TestHelper::createMockRequest(
            method: 'POST',
            path: '/api/v1/pages/photos/media/batch/meta',
            body: (string) json_encode($body),
            attributes: [
                'api_user' => $this->user,
                'route_params' => ['route' => 'photos'],
                'json_body' => $body,
            ],
        );
    }

    private function batch(array $files, array $fields): ResponseInterface
    {
        return $this->controller->batchPageMediaMeta(
            $this->request(['files' => $files, 'fields' => $fields])
        );
    }

    #[Test]
    public function batch_writes_the_shared_fields_to_every_file_and_keeps_other_keys(): void
    {
        $payload = json_decode((string) $this->batch(
            ['a.jpg', 'b.jpg'],
            ['rating' => '7', 'credit' => 'Batch test', 'tags' => ['hero']],
        )->getBody(), true)['data'];

        $this->assertSame(2, $payload['successful']);
        $this->assertSame(0, $payload['failed']);
        $this->assertSame(['a.jpg', 'b.jpg'], array_column($payload['results'], 'filename'));

        $a = $this->stored('a.jpg');
        $this->assertSame(7, $a['rating']);
        $this->assertSame('Batch test', $a['credit']);
        $this->assertSame(['hero'], $a['tags']);
        $this->assertSame('41.9', $a['Lat']);

        $b = $this->stored('b.jpg');
        $this->assertSame(7, $b['rating']);
        $this->assertSame('Batch test', $b['credit']);
    }

    #[Test]
    public function batch_rejects_an_invalid_field_map_before_writing_any_file(): void
    {
        $before = $this->stored('a.jpg');

        try {
            $this->batch(['a.jpg', 'b.jpg'], ['rating' => 99]);
            $this->fail('An invalid shared value should fail the whole batch.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('at most 10', $e->getMessage());
        }

        $this->assertSame($before, $this->stored('a.jpg'));
        $this->assertFileDoesNotExist($this->pageDir . '/b.jpg.meta.yaml');
    }

    #[Test]
    public function batch_rejects_a_required_field_sent_empty_before_writing_any_file(): void
    {
        $before = $this->stored('a.jpg');

        try {
            $this->batch(['a.jpg', 'b.jpg'], ['credit' => '']);
            $this->fail('A required field sent empty should fail the whole batch.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString("'credit' is required", $e->getMessage());
        }

        $this->assertSame($before, $this->stored('a.jpg'));
        $this->assertFileDoesNotExist($this->pageDir . '/b.jpg.meta.yaml');
    }

    #[Test]
    public function batch_reports_a_missing_file_in_its_own_row(): void
    {
        $payload = json_decode((string) $this->batch(
            ['missing.jpg', 'a.jpg'],
            ['rating' => 6],
        )->getBody(), true)['data'];

        $this->assertSame(1, $payload['successful']);
        $this->assertSame(1, $payload['failed']);
        $this->assertSame('error', $payload['results'][0]['status']);
        $this->assertStringContainsString('not found on this page', $payload['results'][0]['message']);
        $this->assertSame('success', $payload['results'][1]['status']);
        $this->assertSame(6, $this->stored('a.jpg')['rating']);
    }

    #[Test]
    public function batch_rejects_filenames_that_are_not_flat_names(): void
    {
        $payload = json_decode((string) $this->batch(
            ['nested/a.jpg', '../a.jpg', '.hidden.jpg', 42],
            ['rating' => 5],
        )->getBody(), true)['data'];

        $this->assertSame(0, $payload['successful']);
        $this->assertSame(4, $payload['failed']);
        foreach ($payload['results'] as $row) {
            $this->assertSame('error', $row['status']);
            $this->assertContains($row['message'], ['Filename is required.', 'Invalid filename.']);
        }
        $this->assertSame(['Lat' => '41.9', 'rating' => '4'], $this->stored('a.jpg'));
    }

    #[Test]
    public function batch_rejects_a_non_array_file_list(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('non-empty array');

        $this->controller->batchPageMediaMeta(
            $this->request(['files' => 'a.jpg', 'fields' => ['rating' => 5]])
        );
    }

    #[Test]
    public function batch_rejects_an_empty_file_list(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('non-empty array');

        $this->batch([], ['rating' => 5]);
    }

    #[Test]
    public function batch_rejects_a_fields_value_that_is_not_an_object(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('object of metadata values');

        $this->controller->batchPageMediaMeta(
            $this->request(['files' => ['a.jpg'], 'fields' => 'rating=5'])
        );
    }

    #[Test]
    public function batch_caps_the_number_of_files(): void
    {
        $files = array_map(static fn (int $i): string => "f{$i}.jpg", range(0, 50));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('limited to 50 files');

        $this->batch($files, ['rating' => 5]);
    }

    #[Test]
    public function batch_sets_the_page_invalidation_tags(): void
    {
        $response = $this->batch(['a.jpg'], ['rating' => 5]);

        $this->assertSame(
            'media:update:pages/photos, pages:update:/photos',
            $response->getHeaderLine('X-Invalidates')
        );
    }

    #[Test]
    public function batch_fires_the_page_metadata_event_once_per_written_file(): void
    {
        $seen = [];
        $this->grav->addListener(
            'onApiMediaMetadataUpdated',
            static function (object $event) use (&$seen): void {
                $seen[] = $event['filename'];
            }
        );

        $this->batch(['a.jpg', 'b.jpg', 'missing.jpg'], ['rating' => 5]);

        $this->assertSame(['a.jpg', 'b.jpg'], $seen);
    }
}
