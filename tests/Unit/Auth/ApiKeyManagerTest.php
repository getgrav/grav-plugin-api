<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Auth;

use Grav\Common\Yaml;
use Grav\Plugin\Api\Auth\ApiKeyManager;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ApiKeyManager.
 *
 * Keys are stored centrally in user/data/api-keys.yaml (keyed by id, each
 * entry carrying its owning `username`), so the tests seed and inspect that
 * central store via a temp-dir `user://data` locator rather than the user
 * object itself.
 */
#[CoversClass(ApiKeyManager::class)]
class ApiKeyManagerTest extends TestCase
{
    private ApiKeyManager $manager;
    private string $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/grav_api_keys_test_' . uniqid();
        @mkdir($this->dataDir, 0775, true);

        $dataDir = $this->dataDir;
        $locator = new class ($dataDir) {
            public function __construct(private string $dir) {}
            public function findResource(string $uri, bool $absolute = true, bool $first = false): string
            {
                return $this->dir;
            }
        };

        TestHelper::createMockGrav(['locator' => $locator]);
        $this->resetKeysCache();

        $this->manager = new ApiKeyManager();
    }

    protected function tearDown(): void
    {
        $this->resetKeysCache();
        $this->rmrf($this->dataDir);
    }

    /** Clear the ApiKeyManager static cache so each test starts clean. */
    private function resetKeysCache(): void
    {
        (new \ReflectionProperty(ApiKeyManager::class, 'keysCache'))->setValue(null, null);
    }

    /** Seed the central api-keys.yaml store with the given entries. */
    private function seedKeys(array $keys): void
    {
        file_put_contents($this->dataDir . '/api-keys.yaml', Yaml::dump($keys));
        $this->resetKeysCache();
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
    public function generated_key_is_stored_centrally(): void
    {
        $user = TestHelper::createMockUser('carol');

        $result = $this->manager->generateKey($user, 'My Key', ['read', 'write']);

        $keys = $this->manager->loadKeys();
        self::assertArrayHasKey($result['id'], $keys);

        $stored = $keys[$result['id']];
        self::assertSame($result['id'], $stored['id']);
        self::assertSame('carol', $stored['username']);
        self::assertSame('My Key', $stored['name']);
        // The raw key verifies against the stored hash (bcrypt, not reversible).
        self::assertTrue(ApiKeyManager::verifyKey($result['key'], $stored['hash']));
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

        $stored = $this->manager->loadKeys()[$result['id']];

        // Prefix should be first 12 chars of the raw key followed by '...'
        $expectedPrefix = substr($result['key'], 0, 12) . '...';
        self::assertSame($expectedPrefix, $stored['prefix']);
    }

    #[Test]
    public function default_key_name_is_api_key(): void
    {
        $user = TestHelper::createMockUser('eve');

        $result = $this->manager->generateKey($user);

        self::assertSame('API Key', $this->manager->loadKeys()[$result['id']]['name']);
    }

    #[Test]
    public function list_keys_excludes_hashes(): void
    {
        $this->seedKeys([
            'k1' => [
                'id' => 'k1',
                'username' => 'frank',
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
                'username' => 'frank',
                'name' => 'Staging',
                'hash' => 'def456secrethash',
                'prefix' => 'grav_def456...',
                'scopes' => [],
                'active' => false,
                'created' => 1700001000,
                'last_used' => 1700002000,
                'expires' => 1700100000,
            ],
        ]);

        $user = TestHelper::createMockUser('frank');
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
    public function list_keys_only_returns_keys_for_the_given_user(): void
    {
        $this->seedKeys([
            'k1' => ['id' => 'k1', 'username' => 'frank', 'name' => 'Mine', 'hash' => 'h'],
            'k2' => ['id' => 'k2', 'username' => 'someone_else', 'name' => 'Theirs', 'hash' => 'h'],
        ]);

        $list = $this->manager->listKeys(TestHelper::createMockUser('frank'));

        self::assertCount(1, $list);
        self::assertSame('Mine', $list[0]['name']);
    }

    #[Test]
    public function list_keys_skips_non_array_entries(): void
    {
        $this->seedKeys([
            'k1' => [
                'id' => 'k1',
                'username' => 'grace',
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
        ]);

        $list = $this->manager->listKeys(TestHelper::createMockUser('grace'));

        self::assertCount(1, $list);
        self::assertSame('Valid Key', $list[0]['name']);
    }

    #[Test]
    public function revoke_key_removes_it(): void
    {
        $this->seedKeys([
            'k1' => ['id' => 'k1', 'username' => 'heidi', 'name' => 'To be revoked', 'hash' => 'somehash'],
            'k2' => ['id' => 'k2', 'username' => 'heidi', 'name' => 'Keeper', 'hash' => 'otherhash'],
        ]);

        $result = $this->manager->revokeKey(TestHelper::createMockUser('heidi'), 'k1');

        self::assertTrue($result);

        $keys = $this->manager->loadKeys();
        self::assertArrayNotHasKey('k1', $keys);
        self::assertArrayHasKey('k2', $keys);
    }

    #[Test]
    public function revoke_nonexistent_key_returns_false(): void
    {
        $this->seedKeys([
            'k1' => ['id' => 'k1', 'username' => 'ivan', 'name' => 'Existing', 'hash' => 'h'],
        ]);

        $result = $this->manager->revokeKey(TestHelper::createMockUser('ivan'), 'nonexistent');

        self::assertFalse($result);

        // The existing key should remain untouched
        self::assertArrayHasKey('k1', $this->manager->loadKeys());
    }

    #[Test]
    public function revoke_does_not_remove_another_users_key(): void
    {
        $this->seedKeys([
            'k1' => ['id' => 'k1', 'username' => 'owner', 'name' => 'Owned', 'hash' => 'h'],
        ]);

        // A different user attempting to revoke a key they don't own fails.
        $result = $this->manager->revokeKey(TestHelper::createMockUser('attacker'), 'k1');

        self::assertFalse($result);
        self::assertArrayHasKey('k1', $this->manager->loadKeys());
    }

    #[Test]
    public function multiple_keys_can_be_generated_for_same_user(): void
    {
        $user = TestHelper::createMockUser('judy');

        $first = $this->manager->generateKey($user, 'First Key');
        $second = $this->manager->generateKey($user, 'Second Key');

        self::assertNotSame($first['key'], $second['key']);
        self::assertNotSame($first['id'], $second['id']);

        $keys = $this->manager->loadKeys();
        self::assertCount(2, $keys);
        self::assertSame('judy', $keys[$first['id']]['username']);
        self::assertSame('judy', $keys[$second['id']]['username']);
    }

    #[Test]
    public function touch_key_updates_last_used(): void
    {
        $this->seedKeys([
            'k1' => [
                'id' => 'k1',
                'username' => 'kate',
                'name' => 'Touch Test',
                'hash' => 'somehash',
                'last_used' => null,
            ],
        ]);

        $this->manager->touchKey('k1');

        $keys = $this->manager->loadKeys();
        self::assertNotNull($keys['k1']['last_used']);
        self::assertEqualsWithDelta(time(), $keys['k1']['last_used'], 2);
    }

    #[Test]
    public function find_key_matches_raw_key_against_central_store(): void
    {
        $rawKey = 'grav_find_me_raw_value_0123456789';
        $this->seedKeys([
            'k1' => [
                'id' => 'k1',
                'username' => 'leo',
                'name' => 'Findable',
                'hash' => hash('sha256', $rawKey),
            ],
        ]);

        $match = $this->manager->findKey($rawKey);

        self::assertNotNull($match);
        self::assertSame('k1', $match['key_id']);
        self::assertSame('leo', $match['username']);

        self::assertNull($this->manager->findKey('grav_not_a_real_key'));
    }
}
