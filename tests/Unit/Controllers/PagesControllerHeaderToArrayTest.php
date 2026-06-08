<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Page\Header;
use Grav\Plugin\Api\Controllers\PagesController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression coverage for getgrav/grav-plugin-admin2#31 — frontmatter polluted
 * with NUL-prefixed keys after saving from Admin 2.0 (Expert mode).
 *
 * Flex pages (Grav's default since 1.7) return a Header/Data object from
 * header(), not a stdClass. The update path used to read the current header
 * with `(array) $page->header()`, which leaks the object's protected
 * properties as NUL-prefixed keys ("\0*\0items", "\0*\0nestedSeparator").
 * Those keys were then merged with the incoming edit and persisted, so every
 * save nested the real fields one level deeper inside a growing "\0*\0items"
 * wrapper. headerToArray() routes through jsonSerialize() instead and keeps the
 * keys clean.
 */
#[CoversClass(PagesController::class)]
class PagesControllerHeaderToArrayTest extends TestCase
{
    private function headerToArray($header): array
    {
        $ref = new ReflectionClass(PagesController::class);
        $instance = $ref->newInstanceWithoutConstructor();

        return $ref->getMethod('headerToArray')->invoke($instance, $header);
    }

    #[Test]
    public function flex_header_object_yields_clean_keys(): void
    {
        $header = new Header([
            'title' => 'page to filter out',
            'access' => ['site.restricted' => true],
        ]);

        $result = $this->headerToArray($header);

        $this->assertSame(['title', 'access'], array_keys($result));
        foreach (array_keys($result) as $key) {
            $this->assertStringNotContainsString("\0", (string) $key, 'No NUL-prefixed keys may leak');
        }
        $this->assertStringNotContainsString('items', Yaml::dump($result));
        $this->assertStringNotContainsString('nestedSeparator', Yaml::dump($result));
    }

    #[Test]
    public function stdclass_header_from_legacy_pages_round_trips(): void
    {
        $header = (object) ['title' => 'Hello', 'taxonomy' => (object) ['tag' => ['a', 'b']]];

        $result = $this->headerToArray($header);

        $this->assertSame('Hello', $result['title']);
        $this->assertSame(['a', 'b'], $result['taxonomy']['tag']);
    }

    #[Test]
    public function null_and_array_headers_are_passed_through(): void
    {
        $this->assertSame([], $this->headerToArray(null));
        $this->assertSame(['title' => 'x'], $this->headerToArray(['title' => 'x']));
    }

    #[Test]
    public function repeated_saves_do_not_compound_pollution(): void
    {
        // Simulate the read-merge-write loop the update endpoint runs: read the
        // current header off the page object, merge an incoming Expert-mode
        // payload, then store it back. Three iterations must stay clean — the
        // bug nested the real fields one level deeper on every save.
        $stored = ['title' => 'Original', 'access' => ['site.restricted' => true]];

        for ($i = 0; $i < 3; $i++) {
            $headerObject = new Header($stored);
            $existing = $this->headerToArray($headerObject);
            $incoming = ['title' => 'Edited ' . $i, 'access' => ['site.restricted' => true]];

            // mirrors mergePatch + (object) cast + Header re-wrap on save
            $merged = array_replace($existing, $incoming);
            $stored = $merged;

            $dump = Yaml::dump($merged, 10, 2);
            $this->assertStringNotContainsString("\0", $dump);
            $this->assertStringNotContainsString('*0items', $dump);
            $this->assertSame(['title', 'access'], array_keys($merged));
        }

        $this->assertSame('Edited 2', $stored['title']);
    }
}
