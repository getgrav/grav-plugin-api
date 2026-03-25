<?php

declare(strict_types=1);

namespace Grav\Plugin\Api;

use FastRoute\RouteCollector;

/**
 * Wrapper around FastRoute's RouteCollector that provides a clean API
 * for plugins to register their own API routes.
 *
 * Usage in a plugin:
 *   public function onApiRegisterRoutes(Event $event) {
 *       $routes = $event['routes'];
 *       $routes->get('/comments', [CommentsController::class, 'index']);
 *       $routes->post('/comments', [CommentsController::class, 'create']);
 *       $routes->group('/webhooks', function(ApiRouteCollector $group) {
 *           $group->get('', [WebhookController::class, 'index']);
 *           $group->post('', [WebhookController::class, 'create']);
 *       });
 *   }
 */
class ApiRouteCollector
{
    protected string $prefix = '';

    public function __construct(
        protected readonly RouteCollector $collector,
    ) {}

    public function get(string $route, array $handler): self
    {
        $this->collector->addRoute('GET', $this->prefix . $route, $handler);
        return $this;
    }

    public function post(string $route, array $handler): self
    {
        $this->collector->addRoute('POST', $this->prefix . $route, $handler);
        return $this;
    }

    public function patch(string $route, array $handler): self
    {
        $this->collector->addRoute('PATCH', $this->prefix . $route, $handler);
        return $this;
    }

    public function delete(string $route, array $handler): self
    {
        $this->collector->addRoute('DELETE', $this->prefix . $route, $handler);
        return $this;
    }

    public function put(string $route, array $handler): self
    {
        $this->collector->addRoute('PUT', $this->prefix . $route, $handler);
        return $this;
    }

    public function addRoute(string|array $methods, string $route, array $handler): self
    {
        $this->collector->addRoute($methods, $this->prefix . $route, $handler);
        return $this;
    }

    /**
     * Register a group of routes under a shared prefix.
     */
    public function group(string $prefix, callable $callback): self
    {
        $group = new self($this->collector);
        $group->prefix = $this->prefix . $prefix;
        $callback($group);
        return $this;
    }
}
