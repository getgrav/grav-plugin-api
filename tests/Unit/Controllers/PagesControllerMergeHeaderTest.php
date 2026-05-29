<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Controllers\PagesController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests for AbstractApiController::mergePatch(), exercised through
 * PagesController (the page-update endpoint is where the regression bit).
 *
 * Regression coverage for getgrav/grav-theme-quark2#8 — saving a page whose
 * frontmatter contains a YAML list (e.g. `form.fields`, `form.process`) used to
 * grow the file on every save: array_replace_recursive merged the existing
 * integer-keyed list with the incoming name-keyed map, leaving both keysets in
 * the result and forcing Symfony YAML to dump the list as a quoted '0','1','2'
 * map next to the new named entries.
 */
#[CoversClass(AbstractApiController::class)]
#[CoversClass(PagesController::class)]
class PagesControllerMergeHeaderTest extends TestCase
{
    private function invoke(array $existing, array $incoming): array
    {
        $ref = new ReflectionClass(PagesController::class);
        $instance = $ref->newInstanceWithoutConstructor();

        return $ref->getMethod('mergePatch')->invoke($instance, $existing, $incoming);
    }

    #[Test]
    public function list_in_existing_is_replaced_when_incoming_sends_a_map(): void
    {
        $existing = [
            'form' => [
                'fields' => [
                    ['name' => 'name', 'label' => 'Name'],
                    ['name' => 'email', 'label' => 'Email'],
                    ['name' => 'message', 'label' => 'Message'],
                ],
            ],
        ];
        $incoming = [
            'form' => [
                'fields' => [
                    'name' => ['label' => 'Name'],
                    'email' => ['label' => 'Email'],
                ],
            ],
        ];

        $merged = $this->invoke($existing, $incoming);

        $this->assertSame(['name', 'email'], array_keys($merged['form']['fields']));
        $yaml = Yaml::dump($merged, 10, 2);
        $this->assertStringNotContainsString("'0':", $yaml, 'No quoted integer keys should leak through');
        $this->assertStringNotContainsString("'1':", $yaml);
        $this->assertStringNotContainsString("'2':", $yaml);
    }

    #[Test]
    public function list_in_incoming_replaces_existing_map(): void
    {
        $existing = [
            'form' => [
                'fields' => [
                    'name' => ['label' => 'Name'],
                    'email' => ['label' => 'Email'],
                ],
            ],
        ];
        $incoming = [
            'form' => [
                'fields' => [
                    ['name' => 'subject', 'label' => 'Subject'],
                ],
            ],
        ];

        $merged = $this->invoke($existing, $incoming);

        $this->assertTrue(array_is_list($merged['form']['fields']));
        $this->assertCount(1, $merged['form']['fields']);
        $this->assertSame('subject', $merged['form']['fields'][0]['name']);
    }

    #[Test]
    public function maps_still_recurse_so_partial_header_updates_keep_working(): void
    {
        $existing = [
            'title' => 'Old',
            'metadata' => [
                'description' => 'desc',
                'author' => 'andy',
            ],
            'published' => true,
        ];
        $incoming = [
            'metadata' => [
                'author' => 'someone',
            ],
        ];

        $merged = $this->invoke($existing, $incoming);

        $this->assertSame('Old', $merged['title']);
        $this->assertSame('desc', $merged['metadata']['description']);
        $this->assertSame('someone', $merged['metadata']['author']);
        $this->assertTrue($merged['published']);
    }

    #[Test]
    public function nested_list_under_a_map_is_replaced_not_index_merged(): void
    {
        $existing = ['taxonomy' => ['tag' => ['a', 'b', 'c', 'd']]];
        $incoming = ['taxonomy' => ['tag' => ['x', 'y']]];

        $merged = $this->invoke($existing, $incoming);

        $this->assertSame(['x', 'y'], $merged['taxonomy']['tag']);
    }

    #[Test]
    public function quark2_issue_8_full_payload_round_trip(): void
    {
        $existing = [
            'title' => 'Contact',
            'form' => [
                'name' => 'contact-form',
                'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ['name' => 'email', 'label' => 'Email', 'type' => 'email'],
                    ['name' => 'message', 'label' => 'Message', 'type' => 'textarea'],
                ],
                'buttons' => [
                    ['type' => 'submit', 'value' => 'Submit'],
                    ['type' => 'reset', 'value' => 'Reset'],
                ],
                'process' => [
                    ['email' => ['from' => 'x', 'subject' => 'y']],
                    ['save' => ['fileprefix' => 'feedback-']],
                    ['message' => 'Thank you'],
                    ['display' => '/contact'],
                ],
            ],
        ];
        $incoming = [
            'form' => [
                'fields' => [
                    'name' => ['label' => 'Name', 'type' => 'text'],
                    'email' => ['label' => 'Email', 'type' => 'email'],
                ],
                'buttons' => [
                    'submit' => ['type' => 'submit', 'value' => 'Submit'],
                ],
                'process' => [
                    'email' => ['from' => 'x', 'subject' => 'y'],
                    'message' => 'Thank you',
                ],
            ],
        ];

        $merged = $this->invoke($existing, $incoming);

        $this->assertSame('Contact', $merged['title']);
        $this->assertSame('contact-form', $merged['form']['name']);
        $this->assertSame(['name', 'email'], array_keys($merged['form']['fields']));
        $this->assertSame(['submit'], array_keys($merged['form']['buttons']));
        $this->assertSame(['email', 'message'], array_keys($merged['form']['process']));

        $yaml = Yaml::dump($merged, 10, 2);
        $this->assertStringNotContainsString("'0':", $yaml);
        $this->assertStringNotContainsString("'1':", $yaml);
        $this->assertStringNotContainsString("'2':", $yaml);
        $this->assertStringNotContainsString("'3':", $yaml);
    }
}
