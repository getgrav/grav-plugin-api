<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Webhooks\WebhookDispatcher;
use Grav\Plugin\Api\Webhooks\WebhookManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class WebhookController extends AbstractApiController
{
    private const PERMISSION_READ = 'api.webhooks.read';
    private const PERMISSION_WRITE = 'api.webhooks.write';

    private const VALID_EVENTS = [
        '*',
        'page.created', 'page.updated', 'page.deleted', 'page.moved', 'page.translated',
        'pages.reordered',
        'media.uploaded', 'media.deleted',
        'user.created', 'user.updated', 'user.deleted',
        'config.updated',
        'gpm.installed', 'gpm.removed', 'grav.upgraded',
    ];

    private readonly WebhookManager $manager;

    public function __construct(\Grav\Common\Grav $grav, \Grav\Common\Config\Config $config)
    {
        parent::__construct($grav, $config);
        $this->manager = new WebhookManager();
    }

    /**
     * GET /webhooks - List all configured webhooks.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $webhooks = $this->manager->getAll();

        // Redact secrets in listing
        $data = array_map(function ($webhook) {
            $webhook['secret'] = $this->redactSecret($webhook['secret'] ?? '');
            return $webhook;
        }, $webhooks);

        return ApiResponse::create($data);
    }

    /**
     * POST /webhooks - Create a new webhook.
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['url']);

        $url = $body['url'];
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new ValidationException("Invalid webhook URL: {$url}");
        }

        // Validate events if provided
        if (isset($body['events'])) {
            $this->validateEvents($body['events']);
        }

        $webhook = $this->manager->create($body);

        $location = $this->getApiBaseUrl() . '/webhooks/' . $webhook['id'];

        return ApiResponse::created($webhook, $location);
    }

    /**
     * GET /webhooks/{id} - Get webhook details.
     */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $id = $this->getRouteParam($request, 'id');
        $webhook = $this->manager->get($id);

        if (!$webhook) {
            throw new NotFoundException("Webhook '{$id}' not found.");
        }

        // Redact secret
        $webhook['secret'] = $this->redactSecret($webhook['secret'] ?? '');

        return $this->respondWithEtag($webhook);
    }

    /**
     * PATCH /webhooks/{id} - Update a webhook.
     */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);

        $id = $this->getRouteParam($request, 'id');
        $body = $this->getRequestBody($request);

        if (isset($body['url']) && !filter_var($body['url'], FILTER_VALIDATE_URL)) {
            throw new ValidationException("Invalid webhook URL: {$body['url']}");
        }

        if (isset($body['events'])) {
            $this->validateEvents($body['events']);
        }

        $webhook = $this->manager->update($id, $body);

        if (!$webhook) {
            throw new NotFoundException("Webhook '{$id}' not found.");
        }

        // Redact secret
        $webhook['secret'] = $this->redactSecret($webhook['secret'] ?? '');

        return $this->respondWithEtag($webhook);
    }

    /**
     * DELETE /webhooks/{id} - Delete a webhook.
     */
    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);

        $id = $this->getRouteParam($request, 'id');
        $deleted = $this->manager->delete($id);

        if (!$deleted) {
            throw new NotFoundException("Webhook '{$id}' not found.");
        }

        return ApiResponse::noContent();
    }

    /**
     * GET /webhooks/{id}/deliveries - Get delivery log for a webhook.
     */
    public function deliveries(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $id = $this->getRouteParam($request, 'id');

        if (!$this->manager->get($id)) {
            throw new NotFoundException("Webhook '{$id}' not found.");
        }

        $pagination = $this->getPagination($request);
        $result = $this->manager->getDeliveries($id, $pagination['limit'], $pagination['offset']);
        $baseUrl = $this->getApiBaseUrl() . '/webhooks/' . $id . '/deliveries';

        return ApiResponse::paginated(
            data: $result['deliveries'],
            total: $result['total'],
            page: $pagination['page'],
            perPage: $pagination['per_page'],
            baseUrl: $baseUrl,
        );
    }

    /**
     * POST /webhooks/{id}/test - Send a test payload.
     */
    public function test(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);

        $id = $this->getRouteParam($request, 'id');
        $webhook = $this->manager->get($id);

        if (!$webhook) {
            throw new NotFoundException("Webhook '{$id}' not found.");
        }

        $dispatcher = new WebhookDispatcher($this->manager);
        $delivery = $dispatcher->sendTest($webhook);

        return ApiResponse::create($delivery, $delivery['success'] ? 200 : 502);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function validateEvents(array $events): void
    {
        foreach ($events as $event) {
            if (!in_array($event, self::VALID_EVENTS, true)) {
                $valid = implode(', ', self::VALID_EVENTS);
                throw new ValidationException("Invalid event '{$event}'. Valid events: {$valid}");
            }
        }
    }

    private function redactSecret(string $secret): string
    {
        if (strlen($secret) <= 10) {
            return str_repeat('*', strlen($secret));
        }
        return substr($secret, 0, 6) . str_repeat('*', strlen($secret) - 10) . substr($secret, -4);
    }
}
