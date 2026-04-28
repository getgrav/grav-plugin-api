<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\BlueprintUploadController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Regression tests for GHSA-6xx2-m8wv-756h.
 *
 * The advisory describes a privilege escalation where a low-priv API user
 * holding `api.media.write` could POST to /blueprint-upload with
 * `destination=self@:` + `scope=users/anything` + a YAML file, drop
 * `pwned.yaml` straight into `user/accounts/`, and log in as a brand-new
 * super-admin. These tests pin the four layers that close the chain:
 *
 *   1. The `users/<x>` scope is gated to self-or-admin (no cross-user writes).
 *   2. `user/accounts/` accepts image extensions only (avatars).
 *   3. `user/config/` and `user/env/` reject every blueprint upload.
 *   4. YAML/JSON/Twig and similar config formats are denied at any target.
 */
#[CoversClass(BlueprintUploadController::class)]
class BlueprintUploadPrivescTest extends TestCase
{
    private string $tempDir;
    private string $userRoot;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_blueprint_upload_test_' . uniqid();
        $this->userRoot = $this->tempDir . '/user';
        @mkdir($this->userRoot . '/accounts', 0775, true);
        @mkdir($this->userRoot . '/config', 0775, true);
        @mkdir($this->userRoot . '/env/dev/config', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tempDir);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmrf($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function buildController(): BlueprintUploadController
    {
        $userRoot = $this->userRoot;
        $config = new Config([
            'plugins' => ['api' => [
                'route' => '/api',
                'version_prefix' => 'v1',
                'pagination' => ['default_per_page' => 20, 'max_per_page' => 100],
            ]],
            'security' => [
                // A representative subset of Grav's default dangerous list. The
                // GHSA-6xx2-m8wv-756h PoC relies on `.yaml` *not* being here —
                // tests pin the per-endpoint denylist that closes that gap.
                'uploads_dangerous_extensions' => ['php', 'phar', 'phtml', 'js', 'exe', 'html', 'htm'],
            ],
        ]);

        $locator = new class ($userRoot) {
            public function __construct(private string $userRoot) {}
            public function findResource(string $uri, bool $absolute = false, bool $createDir = false): mixed
            {
                return match (true) {
                    $uri === 'user://'                  => $this->userRoot,
                    $uri === 'account://'               => $this->userRoot . '/accounts',
                    str_starts_with($uri, 'user://')    => $this->userRoot . '/' . substr($uri, 7),
                    default                             => false,
                };
            }
            public function isStream(string $uri): bool
            {
                return (bool) preg_match('#^[a-z][a-z0-9+.\-]*://#i', $uri);
            }
        };

        TestHelper::createMockGrav([
            'config'      => $config,
            'locator'     => $locator,
            'permissions' => new Permissions(),
            'uri'         => new class { public function rootUrl(): string { return ''; } },
        ]);

        return new BlueprintUploadController(\Grav\Common\Grav::instance(), $config);
    }

    /**
     * @param array<string, string> $body
     * @param array<string, UploadedFileInterface> $files
     */
    private function makeRequest(
        UserInterface $caller,
        array $body,
        array $files = [],
    ): ServerRequestInterface {
        $request = TestHelper::createMockRequest(
            method: 'POST',
            path: '/api/v1/blueprint-upload',
            attributes: ['api_user' => $caller, 'json_body' => $body],
        );
        // PSR-7 ServerRequestInterface expects getParsedBody() and
        // getUploadedFiles() to drive the upload path; the TestHelper
        // request stub returns null/[] for these. Wrap it.
        return new class ($request, $body, $files) implements ServerRequestInterface {
            public function __construct(
                private readonly ServerRequestInterface $inner,
                private readonly array $parsedBody,
                private readonly array $uploadedFiles,
            ) {}
            public function getParsedBody(): array { return $this->parsedBody; }
            public function getUploadedFiles(): array { return $this->uploadedFiles; }
            public function getMethod(): string { return $this->inner->getMethod(); }
            public function getUri(): \Psr\Http\Message\UriInterface { return $this->inner->getUri(); }
            public function getBody(): \Psr\Http\Message\StreamInterface { return $this->inner->getBody(); }
            public function getQueryParams(): array { return $this->inner->getQueryParams(); }
            public function getServerParams(): array { return $this->inner->getServerParams(); }
            public function getHeaderLine(string $n): string { return $this->inner->getHeaderLine($n); }
            public function getHeader(string $n): array { return $this->inner->getHeader($n); }
            public function hasHeader(string $n): bool { return $this->inner->hasHeader($n); }
            public function getHeaders(): array { return $this->inner->getHeaders(); }
            public function getAttribute(string $n, mixed $d = null): mixed { return $this->inner->getAttribute($n, $d); }
            public function withAttribute(string $n, mixed $v): static { return clone $this; }
            public function getRequestTarget(): string { return $this->inner->getRequestTarget(); }
            public function withRequestTarget(string $r): static { return clone $this; }
            public function withMethod(string $m): static { return clone $this; }
            public function withUri(\Psr\Http\Message\UriInterface $u, bool $p = false): static { return clone $this; }
            public function getProtocolVersion(): string { return $this->inner->getProtocolVersion(); }
            public function withProtocolVersion(string $v): static { return clone $this; }
            public function withHeader(string $n, $v): static { return clone $this; }
            public function withAddedHeader(string $n, $v): static { return clone $this; }
            public function withoutHeader(string $n): static { return clone $this; }
            public function withBody(\Psr\Http\Message\StreamInterface $b): static { return clone $this; }
            public function getCookieParams(): array { return []; }
            public function withCookieParams(array $c): static { return clone $this; }
            public function withQueryParams(array $q): static { return clone $this; }
            public function withUploadedFiles(array $u): static { return clone $this; }
            public function withParsedBody($d): static { return clone $this; }
            public function getAttributes(): array { return $this->inner->getAttributes(); }
            public function withoutAttribute(string $n): static { return clone $this; }
        };
    }

    /**
     * Build a stub UploadedFile that records moveTo() destinations to a sink
     * we can inspect from tests.
     */
    private function makeUpload(string $clientFilename, string $content = 'fake'): UploadedFileInterface
    {
        return new class ($clientFilename, $content) implements UploadedFileInterface {
            public ?string $movedTo = null;
            public function __construct(
                private readonly string $name,
                private readonly string $body,
            ) {}
            public function getStream(): \Psr\Http\Message\StreamInterface
            { throw new \RuntimeException('not used'); }
            public function moveTo(string $targetPath): void
            {
                $this->movedTo = $targetPath;
                file_put_contents($targetPath, $this->body);
            }
            public function getSize(): ?int { return strlen($this->body); }
            public function getError(): int { return UPLOAD_ERR_OK; }
            public function getClientFilename(): ?string { return $this->name; }
            public function getClientMediaType(): ?string { return 'application/octet-stream'; }
        };
    }

    // ------------------------------------------------------------------
    // Layer 1: `users/<x>` scope is gated to self-or-admin.
    // ------------------------------------------------------------------

    #[Test]
    public function ghsa_6xx2_self_at_users_other_is_forbidden_for_low_priv_caller(): void
    {
        // The exact PoC shape: caller has only api.media.write, and aims a
        // `self@:` write at `users/<arbitrary>` to land in user/accounts/.
        $caller = TestHelper::createMockUser('uploader', [
            'access' => ['api' => ['access' => true, 'media' => ['write' => true]]],
        ]);

        $controller = $this->buildController();
        $request = $this->makeRequest($caller, [
            'destination' => 'self@:',
            'scope'       => 'users/anything',
        ], ['file' => $this->makeUpload('pwned.yaml', "password: hunter2\naccess:\n  api:\n    super: true\n")]);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessageMatches('/users\/anything.*api\.users\.write/i');
        $controller->upload($request);
    }

    #[Test]
    public function users_scope_succeeds_for_caller_targeting_own_account(): void
    {
        $caller = TestHelper::createMockUser('uploader', [
            'access' => ['api' => ['access' => true, 'media' => ['write' => true]]],
        ]);

        $controller = $this->buildController();
        $request = $this->makeRequest($caller, [
            'destination' => 'self@:',
            'scope'       => 'users/uploader',
        ], ['file' => $this->makeUpload('avatar.png', "\x89PNG\r\n\x1a\n")]);

        $response = $controller->upload($request);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertFileExists($this->userRoot . '/accounts/avatar.png');
    }

    #[Test]
    public function users_scope_allows_admin_to_target_other_user(): void
    {
        $admin = TestHelper::createMockUser('admin', [
            'access' => ['api' => ['access' => true, 'media' => ['write' => true], 'users' => ['write' => true]]],
        ]);

        $controller = $this->buildController();
        $request = $this->makeRequest($admin, [
            'destination' => 'self@:',
            'scope'       => 'users/someone-else',
        ], ['file' => $this->makeUpload('avatar.png', "\x89PNG\r\n\x1a\n")]);

        $response = $controller->upload($request);
        $this->assertSame(201, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Layer 2: `user/accounts/` accepts image extensions only.
    // ------------------------------------------------------------------

    #[Test]
    public function ghsa_6xx2_yaml_into_accounts_via_account_stream_is_rejected(): void
    {
        // Bypasses the scope check by using `account://` directly. Must still
        // be blocked by the per-endpoint extension policy.
        $caller = TestHelper::createMockUser('uploader', [
            'access' => ['api' => ['access' => true, 'media' => ['write' => true]]],
        ]);

        $controller = $this->buildController();
        $request = $this->makeRequest($caller, [
            'destination' => 'account://',
            'scope'       => '',
        ], ['file' => $this->makeUpload('pwned.yaml', "access:\n  api:\n    super: true\n")]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/(\.yaml.*not allowed|user\/accounts)/i');
        $controller->upload($request);
        $this->assertFileDoesNotExist($this->userRoot . '/accounts/pwned.yaml');
    }

    #[Test]
    public function image_into_accounts_via_account_stream_is_allowed(): void
    {
        $caller = TestHelper::createMockUser('uploader', [
            'access' => ['api' => ['access' => true, 'media' => ['write' => true]]],
        ]);

        $controller = $this->buildController();
        $request = $this->makeRequest($caller, [
            'destination' => 'account://',
            'scope'       => '',
        ], ['file' => $this->makeUpload('avatar.png', "\x89PNG\r\n\x1a\n")]);

        $response = $controller->upload($request);
        $this->assertSame(201, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Layer 3: `user/config/` and `user/env/` reject every upload.
    // ------------------------------------------------------------------

    #[Test]
    public function uploads_into_user_config_are_rejected(): void
    {
        $caller = TestHelper::createMockUser('uploader', [
            'access' => ['api' => ['access' => true, 'media' => ['write' => true]]],
        ]);

        $controller = $this->buildController();
        $request = $this->makeRequest($caller, [
            'destination' => 'config',
            'scope'       => '',
        ], ['file' => $this->makeUpload('site.png', 'png-bytes')]);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessageMatches('/config.*not allowed/i');
        $controller->upload($request);
    }

    #[Test]
    public function uploads_into_user_env_are_rejected(): void
    {
        $caller = TestHelper::createMockUser('uploader', [
            'access' => ['api' => ['access' => true, 'media' => ['write' => true]]],
        ]);

        $controller = $this->buildController();
        $request = $this->makeRequest($caller, [
            'destination' => 'env/dev/config',
            'scope'       => '',
        ], ['file' => $this->makeUpload('logo.png', 'png-bytes')]);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessageMatches('/env.*not allowed/i');
        $controller->upload($request);
    }

    // ------------------------------------------------------------------
    // Layer 4: yaml/json/twig denied even outside user/accounts/.
    // ------------------------------------------------------------------

    #[Test]
    public function yaml_into_arbitrary_dir_is_rejected_by_endpoint_denylist(): void
    {
        // Even outside user/accounts/, .yaml has no legitimate use as a
        // blueprint media upload — the per-endpoint denylist guards against
        // future scope/locator edge cases that bypass layer 2.
        $caller = TestHelper::createMockUser('uploader', [
            'access' => ['api' => ['access' => true, 'media' => ['write' => true]]],
        ]);
        @mkdir($this->userRoot . '/data', 0775, true);

        $controller = $this->buildController();
        $request = $this->makeRequest($caller, [
            'destination' => 'data',
            'scope'       => '',
        ], ['file' => $this->makeUpload('payload.yaml', "x: 1\n")]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/\.yaml.*not allowed/i');
        $controller->upload($request);
    }

    // ------------------------------------------------------------------
    // Delete-side coverage: deleting an account YAML via the same endpoint.
    // ------------------------------------------------------------------

    #[Test]
    public function delete_of_account_yaml_is_rejected(): void
    {
        // Place a real account YAML so the endpoint can't claim "already
        // gone" — the rejection must fire on the path classification, not
        // the file-existence shortcut.
        file_put_contents($this->userRoot . '/accounts/admin.yaml', "fullname: Admin\n");

        $caller = TestHelper::createMockUser('uploader', [
            'access' => ['api' => ['access' => true, 'media' => ['write' => true]]],
        ]);

        $controller = $this->buildController();
        $request = $this->makeRequest($caller, [
            'path' => 'accounts/admin.yaml',
        ]);

        $threw = false;
        try {
            $controller->delete($request);
        } catch (ForbiddenException $e) {
            $threw = true;
            $this->assertMatchesRegularExpression('/avatar image|user\/accounts/i', $e->getMessage());
        }
        $this->assertTrue($threw, 'Delete must be rejected by Forbidden, not silently succeed.');
        $this->assertFileExists($this->userRoot . '/accounts/admin.yaml', 'YAML must not be unlinked.');
    }

    #[Test]
    public function delete_into_user_config_is_rejected(): void
    {
        file_put_contents($this->userRoot . '/config/system.yaml', "site:\n  title: x\n");

        $caller = TestHelper::createMockUser('uploader', [
            'access' => ['api' => ['access' => true, 'media' => ['write' => true]]],
        ]);

        $controller = $this->buildController();
        $request = $this->makeRequest($caller, ['path' => 'config/system.yaml']);

        $this->expectException(ForbiddenException::class);
        $controller->delete($request);
        $this->assertFileExists($this->userRoot . '/config/system.yaml');
    }
}
