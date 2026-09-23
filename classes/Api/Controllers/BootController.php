<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * GET /admin-next/boot — everything Admin Next fetches when it starts, in one
 * request instead of nine.
 *
 * Each part comes from the same method its own endpoint uses, with the same
 * permission check, so `data.<part>` is exactly the `data` of that endpoint. A
 * part the caller may not read, or one that fails, is left out of `data` and
 * reported in `errors` with the status and title its endpoint would have sent;
 * the rest of the response is unaffected.
 *
 * After a cache clear the nine separate requests each rebuilt the same cold
 * caches at the same time; here they are rebuilt once.
 */
class BootController extends AbstractApiController
{
    /**
     * Part name => [controller, data method]. The data method is the one the
     * part's own endpoint wraps.
     */
    private const PARTS = [
        'preferences' => [PreferencesController::class, 'showData'],        // GET /admin-next/preferences
        'me' => [AuthController::class, 'meData'],                            // GET /me
        'menubar' => [MenubarController::class, 'itemsData'],                 // GET /menubar/items
        'sidebar' => [SidebarController::class, 'itemsData'],                 // GET /sidebar/items
        'floating_widgets' => [FloatingWidgetController::class, 'itemsData'], // GET /floating-widgets
        'context_panels' => [ContextPanelController::class, 'itemsData'],     // GET /context-panels
        'custom_fields' => [GpmController::class, 'allCustomFieldsData'],     // GET /custom-fields
        'languages' => [PagesController::class, 'siteLanguagesData'],         // GET /languages
    ];

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $data = [];
        $errors = [];

        foreach (self::PARTS as $part => [$class, $method]) {
            $this->collect($part, $data, $errors, fn () => $this->controller($class)->$method($request));
        }

        // The checksum /translations/{lang} would send as its ETag, so a client
        // holding that dictionary can skip the request. `?lang=` picks the
        // language; otherwise the user's admin language, then the site default,
        // resolved exactly as /translations resolves its path parameter.
        $this->collect('translations', $data, $errors, function () use ($request, $data): array {
            $requested = $request->getQueryParams()['lang'] ?? null;
            if (!is_string($requested) || $requested === '') {
                $requested = $data['preferences']['effective']['adminLanguage'] ?? null;
            }

            $system = $this->controller(SystemController::class);
            $lang = $system->resolveTranslationsLanguage($requested);

            return ['lang' => $lang, 'checksum' => $system->translationsChecksum($lang)];
        });

        return ApiResponse::parts($data, $errors);
    }

    /**
     * @template T of AbstractApiController
     * @param class-string<T> $class
     * @return T
     */
    protected function controller(string $class): AbstractApiController
    {
        return new $class($this->grav, $this->config);
    }

    /**
     * Run one part, storing its result in $data or its failure in $errors.
     *
     * @param array<string, mixed> $data
     * @param array<string, array{status: int, title: string}> $errors
     */
    private function collect(string $part, array &$data, array &$errors, callable $build): void
    {
        try {
            $data[$part] = $build();
        } catch (ApiException $e) {
            $errors[$part] = ['status' => $e->getStatusCode(), 'title' => $e->getErrorTitle()];
        } catch (Throwable $e) {
            try {
                $this->grav['log']->error('API boot: part "' . $part . '" failed: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            } catch (Throwable) {
                // Logging must never be the reason the other parts are lost.
            }
            $errors[$part] = ['status' => 500, 'title' => 'Internal Server Error'];
        }
    }
}
