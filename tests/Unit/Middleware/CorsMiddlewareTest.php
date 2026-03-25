<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Middleware;

use Grav\Plugin\Api\Middleware\CorsMiddleware;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(CorsMiddleware::class)]
class CorsMiddlewareTest extends TestCase
{
    private function buildMiddleware(array $corsConfig): CorsMiddleware
    {
        $config = TestHelper::createMockConfig([
            'plugins' => ['api' => ['cors' => $corsConfig]],
        ]);

        return new CorsMiddleware($config);
    }

    #[Test]
    public function adds_cors_headers_to_response(): void
    {
        $middleware = $this->buildMiddleware([
            'enabled' => true,
            'origins' => ['*'],
            'credentials' => false,
            'expose_headers' => [],
        ]);

        $request = TestHelper::createMockRequest(headers: ['Origin' => 'http://example.com']);
        $response = $this->createStubResponse();

        $result = $middleware->addHeaders($request, $response);

        self::assertSame('*', $result->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function wildcard_origin_allows_all(): void
    {
        $middleware = $this->buildMiddleware([
            'enabled' => true,
            'origins' => ['*'],
        ]);

        $request = TestHelper::createMockRequest(headers: ['Origin' => 'http://any-domain.test']);
        $response = $this->createStubResponse();

        $result = $middleware->addHeaders($request, $response);

        self::assertSame('*', $result->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function specific_origin_matching(): void
    {
        $middleware = $this->buildMiddleware([
            'enabled' => true,
            'origins' => ['http://allowed.test', 'http://also-allowed.test'],
        ]);

        $request = TestHelper::createMockRequest(headers: ['Origin' => 'http://allowed.test']);
        $response = $this->createStubResponse();

        $result = $middleware->addHeaders($request, $response);

        self::assertSame('http://allowed.test', $result->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $result->getHeaderLine('Vary'));
    }

    #[Test]
    public function non_matching_origin_no_cors_headers(): void
    {
        $middleware = $this->buildMiddleware([
            'enabled' => true,
            'origins' => ['http://allowed.test'],
        ]);

        $request = TestHelper::createMockRequest(headers: ['Origin' => 'http://evil.test']);
        $response = $this->createStubResponse();

        $result = $middleware->addHeaders($request, $response);

        self::assertSame('', $result->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function credentials_header_when_enabled(): void
    {
        $middleware = $this->buildMiddleware([
            'enabled' => true,
            'origins' => ['*'],
            'credentials' => true,
        ]);

        $request = TestHelper::createMockRequest(headers: ['Origin' => 'http://example.com']);
        $response = $this->createStubResponse();

        $result = $middleware->addHeaders($request, $response);

        self::assertSame('true', $result->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    #[Test]
    public function cors_disabled_no_headers(): void
    {
        $middleware = $this->buildMiddleware([
            'enabled' => false,
        ]);

        $request = TestHelper::createMockRequest(headers: ['Origin' => 'http://example.com']);
        $response = $this->createStubResponse();

        $result = $middleware->addHeaders($request, $response);

        self::assertSame('', $result->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function no_origin_header_no_cors_headers(): void
    {
        $middleware = $this->buildMiddleware([
            'enabled' => true,
            'origins' => ['*'],
        ]);

        $request = TestHelper::createMockRequest();
        $response = $this->createStubResponse();

        $result = $middleware->addHeaders($request, $response);

        self::assertSame('', $result->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function expose_headers_are_set(): void
    {
        $middleware = $this->buildMiddleware([
            'enabled' => true,
            'origins' => ['*'],
            'expose_headers' => ['X-Request-Id', 'X-Rate-Limit-Remaining'],
        ]);

        $request = TestHelper::createMockRequest(headers: ['Origin' => 'http://example.com']);
        $response = $this->createStubResponse();

        $result = $middleware->addHeaders($request, $response);

        self::assertSame(
            'X-Request-Id, X-Rate-Limit-Remaining',
            $result->getHeaderLine('Access-Control-Expose-Headers'),
        );
    }

    #[Test]
    public function preflight_response_has_cors_headers(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'http://example.com';

        try {
            $middleware = $this->buildMiddleware([
                'enabled' => true,
                'origins' => ['*'],
                'methods' => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],
                'headers' => ['Authorization', 'Content-Type'],
                'max_age' => 86400,
                'credentials' => false,
            ]);

            $response = $middleware->createPreflightResponse();

            self::assertInstanceOf(ResponseInterface::class, $response);
            self::assertSame(204, $response->getStatusCode());
            self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
            self::assertStringContainsString('GET', $response->getHeaderLine('Access-Control-Allow-Methods'));
            self::assertStringContainsString('Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
            self::assertSame('86400', $response->getHeaderLine('Access-Control-Max-Age'));
            self::assertSame('0', $response->getHeaderLine('Content-Length'));
        } finally {
            unset($_SERVER['HTTP_ORIGIN']);
        }
    }

    /**
     * Lightweight PSR-7 ResponseInterface stub with withHeader() support.
     */
    private function createStubResponse(): ResponseInterface
    {
        return new \Grav\Framework\Psr7\Response();
    }
}
