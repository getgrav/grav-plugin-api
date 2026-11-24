<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\UsersController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Serializers\UserSerializer;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The avatar `path` stored in an account file is data. Removing the avatar
 * deletes only a file inside the avatars folder, and an avatar URL is only
 * built for a file inside the accounts folder. Changing your own avatar needs
 * api.access, like every other self-service route.
 */
#[CoversClass(UsersController::class)]
#[CoversClass(UserSerializer::class)]
class UsersControllerAvatarPathTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = realpath(sys_get_temp_dir()) . '/grav_api_avatar_path_' . uniqid();
        mkdir($this->tempDir . '/cache/api/thumbnails', 0775, true);
        mkdir($this->tempDir . '/accounts/avatars', 0775, true);
    }

    protected function tearDown(): void
    {
        \Grav\Common\Grav::resetInstance();
        $this->rmrf($this->tempDir);
    }

    #[Test]
    public function removing_an_avatar_never_deletes_a_file_outside_the_avatars_folder(): void
    {
        $account = $this->tempDir . '/accounts/other.yaml';
        file_put_contents($account, "state: enabled\n");
        $avatar = $this->tempDir . '/accounts/avatars/alice.png';
        file_put_contents($avatar, 'png');

        $alice = TestHelper::createMockUser('alice', [
            'state' => 'enabled',
            'access' => ['api' => ['access' => true]],
            'avatar' => [
                'a' => ['name' => 'other.yaml', 'path' => $this->relativeToRoot($account)],
                'b' => ['name' => 'x', 'path' => $this->relativeToRoot($this->tempDir . '/accounts/avatars/../other.yaml')],
                'c' => ['name' => 'alice.png', 'path' => $this->relativeToRoot($avatar)],
                'd' => ['name' => 'bad', 'path' => ['not', 'a', 'string']],
            ],
        ]);

        $this->controller($alice)->deleteAvatar($this->request($alice, 'alice'));

        self::assertFileExists($account);
        self::assertFileDoesNotExist($avatar);
        self::assertSame([], $alice->get('avatar'));
    }

    #[Test]
    public function removing_your_own_avatar_needs_api_access(): void
    {
        $avatar = $this->tempDir . '/accounts/avatars/bob.png';
        file_put_contents($avatar, 'png');
        $bob = TestHelper::createMockUser('bob', [
            'state' => 'enabled',
            'access' => ['site' => ['login' => true]],
            'avatar' => ['a' => ['name' => 'bob.png', 'path' => $this->relativeToRoot($avatar)]],
        ]);

        try {
            $this->controller($bob)->deleteAvatar($this->request($bob, 'bob'));
            self::fail('Expected a 403 without api.access.');
        } catch (ForbiddenException) {
        }

        self::assertFileExists($avatar);
    }

    #[Test]
    public function uploading_your_own_avatar_needs_api_access(): void
    {
        $bob = TestHelper::createMockUser('bob', ['state' => 'enabled', 'access' => ['site' => ['login' => true]]]);

        $this->expectException(ForbiddenException::class);
        $this->controller($bob)->uploadAvatar($this->request($bob, 'bob', 'POST'));
    }

    #[Test]
    public function an_avatar_url_is_only_built_for_a_file_in_the_accounts_folder(): void
    {
        $outside = $this->tempDir . '/secret.png';
        file_put_contents($outside, 'png');
        $inside = $this->tempDir . '/accounts/avatars/carol.png';
        file_put_contents($inside, 'png');
        $this->controller();

        self::assertNull(UserSerializer::storedAvatarFile($this->relativeToRoot($outside)));
        self::assertNull(UserSerializer::storedAvatarFile($this->relativeToRoot($this->tempDir . '/accounts/../secret.png')));
        self::assertNull(UserSerializer::storedAvatarFile(['array']));
        self::assertSame($inside, UserSerializer::storedAvatarFile($this->relativeToRoot($inside)));
        self::assertSame($inside, UserSerializer::storedAvatarFile($this->relativeToRoot($inside), 'account://avatars'));
        self::assertNull(UserSerializer::storedAvatarFile($this->relativeToRoot($this->tempDir . '/accounts/x.png'), 'account://avatars'));

        $carol = TestHelper::createMockUser('carol', [
            'avatar' => ['a' => ['name' => 'secret.png', 'path' => $this->relativeToRoot($outside)]],
        ]);
        self::assertNull(UserSerializer::resolveAvatarUrl($carol));
    }

    private function controller(UserInterface ...$users): UsersController
    {
        $config = new Config([
            'plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']],
        ]);

        $base = $this->tempDir;
        $locator = new class ($base) {
            public function __construct(private string $base) {}
            public function findResource(string $uri, bool $absolute = false, bool $first = false): string|false
            {
                foreach (['cache://' => '/cache', 'account://' => '/accounts'] as $scheme => $dir) {
                    if (str_starts_with($uri, $scheme)) {
                        $path = rtrim($this->base . $dir . '/' . substr($uri, strlen($scheme)), '/');
                        return file_exists($path) ? $path : false;
                    }
                }
                return false;
            }
        };

        $map = [];
        foreach ($users as $user) {
            $map[$user->username] = $user;
        }

        TestHelper::createMockGrav([
            'config' => $config,
            'locator' => $locator,
            'accounts' => TestHelper::createMockAccounts($map),
            'permissions' => new Permissions(),
        ]);

        return new UsersController(\Grav\Common\Grav::instance(), $config);
    }

    private function request(UserInterface $caller, string $target, string $method = 'DELETE'): ServerRequestInterface
    {
        return TestHelper::createMockRequest(
            method: $method,
            path: '/api/v1/users/' . $target . '/avatar',
            attributes: ['api_user' => $caller, 'route_params' => ['username' => $target]],
        );
    }

    /** The stored form of a path: relative to the Grav root. */
    private function relativeToRoot(string $absolute): string
    {
        $root = realpath(GRAV_ROOT);
        return str_repeat('../', substr_count(trim($root, '/'), '/') + 1) . ltrim($absolute, '/');
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmrf($path) : unlink($path);
        }
        rmdir($dir);
    }
}
