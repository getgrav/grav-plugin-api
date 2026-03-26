<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Webhooks;

use Grav\Common\HTTP\Response;

class WebhookDispatcher
{
    /**
     * Map of internal event names to webhook event names.
     */
    private const EVENT_MAP = [
        'onApiPageCreated' => 'page.created',
        'onApiPageUpdated' => 'page.updated',
        'onApiPageDeleted' => 'page.deleted',
        'onApiPageMoved' => 'page.moved',
        'onApiPageTranslated' => 'page.translated',
        'onApiPagesReordered' => 'pages.reordered',
        'onApiMediaUploaded' => 'media.uploaded',
        'onApiMediaDeleted' => 'media.deleted',
        'onApiUserCreated' => 'user.created',
        'onApiUserUpdated' => 'user.updated',
        'onApiUserDeleted' => 'user.deleted',
        'onApiConfigUpdated' => 'config.updated',
        'onApiPackageInstalled' => 'gpm.installed',
        'onApiPackageRemoved' => 'gpm.removed',
        'onApiGravUpgraded' => 'grav.upgraded',
    ];

    private WebhookManager $manager;

    public function __construct(?WebhookManager $manager = null)
    {
        $this->manager = $manager ?? new WebhookManager();
    }

    /**
     * Get the list of subscribed events for the plugin.
     */
    public static function getSubscribedEvents(): array
    {
        $events = [];
        foreach (array_keys(self::EVENT_MAP) as $eventName) {
            $events[$eventName] = ['dispatch', -100]; // Low priority - run after main handlers
        }
        return $events;
    }

    /**
     * Dispatch webhooks for an event.
     */
    public function dispatch(string $internalEvent, array $eventData): void
    {
        $webhookEvent = self::EVENT_MAP[$internalEvent] ?? null;
        if (!$webhookEvent) {
            return;
        }

        $webhooks = $this->manager->getForEvent($webhookEvent);
        if (empty($webhooks)) {
            return;
        }

        $payload = $this->buildPayload($webhookEvent, $eventData);

        foreach ($webhooks as $webhook) {
            $this->send($webhook, $payload);
        }
    }

    /**
     * Send a test payload to a webhook.
     */
    public function sendTest(array $webhook): array
    {
        $payload = $this->buildPayload('test', [
            'message' => 'This is a test webhook delivery.',
        ]);

        return $this->send($webhook, $payload);
    }

    /**
     * Build the webhook payload.
     */
    private function buildPayload(string $event, array $data): array
    {
        // Serialize objects in data to arrays
        $cleanData = $this->serializeEventData($data);

        return [
            'event' => $event,
            'timestamp' => date('c'),
            'data' => $cleanData,
        ];
    }

    /**
     * Send a webhook HTTP request and record the delivery.
     */
    private function send(array $webhook, array $payload): array
    {
        $payload['webhook_id'] = $webhook['id'];
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

        // Generate HMAC signature
        $signature = hash_hmac('sha256', $jsonPayload, $webhook['secret'] ?? '');

        $headers = array_merge(
            [
                'Content-Type' => 'application/json',
                'X-Grav-Signature' => $signature,
                'X-Grav-Event' => $payload['event'],
                'X-Grav-Delivery' => 'dlv_' . bin2hex(random_bytes(8)),
                'User-Agent' => 'Grav-Webhook/1.0',
            ],
            $webhook['headers'] ?? []
        );

        $delivery = [
            'id' => $headers['X-Grav-Delivery'],
            'event' => $payload['event'],
            'url' => $webhook['url'],
            'request_headers' => $headers,
            'request_body' => $payload,
            'created' => time(),
        ];

        $startTime = microtime(true);

        try {
            $response = $this->httpPost($webhook['url'], $jsonPayload, $headers);
            $duration = (int) ((microtime(true) - $startTime) * 1000);

            $delivery['status_code'] = $response['status_code'];
            $delivery['response_body'] = mb_substr($response['body'] ?? '', 0, 1000);
            $delivery['duration_ms'] = $duration;
            $delivery['success'] = $response['status_code'] >= 200 && $response['status_code'] < 300;

            if ($delivery['success']) {
                $this->manager->resetFailureCount($webhook['id']);
            } else {
                $this->manager->recordFailure($webhook['id']);
            }
        } catch (\Exception $e) {
            $duration = (int) ((microtime(true) - $startTime) * 1000);
            $delivery['status_code'] = 0;
            $delivery['error'] = $e->getMessage();
            $delivery['duration_ms'] = $duration;
            $delivery['success'] = false;

            $this->manager->recordFailure($webhook['id']);
        }

        $this->manager->recordDelivery($webhook['id'], $delivery);

        return $delivery;
    }

    /**
     * Make an HTTP POST request.
     */
    private function httpPost(string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL');
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = "{$key}: {$value}";
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $responseBody = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new \RuntimeException('Webhook request failed: ' . $error);
        }

        return [
            'status_code' => $statusCode,
            'body' => is_string($responseBody) ? $responseBody : '',
        ];
    }

    /**
     * Convert event data objects to serializable arrays.
     */
    private function serializeEventData(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_object($value)) {
                // Try common serialization methods
                if (method_exists($value, 'route')) {
                    $result[$key] = [
                        'route' => $value->route(),
                        'title' => method_exists($value, 'title') ? $value->title() : null,
                        'slug' => method_exists($value, 'slug') ? $value->slug() : null,
                    ];
                } elseif (method_exists($value, 'toArray')) {
                    $result[$key] = $value->toArray();
                } elseif (method_exists($value, 'jsonSerialize')) {
                    $result[$key] = $value->jsonSerialize();
                } else {
                    $result[$key] = '(object)';
                }
            } elseif (is_array($value)) {
                $result[$key] = $this->serializeEventData($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
