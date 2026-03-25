<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Middleware;

use Grav\Plugin\Api\Middleware\RateLimitMiddleware;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the token-bucket RateLimitMiddleware.
 *
 * The real class calls Grav::instance()['locator'] in getStorageDir().
 * We extend the class and override getStorageDir() to use a temp directory,
 * making the core checkLimit() logic testable in isolation.
 */
#[CoversClass(RateLimitMiddleware::class)]
class RateLimitMiddlewareTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_ratelimit_test_' . uniqid();
        @mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if ($files) {
            array_map('unlink', $files);
        }
        @rmdir($this->tempDir);
    }

    #[Test]
    public function check_returns_not_limited_when_under_limit(): void
    {
        $middleware = $this->createTestableMiddleware(limit: 10, window: 60);
        $request = TestHelper::createMockRequest(
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $result = $middleware->check($request);

        self::assertFalse($result['limited']);
        self::assertSame(10, $result['limit']);
        self::assertGreaterThanOrEqual(0, $result['remaining']);
    }

    #[Test]
    public function check_returns_limited_when_over_limit(): void
    {
        $middleware = $this->createTestableMiddleware(limit: 3, window: 60);
        $request = TestHelper::createMockRequest(
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        // Exhaust the 3 allowed tokens
        for ($i = 0; $i < 3; $i++) {
            $result = $middleware->check($request);
            self::assertFalse($result['limited'], "Request $i should not be limited");
        }

        // The 4th request should be rate-limited
        $result = $middleware->check($request);
        self::assertTrue($result['limited']);
        self::assertSame(0, $result['remaining']);
    }

    #[Test]
    public function rate_limit_disabled_always_allows(): void
    {
        $config = TestHelper::createMockConfig([
            'plugins' => ['api' => ['rate_limit' => [
                'enabled' => false,
                'requests' => 5,
                'window' => 60,
            ]]],
        ]);

        $tempDir = $this->tempDir;
        $middleware = new class ($config, $tempDir) extends RateLimitMiddleware {
            public function __construct(
                \Grav\Common\Config\Config $config,
                private readonly string $dir,
            ) {
                parent::__construct($config);
            }

            protected function getStorageDir(): string
            {
                return $this->dir;
            }
        };

        $request = TestHelper::createMockRequest(
            serverParams: ['REMOTE_ADDR' => '192.168.1.1'],
        );

        // Even after many requests it should never be limited
        for ($i = 0; $i < 20; $i++) {
            $result = $middleware->check($request);
            self::assertFalse($result['limited']);
            self::assertSame(5, $result['remaining']);
        }
    }

    #[Test]
    public function tokens_refill_over_time(): void
    {
        $limit = 2;
        $window = 60;
        $middleware = $this->createTestableMiddleware(limit: $limit, window: $window);

        $request = TestHelper::createMockRequest(
            serverParams: ['REMOTE_ADDR' => '172.16.0.1'],
        );

        // Exhaust all tokens
        for ($i = 0; $i < $limit; $i++) {
            $middleware->check($request);
        }

        // Manipulate the stored file to simulate time passing.
        $identifier = 'ip:172.16.0.1';
        $file = $this->tempDir . '/' . md5($identifier) . '.json';
        self::assertFileExists($file);

        $data = json_decode(file_get_contents($file), true);
        // Pretend last_refill was 30 seconds ago.
        // With limit=2, window=60: refill rate = 2/60 per second.
        // After 30s: refill = 30 * (2/60) = 1 token.
        $data['last_refill'] -= 30;
        file_put_contents($file, json_encode($data));

        $result = $middleware->check($request);
        self::assertFalse($result['limited'], 'Token bucket should have refilled after elapsed time');
    }

    #[Test]
    public function different_users_have_separate_limits(): void
    {
        $middleware = $this->createTestableMiddleware(limit: 2, window: 60);

        $requestA = TestHelper::createMockRequest(
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );
        $requestB = TestHelper::createMockRequest(
            serverParams: ['REMOTE_ADDR' => '10.0.0.2'],
        );

        // Exhaust limit for user A
        for ($i = 0; $i < 2; $i++) {
            $middleware->check($requestA);
        }
        $resultA = $middleware->check($requestA);
        self::assertTrue($resultA['limited'], 'User A should be rate-limited');

        // User B should still have full budget
        $resultB = $middleware->check($requestB);
        self::assertFalse($resultB['limited'], 'User B should NOT be rate-limited');
    }

    #[Test]
    public function authenticated_user_identified_by_username(): void
    {
        $middleware = $this->createTestableMiddleware(limit: 1, window: 60);

        $user = TestHelper::createMockUser('alice');

        // Two different IPs but same authenticated user
        $requestFromOffice = TestHelper::createMockRequest(
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
            attributes: ['api_user' => $user],
        );
        $requestFromHome = TestHelper::createMockRequest(
            serverParams: ['REMOTE_ADDR' => '192.168.1.1'],
            attributes: ['api_user' => $user],
        );

        // First request uses the single token
        $result = $middleware->check($requestFromOffice);
        self::assertFalse($result['limited']);

        // Second request should also be limited (same user bucket)
        $result = $middleware->check($requestFromHome);
        self::assertTrue($result['limited']);
    }

    /**
     * Build a testable RateLimitMiddleware subclass that uses a temp storage directory.
     */
    private function createTestableMiddleware(int $limit = 120, int $window = 60): RateLimitMiddleware
    {
        $config = TestHelper::createMockConfig([
            'plugins' => ['api' => ['rate_limit' => [
                'enabled' => true,
                'requests' => $limit,
                'window' => $window,
            ]]],
        ]);

        $tempDir = $this->tempDir;

        return new class ($config, $tempDir) extends RateLimitMiddleware {
            public function __construct(
                \Grav\Common\Config\Config $config,
                private readonly string $dir,
            ) {
                parent::__construct($config);
            }

            protected function getStorageDir(): string
            {
                return $this->dir;
            }
        };
    }
}
