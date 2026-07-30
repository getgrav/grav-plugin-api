<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Data\Blueprint;
use Grav\Common\Grav;
use Grav\Plugin\Api\Services\ConfigDiffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Blueprint-aware diffing.
 *
 * Grav does not deep-merge a map that a blueprint declares as a single field —
 * BlueprintSchema::mergeArrays REPLACES it. A delta that keeps only the keys
 * differing from the default therefore deletes the rest at runtime: writing a
 * partial `assets.collections` cost sites the built-in jQuery entry and broke
 * their front end (getgrav/grav-admin-next#15). Fields declared in the
 * blueprint must be diffed whole; only containers may recurse.
 */
class ConfigDifferBlueprintTest extends TestCase
{
    private ConfigDiffer $differ;

    protected function setUp(): void
    {
        Grav::resetInstance();

        // Blueprint::initInternals() reads the plugin-contributed field types
        // off the container; nothing boots plugins here, so give it an empty
        // stand-in rather than let it dereference null.
        $grav = Grav::instance();
        $grav['plugins'] = new class {
            /** @var array<string, mixed>|null */
            public $formFieldTypes = null;
        };

        $blueprint = new Blueprint('test', [
            'form' => [
                'fields' => [
                    'assets.collections' => ['type' => 'multilevel'],
                    'assets.enable_asset_timestamp' => ['type' => 'toggle'],
                    'pages.theme' => ['type' => 'text'],
                ],
            ],
        ]);
        $blueprint->init();

        // Stand in for the scope→blueprint lookup so the test stays a pure unit
        // test (no locator, no fixture site on disk).
        $this->differ = new class (Grav::instance(), $blueprint) extends ConfigDiffer {
            public function __construct(Grav $grav, private Blueprint $stub)
            {
                parent::__construct($grav);
            }

            public function blueprintFor(string $scope): ?Blueprint
            {
                return $this->stub;
            }
        };
    }

    #[Test]
    public function map_field_is_persisted_whole_when_any_entry_differs(): void
    {
        $parent  = ['assets' => ['collections' => ['jquery' => 'system://assets/jquery/jquery-3.x.min.js']]];
        $current = ['assets' => ['collections' => [
            'jquery' => 'system://assets/jquery/jquery-3.x.min.js',
            'bootstrap3' => 'https://example.com/bootstrap.min.js',
        ]]];

        // The default-equal `jquery` entry must survive: dropping it would make
        // Grav's replacing merge delete the built-in collection.
        $this->assertSame(
            ['assets' => ['collections' => [
                'jquery' => 'system://assets/jquery/jquery-3.x.min.js',
                'bootstrap3' => 'https://example.com/bootstrap.min.js',
            ]]],
            $this->differ->diff($current, $parent, 'system')
        );
    }

    #[Test]
    public function map_field_equal_to_the_default_is_still_omitted(): void
    {
        $parent  = ['assets' => ['collections' => ['jquery' => 'system://assets/jquery/jquery-3.x.min.js']]];
        $current = ['assets' => ['collections' => ['jquery' => 'system://assets/jquery/jquery-3.x.min.js']]];

        $this->assertSame([], $this->differ->diff($current, $parent, 'system'));
    }

    #[Test]
    public function sibling_fields_of_a_changed_map_are_not_dragged_in(): void
    {
        $parent = [
            'assets' => [
                'collections' => ['jquery' => 'system://assets/jquery/jquery-3.x.min.js'],
                'enable_asset_timestamp' => false,
            ],
        ];
        $current = [
            'assets' => [
                'collections' => ['bootstrap3' => 'https://example.com/bootstrap.min.js'],
                'enable_asset_timestamp' => false,
            ],
        ];

        $this->assertSame(
            ['assets' => ['collections' => ['bootstrap3' => 'https://example.com/bootstrap.min.js']]],
            $this->differ->diff($current, $parent, 'system')
        );
    }

    #[Test]
    public function containers_still_recurse_to_the_changed_leaf(): void
    {
        $parent  = ['pages' => ['theme' => 'quark'], 'assets' => ['enable_asset_timestamp' => false]];
        $current = ['pages' => ['theme' => 'quark2'], 'assets' => ['enable_asset_timestamp' => false]];

        $this->assertSame(['pages' => ['theme' => 'quark2']], $this->differ->diff($current, $parent, 'system'));
    }

    #[Test]
    public function keys_the_blueprint_never_declares_are_kept_whole(): void
    {
        // Undefined keys are replaced by Grav's merge too, so they diff whole.
        $parent  = ['custom' => ['a' => 1, 'b' => 2]];
        $current = ['custom' => ['a' => 9, 'b' => 2]];

        $this->assertSame(['custom' => ['a' => 9, 'b' => 2]], $this->differ->diff($current, $parent, 'system'));
    }

    #[Test]
    public function no_scope_keeps_the_structural_diff(): void
    {
        $parent  = ['assets' => ['collections' => ['jquery' => 'j.js']]];
        $current = ['assets' => ['collections' => ['jquery' => 'j.js', 'bootstrap3' => 'b.js']]];

        // Callers without a scope (tests, non-config data) get the old
        // key-by-key behaviour.
        $this->assertSame(
            ['assets' => ['collections' => ['bootstrap3' => 'b.js']]],
            $this->differ->diff($current, $parent)
        );
    }
}
