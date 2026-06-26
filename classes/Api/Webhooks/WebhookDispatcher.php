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
        // Re-validate at dispatch time (SSRF guard, GHSA-58q8): a host that
        // passed create/update validation could rebind to an internal address
        // before delivery. Fail closed on anything non-public.
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || !self::hostIsPublic($host)) {
            throw new \RuntimeException('Webhook URL targets a private or reserved address.');
        }

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
            // Restrict to HTTP(S) so a file://, gopher://, etc. URL — or a
            // redirect to one — can't be used to reach the local filesystem or
            // internal services (SSRF guard, GHSA-58q8).
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
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
     * Whether a hostname (or literal IP) resolves only to public, routable
     * addresses. Rejects loopback (127.0.0.0/8, ::1), RFC1918 private ranges,
     * link-local (169.254.0.0/16 — incl. the 169.254.169.254 cloud-metadata
     * endpoint) and other reserved ranges. Shared by create/update validation
     * and dispatch-time re-validation (SSRF guard, GHSA-58q8). Fails closed:
     * an unresolvable host returns false.
     */
    public static function hostIsPublic(string $host): bool
    {
        // Strip IPv6 literal brackets, e.g. "[::1]".
        $host = trim($host, '[]');
        if ($host === '') {
            return false;
        }

        // Literal IP — check directly.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::ipIsPublic($host);
        }

        // Resolve the hostname (A + AAAA) and reject if any address — or the
        // lookup itself — fails the public-range test.
        $ips = [];

        $a = @gethostbynamel($host);
        if (is_array($a)) {
            $ips = $a;
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!self::ipIsPublic($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a literal IP address sits outside every private and reserved
     * range (loopback, RFC1918, link-local, etc.).
     */
    private static function ipIsPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
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
