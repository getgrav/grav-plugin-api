<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Page\Media;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Serializers\MediaSerializer;
use Grav\Plugin\Api\Services\BlueprintPathResolver;
use Grav\Plugin\Api\Services\ThumbnailService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Read-only file browse endpoint for blueprint fields that declare a
 * `folder:` option (filepicker, mediapicker, …).
 *
 * Mirrors admin-classic's `taskGetFilesInFolder` semantics — `folder` can be
 * any Grav stream (`user://media`, `theme://images`, `account://`, …), a
 * `self@:subpath` token resolved against `scope`, or a plain relative path
 * confined under `user/`.
 *
 * The page-attached media case (`@self` / `self@` / empty) is intentionally
 * not handled here. The admin-next client already has the page's media via
 * `/pages/{route}/media`; rerouting it through this controller would force
 * a round-trip for the most common case. Calls with a `@self` literal get
 * a 422 sentinel so the client can fall back.
 */
class BlueprintFilesController extends AbstractApiController
{
    private ?BlueprintPathResolver $resolver = null;
    private ?MediaSerializer $serializer = null;

    /**
     * GET /blueprint-files?folder=<stream-or-token>&scope=<scope>&accept=<csv>&preview_images=1
     */
    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.read');

        $query = $request->getQueryParams();
        $folder = (string)($query['folder'] ?? '');
        $scope = (string)($query['scope'] ?? '');
        $acceptRaw = (string)($query['accept'] ?? '');

        if ($folder === '') {
            throw new ValidationException('folder is required.');
        }

        $resolver = $this->resolver();
        $resolver->assertSafe($folder);

        // `@self` / `self@` literals are page-media — the client has that already.
        if ($resolver->isSelfLiteral($folder)) {
            return ApiResponse::create([
                'error' => 'PAGE_MEDIA_ONLY',
                'message' => 'Use /pages/{route}/media for @self / self@ folders.',
            ], 422);
        }

        $abs = $resolver->resolve($folder, $scope, $this->getUser($request));

        $logicalFolder = $resolver->logicalParent($folder, $scope);

        // Resolve the file list (or empty list when the folder doesn't exist
        // yet — common for fresh installs targeting `theme://images` on a
        // theme that ships no images).
        $items = [];
        if (is_dir($abs)) {
            $accept = $this->parseAccept($acceptRaw);
            foreach ($this->iterateMedia($abs) as $name => $medium) {
                if (!$this->matchesAccept((string)$name, (string)($medium->get('mime') ?? ''), $accept)) {
                    continue;
                }
                $items[] = $this->serializer()->serialize($medium);
            }
        }

        // Use the paginated envelope (`{ data: [...], meta: { pagination, … } }`)
        // even though we don't actually paginate — it matches the shape the
        // admin-next client already expects from `/media` and avoids the
        // double-wrap that `ApiResponse::create` would impose on a hand-built
        // `{ data, meta }` payload.
        $total = count($items);
        return ApiResponse::paginated(
            $items,
            $total,
            1,
            max($total, 1),
            $this->getApiBaseUrl() . '/blueprint-files',
            200,
            [],
            [
                'folder' => $logicalFolder,
                'scope' => $scope !== '' ? $scope : null,
                'exists' => is_dir($abs),
            ],
        );
    }

    /**
     * Seam for tests. Yields `filename => Medium` over the given absolute
     * directory. Production path delegates to Grav's real Media class.
     */
    protected function iterateMedia(string $absoluteDir): iterable
    {
        return (new Media($absoluteDir))->all();
    }

    /**
     * Parse the comma-separated `accept` query param into an array of
     * patterns. Empty input → no filtering.
     */
    private function parseAccept(string $raw): array
    {
        if ($raw === '') return [];
        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn($s) => $s !== '');
        return array_values($parts);
    }

    /**
     * Mirror admin-classic's accept regex: extension form (`.pdf`, `*.jpg`)
     * matches the filename; mime form (`image/png`, `image/*`) matches the
     * Grav-detected mime. The `*` / `+` / `.` escaping mirrors
     * AdminBaseController::taskFilesUpload.
     *
     * @param string[] $patterns
     */
    private function matchesAccept(string $filename, string $mime, array $patterns): bool
    {
        if ($patterns === []) return true;
        foreach ($patterns as $type) {
            if ($type === '*') return true;
            $find = str_replace(['.', '*', '+'], ['\.', '.*', '\+'], $type);
            $isMime = str_contains($type, '/');
            if ($isMime) {
                if (preg_match('#' . $find . '$#', $mime)) return true;
            } else {
                if (preg_match('#' . $find . '$#', $filename)) return true;
            }
        }
        return false;
    }

    private function resolver(): BlueprintPathResolver
    {
        return $this->resolver ??= new BlueprintPathResolver($this->grav);
    }

    private function serializer(): MediaSerializer
    {
        if (!$this->serializer) {
            $cacheDir = $this->grav['locator']->findResource('cache://') . '/api/thumbnails';
            $thumb = new ThumbnailService($cacheDir);
            $this->serializer = new MediaSerializer($thumb, $this->getApiBaseUrl());
        }
        return $this->serializer;
    }
}
