<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Audit;

use Grav\Common\Grav;
use RocketTheme\Toolbox\Event\Event;

/**
 * Listens to the API's semantic mutation + auth events and writes one audit row
 * per action. Capture is decoupled from the controllers; every `onApi*`
 * after-event the rest of the plugin (and third-party plugins) already fire is
 * picked up here, combined with the request-level forensic context
 * (AuditContext), and persisted via AuditStore.
 *
 * Every write is best-effort: a storage failure is swallowed and self-logged so
 * auditing can never break the request that triggered it.
 */
class AuditSubscriber
{
    /**
     * internal event name => [typed code, severity, capture category].
     *
     * The code follows GitHub's `namespace.action` convention; severity uses
     * RFC-5424 levels; the category maps to an `audit.capture.<category>` toggle.
     */
    private const EVENTS = [
        // Auth
        'onApiUserLogin'        => ['user.login',          'info',    'auth'],
        'onApiUserLoginFailure' => ['user.login.failed',   'warning', 'auth'],
        'onApiUserLogout'       => ['user.logout',         'info',    'auth'],
        'onApiPasswordReset'    => ['user.password.reset', 'notice',  'auth'],
        // Pages / content
        'onApiPageCreated'      => ['page.create',    'info',   'content'],
        'onApiPageUpdated'      => ['page.update',    'info',   'content'],
        'onApiPageDeleted'      => ['page.delete',    'notice', 'content'],
        'onApiPageMoved'        => ['page.move',      'info',   'content'],
        'onApiPageTranslated'   => ['page.translate', 'info',   'content'],
        'onApiPagesReordered'   => ['pages.reorder',  'info',   'content'],
        // Media
        'onApiMediaUploaded'          => ['media.upload',          'info',   'media'],
        'onApiMediaDeleted'           => ['media.delete',          'notice', 'media'],
        'onApiMediaMetadataUpdated'   => ['media.metadata.update', 'info',   'media'],
        'onApiMediaMetadataDeleted'   => ['media.metadata.delete', 'notice', 'media'],
        // Users / groups
        'onApiUserCreated'      => ['user.create',  'notice',  'users'],
        'onApiUserUpdated'      => ['user.update',  'notice',  'users'],
        'onApiUserDeleted'      => ['user.delete',  'warning', 'users'],
        'onApiGroupCreated'     => ['group.create', 'notice',  'users'],
        'onApiGroupUpdated'     => ['group.update', 'notice',  'users'],
        'onApiGroupDeleted'     => ['group.delete', 'warning', 'users'],
        // Config
        'onApiConfigUpdated'    => ['config.update', 'notice', 'config'],

        'onApiDemoBaselineCaptured' => ['demo.baseline.capture', 'notice',  'config'],
        'onApiDemoReset'            => ['demo.reset',            'warning', 'config'],
        // Packages / system
        'onApiPackageInstalled' => ['gpm.install', 'notice',  'packages'],
        'onApiPackageUpdated'   => ['gpm.update',  'notice',  'packages'],
        'onApiPackageRemoved'   => ['gpm.remove',  'warning', 'packages'],
        'onApiGravUpgraded'     => ['grav.upgrade', 'warning', 'packages'],
    ];

    /**
     * Max characters stored per content side in a diff. Generous so most page
     * edits are captured whole (an exact diff); only very long bodies truncate.
     * Header scalars use the shorter snippet() default.
     */
    private const CONTENT_MAX = 8000;

    /** Per-request before-snapshots, keyed by target, for the detailed tier. */
    private static array $snapshots = [];

    private ?AuditStore $store = null;

    public function __construct(?AuditStore $store = null)
    {
        $this->store = $store;
    }

    /**
     * Listeners to register. Mutation/auth after-events run at -200 (after the
     * webhook dispatcher at -100, so the action is fully committed); the single
     * before-event we watch for diffs runs at +200 to snapshot pre-mutation
     * state. Registered the same way as the webhook listeners (a closure per
     * event that passes the event name through), so we never depend on the
     * dispatched Event object knowing its own name.
     *
     * @return array<string, array{0:string,1:int}>
     */
    public static function getSubscribedEvents(): array
    {
        $events = [];
        foreach (array_keys(self::EVENTS) as $name) {
            $events[$name] = ['record', -200];
        }
        $events['onApiBeforePageUpdate'] = ['snapshotPageUpdate', 200];
        return $events;
    }

    /**
     * Snapshot the changed fields of a page before an update so the after-event
     * can record a before/after diff (detailed coverage only).
     */
    public function snapshotPageUpdate(string $eventName, Event $event): void
    {
        if (!$this->isDetailed()) {
            return;
        }

        $page = $event['page'] ?? null;
        $patch = $event['data'] ?? null;
        if (!is_object($page) || !is_array($patch)) {
            return;
        }

        $route = method_exists($page, 'rawRoute') ? $page->rawRoute() : null;
        if (!$route) {
            return;
        }

        $before = [];
        $header = (array) (method_exists($page, 'header') ? (array) $page->header() : []);
        foreach (array_keys($patch) as $key) {
            if ($key === 'content') {
                $before['content'] = $this->snippet((string) (method_exists($page, 'rawMarkdown') ? $page->rawMarkdown() : ''), self::CONTENT_MAX);
            } elseif (array_key_exists($key, $header) && is_scalar($header[$key])) {
                $before[$key] = $header[$key];
            }
        }

        self::$snapshots['page:' . $route] = ['before' => $before, 'keys' => array_keys($patch)];
    }

    /**
     * Record one audited action. The single entry point for every mapped event.
     */
    public function record(string $eventName, Event $event): void
    {
        $map = self::EVENTS[$eventName] ?? null;
        if ($map === null || !$this->isEnabled()) {
            return;
        }

        [$code, $severity, $category] = $map;
        if (!$this->categoryEnabled($category)) {
            return;
        }

        try {
            $data = $event->toArray();
            [$actorId, $actorName, $actorRoles] = $this->resolveActor($data);
            [$targetType, $targetId] = $this->resolveTarget($eventName, $data);

            $context = $this->resolveContext($eventName, $data, $targetId);

            $ip = $this->maybeAnonymize((string) ($data['ip'] ?? AuditContext::get('ip') ?? ''));

            $id = $this->getStore()->append([
                'ts'          => $this->nowMs(),
                'event'       => $code,
                'severity'    => $severity,
                'actor_id'    => $actorId,
                'actor_name'  => $actorName,
                'actor_roles' => $actorRoles,
                'auth_method' => AuditContext::get('auth_method'),
                'ip'          => $ip !== '' ? $ip : null,
                'user_agent'  => AuditContext::get('user_agent'),
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'status'      => null,
                'context'     => $context,
            ]);

            $this->maybePrune($id);
        } catch (\Throwable $e) {
            // Auditing must never break the request being audited.
            $this->logFailure($e);
        }
    }

    // ---------------------------------------------------------------------

    /**
     * Actor identity. Auth events carry the user in their payload (the request
     * itself may be unauthenticated, a failed login). Everything else inherits
     * the authenticated caller from AuditContext.
     *
     * @param array<string,mixed> $data
     * @return array{0:?string,1:?string,2:array<int,string>}
     */
    private function resolveActor(array $data): array
    {
        $user = $data['user'] ?? null;
        if (is_object($user)) {
            $id = (string) ($user->get('username') ?? $user->username ?? '');
            $name = (string) ($user->get('fullname') ?: $user->get('username') ?: $user->username ?? '');
            $groups = $user->get('groups');
            return [$id ?: null, $name ?: null, is_array($groups) ? array_values($groups) : []];
        }

        if (!empty($data['username'])) {
            // Failed login etc.; only a username string is known.
            return [(string) $data['username'], (string) $data['username'], []];
        }

        return [
            AuditContext::get('actor_id'),
            AuditContext::get('actor_name'),
            (array) AuditContext::get('actor_roles', []),
        ];
    }

    /**
     * The thing acted on, as (type, id). Falls back to a bare type when the
     * event carries no specific target.
     *
     * @param array<string,mixed> $data
     * @return array{0:?string,1:?string}
     */
    private function resolveTarget(string $eventName, array $data): array
    {
        // Page events: object with route(), or an explicit route string.
        if (str_starts_with($eventName, 'onApiPage')) {
            $page = $data['page'] ?? null;
            $route = $data['new_route'] ?? $data['route'] ?? null;
            if (is_object($page) && method_exists($page, 'route')) {
                $route = $route ?: $page->route();
            }
            return ['page', $route ? (string) $route : null];
        }
        if ($eventName === 'onApiPagesReordered') {
            return ['page', $data['parent'] ?? null];
        }
        if (str_starts_with($eventName, 'onApiMedia')) {
            return ['media', $data['filename'] ?? $data['path'] ?? $data['name'] ?? null];
        }
        if (str_starts_with($eventName, 'onApiUser')) {
            $user = $data['user'] ?? null;
            if (is_object($user)) {
                return ['user', (string) ($user->get('username') ?? $user->username ?? '')];
            }
            return ['user', $data['username'] ?? null];
        }
        if (str_starts_with($eventName, 'onApiGroup')) {
            return ['group', $data['name'] ?? $data['groupname'] ?? null];
        }
        if ($eventName === 'onApiConfigUpdated') {
            return ['config', $data['scope'] ?? null];
        }
        if (str_starts_with($eventName, 'onApiPackage')) {
            return ['package', $data['package'] ?? $data['slug'] ?? $data['name'] ?? null];
        }
        if ($eventName === 'onApiGravUpgraded') {
            return ['grav', $data['version'] ?? null];
        }

        return [null, null];
    }

    /**
     * Compact, JSON-safe extras for the row. Always includes the safe scalars
     * from the payload; in detailed coverage, attaches a before/after diff for
     * page updates and the changed scope for config.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function resolveContext(string $eventName, array $data, ?string $targetId): array
    {
        $context = [];

        // Carry a few well-known scalar hints without dumping whole objects.
        foreach (['method', 'reason', 'old_route', 'new_route', 'lang', 'version'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $context[$key] = $data[$key];
            }
        }

        if ($this->isDetailed() && $eventName === 'onApiPageUpdated') {
            $diff = $this->buildPageDiff($data, $targetId);
            if ($diff !== []) {
                $context['changes'] = $diff;
            }
        }

        return $context;
    }

    /**
     * Diff a page update against the snapshot captured pre-mutation.
     *
     * @param array<string,mixed> $data
     * @return array<string,array{old:mixed,new:mixed}>
     */
    private function buildPageDiff(array $data, ?string $targetId): array
    {
        $page = $data['page'] ?? null;
        if (!is_object($page)) {
            return [];
        }
        $route = method_exists($page, 'rawRoute') ? $page->rawRoute() : $targetId;
        $snapshot = self::$snapshots['page:' . $route] ?? null;
        if ($snapshot === null) {
            return [];
        }

        $header = (array) (method_exists($page, 'header') ? (array) $page->header() : []);
        $diff = [];
        foreach ($snapshot['keys'] as $key) {
            if ($key === 'content') {
                $new = $this->snippet((string) (method_exists($page, 'rawMarkdown') ? $page->rawMarkdown() : ''), self::CONTENT_MAX);
            } elseif (array_key_exists($key, $header) && is_scalar($header[$key])) {
                $new = $header[$key];
            } else {
                continue;
            }
            $old = $snapshot['before'][$key] ?? null;
            if ($old !== $new) {
                $diff[$key] = ['old' => $old, 'new' => $new];
            }
        }

        return $diff;
    }

    // ---------------------------------------------------------------------

    /**
     * Retention is enforced opportunistically: every PRUNE_INTERVAL-th insert
     * triggers a prune. This keeps the DB bounded without depending on Grav's
     * scheduler being wired to system cron (many installs aren't), while costing
     * nothing on the other 999 writes.
     */
    private const PRUNE_INTERVAL = 1000;

    private function maybePrune(int $id): void
    {
        if ($id <= 0 || $id % self::PRUNE_INTERVAL !== 0) {
            return;
        }
        $config = Grav::instance()['config'];
        $this->getStore()->prune(
            (int) $config->get('plugins.api.audit.retention_days', 90),
            (int) $config->get('plugins.api.audit.retention_max_rows', 100000),
        );
    }

    /**
     * Mask an IP address when `audit.anonymize_ip` is on: zero the last octet of
     * an IPv4 address, or the last 80 bits (keep the /48) of an IPv6 address,
     * the GDPR-friendlier truncation Matomo/Analytics use. Unrecognized values
     * pass through unchanged. Returns the address verbatim when the toggle is off.
     */
    private function maybeAnonymize(string $ip): string
    {
        if ($ip === '' || !Grav::instance()['config']->get('plugins.api.audit.anonymize_ip', false)) {
            return $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);
            if ($packed !== false) {
                // Keep the first 48 bits (3 groups); zero the remaining 80.
                $masked = substr($packed, 0, 6) . str_repeat("\0", 10);
                $back = @inet_ntop($masked);
                if ($back !== false) {
                    return $back;
                }
            }
        }

        return $ip;
    }

    private function isEnabled(): bool
    {
        return (bool) Grav::instance()['config']->get('plugins.api.audit.enabled', false)
            && AuditStore::available();
    }

    private function isDetailed(): bool
    {
        return Grav::instance()['config']->get('plugins.api.audit.coverage', 'standard') === 'detailed';
    }

    private function categoryEnabled(string $category): bool
    {
        return (bool) Grav::instance()['config']->get('plugins.api.audit.capture.' . $category, true);
    }

    private function getStore(): AuditStore
    {
        return $this->store ??= new AuditStore();
    }

    private function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /** Truncate long values so the log stays compact. */
    private function snippet(string $value, int $max = 280): string
    {
        $value = trim($value);
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) . '…' : $value;
    }

    private function logFailure(\Throwable $e): void
    {
        $log = Grav::instance()['log'] ?? null;
        if ($log) {
            $log->warning('Audit log write failed: ' . $e->getMessage());
        }
    }
}
