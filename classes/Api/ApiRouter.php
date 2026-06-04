<?php

declare(strict_types=1);

namespace Grav\Plugin\Api;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Processors\ProcessorBase;
use Grav\Framework\Psr7\Response;
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
use Grav\Plugin\Api\Controllers\PasswordPolicyController;
use Grav\Plugin\Api\Controllers\SettingsController;
use Grav\Plugin\Api\Controllers\SetupController;
use Grav\Plugin\Api\Controllers\SidebarController;
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
                return (new CorsMiddleware($this->config))->createPreflightResponse();
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

            $isPublic = in_array($routePath, $publicExact, true);
            if (!$isPublic) {
                foreach ($publicPrefixes as $pp) {
                    if (str_starts_with($routePath, $pp)) {
                        $isPublic = true;
                        break;
                    }
                }
            }

            if (!$isPublic) {
                $request = (new AuthMiddleware($this->container, $this->config))->processRequest($request);

                // Register admin proxy so Grav core treats API requests as
                // admin-scoped (page visibility, Flex auth scope, events, etc.)
                $user = $request->getAttribute('api_user');
                if ($user && !isset($this->container['admin'])) {
                    (new AdminProxy($this->container, $user))->register();
                }
            }

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

        $this->stopTimer();

        return $response;
    }

    protected function dispatch(ServerRequestInterface $request): ResponseInterface
    {
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

        return $controller->$method($request);
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
        $r->addRoute('GET', '/pages/{route:.+}/compare', [PagesController::class, 'compare']);
        $r->addRoute('POST', '/pages/{route:.+}/reorder', [PagesController::class, 'reorder']);
        $r->addRoute('GET', '/pages/{route:.+}/media', [MediaController::class, 'pageMedia']);
        $r->addRoute('POST', '/pages/{route:.+}/media', [MediaController::class, 'uploadPageMedia']);
        $r->addRoute('DELETE', '/pages/{route:.+}/media/{filename}', [MediaController::class, 'deletePageMedia']);
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
        $r->addRoute('GET', '/gpm/plugins/{slug}/field/{type}', [GpmController::class, 'customFieldScript']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/page', [GpmController::class, 'pluginPage']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/page-script', [GpmController::class, 'customPageScript']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/report-script/{reportId}', [GpmController::class, 'reportScript']);
        $r->addRoute('GET', '/gpm/themes', [GpmController::class, 'themes']);
        $r->addRoute('GET', '/gpm/themes/{slug}', [GpmController::class, 'theme']);
        $r->addRoute('GET', '/gpm/themes/{slug}/readme', [GpmController::class, 'readme']);
        $r->addRoute('GET', '/gpm/themes/{slug}/changelog', [GpmController::class, 'changelog']);
$r->addRoute('GET', '/gpm/themes/{slug}/field/{type}', [GpmController::class, 'customFieldScript']);
        $r->addRoute('GET', '/gpm/updates', [GpmController::class, 'updates']);
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
