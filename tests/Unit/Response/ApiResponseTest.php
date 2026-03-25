<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Response;

use Grav\Plugin\Api\Response\ApiResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(ApiResponse::class)]
class ApiResponseTest extends TestCase
{
    #[Test]
    public function create_returns_json_response(): void
    {
        $response = ApiResponse::create(['name' => 'test']);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);
        self::assertSame(['name' => 'test'], $body['data']);
    }

    #[Test]
    public function create_with_custom_status(): void
    {
        $response = ApiResponse::create(['ok' => true], 202);

        self::assertSame(202, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['ok' => true], $body['data']);
    }

    #[Test]
    public function create_includes_cache_control_header(): void
    {
        $response = ApiResponse::create(['x' => 1]);

        self::assertSame('no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function paginated_response_structure(): void
    {
        $items = [['id' => 1], ['id' => 2]];

        $response = ApiResponse::paginated(
            data: $items,
            total: 50,
            page: 2,
            perPage: 10,
            baseUrl: '/api/items',
        );

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);

        self::assertArrayHasKey('data', $body);
        self::assertSame($items, $body['data']);

        self::assertArrayHasKey('meta', $body);
        self::assertArrayHasKey('pagination', $body['meta']);

        $pagination = $body['meta']['pagination'];
        self::assertSame(2, $pagination['page']);
        self::assertSame(10, $pagination['per_page']);
        self::assertSame(50, $pagination['total']);
        self::assertSame(5, $pagination['total_pages']);

        self::assertArrayHasKey('links', $body);
        self::assertArrayHasKey('self', $body['links']);
    }

    #[Test]
    public function paginated_response_links(): void
    {
        $response = ApiResponse::paginated(
            data: [],
            total: 50,
            page: 3,
            perPage: 10,
            baseUrl: '/api/items',
        );

        $body = json_decode((string) $response->getBody(), true);
        $links = $body['links'];

        self::assertStringContainsString('page=3', $links['self']);
        self::assertStringContainsString('per_page=10', $links['self']);

        self::assertArrayHasKey('first', $links);
        self::assertStringContainsString('page=1', $links['first']);

        self::assertArrayHasKey('prev', $links);
        self::assertStringContainsString('page=2', $links['prev']);

        self::assertArrayHasKey('next', $links);
        self::assertStringContainsString('page=4', $links['next']);

        self::assertArrayHasKey('last', $links);
        self::assertStringContainsString('page=5', $links['last']);
    }

    #[Test]
    public function paginated_first_page_no_prev_link(): void
    {
        $response = ApiResponse::paginated(
            data: [],
            total: 30,
            page: 1,
            perPage: 10,
            baseUrl: '/api/items',
        );

        $body = json_decode((string) $response->getBody(), true);
        $links = $body['links'];

        self::assertArrayNotHasKey('first', $links, 'First page should not have a "first" link');
        self::assertArrayNotHasKey('prev', $links, 'First page should not have a "prev" link');
        self::assertArrayHasKey('next', $links);
        self::assertArrayHasKey('last', $links);
    }

    #[Test]
    public function paginated_last_page_no_next_link(): void
    {
        $response = ApiResponse::paginated(
            data: [],
            total: 30,
            page: 3,
            perPage: 10,
            baseUrl: '/api/items',
        );

        $body = json_decode((string) $response->getBody(), true);
        $links = $body['links'];

        self::assertArrayNotHasKey('next', $links, 'Last page should not have a "next" link');
        self::assertArrayNotHasKey('last', $links, 'Last page should not have a "last" link');
        self::assertArrayHasKey('first', $links);
        self::assertArrayHasKey('prev', $links);
    }

    #[Test]
    public function created_response(): void
    {
        $response = ApiResponse::created(['id' => 42], '/api/items/42');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('/api/items/42', $response->getHeaderLine('Location'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['id' => 42], $body['data']);
    }

    #[Test]
    public function no_content_response(): void
    {
        $response = ApiResponse::noContent();

        self::assertSame(204, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertEmpty($body);
    }
}
