<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;

/**
 * Regression coverage for getgrav/grav-plugin-api#55.
 *
 * POST /pages wrote a module with any `template` it was given, and with
 * `modular/default` when it was given none. PATCH /pages/{route} switched a
 * module to any template too. A module whose template Twig cannot find makes
 * its parent render core's red "template not found" heading to visitors.
 *
 * PATCH also treated a module's own template sent without the `modular/` prefix
 * as a switch, and the "old" file it removed after the save was the module's
 * only file.
 */
#[CoversClass(PagesController::class)]
class PagesControllerTemplateCheckTest extends TestCase
{
    private const MODULAR = ['modular/hero' => 'Hero', 'modular/text' => 'Text'];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/grav-api-template-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/parent', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
        Grav::resetInstance();
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @return list<string> */
    private function listing(string $folder = 'parent'): array
    {
        return array_values(array_diff(scandir($this->dir . '/' . $folder) ?: [], ['.', '..']));
    }

    /**
     * @param array<string, string>|null $modular the registered modular types, null for a site whose registry cannot be asked
     * @param list<string> $twigTemplates templates only Twig knows (a plugin's Twig path)
     * @param array<string, PageInterface> $pages pages served by find()
     */
    private function controller(?array $modular = self::MODULAR, array $twigTemplates = [], array $pages = []): PagesController
    {
        $config = new Config([
            'plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']],
        ]);

        $pagesService = $modular === null
            ? new class ($pages) {
                /** @param array<string, PageInterface> $found */
                public function __construct(private readonly array $found) {}

                public function enablePages(): void {}

                public function reset(): void {}

                public function markChanged(): void {}

                public function find(string $route): ?PageInterface
                {
                    return $this->found[$route] ?? null;
                }
            }
            : new class ($pages, $modular) {
                /** @var array<string, string> */
                private static array $modular = [];

                /**
                 * @param array<string, PageInterface> $found
                 * @param array<string, string> $modular
                 */
                public function __construct(private readonly array $found, array $modular)
                {
                    self::$modular = $modular;
                }

                public function enablePages(): void {}

                public function reset(): void {}

                public function markChanged(): void {}

                public function find(string $route): ?PageInterface
                {
                    return $this->found[$route] ?? null;
                }

                // Static, as on core's Pages.
                public static function types(): array
                {
                    return ['blog' => 'Blog', 'default' => 'Default', 'modular' => 'Modular'];
                }

                public static function modularTypes(): array
                {
                    return self::$modular;
                }
            };

        $language = new class {
            public function getActive(): ?string { return null; }

            public function enabled(): bool { return false; }

            public function resetFallbackPageExtensions(): void {}
        };

        $locator = new class ($this->dir) {
            public function __construct(private readonly string $base) {}

            public function findResource(string $uri, bool $absolute = false): string
            {
                return str_starts_with($uri, 'page://') ? $this->base : $this->base . '/cache';
            }
        };

        $twig = new class ($twigTemplates) {
            public int $inits = 0;

            /** @param list<string> $templates */
            public function __construct(private readonly array $templates) {}

            public function init(): void
            {
                $this->inits++;
            }

            public function twig(): object
            {
                return $this;
            }

            public function getLoader(): object
            {
                return $this;
            }

            public function exists(string $name): bool
            {
                return in_array($name, $this->templates, true);
            }
        };

        $events = new class {
            public function dispatch(object $event, ?string $name = null): object
            {
                return $event;
            }
        };

        return new PagesController(TestHelper::createMockGrav([
            'config' => $config,
            'pages' => $pagesService,
            'language' => $language,
            'locator' => $locator,
            'twig' => $twig,
            'events' => $events,
        ]), $config);
    }

    private function parentPage(): PageInterface
    {
        $parent = $this->createMock(PageInterface::class);
        $parent->method('path')->willReturn($this->dir . '/parent');
        $parent->method('route')->willReturn('/parent');
        $parent->method('rawRoute')->willReturn('/parent');
        $parent->method('children')->willReturn(new \ArrayIterator([]));
        $parent->method('header')->willReturn((object) ['title' => 'Parent']);

        return $parent;
    }

    /**
     * A page on disk at `<dir>/parent/<folder>/<file>`, as update() sees it.
     * name() moves filePath() the way a real page does, and save() is a no-op.
     */
    private function pageOnDisk(string $folder, string $file, string $template, bool $module, array $header = []): PageInterface
    {
        $path = $this->dir . '/parent/' . $folder;
        mkdir($path, 0775, true);
        file_put_contents($path . '/' . $file, "---\ntitle: Existing\n---\nbody\n");

        $name = $file;
        $page = $this->createMock(WritablePageForTemplateCheckTest::class);
        $page->method('path')->willReturn($path);
        $page->method('route')->willReturn('/parent/' . $folder);
        $page->method('rawRoute')->willReturn('/parent/' . $folder);
        $page->method('slug')->willReturn($folder);
        $page->method('isModule')->willReturn($module);
        $page->method('template')->willReturn($template);
        $page->method('header')->willReturn((object) (['title' => 'Existing'] + $header));
        $page->method('children')->willReturn(new \ArrayIterator([]));
        $page->method('parent')->willReturn(null);
        $page->method('media')->willReturn(new class {
            public function all(): array { return []; }
        });
        $page->method('translatedLanguages')->willReturn([]);
        $page->method('untranslatedLanguages')->willReturn([]);
        $page->method('name')->willReturnCallback(static function ($var = null) use (&$name) {
            if ($var !== null) {
                $name = $var;
            }

            return $name;
        });
        $page->method('filePath')->willReturnCallback(static function () use (&$name, $path) {
            return $path . '/' . $name;
        });

        return $page;
    }

    private function request(array $body, array $routeParams = [], string $method = 'POST'): ServerRequestInterface
    {
        $superAdmin = TestHelper::createMockUser('admin', ['access' => ['api' => ['super' => true]]]);

        return TestHelper::createMockRequest(
            method: $method,
            path: '/api/v1/pages',
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body),
            attributes: [
                'api_user' => $superAdmin,
                'json_body' => $body,
                'route_params' => $routeParams,
            ],
        );
    }

    private function call(PagesController $controller, string $method, mixed ...$args): mixed
    {
        return (new ReflectionClass(PagesController::class))->getMethod($method)->invoke($controller, ...$args);
    }

    private function refused(callable $do): ValidationException
    {
        try {
            $do();
        } catch (ValidationException $e) {
            return $e;
        }

        self::fail('The request was accepted.');
    }

    // -------------------------------------------------------
    // POST /pages
    // -------------------------------------------------------

    #[Test]
    public function create_refuses_a_module_with_no_template_instead_of_writing_modular_default(): void
    {
        $controller = $this->controller(pages: ['/parent' => $this->parentPage()]);

        $e = $this->refused(fn () => $controller->create($this->request([
            'route' => '/parent/x',
            'title' => 'X',
            'kind' => 'module',
        ])));

        self::assertStringContainsString("A module needs a 'template'", $e->getMessage());
        self::assertStringContainsString('modular/hero, modular/text', $e->getMessage(), 'the caller is told what it can use');
        self::assertSame('template', $e->getValidationErrors()[0]['field']);
        self::assertSame([], $this->listing(), 'nothing may be written');
    }

    #[Test]
    public function create_refuses_a_module_whose_template_is_not_a_modular_type(): void
    {
        $controller = $this->controller(pages: ['/parent' => $this->parentPage()]);

        $e = $this->refused(fn () => $controller->create($this->request([
            'route' => '/parent/t',
            'title' => 'T',
            'kind' => 'module',
            'template' => 'testimonials',
        ])));

        self::assertStringContainsString("'modular/testimonials' is not a modular type", $e->getMessage());
        self::assertSame(422, $e->getStatusCode());
        self::assertSame([], $this->listing());
    }

    #[Test]
    public function create_checks_a_module_made_by_its_slug_alone(): void
    {
        // What a caller with no `kind` to send does: the `_` makes it a module.
        $controller = $this->controller(pages: ['/parent' => $this->parentPage()]);

        $e = $this->refused(fn () => $controller->create($this->request([
            'route' => '/parent/_t',
            'title' => 'T',
            'template' => 'blog',
        ])));

        self::assertStringContainsString("'modular/blog' is not a modular type", $e->getMessage());
        self::assertSame([], $this->listing());
    }

    #[Test]
    public function create_refuses_a_template_header_a_module_cannot_render_with(): void
    {
        $controller = $this->controller(pages: ['/parent' => $this->parentPage()]);

        $e = $this->refused(fn () => $controller->create($this->request([
            'route' => '/parent/h',
            'title' => 'H',
            'kind' => 'module',
            'template' => 'text',
            'header' => ['template' => 'modular/nope'],
        ])));

        self::assertSame('header.template', $e->getValidationErrors()[0]['field']);
        self::assertSame([], $this->listing());
    }

    #[Test]
    public function create_refuses_a_template_that_is_not_a_plain_name(): void
    {
        $controller = $this->controller(pages: ['/parent' => $this->parentPage()]);

        foreach (['', '   ', ['a'], '../x', 'a/b', '.hidden'] as $template) {
            $e = $this->refused(fn () => $controller->create($this->request([
                'route' => '/parent/page',
                'title' => 'Page',
                'template' => $template,
            ])));
            self::assertSame('template', $e->getValidationErrors()[0]['field'], json_encode($template));
        }

        // `modular/text` on an ordinary page used to be written as `text.md`.
        $e = $this->refused(fn () => $controller->create($this->request([
            'route' => '/parent/page',
            'title' => 'Page',
            'template' => 'modular/text',
        ])));
        self::assertStringContainsString("kind 'module'", $e->getMessage());
        self::assertSame([], $this->listing());
    }

    // -------------------------------------------------------
    // What the check accepts
    // -------------------------------------------------------

    #[Test]
    public function a_module_template_is_accepted_in_either_spelling_and_returned_as_registered(): void
    {
        $controller = $this->controller();

        self::assertSame('modular/text', $this->call($controller, 'resolveTemplate', 'text', true));
        self::assertSame('modular/text', $this->call($controller, 'resolveTemplate', 'modular/text', true));
        self::assertSame('modular/hero', $this->call($controller, 'resolveTemplate', 'Hero', true), 'the registered spelling wins');
    }

    #[Test]
    public function a_module_template_only_twig_knows_is_accepted(): void
    {
        // A plugin that adds a Twig path without registering its types.
        $controller = $this->controller(twigTemplates: ['modular/lightbox.html.twig']);

        self::assertSame('modular/lightbox', $this->call($controller, 'resolveTemplate', 'lightbox', true));

        $e = $this->refused(fn () => $this->call($controller, 'resolveTemplate', 'gallery', true));
        self::assertStringContainsString("'modular/gallery'", $e->getMessage());
    }

    #[Test]
    public function cores_own_modular_default_template_does_not_count(): void
    {
        // Twig always finds `modular/default.html.twig`: core ships it, and it
        // is the "template not found" heading the check exists to prevent.
        $controller = $this->controller(twigTemplates: ['modular/default.html.twig']);

        $e = $this->refused(fn () => $this->call($controller, 'resolveTemplate', 'default', true, false));
        self::assertStringContainsString("A module needs a 'template'", $e->getMessage());

        // A theme with its own `templates/modular/default.html.twig` registers it.
        $themed = $this->controller(self::MODULAR + ['modular/default' => 'Default']);
        self::assertSame('modular/default', $this->call($themed, 'resolveTemplate', 'default', true, false));
    }

    #[Test]
    public function twig_is_only_asked_when_the_type_is_not_registered(): void
    {
        $controller = $this->controller();
        $this->call($controller, 'resolveTemplate', 'text', true);

        self::assertSame(0, Grav::instance()['twig']->inits);
    }

    #[Test]
    public function an_ordinary_page_keeps_any_type_name(): void
    {
        // An unknown page type falls back to the theme's default template, and
        // a headless site may use types no theme knows.
        $controller = $this->controller();

        self::assertSame('landing', $this->call($controller, 'resolveTemplate', 'landing', false));
        self::assertSame('default', $this->call($controller, 'resolveTemplate', 'default', false, false));
    }

    #[Test]
    public function a_site_with_no_modular_types_says_so(): void
    {
        $e = $this->refused(fn () => $this->call($this->controller([]), 'resolveTemplate', 'default', true, false));

        self::assertStringContainsString("A module needs a 'template'", $e->getMessage());
        self::assertStringContainsString('no modular types', $e->getMessage());
    }

    #[Test]
    public function nothing_is_refused_when_the_type_registry_cannot_be_asked(): void
    {
        $controller = $this->controller(null);

        self::assertSame('modular/anything', $this->call($controller, 'resolveTemplate', 'anything', true));
    }

    // -------------------------------------------------------
    // PATCH /pages/{route}
    // -------------------------------------------------------

    #[Test]
    public function update_keeps_the_file_when_a_module_is_sent_its_own_template_without_the_prefix(): void
    {
        $page = $this->pageOnDisk('_good', 'text.md', 'modular/text', true);
        $controller = $this->controller(pages: ['/parent/_good' => $page]);

        $response = $controller->update($this->request(['template' => 'text'], ['route' => 'parent/_good'], 'PATCH'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['text.md'], $this->listing('parent/_good'), 'the page file was deleted');
    }

    #[Test]
    public function update_refuses_to_switch_a_module_to_a_type_that_does_not_exist(): void
    {
        $page = $this->pageOnDisk('_good', 'text.md', 'modular/text', true);
        $page->expects(self::never())->method('save');
        $controller = $this->controller(pages: ['/parent/_good' => $page]);

        $e = $this->refused(fn () => $controller->update(
            $this->request(['template' => 'testimonials'], ['route' => 'parent/_good'], 'PATCH')
        ));

        self::assertStringContainsString("'modular/testimonials' is not a modular type", $e->getMessage());
        self::assertSame(['text.md'], $this->listing('parent/_good'));
    }

    #[Test]
    public function update_still_switches_a_module_to_a_registered_type(): void
    {
        $page = $this->pageOnDisk('_good', 'text.md', 'modular/text', true);
        $page->expects(self::once())->method('save');
        $controller = $this->controller(pages: ['/parent/_good' => $page]);

        $controller->update($this->request(['template' => 'hero'], ['route' => 'parent/_good'], 'PATCH'));

        self::assertSame('hero.md', $page->name());
        self::assertSame([], $this->listing('parent/_good'), 'the old file is removed once the new one is saved');
    }

    #[Test]
    public function update_saves_a_page_whose_unregistered_template_is_sent_back_unchanged(): void
    {
        // Content that predates a theme switch has to stay editable.
        $module = $this->pageOnDisk('_old', 'child-lister.md', 'modular/child-lister', true);
        $plain = $this->pageOnDisk('old', 'landing.md', 'landing', false);
        $controller = $this->controller(pages: ['/parent/_old' => $module, '/parent/old' => $plain]);

        foreach ([['parent/_old', 'modular/child-lister'], ['parent/_old', 'child-lister'], ['parent/old', 'landing']] as [$route, $template]) {
            $response = $controller->update($this->request(['template' => $template, 'title' => 'Edited'], ['route' => $route], 'PATCH'));
            self::assertSame(200, $response->getStatusCode(), $route . ' ' . $template);
        }

        self::assertSame(['child-lister.md'], $this->listing('parent/_old'));
        self::assertSame(['landing.md'], $this->listing('parent/old'));
    }

    #[Test]
    public function update_removes_the_file_the_page_was_loaded_from_when_a_template_header_hides_its_name(): void
    {
        // `text.md` with a `template: modular/gallery` header reports
        // `modular/gallery`. The old path used to be built from that, so
        // `text.md` was left behind beside the new file.
        $page = $this->pageOnDisk('_h', 'text.md', 'modular/gallery', true, ['template' => 'modular/gallery']);
        $controller = $this->controller(pages: ['/parent/_h' => $page]);

        $controller->update($this->request(['template' => 'modular/hero'], ['route' => 'parent/_h'], 'PATCH'));

        self::assertSame('hero.md', $page->name());
        self::assertSame([], $this->listing('parent/_h'), 'text.md was left beside the new file');
    }

    #[Test]
    public function update_keeps_the_file_when_the_switch_saves_to_the_file_it_was_loaded_from(): void
    {
        // The same page asked for the type its file already has.
        $page = $this->pageOnDisk('_h', 'text.md', 'modular/gallery', true, ['template' => 'modular/gallery']);
        $controller = $this->controller(pages: ['/parent/_h' => $page]);

        $controller->update($this->request(['template' => 'modular/text'], ['route' => 'parent/_h'], 'PATCH'));

        self::assertSame('text.md', $page->name());
        self::assertSame(['text.md'], $this->listing('parent/_h'));
    }

    #[Test]
    public function update_checks_a_template_header_only_when_the_request_changes_it(): void
    {
        $page = $this->pageOnDisk('_h', 'text.md', 'modular/nope', true, ['template' => 'modular/nope']);
        $controller = $this->controller(pages: ['/parent/_h' => $page]);

        // The header already holds an unknown template: sending it back (as a
        // raw-frontmatter editor does) is not a change.
        $response = $controller->update($this->request(
            ['header' => ['title' => 'Edited', 'template' => 'modular/nope'], 'header_mode' => 'replace'],
            ['route' => 'parent/_h'],
            'PATCH',
        ));
        self::assertSame(200, $response->getStatusCode());

        $e = $this->refused(fn () => $controller->update($this->request(
            ['header' => ['template' => 'modular/other']],
            ['route' => 'parent/_h'],
            'PATCH',
        )));
        self::assertSame('header.template', $e->getValidationErrors()[0]['field']);
    }
}

/**
 * The test stubs' PageInterface doesn't declare the methods update() uses to
 * rename and save a page, which the real page classes carry; this adds them so
 * they can be mocked.
 */
abstract class WritablePageForTemplateCheckTest implements PageInterface
{
    abstract public function name($var = null);

    abstract public function filePath($var = null);

    abstract public function save($reorder = true);

    // Read by PageSerializer for the response.
    abstract public function rawMarkdown($var = null);

    abstract public function media($var = null);

    abstract public function translatedLanguages($onlyPublished = false);

    abstract public function untranslatedLanguages($includeUnpublished = false);
}
