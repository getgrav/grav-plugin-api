<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Tests\Unit\BuildsPageAclTree;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /pages and GET /pages/{route}/neighbors leave out pages the caller may
 * not read or list, keep the way down to the ones they may as `ancestor_only`
 * rows, and honour `editable=true` (getgrav/grav-plugin-admin2#209).
 *
 * Runs the real endpoints on the regular Pages service against the mock tree
 * in BuildsPageAclTree.
 */
#[CoversClass(PagesController::class)]
class PagesControllerListVisibilityTest extends TestCase
{
    use BuildsPageAclTree;

    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    private function controller(): PagesController
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
        $pages->method('children')->willReturnCallback(function (string $path) use ($byPath): \ArrayIterator {
            $page = $byPath[$path] ?? null;
            $children = [];
            foreach ($page?->children() ?? [] as $child) {
                $children[(string) $child->path()] = $child;
            }

            return new \ArrayIterator($children);
        });

        $config = new Config([
            'plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1', 'flex_backend' => ['pages' => false]]],
        ]);
        $grav = TestHelper::createMockGrav([
            'config' => $config,
            'pages' => $pages,
            'permissions' => new Permissions(),
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
                    return sys_get_temp_dir() . '/grav_api_list_visibility_test';
                }
            },
        ]);

        return new PagesController($grav, $config);
    }

    private function info(): UserInterface
    {
        return $this->treeUser('info', ['redaction-informatique']);
    }

    private function admin(): UserInterface
    {
        return $this->treeUser('admin', [], ['api' => ['access' => true, 'super' => true]]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{rows: array<string, array<string, mixed>>, total: int}
     */
    private function list(UserInterface $user, array $query): array
    {
        $response = $this->controller()->index(TestHelper::createMockRequest(
            queryParams: $query + ['per_page' => 100],
            attributes: ['api_user' => $user],
        ));
        $body = $this->body($response);

        $rows = [];
        foreach ($body['data'] as $row) {
            $rows[$row['raw_route']] = $row;
        }

        return ['rows' => $rows, 'total' => $body['meta']['pagination']['total']];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @return array<string, bool> raw route => ancestor_only
     */
    private static function ancestorFlags(array $rows): array
    {
        return array_map(static fn (array $row): bool => $row['ancestor_only'], $rows);
    }

    #[Test]
    public function the_flat_list_leaves_out_unreadable_pages_and_counts_only_the_rest(): void
    {
        $result = $this->list($this->info(), ['sort' => 'order']);

        self::assertSame(13, $result['total']);
        self::assertArrayNotHasKey('/rh', $result['rows']);
        self::assertArrayNotHasKey('/rh/formation', $result['rows']);
        self::assertArrayNotHasKey('/direction', $result['rows']);
        self::assertArrayNotHasKey('/communication/charte', $result['rows']);
        self::assertArrayHasKey('/rh/annuaire', $result['rows']);
        self::assertArrayHasKey('/direction/public', $result['rows']);
        foreach ($result['rows'] as $route => $row) {
            self::assertFalse($row['ancestor_only'], $route);
            self::assertTrue($row['permissions']['read'], $route);
            self::assertTrue($row['permissions']['list'], $route);
        }
    }

    #[Test]
    public function the_flat_list_is_unchanged_for_a_super_admin(): void
    {
        $result = $this->list($this->admin(), ['sort' => 'order']);

        self::assertSame(20, $result['total']);
        self::assertTrue($result['rows']['/direction/comite']['permissions']['update']);
        self::assertFalse($result['rows']['/direction/comite']['ancestor_only']);
    }

    #[Test]
    public function a_folder_listing_keeps_the_way_down_as_ancestor_only_rows(): void
    {
        foreach ([['children_of' => '/', 'sort' => 'default'], ['root' => 'true'], ['children_of' => '/', 'sort' => 'title']] as $query) {
            $result = $this->list($this->info(), $query);

            self::assertSame(
                ['/blog' => false, '/communication' => false, '/direction' => true, '/home' => false, '/outils' => false, '/rh' => true],
                self::sorted(self::ancestorFlags($result['rows'])),
                json_encode($query),
            );
            self::assertSame(6, $result['total']);
            self::assertFalse($result['rows']['/rh']['permissions']['read']);
            self::assertFalse($result['rows']['/rh']['permissions']['update']);
        }

        // A page the caller can't read gives away no more than the tree needs.
        $result = $this->list($this->info(), ['children_of' => '/', 'sort' => 'default']);
        self::assertSame(
            ['route', 'raw_route', 'parent_route', 'slug', 'title', 'menu', 'order', 'has_children', 'permissions', 'ancestor_only'],
            array_keys($result['rows']['/rh']),
        );
        self::assertArrayHasKey('template', $result['rows']['/outils']);
        self::assertArrayHasKey('header', $result['rows']['/outils']);

        $rh = $this->list($this->info(), ['children_of' => '/rh', 'sort' => 'default']);
        self::assertSame(['/rh/formation' => true, '/rh/annuaire' => false], self::ancestorFlags($rh['rows']));
    }

    #[Test]
    public function editable_true_keeps_editable_pages_and_the_way_down_to_them(): void
    {
        $flat = $this->list($this->info(), ['editable' => 'true', 'sort' => 'order']);
        self::assertSame(
            ['/communication/newsletter/edition-it', '/outils', '/outils/logiciels', '/rh/formation/outils-informatiques'],
            self::sortedKeys($flat['rows']),
        );
        self::assertSame(4, $flat['total']);

        $root = $this->list($this->info(), ['children_of' => '/', 'sort' => 'default', 'editable' => 'true']);
        self::assertSame(['/communication' => true, '/outils' => false, '/rh' => true], self::sorted(self::ancestorFlags($root['rows'])));
        // A read-only way down still says the caller may read it.
        self::assertTrue($root['rows']['/communication']['permissions']['read']);
        // …and it is a full row, so the admin can open it read-only and show its
        // state. A way down the caller can't read stays cut down.
        self::assertArrayHasKey('published', $root['rows']['/communication']);
        self::assertArrayHasKey('delete_recoverable', $root['rows']['/communication']);
        self::assertArrayNotHasKey('published', $root['rows']['/rh']);
        self::assertFalse($root['rows']['/rh']['permissions']['read']);

        // editable=false is not "read-only pages only": it changes nothing.
        $all = $this->list($this->info(), ['editable' => 'false', 'sort' => 'order']);
        self::assertSame(13, $all['total']);
    }

    #[Test]
    public function locate_points_into_the_filtered_list(): void
    {
        $response = $this->controller()->index(TestHelper::createMockRequest(
            queryParams: ['children_of' => '/rh', 'sort' => 'default', 'locate' => '/rh/annuaire', 'per_page' => 1],
            attributes: ['api_user' => $this->info()],
        ));
        $pagination = $this->body($response)['meta']['pagination'];

        self::assertSame(1, $pagination['located_at_index']);
        self::assertSame(2, $pagination['total']);
    }

    /**
     * @return array<string, mixed>
     */
    private function neighbors(UserInterface $user, string $route, array $query = []): array
    {
        $response = $this->controller()->neighbors(TestHelper::createMockRequest(
            queryParams: $query,
            attributes: ['api_user' => $user, 'route_params' => ['route' => ltrim($route, '/')]],
        ));

        return $this->body($response)['data'];
    }

    #[Test]
    public function neighbors_step_over_pages_the_listing_hides_or_keeps_only_as_a_way_down(): void
    {
        // Top level for info: home, outils, [rh: ancestor_only], communication,
        // [direction: ancestor_only], blog. The navigator skips both.
        $data = $this->neighbors($this->info(), '/communication');
        self::assertSame('/outils', $data['prev']['raw_route']);
        self::assertSame('/blog', $data['next']['raw_route']);
        self::assertSame(2, $data['index']);
        self::assertSame(4, $data['total']);
        self::assertFalse($data['next']['ancestor_only']);

        // Inside a hidden folder: no parent to step up to, no siblings.
        $data = $this->neighbors($this->info(), '/rh/annuaire');
        self::assertNull($data['parent']);
        self::assertNull($data['prev']);
        self::assertSame(1, $data['total']);

        // The super admin sees every sibling.
        $data = $this->neighbors($this->admin(), '/communication');
        self::assertSame('/rh', $data['prev']['raw_route']);
        self::assertSame(6, $data['total']);
    }

    #[Test]
    public function neighbors_follow_editable_true(): void
    {
        $data = $this->neighbors($this->info(), '/outils/logiciels', ['editable' => 'true']);
        self::assertNull($data['prev']);
        // /outils/procedures-it is read-only, so it isn't stepped onto.
        self::assertNull($data['next']);
        self::assertSame(1, $data['total']);
        self::assertSame('/outils', $data['parent']['raw_route']);
        self::assertFalse($data['parent']['ancestor_only']);

        // A readable parent the caller can't edit comes back flagged.
        $data = $this->neighbors($this->info(), '/communication/newsletter/edition-it', ['editable' => 'true']);
        self::assertSame('/communication/newsletter', $data['parent']['raw_route']);
        self::assertTrue($data['parent']['ancestor_only']);
    }

    /**
     * @param array<string, bool> $flags
     * @return array<string, bool>
     */
    private static function sorted(array $flags): array
    {
        ksort($flags);

        return $flags;
    }

    /**
     * @param array<string, mixed> $rows
     * @return list<string>
     */
    private static function sortedKeys(array $rows): array
    {
        $keys = array_keys($rows);
        sort($keys);

        return $keys;
    }
}
