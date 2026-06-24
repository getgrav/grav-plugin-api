<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Serializers;

use Grav\Common\Config\Config;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Serializers\UserSerializer;
use Grav\Plugin\Api\Services\ThumbnailService;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * {@see UserSerializer::resolveAvatarUrl()} must resolve avatars through the user
 * abstraction when available. Legacy Flex users store `avatar.path` as a filename
 * inside their media folder, so joining it against GRAV_ROOT yields null even
 * though {@see UserInterface::getAvatarImage()} knows the real filesystem path.
 */
#[CoversClass(UserSerializer::class)]
class UserSerializerTest extends TestCase
{
    private string $tempDir;

    /** @var list<string> paths removed in tearDown */
    private array $cleanupPaths = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_user_serializer_' . uniqid();
        mkdir($this->tempDir . '/cache/api/thumbnails', 0775, true);
        mkdir($this->tempDir . '/accounts', 0775, true);

        TestHelper::createMockGrav([
            'config' => $this->apiConfig(),
            'locator' => new UserSerializerTestLocator($this->tempDir),
        ]);
    }

    protected function tearDown(): void
    {
        \Grav\Common\Grav::resetInstance();

        $this->rmrf($this->tempDir);

        foreach ($this->cleanupPaths as $path) {
            $this->rmrf($path);
        }
        $this->cleanupPaths = [];
    }

    #[Test]
    public function flex_user_resolves_avatar_via_get_avatar_image_when_metadata_path_is_filename_only(): void
    {
        $username = 'alice';
        $accountsDir = $this->tempDir . '/accounts/' . $username;
        mkdir($accountsDir, 0775, true);

        $avatarPath = $accountsDir . '/portrait.png';
        file_put_contents($avatarPath, $this->minimalPng());

        // Flex storage keeps only the basename in metadata — the bug case.
        $user = $this->flexUser($username, [
            'avatar' => [
                'portrait.png' => [
                    'name' => 'portrait.png',
                    'type' => 'image/png',
                    'size' => filesize($avatarPath),
                    'path' => 'portrait.png',
                ],
            ],
        ], $avatarPath);

        $url = UserSerializer::resolveAvatarUrl($user);

        self::assertNotNull($url);
        self::assertSame($this->expectedThumbnailUrl($avatarPath), $url);
        self::assertFileExists($this->thumbnailCachePath($avatarPath));

        // Metadata-only resolution must still fail for this layout.
        self::assertFileDoesNotExist(GRAV_ROOT . '/portrait.png');
        self::assertNull(UserSerializer::resolveAvatarUrl($this->plainUser($username, $user->get('avatar'))));
    }

    #[Test]
    public function regular_user_resolves_avatar_from_grav_root_relative_metadata(): void
    {
        $relativePath = 'user/accounts/grav-api-serializer-' . uniqid('', true) . '/member.png';
        $absolutePath = GRAV_ROOT . '/' . $relativePath;
        mkdir(dirname($absolutePath), 0775, true);
        file_put_contents($absolutePath, $this->minimalPng());
        $this->cleanupPaths[] = dirname($absolutePath);

        $user = $this->plainUser('member', [
            'member.png' => [
                'name' => 'member.png',
                'type' => 'image/png',
                'size' => filesize($absolutePath),
                'path' => $relativePath,
            ],
        ]);

        $url = UserSerializer::resolveAvatarUrl($user);

        self::assertNotNull($url);
        self::assertSame($this->expectedThumbnailUrl($absolutePath), $url);
        self::assertFileExists($this->thumbnailCachePath($absolutePath));
    }

    #[Test]
    public function user_without_avatar_returns_null(): void
    {
        $user = $this->plainUser('nobody', []);

        self::assertNull(UserSerializer::resolveAvatarUrl($user));
    }

    #[Test]
    public function serialize_includes_resolved_avatar_url(): void
    {
        $username = 'bob';
        $accountsDir = $this->tempDir . '/accounts/' . $username;
        mkdir($accountsDir, 0775, true);

        $avatarPath = $accountsDir . '/avatar.png';
        file_put_contents($avatarPath, $this->minimalPng());

        $user = $this->flexUser($username, [
            'email' => 'bob@example.test',
            'fullname' => 'Bob Example',
            'avatar' => [
                'avatar.png' => [
                    'name' => 'avatar.png',
                    'type' => 'image/png',
                    'size' => filesize($avatarPath),
                    'path' => 'avatar.png',
                ],
            ],
        ], $avatarPath);

        $payload = (new UserSerializer())->serialize($user);

        self::assertSame($this->expectedThumbnailUrl($avatarPath), $payload['avatar_url']);
        self::assertSame('bob@example.test', $payload['email']);
        self::assertSame('Bob Example', $payload['fullname']);
    }

    private function apiConfig(): Config
    {
        return new Config([
            'plugins' => [
                'api' => [
                    'route' => '/api',
                    'version_prefix' => 'v1',
                ],
            ],
        ]);
    }

    /**
     * Duck-type a Flex-backed user with {@see getAvatarImage()}.
     */
    private function flexUser(string $username, array $data, ?string $avatarAbsolutePath): UserInterface
    {
        return new class ($username, $data, $avatarAbsolutePath) implements UserInterface {
            public readonly string $username;

            public function __construct(
                string $username,
                private array $data,
                private readonly ?string $avatarAbsolutePath,
            ) {
                $this->username = $username;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function save(): void
            {
            }

            public function exists(): bool
            {
                return true;
            }

            public function getAvatarImage(): ?object
            {
                if ($this->avatarAbsolutePath === null) {
                    return null;
                }

                $path = $this->avatarAbsolutePath;

                return new class ($path) {
                    public function __construct(private readonly string $path)
                    {
                    }

                    /**
                     * Mirror Medium::get('filepath'), which is the accessor
                     * available on the ImageMedium returned by real users.
                     */
                    public function get(string $key, mixed $default = null): mixed
                    {
                        return $key === 'filepath' ? $this->path : $default;
                    }

                    /**
                     * Medium has no getPath() method. Unknown calls are media
                     * actions and return the Medium itself through __call().
                     * Keeping that behaviour makes this test fail if the
                     * serializer regresses to getPath().
                     */
                    public function __call(string $method, array $arguments): static
                    {
                        return $this;
                    }
                };
            }
        };
    }

    /** User whose avatar medium is absent — exercises metadata fallback only. */
    private function plainUser(string $username, array $avatarMetadata): UserInterface
    {
        return $this->flexUser($username, ['avatar' => $avatarMetadata], null);
    }

    private function expectedThumbnailUrl(string $sourcePath): string
    {
        $cacheDir = $this->tempDir . '/cache/api/thumbnails';
        $thumbService = new ThumbnailService($cacheDir, 200);
        $filename = $thumbService->getThumbnailFilename($sourcePath);

        self::assertNotNull($filename, 'fixture image must be thumbnail-eligible');

        return '/api/v1/thumbnails/' . $filename;
    }

    private function thumbnailCachePath(string $sourcePath): string
    {
        $cacheDir = $this->tempDir . '/cache/api/thumbnails';
        $thumbService = new ThumbnailService($cacheDir, 200);
        $filename = $thumbService->getThumbnailFilename($sourcePath);

        self::assertNotNull($filename);

        return $cacheDir . '/' . $filename;
    }

    /** Valid 1×1 PNG — enough for mime detection and GD thumbnail generation. */
    private function minimalPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ) ?: '';
    }

    private function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->rmrf($path . '/' . $item);
        }
        @rmdir($path);
    }
}

final class UserSerializerTestLocator
{
    public function __construct(private readonly string $base)
    {
    }

    public function findResource(string $uri, bool $absolute = false, bool $first = false): string|false
    {
        if (str_starts_with($uri, 'cache://')) {
            return rtrim($this->base . '/cache/' . ltrim(substr($uri, strlen('cache://')), '/'), '/');
        }

        return false;
    }
}
