<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Invitations;

use Grav\Common\Grav;
use RocketTheme\Toolbox\File\YamlFile;

/**
 * Self-contained store for user invitations.
 *
 * Invites are persisted to user-data://accounts/invites.yaml, keyed by token.
 * Each record carries the recipient email plus the access/groups the inviting
 * admin pre-configured — those are applied verbatim when the invite is
 * accepted, so the invitee never gets to choose their own permissions.
 *
 * Deliberately independent of the Login plugin's own Invitations classes so
 * the API plugin has no hard dependency on Login being installed.
 *
 * Every change re-reads the file under an exclusive lock on a sidecar
 * `invites.yaml.lock` and writes it back before the lock is released, so two
 * requests never both act on the same snapshot. take() is how an invite is
 * redeemed: whichever request removes the token gets the record, and every
 * other request sees it gone. The sidecar is locked rather than the YAML file
 * itself because the save replaces the file by rename.
 *
 * Record shape:
 *   token           string  the secret token (also the array key)
 *   email           string  recipient address (locked at acceptance)
 *   fullname        string  optional pre-fill for the accept form
 *   access          array   permission tree applied on acceptance
 *   groups          array   group names applied on acceptance
 *   created         int     unix timestamp
 *   created_by      string  inviting user's username
 *   created_by_name string  inviting user's fullname (email "actor")
 *   expires         int     unix timestamp after which the token is invalid
 */
class InviteStore
{
    private const FILE = 'user-data://accounts/invites.yaml';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $items = null;

    private function path(): string
    {
        return (string) Grav::instance()['locator']->findResource(self::FILE, true, true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function read(): array
    {
        $file = YamlFile::instance($this->path());
        $file->free();
        $content = $file->content();
        $file->free();

        return is_array($content) ? $content : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(): array
    {
        if ($this->items === null) {
            $this->items = $this->read();
        }
        return $this->items;
    }

    /**
     * Read-modify-write the invites under the lock. `$change` receives the
     * current invites by reference and returns the caller's result; the file
     * is written back only when the invites changed.
     *
     * @param callable(array<string, array<string, mixed>>&): mixed $change
     */
    private function mutate(callable $change): mixed
    {
        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create directory "%s"', $dir));
        }

        $handle = @fopen($path . '.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to open "%s.lock"', $path));
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Unable to lock "%s.lock"', $path));
            }

            $items = $this->read();
            $before = $items;
            $result = $change($items);

            if ($items !== $before) {
                $file = YamlFile::instance($path);
                $file->save($items);
                $file->free();
            }
            $this->items = $items;

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Generate a unique, URL-safe token (40 hex chars).
     */
    public function generateToken(): string
    {
        $items = $this->load();
        do {
            try {
                $token = bin2hex(random_bytes(20));
            } catch (\Exception) {
                $token = md5(uniqid((string) mt_rand(), true)) . md5(uniqid((string) mt_rand(), true));
            }
        } while (isset($items[$token]));

        return $token;
    }

    /**
     * @return array<string, array<string, mixed>> token => record
     */
    public function all(): array
    {
        return $this->load();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $token): ?array
    {
        $items = $this->load();
        return $items[$token] ?? null;
    }

    public function getByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        foreach ($this->load() as $record) {
            if (strtolower((string) ($record['email'] ?? '')) === $email) {
                return $record;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $record must contain a 'token' key
     */
    public function add(array $record): void
    {
        $token = (string) ($record['token'] ?? '');
        if ($token === '') {
            throw new \InvalidArgumentException('Invite record requires a token.');
        }
        $this->mutate(static function (array &$items) use ($token, $record): void {
            $items[$token] = $record;
        });
    }

    public function remove(string $token): bool
    {
        return $this->take($token) !== null;
    }

    /**
     * Remove an invite and return its record, or null when it is already gone.
     * Only one caller can ever get a given invite back.
     *
     * @return array<string, mixed>|null
     */
    public function take(string $token): ?array
    {
        return $this->mutate(static function (array &$items) use ($token): ?array {
            $record = $items[$token] ?? null;
            if (!is_array($record)) {
                return null;
            }
            unset($items[$token]);
            return $record;
        });
    }

    /**
     * Drop any invites whose expiry has passed.
     *
     * @return int number of invites removed
     */
    public function purgeExpired(): int
    {
        return $this->mutate(static function (array &$items): int {
            $removed = 0;
            foreach ($items as $token => $record) {
                if (is_array($record) && self::isExpired($record)) {
                    unset($items[$token]);
                    $removed++;
                }
            }
            return $removed;
        });
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function isExpired(array $record): bool
    {
        $expires = (int) ($record['expires'] ?? 0);
        return $expires > 0 && time() > $expires;
    }
}
