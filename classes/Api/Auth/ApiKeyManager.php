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
    /**
     * Marker cached when a last_used write fails, so following requests back off
     * instead of re-attempting -- and re-logging -- the same failure.
     */
    private const TOUCH_BACKOFF_KEY = 'api-key-touch-failed';
    private const TOUCH_BACKOFF_TTL = 300;

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
        // An all-digit id becomes an int when used as an array key, so never mint one
        do {
            $keyId = bin2hex(random_bytes(8));
        } while (ctype_digit($keyId));
        $hash = Authentication::create($rawKey);
        $expires = $expiryDays !== null ? time() + ($expiryDays * 86400) : null;

        $entry = [
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

        $this->updateKeys(static function (array $keys) use ($keyId, $entry): array {
            $keys[$keyId] = $entry;
            return $keys;
        });

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
        $username = $user->username;
        $revoked = false;

        $this->updateKeys(static function (array $keys) use ($keyId, $username, &$revoked): ?array {
            if (!isset($keys[$keyId]) || ($keys[$keyId]['username'] ?? '') !== $username) {
                return null;
            }

            unset($keys[$keyId]);
            $revoked = true;

            return $keys;
        });

        return $revoked;
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
        $hash = Authentication::create($rawKey);

        $this->updateKeys(static function (array $keys) use ($keyId, $hash): ?array {
            if (!isset($keys[$keyId]) || !is_array($keys[$keyId])) {
                return null;
            }

            $keys[$keyId]['hash'] = $hash;

            return $keys;
        });
    }

    /**
     * Update last_used timestamp for a key.
     *
     * Persisting on every request would rewrite the whole keys file per
     * API-key call (YAML dump + atomic rename), so the write is throttled:
     * last_used is only refreshed once it is more than a minute stale, which
     * is plenty for a "when was this key last used" display.
     *
     * The write is also best-effort. last_used is display-only bookkeeping, so a
     * site whose user/data has become unwritable must still be able to serve
     * API-key requests: a failure here is logged once and backed off, never
     * thrown. Note the throttle above cannot absorb this on its own -- a failed
     * write leaves last_used stale forever, so without the backoff every single
     * request would retry and re-log (#30).
     *
     * The throttle reads this request's copy of the store, but the write itself
     * goes through updateKeys(), so it only ever sets last_used on the store as
     * it is now and never brings back a key that was revoked in the meantime.
     */
    public function touchKey(string $keyId): void
    {
        $keys = $this->loadKeys();

        if (!isset($keys[$keyId]) || !is_array($keys[$keyId])) {
            return;
        }

        $lastUsed = $keys[$keyId]['last_used'] ?? null;
        if (is_numeric($lastUsed) && time() - (int) $lastUsed < 60) {
            return;
        }

        $cache = Grav::instance()['cache'] ?? null;
        if ($cache && $cache->fetch(self::TOUCH_BACKOFF_KEY)) {
            return;
        }

        try {
            $this->updateKeys(static function (array $keys) use ($keyId): ?array {
                if (!isset($keys[$keyId]) || !is_array($keys[$keyId])) {
                    return null;
                }

                $keys[$keyId]['last_used'] = time();

                return $keys;
            });
        } catch (\Throwable $e) {
            $cache?->save(self::TOUCH_BACKOFF_KEY, 1, self::TOUCH_BACKOFF_TTL);

            $grav = Grav::instance();
            if (isset($grav['log'])) {
                $grav['log']->warning(sprintf(
                    'api.keys: could not record last_used for API key %s: %s',
                    $keyId,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Find a key entry by raw API key. Returns [keyId, keyData, username] or null.
     *
     * Verification runs bcrypt (Authentication::verify), which is deliberately
     * ~50ms — so an API-key client hammering many endpoints pays that on every
     * request. To avoid it we keep a short-TTL cache mapping a SHA-256 of the
     * raw key to the key_id it last verified against. SHA-256 is fast and
     * non-reversible, and the key is high-entropy, so the token is safe to
     * store. The cache only skips the bcrypt math: the key_id it returns is
     * re-checked against the current key file here, and active/expiry/existence
     * are re-checked by the authenticator on every request — so a revoked,
     * disabled or expired key never authenticates from a warm cache.
     */
    public function findKey(string $rawKey): ?array
    {
        $keys = $this->loadKeys();

        $cache = $this->verifyCache();
        $token = hash('sha256', $rawKey);

        // Fast path: a recent successful verification of this exact key.
        if ($cache) {
            $cachedId = $cache->fetch($this->verifyCacheKey($token));
            if (is_string($cachedId) && isset($keys[$cachedId]) && is_array($keys[$cachedId]) && isset($keys[$cachedId]['hash'])) {
                return [
                    'key_id' => $cachedId,
                    'data' => $keys[$cachedId],
                    'username' => $keys[$cachedId]['username'] ?? '',
                ];
            }
        }

        foreach ($keys as $keyId => $keyData) {
            if (!is_array($keyData) || !isset($keyData['hash'])) {
                continue;
            }

            if (self::verifyKey($rawKey, $keyData['hash'])) {
                // Cache only successful verifications, never misses.
                $cache?->save($this->verifyCacheKey($token), (string) $keyId, $this->verifyCacheTtl());
                // PHP turns an all-digit array key into an int; callers expect a string
                return [
                    'key_id' => (string) $keyId,
                    'data' => $keyData,
                    'username' => $keyData['username'] ?? '',
                ];
            }
        }

        return null;
    }

    /**
     * Grav cache used to skip repeat bcrypt verifications, or null when the
     * verified-key cache is disabled (ttl 0) or the cache backend is unavailable.
     * Note: Grav's cache no-ops when system.cache.enabled is false — so on a
     * cache-off site this speedup only applies to requests that force the cache
     * on for themselves (the API does this; see plugins.api.force_cache).
     */
    protected function verifyCache(): ?object
    {
        if ($this->verifyCacheTtl() <= 0) {
            return null;
        }
        return Grav::instance()['cache'] ?? null;
    }

    protected function verifyCacheTtl(): int
    {
        return (int) Grav::instance()['config']->get('plugins.api.auth.key_cache_ttl', 600);
    }

    protected function verifyCacheKey(string $token): string
    {
        return 'api-key-verify-' . $token;
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
     * Apply one change to the key store under an exclusive lock.
     *
     * Every change rewrites the whole file, so two requests that each change
     * the copy they read earlier lose whichever write lands first. When the
     * lost write is a revocation, the revoked key works again. The callback is
     * therefore handed the store as re-read inside the lock, never the copy
     * this request loaded when it authenticated, and returns the new store, or
     * null to leave the file as it is.
     *
     * The lock lives on a separate file because saveKeys() replaces
     * api-keys.yaml by rename: a lock held on the old file would not stop a
     * writer that opened the new one.
     *
     * @param callable(array): ?array $change
     */
    protected function updateKeys(callable $change): void
    {
        $file = $this->getKeysFile();
        $dir = dirname($file);

        // Suppressed so a permission failure reaches the RuntimeException below
        // instead of ending the request as a raw PHP warning -- Grav's error
        // handler turns unsilenced warnings into fatals, so without the `@` the
        // clearer message never gets thrown.
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create directory "%s"', $dir));
        }

        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException(sprintf('Unable to write "%s"', $file . '.lock'));
        }

        try {
            @flock($lock, LOCK_EX);

            static::$keysCache = null;
            $keys = $change($this->loadKeys());

            if ($keys !== null) {
                $this->saveKeys($keys);
            }
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Save all API keys to the data file. Only called from updateKeys(), with
     * the lock held.
     */
    protected function saveKeys(array $keys): void
    {
        $file = $this->getKeysFile();

        // Write atomically. Both calls are checked: an unwritable user/data means
        // the temp file is never created, and the failing rename() then reports a
        // missing temp file, which points at the wrong problem entirely (#30).
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, Yaml::dump($keys)) === false) {
            throw new \RuntimeException(sprintf('Unable to write "%s"', $tmp));
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Unable to write "%s"', $file));
        }

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

            $this->updateKeys(static function (array $keys) use ($userKeys, $username, &$migrated): array {
                foreach ($userKeys as $keyId => $keyData) {
                    if (!is_array($keyData) || isset($keys[$keyId])) {
                        continue;
                    }

                    $keyData['username'] = $username;
                    $keys[$keyId] = $keyData;
                    $migrated++;
                }

                return $keys;
            });

            // Remove api_keys from user account
            $user->undef('api_keys');
            $user->save();
        }

        return $migrated;
    }
}
