<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\User\Authentication;
use Grav\Common\User\DataUser\User as DataUser;
use Grav\Common\User\Interfaces\UserCollectionInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Auth\JwtAuthenticator;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ConflictException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Invitations\InviteStore;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * User invitations.
 *
 * An admin pre-configures a new user's permissions/groups and sends a
 * time-limited invite link. The recipient opens the link, chooses their own
 * username/fullname/title/password, and the account is created with exactly
 * the access the admin pre-set — never more. Because the invitee never picks
 * their own access, they cannot make themselves a super admin.
 *
 * Admin endpoints require api.users.write (list requires api.users.read).
 * The accept/validate endpoints live under /auth/ so they are public.
 */
class InvitationsController extends AbstractApiController
{
    use ResolvesAdminBaseUrl;

    private ?InviteStore $store = null;

    private function store(): InviteStore
    {
        return $this->store ??= new InviteStore();
    }

    /**
     * GET /invitations — list pending (non-expired) invites.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.users.read');

        $store = $this->store();
        $store->purgeExpired();

        $data = [];
        foreach ($store->all() as $record) {
            $data[] = $this->serializeInvite($record);
        }

        // Most-recent first.
        usort($data, static fn($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));

        return ApiResponse::create(['invitations' => $data]);
    }

    /**
     * POST /invitations — create an invite and (if email is configured) send it.
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.users.write');

        $actor = $this->getUser($request);
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['email']);

        $email = trim((string) $body['email']);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException(
                'Invalid email address.',
                [['field' => 'email', 'message' => 'A valid email address is required.']],
            );
        }

        /** @var UserCollectionInterface $accounts */
        $accounts = $this->grav['accounts'];
        $existing = $accounts->find($email, ['email']);
        if ($existing && $existing->exists()) {
            throw new ConflictException('A user with that email already exists.');
        }

        // Permissions the invitee will receive. Strip super flags unless the
        // inviting admin is itself super — an admin cannot grant authority it
        // does not hold, and this is the core "can't make yourself super" gate.
        // isSuperWithinScope() also rejects a scoped key on a super account, which
        // a bare isSuperAdmin() would have let seed super access (GHSA-wvpj-fg8h-843q).
        $access = is_array($body['access'] ?? null) ? $body['access'] : [];
        if (!$this->isSuperWithinScope($request)) {
            $access = $this->stripSuperFlags($access);
        }

        // `groups` is super-admin-only, exactly like `access` above and like
        // UsersController create()/update() already enforce: group membership
        // can confer any permission (including api.super) via groups.yaml, so a
        // non-super inviter must not seed group assignments — otherwise an
        // api.users.write caller could invite an account straight into a
        // super-admin group (GHSA-m86m-jjcg-gcvv).
        $groups = [];
        if ($this->isSuperWithinScope($request) && is_array($body['groups'] ?? null)) {
            $groups = array_values(array_filter(
                $body['groups'],
                static fn($g) => is_string($g) && $g !== '',
            ));
        }

        // Expiration: clamp to a sane window; default 7 days.
        $default = (int) $this->config->get('plugins.api.invitations.expiration', 604800);
        $expiration = (int) ($body['expiration'] ?? $default);
        if ($expiration < 300) {
            $expiration = $default;
        }

        $store = $this->store();

        // One pending invite per email — replace any prior one.
        $prior = $store->getByEmail($email);
        if ($prior && isset($prior['token'])) {
            $store->remove((string) $prior['token']);
        }

        $token = $store->generateToken();
        $record = [
            'token'           => $token,
            'email'           => $email,
            'fullname'        => trim((string) ($body['fullname'] ?? '')),
            'access'          => $access,
            'groups'          => $groups,
            'created'         => time(),
            'created_by'      => (string) $actor->username,
            'created_by_name' => (string) ($actor->get('fullname') ?: $actor->username),
            'expires'         => time() + $expiration,
        ];
        $store->add($record);

        $link = $this->buildInviteLink($body['admin_base_url'] ?? null, $request, $token);

        // Email guard mirrors AuthController::forgotPassword. If email isn't
        // configured we still create the invite and hand the link back so the
        // admin can deliver it manually — never silently fail.
        $emailSent = false;
        $warning = null;
        if (isset($this->grav['Email']) && !empty($this->config->get('plugins.email.from'))) {
            try {
                $this->sendInviteEmail($record, $link, $actor, (string) ($body['message'] ?? ''));
                $emailSent = true;
            } catch (\Throwable $e) {
                $this->grav['log']->error('api.invitations: failed to send invite email: ' . $e->getMessage());
                $warning = 'The invitation was created but the email could not be sent. Share the link manually.';
            }
        } else {
            $warning = 'Email is not configured, so no invitation email was sent. Share the link manually.';
        }

        $payload = $this->serializeInvite($record);
        $payload['link'] = $link;
        $payload['email_sent'] = $emailSent;
        if ($warning !== null) {
            $payload['warning'] = $warning;
        }

        return ApiResponse::created(
            data: $payload,
            location: $this->getApiBaseUrl() . '/invitations/' . $token,
            headers: $this->invalidationHeaders(['invitations:list']),
        );
    }

    /**
     * POST /invitations/{token}/resend — re-send an existing invite's email.
     */
    public function resend(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.users.write');

        $token = (string) $this->getRouteParam($request, 'token');
        $record = $this->store()->get($token);
        if ($record === null || InviteStore::isExpired($record)) {
            throw new NotFoundException('Invitation not found or expired.');
        }

        $body = $this->getRequestBody($request);
        $link = $this->buildInviteLink($body['admin_base_url'] ?? null, $request, $token);

        if (!isset($this->grav['Email']) || empty($this->config->get('plugins.email.from'))) {
            throw new ApiException(422, 'Unprocessable Entity', 'Email is not configured. Share the invite link manually.');
        }

        $actor = $this->getUser($request);
        $this->sendInviteEmail($record, $link, $actor, (string) ($body['message'] ?? ''));

        $payload = $this->serializeInvite($record);
        $payload['link'] = $link;
        $payload['email_sent'] = true;

        return ApiResponse::create($payload);
    }

    /**
     * DELETE /invitations/{token} — revoke an invite.
     */
    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.users.write');

        $token = (string) $this->getRouteParam($request, 'token');
        if (!$this->store()->remove($token)) {
            throw new NotFoundException('Invitation not found.');
        }

        return $this->respondWithInvalidation(null, ['invitations:list'], 204);
    }

    /**
     * GET /auth/invite/{token} — PUBLIC. Validate a token for the accept page.
     *
     * Returns only what the accept form needs (email to lock, optional
     * fullname prefill, validity). Never leaks the pre-set access/groups.
     */
    public function validate(ServerRequestInterface $request): ResponseInterface
    {
        $token = (string) $this->getRouteParam($request, 'token');
        $record = $this->store()->get($token);

        if ($record === null) {
            throw new NotFoundException('This invitation is invalid.');
        }

        if (InviteStore::isExpired($record)) {
            return ApiResponse::create([
                'valid'   => false,
                'expired' => true,
                'email'   => (string) ($record['email'] ?? ''),
            ]);
        }

        return ApiResponse::create([
            'valid'    => true,
            'expired'  => false,
            'email'    => (string) ($record['email'] ?? ''),
            'fullname' => (string) ($record['fullname'] ?? ''),
        ]);
    }

    /**
     * POST /auth/invite/{token} — PUBLIC. Accept an invite: create the account
     * with the admin-preset access/groups and auto-login.
     */
    public function accept(ServerRequestInterface $request): ResponseInterface
    {
        $token = (string) $this->getRouteParam($request, 'token');
        $store = $this->store();
        $record = $store->get($token);

        if ($record === null) {
            throw new NotFoundException('This invitation is invalid.');
        }
        if (InviteStore::isExpired($record)) {
            $store->remove($token);
            throw new ApiException(410, 'Gone', 'This invitation has expired.');
        }

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['username', 'password']);

        $username = (string) $body['username'];
        $password = (string) $body['password'];

        // Username format — identical rules to UsersController::create.
        $length = mb_strlen($username);
        if ($length < 3 || $length > 64 || !DataUser::isValidUsername($username)) {
            throw new ValidationException(
                'Invalid username format.',
                [['field' => 'username', 'message' => 'Username must be 3-64 characters and contain only letters, numbers, periods, hyphens, and underscores (and cannot start with a period).']],
            );
        }

        // Password policy — mirror SetupController.
        $pwdRegex = (string) $this->config->get('system.pwd_regex', '');
        if ($pwdRegex !== '' && !@preg_match('#^(?:' . $pwdRegex . ')$#', $password)) {
            throw new ValidationException(
                'Password does not meet the required policy.',
                [['field' => 'password', 'message' => 'Password does not meet the required policy.']],
            );
        }
        if ($pwdRegex === '' && strlen($password) < 8) {
            throw new ValidationException(
                'Password is too short.',
                [['field' => 'password', 'message' => 'Password must be at least 8 characters.']],
            );
        }

        /** @var UserCollectionInterface $accounts */
        $accounts = $this->grav['accounts'];

        if ($accounts->load($username)->exists()) {
            throw new ConflictException("User '{$username}' already exists.");
        }

        $user = $accounts->load($username);
        // Email is locked to the invited address — the token is bound to it.
        $user->set('email', (string) ($record['email'] ?? ''));
        $user->set('fullname', trim((string) ($body['fullname'] ?? ($record['fullname'] ?? ''))));
        $user->set('title', trim((string) ($body['title'] ?? '')));
        $user->set('state', 'enabled');
        $user->set('hashed_password', Authentication::create($password));
        $user->set('created', time());
        $user->set('modified', time());
        // Access + groups come from the invite, NOT the request body — the
        // invitee can never influence their own permissions.
        $user->set('access', is_array($record['access'] ?? null) ? $record['access'] : []);
        if (!empty($record['groups']) && is_array($record['groups'])) {
            $user->set('groups', array_values($record['groups']));
        }
        // Fresh-account hygiene (matches SetupController).
        $user->set('avatar', []);
        $user->set('twofa_enabled', false);
        $user->set('twofa_secret', '');

        // NOTE: unlike the authenticated UsersController::create, this is a
        // PUBLIC endpoint, so the router has not registered the admin proxy
        // ($grav['admin']) that onAdminSave/onAdminAfterSave subscribers
        // (git-sync, SEO, etc.) rely on — firing them here fatals. We follow
        // the same convention as the public SetupController: save the account
        // and fire only the API-level events.
        $user->save();
        $this->fireEvent('onApiUserCreated', ['user' => $user]);
        $this->fireEvent('onApiInvitationAccepted', ['user' => $user, 'invitation' => $record]);

        $store->remove($token);

        // Auto-login the new user (same token pair as /auth/setup).
        $jwt = new JwtAuthenticator($this->grav, $this->config);
        $response = $this->issueTokenPair($jwt, $user);

        return $response->withHeader('X-Invalidates', 'users:list');
    }

    /**
     * Strip super-admin flags from an access tree.
     *
     * @param array<string, mixed> $access
     * @return array<string, mixed>
     */
    private function stripSuperFlags(array $access): array
    {
        foreach (['admin', 'api'] as $scope) {
            if (isset($access[$scope]) && is_array($access[$scope])) {
                unset($access[$scope]['super']);
            }
        }
        return $access;
    }

    private function buildInviteLink(mixed $clientBaseUrl, ServerRequestInterface $request, string $token): string
    {
        $adminBase = $this->resolveAdminBaseUrl($clientBaseUrl, $request, ['/users/invite', '/invite']);
        return rtrim($adminBase, '/') . '/invite?token=' . rawurlencode($token);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function sendInviteEmail(array $record, string $link, UserInterface $actor, string $message = ''): void
    {
        if (!isset($this->grav['Email'])) {
            throw new \RuntimeException('Email service not available.');
        }

        $cfg = $this->grav['config'];
        $siteHost = (string) ($cfg->get('plugins.login.site_host') ?: ($this->grav['uri']->host() ?? ''));

        $context = [
            'invite_link' => $link,
            'actor'       => (string) ($record['created_by_name'] ?? $actor->get('fullname') ?: $actor->username),
            'message'     => $message,
            'site_name'   => $cfg->get('site.title', 'Website'),
            'site_host'   => $siteHost,
            'author'      => $cfg->get('site.author.name', ''),
        ];

        $params = [
            'to'   => (string) ($record['email'] ?? ''),
            'body' => [
                [
                    'content_type' => 'text/html',
                    'template'     => 'emails/api/invite-user.html.twig',
                    'body'         => '',
                ],
            ],
        ];

        /** @var \Grav\Plugin\Email\Email $email */
        $email = $this->grav['Email'];
        $emailMessage = $email->buildMessage($params, $context);
        $email->send($emailMessage);
    }

    /**
     * Public-safe invite representation (no access/groups leakage beyond what
     * an authenticated admin endpoint returns).
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function serializeInvite(array $record): array
    {
        return [
            'token'           => (string) ($record['token'] ?? ''),
            'email'           => (string) ($record['email'] ?? ''),
            'fullname'        => (string) ($record['fullname'] ?? ''),
            'groups'          => array_values((array) ($record['groups'] ?? [])),
            'created'         => (int) ($record['created'] ?? 0),
            'created_by'      => (string) ($record['created_by'] ?? ''),
            'created_by_name' => (string) ($record['created_by_name'] ?? ''),
            'expires'         => (int) ($record['expires'] ?? 0),
            'expired'         => InviteStore::isExpired($record),
        ];
    }
}
