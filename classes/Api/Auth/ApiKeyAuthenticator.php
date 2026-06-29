<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Auth;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserCollectionInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Psr\Http\Message\ServerRequestInterface;

class ApiKeyAuthenticator implements AuthenticatorInterface
{
    /**
     * Scopes of the key that last authenticated successfully, or null if none
     * has. The AuthMiddleware reads this immediately after authenticate() to
     * stamp the request with `api_key_scopes` so requirePermission() can cap a
     * scoped key to exactly its declared permissions (GHSA-x7hm). A fresh
     * authenticator instance is built per request, so this is request-local.
     *
     * @var array<int, mixed>|null
     */
    private ?array $authenticatedScopes = null;

    public function __construct(
        protected readonly Grav $grav,
    ) {}

    /**
     * Scopes recorded for the most recent successful authenticate() call.
     * An empty array means an unscoped key (full account access).
     *
     * @return array<int, mixed>
     */
    public function getAuthenticatedScopes(): array
    {
        return $this->authenticatedScopes ?? [];
    }

    public function authenticate(ServerRequestInterface $request): ?UserInterface
    {
        $apiKey = $this->extractApiKey($request);
        if (!$apiKey || !str_starts_with($apiKey, 'grav_')) {
            return null;
        }

        $manager = new ApiKeyManager();
        $match = $manager->findKey($apiKey);

        if (!$match) {
            return null;
        }

        $keyData = $match['data'];
        $keyId = $match['key_id'];
        $username = $match['username'];

        // Check if key is active
        if (($keyData['active'] ?? true) === false) {
            return null;
        }

        // Check expiry
        if (isset($keyData['expires']) && $keyData['expires'] < time()) {
            return null;
        }

        // Load the associated user
        /** @var UserCollectionInterface $accounts */
        $accounts = $this->grav['accounts'];
        $user = $accounts->load($username);

        if (!$user->exists()) {
            return null;
        }

        // Auto-rehash legacy SHA-256 keys to bcrypt
        if (!str_starts_with($keyData['hash'], '$2')) {
            $manager->rehashKey($keyId, $apiKey);
        }

        // Update last_used timestamp
        $manager->touchKey($keyId);

        // Record the key's scopes so the middleware can cap this request to
        // them (GHSA-x7hm). An empty/absent list means full account access.
        $this->authenticatedScopes = isset($keyData['scopes']) && is_array($keyData['scopes'])
            ? $keyData['scopes']
            : [];

        return $user;
    }

    protected function extractApiKey(ServerRequestInterface $request): ?string
    {
        // Check X-API-Key header first
        $key = $request->getHeaderLine('X-API-Key');
        if ($key) {
            return $key;
        }

        // Fall back to query parameter
        $query = $request->getQueryParams();
        return $query['api_key'] ?? null;
    }
}
