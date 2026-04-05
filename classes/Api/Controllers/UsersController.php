<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\User\Authentication;
use Grav\Common\User\Interfaces\UserCollectionInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Auth\ApiKeyManager;
use Grav\Plugin\Api\Exceptions\ConflictException;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Serializers\UserSerializer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class UsersController extends AbstractApiController
{
    private ?UserSerializer $serializer = null;

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.users.read');

        $pagination = $this->getPagination($request);

        // Scan account files directly (UserCollection iteration doesn't work in all contexts)
        $allUsers = [];
        foreach ($this->getAllUsernames() as $username) {
            $user = $this->grav['accounts']->load($username);
            if ($user->exists()) {
                $allUsers[] = $this->serializeUser($user);
            }
        }

        $total = count($allUsers);

        // Apply pagination
        $paged = array_slice($allUsers, $pagination['offset'], $pagination['limit']);

        return ApiResponse::paginated(
            data: $paged,
            total: $total,
            page: $pagination['page'],
            perPage: $pagination['per_page'],
            baseUrl: $this->getApiBaseUrl() . '/users',
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.users.read');

        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        return $this->respondWithEtag($this->serializeUser($user));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.users.write');

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['username', 'password', 'email']);

        $username = $body['username'];

        // Validate username format
        if (!preg_match('/^[a-z0-9_-]{3,64}$/i', $username)) {
            throw new ValidationException(
                'Invalid username format.',
                [['field' => 'username', 'message' => 'Username must be 3-64 characters and contain only letters, numbers, hyphens, and underscores.']],
            );
        }

        /** @var UserCollectionInterface $accounts */
        $accounts = $this->grav['accounts'];
        $existing = $accounts->load($username);

        if ($existing->exists()) {
            throw new ConflictException("User '{$username}' already exists.");
        }

        // Create new user
        $user = $accounts->load($username);
        $user->set('email', $body['email']);
        $user->set('fullname', $body['fullname'] ?? '');
        $user->set('title', $body['title'] ?? '');
        $user->set('state', $body['state'] ?? 'enabled');
        $user->set('hashed_password', Authentication::create($body['password']));
        $user->set('created', time());
        $user->set('modified', time());

        if (isset($body['access'])) {
            $user->set('access', $body['access']);
        }

        // Allow plugins to modify the user before save
        $this->fireAdminEvent('onAdminSave', ['object' => &$user]);

        $user->save();

        $this->fireAdminEvent('onAdminAfterSave', ['object' => $user]);
        $this->fireEvent('onApiUserCreated', ['user' => $user]);

        return ApiResponse::created(
            data: $this->serializeUser($user),
            location: $this->getApiBaseUrl() . '/users/' . $username,
            headers: $this->invalidationHeaders(['users:create:' . $username, 'users:list']),
        );
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $currentUser = $this->getUser($request);
        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        // Users can update themselves with just api.access, otherwise need api.users.write
        $isSelf = $currentUser->username === $username;
        if (!$isSelf) {
            $this->requirePermission($request, 'api.users.write');
        } else {
            // Self-edit only requires api.access (already checked by auth middleware)
            $this->requirePermission($request, 'api.access');
        }

        // ETag validation
        $currentHash = $this->generateEtag($this->serializeUser($user));
        $this->validateEtag($request, $currentHash);

        $body = $this->getRequestBody($request);

        if (empty($body)) {
            throw new ValidationException('Request body must contain fields to update.');
        }

        // Partial update - only update provided fields
        $allowedFields = ['email', 'fullname', 'title', 'state', 'language', 'content_editor', 'access', 'twofa_enabled'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $body)) {
                $user->set($field, $body[$field]);
            }
        }

        // Hash password if provided
        if (isset($body['password']) && $body['password'] !== '') {
            $user->set('hashed_password', Authentication::create($body['password']));
        }

        $user->set('modified', time());

        // Allow plugins to modify the user before save
        $this->fireAdminEvent('onAdminSave', ['object' => &$user]);

        $user->save();

        $this->fireAdminEvent('onAdminAfterSave', ['object' => $user]);
        $this->fireEvent('onApiUserUpdated', ['user' => $user]);

        return $this->respondWithEtag(
            $this->serializeUser($user),
            200,
            ['users:update:' . $username, 'users:list'],
        );
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.users.write');

        $currentUser = $this->getUser($request);
        $username = $this->getRouteParam($request, 'username');

        if ($currentUser->username === $username) {
            throw new ForbiddenException('You cannot delete your own account.');
        }

        $user = $this->loadUserOrFail($username);

        $this->fireEvent('onApiBeforeUserDelete', ['user' => $user]);

        // Remove user file
        $file = $user->file();
        if ($file) {
            $file->delete();
        }

        $this->fireEvent('onApiUserDeleted', ['username' => $username]);

        return ApiResponse::noContent(
            $this->invalidationHeaders(['users:delete:' . $username, 'users:list']),
        );
    }

    /**
     * POST /users/{username}/avatar - Upload a custom avatar image.
     */
    public function uploadAvatar(ServerRequestInterface $request): ResponseInterface
    {
        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        $currentUser = $this->getUser($request);
        if ($currentUser->username !== $username) {
            $this->requirePermission($request, 'api.users.write');
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['avatar'] ?? $uploadedFiles['file'] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('No avatar file uploaded.');
        }

        $mime = $file->getClientMediaType() ?? '';
        if (!str_starts_with($mime, 'image/')) {
            throw new ValidationException('Avatar must be an image file.');
        }

        // Save to account://avatars/
        $locator = $this->grav['locator'];
        $avatarDir = $locator->findResource('account://', true) . '/avatars';
        if (!is_dir($avatarDir)) {
            mkdir($avatarDir, 0755, true);
        }

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $filename = $username . '-' . substr(md5((string) time()), 0, 8) . '.' . $ext;
        $filepath = $avatarDir . '/' . $filename;
        $file->moveTo($filepath);

        // Build path relative to Grav root (e.g. user/accounts/avatars/filename.jpg)
        // to match the format used by the old admin plugin.
        $relativeBase = $locator->findResource('account://', false);
        $relativePath = $relativeBase . '/avatars/' . $filename;

        // Update user's avatar reference
        $user->set('avatar', [
            $relativePath => [
                'name' => $filename,
                'type' => $mime,
                'size' => filesize($filepath),
                'path' => $relativePath,
            ],
        ]);
        $user->save();

        return ApiResponse::create(
            $this->serializeUser($user),
            201,
            $this->invalidationHeaders(['users:update:' . $username]),
        );
    }

    /**
     * DELETE /users/{username}/avatar - Remove the custom avatar.
     */
    public function deleteAvatar(ServerRequestInterface $request): ResponseInterface
    {
        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        $currentUser = $this->getUser($request);
        if ($currentUser->username !== $username) {
            $this->requirePermission($request, 'api.users.write');
        }

        // Delete avatar file(s)
        $avatar = $user->get('avatar');
        if (is_array($avatar)) {
            foreach ($avatar as $entry) {
                if (is_array($entry) && isset($entry['path'])) {
                    // path is relative to Grav root (e.g. user/accounts/avatars/file.jpg)
                    $filePath = GRAV_ROOT . '/' . $entry['path'];
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                }
            }
        }

        $user->set('avatar', []);
        $user->save();

        return ApiResponse::create(
            $this->serializeUser($user),
            200,
            $this->invalidationHeaders(['users:update:' . $username]),
        );
    }

    /**
     * POST /users/{username}/2fa - Generate or regenerate 2FA secret and return QR code.
     */
    public function generate2fa(ServerRequestInterface $request): ResponseInterface
    {
        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        // Self or admin
        $currentUser = $this->getUser($request);
        if ($currentUser->username !== $username) {
            $this->requirePermission($request, 'api.users.write');
        }

        if (!class_exists(\Grav\Plugin\Login\TwoFactorAuth\TwoFactorAuth::class)) {
            throw new \Grav\Plugin\Api\Exceptions\ApiException(
                500,
                '2FA Not Available',
                'The Login plugin with 2FA support must be installed.'
            );
        }

        $twoFa = new \Grav\Plugin\Login\TwoFactorAuth\TwoFactorAuth();
        $secret = $twoFa->createSecret();

        // Format secret with spaces for readability
        $formattedSecret = trim(chunk_split($secret, 4, ' '));

        // Save to user
        $user->set('twofa_secret', $formattedSecret);
        $user->save();

        // Generate QR code data URI
        $qrImage = $twoFa->getQrImageData($username, $secret);

        return ApiResponse::create([
            'secret' => $formattedSecret,
            'qr_code' => $qrImage,
        ]);
    }

    public function apiKeys(ServerRequestInterface $request): ResponseInterface
    {
        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        $this->requireApiKeyPermission($request, $username);

        $manager = new ApiKeyManager();
        $keys = $manager->listKeys($user);

        return ApiResponse::create($keys);
    }

    public function createApiKey(ServerRequestInterface $request): ResponseInterface
    {
        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        $this->requireApiKeyPermission($request, $username, write: true);

        $body = $this->getRequestBody($request);
        $name = $body['name'] ?? '';
        $scopes = $body['scopes'] ?? [];
        $expiryDays = isset($body['expiry_days']) ? (int) $body['expiry_days'] : null;

        $manager = new ApiKeyManager();
        $result = $manager->generateKey($user, $name, $scopes, $expiryDays);

        // Return the raw key (shown ONCE only) along with key metadata
        $keys = $manager->listKeys($user);
        $keyMeta = null;
        foreach ($keys as $key) {
            if ($key['id'] === $result['id']) {
                $keyMeta = $key;
                break;
            }
        }

        $data = array_merge($keyMeta ?? [], ['api_key' => $result['key']]);

        return ApiResponse::created(
            data: $data,
            location: $this->getApiBaseUrl() . '/users/' . $username . '/api-keys',
            headers: $this->invalidationHeaders(['users:update:' . $username . ':api-keys']),
        );
    }

    public function deleteApiKey(ServerRequestInterface $request): ResponseInterface
    {
        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        $this->requireApiKeyPermission($request, $username, write: true);

        $keyId = $this->getRouteParam($request, 'keyId');

        $manager = new ApiKeyManager();
        $revoked = $manager->revokeKey($user, $keyId);

        if (!$revoked) {
            throw new NotFoundException("API key '{$keyId}' not found for user '{$username}'.");
        }

        return ApiResponse::noContent(
            $this->invalidationHeaders(['users:update:' . $username . ':api-keys']),
        );
    }

    /**
     * Check permission for API key operations. Own user with api.access is sufficient,
     * otherwise require api.users.read (or api.users.write for mutations).
     */
    private function requireApiKeyPermission(
        ServerRequestInterface $request,
        string $targetUsername,
        bool $write = false,
    ): void {
        $currentUser = $this->getUser($request);
        $isSelf = $currentUser->username === $targetUsername;

        if ($isSelf) {
            // Self-access only requires api.access
            $this->requirePermission($request, 'api.access');
        } else {
            $this->requirePermission($request, $write ? 'api.users.write' : 'api.users.read');
        }
    }

    private function loadUserOrFail(?string $username): UserInterface
    {
        if ($username === null || $username === '') {
            throw new ValidationException('Username is required.');
        }

        /** @var UserCollectionInterface $accounts */
        $accounts = $this->grav['accounts'];
        $user = $accounts->load($username);

        if (!$user->exists()) {
            throw new NotFoundException("User '{$username}' not found.");
        }

        return $user;
    }

    private function serializeUser(UserInterface $user): array
    {
        return $this->getSerializer()->serialize($user);
    }

    private function getSerializer(): UserSerializer
    {
        return $this->serializer ??= new UserSerializer();
    }

    /**
     * Get all usernames by scanning account files.
     */
    private function getAllUsernames(): array
    {
        $locator = $this->grav['locator'];

        $accountDir = $locator->findResource('account://', true)
            ?: $locator->findResource('user://accounts', true);

        if (!$accountDir || !is_dir($accountDir)) {
            return [];
        }

        $usernames = [];
        foreach (new \DirectoryIterator($accountDir) as $file) {
            if ($file->isDot() || !$file->isFile() || $file->getExtension() !== 'yaml') {
                continue;
            }
            $usernames[] = $file->getBasename('.yaml');
        }

        sort($usernames);
        return $usernames;
    }
}
