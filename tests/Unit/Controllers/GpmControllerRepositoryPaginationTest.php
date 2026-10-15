<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\GPM\GPM;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\GpmController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The install modal asks for /gpm/repository/{plugins,themes} 500 rows at a
 * time and walks every page. A site whose saved api.yaml still carries the old
 * `max_per_page: 100` must still get each package exactly once: page 2 has to
 * start at row 500, not at row 100 (getgrav/grav-admin-next#38).
 */
#[CoversClass(GpmController::class)]
class GpmControllerRepositoryPaginationTest extends TestCase
{
    #[Test]
    #[DataProvider('cases')]
    public function walking_every_page_returns_each_package_once(string $type, int $maxPerPage): void
    {
        $controller = $this->controller(525, $maxPerPage);
        $method = $type === 'plugins' ? 'repositoryPlugins' : 'repositoryThemes';

        $slugs = [];
        for ($page = 1; ; $page++) {
            $body = json_decode((string) $controller->{$method}($this->request($type, $page))->getBody(), true);
            foreach ($body['data'] as $row) {
                $slugs[] = $row['slug'];
            }
            if ($page >= $body['meta']['pagination']['total_pages']) {
                break;
            }
        }

        $this->assertSame(525, count($slugs));
        $this->assertSame([], array_keys(array_filter(array_count_values($slugs), static fn (int $n) => $n > 1)));
    }

    /** @return array<string, array{string, int}> */
    public static function cases(): array
    {
        return [
            'plugins, default max' => ['plugins', 1000],
            'plugins, legacy max 100' => ['plugins', 100],
            'themes, legacy max 100' => ['themes', 100],
        ];
    }

    private function controller(int $count, int $maxPerPage): GpmController
    {
        $config = new Config(['plugins' => ['api' => [
            'route' => '/api',
            'version_prefix' => 'v1',
            'pagination' => ['default_per_page' => 20, 'max_per_page' => $maxPerPage],
        ]]]);

        $grav = TestHelper::createMockGrav([
            'config' => $config,
            'locator' => new class {
                public function findResource(string $uri, bool $absolute = false, bool $createDir = false): string|false
                {
                    return str_starts_with($uri, 'cache://') ? sys_get_temp_dir() : false;
                }
            },
            'permissions' => new Permissions(),
        ]);

        $packages = [];
        for ($i = 0; $i < $count; $i++) {
            $slug = sprintf('pkg-%03d', $i);
            $packages[$slug] = (object) ['slug' => $slug, 'name' => $slug, 'version' => '1.0.0'];
        }

        $gpm = new class ($packages) extends GPM {
            /** @param array<string, object> $packages */
            public function __construct(private array $packages) {}

            public function getRepositoryPlugins()
            {
                return $this->packages;
            }

            public function getRepositoryThemes()
            {
                return $this->packages;
            }

            public function isPluginInstalled($slug): bool
            {
                return false;
            }

            public function isThemeInstalled($slug): bool
            {
                return false;
            }
        };

        return new class ($grav, $config, $gpm) extends GpmController {
            public function __construct($grav, $config, private GPM $gpm)
            {
                parent::__construct($grav, $config);
            }

            protected function getGpm(bool $refresh = false): GPM
            {
                return $this->gpm;
            }
        };
    }

    private function request(string $type, int $page): ServerRequestInterface
    {
        return TestHelper::createMockRequest(
            method: 'GET',
            path: '/api/v1/gpm/repository/' . $type,
            queryParams: ['per_page' => '500', 'page' => (string) $page],
            attributes: [
                'api_user' => TestHelper::createMockUser('admin', ['access' => ['api' => ['access' => true, 'gpm' => ['read' => true]]]]),
            ],
        );
    }
}
