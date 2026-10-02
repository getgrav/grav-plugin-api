<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Plugin\Api\Controllers\PagesController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression coverage for getgrav/grav-plugin-admin2#186: GET /taxonomy sent
 * integer-like values (a `2024` tag) as JSON numbers.
 *
 * Core's taxonomy map is keyed by value, and PHP stores a key such as "2024"
 * as an int, so array_keys() returned ints for those values. The response is
 * documented as lists of strings, and admin2 sorts the list as strings, so one
 * year tag anywhere on a site stopped the page editor's Taxonomy field from
 * drawing at all.
 */
#[CoversClass(PagesController::class)]
class PagesControllerTaxonomyValuesTest extends TestCase
{
    /**
     * @param array<int|string, mixed> $values
     * @return list<string>
     */
    private function valueList(array $values): array
    {
        $ref = new ReflectionClass(PagesController::class);

        return $ref->getMethod('taxonomyValueList')->invoke(null, $values);
    }

    /**
     * Core's map for one type: value => [page path => page info].
     *
     * @param list<string> $values
     * @return array<int|string, array<string, array<string, string>>>
     */
    private function coreMap(array $values): array
    {
        $map = [];
        foreach ($values as $value) {
            // Same write as Taxonomy::iterateTaxonomy().
            $map[(string) $value]['/pages/blog/post'] = ['slug' => 'post'];
        }

        return $map;
    }

    #[Test]
    public function integer_like_values_come_back_as_strings(): void
    {
        $map = $this->coreMap(['zeitgeistgedachtegang', '2024', '2023', '-3']);

        // The premise: PHP has already turned these keys into ints.
        $this->assertContains(2024, array_keys($map));

        $this->assertSame(['zeitgeistgedachtegang', '2024', '2023', '-3'], $this->valueList($map));
        $this->assertSame(
            '["zeitgeistgedachtegang","2024","2023","-3"]',
            json_encode($this->valueList($map))
        );
    }

    #[Test]
    public function other_values_are_unchanged_and_stay_a_list(): void
    {
        $values = ['blog', '007', '1.5', 'Nieuws & Zo'];

        $this->assertSame($values, $this->valueList($this->coreMap($values)));
        $this->assertSame([], $this->valueList([]));
    }
}
