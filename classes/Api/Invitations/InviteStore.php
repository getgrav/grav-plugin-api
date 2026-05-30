<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Invitations;

use Grav\Common\File\CompiledYamlFile;

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

    private function getFile(): CompiledYamlFile
    {
        return CompiledYamlFile::instance(self::FILE);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(): array
    {
        if ($this->items === null) {
            $content = $this->getFile()->content();
            $this->items = is_array($content) ? $content : [];
        }
        return $this->items;
    }

    private function persist(): void
    {
        $file = $this->getFile();
        $file->save($this->items ?? []);
        $file->free();
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
        $this->load();
        $this->items[$token] = $record;
        $this->persist();
    }

    public function remove(string $token): bool
    {
        $this->load();
        if (!isset($this->items[$token])) {
            return false;
        }
        unset($this->items[$token]);
        $this->persist();
        return true;
    }

    /**
     * Drop any invites whose expiry has passed.
     *
     * @return int number of invites removed
     */
    public function purgeExpired(): int
    {
        $this->load();
        $removed = 0;
        foreach ($this->items as $token => $record) {
            if (self::isExpired($record)) {
                unset($this->items[$token]);
                $removed++;
            }
        }
        if ($removed > 0) {
            $this->persist();
        }
        return $removed;
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
