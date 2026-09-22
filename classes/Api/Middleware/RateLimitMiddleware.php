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

        if (!$enabled || $this->isExcluded($request)) {
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

    /**
     * Whether this request skips the per-user bucket.
     *
     * Only `plugins.api.rate_limit.excluded_paths` exempts anything. Its
     * defaults are `/sync/`, because an editor in a shared session polls it
     * every second plus presence and page-saved checks, roughly 90 requests a
     * minute, which would use up the 120 budget on its own; and `/thumbnails/`,
     * because every tile in a media folder is its own `<img>` request, so
     * scrolling a few hundred files runs the budget dry and leaves blank tiles
     * (admin2#178). That route is already public, only reads thumbnails the
     * authenticated listing generated (a miss is a 404, never a resize), and is
     * served with a year-long immutable cache, so a request there costs no more
     * than an ordinary front-end page view, which is not rate limited either.
     * Nothing else gets a pass; the plugin scripts admin2 loads are a handful
     * per page, cached with an ETag, and the client backs off on a 429.
     *
     * Entries are path prefixes, matched against the route path after the API
     * base (`/sync/`) or the full request path (`/api/v1/sync/`). They used to be
     * a str_contains() fragment match, so any path merely containing `/sync/`
     * (e.g. a page route `/pages/sync/notes`) skipped rate limiting entirely.
     */
    protected function isExcluded(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        $routePath = $this->apiRoutePath($path);

        $excluded = (array) $this->config->get('plugins.api.rate_limit.excluded_paths', ['/sync/', '/thumbnails/']);
        foreach ($excluded as $prefix) {
            if (!is_string($prefix) || $prefix === '') {
                continue;
            }
            if (($routePath !== null && str_starts_with($routePath, $prefix)) || str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The route path after `<site base>/<api route>/<version>`, e.g.
     * `/gpm/plugins/foo/fields`, or null when the request isn't under the API base.
     */
    protected function apiRoutePath(string $path): ?string
    {
        $base = '/' . trim((string) $this->config->get('plugins.api.route', '/api'), '/')
            . '/' . trim((string) $this->config->get('plugins.api.version_prefix', 'v1'), '/');

        $pos = strpos($path, $base . '/');
        if ($pos === false) {
            return null;
        }
        // Anything before the API base must be the site's own base path (a
        // subdirectory install), never part of the route.
        return substr($path, $pos + strlen($base));
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
