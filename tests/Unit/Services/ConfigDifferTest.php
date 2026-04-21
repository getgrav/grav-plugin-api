<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Grav;
use Grav\Plugin\Api\Services\ConfigDiffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConfigDiffer.
 *
 * The {@see ConfigDiffer::diff()} and {@see ConfigDiffer::deepMergeAssoc()}
 * methods are pure and don't touch Grav services — we pass a throwaway Grav
 * instance just to satisfy the constructor. {@see ConfigDiffer::parent()}
 * covers filesystem resolution in its own test with a real tempdir.
 */
class ConfigDifferTest extends TestCase
{
    private ConfigDiffer $differ;

    protected function setUp(): void
    {
        Grav::resetInstance();
        $this->differ = new ConfigDiffer(Grav::instance());
    }

    // ---------- diff() ----------

    #[Test]
    public function diff_returns_empty_when_current_matches_parent(): void
    {
        $parent  = ['force_ssl' => false, 'languages' => ['supported' => ['en', 'fr']]];
        $current = ['force_ssl' => false, 'languages' => ['supported' => ['en', 'fr']]];

        $this->assertSame([], $this->differ->diff($current, $parent));
    }

    #[Test]
    public function diff_includes_scalar_overrides(): void
    {
        $parent  = ['force_ssl' => false, 'timezone' => null];
        $current = ['force_ssl' => true,  'timezone' => null];

        $this->assertSame(['force_ssl' => true], $this->differ->diff($current, $parent));
    }

    #[Test]
    public function diff_recurses_into_associative_arrays(): void
    {
        $parent = [
            'pages' => ['theme' => 'quark', 'markdown' => ['extra' => false]],
        ];
        $current = [
            'pages' => ['theme' => 'quark2', 'markdown' => ['extra' => false]],
        ];

        $this->assertSame(
            ['pages' => ['theme' => 'quark2']],
            $this->differ->diff($current, $parent),
        );
    }

    #[Test]
    public function diff_treats_sequential_arrays_as_atomic(): void
    {
        // The classic "shortened list" scenario: user removes one language from
        // the list. We must emit the whole shortened list, not a key-diff that
        // would silently merge the removed entry back in when Grav re-loads.
        $parent  = ['languages' => ['supported' => ['en', 'fr', 'de']]];
        $current = ['languages' => ['supported' => ['en', 'fr']]];

        $this->assertSame(
            ['languages' => ['supported' => ['en', 'fr']]],
            $this->differ->diff($current, $parent),
        );
    }

    #[Test]
    public function diff_treats_reordered_sequential_arrays_as_different(): void
    {
        $parent  = ['types' => ['htm', 'html']];
        $current = ['types' => ['html', 'htm']];

        $this->assertSame(
            ['types' => ['html', 'htm']],
            $this->differ->diff($current, $parent),
        );
    }

    #[Test]
    public function diff_null_override_is_retained(): void
    {
        // Setting a field explicitly to null should override a non-null default.
        $parent  = ['timezone' => 'UTC'];
        $current = ['timezone' => null];

        $this->assertSame(['timezone' => null], $this->differ->diff($current, $parent));
    }

    #[Test]
    public function diff_key_absent_from_parent_is_always_kept(): void
    {
        $parent  = ['a' => 1];
        $current = ['a' => 1, 'b' => 2];

        $this->assertSame(['b' => 2], $this->differ->diff($current, $parent));
    }

    #[Test]
    public function diff_type_change_from_assoc_to_list_replaces_whole_value(): void
    {
        $parent  = ['http_x_forwarded' => ['protocol' => true]];
        $current = ['http_x_forwarded' => ['a', 'b']];

        $this->assertSame(
            ['http_x_forwarded' => ['a', 'b']],
            $this->differ->diff($current, $parent),
        );
    }

    #[Test]
    public function diff_ignores_key_order_differences(): void
    {
        // Yaml parsers or API clients can legitimately reorder keys. Parent has
        // the same content, different insertion order — should diff to empty.
        $parent  = ['pages' => ['theme' => 'quark', 'markdown' => ['extra' => false]]];
        $current = ['pages' => ['markdown' => ['extra' => false], 'theme' => 'quark']];

        $this->assertSame([], $this->differ->diff($current, $parent));
    }

    #[Test]
    public function diff_drops_subtree_when_no_inner_differences(): void
    {
        $parent  = ['a' => ['b' => 1, 'c' => 2]];
        $current = ['a' => ['b' => 1, 'c' => 2], 'd' => 3];

        $this->assertSame(['d' => 3], $this->differ->diff($current, $parent));
    }

    #[Test]
    public function diff_deeply_nested_override(): void
    {
        $parent  = ['a' => ['b' => ['c' => ['d' => 1, 'e' => 2]]]];
        $current = ['a' => ['b' => ['c' => ['d' => 1, 'e' => 99]]]];

        $this->assertSame(
            ['a' => ['b' => ['c' => ['e' => 99]]]],
            $this->differ->diff($current, $parent),
        );
    }

    // ---------- deepMergeAssoc() ----------

    #[Test]
    public function deep_merge_overrides_scalar(): void
    {
        $this->assertSame(
            ['a' => 2, 'b' => 3],
            $this->differ->deepMergeAssoc(['a' => 1, 'b' => 3], ['a' => 2]),
        );
    }

    #[Test]
    public function deep_merge_recurses_into_assoc(): void
    {
        $result = $this->differ->deepMergeAssoc(
            ['x' => ['a' => 1, 'b' => 2]],
            ['x' => ['b' => 20, 'c' => 30]],
        );

        $this->assertSame(['x' => ['a' => 1, 'b' => 20, 'c' => 30]], $result);
    }

    #[Test]
    public function deep_merge_replaces_sequential_arrays(): void
    {
        $result = $this->differ->deepMergeAssoc(
            ['tags' => ['a', 'b', 'c']],
            ['tags' => ['x']],
        );

        $this->assertSame(['tags' => ['x']], $result);
    }

    // ---------- valuesEqual() / isAssoc() ----------

    #[Test]
    public function values_equal_treats_assoc_key_order_as_irrelevant(): void
    {
        $this->assertTrue(ConfigDiffer::valuesEqual(
            ['a' => 1, 'b' => 2],
            ['b' => 2, 'a' => 1],
        ));
    }

    #[Test]
    public function values_equal_respects_sequential_order(): void
    {
        $this->assertFalse(ConfigDiffer::valuesEqual([1, 2], [2, 1]));
    }

    #[Test]
    public function is_assoc_is_false_for_empty_and_lists(): void
    {
        $this->assertFalse(ConfigDiffer::isAssoc([]));
        $this->assertFalse(ConfigDiffer::isAssoc(['a', 'b']));
        $this->assertTrue(ConfigDiffer::isAssoc(['x' => 1]));
    }
}
