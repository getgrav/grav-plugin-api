<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Middleware;

use Grav\Common\Helpers\JsonBody;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Middleware\JsonBodyParserMiddleware;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
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

    /** One object whose integer keys all land in one PHP hash bucket. */
    private static function colliding(int $members): string
    {
        return '{' . implode(',', array_map(static fn($i) => '"' . ($i << 20) . '":0', range(0, $members - 1))) . '}';
    }

    private function requireCoreJsonBody(): void
    {
        if (!method_exists(JsonBody::class, 'decode')) {
            self::markTestSkipped('Needs Grav core 2.2.20+ (Grav\\Common\\Helpers\\JsonBody).');
        }
    }

    #[Test]
    public function colliding_body_is_refused_with_413(): void
    {
        $this->requireCoreJsonBody();

        $request = TestHelper::createMockRequest(
            method: 'POST',
            headers: ['Content-Type' => 'application/json'],
            body: self::colliding(65536),
        );

        $started = microtime(true);
        try {
            $this->middleware->processRequest($request);
            self::fail('A body with a 65,536-member object was decoded');
        } catch (ApiException $e) {
            self::assertSame(413, $e->getStatusCode());
            self::assertSame('Payload Too Large', $e->getErrorTitle());
        }
        self::assertLessThan(0.5, microtime(true) - $started);
    }

    #[Test]
    public function largest_real_payloads_still_pass(): void
    {
        $this->requireCoreJsonBody();

        $payloads = [
            // A page save with a 2 MB body full of colons and quotes.
            'page' => [
                'title' => 'Big page',
                'header' => ['taxonomy' => ['tag' => ['a', 'b']], 'routes' => ['aliases' => ['/x']]],
                'content' => str_repeat("Time: 10:30, \"quoted\" {\"k\": 1}\n", 60000),
            ],
            // Accept-all in the translation editor for every key of a busy site.
            'translations' => ['set' => array_combine(
                array_map(static fn($i) => "PLUGIN_X.KEY_$i", range(1, 9500)),
                array_fill(0, 9500, 'Value: text'),
            ), 'unset' => []],
            // A config save.
            'config' => ['data' => ['pages' => ['theme' => 'quark2', 'markdown' => ['extra' => false]], 'cache' => ['enabled' => true]]],
            // A long batch of small objects.
            'batch' => ['operations' => array_map(static fn($i) => ['op' => 'move', 'route' => "/p$i", 'parent' => '/', 'order' => $i], range(1, 20000))],
        ];

        foreach ($payloads as $name => $payload) {
            $request = TestHelper::createMockRequest(
                method: 'PATCH',
                headers: ['Content-Type' => 'application/json'],
                body: json_encode($payload),
            );
            self::assertSame($payload, $this->middleware->processRequest($request)->getAttribute('json_body'), $name);
        }
    }

    #[Test]
    public function older_core_reuses_its_parsed_body(): void
    {
        // Core before 2.2.20 has already decoded an application/json body into the
        // parsed body. It is reused, not parsed a second time.
        $middleware = new class extends JsonBodyParserMiddleware {
            protected function coreDecodesWithinLimits(): bool
            {
                return false;
            }
        };
        $request = (new ServerRequest('POST', '/api/v1/pages', ['Content-Type' => 'application/json'], '{"a":2}'))
            ->withParsedBody(['a' => 1]);

        self::assertSame(['a' => 1], $middleware->processRequest($request)->getAttribute('json_body'));

        $unparsed = TestHelper::createMockRequest(
            method: 'POST',
            headers: ['Content-Type' => 'application/json'],
            body: '{"a":2}',
        );
        self::assertSame(['a' => 2], $middleware->processRequest($unparsed)->getAttribute('json_body'));

        $this->expectException(ValidationException::class);
        $middleware->processRequest(TestHelper::createMockRequest(
            method: 'POST',
            headers: ['Content-Type' => 'application/json'],
            body: '{bad',
        ));
    }
}
