<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Auth;

use Grav\Plugin\Api\Auth\ApiKeyManager;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiKeyManager::class)]
class ApiKeyManagerTest extends TestCase
{
    private ApiKeyManager $manager;

    protected function setUp(): void
    {
        $this->manager = new ApiKeyManager();
    }

    #[Test]
    public function generate_key_returns_key_and_id(): void
    {
        $user = TestHelper::createMockUser('alice');

        $result = $this->manager->generateKey($user);

        self::assertArrayHasKey('key', $result);
        self::assertArrayHasKey('id', $result);
        self::assertNotEmpty($result['key']);
        self::assertNotEmpty($result['id']);
    }

    #[Test]
    public function generated_key_starts_with_grav_prefix(): void
    {
        $user = TestHelper::createMockUser('bob');

        $result = $this->manager->generateKey($user);

        self::assertStringStartsWith('grav_', $result['key']);
    }

    #[Test]
    public function generated_key_is_stored_on_user(): void
    {
        $user = TestHelper::createMockUser('carol');

        $result = $this->manager->generateKey($user, 'My Key', ['read', 'write']);

        $keys = $user->get('api_keys', []);
        self::assertArrayHasKey($result['id'], $keys);

        $stored = $keys[$result['id']];
        self::assertSame($result['id'], $stored['id']);
        self::assertSame('My Key', $stored['name']);
        self::assertSame(hash('sha256', $result['key']), $stored['hash']);
        self::assertSame(['read', 'write'], $stored['scopes']);
        self::assertTrue($stored['active']);
        self::assertNotNull($stored['created']);
        self::assertNull($stored['last_used']);
        self::assertNull($stored['expires']);
    }

    #[Test]
    public function generated_key_stores_prefix(): void
    {
        $user = TestHelper::createMockUser('dave');

        $result = $this->manager->generateKey($user);

        $keys = $user->get('api_keys', []);
        $stored = $keys[$result['id']];

        // Prefix should be first 12 chars of the raw key followed by '...'
        $expectedPrefix = substr($result['key'], 0, 12) . '...';
        self::assertSame($expectedPrefix, $stored['prefix']);
    }

    #[Test]
    public function default_key_name_is_api_key(): void
    {
        $user = TestHelper::createMockUser('eve');

        $result = $this->manager->generateKey($user);

        $keys = $user->get('api_keys', []);
        self::assertSame('API Key', $keys[$result['id']]['name']);
    }

    #[Test]
    public function list_keys_excludes_hashes(): void
    {
        $user = TestHelper::createMockUser('frank', [
            'api_keys' => [
                'k1' => [
                    'id' => 'k1',
                    'name' => 'Production',
                    'hash' => 'abc123secrethash',
                    'prefix' => 'grav_abc123...',
                    'scopes' => ['read'],
                    'active' => true,
                    'created' => 1700000000,
                    'last_used' => null,
                    'expires' => null,
                ],
                'k2' => [
                    'id' => 'k2',
                    'name' => 'Staging',
                    'hash' => 'def456secrethash',
                    'prefix' => 'grav_def456...',
                    'scopes' => [],
                    'active' => false,
                    'created' => 1700001000,
                    'last_used' => 1700002000,
                    'expires' => 1700100000,
                ],
            ],
        ]);

        $list = $this->manager->listKeys($user);

        self::assertCount(2, $list);

        // Verify no hash field is present in the output
        foreach ($list as $item) {
            self::assertArrayNotHasKey('hash', $item);
            self::assertArrayHasKey('id', $item);
            self::assertArrayHasKey('name', $item);
            self::assertArrayHasKey('prefix', $item);
            self::assertArrayHasKey('scopes', $item);
            self::assertArrayHasKey('active', $item);
            self::assertArrayHasKey('created', $item);
            self::assertArrayHasKey('last_used', $item);
            self::assertArrayHasKey('expires', $item);
        }

        self::assertSame('Production', $list[0]['name']);
        self::assertSame('Staging', $list[1]['name']);
    }

    #[Test]
    public function list_keys_skips_non_array_entries(): void
    {
        $user = TestHelper::createMockUser('grace', [
            'api_keys' => [
                'k1' => [
                    'id' => 'k1',
                    'name' => 'Valid Key',
                    'hash' => 'somehash',
                    'prefix' => 'grav_aaa...',
                    'scopes' => [],
                    'active' => true,
                    'created' => 1700000000,
                    'last_used' => null,
                    'expires' => null,
                ],
                'corrupted' => 'not_an_array',
            ],
        ]);

        $list = $this->manager->listKeys($user);

        self::assertCount(1, $list);
        self::assertSame('Valid Key', $list[0]['name']);
    }

    #[Test]
    public function revoke_key_removes_it(): void
    {
        $user = TestHelper::createMockUser('heidi', [
            'api_keys' => [
                'k1' => [
                    'id' => 'k1',
                    'name' => 'To be revoked',
                    'hash' => 'somehash',
                ],
                'k2' => [
                    'id' => 'k2',
                    'name' => 'Keeper',
                    'hash' => 'otherhash',
                ],
            ],
        ]);

        $result = $this->manager->revokeKey($user, 'k1');

        self::assertTrue($result);

        $keys = $user->get('api_keys', []);
        self::assertArrayNotHasKey('k1', $keys);
        self::assertArrayHasKey('k2', $keys);
    }

    #[Test]
    public function revoke_nonexistent_key_returns_false(): void
    {
        $user = TestHelper::createMockUser('ivan', [
            'api_keys' => [
                'k1' => ['id' => 'k1', 'name' => 'Existing', 'hash' => 'h'],
            ],
        ]);

        $result = $this->manager->revokeKey($user, 'nonexistent');

        self::assertFalse($result);

        // The existing key should remain untouched
        $keys = $user->get('api_keys', []);
        self::assertArrayHasKey('k1', $keys);
    }

    #[Test]
    public function multiple_keys_can_be_generated_for_same_user(): void
    {
        $user = TestHelper::createMockUser('judy');

        $first = $this->manager->generateKey($user, 'First Key');
        $second = $this->manager->generateKey($user, 'Second Key');

        self::assertNotSame($first['key'], $second['key']);
        self::assertNotSame($first['id'], $second['id']);

        $keys = $user->get('api_keys', []);
        self::assertCount(2, $keys);
    }

    #[Test]
    public function touch_key_updates_last_used(): void
    {
        $hash = hash('sha256', 'grav_some_raw_key_value');
        $user = TestHelper::createMockUser('kate', [
            'api_keys' => [
                'k1' => [
                    'id' => 'k1',
                    'name' => 'Touch Test',
                    'hash' => $hash,
                    'last_used' => null,
                ],
            ],
        ]);

        $this->manager->touchKey($user, $hash);

        $keys = $user->get('api_keys', []);
        self::assertNotNull($keys['k1']['last_used']);
        self::assertEqualsWithDelta(time(), $keys['k1']['last_used'], 2);
    }
}
