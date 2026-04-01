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
use Grav\Plugin\Api\Controllers\ConfigController;
use Grav\Plugin\Api\Controllers\DashboardController;
use Grav\Plugin\Api\Controllers\GpmController;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Controllers\SchedulerController;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Controllers\MenubarController;
use Grav\Plugin\Api\Controllers\SidebarController;
use Grav\Plugin\Api\Controllers\SystemController;
use Grav\Plugin\Api\Controllers\UsersController;
use Grav\Plugin\Api\Controllers\WebhookController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Middleware\AuthMiddleware;
use Grav\Plugin\Api\Middleware\CorsMiddleware;
use Grav\Plugin\Api\Middleware\JsonBodyParserMiddleware;
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

            $isPublic = false;
            foreach ($publicPrefixes as $pp) {
                if (str_starts_with($routePath, $pp)) {
                    $isPublic = true;
                    break;
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
        $r->addRoute('POST', '/auth/refresh', [AuthController::class, 'refresh']);
        $r->addRoute('POST', '/auth/revoke', [AuthController::class, 'revoke']);

        // Languages
        $r->addRoute('GET', '/languages', [PagesController::class, 'siteLanguages']);

        // Pages
        $r->addRoute('GET', '/pages', [PagesController::class, 'index']);
        $r->addRoute('POST', '/pages', [PagesController::class, 'create']);
        $r->addRoute('POST', '/pages/batch', [PagesController::class, 'batch']);
        $r->addRoute('POST', '/pages/reorganize', [PagesController::class, 'reorganize']);
        $r->addRoute('GET', '/pages/{route:.+}/languages', [PagesController::class, 'languages']);
        $r->addRoute('POST', '/pages/{route:.+}/translate', [PagesController::class, 'translate']);
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
        $r->addRoute('GET', '/users/{username}/api-keys', [UsersController::class, 'apiKeys']);
        $r->addRoute('POST', '/users/{username}/api-keys', [UsersController::class, 'createApiKey']);
        $r->addRoute('DELETE', '/users/{username}/api-keys/{keyId}', [UsersController::class, 'deleteApiKey']);

        // GPM (Package Manager)
        $r->addRoute('GET', '/gpm/plugins', [GpmController::class, 'plugins']);
        $r->addRoute('GET', '/gpm/plugins/{slug}', [GpmController::class, 'plugin']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/readme', [GpmController::class, 'readme']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/changelog', [GpmController::class, 'changelog']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/field/{type}', [GpmController::class, 'customFieldScript']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/page', [GpmController::class, 'pluginPage']);
        $r->addRoute('GET', '/gpm/plugins/{slug}/page-script', [GpmController::class, 'customPageScript']);
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

        // Scheduler & Reports
        $r->addRoute('GET', '/scheduler/jobs', [SchedulerController::class, 'jobs']);
        $r->addRoute('GET', '/scheduler/status', [SchedulerController::class, 'status']);
        $r->addRoute('GET', '/scheduler/history', [SchedulerController::class, 'history']);
        $r->addRoute('POST', '/scheduler/run', [SchedulerController::class, 'run']);
        $r->addRoute('GET', '/reports', [SchedulerController::class, 'reports']);

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
        $r->addRoute('GET', '/blueprints/pages/{template}', [BlueprintController::class, 'pageBlueprint']);
        $r->addRoute('GET', '/blueprints/plugins/{plugin}', [BlueprintController::class, 'pluginBlueprint']);
        $r->addRoute('GET', '/blueprints/plugins/{plugin}/pages/{pageId}', [BlueprintController::class, 'pluginPageBlueprint']);
        $r->addRoute('GET', '/blueprints/themes/{theme}', [BlueprintController::class, 'themeBlueprint']);
        $r->addRoute('GET', '/blueprints/users', [BlueprintController::class, 'userBlueprint']);
        $r->addRoute('GET', '/blueprints/users/permissions', [BlueprintController::class, 'permissionsBlueprint']);
        $r->addRoute('GET', '/blueprints/config/{scope}', [BlueprintController::class, 'configBlueprint']);

        // System
        $r->addRoute('GET', '/ping', [SystemController::class, 'ping']);
        $r->addRoute('GET', '/system/environments', [SystemController::class, 'environments']);
        $r->addRoute('GET', '/system/info', [SystemController::class, 'info']);
        $r->addRoute('DELETE', '/cache', [SystemController::class, 'clearCache']);
        $r->addRoute('GET', '/system/logs', [SystemController::class, 'logs']);
        $r->addRoute('POST', '/system/backup', [SystemController::class, 'backup']);
        $r->addRoute('GET', '/system/backups', [SystemController::class, 'backups']);

        // Translations
        $r->addRoute('GET', '/translations/{lang}', [SystemController::class, 'translations']);

        // Menubar
        $r->addRoute('GET', '/menubar/items', [MenubarController::class, 'items']);
        $r->addRoute('POST', '/menubar/actions/{plugin}/{action}', [MenubarController::class, 'executeAction']);

        // Sidebar
        $r->addRoute('GET', '/sidebar/items', [SidebarController::class, 'items']);
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
     * Reinitializes config and cache when switching environments.
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
