<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\GPM\GPM;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\GpmController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /gpm/{plugins,themes}/{slug}/changelog?available=true returns the feed's
 * changelog entries newer than the installed version, while the plain route
 * keeps serving the installed package's own CHANGELOG.md.
 *
 * The real GPM reads the filesystem and the remote feed, so the controller's
 * protected getGpm() is overridden with a GPM subclass that returns canned
 * installed/remote packages.
 */
#[CoversClass(GpmController::class)]
class GpmControllerAvailableChangelogTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_gpm_available_changelog_' . uniqid();
        foreach (['plugins', 'themes'] as $type) {
            mkdir($this->tempDir . '/' . $type . '/demo', 0775, true);
            file_put_contents($this->tempDir . '/' . $type . '/demo/CHANGELOG.md', "# v1.0.0\n\n- the installed changelog");
        }
        mkdir($this->tempDir . '/cache', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tempDir);
    }

    #[Test]
    #[DataProvider('packageTypes')]
    public function available_returns_only_the_entries_newer_than_the_installed_version(string $type): void
    {
        $controller = $this->controller($this->feed());

        $body = $this->body($controller->changelog($this->request($type, 'demo', ['available' => 'true'])));

        $this->assertSame(
            "# v1.2.0 (2026-02-01)\n\n1. [](#new)\n    * Second feature\n\n# v1.1.0 (2026-01-01)\n\n1. [](#bugfix)\n    * First fix",
            $body['data']['content'],
        );
        $this->assertStringNotContainsString('v1.0.0', $body['data']['content']);
        $this->assertStringNotContainsString('the installed changelog', $body['data']['content']);
    }

    #[Test]
    public function an_entry_without_a_date_gets_a_bare_version_heading(): void
    {
        $controller = $this->controller($this->feed(changelog: ['1.1.0' => "plain markdown entry\n"]));

        $body = $this->body($controller->changelog($this->request('plugins', 'demo', ['available' => '1'])));

        $this->assertSame("# v1.1.0\n\nplain markdown entry", $body['data']['content']);
    }

    #[Test]
    #[DataProvider('packageTypes')]
    public function available_is_a_404_when_nothing_is_newer_than_the_installed_version(string $type): void
    {
        $controller = $this->controller($this->feed(installed: '1.2.0'));

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage("No newer changelog entries found for 'demo'.");
        $controller->changelog($this->request($type, 'demo', ['available' => 'true']));
    }

    #[Test]
    #[DataProvider('packageTypes')]
    public function available_is_a_404_when_the_package_is_not_in_the_feed(string $type): void
    {
        $controller = $this->controller($this->feed(remote: false));

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage("No newer changelog entries found for 'demo'.");
        $controller->changelog($this->request($type, 'demo', ['available' => 'true']));
    }

    #[Test]
    public function available_is_a_404_when_the_feed_entry_has_no_changelog(): void
    {
        $controller = $this->controller($this->feed(changelog: []));

        $this->expectException(NotFoundException::class);
        $controller->changelog($this->request('plugins', 'demo', ['available' => 'true']));
    }

    #[Test]
    public function available_is_a_404_when_the_package_is_not_installed(): void
    {
        $controller = $this->controller($this->feed(installed: null));

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage("Package 'demo' is not installed.");
        $controller->changelog($this->request('plugins', 'demo', ['available' => 'true']));
    }

    #[Test]
    #[DataProvider('packageTypes')]
    public function without_the_flag_the_installed_changelog_is_served_and_the_feed_is_not_read(string $type): void
    {
        $controller = $this->controller($this->feed(), failOnFeedRead: true);

        $body = $this->body($controller->changelog($this->request($type, 'demo')));

        $this->assertSame("# v1.0.0\n\n- the installed changelog", $body['data']['content']);
    }

    #[Test]
    public function available_false_serves_the_installed_changelog(): void
    {
        $controller = $this->controller($this->feed(), failOnFeedRead: true);

        $body = $this->body($controller->changelog($this->request('plugins', 'demo', ['available' => 'false'])));

        $this->assertSame("# v1.0.0\n\n- the installed changelog", $body['data']['content']);
    }

    #[Test]
    public function the_slug_is_still_validated_with_the_flag(): void
    {
        $controller = $this->controller($this->feed(), failOnFeedRead: true);

        $this->expectException(ValidationException::class);
        $controller->changelog($this->request('plugins', '..', ['available' => 'true']));
    }

    #[Test]
    public function available_needs_the_same_permission_as_the_installed_changelog(): void
    {
        $controller = $this->controller($this->feed(), failOnFeedRead: true);

        $this->expectException(ForbiddenException::class);
        $controller->changelog($this->request('plugins', 'demo', ['available' => 'true'], gpmRead: false));
    }

    /** @return array<string, array{string}> */
    public static function packageTypes(): array
    {
        return ['plugin' => ['plugins'], 'theme' => ['themes']];
    }

    /**
     * Canned feed state. $installed is the installed version (null = not installed),
     * $remote false drops the package from the feed, $changelog overrides the entries.
     *
     * @param array<string, mixed>|null $changelog
     * @return array{installed: ?string, remote: bool, changelog: array<string, mixed>}
     */
    private function feed(?string $installed = '1.0.0', bool $remote = true, ?array $changelog = null): array
    {
        return [
            'installed' => $installed,
            'remote' => $remote,
            'changelog' => $changelog ?? [
                '1.2.0' => ['date' => '2026-02-01', 'content' => "1. [](#new)\n    * Second feature\n"],
                '1.1.0' => ['date' => '2026-01-01', 'content' => "1. [](#bugfix)\n    * First fix"],
                '1.0.0' => ['date' => '2025-12-01', 'content' => "1. [](#new)\n    * Initial release"],
            ],
        ];
    }

    /** @param array{installed: ?string, remote: bool, changelog: array<string, mixed>} $feed */
    private function controller(array $feed, bool $failOnFeedRead = false): GpmController
    {
        $config = new Config(['plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']]]);

        $locator = new class ($this->tempDir) {
            public function __construct(private string $base) {}

            public function findResource(string $uri, bool $absolute = false, bool $createDir = false): string|false
            {
                if (str_starts_with($uri, 'cache://')) {
                    return $this->base . '/cache';
                }
                if (preg_match('#^(plugins|themes)://(.+)$#', $uri, $m)) {
                    $path = $this->base . '/' . $m[1] . '/' . $m[2];
                    return is_dir($path) ? $path : false;
                }

                return false;
            }
        };

        $grav = TestHelper::createMockGrav([
            'config' => $config,
            'locator' => $locator,
            'permissions' => new Permissions(),
        ]);

        $gpm = new class ($feed, $failOnFeedRead) extends GPM {
            /** @param array{installed: ?string, remote: bool, changelog: array<string, mixed>} $feed */
            public function __construct(private array $feed, private bool $failOnFeedRead) {}

            public function getInstalledPlugin($slug)
            {
                return $this->installed();
            }

            public function getInstalledTheme($slug)
            {
                return $this->installed();
            }

            public function getRepositoryPlugin($slug)
            {
                return $this->remote();
            }

            public function getRepositoryTheme($slug)
            {
                return $this->remote();
            }

            private function installed(): ?object
            {
                if ($this->failOnFeedRead) {
                    throw new \LogicException('The GPM must not be consulted without ?available=true.');
                }

                return $this->feed['installed'] === null ? null : (object) ['version' => $this->feed['installed']];
            }

            private function remote(): ?object
            {
                if (!$this->feed['remote']) {
                    return null;
                }

                // Same contract as Remote\Package::getChangelog(): entries newer than $diff, keys kept.
                return new class ($this->feed['changelog']) {
                    /** @param array<string, mixed> $changelog */
                    public function __construct(public array $changelog) {}

                    /** @return array<string, mixed> */
                    public function getChangelog(?string $diff = null): array
                    {
                        return array_filter(
                            $this->changelog,
                            static fn (string|int $version) => version_compare((string) $diff, (string) $version, '<'),
                            ARRAY_FILTER_USE_KEY,
                        );
                    }
                };
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

    /** @param array<string, string> $query */
    private function request(string $type, string $slug, array $query = [], bool $gpmRead = true): ServerRequestInterface
    {
        $access = $gpmRead
            ? ['api' => ['access' => true, 'gpm' => ['read' => true]]]
            : ['api' => ['access' => true]];

        return TestHelper::createMockRequest(
            method: 'GET',
            path: '/api/v1/gpm/' . $type . '/' . $slug . '/changelog',
            queryParams: $query,
            attributes: [
                'api_user' => TestHelper::createMockUser('auditor', ['access' => $access]),
                'route_params' => ['slug' => $slug],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function body(\Psr\Http\Message\ResponseInterface $response): array
    {
        $this->assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true);
    }

    private function rmrf(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
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
        rmdir($path);
    }
}
