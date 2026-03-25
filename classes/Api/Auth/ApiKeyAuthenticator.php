<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Auth;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserCollectionInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Psr\Http\Message\ServerRequestInterface;

class ApiKeyAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        protected readonly Grav $grav,
    ) {}

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
