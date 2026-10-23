<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Plugin\Api\Services\ModuleTypeTitles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Add Module list showed each type's file name made readable, never the
 * title its blueprint gives it. A type is now named after its own blueprint,
 * and keeps the readable file name when it has none.
 */
#[CoversClass(ModuleTypeTitles::class)]
class ModuleTypeTitlesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/grav-api-module-titles-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/theme/modular', 0775, true);
        mkdir($this->dir . '/system', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (['theme/modular', 'system'] as $folder) {
            array_map('unlink', glob($this->dir . '/' . $folder . '/*') ?: []);
        }
        @rmdir($this->dir . '/theme/modular');
        @rmdir($this->dir . '/theme');
        @rmdir($this->dir . '/system');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function file(string $path, string $yaml): string
    {
        file_put_contents($this->dir . '/' . $path, $yaml);

        return $this->dir . '/' . $path;
    }

    /** @param array<string, list<string>> $registry type => blueprint files, highest priority first */
    private function titles(array $registry, ?\Closure $translate = null): ModuleTypeTitles
    {
        return new ModuleTypeTitles(
            fn (string $type): array => $registry[$type] ?? [],
            $translate ?? fn (string $label): string => $label,
            fn (string $url): ?string => $this->dir . '/' . substr($url, strlen('theme://')),
        );
    }

    #[Test]
    public function a_type_takes_the_title_of_its_own_blueprint(): void
    {
        $hero = $this->file('theme/modular/hero.yaml', "title: Big opening\nform:\n  fields: {}\n");
        $titles = $this->titles(['modular/hero' => [$hero]]);

        self::assertSame(['modular/hero' => 'Big opening'], $titles->apply(['modular/hero' => 'Hero']));
    }

    #[Test]
    public function a_type_without_a_title_keeps_its_readable_file_name(): void
    {
        $facts = $this->file('theme/modular/facts.yaml', "extends@: default\nform:\n  fields: {}\n");
        $blank = $this->file('theme/modular/blank.yaml', "title: '  '\n");
        $titles = $this->titles(['modular/facts' => [$facts], 'modular/blank' => [$blank]]);

        self::assertSame(
            ['modular/facts' => 'Facts', 'modular/blank' => 'Blank', 'modular/other' => 'Other'],
            $titles->apply(['modular/facts' => 'Facts', 'modular/blank' => 'Blank', 'modular/other' => 'Other']),
        );
    }

    #[Test]
    public function a_template_with_no_blueprint_is_not_named_after_cores_default(): void
    {
        // Core registers a template-only type with its own default.yaml.
        $default = $this->file('system/default.yaml', "title: Default\n");
        $titles = $this->titles(['modular/gallery' => [$default]]);

        self::assertSame(['modular/gallery' => 'Gallery'], $titles->apply(['modular/gallery' => 'Gallery']));
    }

    #[Test]
    public function the_highest_priority_file_of_the_types_name_wins(): void
    {
        $theme = $this->file('theme/modular/hero.yaml', "title: Theme hero\n");
        $plugin = $this->file('system/hero.yaml', "title: Plugin hero\n");
        $titles = $this->titles(['modular/hero' => [$theme, $plugin]]);

        self::assertSame('Theme hero', $titles->title('modular/hero'));
    }

    #[Test]
    public function a_stream_url_is_resolved_and_a_language_key_translated(): void
    {
        $this->file('theme/modular/hero.yaml', "title: THEME_ENCORE.MODULE.HERO\n");
        $titles = $this->titles(
            ['modular/hero' => ['theme://theme/modular/hero.yaml']],
            fn (string $label): string => $label === 'THEME_ENCORE.MODULE.HERO' ? 'Opening' : $label,
        );

        self::assertSame('Opening', $titles->title('modular/hero'));
    }

    #[Test]
    public function a_broken_or_missing_blueprint_keeps_the_label(): void
    {
        $broken = $this->file('theme/modular/broken.yaml', "title: [unclosed\n");
        $titles = $this->titles([
            'modular/broken' => [$broken],
            'modular/gone' => [$this->dir . '/theme/modular/gone.yaml'],
        ]);

        self::assertSame(
            ['modular/broken' => 'Broken', 'modular/gone' => 'Gone'],
            $titles->apply(['modular/broken' => 'Broken', 'modular/gone' => 'Gone']),
        );
    }
}
