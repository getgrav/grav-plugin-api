<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Middleware;

use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Middleware\JsonBodyParserMiddleware;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(JsonBodyParserMiddleware::class)]
class JsonBodyParserMiddlewareTest extends TestCase
{
    private JsonBodyParserMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new JsonBodyParserMiddleware();
    }

    #[Test]
    public function parses_json_body(): void
    {
        $payload = ['title' => 'Hello', 'published' => true];
        $request = TestHelper::createMockRequest(
            method: 'POST',
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($payload),
        );

        $result = $this->middleware->processRequest($request);

        self::assertInstanceOf(ServerRequestInterface::class, $result);
        self::assertSame($payload, $result->getAttribute('json_body'));
    }

    #[Test]
    public function ignores_non_json_content_type(): void
    {
        $request = TestHelper::createMockRequest(
            method: 'POST',
            headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
            body: 'foo=bar',
        );

        $result = $this->middleware->processRequest($request);

        // The request should be returned as-is (no json_body attribute set)
        self::assertNull($result->getAttribute('json_body'));
    }

    #[Test]
    public function empty_body_returns_empty_array(): void
    {
        $request = TestHelper::createMockRequest(
            method: 'POST',
            headers: ['Content-Type' => 'application/json'],
            body: '',
        );

        $result = $this->middleware->processRequest($request);

        self::assertSame([], $result->getAttribute('json_body'));
    }

    #[Test]
    public function invalid_json_throws_validation_exception(): void
    {
        $request = TestHelper::createMockRequest(
            method: 'POST',
            headers: ['Content-Type' => 'application/json'],
            body: '{invalid json!!!',
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/Invalid JSON/');

        $this->middleware->processRequest($request);
    }

    #[Test]
    public function parses_json_with_charset_in_content_type(): void
    {
        $payload = ['key' => 'value'];
        $request = TestHelper::createMockRequest(
            method: 'PUT',
            headers: ['Content-Type' => 'application/json; charset=utf-8'],
            body: json_encode($payload),
        );

        $result = $this->middleware->processRequest($request);

        self::assertSame($payload, $result->getAttribute('json_body'));
    }
}
