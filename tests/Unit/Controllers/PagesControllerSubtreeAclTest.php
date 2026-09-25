<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Serializers\PageSerializer;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;

/**
 * Operations that take a whole page folder must honor the rules on every page
 * inside it, not just the one named in the request (getgrav/grav-plugin-api#47).
 *
 *  - GET /pages/{route}?children=true returned children the caller would get a
 *    403 for on their own route, with header, content and media.
 *  - DELETE /pages/{route}, batch delete and copy authorized only the top page,
 *    so a child's own delete/read deny was skipped.
 */
class PagesControllerSubtreeAclTest extends TestCase
{
    /** @var array<string, PageInterface> child route => parent */
    private array $parents = [];

    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    private function controller(): PagesController
    {
        $config = new Config([
            'plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']],
        ]);
        $locator = new class {
            public function findResource(string $uri, bool $absolute = false): string
            {
                return sys_get_temp_dir() . '/grav_api_subtree_acl_test';
            }
        };
        $grav = TestHelper::createMockGrav(['config' => $config, 'locator' => $locator]);

        return new PagesController($grav, $config);
    }

    private function request(UserInterface $user): ServerRequestInterface
    {
        return TestHelper::createMockRequest(attributes: ['api_user' => $user]);
    }

    private function alice(array $pages = ['read' => true]): UserInterface
    {
        return TestHelper::createMockUser('alice', [
            'username' => 'alice',
            'groups' => ['staff'],
            'access' => ['api' => ['access' => true, 'pages' => $pages]],
        ]);
    }

    /**
     * @param array<string, mixed> $header
     * @param list<PageInterface> $children
     */
    private function page(string $route, array $header = [], array $children = []): PageInterface
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('header')->willReturn(json_decode((string) json_encode($header ?: ['title' => $route])));
        $page->method('rawRoute')->willReturn($route);
        $page->method('route')->willReturn($route);
        $page->method('path')->willReturn('/pages' . $route);
        $page->method('parent')->willReturnCallback(fn () => $this->parents[$route] ?? null);
        $page->method('children')->willReturn(new \ArrayIterator($children));
        foreach ($children as $child) {
            $this->parents[(string) $child->rawRoute()] = $page;
        }
        $page->method('template')->willReturn('default');
        $page->method('language')->willReturn(null);

        return $page;
    }

    /**
     * /docs (no rules) with /docs/public (no rules) and /docs/internal, whose own
     * frontmatter denies the `staff` group read and delete.
     *
     * @return array{PageInterface, PageInterface, PageInterface}
     */
    private function tree(): array
    {
        $public = $this->page('/docs/public');
        $internal = $this->page('/docs/internal', [
            'title' => 'Internal',
            'permissions' => ['groups' => ['staff' => ['read' => false, 'delete' => false]]],
        ]);
        $docs = $this->page('/docs', [], [$public, $internal]);

        return [$docs, $public, $internal];
    }

    private function canRead(PagesController $controller, UserInterface $user, PageInterface $page): bool
    {
        return (new ReflectionMethod(PagesController::class, 'canReadPage'))
            ->invoke($controller, $this->request($user), $page);
    }

    private function assertDescendants(PagesController $controller, UserInterface $user, PageInterface $page, string $action): void
    {
        (new ReflectionMethod(PagesController::class, 'assertDescendantsNotDenied'))
            ->invoke($controller, $this->request($user), $page, $action);
    }

    #[Test]
    public function a_child_denying_read_is_not_readable(): void
    {
        [$docs, $public, $internal] = $this->tree();
        $controller = $this->controller();

        self::assertTrue($this->canRead($controller, $this->alice(), $docs));
        self::assertTrue($this->canRead($controller, $this->alice(), $public));
        self::assertFalse($this->canRead($controller, $this->alice(), $internal));
    }

    #[Test]
    public function a_page_level_grant_reaches_children_that_inherit_it(): void
    {
        // No api.pages.read on the account: /team grants read to staff, and
        // /team/notes inherits it while /team/hr denies it.
        $notes = $this->page('/team/notes');
        $hr = $this->page('/team/hr', ['permissions' => ['groups' => ['staff' => ['read' => false]]]]);
        $team = $this->page('/team', ['permissions' => ['groups' => ['staff' => ['read' => true]]]], [$notes, $hr]);

        $controller = $this->controller();
        $alice = $this->alice([]);

        self::assertTrue($this->canRead($controller, $alice, $team));
        self::assertTrue($this->canRead($controller, $alice, $notes));
        self::assertFalse($this->canRead($controller, $alice, $hr));
    }

    #[Test]
    public function the_serializer_leaves_filtered_children_out(): void
    {
        [$docs] = $this->tree();
        $controller = $this->controller();
        $request = $this->request($this->alice());

        $serializer = new class extends PageSerializer {
            public function serialize(object $resource, array $options = []): array
            {
                return ['route' => $resource->rawRoute()];
            }
        };

        $filter = fn (PageInterface $child): bool => (new ReflectionMethod(PagesController::class, 'canReadPage'))
            ->invoke($controller, $request, $child);

        $children = (new ReflectionMethod(PageSerializer::class, 'serializeChildren'))
            ->invoke($serializer, $docs, ['child_filter' => $filter], 1);

        self::assertSame([['route' => '/docs/public']], $children);
    }

    #[Test]
    public function deleting_a_folder_refuses_when_a_child_denies_delete(): void
    {
        [$docs] = $this->tree();

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('/docs/internal');
        $this->assertDescendants($this->controller(), $this->alice(['read' => true, 'write' => true]), $docs, 'delete');
    }

    #[Test]
    public function grandchildren_are_checked_too(): void
    {
        $secret = $this->page('/docs/a/secret', ['permissions' => ['groups' => ['staff' => ['delete' => false]]]]);
        $a = $this->page('/docs/a', [], [$secret]);
        $docs = $this->page('/docs', [], [$a]);

        $this->expectException(ForbiddenException::class);
        $this->assertDescendants($this->controller(), $this->alice(['read' => true, 'write' => true]), $docs, 'delete');
    }

    #[Test]
    public function a_folder_with_no_denying_child_passes(): void
    {
        $docs = $this->page('/docs', [], [$this->page('/docs/a'), $this->page('/docs/b')]);

        $this->assertDescendants($this->controller(), $this->alice(['read' => true, 'write' => true]), $docs, 'delete');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function super_admins_are_not_bound_by_page_rules(): void
    {
        [$docs] = $this->tree();
        $root = TestHelper::createMockUser('root', ['access' => ['api' => ['super' => true]]]);

        $this->assertDescendants($this->controller(), $root, $docs, 'delete');
        $this->addToAssertionCount(1);
    }
}
