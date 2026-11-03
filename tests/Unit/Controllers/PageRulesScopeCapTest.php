<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The API-key scope cap applies to page rules too. A scoped key is held to its
 * scopes whatever its account may do, so the page checks must not
 * read the account's authority past the key:
 *
 *   - changing a page's media on a page whose rules don't decide `update`
 *     falls back to `api.pages.write`, which a key scoped to media doesn't carry
 *   - overriding a page's own deny is super authority, which a scoped key only
 *     has when its scopes include `admin.super`
 *
 * Covers pageUpdateRefusal() (media writes, file fields, permissions.media),
 * assertPageNotDenied() (bulk endpoints, media reads) and authorizePageAction()
 * (single-page reads and writes).
 */
#[CoversClass(AbstractApiController::class)]
class PageRulesScopeCapTest extends TestCase
{
    private const EDITOR = ['api' => ['access' => true, 'pages' => ['read' => true, 'write' => true], 'media' => ['read' => true, 'write' => true]]];
    private const SUPER = ['api' => ['access' => true, 'super' => true]];

    private const MEDIA_KEY = ['api.access', 'api.pages.read', 'api.media.read', 'api.media.write'];
    private const MEDIA_AND_PAGES_KEY = ['api.access', 'api.pages.read', 'api.pages.write', 'api.media.read', 'api.media.write'];
    private const PAGES_KEY = ['api.access', 'api.pages.read', 'api.pages.write'];
    private const SUPER_KEY = ['api.access', 'api.pages.write', 'api.media.write', 'admin.super'];

    private const READ_ONLY = ['groups' => ['editors' => 'rl']];
    private const DENY_UPDATE = ['groups' => ['defaults' => '-u']];

    private object $user;
    private PageInterface $page;
    private MediaController $controller;

    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    /**
     * @param array<string, mixed> $access
     * @param array<string, mixed> $rules
     * @param list<string>|null $scopes null for an unscoped credential
     */
    private function request(array $access, array $rules, ?array $scopes): ServerRequestInterface
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('path')->willReturn(sys_get_temp_dir() . '/grav_api_scope_acl/01.photos');
        $page->method('rawRoute')->willReturn('/photos');
        $page->method('route')->willReturn('/photos');
        $page->method('header')->willReturn(json_decode((string) json_encode(['title' => 'Photos', 'permissions' => $rules])));
        $this->page = $page;

        $pages = $this->createMock(Pages::class);
        $pages->method('find')->willReturnCallback(static fn (string $r): ?PageInterface => $r === '/photos' ? $page : null);

        $this->user = TestHelper::createMockUser('editor', ['username' => 'editor', 'groups' => ['editors'], 'access' => $access]);
        $config = new Config(['plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']]]);
        $grav = TestHelper::createMockGrav([
            'config' => $config,
            'pages' => $pages,
            'permissions' => new Permissions(),
            'accounts' => TestHelper::createMockAccounts(['editor' => $this->user]),
        ]);
        $this->controller = new MediaController($grav, $config);

        $attributes = ['api_user' => $this->user, 'route_params' => ['route' => 'photos']];
        if ($scopes !== null) {
            $attributes['api_key_scopes'] = $scopes;
        }

        return TestHelper::createMockRequest(attributes: $attributes);
    }

    private function call(string $class, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($class, $method))->invoke($this->controller, ...$args);
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>, ?list<string>, bool}> */
    public static function mediaCases(): array
    {
        return [
            // The advisory's two cases.
            'media key borrows account api.pages.write' => [self::EDITOR, self::READ_ONLY, self::MEDIA_KEY, false],
            'media key on super account ignores -u' => [self::SUPER, self::DENY_UPDATE, self::MEDIA_KEY, false],
            'pages key on super account ignores -u' => [self::SUPER, self::DENY_UPDATE, self::MEDIA_AND_PAGES_KEY, false],
            // What must keep working.
            'editor, unscoped, read-only page' => [self::EDITOR, self::READ_ONLY, null, true],
            'editor, key with api.pages.write, read-only page' => [self::EDITOR, self::READ_ONLY, self::MEDIA_AND_PAGES_KEY, true],
            'super, unscoped, -u page' => [self::SUPER, self::DENY_UPDATE, null, true],
            'super, key with admin.super, -u page' => [self::SUPER, self::DENY_UPDATE, self::SUPER_KEY, true],
            'super, key with api.pages.write, read-only page' => [self::SUPER, self::READ_ONLY, self::MEDIA_AND_PAGES_KEY, true],
        ];
    }

    /**
     * @param array<string, mixed> $access
     * @param array<string, mixed> $rules
     * @param list<string>|null $scopes
     */
    #[Test]
    #[DataProvider('mediaCases')]
    public function page_media_writes_honor_the_key_scope(array $access, array $rules, ?array $scopes, bool $allowed): void
    {
        $request = $this->request($access, $rules, $scopes);

        if (!$allowed) {
            $this->expectException(ForbiddenException::class);
        }
        $page = $this->call(MediaController::class, 'findPageOrFail', $request, 'update');
        $this->assertSame('/photos', $page->rawRoute());
    }

    /**
     * @param array<string, mixed> $access
     * @param array<string, mixed> $rules
     * @param list<string>|null $scopes
     */
    #[Test]
    #[DataProvider('mediaCases')]
    public function permissions_media_matches_the_write(array $access, array $rules, ?array $scopes, bool $allowed): void
    {
        $request = $this->request($access, $rules, $scopes);

        $this->assertSame($allowed, $this->call(AbstractApiController::class, 'canChangePageMedia', $request, $this->page));
    }

    #[Test]
    public function bulk_page_checks_honor_a_deny_for_a_scoped_key_on_a_super_account(): void
    {
        $request = $this->request(self::SUPER, self::DENY_UPDATE, self::PAGES_KEY);

        $this->expectException(ForbiddenException::class);
        $this->call(AbstractApiController::class, 'assertPageNotDenied', $request, $this->page, 'update');
    }

    #[Test]
    public function single_page_writes_honor_a_deny_for_a_scoped_key_on_a_super_account(): void
    {
        $request = $this->request(self::SUPER, self::DENY_UPDATE, self::PAGES_KEY);

        $this->expectException(ForbiddenException::class);
        $this->call(AbstractApiController::class, 'authorizePageAction', $request, $this->page, 'update', 'api.pages.write');
    }

    /** @return array<string, array{?list<string>}> */
    public static function superCredentials(): array
    {
        return [
            'unscoped' => [null],
            'key with admin.super' => [self::SUPER_KEY],
        ];
    }

    /** @param list<string>|null $scopes */
    #[Test]
    #[DataProvider('superCredentials')]
    public function super_authority_still_overrides_a_page_deny(?array $scopes): void
    {
        $request = $this->request(self::SUPER, self::DENY_UPDATE, $scopes);

        $this->call(AbstractApiController::class, 'assertPageNotDenied', $request, $this->page, 'update');
        $this->call(AbstractApiController::class, 'authorizePageAction', $request, $this->page, 'update', 'api.pages.write');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_scoped_key_on_a_super_account_still_writes_pages_without_a_deny(): void
    {
        $request = $this->request(self::SUPER, self::READ_ONLY, self::PAGES_KEY);

        $this->call(AbstractApiController::class, 'authorizePageAction', $request, $this->page, 'update', 'api.pages.write');
        $this->addToAssertionCount(1);
    }
}
