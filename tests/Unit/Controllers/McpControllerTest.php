<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\McpController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Tests\Unit\Mcp\McpTestLocator;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Covers GET /mcp/tools: the api.access gate, the per-caller permission filter
 * over the collected tools, the plugin counts that follow from it, and the
 * fingerprint ETag with its conditional 304.
 *
 * Runs against tests/Fixtures/mcp/plugins, the same manifests the loader tests use.
 */
#[CoversClass(McpController::class)]
class McpControllerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/mcp/plugins';

    protected function setUp(): void
    {
        // PermissionResolver reads group access off the Grav singleton, so pin
        // it to a config with no groups: every permission below comes from the
        // account's own access map.
        TestHelper::createMockGrav(['config' => new Config([])]);
    }

    #[Test]
    public function a_caller_without_api_access_is_refused(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->controller()->tools($this->request(['api' => ['pages' => true]]));
    }

    #[Test]
    public function a_caller_only_sees_the_tools_whose_permission_they_hold(): void
    {
        $body = $this->body($this->controller()->tools($this->request([
            'api' => ['access' => true],
            'demoshop' => ['products' => ['read' => true]],
        ])));

        self::assertSame([
            'shop_list_products',
            'shop_get_product',
            'shop_storefront_status',
        ], array_column($body['data']['tools'], 'name'));
    }

    #[Test]
    public function a_tool_with_no_permission_is_always_included(): void
    {
        $body = $this->body($this->controller()->tools($this->request(['api' => ['access' => true]])));

        self::assertSame(['shop_storefront_status'], array_column($body['data']['tools'], 'name'));
        self::assertNull($body['data']['tools'][0]['permission']);
    }

    #[Test]
    public function a_super_admin_sees_every_tool(): void
    {
        $body = $this->body($this->controller()->tools($this->request(['api' => ['super' => true]])));

        self::assertCount(7, $body['data']['tools']);
    }

    #[Test]
    public function plugin_counts_are_recounted_after_the_filter(): void
    {
        $body = $this->body($this->controller()->tools($this->request([
            'api' => ['access' => true],
            'demoshop' => ['products' => ['read' => true]],
        ])));

        self::assertSame([
            ['slug' => 'demo-shop', 'name' => 'Demo Shop', 'version' => '2.3.0', 'tools' => 3],
            ['slug' => 'other-shop', 'name' => 'Other Shop', 'version' => '0.9.1', 'tools' => 0],
        ], $body['data']['plugins']);
    }

    #[Test]
    public function the_envelope_carries_the_warnings_and_the_fingerprint(): void
    {
        $response = $this->controller()->tools($this->request(['api' => ['super' => true]]));
        $body = $this->body($response);

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $body['data']['fingerprint']);
        self::assertSame('"' . $body['data']['fingerprint'] . '"', $response->getHeaderLine('ETag'));
        self::assertContains(
            "demo-shop: tool 'shop_upload_image' skipped: unsupported schema keyword 'oneOf' at properties.file",
            $body['data']['warnings'],
        );
    }

    #[Test]
    public function a_matching_if_none_match_gets_a_304_with_no_body(): void
    {
        $access = ['api' => ['super' => true]];
        $etag = $this->controller()->tools($this->request($access))->getHeaderLine('ETag');

        $response = $this->controller()->tools($this->request($access, ['If-None-Match' => $etag]));

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame($etag, $response->getHeaderLine('ETag'));
    }

    #[Test]
    public function a_stale_if_none_match_gets_the_full_body(): void
    {
        $response = $this->controller()->tools($this->request(
            ['api' => ['super' => true]],
            ['If-None-Match' => '"0000000000000000"'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame([], $this->body($response)['data']['tools']);
    }

    private function controller(): McpController
    {
        $locator = new McpTestLocator(self::FIXTURES);

        $grav = $this->createMock(Grav::class);
        $grav->method('offsetGet')->willReturnCallback(
            static fn($key) => $key === 'locator' ? $locator : null,
        );
        $grav->method('fireEvent')->willReturnCallback(static fn($name, $event) => $event);

        return new McpController($grav, new Config(['plugins' => [
            'demo-shop' => ['enabled' => true],
            'other-shop' => ['enabled' => true],
            'quiet' => ['enabled' => true],
        ]]));
    }

    /**
     * @param array<string, mixed> $access
     * @param array<string, string> $headers
     */
    private function request(array $access, array $headers = []): ServerRequestInterface
    {
        $user = TestHelper::createMockUser('tester', ['access' => $access]);

        return TestHelper::createMockRequest(
            'GET',
            '/mcp/tools',
            $headers,
            attributes: ['api_user' => $user],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }
}
