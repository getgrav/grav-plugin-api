<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit;

use Grav\Common\Config\Config;
use Grav\Plugin\Api\ApiRouter;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ApiRouterCoreErrorsTest extends TestCase
{
    public function testYamlMergeKeyErrorIsAValidationResponse(): void
    {
        $response = $this->responseFor(new \RuntimeException("Encoding YAML failed: '<<' cannot be used as a key"));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Unprocessable Entity', json_decode((string) $response->getBody(), true)['title']);
        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testStaleAccountSaveIsAConflictResponse(): void
    {
        $response = $this->responseFor(new \RuntimeException('User account has been changed since it was loaded', 409));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('Conflict', json_decode((string) $response->getBody(), true)['title']);
        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testOtherRuntimeErrorsRemainServerErrors(): void
    {
        self::assertSame(500, $this->responseFor(new \RuntimeException('Storage unavailable'))->getStatusCode());
    }

    private function responseFor(\Throwable $error): ResponseInterface
    {
        $config = new Config(['plugins' => ['api' => ['cors' => ['origins' => ['https://example.com']]]]]);
        $log = new class {
            public function error(...$args): void {}
            public function debug(...$args): void {}
        };
        $grav = TestHelper::createMockGrav(['config' => $config, 'log' => $log]);
        $router = new class ($grav, $config) extends ApiRouter {
            public \Throwable $error;
            protected function startTimer($id = null, $title = null): void {}
            protected function stopTimer($id = null): void {}
            protected function applyEnvironment(ServerRequestInterface $request): void { throw $this->error; }
        };
        $router->error = $error;
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface { throw new \LogicException('Unexpected fallback.'); }
        };

        return $router->process(TestHelper::createMockRequest(headers: ['Origin' => 'https://example.com']), $handler);
    }
}
