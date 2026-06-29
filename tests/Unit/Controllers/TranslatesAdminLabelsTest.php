<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Controllers\TranslatesAdminLabels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslatesAdminLabels::class)]
class TranslatesAdminLabelsTest extends TestCase
{
    private string $admin2Languages;

    protected function setUp(): void
    {
        $this->admin2Languages = sys_get_temp_dir() . '/api-admin2-languages-' . bin2hex(random_bytes(4));
        mkdir($this->admin2Languages, 0777, true);

        foreach (['en-US', 'es-ES', 'es-MX', 'ru-RU'] as $locale) {
            file_put_contents($this->admin2Languages . '/' . $locale . '.yaml', "ICU: []\n");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->admin2Languages . '/*.yaml') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->admin2Languages)) {
            rmdir($this->admin2Languages);
        }
    }

    #[Test]
    public function regioned_admin_language_falls_back_to_short_plugin_language_before_english(): void
    {
        $controller = $this->controller();

        self::assertSame(['ru-RU', 'ru', 'en', 'en-US'], $controller->languageChain('ru-RU'));
    }

    #[Test]
    public function requested_region_stays_first_before_sibling_region_variants(): void
    {
        $controller = $this->controller();

        self::assertSame(['es-MX', 'es', 'es-ES', 'en', 'en-US'], $controller->languageChain('es-MX'));
    }

    #[Test]
    public function bare_admin_language_still_reaches_regioned_admin2_dictionary(): void
    {
        $controller = $this->controller();

        self::assertSame(['en', 'en-US'], $controller->languageChain('en'));
    }

    private function controller(): TranslatesAdminLabelsProbeController
    {
        $grav = new Grav();
        $grav['locator'] = new TranslatesAdminLabelsTestLocator($this->admin2Languages);

        return new TranslatesAdminLabelsProbeController($grav, new Config([]));
    }
}

final class TranslatesAdminLabelsProbeController extends AbstractApiController
{
    use TranslatesAdminLabels;

    /**
     * @return array<int, string>
     */
    public function languageChain(string $lang): array
    {
        return $this->expandLanguageChain($lang);
    }
}

final class TranslatesAdminLabelsTestLocator
{
    public function __construct(private readonly string $admin2Languages) {}

    public function findResource(string $uri, bool $absolute = true, bool $create = false): ?string
    {
        return $uri === 'plugin://admin2/languages' ? $this->admin2Languages : null;
    }
}
