<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\SystemController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GET /translations/{lang} is public and caches one dictionary per resolved
 * code. A code no source ships a file for only ever produces the English
 * backfill, so it must resolve to the default rather than get a cache entry of
 * its own: otherwise every made-up code an anonymous visitor sends is another
 * copy of the full dictionary on disk for seven days.
 */
class TranslationsLanguageResolveTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    private function controller(): SystemController
    {
        $config = new Config([
            'plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']],
        ]);

        // The source inventory TranslationSourceIndex reads from the cache:
        // core and plugins ship `en`, `fr` and `pt`, admin2 ships `en-US`.
        $cache = new class {
            public function fetch(string $key): mixed
            {
                return str_starts_with($key, 'api-i18n-meta-')
                    ? ['providers' => [], 'files' => ['en' => [], 'en-US' => [], 'fr' => [], 'pt' => []]]
                    : false;
            }

            public function save(string $key, mixed $value, int $ttl = 0): bool
            {
                return true;
            }
        };
        $locator = new class {
            public function findResources(string $uri): array
            {
                return [];
            }
        };
        $language = new class {
            public function getDefault(): string
            {
                return 'en';
            }
        };

        $grav = TestHelper::createMockGrav([
            'config' => $config,
            'cache' => $cache,
            'locator' => $locator,
            'language' => $language,
        ]);

        return new SystemController($grav, $config);
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function cases(): array
    {
        return [
            'shipped bare code'                 => ['fr', 'fr-FR'],
            'shipped region code'               => ['en-US', 'en-US'],
            'region of a shipped bare code'     => ['pt-BR', 'pt-BR'],
            'well-formed code nothing ships'    => ['qza-QZ', 'en-US'],
            'another unshipped code'            => ['xyz', 'en-US'],
            'malformed code'                    => ['../etc', 'en-US'],
            'missing code'                      => [null, 'en-US'],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function only_shipped_languages_get_their_own_dictionary(mixed $requested, string $expected): void
    {
        self::assertSame($expected, $this->controller()->resolveTranslationsLanguage($requested));
    }
}
