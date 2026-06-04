<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Auth;

use Grav\Common\Yaml;
use Grav\Plugin\Api\Auth\ApiKeyAuthenticator;
use Grav\Plugin\Api\Auth\ApiKeyManager;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ApiKeyAuthenticator.
 *
 * API keys live in the central user/data/api-keys.yaml store (keyed by id,
 * each entry carrying its owning `username`). The authenticator looks a raw
 * key up in that store, then loads the matching account. Tests seed the store
 * via a temp-dir `user://data` locator and provide a matching accounts mock.
 */
#[CoversClass(ApiKeyAuthenticator::class)]
class ApiKeyAuthenticatorTest extends TestCase
{
    private const RAW_KEY = 'grav_test_api_key_raw_value_1234';

    private string $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/grav_api_authn_test_' . uniqid();
        @mkdir($this->dataDir, 0775, true);
        $this->resetKeysCache();
    }

    protected function tearDown(): void
    {
        $this->resetKeysCache();
        $this->rmrf($this->dataDir);
    }

    private function resetKeysCache(): void
    {
        (new \ReflectionProperty(ApiKeyManager::class, 'keysCache'))->setValue(null, null);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmrf($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Build an authenticator with the central key store seeded and the given
     * accounts available.
     *
     * @param array<string, array> $keys  central store entries keyed by id
     * @param array<string, object> $users accounts keyed by username
     */
    private function buildAuthenticator(array $keys, array $users = []): ApiKeyAuthenticator
    {
        $dataDir = $this->dataDir;
        $locator = new class ($dataDir) {
            public function __construct(private string $dir) {}
            public function findResource(string $uri, bool $absolute = true, bool $first = false): string
            {
                return $this->dir;
            }
        };

        $accounts = TestHelper::createMockAccounts($users);
        $grav = TestHelper::createMockGrav(['accounts' => $accounts, 'locator' => $locator]);

        // Seed the central store after the Grav container exists.
        file_put_contents($this->dataDir . '/api-keys.yaml', Yaml::dump($keys));
        $this->resetKeysCache();

        return new ApiKeyAuthenticator($grav);
    }

    #[Test]
    public function returns_null_when_no_api_key_present(): void
    {
        $authenticator = $this->buildAuthenticator([]);

        $request = TestHelper::createMockRequest();

        self::assertNull($authenticator->authenticate($request));
    }

    #[Test]
    public function authenticates_via_header(): void
    {
        $user = TestHelper::createMockUser('alice');
        $authenticator = $this->buildAuthenticator([
            'key1' => [
                'id' => 'key1',
                'username' => 'alice',
                'hash' => hash('sha256', self::RAW_KEY),
                'active' => true,
                'expires' => null,
            ],
        ], ['alice' => $user]);

        $request = TestHelper::createMockRequest(
            headers: ['X-API-Key' => self::RAW_KEY],
        );

        $result = $authenticator->authenticate($request);

        self::assertNotNull($result);
        self::assertSame('alice', $result->username);
    }

    #[Test]
    public function authenticates_via_query_param(): void
    {
        $user = TestHelper::createMockUser('bob');
        $authenticator = $this->buildAuthenticator([
            'key1' => [
                'id' => 'key1',
                'username' => 'bob',
                'hash' => hash('sha256', self::RAW_KEY),
                'active' => true,
                'expires' => null,
            ],
        ], ['bob' => $user]);

        $request = TestHelper::createMockRequest(
            queryParams: ['api_key' => self::RAW_KEY],
        );

        $result = $authenticator->authenticate($request);

        self::assertNotNull($result);
        self::assertSame('bob', $result->username);
    }

    #[Test]
    public function returns_null_for_invalid_key(): void
    {
        $user = TestHelper::createMockUser('carol');
        $authenticator = $this->buildAuthenticator([
            'key1' => [
                'id' => 'key1',
                'username' => 'carol',
                'hash' => hash('sha256', 'some_other_key'),
                'active' => true,
            ],
        ], ['carol' => $user]);

        $request = TestHelper::createMockRequest(
            headers: ['X-API-Key' => 'grav_wrong_key_value'],
        );

        self::assertNull($authenticator->authenticate($request));
    }

    #[Test]
    public function returns_null_for_inactive_key(): void
    {
        $user = TestHelper::createMockUser('dave');
        $authenticator = $this->buildAuthenticator([
            'key1' => [
                'id' => 'key1',
                'username' => 'dave',
                'hash' => hash('sha256', self::RAW_KEY),
                'active' => false,
            ],
        ], ['dave' => $user]);

        $request = TestHelper::createMockRequest(
            headers: ['X-API-Key' => self::RAW_KEY],
        );

        self::assertNull($authenticator->authenticate($request));
    }

    #[Test]
    public function returns_null_for_expired_key(): void
    {
        $user = TestHelper::createMockUser('eve');
        $authenticator = $this->buildAuthenticator([
            'key1' => [
                'id' => 'key1',
                'username' => 'eve',
                'hash' => hash('sha256', self::RAW_KEY),
                'active' => true,
                'expires' => time() - 3600, // expired an hour ago
            ],
        ], ['eve' => $user]);

        $request = TestHelper::createMockRequest(
            headers: ['X-API-Key' => self::RAW_KEY],
        );

        self::assertNull($authenticator->authenticate($request));
    }

    #[Test]
    public function returns_null_when_account_does_not_exist(): void
    {
        // Key matches, but no account exists for its username.
        $authenticator = $this->buildAuthenticator([
            'key1' => [
                'id' => 'key1',
                'username' => 'ghost',
                'hash' => hash('sha256', self::RAW_KEY),
                'active' => true,
            ],
        ], []);

        $request = TestHelper::createMockRequest(
            headers: ['X-API-Key' => self::RAW_KEY],
        );

        self::assertNull($authenticator->authenticate($request));
    }

    #[Test]
    public function header_takes_precedence_over_query_param(): void
    {
        $headerKey = 'grav_header_key_value_123456789';
        $queryKey = 'grav_query_key_value_987654321';

        $user = TestHelper::createMockUser('frank');
        $authenticator = $this->buildAuthenticator([
            'key1' => [
                'id' => 'key1',
                'username' => 'frank',
                'hash' => hash('sha256', $headerKey),
                'active' => true,
            ],
        ], ['frank' => $user]);

        $request = TestHelper::createMockRequest(
            headers: ['X-API-Key' => $headerKey],
            queryParams: ['api_key' => $queryKey],
        );

        $result = $authenticator->authenticate($request);

        self::assertNotNull($result);
        self::assertSame('frank', $result->username);
    }
}
