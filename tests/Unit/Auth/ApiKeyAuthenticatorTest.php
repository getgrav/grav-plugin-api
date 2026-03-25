<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Auth;

use Grav\Plugin\Api\Auth\ApiKeyAuthenticator;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiKeyAuthenticator::class)]
class ApiKeyAuthenticatorTest extends TestCase
{
    private const RAW_KEY = 'grav_test_api_key_raw_value_1234';

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
        $hash = hash('sha256', self::RAW_KEY);
        $user = TestHelper::createMockUser('alice', [
            'api_keys' => [
                'key1' => [
                    'hash' => $hash,
                    'active' => true,
                    'expires' => null,
                ],
            ],
        ]);

        $authenticator = $this->buildAuthenticator(['alice' => $user]);

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
        $hash = hash('sha256', self::RAW_KEY);
        $user = TestHelper::createMockUser('bob', [
            'api_keys' => [
                'key1' => [
                    'hash' => $hash,
                    'active' => true,
                    'expires' => null,
                ],
            ],
        ]);

        $authenticator = $this->buildAuthenticator(['bob' => $user]);

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
        $user = TestHelper::createMockUser('carol', [
            'api_keys' => [
                'key1' => [
                    'hash' => hash('sha256', 'some_other_key'),
                    'active' => true,
                ],
            ],
        ]);

        $authenticator = $this->buildAuthenticator(['carol' => $user]);

        $request = TestHelper::createMockRequest(
            headers: ['X-API-Key' => 'wrong_key_value'],
        );

        self::assertNull($authenticator->authenticate($request));
    }

    #[Test]
    public function returns_null_for_inactive_key(): void
    {
        $hash = hash('sha256', self::RAW_KEY);
        $user = TestHelper::createMockUser('dave', [
            'api_keys' => [
                'key1' => [
                    'hash' => $hash,
                    'active' => false,
                ],
            ],
        ]);

        $authenticator = $this->buildAuthenticator(['dave' => $user]);

        $request = TestHelper::createMockRequest(
            headers: ['X-API-Key' => self::RAW_KEY],
        );

        self::assertNull($authenticator->authenticate($request));
    }

    #[Test]
    public function returns_null_for_expired_key(): void
    {
        $hash = hash('sha256', self::RAW_KEY);
        $user = TestHelper::createMockUser('eve', [
            'api_keys' => [
                'key1' => [
                    'hash' => $hash,
                    'active' => true,
                    'expires' => time() - 3600, // expired an hour ago
                ],
            ],
        ]);

        $authenticator = $this->buildAuthenticator(['eve' => $user]);

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

        $headerHash = hash('sha256', $headerKey);

        $user = TestHelper::createMockUser('frank', [
            'api_keys' => [
                'key1' => [
                    'hash' => $headerHash,
                    'active' => true,
                ],
            ],
        ]);

        $authenticator = $this->buildAuthenticator(['frank' => $user]);

        $request = TestHelper::createMockRequest(
            headers: ['X-API-Key' => $headerKey],
            queryParams: ['api_key' => $queryKey],
        );

        $result = $authenticator->authenticate($request);

        self::assertNotNull($result);
        self::assertSame('frank', $result->username);
    }

    private function buildAuthenticator(array $users): ApiKeyAuthenticator
    {
        $accounts = TestHelper::createMockAccounts($users);
        $grav = TestHelper::createMockGrav(['accounts' => $accounts]);

        return new ApiKeyAuthenticator($grav);
    }
}
