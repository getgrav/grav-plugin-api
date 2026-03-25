<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Auth;

use Grav\Common\Grav;
use Grav\Common\User\Authentication;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Common\Yaml;

/**
 * Manages API keys stored centrally in user/data/api-keys.yaml
 */
class ApiKeyManager
{
    protected static ?array $keysCache = null;

    /**
     * Generate a new API key for a user.
     *
     * @param int|null $expiryDays Number of days until the key expires, or null for no expiry
     * @return array{key: string, id: string} The raw key (shown once) and the key ID
     */
    public function generateKey(UserInterface $user, string $name = '', array $scopes = [], ?int $expiryDays = null): array
    {
        $rawKey = 'grav_' . bin2hex(random_bytes(24));
        $keyId = bin2hex(random_bytes(8));
        $hash = Authentication::create($rawKey);
        $expires = $expiryDays !== null ? time() + ($expiryDays * 86400) : null;

        $keys = $this->loadKeys();
        $keys[$keyId] = [
            'id' => $keyId,
            'username' => $user->username,
            'name' => $name ?: 'API Key',
            'hash' => $hash,
            'prefix' => substr($rawKey, 0, 12) . '...',
            'scopes' => $scopes,
            'active' => true,
            'created' => time(),
            'last_used' => null,
            'expires' => $expires,
        ];

        $this->saveKeys($keys);

        return [
            'key' => $rawKey,
            'id' => $keyId,
        ];
    }

    /**
     * List all API keys for a user (without hashes).
     */
    public function listKeys(UserInterface $user): array
    {
        $keys = $this->loadKeys();
        $result = [];

        foreach ($keys as $keyData) {
            if (!is_array($keyData) || ($keyData['username'] ?? '') !== $user->username) {
                continue;
            }

            $result[] = [
                'id' => $keyData['id'] ?? '',
                'name' => $keyData['name'] ?? 'API Key',
                'prefix' => $keyData['prefix'] ?? '',
                'scopes' => $keyData['scopes'] ?? [],
                'active' => $keyData['active'] ?? true,
                'created' => $keyData['created'] ?? null,
                'last_used' => $keyData['last_used'] ?? null,
                'expires' => $keyData['expires'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Revoke (delete) an API key.
     */
    public function revokeKey(UserInterface $user, string $keyId): bool
    {
        $keys = $this->loadKeys();

        if (!isset($keys[$keyId]) || ($keys[$keyId]['username'] ?? '') !== $user->username) {
            return false;
        }

        unset($keys[$keyId]);
        $this->saveKeys($keys);

        return true;
    }

    /**
     * Verify a raw API key against a stored hash.
     */
    public static function verifyKey(string $rawKey, string $hash): bool
    {
        // Bcrypt hashes start with $2y$ or $2b$
        if (str_starts_with($hash, '$2')) {
            return Authentication::verify($rawKey, $hash) > 0;
        }

        // Legacy SHA-256 fallback
        return hash_equals($hash, hash('sha256', $rawKey));
    }

    /**
     * Rehash a legacy SHA-256 key to bcrypt.
     */
    public function rehashKey(string $keyId, string $rawKey): void
    {
        $keys = $this->loadKeys();

        if (isset($keys[$keyId]) && is_array($keys[$keyId])) {
            $keys[$keyId]['hash'] = Authentication::create($rawKey);
            $this->saveKeys($keys);
        }
    }

    /**
     * Update last_used timestamp for a key.
     */
    public function touchKey(string $keyId): void
    {
        $keys = $this->loadKeys();

        if (isset($keys[$keyId]) && is_array($keys[$keyId])) {
            $keys[$keyId]['last_used'] = time();
            $this->saveKeys($keys);
        }
    }

    /**
     * Find a key entry by raw API key. Returns [keyId, keyData, username] or null.
     */
    public function findKey(string $rawKey): ?array
    {
        $keys = $this->loadKeys();

        foreach ($keys as $keyId => $keyData) {
            if (!is_array($keyData) || !isset($keyData['hash'])) {
                continue;
            }

            if (self::verifyKey($rawKey, $keyData['hash'])) {
                return [
                    'key_id' => $keyId,
                    'data' => $keyData,
                    'username' => $keyData['username'] ?? '',
                ];
            }
        }

        return null;
    }

    /**
     * Load all API keys from the data file.
     */
    public function loadKeys(): array
    {
        if (static::$keysCache !== null) {
            return static::$keysCache;
        }

        $file = $this->getKeysFile();
        if (!file_exists($file)) {
            static::$keysCache = [];
            return [];
        }

        $data = Yaml::parse(file_get_contents($file)) ?? [];
        static::$keysCache = $data;

        return $data;
    }

    /**
     * Save all API keys to the data file.
     */
    protected function saveKeys(array $keys): void
    {
        $file = $this->getKeysFile();
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Write atomically
        $tmp = $file . '.tmp';
        file_put_contents($tmp, Yaml::dump($keys));
        rename($tmp, $file);

        static::$keysCache = $keys;
    }

    /**
     * Get the path to the API keys data file.
     */
    protected function getKeysFile(): string
    {
        $locator = Grav::instance()['locator'];
        return $locator->findResource('user://data', true, true) . '/api-keys.yaml';
    }

    /**
     * Migrate keys from user account files to centralized storage.
     */
    public function migrateFromAccounts(): int
    {
        $grav = Grav::instance();
        $accounts = $grav['accounts'];
        $locator = $grav['locator'];
        $migrated = 0;

        // Scan account files
        $accountDir = $locator->findResource('account://', true)
            ?: $locator->findResource('user://accounts', true);

        if (!$accountDir || !is_dir($accountDir)) {
            return 0;
        }

        foreach (new \DirectoryIterator($accountDir) as $file) {
            if ($file->isDot() || !$file->isFile() || $file->getExtension() !== 'yaml') {
                continue;
            }

            $username = $file->getBasename('.yaml');
            $user = $accounts->load($username);

            if (!$user->exists()) {
                continue;
            }

            $userKeys = $user->get('api_keys', []);
            if (empty($userKeys)) {
                continue;
            }

            $existingKeys = $this->loadKeys();

            foreach ($userKeys as $keyId => $keyData) {
                if (!is_array($keyData) || isset($existingKeys[$keyId])) {
                    continue;
                }

                $keyData['username'] = $username;
                $existingKeys[$keyId] = $keyData;
                $migrated++;
            }

            $this->saveKeys($existingKeys);
            static::$keysCache = null; // Clear cache for next loadKeys()

            // Remove api_keys from user account
            $user->undef('api_keys');
            $user->save();
        }

        return $migrated;
    }
}
