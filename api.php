<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\Processors\Events\RequestHandlerEvent;
use Grav\Common\Utils;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;
use Grav\Plugin\Api\ApiRouter;
use Grav\Plugin\Api\Auth\ApiKeyManager;
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
            PermissionsRegisterEvent::class => ['onRegisterPermissions', 1000],
        ];
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

        if (str_starts_with($currentPath, $this->base)) {
            $this->active = true;
        }
    }

    public function onPluginsInitialized(): void
    {
        // Register webhook event listeners (always active, not just on API routes)
        $this->registerWebhookListeners();

        if ($this->active) {
            // Disable pages processing for API requests - we don't need Twig/templates
            $this->grav['pages']->disablePages();
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

        match ($task) {
            'apiKeyGenerate' => $this->handleApiKeyGenerate(),
            'apiKeyRevoke' => $this->handleApiKeyRevoke(),
        };
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
     * Register API-specific permissions.
     */
    public function onRegisterPermissions(PermissionsRegisterEvent $event): void
    {
        $actions = PermissionsReader::fromYaml("plugin://{$this->name}/permissions.yaml");
        $event->permissions->addActions($actions);
    }
}
