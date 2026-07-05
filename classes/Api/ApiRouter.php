<?php

declare(strict_types=1);

namespace Grav\Plugin\Api;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Processors\ProcessorBase;
use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Audit\AuditContext;
use Grav\Plugin\Api\Controllers\AuditController;
use Grav\Plugin\Api\Controllers\AuthController;
use Grav\Plugin\Api\Controllers\BlueprintController;
use Grav\Plugin\Api\Controllers\BlueprintFilesController;
use Grav\Plugin\Api\Controllers\BlueprintUploadController;
use Grav\Plugin\Api\Controllers\ConfigController;
use Grav\Plugin\Api\Controllers\DashboardController;
use Grav\Plugin\Api\Controllers\DashboardWidgetController;
use Grav\Plugin\Api\Controllers\GpmController;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Controllers\SchedulerController;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Controllers\PreferencesController;
use Grav\Plugin\Api\Controllers\ReportsController;
use Grav\Plugin\Api\Controllers\MenubarController;
use Grav\Plugin\Api\Controllers\EditorButtonsController;
use Grav\Plugin\Api\Controllers\PasswordPolicyController;
use Grav\Plugin\Api\Controllers\SettingsController;
use Grav\Plugin\Api\Controllers\SetupController;
use Grav\Plugin\Api\Controllers\SidebarController;
use Grav\Plugin\Api\Controllers\SsoController;
use Grav\Plugin\Api\Controllers\FloatingWidgetController;
use Grav\Plugin\Api\Controllers\ContextPanelController;
use Grav\Plugin\Api\Controllers\SystemController;
use Grav\Plugin\Api\Controllers\UsersController;
use Grav\Plugin\Api\Controllers\GroupsController;
use Grav\Plugin\Api\Controllers\InvitationsController;
use Grav\Plugin\Api\Controllers\AccountsConfigController;
use Grav\Plugin\Api\Controllers\WebhookController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Middleware\AuthMiddleware;
use Grav\Plugin\Api\Middleware\CorsMiddleware;
use Grav\Plugin\Api\Middleware\JsonBodyParserMiddleware;
use Grav\Plugin\Api\Middleware\MethodOverrideMiddleware;
use Grav\Plugin\Api\Middleware\RateLimitMiddleware;
use Grav\Plugin\Api\Response\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RocketTheme\Toolbox\Event\Event;
use Throwable;

use function FastRoute\cachedDispatcher;

class ApiRouter extends ProcessorBase
{
    public $id = 'api_router';
    public $title = 'API Router';

    protected Config $config;

    /** @var array<int,string>|null Cached public-route prefixes after plugin contributions. */
    protected ?array $publicPrefixes = null;

    /** @var array<int,string>|null Cached public-route exact paths after plugin contributions. */
    protected ?array $publicExact = null;


    public function __construct(Grav $container, Config $config)
    {
        parent::__construct($container);
        $this->config = $config;
    }

    /**
     * Open a Grav debugger timer for an API phase, so it lands in the Clockwork
     * timeline next to Grav's own boot processors — giving the admin-next debug
     * panel an auth-vs-controller split that Grav's processor-level timeline
     * can't see on its own (admin2#65). Gated on the debugger being enabled, so
     * it adds nothing in production; Grav's own Server-Timing header already
     * carries the boot phases when the debugger is on.
     */
    protected function startPhase(string $name, string $desc): void
    {
        $debugger = $this->container['debugger'] ?? null;
        if ($debugger && $debugger->enabled()) {
            $debugger->startTimer('api_' . $name, $desc);
        }
    }

    /** Close the phase timer opened by startPhase(). */
    protected function stopPhase(string $name): void
    {
        $debugger = $this->container['debugger'] ?? null;
        if ($debugger && $debugger->enabled()) {
            $debugger->stopTimer('api_' . $name);
        }
    }

    /**
     * Commit and release the session lock for read-only requests so the SPA's
     * parallel GETs (all sharing one session cookie) don't serialize on PHP's
     * exclusive per-session file lock. Only GET/HEAD — mutations may still queue
     * flash messages or rotate the session — and skippable via config.
     */
    protected function closeSessionEarly(string $method): void
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }
        if (!$this->config->get('plugins.api.session_early_close', true)) {
            return;
        }
        $session = $this->container['session'] ?? null;
        if ($session && $session->isStarted()) {
            $session->close();
        }
    }

    /**
     * Whether the request carries a bearer token (so authentication will be
     * stateless and not touch the session). Checks X-API-Token first since
     * FPM/FastCGI strips the Authorization header on some hosts.
     */
    protected function requestHasBearerToken(ServerRequestInterface $request): bool
    {
        if ($request->getHeaderLine('X-API-Token') !== '') {
            return true;
        }
        return stripos($request->getHeaderLine('Authorization'), 'Bearer ') === 0;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->startTimer();

        try {
            // Run through API middleware chain
            $request = (new JsonBodyParserMiddleware())->processRequest($request);
            $request = (new CorsMiddleware($this->config))->processRequest($request);
            // Must run before routing so dispatch sees the overridden method.
            $request = (new MethodOverrideMiddleware())->processRequest($request);

            // Handle CORS preflight
            if ($request->getMethod() === 'OPTIONS') {
                return (new CorsMiddleware($this->config))->createPreflightResponse($request);
            }

            // Require and apply Grav environment
            $this->applyEnvironment($request);

            // Authenticate (skip for public endpoints - use Grav route which is subdirectory-safe)
            $route = $request->getAttribute('route');
            $routePath = $route ? $route->getRoute() : '';
            $base = $this->config->get('plugins.api.route', '/api');
            $prefix = $this->config->get('plugins.api.version_prefix', 'v1');
            $apiBase = '/' . trim($base, '/') . '/' . $prefix;
            $publicPrefixes = [
                $apiBase . '/auth/',
                $apiBase . '/translations/',
                $apiBase . '/thumbnails/',
            ];
            $publicExact = [
                $apiBase . '/ping',
            ];

            // Let plugins contribute additional public routes. The event fires once and
            // its result is cached on this ApiRouter instance.
            if ($this->publicPrefixes === null) {
                $event = new Event([
                    'api_base' => $apiBase,
                    'prefixes' => $publicPrefixes,
                    'exact'    => $publicExact,
                ]);
                $this->container->fireEvent('onApiCollectPublicRoutes', $event);
                $this->publicPrefixes = (array) $event['prefixes'];
                $this->publicExact    = (array) $event['exact'];
            }
            $publicPrefixes = $this->publicPrefixes;
            $publicExact    = $this->publicExact;

            // Entries may be method-scoped as "METHOD /path" (e.g. "GET /api/v1/foo/")
            // so plugins can expose public reads while writes on the same paths
            // still require authentication. Method-less entries match all methods.
            $method = $request->getMethod();
            $matches = static function (string $entry, bool $prefix) use ($method, $routePath): bool {
                $entryMethod = null;
                if (str_contains($entry, ' ')) {
                    [$entryMethod, $entry] = explode(' ', $entry, 2);
                }
                if ($entryMethod !== null && strcasecmp($entryMethod, $method) !== 0) {
                    return false;
                }
                return $prefix ? str_starts_with($routePath, $entry) : $routePath === $entry;
            };

            $isPublic = false;
            foreach ($publicExact as $entry) {
                if ($matches($entry, false)) {
                    $isPublic = true;
                    break;
                }
            }
            if (!$isPublic) {
                foreach ($publicPrefixes as $entry) {
                    if ($matches($entry, true)) {
                        $isPublic = true;
                        break;
                    }
                }
            }

            // When the caller presents a bearer token (the admin SPA always
            // does), authentication is stateless and never reads the session —
            // so release Grav's exclusive session lock BEFORE the comparatively
            // expensive auth (JWT verification) and dispatch, rather than after.
            // Without this, a burst of parallel GETs from one SPA tab serialize
            // through boot+auth on the single session lock and saturate the
            // PHP-FPM pool (503s). Token auth is authoritative when a token is
            // supplied, so we don't fall back to session passthrough here;
            // session-cookie callers keep the lock until the user is resolved
            // (the post-auth closeSessionEarly below). (admin2#65)
            if ($this->requestHasBearerToken($request)) {
                $this->closeSessionEarly($request->getMethod());
            }

            $this->startPhase('auth', 'API: Authentication');
            if (!$isPublic) {
                $request = (new AuthMiddleware($this->container, $this->config))->processRequest($request);
            } else {
                // Optimistic auth: public endpoints still see the caller when
                // credentials are supplied (richer, permission-filtered
                // responses); anonymous callers continue as guests.
                $request = (new AuthMiddleware($this->container, $this->config))->processOptional($request);
            }
            $this->stopPhase('auth');

            // Register admin proxy so Grav core treats API requests as
            // admin-scoped (page visibility, Flex auth scope, events, etc.)
            $user = $request->getAttribute('api_user');
            if ($user && !isset($this->container['admin'])) {
                (new AdminProxy($this->container, $user))->register();
            }

            // Capture request-level forensic context (actor, IP, user-agent) for
            // the audit trail. The semantic onApi* events fired downstream carry
            // only the affected object; this fills in the who/where. Runs whether
            // or not auditing is enabled; it's a few array writes, so the
            // context is ready the moment a controller fires an audited event.
            AuditContext::capture($request, $user);

            // Release the PHP session lock for read-only requests. Grav core
            // starts and EXCLUSIVELY locks the session during boot on every
            // request; the admin SPA fires many GETs that all carry the same
            // session cookie, so without this they serialize on that single
            // lock. GET/HEAD never write session state (flash messages are only
            // queued on mutations), and the user is already resolved above, so
            // committing the session now lets those parallel reads run
            // concurrently instead of queuing (admin2#65).
            $this->closeSessionEarly($request->getMethod());

            // Rate limit (after auth so we can rate limit per-user)
            $rateLimitResult = (new RateLimitMiddleware($this->config))->check($request);
            if ($rateLimitResult['limited']) {
                $response = ErrorResponse::create(429, 'Too Many Requests', 'Rate limit exceeded. Try again later.');
                return $this->addRateLimitHeaders($response, $rateLimitResult);
            }

            // Dispatch the route
            $response = $this->dispatch($request);

            // Add rate limit headers to successful responses
            $response = $this->addRateLimitHeaders($response, $rateLimitResult);

            // Add CORS headers to response
            $response = (new CorsMiddleware($this->config))->addHeaders($request, $response);

        } catch (ApiException $e) {
            // Client-facing 4xx errors (validation, auth, not-found). These were
            // previously returned with no log line at all, which made genuine
            // misconfigurations — e.g. an upload destination that resolves to
            // nothing ("Stream not resolvable") — impossible to diagnose from the
            // server side. Log at debug so routine 401/404s don't flood production
            // logs while the detail stays recoverable on demand.
            $this->container['log']->debug('API client error: ' . $e->getMessage(), [
                'status' => $e->getStatusCode(),
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
            ]);
            $response = ErrorResponse::fromException($e);
            if (isset($rateLimitResult)) {
                $response = $this->addRateLimitHeaders($response, $rateLimitResult);
            }
            // CORS headers on error responses so browsers don't block them
            $response = (new CorsMiddleware($this->config))->addHeaders($request, $response);
        } catch (Throwable $e) {
            $this->container['log']->error('API unhandled exception: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            $response = ErrorResponse::create(
                500,
                'Internal Server Error',
                $this->config->get('system.debugger.enabled') ? $e->getMessage() : 'An unexpected error occurred.'
            );
            // CORS headers on error responses so browsers don't block them
            $response = (new CorsMiddleware($this->config))->addHeaders($request, $response);
        }

        // Don't let a stateless API call leave a front-end session cookie
        // behind that would boot a visitor logged in to the public site in the
        // same browser (admin2#79, #88).
        $this->protectSharedSession();

        $this->stopTimer();

        return $response;
    }

    /**
     * Stop a stateless API call from planting the shared front-end PHP session
     * cookie.
     *
     * The `/api` route is never under the admin path, so it rides the
     * front-end `grav-site-*` session cookie (no `-admin` split). When the SPA
     * reaches the API from a different origin or port — or any time the browser
     * otherwise doesn't send that cookie — Grav still starts a fresh front-end
     * session during boot and queues a `Set-Cookie` for it. Emitted, that
     * cookie overwrites the session of a visitor logged in to the public site
     * in the same browser and boots them; repeated on every background poll it
     * reads as being logged out "every few minutes" once Grav core's
     * obsolete-session grace window elapses (admin2#79, #88).
     *
     * JWT and API-key auth are stateless, so a caller that brought no session
     * cookie of its own has nothing in that freshly-minted session worth
     * keeping: drop its planted `Set-Cookie` so the visitor's own session (or
     * lack of one) is left exactly as it was. A caller that DID present a
     * session cookie is a real browser session — possibly the front-end
     * visitor, or an admin tab — and Grav is just refreshing it, so leave it
     * untouched (an authenticated request carrying the session cookie does not
     * rotate or clear it).
     */
    protected function protectSharedSession(): void
    {
        if (!$this->config->get('plugins.api.protect_frontend_session', true)) {
            return;
        }
        if (headers_sent()) {
            return;
        }

        $session = $this->container['session'] ?? null;
        if (!$session) {
            return;
        }

        $sessionName = $session->getName();
        if (!$sessionName || isset($_COOKIE[$sessionName])) {
            // No session name resolved, or the caller brought its own session
            // cookie — there is nothing freshly-minted to strip.
            return;
        }

        // Remove only the just-planted session cookie, preserving every other
        // Set-Cookie (CORS, remember-me, etc.). Mirrors the header rewrite in
        // Grav\Framework\Session\Session::removeCookie(), which we can't call
        // (protected). The leading space matches "Set-Cookie: <name>=".
        $needle = " {$sessionName}=";
        $kept = [];
        $found = false;
        foreach (headers_list() as $header) {
            if (stripos($header, 'Set-Cookie:') !== 0) {
                continue;
            }
            if (str_contains($header, $needle)) {
                $found = true;
            } else {
                $kept[] = $header;
            }
        }

        if (!$found) {
            return;
        }

        header_remove('Set-Cookie');
        foreach ($kept as $header) {
            header($header, false);
        }
    }

    protected function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $this->startPhase('route', 'API: Routing');
        $dispatcher = $this->createDispatcher();

        $base = $this->config->get('plugins.api.route', '/api');
        $prefix = $this->config->get('plugins.api.version_prefix', 'v1');
        $basePath = '/' . trim($base, '/') . '/' . $prefix;

        // Use Grav's route (base-path-stripped) not the raw URI
        $route = $request->getAttribute('route');
        $gravPath = $route ? $route->getRoute() : $request->getUri()->getPath();

        // Grav's Uri::init strips trailing extensions that match a registered
        // page type (e.g. .md, .txt, .html) before the route is built. Without
        // this re-attach, `DELETE /api/v1/media/notes.txt` would arrive as
        // `/media/notes` and 404.
        if ($route) {
            $extension = (string)$route->getExtension();
            if ($extension !== '' && !str_ends_with($gravPath, '.' . $extension)) {
                $gravPath .= '.' . $extension;
            }
        }

        // On subpath installs (e.g. /sync-testing/grav-c) the PSR-7 URI
        // path includes Grav's base; strip it so substr below cleanly peels
        // off `$basePath` to leave just the route path.
        $gravBase = rtrim((string)$this->container['uri']->rootUrl(false), '/');
        if ($gravBase !== '' && str_starts_with($gravPath, $gravBase)) {
            $gravPath = substr($gravPath, strlen($gravBase)) ?: '/';
        }

        $routePath = substr($gravPath, strlen($basePath)) ?: '/';

        // Ensure leading slash
        if (!str_starts_with($routePath, '/')) {
            $routePath = '/' . $routePath;
        }

        $method = $request->getMethod();
        $routeInfo = $dispatcher->dispatch($method, $routePath);
        $this->stopPhase('route');

        return match ($routeInfo[0]) {
            Dispatcher::NOT_FOUND => ErrorResponse::create(404, 'Not Found', "No route matches '{$method} {$routePath}'."),
            Dispatcher::METHOD_NOT_ALLOWED => ErrorResponse::create(
                405,
                'Method Not Allowed',
                "Method '{$method}' is not allowed. Allowed: " . implode(', ', $routeInfo[1]) . '.',
                ['Allow' => implode(', ', $routeInfo[1])]
            ),
            Dispatcher::FOUND => $this->handleRoute($request, $routeInfo[1], $routeInfo[2]),
        };
    }

    protected function handleRoute(ServerRequestInterface $request, array $handler, array $vars): ResponseInterface
    {
        [$controllerClass, $method] = $handler;

        $controller = new $controllerClass($this->container, $this->config);

        // Grav builds route paths from parse_url() which does not decode
        // percent-escaped octets, so captured params still contain raw %xx
        // sequences (e.g. "imäge1.png" arrives as "im%C3%A4ge1.png").
        // Decode once here so every controller sees real filenames.
        $vars = array_map(
            static fn($v) => is_string($v) ? rawurldecode($v) : $v,
            $vars
        );

        $request = $request->withAttribute('route_params', $vars);

        $this->startPhase('controller', 'API: Controller');
        $response = $controller->$method($request);
        $this->stopPhase('controller');

        return $response;
    }

    protected function createDispatcher(): Dispatcher
    {
        $cacheFile = $this->container['locator']->findResource('cache://api', true, true) . '/route.cache';
        $cacheDisabled = $this->config->get('system.debugger.enabled', false);

        return cachedDispatcher(function (RouteCollector $r) {
            $this->registerCoreRoutes($r);
            $this->registerPluginRoutes($r);
        }, [
            'cacheFile' => $cacheFile,
            'cacheDisabled' => $cacheDisabled,
        ]);
    }

    protected function registerCoreRoutes(RouteCollector $r): void
    {
        // Auth (no auth required for these)
        $r->addRoute('POST', '/auth/token', [AuthController::class, 'token']);
        $r->addRoute('POST', '/auth/2fa/verify', [AuthController::class, 'verify2fa']);
        $r->addRoute('POST', '/auth/refresh', [AuthController::class, 'refresh']);
        $r->addRoute('POST', '/auth/revoke', [AuthController::class, 'revoke']);
        $r->addRoute('POST', '/auth/forgot-password', [AuthController::class, 'forgotPassword']);
        $r->addRoute('POST', '/auth/reset-password', [AuthController::class, 'resetPassword']);
        // Invitation acceptance (public — under /auth/ so it inherits the
        // public-route prefix; the token is the only credential needed).
        $r->addRoute('GET',  '/auth/invite/{token}', [InvitationsController::class, 'validate']);
        $r->addRoute('POST', '/auth/invite/{token}', [InvitationsController::class, 'accept']);
        $r->addRoute('GET',  '/auth/setup', [SetupController::class, 'status']);
        $r->addRoute('POST', '/auth/setup', [SetupController::class, 'create']);
        $r->addRoute('GET',  '/auth/password-policy', [PasswordPolicyController::class, 'show']);

        // SSO / OAuth login bridge for admin-next (public — under /auth/). Static
        // routes before the parameterized ones (FastRoute matching order).
        $r->addRoute('GET',  '/auth/sso/providers', [SsoController::class, 'providers']);
        $r->addRoute('POST', '/auth/sso/exchange', [SsoController::class, 'exchange']);
        $r->addRoute('GET',  '/auth/sso/{provider}/start', [SsoController::class, 'start']);
        $r->addRoute('GET',  '/auth/sso/{provider}/callback', [SsoController::class, 'callback']);

        // Current user profile + resolved permissions (protected — auth required)
        $r->addRoute('GET', '/me', [AuthController::class, 'me']);

        // Languages
        $r->addRoute('GET', '/languages', [PagesController::class, 'siteLanguages']);

        // Pages
        $r->addRoute('GET', '/pages', [PagesController::class, 'index']);
        $r->addRoute('POST', '/pages', [PagesController::class, 'create']);
        $r->addRoute('POST', '/pages/batch', [PagesController::class, 'batch']);
        $r->addRoute('POST', '/pages/reorganize', [PagesController::class, 'reorganize']);
        $r->addRoute('GET', '/pages/{route:.+}/languages', [PagesController::class, 'languages']);
        $r->addRoute('POST', '/pages/{route:.+}/translate', [PagesController::class, 'translate']);
        $r->addRoute('POST', '/pages/{route:.+}/adopt-language', [PagesController::class, 'adoptLanguage']);
        $r->addRoute('POST', '/pages/{route:.+}/sync', [PagesController::class, 'sync']);
        $r->addRoute('POST', '/pages/{route:.+}/preview-token', [PagesController::class, 'previewToken']);
        $r->addRoute('GET', '/pages/{route:.+}/compare', [PagesController::class, 'compare']);
        $r->addRoute('POST', '/pages/{route:.+}/reorder', [PagesController::class, 'reorder']);
        $r->addRoute('GET', '/pages/{route:.+}/media', [MediaController::class, 'pageMedia']);
        $r->addRoute('POST', '/pages/{route:.+}/media', [MediaController::class, 'uploadPageMedia']);
        $r->addRoute('DELETE', '/pages/{route:.+}/media/{filename}', [MediaController::class, 'deletePageMedia']);
        // Per-file metadata (.meta.yaml sidecar) for page media. The static
        // `/meta` suffix keeps these distinct from the delete route above.
        $r->addRoute('GET', '/pages/{route:.+}/media/{filename}/meta', [MediaController::class, 'getPageMediaMeta']);
        $r->addRoute('PATCH', '/pages/{route:.+}/media/{filename}/meta', [MediaController::class, 'savePageMediaMeta']);
        $r->addRoute('DELETE', '/pages/{route:.+}/media/{filename}/meta', [MediaController::class, 'deletePageMediaMeta']);
        $r->addRoute('POST', '/pages/{route:.+}/move', [PagesController::class, 'move']);
        $r->addRoute('POST', '/pages/{route:.+}/copy', [PagesController::class, 'copy']);
        $r->addRoute('GET', '/pages/{route:.+}', [PagesController::class, 'show']);
        $r->addRoute('PATCH', '/pages/{route:.+}', [PagesController::class, 'update']);
        $r->addRoute('DELETE', '/pages/{route:.+}', [PagesController::class, 'delete']);

        // Thumbnails
        $r->addRoute('GET', '/thumbnails/{file:.+}', [MediaController::class, 'thumbnail']);

        // Destination-aware blueprint file-field uploads (theme/plugin/user
        // custom file fields that specify `destination:` in their blueprint).
        $r->addRoute('POST', '/blueprint-upload', [BlueprintUploadController::class, 'upload']);
        $r->addRoute('DELETE', '/blueprint-upload', [BlueprintUploadController::class, 'delete']);

        // Read-only browse for blueprint `folder:` fields (filepicker, mediapicker, …)
        // — any Grav stream, `self@:` token, or relative path under user/.
        $r->addRoute('GET', '/blueprint-files', [BlueprintFilesController::class, 'list']);

        // Site-level media
        $r->addRoute('GET', '/media', [MediaController::class, 'siteMedia']);
        $r->addRoute('POST', '/media', [MediaController::class, 'uploadSiteMedia']);
        $r->addRoute('POST', '/media/folders', [MediaController::class, 'createFolder']);
        $r->addRoute('POST', '/media/rename', [MediaController::class, 'renameFile']);
        // Per-file metadata (.meta.yaml sidecar) for site media. The file is
        // addressed by `?path=` (site paths contain slashes), and these static
        // routes MUST precede the greedy `/media/{filename:.+}` route below so
        // FastRoute doesn't let the catch-all shadow them.
        $r->addRoute('GET', '/media/meta', [MediaController::class, 'getSiteMediaMeta']);
        $r->addRoute('PATCH', '/media/meta', [MediaController::class, 'saveSiteMediaMeta']);
        $r->addRoute('DELETE', '/media/meta', [MediaController::class, 'deleteSiteMediaMeta']);
        $r->addRoute('POST', '/media/order', [MediaController::class, 'setSiteMediaOrder']);
        $r->addRoute('POST', '/media/folders/rename', [MediaController::class, 'renameFolder']);
        $r->addRoute('DELETE', '/media/folders/{path:.+}', [MediaController::class, 'deleteFolder']);
        $r->addRoute('DELETE', '/media/{filename:.+}', [MediaController::class, 'deleteSiteMedia']);

        // Taxonomy
        $r->addRoute('GET', '/taxonomy', [PagesController::class, 'taxonomy']);

        // Config
        $r->addRoute('GET', '/config', [ConfigController::class, 'index']);
        // Static config routes must be registered BEFORE the variable
        // /config/{scope:.+} route below — FastRoute rejects statics that
        // would be shadowed by an earlier-defined variable on the same path.
        $r->addRoute('GET',   '/config/accounts', [AccountsConfigController::class, 'show']);
        $r->addRoute('PATCH', '/config/accounts', [AccountsConfigController::class, 'update']);
        $r->addRoute('POST', '/config/{scope:.+}/revert', [ConfigController::class, 'revert']);
        $r->addRoute('GET', '/config/{scope:.+}', [ConfigController::class, 'show']);
        $r->addRoute('PATCH', '/config/{scope:.+}', [ConfigController::class, 'update']);

        // Users
        $r->addRoute('GET', '/users', [UsersController::class, 'index']);
        // Static route registered before the /users/{username} catch-all so the
        // tab-discovery endpoint is never swallowed as a username lookup.
        $r->addRoute('GET', '/users/filters', [UsersController::class, 'filters']);
        $r->addRoute('POST', '/users', [UsersController::class, 'create']);
        $r->addRoute('GET', '/users/{username}', [UsersController::class, 'show']);
        $r->addRoute('PATCH', '/users/{username}', [UsersController::class, 'update']);
        $r->addRoute('DELETE', '/users/{username}', [UsersController::class, 'delete']);
        $r->addRoute('POST', '/users/{username}/avatar', [UsersController::class, 'uploadAvatar']);
        $r->addRoute('DELETE', '/users/{username}/avatar', [UsersController::class, 'deleteAvatar']);
        $r->addRoute('POST', '/users/{username}/2fa', [UsersController::class, 'generate2fa']);
        $r->addRoute('POST', '/users/{username}/2fa/enable', [UsersController::class, 'enable2fa']);
        $r->addRoute('POST', '/users/{username}/2fa/disable', [UsersController::class, 'disable2fa']);
        $r->addRoute('GET', '/users/{username}/api-keys', [UsersController::class, 'apiKeys']);
        $r->addRoute('POST', '/users/{username}/api-keys', [UsersController::class, 'createApiKey']);
        $r->addRoute('DELETE', '/users/{username}/api-keys/{keyId}', [UsersController::class, 'deleteApiKey']);

        // Groups
        $r->addRoute('GET',    '/groups',         [GroupsController::class, 'index']);
        $r->addRoute('POST',   '/groups',         [GroupsController::class, 'create']);
        $r->addRoute('GET',    '/groups/{name}',  [GroupsController::class, 'show']);
        $r->addRoute('PATCH',  '/groups/{name}',  [GroupsController::class, 'update']);
        $r->addRoute('DELETE', '/groups/{name}',  [GroupsController::class, 'delete']);

        // Invitations (admin). Top-level path (not /users/...) so it never
        // collides with the GET /users/{username} catch-all.
        $r->addRoute('GET',    '/invitations',                 [InvitationsController::class, 'index']);
        $r->addRoute('POST',   '/invitations',                 [InvitationsController::class, 'create']);
        $r->addRoute('DELETE', '/invitations/{token}',         [InvitationsController::class, 'delete']);
        $r->addRoute('POST',   '/invitations/{token}/resend',  [InvitationsController::class, 'resend']);

        // Custom fields discovery (all plugins/themes)
        $r->addRoute('GET', '/custom-fields', [GpmController::class, 'allCustomFields']);

        // GPM (Package Manager)
        $r->addRoute('GET', '/gpm/plugins', [GpmController::class, 'plugins']);
        $r->addRoute('GET', '/gpm/plugins/{slug}', [GpmController::class, 'plugin']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/readme', [GpmController::class, 'readme']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/changelog', [GpmController::class, 'changelog']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/fields', [GpmController::class, 'customFieldBundle']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/field/{type}', [GpmController::class, 'customFieldScript']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/page', [GpmController::class, 'pluginPage']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/page-script', [GpmController::class, 'customPageScript']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/report-script/{reportId}', [GpmController::class, 'reportScript']);
        $r->addRoute('GET', '/gpm/themes', [GpmController::class, 'themes']);
        $r->addRoute('GET', '/gpm/themes/{slug}', [GpmController::class, 'theme']);
        $r->addRoute('GET', '/gpm/themes/{slug}/readme', [GpmController::class, 'readme']);
        $r->addRoute('GET', '/gpm/themes/{slug}/changelog', [GpmController::class, 'changelog']);
        $r->addRoute('GET', '/gpm/themes/{slug}/fields', [GpmController::class, 'customFieldBundle']);
        $r->addRoute('GET', '/gpm/themes/{slug}/field/{type}', [GpmController::class, 'customFieldScript']);
        $r->addRoute('GET', '/gpm/updates', [GpmController::class, 'updates']);
        $r->addRoute('GET', '/gpm/grav/changelog', [GpmController::class, 'gravChangelog']);
        $r->addRoute('POST', '/gpm/install', [GpmController::class, 'install']);
        $r->addRoute('POST', '/gpm/remove', [GpmController::class, 'remove']);
        $r->addRoute('POST', '/gpm/update', [GpmController::class, 'update']);
        $r->addRoute('POST', '/gpm/update-all', [GpmController::class, 'updateAll']);
        $r->addRoute('POST', '/gpm/upgrade', [GpmController::class, 'upgrade']);
        $r->addRoute('POST', '/gpm/direct-install', [GpmController::class, 'directInstall']);
        $r->addRoute('GET', '/gpm/search', [GpmController::class, 'search']);
        $r->addRoute('GET', '/gpm/repository/plugins', [GpmController::class, 'repositoryPlugins']);
        $r->addRoute('GET', '/gpm/repository/themes', [GpmController::class, 'repositoryThemes']);
        $r->addRoute('GET', '/gpm/repository/{slug}', [GpmController::class, 'repositoryPackage']);

        // Dashboard
        $r->addRoute('GET', '/dashboard/notifications', [DashboardController::class, 'notifications']);
        $r->addRoute('POST', '/dashboard/notifications/{id}/hide', [DashboardController::class, 'hideNotification']);
        $r->addRoute('GET', '/dashboard/feed', [DashboardController::class, 'feed']);
        $r->addRoute('GET', '/dashboard/stats', [DashboardController::class, 'stats']);
        $r->addRoute('GET', '/dashboard/security/exposure-probe', [DashboardController::class, 'securityProbe']);
        $r->addRoute('GET', '/dashboard/popularity', [DashboardController::class, 'popularity']);
        $r->addRoute('GET', '/dashboard/widgets', [DashboardWidgetController::class, 'widgets']);
        $r->addRoute('PATCH', '/dashboard/layout', [DashboardWidgetController::class, 'saveUserLayout']);
        $r->addRoute('PATCH', '/dashboard/site-layout', [DashboardWidgetController::class, 'saveSiteLayout']);

        // Admin-next UI preferences (site defaults + per-user overrides + branding)
        $r->addRoute('GET', '/admin-next/preferences', [PreferencesController::class, 'show']);
        $r->addRoute('PATCH', '/admin-next/preferences/user', [PreferencesController::class, 'saveUser']);
        $r->addRoute('DELETE', '/admin-next/preferences/user', [PreferencesController::class, 'resetUser']);
        $r->addRoute('PATCH', '/admin-next/preferences/site', [PreferencesController::class, 'saveSite']);
        $r->addRoute('PATCH', '/admin-next/branding', [PreferencesController::class, 'saveBranding']);
        $r->addRoute('POST', '/admin-next/branding/logo', [PreferencesController::class, 'uploadLogo']);
        $r->addRoute('DELETE', '/admin-next/branding/logo', [PreferencesController::class, 'deleteLogo']);

        // Scheduler
        $r->addRoute('GET', '/scheduler/jobs', [SchedulerController::class, 'jobs']);
        $r->addRoute('GET', '/scheduler/status', [SchedulerController::class, 'status']);
        $r->addRoute('GET', '/scheduler/history', [SchedulerController::class, 'history']);
        $r->addRoute('POST', '/scheduler/run', [SchedulerController::class, 'run']);

        // System Info & Reports
        $r->addRoute('GET', '/systeminfo', [SchedulerController::class, 'systemInfo']);
        $r->addRoute('GET', '/reports', [ReportsController::class, 'index']);
        $r->addRoute('POST', '/reports/twig-content/allowlist', [ReportsController::class, 'allowlistAdd']);
        $r->addRoute('DELETE', '/reports/twig-content/events', [ReportsController::class, 'clearTwigEvents']);
        $r->addRoute('GET', '/reports/twig-content/page', [ReportsController::class, 'twigContentPageStatus']);
        $r->addRoute('GET', '/reports/twig-content/scan', [ReportsController::class, 'twigContentScan']);

        // Audit trail (super-admin only; off by default)
        $r->addRoute('GET', '/audit/status', [AuditController::class, 'status']);
        $r->addRoute('GET', '/audit/events', [AuditController::class, 'events']);
        $r->addRoute('GET', '/audit/facets', [AuditController::class, 'facets']);
        $r->addRoute('GET', '/audit/export', [AuditController::class, 'export']);

        // Webhooks
        $r->addRoute('GET', '/webhooks', [WebhookController::class, 'index']);
        $r->addRoute('POST', '/webhooks', [WebhookController::class, 'create']);
        $r->addRoute('GET', '/webhooks/{id}', [WebhookController::class, 'show']);
        $r->addRoute('PATCH', '/webhooks/{id}', [WebhookController::class, 'update']);
        $r->addRoute('DELETE', '/webhooks/{id}', [WebhookController::class, 'delete']);
        $r->addRoute('GET', '/webhooks/{id}/deliveries', [WebhookController::class, 'deliveries']);
        $r->addRoute('POST', '/webhooks/{id}/test', [WebhookController::class, 'test']);

        // Data resolver — generic endpoint for data-options@ directives
        $r->addRoute('GET', '/data/resolve', [BlueprintController::class, 'resolveData']);

        // Blueprints
        $r->addRoute('GET', '/blueprints/pages', [BlueprintController::class, 'pageTypes']);
        $r->addRoute('GET', '/blueprints/pages/{template:.+}', [BlueprintController::class, 'pageBlueprint']);
        $r->addRoute('GET', '/blueprints/plugins/{plugin}', [BlueprintController::class, 'pluginBlueprint']);
        $r->addRoute('GET', '/blueprints/plugins/{plugin}/pages/{pageId}', [BlueprintController::class, 'pluginPageBlueprint']);
        $r->addRoute('GET', '/blueprints/themes/{theme}', [BlueprintController::class, 'themeBlueprint']);
        $r->addRoute('GET', '/blueprints/users', [BlueprintController::class, 'userBlueprint']);
        $r->addRoute('GET', '/blueprints/users/permissions', [BlueprintController::class, 'permissionsBlueprint']);
        $r->addRoute('GET', '/blueprints/groups', [BlueprintController::class, 'groupBlueprint']);
        $r->addRoute('GET', '/blueprints/groups/new', [BlueprintController::class, 'groupNewBlueprint']);
        $r->addRoute('GET', '/blueprints/config/accounts', [BlueprintController::class, 'accountsConfigBlueprint']);
        $r->addRoute('GET', '/blueprints/config/{scope}', [BlueprintController::class, 'configBlueprint']);

        // System
        $r->addRoute('GET', '/ping', [SystemController::class, 'ping']);
        $r->addRoute('GET', '/system/environments', [SystemController::class, 'environments']);
        $r->addRoute('POST', '/system/environments', [SystemController::class, 'createEnvironment']);
        $r->addRoute('DELETE', '/system/environments/{name}', [SystemController::class, 'deleteEnvironment']);
        $r->addRoute('GET', '/system/info', [SystemController::class, 'info']);
        $r->addRoute('DELETE', '/cache', [SystemController::class, 'clearCache']);
        $r->addRoute('GET', '/system/logs/files', [SystemController::class, 'logFiles']);
        $r->addRoute('GET', '/system/logs', [SystemController::class, 'logs']);
        $r->addRoute('POST', '/system/backup', [SystemController::class, 'backup']);
        $r->addRoute('GET', '/system/backups', [SystemController::class, 'backups']);
        $r->addRoute('DELETE', '/system/backups/{filename}', [SystemController::class, 'deleteBackup']);
        $r->addRoute('GET', '/system/backups/{filename}/download', [SystemController::class, 'downloadBackup']);

        // Translations
        $r->addRoute('GET', '/translations/{lang}', [SystemController::class, 'translations']);

        // Admin UI languages (locales the admin itself can be rendered in,
        // as opposed to /languages which lists site content languages).
        $r->addRoute('GET', '/admin/languages', [SystemController::class, 'adminLanguages']);

        // Menubar
        $r->addRoute('GET', '/menubar/items', [MenubarController::class, 'items']);
        $r->addRoute('POST', '/menubar/actions/{plugin}/{action}', [MenubarController::class, 'executeAction']);

        // Markdown editor toolbar buttons (plugins register via onApiMarkdownEditorButtons)
        $r->addRoute('GET', '/editor/toolbar-buttons', [EditorButtonsController::class, 'items']);

        // Sidebar
        $r->addRoute('GET', '/sidebar/items', [SidebarController::class, 'items']);

        // Admin-next settings panels (plugins register via onApiAdminSettingsPanels)
        $r->addRoute('GET', '/settings/panels', [SettingsController::class, 'panels']);

        // Floating Widgets
        $r->addRoute('GET', '/floating-widgets', [FloatingWidgetController::class, 'items']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/widget-script', [GpmController::class, 'widgetScript']);

        // Context Panels
        $r->addRoute('GET', '/context-panels', [ContextPanelController::class, 'items']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/panel-script', [GpmController::class, 'panelScript']);

        // Plugin Modals (opened via window.__GRAV_DIALOGS.open / menubar `modal` intent)
        $r->addRoute('GET', '/gpm/plugins/{slug}/modal-script/{modalId}', [GpmController::class, 'modalScript']);
    }

    /**
     * Fire event to let other plugins register their API routes.
     */
    protected function registerPluginRoutes(RouteCollector $r): void
    {
        $event = new Event(['routes' => new ApiRouteCollector($r)]);
        $this->container->fireEvent('onApiRegisterRoutes', $event);
    }

    /**
     * Apply the X-Grav-Environment header if provided.
     * Defaults to Grav's auto-detected environment (from hostname) if not set.
     *
     * NOTE: once Grav has booted, `setup()` is idempotent (it early-returns on
     * the `initialized['setup']` guard), so this can only take effect for a
     * request that has NOT yet been set up — it does not switch the environment
     * of an already-booted request. Per-environment CONFIG reads/writes do not
     * rely on this: ConfigController resolves each scope for the requested
     * target from YAML files (ConfigDiffer::effective) when the target differs
     * from the booted environment, so base/"Default" sees base config even
     * though the live Grav instance stays on the hostname overlay.
     */
    protected function applyEnvironment(ServerRequestInterface $request): void
    {
        $environment = $request->getHeaderLine('X-Grav-Environment');

        if (!$environment) {
            // Default to Grav's auto-detected environment
            return;
        }

        // Sanitize — environment should be a valid hostname-style string
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $environment)) {
            throw new Exceptions\ApiException(
                400,
                'Bad Request',
                'Invalid environment name. Use a valid hostname (e.g., localhost, mysite.com).'
            );
        }

        $currentEnv = $this->container['uri']->environment();

        // Only reinitialize if the requested environment differs from current
        if ($environment !== $currentEnv) {
            $this->container->setup($environment);
            $this->config->reload();
        }
    }

    protected function addRateLimitHeaders(ResponseInterface $response, array $result): ResponseInterface
    {
        if (!$this->config->get('plugins.api.rate_limit.enabled', true)) {
            return $response;
        }

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $result['limit'])
            ->withHeader('X-RateLimit-Remaining', (string) $result['remaining'])
            ->withHeader('X-RateLimit-Reset', (string) $result['reset']);
    }
}
