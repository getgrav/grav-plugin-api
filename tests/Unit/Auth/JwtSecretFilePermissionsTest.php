<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Auth {

    use Grav\Plugin\Api\Tests\Unit\Auth\JwtSecretFilePermissionsTest;

    /**
     * Pass-through for the unqualified chmod() call in JwtAuthenticator::writeSecret().
     * PHP resolves it to this namespaced function first, which lets the test record
     * the mode the temp secret file was created with, before it is tightened.
     */
    if (!function_exists(__NAMESPACE__ . '\chmod')) {
        function chmod(string $filename, int $permissions): bool
        {
            JwtSecretFilePermissionsTest::$modesBeforeChmod[$filename] = fileperms($filename) & 0777;

            return \chmod($filename, $permissions);
        }
    }
}

namespace Grav\Plugin\Api\Tests\Unit\Auth {

    use Grav\Plugin\Api\Auth\JwtAuthenticator;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\Test;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;

    /**
     * The JWT secret temp file must never exist with group or other permissions,
     * not even between the write and the chmod().
     */
    #[CoversClass(JwtAuthenticator::class)]
    class JwtSecretFilePermissionsTest extends TestCase
    {
        /** @var array<string,int> */
        public static array $modesBeforeChmod = [];

        private string $dir;

        protected function setUp(): void
        {
            $this->dir = sys_get_temp_dir() . '/grav_api_jwt_secret_' . bin2hex(random_bytes(4));
            mkdir($this->dir);
            self::$modesBeforeChmod = [];
        }

        protected function tearDown(): void
        {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
        }

        #[Test]
        public function temp_secret_file_is_private_from_creation(): void
        {
            $path = $this->dir . '/api-private.php';
            $authenticator = (new ReflectionClass(JwtAuthenticator::class))->newInstanceWithoutConstructor();
            $write = (new ReflectionClass(JwtAuthenticator::class))->getMethod('writeSecret');

            $previous = umask(0022);
            try {
                self::assertTrue($write->invoke($authenticator, $path, str_repeat('d', 64)));
                self::assertSame(0022, umask(), 'the caller umask is restored after the write');
            } finally {
                umask($previous);
            }

            self::assertArrayHasKey($path . '.tmp', self::$modesBeforeChmod);
            self::assertSame(0600, self::$modesBeforeChmod[$path . '.tmp'], 'the temp secret file must be created 0600, not tightened afterwards');
            self::assertSame(0600, fileperms($path) & 0777);
            self::assertSame(str_repeat('d', 64), include $path);
        }
    }
}
