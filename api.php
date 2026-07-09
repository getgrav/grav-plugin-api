<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\Processors\Events\RequestHandlerEvent;
use Grav\Common\Utils;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Events\PluginsLoadedEvent;
use Grav\Framework\Acl\PermissionsReader;
use Grav\Plugin\Api\ApiRouter;
use Grav\Plugin\Api\Auth\JwtAuthenticator;
use Grav\Plugin\Api\Audit\AuditStore;
use Grav\Plugin\Api\Audit\AuditSubscriber;
use Grav\Plugin\Api\Demo\DemoManager;
use Grav\Plugin\Api\Auth\ApiKeyManager;
use Grav\Plugin\Api\Popularity\PopularityTracker;
use Grav\Plugin\Api\Webhooks\WebhookDispatcher;
use RocketTheme\Toolbox\Event\Event;

class ApiPlugin extends Plugin
{
    public $features = [
        'blueprints' => 1000,
    ];

    protected $active = false;
    protected string $base = '';
    protected string $apiRoute = '';

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['setup', 100000],
                ['onPluginsInitialized', 1001],
            ],
            'onRequestHandlerInit' => [
                ['onRequestHandlerInit', 99000],
            ],
            'onBeforeCacheClear' => ['onBeforeCacheClear', 0],
            PermissionsRegisterEvent::class => ['onRegisterPermissions', 1000],
            // Fires from Plugins::init(), which runs BEFORE InitializeProcessor
            // starts the session — the only window in which we can still stop the
            // shared front-end session from being started for a preview request.
            PluginsLoadedEvent::class => ['onPluginsLoaded', 0],
        ];
    }

    /**
     * Isolate the Admin-Next page preview from the visitor's front-end session.
     *
     * Admin-Next renders the preview by pointing an iframe (and the "open in new
     * tab" link) at the real front-end URL. That request rides the shared
     * front-end `grav-site-*` session cookie, and booting/reading that session
     * under the admin context can rotate or invalidate it — logging out a visitor
     * signed in to the public site in the same browser (admin2#88, #79).
     *
     * When the preview flags itself with `admin_preview`, suppress session
     * initialization for this one request so the visitor's session is never read,
     * rewritten, or destroyed. The page still renders (as an anonymous visitor,
     * which is exactly what a layout preview wants), and no `grav-site-*` cookie
     * is planted even on a cross-origin iframe. This must run before the session
     * starts, so it hooks PluginsLoadedEvent rather than onPluginsInitialized
     * (which fires a processor later, after the session is already up).
     */
    public function onPluginsLoaded(): void
    {
        if (
            isset($_GET['admin_preview'])
            && $this->config->get('plugins.api.protect_frontend_session', true)
        ) {
            $this->config->set('system.session.initialize', false);
        }
    }

    /**
     * Force-publish a single page for a validated admin draft-preview request
     * (getgrav/grav-plugin-admin2#100).
     *
     * A page with `published: false` is not routable on the front end, so the
     * admin's preview (an iframe / new-tab navigation to the real front-end URL)
     * 404s. The admin obtains a short-lived, route-scoped token from
     * `POST /pages/{route}/preview-token` — which requires page-read permission —
     * and appends it to the preview URL alongside `admin_preview=1`.
     *
     * This runs on `onPagesInitialized`, after the page index is built but before
     * the page is dispatched (Grav resolves `grav['page']` a few lines later in
     * PagesProcessor). We validate the token's signature and read the single
     * route it authorizes, then flip that one page to published+routable in
     * memory for this request only. Dispatch then finds it and it renders. The
     * token is the entire authorization: `admin_preview=1` on its own never
     * unlocks anything, and a token can only ever reveal the exact page it was
     * minted for. The session stays suppressed throughout (see onPluginsLoaded),
     * so this doesn't reintroduce the front-end-session rotation of admin2#88/#79.
     */
    public function onPreviewPagesInitialized(Event $event): void
    {
        $token = (string) ($_GET['preview_token'] ?? '');
        if ($token === '') {
            return;
        }

        $route = (new JwtAuthenticator($this->grav, $this->config))->validatePreviewToken($token);
        if ($route === null) {
            return;
        }

        $pages = $event['pages'] ?? $this->grav['pages'];
        $page = $pages->find($route);
        if ($page === null) {
            return;
        }

        // Unlock this one page for this request only (nothing is written to disk).
        // Setting both flags also covers a page that is explicitly `routable:
        // false`, which the author still wants to see rendered in a preview.
        $page->published(true);
        $page->routable(true);
    }

    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Early setup - determine if we're on an API route.
     */
    public function setup(): void
    {
        $route = $this->config->get('plugins.api.route');
        if (!$route) {
            return;
        }

        $this->base = '/' . trim($route, '/');
        $prefix = $this->config->get('plugins.api.version_prefix', 'v1');
        $this->apiRoute = $this->base . '/' . $prefix;

        $uri = $this->grav['uri'];
        $currentPath = $uri->path();

        // On subpath installs (e.g. /sync-testing/grav-c) $uri->path() may
        // include Grav's base; strip it before testing the api prefix so
        // the plugin still activates and the api router gets installed.
        $gravBase = rtrim((string)$uri->rootUrl(false), '/');
        if ($gravBase !== '' && str_starts_with($currentPath, $gravBase)) {
            $currentPath = substr($currentPath, strlen($gravBase)) ?: '/';
        }

        if (str_starts_with($currentPath, $this->base)) {
            $this->active = true;
        }
    }

    public function onPluginsInitialized(): void
    {
        // Register webhook event listeners (always active, not just on API routes)
        $this->registerWebhookListeners();

        // Register audit-trail listeners (always active; each listener checks the
        // audit.enabled flag at fire time, so this is a no-op when the feature is
        // off (it is off by default)..
        $this->registerAuditListeners();

        // Register the demo-mode reset scheduler job. Inert until a baseline has
        // been captured, so this is a no-op on normal installs.
        $this->registerDemoListeners();

        // Page-view tracking subscribes for FRONTEND requests only — the
        // handler itself short-circuits for admin/API/non-page requests.
        if (!$this->active && !$this->isAdmin()) {
            $listeners = [
                'onPageInitialized' => ['onFrontendPageInitialized', 0],
            ];

            // Admin draft preview: when a signed, route-scoped preview token
            // rides the request (admin2#100), unlock that one page so an
            // unpublished draft renders instead of 404ing. Only wired up when the
            // preview flags are actually present, so it costs nothing otherwise.
            if (
                isset($_GET['admin_preview'], $_GET['preview_token'])
                && $this->config->get('plugins.api.allow_draft_preview', true)
            ) {
                $listeners['onPagesInitialized'] = ['onPreviewPagesInitialized', 0];
            }

            $this->enable($listeners);
        }

        if ($this->active) {
            // Keep the object cache warm for API requests even when the global
            // cache is switched off. Disabling cache is a frontend-dev workflow
            // (see fresh template/page output) — but the API renders no Twig and
            // no frontend pages, so for it cache-off buys nothing and forces a
            // full page-tree rebuild on every one of the SPA's many small calls
            // (admin2#65). This runs before PagesProcessor builds the index, so
            // the override is in place when the page index is first fetched.
            if ($this->config->get('plugins.api.force_cache', true)) {
                $this->grav['cache']->setEnabled(true);
            }

            // Disable pages processing for API requests - we don't need Twig/templates
            $this->grav['pages']->disablePages();

            // Register the plugin's templates path so server-side operations
            // that need to render Twig (e.g. password reset emails composed
            // by AuthController) can find emails/api/*.html.twig.
            $this->enable([
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                // Disable the audit toggle in the plugin's own config form when
                // the SQLite backend the trail depends on isn't available.
                'onApiBlueprintResolved' => ['onApiBlueprintResolved', 0],
            ]);
            return;
        }

        // Handle admin API key tasks and templates
        if ($this->isAdmin()) {
            // Intercept API key tasks early, before admin's Flex routing
            $this->handleAdminApiKeyTask();

            $this->enable([
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onTwigExtensions' => ['onTwigExtensions', 0],
            ]);
        }
    }

    /**
     * Register Twig function to read API keys from centralized store.
     */
    public function onTwigExtensions(): void
    {
        $manager = new ApiKeyManager();
        $this->grav['twig']->twig()->addFunction(
            new \Twig\TwigFunction('api_keys_for_user', function (string $username) use ($manager) {
                $accounts = $this->grav['accounts'];
                $user = $accounts->load($username);
                if (!$user->exists()) {
                    return [];
                }
                return $manager->listKeys($user);
            })
        );
    }

    /**
     * Check for and handle API key admin tasks directly.
     * This runs before admin's Flex controller, which doesn't fire onAdminTaskExecute.
     */
    protected function handleAdminApiKeyTask(): void
    {
        $uri = $this->grav['uri'];
        $task = $uri->param('task') ?? $_POST['task'] ?? null;

        if (!$task || !in_array($task, ['apiKeyGenerate', 'apiKeyRevoke'], true)) {
            return;
        }

        // Validate nonce
        $nonce = $uri->param('admin-nonce') ?? $_POST['admin-nonce'] ?? null;
        if (!$nonce || !Utils::verifyNonce($nonce, 'admin-form')) {
            $this->outputJson(['status' => 'error', 'message' => 'Invalid security nonce.']);
        }

        // Verify admin is logged in
        $this->grav['session']->init();
        $user = $this->grav['session']->user ?? null;
        if (!$user || !$user->authorized || !$user->authorize('admin.login')) {
            $this->outputJson(['status' => 'error', 'message' => 'Not authorized.']);
        }

        // Authorize the caller against the target account. admin.login is the
        // baseline permission every panel user holds; on its own it only grants
        // management of the caller's OWN keys. Minting or revoking keys for any
        // other account is an account-management operation and requires
        // admin.users / admin.super, and only a super-admin may target a
        // super-admin account. Without this gate an admin.login user could forge
        // a persistent key bound to any account and inherit its API permissions.
        // Mirrors the REST path's requireApiKeyPermission + requireNotSuperTarget.
        // (GHSA-7v74-m76q-8wf3)
        $this->authorizeApiKeyTarget($user);

        match ($task) {
            'apiKeyGenerate' => $this->handleApiKeyGenerate(),
            'apiKeyRevoke' => $this->handleApiKeyRevoke(),
        };
    }

    /**
     * Enforce that the logged-in admin may manage API keys for the account named
     * in the route. Acting on your own account is never an escalation and is
     * always allowed. Acting on any other account requires admin.users or
     * admin.super, and a non-super caller may never target a super-admin
     * account. Terminates the request with a JSON error when the check fails.
     * (GHSA-7v74-m76q-8wf3)
     *
     * @param object $current The logged-in session user.
     */
    protected function authorizeApiKeyTarget($current): void
    {
        $username = $this->getAdminRouteUsername();
        if (!$username) {
            $this->outputJson(['status' => 'error', 'message' => 'Could not determine username.']);
        }

        // Managing your own keys is always allowed.
        if (($current->username ?? null) === $username) {
            return;
        }

        // Managing another account's keys requires account-management rights.
        if (!$current->authorize('admin.super') && !$current->authorize('admin.users')) {
            $this->outputJson(['status' => 'error', 'message' => 'Not authorized.']);
        }

        // Only a super-admin may act on a super-admin account.
        $target = $this->grav['accounts']->load($username);
        if ($target->exists() && $target->authorize('admin.super') && !$current->authorize('admin.super')) {
            $this->outputJson(['status' => 'error', 'message' => 'Only super-admins can manage super-admin accounts.']);
        }
    }

    protected function handleApiKeyGenerate(): void
    {
        $post = $_POST;
        $username = $this->getAdminRouteUsername();

        if (!$username) {
            $this->outputJson(['status' => 'error', 'message' => 'Could not determine username.']);
        }

        $user = $this->grav['accounts']->load($username);
        if (!$user->exists()) {
            $this->outputJson(['status' => 'error', 'message' => "User '{$username}' not found."]);
        }

        $name = $post['name'] ?? 'API Key';
        $expiryDays = !empty($post['expiry_days']) ? (int) $post['expiry_days'] : null;

        $manager = new ApiKeyManager();
        $result = $manager->generateKey($user, $name, [], $expiryDays);

        $this->outputJson([
            'status' => 'success',
            'key' => $result['key'],
            'id' => $result['id'],
            'message' => 'API key generated successfully.',
        ]);
    }

    protected function handleApiKeyRevoke(): void
    {
        $post = $_POST;
        $keyId = $post['key_id'] ?? '';
        $username = $this->getAdminRouteUsername();

        if (!$username || !$keyId) {
            $this->outputJson(['status' => 'error', 'message' => 'Missing parameters.']);
        }

        $user = $this->grav['accounts']->load($username);
        if (!$user->exists()) {
            $this->outputJson(['status' => 'error', 'message' => "User '{$username}' not found."]);
        }

        $manager = new ApiKeyManager();
        $revoked = $manager->revokeKey($user, $keyId);

        $this->outputJson([
            'status' => $revoked ? 'success' : 'error',
            'message' => $revoked ? 'API key revoked.' : 'API key not found.',
        ]);
    }

    /**
     * Output JSON and terminate. Used for admin AJAX tasks.
     */
    protected function outputJson(array $data): never
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data);
        exit;
    }

    /**
     * Extract username from admin route (e.g. /admin/accounts/admin)
     */
    protected function getAdminRouteUsername(): ?string
    {
        $uri = $this->grav['uri'];
        $path = $uri->path();

        if (preg_match('#/(?:accounts|user)/([^/]+)#', $path, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Register plugin templates so admin can find the api_keys field type.
     */
    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Register the API router middleware into Grav's request pipeline.
     */
    public function onRequestHandlerInit(RequestHandlerEvent $event): void
    {
        if (!$this->active) {
            return;
        }

        $route = $event->getRoute();
        $path = $route->getRoute();

        if (str_starts_with($path, $this->base)) {
            $event->addMiddleware('api_router', new ApiRouter($this->grav, $this->config));
        }
    }

    /**
     * Register webhook event listeners for all API mutation events.
     */
    protected function registerWebhookListeners(): void
    {
        $events = WebhookDispatcher::getSubscribedEvents();

        /** @var \Symfony\Component\EventDispatcher\EventDispatcher $dispatcher */
        $eventDispatcher = $this->grav['events'];
        $webhookDispatcher = null;

        foreach ($events as $eventName => [$method, $priority]) {
            $eventDispatcher->addListener($eventName, function (Event $event) use ($eventName, &$webhookDispatcher) {
                // Lazy-load dispatcher only when first event fires
                if ($webhookDispatcher === null) {
                    $webhookDispatcher = new WebhookDispatcher();
                }
                $webhookDispatcher->dispatch($eventName, $event->toArray());
            }, $priority);
        }
    }

    /**
     * When the API plugin's own config form is resolved for admin-next, disable
     * the "Enable Audit Trail" toggle and surface a warning if SQLite (the audit
     * store's backend) is not available, so it cannot be switched on with no way
     * to persist the data. Backend writes already fail closed without SQLite;
     * this just makes the constraint visible in the UI.
     */
    public function onApiBlueprintResolved(Event $event): void
    {
        if (($event['plugin'] ?? null) !== 'api' || AuditStore::available()) {
            return;
        }

        $event['fields'] = $this->annotateAuditUnavailable((array) ($event['fields'] ?? []));
    }

    /**
     * Recursively walk the serialized field tree and, on the `audit.enabled`
     * node, set `disabled` and prepend a SQLite-required warning to its help.
     *
     * @param array<int,array<string,mixed>> $fields
     * @return array<int,array<string,mixed>>
     */
    protected function annotateAuditUnavailable(array $fields): array
    {
        $warning = 'SQLite (the pdo_sqlite PHP extension) is required to store audit data and is not available on this server. Install it to enable the audit trail. ';

        foreach ($fields as &$field) {
            if (!is_array($field)) {
                continue;
            }
            if (($field['name'] ?? null) === 'audit.enabled') {
                $field['disabled'] = true;
                $field['help'] = $warning . (string) ($field['help'] ?? '');
            }
            if (isset($field['fields']) && is_array($field['fields'])) {
                $field['fields'] = $this->annotateAuditUnavailable($field['fields']);
            }
        }
        unset($field);

        return $fields;
    }

    /**
     * Register audit-trail event listeners. Mirrors the webhook listener wiring:
     * one closure per event that forwards the event name + payload to the
     * AuditSubscriber. The subscriber itself is lazily created on first fire and
     * short-circuits when the feature is disabled or SQLite is unavailable.
     */
    protected function registerAuditListeners(): void
    {
        if (!AuditStore::available()) {
            return;
        }

        /** @var \Symfony\Component\EventDispatcher\EventDispatcher $eventDispatcher */
        $eventDispatcher = $this->grav['events'];
        $subscriber = null;

        foreach (AuditSubscriber::getSubscribedEvents() as $eventName => [$method, $priority]) {
            $eventDispatcher->addListener($eventName, function (Event $event) use ($eventName, $method, &$subscriber) {
                if ($subscriber === null) {
                    $subscriber = new AuditSubscriber();
                }
                $subscriber->{$method}($eventName, $event);
            }, $priority);
        }
    }

    /**
     * Register the demo-mode reset scheduler job. The listener forwards the
     * onSchedulerInitialized event to DemoManager, which itself no-ops unless
     * reset_on_schedule is on AND a baseline has been captured — so this stays
     * dormant on any install that isn't running a demo.
     */
    protected function registerDemoListeners(): void
    {
        /** @var \Symfony\Component\EventDispatcher\EventDispatcher $eventDispatcher */
        $eventDispatcher = $this->grav['events'];

        $eventDispatcher->addListener('onSchedulerInitialized', function (Event $event) {
            (new DemoManager($this->grav, $this->grav['config']))->onSchedulerInitialized($event);
        });
    }

    /**
     * Register API-specific permissions.
     */
    /**
     * Clear the API route cache when Grav cache is cleared.
     */
    public function onBeforeCacheClear(\RocketTheme\Toolbox\Event\Event $event): void
    {
        $locator = $this->grav['locator'];
        $cacheDir = $locator->findResource('cache://', true);

        if ($cacheDir) {
            $apiCachePath = $cacheDir . '/api';
            if (is_dir($apiCachePath)) {
                $paths = $event['paths'] ?? [];
                $paths[] = $apiCachePath;
                $event['paths'] = $paths;
            }
        }
    }

    public function onRegisterPermissions(PermissionsRegisterEvent $event): void
    {
        $actions = PermissionsReader::fromYaml("plugin://{$this->name}/permissions.yaml");
        $event->permissions->addActions($actions);
    }

    /**
     * Track a frontend page view. Replaces admin-classic's Popularity
     * tracker so popularity stats keep working in admin-next-only installs.
     */
    public function onFrontendPageInitialized(): void
    {
        (new PopularityTracker())->trackHit();
    }

}
