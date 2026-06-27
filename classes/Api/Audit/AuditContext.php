<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Audit;

use Grav\Common\User\Interfaces\UserInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Request-scoped forensic context for the audit trail.
 *
 * The semantic `onApi*` events the subscriber listens to carry the *what* (the
 * page, the user, the config scope) but not the *who* and *where*: the actor,
 * IP, and user-agent. Those are request-level facts. ApiRouter populates this
 * holder once per request (right after authentication resolves the caller), and
 * the AuditSubscriber merges it into every row it writes.
 *
 * One Grav request is one PHP process, so a static holder is safe and avoids
 * threading a context object through Grav's event system.
 */
final class AuditContext
{
    /** @var array<string,mixed> */
    private static array $data = [
        'actor_id' => null,
        'actor_name' => null,
        'actor_roles' => [],
        'auth_method' => null,
        'ip' => null,
        'user_agent' => null,
        'method' => null,
        'path' => null,
    ];

    /**
     * Capture the request-level facts. Called from ApiRouter::process once the
     * caller is known. $user may be null for anonymous/public requests (e.g. a
     * failed login, where identity is what's being established).
     */
    public static function capture(ServerRequestInterface $request, ?UserInterface $user): void
    {
        $server = $request->getServerParams();

        self::$data['ip'] = (string) ($server['REMOTE_ADDR'] ?? '');
        self::$data['user_agent'] = $request->getHeaderLine('User-Agent') ?: null;
        self::$data['method'] = $request->getMethod();
        self::$data['path'] = $request->getUri()->getPath();

        // AuthMiddleware sets the api_key_scopes attribute only when the caller
        // authenticated with an API key (even an unscoped key gets an empty
        // array); it's absent for JWT/session. So presence, not contents, is
        // the signal. A `false` default distinguishes "absent" from "set to []".
        $keyAuth = $request->getAttribute('api_key_scopes', false) !== false;
        self::$data['auth_method'] = $keyAuth ? 'apikey' : 'session';

        if ($user !== null) {
            self::setActor($user);
        }
    }

    /** Record the acting user (split out so auth handlers can set it post-login). */
    public static function setActor(UserInterface $user): void
    {
        self::$data['actor_id'] = (string) ($user->get('username') ?? $user->username ?? '');
        self::$data['actor_name'] = (string) ($user->get('fullname') ?: $user->get('username') ?: $user->username ?? '');

        $groups = $user->get('groups');
        self::$data['actor_roles'] = is_array($groups) ? array_values($groups) : [];
    }

    /** @return array<string,mixed> A copy of the current context. */
    public static function all(): array
    {
        return self::$data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    /** Reset to defaults, primarily for test isolation. */
    public static function reset(): void
    {
        self::$data = [
            'actor_id' => null,
            'actor_name' => null,
            'actor_roles' => [],
            'auth_method' => null,
            'ip' => null,
            'user_agent' => null,
            'method' => null,
            'path' => null,
        ];
    }
}
