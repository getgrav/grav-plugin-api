<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Middleware;

use Grav\Common\Config\Config;
use Psr\Http\Message\ServerRequestInterface;

/**
 * File-based token bucket rate limiter.
 * Cloud-safe: each Grav instance has its own cache directory.
 */
class RateLimitMiddleware
{
    public function __construct(
        protected readonly Config $config,
    ) {}

    /**
     * Check rate limit for the current request.
     *
     * @return array{limited: bool, limit: int, remaining: int, reset: int}
     */
    public function check(ServerRequestInterface $request): array
    {
        $enabled = $this->config->get('plugins.api.rate_limit.enabled', true);
        $limit = (int) $this->config->get('plugins.api.rate_limit.requests', 120);
        $window = (int) $this->config->get('plugins.api.rate_limit.window', 60);

        if (!$enabled) {
            return [
                'limited' => false,
                'limit' => $limit,
                'remaining' => $limit,
                'reset' => time() + $window,
            ];
        }

        $identifier = $this->getIdentifier($request);
        $storageDir = $this->getStorageDir();

        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0775, true);
        }

        $file = $storageDir . '/' . md5($identifier) . '.json';

        return $this->checkLimit($file, $limit, $window);
    }

    protected function getIdentifier(ServerRequestInterface $request): string
    {
        // Use authenticated user if available, otherwise fall back to IP
        $user = $request->getAttribute('api_user');
        if ($user) {
            return 'user:' . $user->username;
        }

        return 'ip:' . ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
    }

    protected function checkLimit(string $file, int $limit, int $window): array
    {
        $now = time();
        $data = ['tokens' => $limit, 'last_refill' => $now];

        // Use file locking for concurrency safety
        $fp = fopen($file, 'c+');
        if (!$fp) {
            // If we can't open the file, allow the request
            return ['limited' => false, 'limit' => $limit, 'remaining' => $limit, 'reset' => $now + $window];
        }

        flock($fp, LOCK_EX);

        $contents = stream_get_contents($fp);
        if ($contents) {
            $data = json_decode($contents, true) ?: $data;
        }

        // Refill tokens based on elapsed time
        $elapsed = $now - ($data['last_refill'] ?? $now);
        $refillRate = $limit / $window;
        $data['tokens'] = min($limit, ($data['tokens'] ?? $limit) + ($elapsed * $refillRate));
        $data['last_refill'] = $now;

        // Try to consume a token
        $limited = $data['tokens'] < 1;
        if (!$limited) {
            $data['tokens'] -= 1;
        }

        // Write back
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $remaining = max(0, (int) floor($data['tokens']));
        $reset = $now + (int) ceil(($limit - $data['tokens']) / $refillRate);

        return [
            'limited' => $limited,
            'limit' => $limit,
            'remaining' => $remaining,
            'reset' => $reset,
        ];
    }

    protected function getStorageDir(): string
    {
        $locator = \Grav\Common\Grav::instance()['locator'];
        return $locator->findResource('cache://api/ratelimit', true, true);
    }
}
