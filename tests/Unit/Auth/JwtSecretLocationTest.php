<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Auth;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Auth\JwtAuthenticator;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The signing secret lives once per site, in base user/config. `config://`
 * resolves to an environment overlay first when one exists, and keeping the
 * secret there gave every new user/env/<host>/ folder a fresh secret, which
 * signed everyone out.
 */
class JwtSecretLocationTest extends TestCase
{
    private string $root;
    private string $base;
    private string $overlay;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/api-secret-' . bin2hex(random_bytes(4));
        $this->base = $this->root . '/user/config';
        $this->overlay = $this->root . '/user/env/example.com/config';
        mkdir($this->base . '/plugins', 0775, true);
        mkdir($this->overlay . '/plugins', 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_new_environment_folder_does_not_get_a_secret_of_its_own(): void
    {
        $secret = $this->secret();

        self::assertFileExists($this->base . '/plugins/api-private.php');
        self::assertFileDoesNotExist($this->overlay . '/plugins/api-private.php');
        self::assertSame($secret, $this->secret(), 'the next request reads the same secret');
    }

    #[Test]
    public function an_existing_base_secret_is_used_when_an_environment_folder_appears(): void
    {
        $this->writeSecretFile($this->base . '/plugins/api-private.php', 'base-secret');

        self::assertSame('base-secret', $this->secret());
        self::assertFileDoesNotExist($this->overlay . '/plugins/api-private.php');
    }

    #[Test]
    public function a_secret_an_earlier_version_left_in_the_overlay_moves_to_base(): void
    {
        $this->writeSecretFile($this->overlay . '/plugins/api-private.php', 'overlay-secret');

        self::assertSame('overlay-secret', $this->secret(), 'tokens it signed stay valid');
        self::assertFileExists($this->base . '/plugins/api-private.php');
        self::assertFileDoesNotExist($this->overlay . '/plugins/api-private.php');
        self::assertSame('overlay-secret', $this->secret());
    }

    #[Test]
    public function the_base_secret_wins_over_an_overlay_copy(): void
    {
        $this->writeSecretFile($this->base . '/plugins/api-private.php', 'base-secret');
        $this->writeSecretFile($this->overlay . '/plugins/api-private.php', 'overlay-secret');

        self::assertSame('base-secret', $this->secret());
    }

    private function writeSecretFile(string $path, string $value): void
    {
        file_put_contents($path, "<?php\nreturn " . var_export($value, true) . ";\n");
    }

    /** A fresh authenticator per call, like a new request. */
    private function secret(): string
    {
        $base = $this->base;
        $overlay = $this->overlay;
        $locator = new class ($base, $overlay) {
            public function __construct(private string $base, private string $overlay) {}

            public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false
            {
                return match ($uri) {
                    'user://config' => $this->base,
                    'config://' => $this->overlay,
                    default => false,
                };
            }

            /** @return list<string> */
            public function findResources(string $uri, bool $absolute = true, bool $all = false): array
            {
                return $uri === 'config://' ? [$this->overlay, $this->base] : [];
            }
        };

        $grav = TestHelper::createMockGrav(['locator' => $locator]);
        $config = TestHelper::createMockConfig(['plugins' => ['api' => []]]);

        $authenticator = new class ($grav, $config) extends JwtAuthenticator {
            public function secret(): string
            {
                return $this->getSecret();
            }
        };

        return $authenticator->secret();
    }
}
