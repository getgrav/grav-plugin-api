<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Response;

use Grav\Framework\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Gzips large JSON responses on their way out of the API router.
 *
 * Grav's own `system.cache.gzip` only sets headers and never compresses, and
 * most hosts don't compress PHP output either, so a 700 KB translations
 * dictionary or a 500-row page list went out raw. Level 1 costs a few
 * milliseconds and cuts those bodies to a fifth or less.
 *
 * Only JSON bodies of a known size over the threshold are touched. Anything
 * already encoded, downloads (Content-Disposition), empty statuses (204/304),
 * HEAD requests and hosts that compress PHP output themselves
 * (zlib.output_compression) pass through unchanged. Apache's mod_deflate skips
 * a body that already carries Content-Encoding, so it never double-compresses.
 */
class ResponseCompressor
{
    public const MIN_BYTES = 8192;
    private const LEVEL = 1;

    /**
     * @param string|bool|null $mode The `plugins.api.compression` setting: `auto` (default) or `off`.
     * @param bool $shutdownRewritesEncoding Whether Grav's shutdown handler will send its own
     *        Content-Encoding header later in this request (see ApiRouter::compressResponse()).
     */
    public function __construct(
        private readonly string|bool|null $mode = 'auto',
        private readonly bool $shutdownRewritesEncoding = false,
        private readonly int $minBytes = self::MIN_BYTES,
    ) {}

    public function compress(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->enabled() || !$this->isJson($response)) {
            return $response;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status === 204 || $status === 304) {
            return $response;
        }

        // The representation now depends on Accept-Encoding, even when this
        // particular response goes out uncompressed.
        $response = $this->addVary($response);

        if (strtoupper($request->getMethod()) === 'HEAD'
            || $response->hasHeader('Content-Encoding')
            || $response->hasHeader('Content-Disposition')
            || $this->shutdownRewritesEncoding
            || $this->outputIsCompressedElsewhere()
            || !$this->acceptsGzip($request->getHeaderLine('Accept-Encoding'))
        ) {
            return $response;
        }

        $body = $response->getBody();
        $size = $body->getSize();
        if ($size === null || $size < $this->minBytes) {
            return $response;
        }

        // __toString() seeks to the start before reading (PSR-7).
        $compressed = gzencode((string) $body, self::LEVEL);
        if ($compressed === false) {
            return $response;
        }

        $headers = $response->getHeaders();
        $headers['Content-Encoding'] = ['gzip'];
        if (isset($headers['Content-Length'])) {
            $headers['Content-Length'] = [(string) strlen($compressed)];
        }

        return new Response($status, $headers, $compressed, $response->getProtocolVersion(), $response->getReasonPhrase());
    }

    /**
     * Whether an Accept-Encoding header allows gzip, honouring `q=0` and `*`.
     */
    public function acceptsGzip(string $acceptEncoding): bool
    {
        if (trim($acceptEncoding) === '') {
            return false;
        }

        $gzip = null;
        $wildcard = null;
        foreach (explode(',', $acceptEncoding) as $part) {
            $params = explode(';', trim($part));
            $coding = strtolower(trim((string) array_shift($params)));
            $q = 1.0;
            foreach ($params as $param) {
                $param = trim($param);
                if (stripos($param, 'q=') === 0) {
                    $q = (float) substr($param, 2);
                }
            }

            if ($coding === 'gzip' || $coding === 'x-gzip') {
                $gzip = max($gzip ?? 0.0, $q);
            } elseif ($coding === '*') {
                $wildcard = $q;
            }
        }

        return ($gzip ?? $wildcard ?? 0.0) > 0.0;
    }

    private function enabled(): bool
    {
        if ($this->mode === false || !function_exists('gzencode')) {
            return false;
        }

        return !is_string($this->mode) || strtolower($this->mode) !== 'off';
    }

    private function isJson(ResponseInterface $response): bool
    {
        $type = strtolower($response->getHeaderLine('Content-Type'));

        return str_starts_with($type, 'application/json') || str_contains($type, '+json');
    }

    private function addVary(ResponseInterface $response): ResponseInterface
    {
        $vary = strtolower($response->getHeaderLine('Vary'));
        if (str_contains($vary, 'accept-encoding') || trim($vary) === '*') {
            return $response;
        }

        return $response->withAddedHeader('Vary', 'Accept-Encoding');
    }

    protected function outputIsCompressedElsewhere(): bool
    {
        // On, 1 or a buffer size all switch it on.
        $zlib = strtolower(trim((string) ini_get('zlib.output_compression')));
        if (!in_array($zlib, ['', '0', 'off', 'false', 'no'], true)) {
            return true;
        }

        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Encoding:') === 0) {
                return true;
            }
        }

        return false;
    }
}
