<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Grav;
use Grav\Plugin\Api\Services\IconSets;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The iconpicker field only listed Font Awesome, which themes that draw their
 * own SVG icons never load. A theme now registers its icons as a set on
 * `onApiIconSets`, and a field names the set with `icons: <set>`.
 */
#[CoversClass(IconSets::class)]
class IconSetsTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
        parent::tearDown();
    }

    /** @param array<string, mixed> $sets */
    private function sets(array $sets, int &$fired = 0): IconSets
    {
        $grav = TestHelper::createMockGrav();
        $grav->addListener('onApiIconSets', function ($event) use ($sets, &$fired): void {
            $fired++;
            $event['sets'] = $sets + $event['sets'];
        });

        return new IconSets($grav);
    }

    #[Test]
    public function a_themes_inline_markup_becomes_structured_svg(): void
    {
        $spec = IconSets::fromMarkup('<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>');

        self::assertSame([
            'type' => 'svg',
            'viewBox' => '0 0 24 24',
            'elements' => [
                ['tag' => 'circle', 'attrs' => ['cx' => '11', 'cy' => '11', 'r' => '7']],
                ['tag' => 'path', 'attrs' => ['d' => 'M20 20l-3.5-3.5']],
            ],
        ], $spec);
    }

    #[Test]
    public function a_whole_svg_keeps_its_view_box_and_loses_everything_unsafe(): void
    {
        $spec = IconSets::fromMarkup(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" onload="alert(1)">'
            . '<script>alert(1)</script><!-- note -->'
            . '<g transform="scale(2)"><rect x="1" y="2" width="3" height="4" rx="1" onclick="x()" style="fill:red"/></g>'
            . '<foreignObject><div>hi</div></foreignObject>'
            . '</svg>'
        );

        self::assertSame([
            'type' => 'svg',
            'viewBox' => '0 0 16 16',
            'elements' => [
                ['tag' => 'rect', 'attrs' => ['x' => '1', 'y' => '2', 'rx' => '1', 'width' => '3', 'height' => '4']],
            ],
        ], $spec);
    }

    #[Test]
    public function markup_with_nothing_to_draw_or_that_does_not_parse_is_dropped(): void
    {
        self::assertNull(IconSets::fromMarkup('<script>alert(1)</script>'));
        self::assertNull(IconSets::fromMarkup('<path d="M0 0"'));
        self::assertNull(IconSets::fromMarkup('<div><p>text</p></div>'));
        self::assertNull(IconSets::spec(''));
        self::assertNull(IconSets::spec(['d' => 'M0 0']));
        self::assertNull(IconSets::spec(42));
    }

    #[Test]
    public function an_icon_spec_passes_through(): void
    {
        self::assertSame('fa:clock', IconSets::spec('fa:clock'));
        $svg = ['type' => 'svg', 'viewBox' => '0 0 24 24', 'path' => 'M1 1h2'];
        self::assertSame($svg, IconSets::spec($svg));
    }

    #[Test]
    public function a_registered_set_is_listed_in_order_and_the_event_fires_once(): void
    {
        $fired = 0;
        $sets = $this->sets([
            'encore' => [
                'label' => 'Encore',
                'icons' => [
                    'search' => '<circle cx="11" cy="11" r="7"/>',
                    'broken' => '<script/>',
                    'clock' => 'fa:clock',
                ],
            ],
        ], $fired);

        $set = $sets->get('encore');
        self::assertSame('encore', $set['name']);
        self::assertSame('Encore', $set['label']);
        self::assertSame(['search', 'clock'], array_column($set['icons'], 'name'), 'an icon that cannot be drawn is left out');
        self::assertSame('fa:clock', $set['icons'][1]['icon']);

        $sets->get('encore');
        $sets->get('other');
        self::assertSame(1, $fired);
    }

    #[Test]
    public function an_unknown_or_empty_set_is_null(): void
    {
        $sets = $this->sets(['empty' => ['icons' => ['x' => '<script/>']], 'odd' => 'not a set']);

        self::assertNull($sets->get('missing'));
        self::assertNull($sets->get('empty'));
        self::assertNull($sets->get('odd'));
        self::assertNull($sets->get(' '));
    }

    /**
     * A set whose `file` goes through a fake `theme://` stream: the stream's
     * folder is `$base`, and `theme://x` is `$base/x` whatever `x` is, so a
     * traversal or a symlink reaches the real file unless the reader stops it.
     *
     * @param array<string, mixed> $set
     */
    private function setFromStream(string $base, array $set): ?array
    {
        $grav = TestHelper::createMockGrav();
        $grav->addListener('onApiIconSets', function ($event) use ($set): void {
            $event['sets'] = ['shop' => $set] + $event['sets'];
        });
        $resolve = static fn (string $url): ?string => str_starts_with($url, 'theme://')
            ? rtrim($base . '/' . substr($url, strlen('theme://')), '/')
            : null;

        return (new IconSets($grav, $resolve))->get('shop');
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/grav-api-icons-' . bin2hex(random_bytes(6));
        mkdir($dir . '/theme', 0777, true);

        return realpath($dir);
    }

    private function removeDir(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isLink() || $f->isFile() ? unlink($f->getPathname()) : rmdir($f->getPathname());
        }
        rmdir($dir);
    }

    #[Test]
    public function a_set_can_come_from_a_yaml_file_in_a_stream(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/theme/icons.yaml', "bag: '<path d=\"M6 8h12l-1 12H7z\"/>'\nstar: 'fa:star'\n");
        file_put_contents($dir . '/theme/more.yml', "moon: 'fa:moon'\n");

        try {
            $set = $this->setFromStream($dir . '/theme', ['file' => 'theme://icons.yaml']);
            self::assertSame('shop', $set['label'], 'a set without a label is called by its name');
            self::assertSame(['bag', 'star'], array_column($set['icons'], 'name'));
            self::assertSame('svg', $set['icons'][0]['icon']['type']);

            $set = $this->setFromStream($dir . '/theme', ['file' => 'theme://more.yml']);
            self::assertSame(['moon'], array_column($set['icons'], 'name'), '.yml reads like .yaml');
        } finally {
            $this->removeDir($dir);
        }
    }

    #[Test]
    public function a_file_that_is_not_a_stream_url_is_refused(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/theme/icons.yaml', "star: 'fa:star'\n");

        try {
            foreach ([
                $dir . '/theme/icons.yaml',               // absolute path
                'theme/icons.yaml',                       // relative path
                'file://' . $dir . '/theme/icons.yaml',   // a scheme that is not a Grav stream
                'cache://icons.yaml',                     // a Grav stream that is not allowed
            ] as $file) {
                self::assertNull($this->setFromStream($dir . '/theme', ['file' => $file]), $file);
            }
        } finally {
            $this->removeDir($dir);
        }
    }

    #[Test]
    public function a_stream_url_cannot_climb_out_of_its_folder(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/outside.yaml', "star: 'fa:star'\n");
        file_put_contents($dir . '/theme/icons.yaml', "star: 'fa:star'\n");

        try {
            // The fake stream resolves these to a real file, so only the reader's
            // own check keeps them out.
            self::assertNotNull($this->setFromStream($dir . '/theme', ['file' => 'theme://icons.yaml']));
            foreach (['theme://../outside.yaml', 'theme://sub/../../outside.yaml', 'theme://..\\outside.yaml'] as $file) {
                self::assertNull($this->setFromStream($dir . '/theme', ['file' => $file]), $file);
            }
        } finally {
            $this->removeDir($dir);
        }
    }

    #[Test]
    public function a_symlink_cannot_lead_out_of_the_stream_and_only_yaml_is_read(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/outside.yaml', "star: 'fa:star'\n");
        file_put_contents($dir . '/theme/icons.txt', "star: 'fa:star'\n");
        symlink($dir . '/outside.yaml', $dir . '/theme/link.yaml');

        try {
            self::assertNull($this->setFromStream($dir . '/theme', ['file' => 'theme://link.yaml']), 'a symlink out of the folder');
            self::assertNull($this->setFromStream($dir . '/theme', ['file' => 'theme://icons.txt']), 'not a YAML file');
            self::assertNull($this->setFromStream($dir . '/theme', ['file' => 'theme://missing.yaml']), 'no such file');
        } finally {
            $this->removeDir($dir);
        }
    }
}
