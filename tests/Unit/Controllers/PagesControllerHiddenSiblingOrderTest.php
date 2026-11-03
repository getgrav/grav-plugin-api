<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\BuildsPageAclTree;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An editor's page list leaves out the pages they may not read
 * (getgrav/grav-plugin-admin2#209), so the numbers the admin picks for a drop,
 * and the slug list it sends to reorder, only know about the pages it showed.
 * The server numbers against ALL of a folder's children, so a hidden sibling
 * never ends up sharing its number with a page that was moved.
 */
#[CoversClass(PagesController::class)]
class PagesControllerHiddenSiblingOrderTest extends TestCase
{
    use BuildsPageAclTree;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/grav_api_hidden_sibling_' . bin2hex(random_bytes(4));
        foreach (['01.actualites', '02.evenements', '03.charte', '04.newsletter', 'zz-unnumbered'] as $folder) {
            mkdir($this->dir . '/communication/' . $folder, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/communication/*') ?: [] as $folder) {
            rmdir($folder);
        }
        rmdir($this->dir . '/communication');
        rmdir($this->dir);
        Grav::resetInstance();
    }

    private function controller(?PageInterface $charte = null): PagesController
    {
        $pages = $this->createMock(Pages::class);
        $pages->method('get')->willReturnCallback(
            fn (string $path): ?PageInterface => $path === $this->dir . '/communication/03.charte' ? $charte : null
        );
        $config = new Config(['plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']]]);
        $grav = TestHelper::createMockGrav([
            'config' => $config,
            'pages' => $pages,
            'locator' => new class {
                public function findResource(string $uri, bool $absolute = false): string
                {
                    return sys_get_temp_dir() . '/grav_api_hidden_sibling_locator';
                }
            },
        ]);

        return new PagesController($grav, $config);
    }

    /**
     * A reorganize op as Phase 1 of reorganize() resolves it, for a page
     * staying in /communication.
     *
     * @return array<string, mixed>
     */
    private function op(string $folder, int $position): array
    {
        $slug = (string) preg_replace('/^\d+\./', '', $folder);

        return [
            'route' => '/communication/' . $slug,
            'page' => null,
            'slug' => $slug,
            'dirName' => sprintf('%02d.%s', $position, $slug),
            'oldPath' => $this->dir . '/communication/' . $folder,
            'currentParentRoute' => '/communication',
            'newParentRoute' => '/communication',
            'newParentPath' => $this->dir . '/communication',
            'position' => $position,
            'actuallyMoves' => sprintf('%02d.%s', $position, $slug) !== $folder,
            'tempPath' => null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $ops
     * @return array<string, string> route => final folder name
     */
    private function unique(PagesController $controller, array $ops): array
    {
        $resolved = (new \ReflectionMethod(PagesController::class, 'keepSiblingNumbersUnique'))->invoke($controller, $ops);

        $names = [];
        foreach ($resolved as $op) {
            $names[$op['route']] = $op['dirName'];
        }

        return $names;
    }

    private function charte(): PageInterface
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('rawRoute')->willReturn('/communication/charte');
        $page->method('path')->willReturn($this->dir . '/communication/03.charte');
        $parent = $this->createMock(PageInterface::class);
        $parent->method('rawRoute')->willReturn('/communication');
        $parent->method('root')->willReturn(false);
        $page->method('parent')->willReturn($parent);

        return $page;
    }

    #[Test]
    public function a_position_that_is_free_is_used_as_sent(): void
    {
        // Moving newsletter to 5: nothing else has 5, nothing else moves.
        self::assertSame(
            ['/communication/newsletter' => '05.newsletter'],
            $this->unique($this->controller(), [$this->op('04.newsletter', 5)]),
        );
    }

    #[Test]
    public function a_hidden_sibling_holding_the_number_makes_room(): void
    {
        // The editor saw actualites (1), evenements (2) and newsletter (4),
        // and dropped newsletter third: position 3, which the hidden charte has.
        self::assertSame(
            ['/communication/newsletter' => '03.newsletter', '/communication/charte' => '04.charte'],
            $this->unique($this->controller($this->charte()), [$this->op('04.newsletter', 3)]),
        );
    }

    #[Test]
    public function every_number_stays_unique_and_the_requested_order_holds(): void
    {
        // evenements -> 3 takes charte's number, charte moves to the next
        // one (4) and newsletter, asked for 4, follows at 5. The editor's
        // order (evenements before newsletter) holds, nothing shares a number,
        // and the gap evenements leaves at 2 stays.
        self::assertSame(
            [
                '/communication/evenements' => '03.evenements',
                '/communication/newsletter' => '05.newsletter',
                '/communication/charte' => '04.charte',
            ],
            $this->unique($this->controller($this->charte()), [$this->op('02.evenements', 3), $this->op('04.newsletter', 4)]),
        );
    }

    #[Test]
    public function a_sibling_that_cannot_be_found_fails_the_request_instead_of_sharing_a_number(): void
    {
        $this->expectException(ValidationException::class);
        $this->unique($this->controller(null), [$this->op('04.newsletter', 3)]);
    }

    /**
     * @param list<string> $order
     * @return list<string>
     */
    private static function merge(array $order): array
    {
        $childMap = [
            'actualites' => '01.actualites',
            'evenements' => '02.evenements',
            'charte' => '03.charte',
            'newsletter' => '04.newsletter',
            'unnumbered' => 'unnumbered',
        ];
        $dirsBySlug = array_map(static fn (string $dir): array => [$dir], $childMap);

        return (new \ReflectionMethod(PagesController::class, 'mergeReorder'))->invoke(null, $childMap, $dirsBySlug, $order);
    }

    #[Test]
    public function a_partial_reorder_keeps_unlisted_siblings_in_their_place(): void
    {
        // charte (hidden) keeps the third place; the listed pages swap around it.
        self::assertSame(
            ['newsletter', 'evenements', 'charte', 'actualites'],
            self::merge(['newsletter', 'evenements', 'actualites']),
        );
    }

    #[Test]
    public function a_full_reorder_is_the_requested_order(): void
    {
        $order = ['unnumbered', 'charte', 'newsletter', 'actualites', 'evenements'];

        self::assertSame($order, self::merge($order));
    }

    /**
     * A caller may only name the slugs its own folder listing shows. The
     * mock tree in BuildsPageAclTree hides /communication/charte from the
     * informatique group.
     */
    private function reorderAs(string $username, array $access, array $order): void
    {
        $tree = $this->buildTree();
        $byPath = [];
        foreach ($tree as $page) {
            $byPath[(string) $page->path()] = $page;
        }
        $pages = $this->createMock(Pages::class);
        $pages->method('instances')->willReturn($byPath);
        $pages->method('root')->willReturn($tree['/']);
        $pages->method('getPagesCacheId')->willReturn(null);
        $pages->method('find')->willReturnCallback(
            static fn (string $route): ?PageInterface => $route !== '/' ? ($tree[$route] ?? null) : null
        );
        $config = new Config([
            'plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1', 'flex_backend' => ['pages' => false]]],
        ]);
        $grav = TestHelper::createMockGrav([
            'config' => $config,
            'pages' => $pages,
            'permissions' => new \Grav\Framework\Acl\Permissions(),
            'language' => new class {
                public function getActive(): ?string { return null; }
                public function enabled(): bool { return false; }
            },
            'events' => new class {
                public function dispatch(object $event, ?string $eventName = null): object { return $event; }
            },
            'locator' => new class {
                public function findResource(string $uri, bool $absolute = false): string
                {
                    return sys_get_temp_dir() . '/grav_api_hidden_sibling_locator';
                }
            },
        ]);
        $user = TestHelper::createMockUser($username, ['username' => $username, 'groups' => ['redaction-informatique'], 'access' => $access]);

        (new PagesController($grav, $config))->reorder(TestHelper::createMockRequest(
            attributes: ['api_user' => $user, 'route_params' => ['route' => 'communication'], 'json_body' => ['order' => $order]],
        ));
    }

    private const WRITER = ['api' => ['access' => true, 'pages' => ['read' => true, 'write' => true]]];

    #[Test]
    public function a_hidden_childs_slug_is_unknown_to_a_caller_who_cannot_see_it(): void
    {
        try {
            $this->reorderAs('info', self::WRITER, ['charte', 'newsletter']);
            self::fail('The hidden slug should have been refused.');
        } catch (ValidationException $e) {
            self::assertSame("Child page with slug 'charte' not found under '/communication'.", $e->getMessage());
        }

        // Same answer as for a slug that was never there.
        try {
            $this->reorderAs('info', self::WRITER, ['no-such-page', 'newsletter']);
            self::fail('The unknown slug should have been refused.');
        } catch (ValidationException $e) {
            self::assertSame("Child page with slug 'no-such-page' not found under '/communication'.", $e->getMessage());
        }
    }

    #[Test]
    public function a_super_admin_can_name_every_child(): void
    {
        // Listing the hidden slug twice gets past the "not found" check, to "more than once".
        try {
            $this->reorderAs('root', ['api' => ['access' => true, 'super' => true]], ['charte', 'charte']);
            self::fail('The repeated slug should have been refused.');
        } catch (ValidationException $e) {
            self::assertSame("Child page with slug 'charte' is listed more than once.", $e->getMessage());
        }
    }

    #[Test]
    public function the_reorder_plan_names_a_folder_in_the_way_only_to_a_caller_who_sees_them_all(): void
    {
        $parent = $this->dir . '/communication';
        $childMap = ['actualites' => '01.actualites', 'evenements' => '02.evenements'];
        // `04.newsletter` isn't in the request, so it sits on the name the plan wants.
        $order = ['evenements', 'actualites'];
        mkdir($parent . '/_temp_1_evenements');
        try {
            $plan = new \ReflectionMethod(PagesController::class, 'planReorder');
            $controller = $this->controller();

            try {
                $plan->invoke($controller, $parent, $childMap, $order, 3, '/communication', true);
                self::fail('The folder in the way should have been refused.');
            } catch (ValidationException $e) {
                self::assertStringContainsString('_temp_1_evenements', $e->getMessage());
            }

            try {
                $plan->invoke($controller, $parent, $childMap, $order, 3, '/communication', false);
                self::fail('The folder in the way should have been refused.');
            } catch (ValidationException $e) {
                self::assertStringNotContainsString('_temp_1_evenements', $e->getMessage());
                self::assertStringContainsString("Cannot reorder '/communication'", $e->getMessage());
            }
        } finally {
            rmdir($parent . '/_temp_1_evenements');
        }
    }

    #[Test]
    public function making_room_names_a_sibling_that_cannot_be_found_only_to_a_caller_who_sees_them_all(): void
    {
        $method = new \ReflectionMethod(PagesController::class, 'keepSiblingNumbersUnique');
        $controller = $this->controller(null);

        try {
            $method->invoke($controller, [$this->op('04.newsletter', 3)], true);
            self::fail('The sibling should have been refused.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('03.charte', $e->getMessage());
        }

        try {
            $method->invoke($controller, [$this->op('04.newsletter', 3)], false);
            self::fail('The sibling should have been refused.');
        } catch (ValidationException $e) {
            self::assertStringNotContainsString('charte', $e->getMessage());
        }
    }
}
