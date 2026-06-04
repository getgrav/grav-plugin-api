<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Grav;
use Grav\Plugin\Api\Services\ConfigDiffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The override-map primitives behind the per-field override indicators and the
 * revert endpoint (see docs/config-overrides-revert): flatten a persisted delta
 * to dotted leaf paths, read the fallback value out of the parent layer, and
 * remove a key from the active layer's file.
 */
class ConfigDifferOverrideTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    #[Test]
    public function flatten_leaves_recurses_maps_but_keeps_lists_atomic(): void
    {
        $delta = [
            'pages' => ['theme' => 'quark2', 'events' => ['page' => false]],
            'types' => ['html', 'htm', 'xml'],   // sequential list — atomic
            'debugger' => ['enabled' => true],
        ];

        $this->assertSame(
            ['pages.theme', 'pages.events.page', 'types', 'debugger.enabled'],
            ConfigDiffer::flattenLeaves($delta),
        );
    }

    #[Test]
    public function flatten_leaves_is_empty_for_no_overrides(): void
    {
        $this->assertSame([], ConfigDiffer::flattenLeaves([]));
    }

    #[Test]
    public function value_at_path_digs_nested_keys(): void
    {
        $parent = ['github' => ['app_id' => '3771292'], 'kimi' => ['api_key' => 'sk-A7']];

        $this->assertSame('3771292', ConfigDiffer::valueAtPath($parent, 'github.app_id'));
        $this->assertSame('sk-A7', ConfigDiffer::valueAtPath($parent, 'kimi.api_key'));
    }

    #[Test]
    public function value_at_path_returns_null_when_absent(): void
    {
        // A key the active layer adds but the parent never had reverts to the
        // blueprint default / unset, which the client renders as "empty".
        $parent = ['github' => ['app_id' => '3771292']];

        $this->assertNull(ConfigDiffer::valueAtPath($parent, 'github.missing'));
        $this->assertNull(ConfigDiffer::valueAtPath($parent, 'nope.at.all'));
    }

    #[Test]
    public function unset_dot_path_removes_a_key_and_prunes_empty_parents(): void
    {
        $differ = new ConfigDiffer(Grav::instance());

        $delta = ['github' => ['app_id' => '3726627', 'secret' => 'x'], 'kimi' => ['api_key' => '123']];

        // Removing one of two siblings keeps the parent.
        $this->assertSame(
            ['github' => ['secret' => 'x'], 'kimi' => ['api_key' => '123']],
            $differ->unsetDotPath($delta, 'github.app_id'),
        );

        // Removing the last child prunes the now-empty parent map entirely.
        $this->assertSame(
            ['github' => ['app_id' => '3726627', 'secret' => 'x']],
            $differ->unsetDotPath($delta, 'kimi.api_key'),
        );
    }

    #[Test]
    public function unset_dot_path_is_a_noop_for_absent_keys(): void
    {
        $differ = new ConfigDiffer(Grav::instance());
        $delta = ['github' => ['app_id' => '3726627']];

        $this->assertSame($delta, $differ->unsetDotPath($delta, 'kimi.api_key'));
        $this->assertSame($delta, $differ->unsetDotPath($delta, 'github.nope'));
    }
}
