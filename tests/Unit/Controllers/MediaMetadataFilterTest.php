<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit coverage for the schema-bound media filter parser used by
 * `GET /pages/{route}/media?filter=…`. The filterable surface is the
 * configured `media_metadata.fields` set: unknown keys are ignored leniently,
 * a field's `type` drives which operators are legal, and values are sanitized
 * like write input. See getgrav/grav#4200.
 */
#[CoversClass(MediaController::class)]
class MediaMetadataFilterTest extends TestCase
{
    /** field key => type, mirroring getMetadataFieldDefs() output. */
    private const TYPES = [
        'title' => 'text',
        'rating' => 'text',
        'tags' => 'tags',
    ];

    private MediaController $controller;

    protected function setUp(): void
    {
        // parseFilterClause / sanitizeFilterValue are pure — no Grav container.
        $this->controller = (new ReflectionClass(MediaController::class))->newInstanceWithoutConstructor();
    }

    /** @return array{0: string, 1: string, 2: string|list<string>}|null */
    private function parse(string $clause): ?array
    {
        $ref = new ReflectionClass(MediaController::class);
        $method = $ref->getMethod('parseFilterClause');

        return $method->invoke($this->controller, $clause, self::TYPES, 2000);
    }

    #[Test]
    public function three_part_clause_parses_field_operator_value(): void
    {
        $this->assertSame(['rating', '>=', '3'], $this->parse('rating:>=:3'));
    }

    #[Test]
    public function two_part_clause_infers_operator_from_type(): void
    {
        // text field defaults to equality...
        $this->assertSame(['rating', '==', '3'], $this->parse('rating:3'));
        // ...a tags field defaults to membership.
        $this->assertSame(['tags', 'contains', 'sunset'], $this->parse('tags:sunset'));
    }

    #[Test]
    public function value_may_contain_colons(): void
    {
        // explode(limit: 3) keeps colons in the value intact.
        $this->assertSame(['title', '==', 'a:b:c'], $this->parse('title:==:a:b:c'));
    }

    #[Test]
    public function in_operator_splits_value_into_a_set(): void
    {
        $this->assertSame(['tags', 'in', ['city', 'mountain']], $this->parse('tags:in:city,mountain'));
    }

    #[Test]
    public function unknown_field_is_ignored_leniently(): void
    {
        $this->assertNull($this->parse('bogus:==:x'));
    }

    #[Test]
    public function unsupported_operator_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->parse('rating:~=:3');
    }

    #[Test]
    public function tags_field_rejects_scalar_operator(): void
    {
        $this->expectException(ValidationException::class);
        $this->parse('tags:>:3');
    }

    #[Test]
    public function malformed_field_name_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->parse('bad field!:==:x');
    }

    #[Test]
    public function value_is_stripped_of_tags(): void
    {
        // strip_tags removes markup; the parser never emits raw HTML.
        $this->assertSame(['title', '==', 'hello'], $this->parse('title:==:<b>hello</b>'));
    }
}
