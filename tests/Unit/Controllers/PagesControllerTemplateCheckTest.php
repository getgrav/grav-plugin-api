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
 * The API first refused those requests (422). A developer may be part way
 * through building a template, so it now allows them and answers with a
 * `template_missing` warning instead, and page details carry `template_missing`
 * (see PageSerializerTemplateMissingTest). What stays refused is whatever
 * would write a file Grav can't use: a template that is not a plain name.
 *
 * PATCH also treated a module's own template sent without the `modular/` prefix
 * as a switch, and the "old" file it removed after the save was the module's
 * only file.
 *
 * Follow-up: a route whose last segment carries an order prefix (`01._hero`)
 * escaped the check, because `_` was looked for in front of the prefix. Grav
 * strips the prefix before it decides a page is a module.
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

    /**
     * Pages create() can read back at their routes, as the answer it builds
     * the response from. Only prefix-free routes are ever looked up.
     *
     * @return array<string, PageInterface>
     */
    private function served(string ...$routes): array
    {
        $served = [];
        foreach ($routes as $route) {
            $page = $this->createMock(WritablePageForTemplateCheckTest::class);
            $page->method('route')->willReturn($route);
            $page->method('rawRoute')->willReturn($route);
            $page->method('header')->willReturn((object) []);
            $page->method('children')->willReturn(new \ArrayIterator([]));
            $page->method('parent')->willReturn(null);
            $page->method('media')->willReturn(new class {
                public function all(): array { return []; }
            });
            $page->method('translatedLanguages')->willReturn([]);
            $page->method('untranslatedLanguages')->willReturn([]);
            $served[$route] = $page;
        }

        return $served;
    }

    /**
     * The test Page's save() writes nothing, so what would be written is read
     * off the page each save is announced with.
     *
     * @param list<string> $written filled with `<folder>/<file>`
     */
    private function captureWrites(array &$written): void
    {
        Grav::instance()->addListener('onAdminSave', function ($event) use (&$written): void {
            $written[] = basename(dirname($event['page']->filePath())) . '/' . basename($event['page']->filePath());
        });
    }

    /** @return array<string, mixed> the `data` of a response */
    private function data(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true)['data'];
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
    public function create_allows_a_module_with_no_template_and_warns_that_modular_default_is_missing(): void
    {
        $controller = $this->controller(pages: ['/parent' => $this->parentPage()] + $this->served('/parent/_x'));
        $written = [];
        $this->captureWrites($written);

        $response = $controller->create($this->request([
            'route' => '/parent/x',
            'title' => 'X',
            'kind' => 'module',
        ]));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['_x/default.md'], $written);
        $warnings = $this->data($response)['warnings'];
        self::assertCount(1, $warnings);
        self::assertSame('template', $warnings[0]['field']);
        self::assertSame('template_missing', $warnings[0]['code']);
        self::assertStringContainsString("Template 'modular/default' doesn't exist on this site", $warnings[0]['message']);
        self::assertStringContainsString('modular/hero, modular/text', $warnings[0]['message'], 'the caller is told what it can use');
    }

    #[Test]
    public function create_allows_a_module_whose_template_is_not_a_modular_type_and_warns(): void
    {
        $controller = $this->controller(pages: ['/parent' => $this->parentPage()] + $this->served('/parent/_t'));
        $written = [];
        $this->captureWrites($written);

        $response = $controller->create($this->request([
            'route' => '/parent/t',
            'title' => 'T',
            'kind' => 'module',
            'template' => 'testimonials',
        ]));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['_t/testimonials.md'], $written);
        $warnings = $this->data($response)['warnings'];
        self::assertSame('template', $warnings[0]['field']);
        self::assertSame('template_missing', $warnings[0]['code']);
        self::assertStringContainsString("Template 'modular/testimonials' doesn't exist on this site", $warnings[0]['message']);
        self::assertStringContainsString("'template not found' error", $warnings[0]['message']);
    }

    #[Test]
    public function create_warns_about_a_module_made_by_its_slug_alone(): void
    {
        // What a caller with no `kind` to send does: the `_` makes it a module.
        $controller = $this->controller(pages: ['/parent' => $this->parentPage()] + $this->served('/parent/_t'));
        $written = [];
        $this->captureWrites($written);

        $response = $controller->create($this->request([
            'route' => '/parent/_t',
            'title' => 'T',
            'template' => 'blog',
        ]));

        self::assertSame(['_t/blog.md'], $written);
        self::assertStringContainsString("Template 'modular/blog' doesn't exist", $this->data($response)['warnings'][0]['message']);
    }

    #[Test]
    public function create_warns_about_a_template_header_a_module_cannot_render_with(): void
    {
        $controller = $this->controller(pages: ['/parent' => $this->parentPage()] + $this->served('/parent/_h'));
        $written = [];
        $this->captureWrites($written);

        $response = $controller->create($this->request([
            'route' => '/parent/h',
            'title' => 'H',
            'kind' => 'module',
            'template' => 'text',
            'header' => ['template' => 'modular/nope'],
        ]));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['_h/text.md'], $written);
        $warnings = $this->data($response)['warnings'];
        self::assertCount(1, $warnings, 'the file template is registered, only the header names a missing one');
        self::assertSame('header.template', $warnings[0]['field']);
        self::assertSame('template_missing', $warnings[0]['code']);
        self::assertStringContainsString("'modular/nope', which doesn't exist on this site", $warnings[0]['message']);
    }

    #[Test]
    public function create_does_not_warn_about_the_file_template_when_a_template_header_wins(): void
    {
        // Core renders with the header's template, so a missing file template
        // is not what breaks the page.
        $controller = $this->controller(pages: ['/parent' => $this->parentPage()] + $this->served('/parent/_h'));

        $response = $controller->create($this->request([
            'route' => '/parent/h',
            'title' => 'H',
            'kind' => 'module',
            'template' => 'nope',
            'header' => ['template' => 'modular/hero'],
        ]));

        self::assertSame(201, $response->getStatusCode());
        self::assertArrayNotHasKey('warnings', $this->data($response));
    }

    #[Test]
    public function create_still_refuses_a_template_header_that_is_not_text(): void
    {
        // Core trims the header, so this would not even be a missing template.
        $controller = $this->controller(pages: ['/parent' => $this->parentPage()]);

        $e = $this->refused(fn () => $controller->create($this->request([
            'route' => '/parent/h',
            'title' => 'H',
            'kind' => 'module',
            'template' => 'text',
            'header' => ['template' => ['a']],
        ])));

        self::assertSame('header.template', $e->getValidationErrors()[0]['field']);
        self::assertSame([], $this->listing());
    }

    #[Test]
    public function create_returns_no_warning_for_a_module_whose_template_exists(): void
    {
        $controller = $this->controller(
            twigTemplates: ['modular/lightbox.html.twig'],
            pages: ['/parent' => $this->parentPage()] + $this->served('/parent/_a', '/parent/_b'),
        );

        // Registered, and found only by Twig.
        foreach ([['a', 'hero'], ['b', 'lightbox']] as [$slug, $template]) {
            $response = $controller->create($this->request(['route' => '/parent/' . $slug, 'title' => 'T', 'kind' => 'module', 'template' => $template]));
            self::assertSame(201, $response->getStatusCode());
            self::assertArrayNotHasKey('warnings', $this->data($response), $template);
        }
    }

    #[Test]
    public function create_returns_no_warning_for_an_ordinary_page_with_an_unknown_template(): void
    {
        // An unknown page type falls back to the theme's default template.
        $controller = $this->controller(pages: ['/parent' => $this->parentPage()] + $this->served('/parent/landing'));
        $written = [];
        $this->captureWrites($written);

        $response = $controller->create($this->request(['route' => '/parent/landing', 'title' => 'L', 'template' => 'landing']));

        self::assertSame(['landing/landing.md'], $written);
        self::assertArrayNotHasKey('warnings', $this->data($response));
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

    #[Test]
    public function create_warns_about_a_module_whose_route_carries_an_order_prefix(): void
    {
        // `01._hero` is a module to Grav, which strips the prefix before it
        // looks for the `_`. With no template it used to be written as an
        // ordinary page: `01._hero/default.md`, and its parent rendered the
        // red "modular/default.html.twig not found" heading.
        $controller = $this->controller(pages: ['/parent' => $this->parentPage()] + $this->served('/parent/_hero', '/parent/_t'));
        $written = [];
        $this->captureWrites($written);

        $response = $controller->create($this->request([
            'route' => '/parent/01._hero',
            'title' => 'X',
        ]));

        self::assertStringContainsString("Template 'modular/default' doesn't exist", $this->data($response)['warnings'][0]['message']);

        $response = $controller->create($this->request([
            'route' => '/parent/12._t',
            'title' => 'T',
            'template' => 'testimonials',
        ]));

        self::assertStringContainsString("Template 'modular/testimonials' doesn't exist", $this->data($response)['warnings'][0]['message']);
        self::assertSame(['01._hero/default.md', '12._t/testimonials.md'], $written);
    }

    #[Test]
    public function create_warns_about_a_prefixed_module_whatever_the_width_of_the_prefix(): void
    {
        $controller = $this->controller(pages: ['/parent' => $this->parentPage()] + $this->served('/parent/_a'));

        foreach (['1._a', '001._a', '0._a'] as $segment) {
            $response = $controller->create($this->request([
                'route' => '/parent/' . $segment,
                'title' => 'X',
                'template' => 'nope',
            ]));
            self::assertSame('template_missing', $this->data($response)['warnings'][0]['code'], $segment);
        }
    }

    #[Test]
    public function create_keeps_the_prefix_in_the_folder_and_out_of_the_route(): void
    {
        // create() answers with the page found at the route it was given, so
        // only the prefix-free routes are served.
        $controller = $this->controller(pages: ['/parent' => $this->parentPage()] + $this->served('/parent/_hero', '/parent/_text', '/parent/plain', '/parent/other'));
        $written = [];
        $this->captureWrites($written);

        // A valid module still goes through, with the prefix in its folder name.
        $response = $controller->create($this->request(['route' => '/parent/01._hero', 'title' => 'H', 'template' => 'hero']));
        self::assertSame('/api/v1/pages/parent/_hero', parse_url($response->getHeaderLine('Location'), PHP_URL_PATH));
        self::assertArrayNotHasKey('warnings', $this->data($response));
        // `kind` adds the `_` after the prefix, not in front of it.
        $controller->create($this->request(['route' => '/parent/02.text', 'title' => 'T', 'kind' => 'module', 'template' => 'text']));
        // An ordinary page is written as asked.
        $controller->create($this->request(['route' => '/parent/03.plain', 'title' => 'P']));
        // An `order` in the body wins over the prefix typed into the route.
        $controller->create($this->request(['route' => '/parent/07.other', 'title' => 'O', 'order' => 4]));

        self::assertSame(['01._hero/hero.md', '02._text/text.md', '03.plain/default.md', '04.other/default.md'], $written);
    }

    #[Test]
    public function split_order_prefix_follows_the_pattern_core_strips_it_with(): void
    {
        $controller = $this->controller();

        self::assertSame(['01.', '_hero'], $this->call($controller, 'splitOrderPrefix', '01._hero'));
        self::assertSame(['001.', 'hero'], $this->call($controller, 'splitOrderPrefix', '001.hero'));
        self::assertSame(['', '_hero'], $this->call($controller, 'splitOrderPrefix', '_hero'));
        self::assertSame(['', 'hero'], $this->call($controller, 'splitOrderPrefix', 'hero'));
        // Only a leading run of digits and one dot is a prefix.
        self::assertSame(['01.', '02.hero'], $this->call($controller, 'splitOrderPrefix', '01.02.hero'));
        self::assertSame(['', 'v1.2'], $this->call($controller, 'splitOrderPrefix', 'v1.2'));
        self::assertSame(['', '01'], $this->call($controller, 'splitOrderPrefix', '01'));
        self::assertSame(['01.', ''], $this->call($controller, 'splitOrderPrefix', '01.'));
    }

    #[Test]
    public function create_refuses_a_route_that_is_only_an_order_prefix(): void
    {
        $controller = $this->controller(pages: ['/parent' => $this->parentPage()]);

        $e = $this->refused(fn () => $controller->create($this->request([
            'route' => '/parent/01.',
            'title' => 'X',
        ])));

        self::assertStringContainsString('order prefix but no page name', $e->getMessage());
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
    public function a_module_template_that_does_not_exist_is_returned_not_refused(): void
    {
        $controller = $this->controller();

        self::assertSame('modular/testimonials', $this->call($controller, 'resolveTemplate', 'testimonials', true));
        self::assertSame('modular/testimonials', $this->call($controller, 'resolveTemplate', 'modular/testimonials', true));
        self::assertSame('modular/default', $this->call($controller, 'resolveTemplate', 'default', true));
    }

    #[Test]
    public function a_module_template_still_has_to_be_a_plain_name(): void
    {
        $controller = $this->controller();

        foreach (['', '  ', 'a/b', '..', '.hidden', 'modular/a/b', 'a\\b', 'modular/'] as $template) {
            $e = $this->refused(fn () => $this->call($controller, 'resolveTemplate', $template, true));
            self::assertSame('template', $e->getValidationErrors()[0]['field'], json_encode($template));
        }
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
    public function page_lists_leave_the_template_flag_out(): void
    {
        // A Twig lookup per module row is not worth it in a list. Details
        // (show, create, update) carry the flag.
        $options = $this->call($this->controller(), 'listOptions', $this->request([], [], 'GET'));

        self::assertFalse($options['include_template_state']);
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
    public function update_switches_a_module_to_a_type_that_does_not_exist_and_warns(): void
    {
        $page = $this->pageOnDisk('_good', 'text.md', 'modular/text', true);
        $page->expects(self::once())->method('save');
        $controller = $this->controller(pages: ['/parent/_good' => $page]);

        $response = $controller->update($this->request(['template' => 'testimonials'], ['route' => 'parent/_good'], 'PATCH'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('testimonials.md', $page->name());
        self::assertSame([], $this->listing('parent/_good'), 'the old file is removed once the new one is saved');
        $warnings = $this->data($response)['warnings'];
        self::assertCount(1, $warnings);
        self::assertSame('template', $warnings[0]['field']);
        self::assertSame('template_missing', $warnings[0]['code']);
        self::assertStringContainsString("Template 'modular/testimonials' doesn't exist on this site", $warnings[0]['message']);
        self::assertStringContainsString('modular/hero, modular/text', $warnings[0]['message']);
    }

    #[Test]
    public function update_returns_no_warning_without_a_template_to_warn_about(): void
    {
        $page = $this->pageOnDisk('_good', 'text.md', 'modular/text', true);
        $plain = $this->pageOnDisk('plain', 'default.md', 'default', false);
        $controller = $this->controller(pages: ['/parent/_good' => $page, '/parent/plain' => $plain]);

        // To a registered type, to its own template, and with no template at all.
        foreach ([['parent/_good', ['template' => 'hero']], ['parent/_good', ['template' => 'modular/hero']], ['parent/_good', ['title' => 'T']]] as [$route, $body]) {
            self::assertArrayNotHasKey('warnings', $this->data($controller->update($this->request($body, ['route' => $route], 'PATCH'))), json_encode($body));
        }

        // An ordinary page may switch to a type no theme has.
        $response = $controller->update($this->request(['template' => 'landing'], ['route' => 'parent/plain'], 'PATCH'));
        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey('warnings', $this->data($response));
    }

    #[Test]
    public function update_does_not_warn_about_the_file_template_when_a_template_header_wins(): void
    {
        $page = $this->pageOnDisk('_h', 'text.md', 'modular/hero', true, ['template' => 'modular/hero']);
        $controller = $this->controller(pages: ['/parent/_h' => $page]);

        $response = $controller->update($this->request(['template' => 'nope'], ['route' => 'parent/_h'], 'PATCH'));

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey('warnings', $this->data($response));
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
    public function update_warns_about_a_template_header_only_when_the_request_changes_it(): void
    {
        $page = $this->pageOnDisk('_h', 'text.md', 'modular/nope', true, ['template' => 'modular/nope']);
        $controller = $this->controller(pages: ['/parent/_h' => $page]);

        // The header already holds an unknown template: sending it back (as a
        // raw-frontmatter editor does) is not a change, so nothing is repeated.
        $response = $controller->update($this->request(
            ['header' => ['title' => 'Edited', 'template' => 'modular/nope'], 'header_mode' => 'replace'],
            ['route' => 'parent/_h'],
            'PATCH',
        ));
        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey('warnings', $this->data($response));
        // The page itself still says so.
        self::assertTrue($this->data($response)['template_missing']);

        $response = $controller->update($this->request(
            ['header' => ['template' => 'modular/other']],
            ['route' => 'parent/_h'],
            'PATCH',
        ));
        self::assertSame(200, $response->getStatusCode());
        $warnings = $this->data($response)['warnings'];
        self::assertSame('header.template', $warnings[0]['field']);
        self::assertSame('template_missing', $warnings[0]['code']);
        self::assertStringContainsString("'modular/other', which doesn't exist", $warnings[0]['message']);

        // A header that names a registered type is fine.
        $response = $controller->update($this->request(
            ['header' => ['template' => 'modular/hero']],
            ['route' => 'parent/_h'],
            'PATCH',
        ));
        self::assertArrayNotHasKey('warnings', $this->data($response));
    }

    #[Test]
    public function update_still_refuses_a_template_that_is_not_a_plain_name(): void
    {
        $page = $this->pageOnDisk('_good', 'text.md', 'modular/text', true);
        $page->expects(self::never())->method('save');
        $controller = $this->controller(pages: ['/parent/_good' => $page]);

        foreach (['a/b', '.hidden', '', ['a']] as $template) {
            $e = $this->refused(fn () => $controller->update(
                $this->request(['template' => $template], ['route' => 'parent/_good'], 'PATCH')
            ));
            self::assertSame('template', $e->getValidationErrors()[0]['field'], json_encode($template));
        }
        self::assertSame(['text.md'], $this->listing('parent/_good'));
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
