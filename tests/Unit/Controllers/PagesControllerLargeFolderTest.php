<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Framework\Flex\FlexDirectory;
use Grav\Framework\Flex\Interfaces\FlexIndexInterface;
use Grav\Framework\Flex\Interfaces\FlexObjectInterface;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A folder listing reads the folder's children from the folder itself, so it
 * must not ask every child for its parent again, or load every child object
 * just to learn its key. With the object cache on, each parent() unserialized
 * a fresh copy of the folder, and a folder with 4,000 children took seconds
 * and gigabytes for one chunk (getgrav/grav-plugin-admin2#200).
 */
#[CoversClass(PagesController::class)]
class PagesControllerLargeFolderTest extends TestCase
{
    /**
     * A Flex pages directory holding /docs and the given children
     * (storage folder => [key, order, slug]).
     *
     * @param array<string, array{0: string, 1: int, 2: string}> $children
     */
    private function directory(array $children): FlexDirectory
    {
        $folder = $this->createMock(LargeFolderPage::class);
        $folder->method('rawRoute')->willReturn('/docs');
        $folder->method('route')->willReturn('/docs');
        $folder->method('getMasterKey')->willReturn('02.docs');
        $folder->method('getMetaData')->willReturn(['children' => array_fill_keys(array_keys($children), 1)]);
        $folder->method('header')->willReturn((object) []);

        $pages = [];
        $keyMap = [];
        foreach ($children as $storage => [$key, $order, $slug]) {
            $page = $this->createMock(LargeFolderChild::class);
            $page->expects(self::never())->method('parent');
            $page->method('getKey')->willReturn($key);
            $page->method('order')->willReturn($order);
            $page->method('slug')->willReturn($slug);
            $pages[$key] = $page;
            $keyMap['02.docs/' . $storage] = $key;
        }

        // Looking up the children by storage key answers from the index
        // entries; walking it would load every child object.
        $byStorageKey = $this->createMock(FlexIndexInterface::class);
        $byStorageKey->method('getIndexMap')->with('key')->willReturn($keyMap);
        $byStorageKey->expects(self::never())->method('getIterator');

        $selected = $this->createMock(FlexIndexInterface::class);
        $selected->method('getIterator')->willReturn(new \ArrayIterator($pages));

        $collection = $this->createMock(FlexIndexInterface::class);
        $collection->method('getKeys')->willReturn(array_merge(['docs', 'home'], array_keys($pages)));
        $collection->method('select')->willReturnCallback(function (array $keys) use ($selected, $pages) {
            self::assertSame(array_keys($pages), $keys, 'only the folder\'s own children are selected');
            return $selected;
        });

        $directory = $this->createMock(FlexDirectory::class);
        $directory->method('isEnabled')->willReturn(true);
        $directory->method('getCollection')->willReturn($collection);
        $directory->method('getObject')->willReturnCallback(fn ($key) => $key === 'docs' ? $folder : null);
        $directory->method('getIndex')->willReturnCallback(function (?array $keys = null, ?string $keyField = null) use ($byStorageKey, $keyMap) {
            self::assertSame('storage_key', $keyField);
            self::assertSame(array_keys($keyMap), $keys);
            return $byStorageKey;
        });

        return $directory;
    }

    private function controller(FlexDirectory $directory): PagesController
    {
        $config = new Config([
            'plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']],
        ]);
        $locator = new class {
            public function findResource(string $uri, bool $absolute = false): string
            {
                return sys_get_temp_dir() . '/grav_api_large_folder_test';
            }
        };
        $flex = new class ($directory) {
            public function __construct(private readonly FlexDirectory $directory) {}

            public function getDirectory(string $type): FlexDirectory
            {
                return $this->directory;
            }
        };

        return new PagesController(TestHelper::createMockGrav(['config' => $config, 'locator' => $locator, 'flex_objects' => $flex]), $config);
    }

    /** @return list<string> */
    private function orderedKeys(PagesController $controller, array $filters): array
    {
        $items = (new ReflectionMethod(PagesController::class, 'defaultOrderedChildren'))->invoke($controller, $filters, null);

        return array_map(static fn (PageInterface $p) => $p->getKey(), $items);
    }

    #[Test]
    public function default_order_lists_the_folders_children_without_asking_each_for_its_parent(): void
    {
        $directory = $this->directory([
            '03.zeta' => ['docs/zeta', 3, 'zeta'],
            '01.beta' => ['docs/beta', 1, 'beta'],
            '02.alpha' => ['docs/alpha', 2, 'alpha'],
        ]);

        self::assertSame(
            ['docs/beta', 'docs/alpha', 'docs/zeta'],
            $this->orderedKeys($this->controller($directory), ['children_of' => '/docs']),
        );
    }

    #[Test]
    public function folder_children_map_storage_keys_to_page_keys_from_the_index(): void
    {
        $directory = $this->directory([
            '01.a' => ['docs/a', 1, 'a'],
            '02.b' => ['docs/b', 2, 'b'],
        ]);
        $controller = $this->controller($directory);

        $folder = (new ReflectionMethod(PagesController::class, 'flexFolderChildren'))
            ->invoke($controller, $directory, $directory->getCollection(), '/docs');

        self::assertSame(['docs/a', 'docs/b'], $folder['keys']);
    }
}

/** A Flex page folder: what flexFolderChildren() reads from the folder object. */
interface LargeFolderPage extends PageInterface, FlexObjectInterface
{
    public function getMasterKey(): string;
}

/** A Flex page as the listing reads it: the stubbed interface plus its key. */
interface LargeFolderChild extends PageInterface
{
    public function getKey();
}
