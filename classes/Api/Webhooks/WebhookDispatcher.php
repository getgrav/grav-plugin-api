<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Webhooks;

use Grav\Common\HTTP\Response;

class WebhookDispatcher
{
    /**
     * Ranges that PHP's FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE accept as
     * public but that are not globally routable, and commonly carry internal
     * infrastructure. Checked on top of those flags, never instead of them.
     */
    private const NON_ROUTABLE_RANGES = [
        '100.64.0.0/10',    // Carrier-grade NAT (RFC 6598); also Tailscale
        '192.0.0.0/24',     // IETF protocol assignments (RFC 6890)
        '198.18.0.0/15',    // Benchmarking (RFC 2544)
        '192.0.2.0/24',     // TEST-NET-1 documentation (RFC 5737)
        '198.51.100.0/24',  // TEST-NET-2 documentation (RFC 5737)
        '203.0.113.0/24',   // TEST-NET-3 documentation (RFC 5737)
        '64:ff9b::/96',     // NAT64 well-known prefix (RFC 6052)
        '64:ff9b:1::/48',   // NAT64 local-use prefix (RFC 8215)
        '2001:db8::/32',    // IPv6 documentation (RFC 3849)
    ];

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
        //
        // Resolve once and pin the answer onto the handle (GHSA-hq2v): checking
        // a hostname and then handing cURL the same hostname leaves cURL to run
        // its own second lookup, so a low-TTL record can answer public for the
        // check and private for the connection moments later. Pinning replaces
        // only the address lookup — the Host header, TLS SNI and certificate
        // verification still use the hostname from the URL, so virtual hosting
        // and HTTPS behave exactly as before.
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            throw new \RuntimeException('Webhook URL targets a private or reserved address.');
        }

        $resolve = [];

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            // A literal IP means cURL performs no lookup at all, so there is no
            // window to close and nothing to pin — the check is final.
            if (!self::hostIsPublic($host)) {
                throw new \RuntimeException('Webhook URL targets a private or reserved address.');
            }
        } else {
            $ips = self::resolvePublicIps($host);
            if ($ips === []) {
                throw new \RuntimeException('Webhook URL targets a private or reserved address.');
            }

            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));

            // "host:port:addr[,addr...]". Every validated address is kept so a
            // round-robin target can still fail over; IPv6 needs brackets.
            $addresses = array_map(
                static fn (string $ip): string => str_contains($ip, ':') ? "[{$ip}]" : $ip,
                $ips
            );

            $resolve[] = $host . ':' . $port . ':' . implode(',', $addresses);
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

        // Note: the pin covers the original host and port only. It is also
        // ignored when a proxy is configured, so keep redirects off and add no
        // proxy here without revisiting this guard.
        if ($resolve !== []) {
            curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
        }

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

        return self::resolvePublicIps($host) !== [];
    }

    /**
     * Resolve a hostname (A + AAAA) and return its addresses only when every one
     * of them is public and routable. Returns an empty array when the lookup
     * fails or any address falls in a private or reserved range, so callers fail
     * closed. The dispatcher pins these addresses onto the cURL handle so the
     * connection cannot land somewhere the check never saw (GHSA-hq2v).
     *
     * @return array<int, string>
     */
    private static function resolvePublicIps(string $host): array
    {
        $host = trim($host, '[]');
        if ($host === '') {
            return [];
        }

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

        $ips = array_values(array_unique($ips));
        if ($ips === []) {
            return [];
        }

        foreach ($ips as $ip) {
            if (!self::ipIsPublic($ip)) {
                return [];
            }
        }

        return $ips;
    }

    /**
     * Whether a literal IP address sits outside every private and reserved
     * range (loopback, RFC1918, link-local, etc.).
     */
    private static function ipIsPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // PHP's reserved-range flags miss several ranges that are not globally
        // routable and routinely carry internal infrastructure. Reaching these
        // needs no DNS trickery at all, just an ordinary record pointing at one.
        foreach (self::NON_ROUTABLE_RANGES as $range) {
            if (self::ipInCidr($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an IP falls inside a CIDR block. Handles IPv4 and IPv6 by
     * comparing the leading prefix bits of the packed addresses.
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $whole = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($whole > 0 && strncmp($ipBin, $subnetBin, $whole) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($ipBin[$whole]) & $mask) === (ord($subnetBin[$whole]) & $mask);
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
