<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Controllers\SystemController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /admin/languages feeds the Admin Language dropdown every Admin2 user
 * sees in their own settings, so it needs only api.access, like the
 * preferences endpoint that saves the choice. Gating it on api.system.read
 * left everyone without that permission with an English-only dropdown
 * (getgrav/grav-plugin-api#73).
 */
#[CoversClass(SystemController::class)]
class SystemControllerAdminLanguagesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/api-admin-langs-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        foreach (['en-US', 'de-DE', 'fr'] as $code) {
            file_put_contents($this->dir . '/' . $code . '.yaml', "{}\n");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        Grav::resetInstance();
    }

    #[Test]
    public function a_user_with_only_api_access_gets_every_admin_language(): void
    {
        $response = $this->adminLanguages(
            TestHelper::createMockUser('editor', ['access' => ['api' => ['access' => true], 'site' => ['login' => true]]]),
        );

        self::assertSame(200, $response->getStatusCode());
        $codes = array_column($this->body($response)['data']['languages'], 'code');
        sort($codes);
        self::assertSame(['de-DE', 'en-US', 'fr'], $codes);
    }

    #[Test]
    public function a_key_scoped_to_api_access_is_enough(): void
    {
        $response = $this->adminLanguages(
            TestHelper::createMockUser('root', ['access' => ['api' => ['super' => true]]]),
            ['api.access'],
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function an_account_without_api_access_is_refused(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->adminLanguages(
            TestHelper::createMockUser('frontend', ['access' => ['site' => ['login' => true]]]),
        );
    }

    /**
     * @param array<int, string> $scopes
     */
    private function adminLanguages(UserInterface $user, array $scopes = []): ResponseInterface
    {
        $dir = $this->dir;
        $grav = TestHelper::createMockGrav([
            'locator' => new class ($dir) {
                public function __construct(private readonly string $dir) {}

                public function findResource(string $uri, bool $absolute = true): string|false
                {
                    return $uri === 'plugins://admin2/languages' ? $this->dir : false;
                }
            },
        ]);

        $attributes = ['api_user' => $user];
        if ($scopes !== []) {
            $attributes['api_key_scopes'] = $scopes;
        }

        return (new SystemController($grav, new Config()))->adminLanguages(
            TestHelper::createMockRequest('GET', '/admin/languages', attributes: $attributes),
        );
    }

    /** @return array<string, mixed> */
    private function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
