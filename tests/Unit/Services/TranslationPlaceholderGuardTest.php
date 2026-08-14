<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Plugin\Api\Services\TranslationPlaceholderGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationPlaceholderGuard::class)]
class TranslationPlaceholderGuardTest extends TestCase
{
    private TranslationPlaceholderGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new TranslationPlaceholderGuard();
    }

    /** @return array<string, array{0: string}> */
    public static function roundTripCases(): array
    {
        return [
            'printf simple' => ['Showing %s results'],
            'printf positional' => ['Showing %1$s of %2$s'],
            'printf padded' => ['%02d:%02d'],
            'printf literal percent' => ['100%% complete'],
            'named brace' => ['Delete "{title}" at {route}?'],
            'icu plural' => ['{count, plural, one {# field} other {# fields}}'],
            'icu nested' => ['{n, plural, other {# of {total} items}}'],
            'icu select' => ['{gender, select, male {He} female {She} other {They}} replied'],
            'html anchor' => ['See <a href="/docs">the docs</a> for more'],
            'html self closing' => ['Line one<br />Line two'],
            'mixed' => ['<strong>%1$s</strong> of {count} — see <a href="{url}">docs</a>'],
            'no tokens at all' => ['Just some ordinary words'],
            'empty' => [''],
        ];
    }

    #[Test]
    #[DataProvider('roundTripCases')]
    public function masking_then_unmasking_returns_the_original(string $source): void
    {
        [$masked, $map] = $this->guard->mask($source);

        $this->assertSame($source, $this->guard->unmask($masked, $map));
    }

    #[Test]
    public function tokens_are_hidden_from_the_translator(): void
    {
        [$masked] = $this->guard->mask('Showing %1$s of %2$s results');

        $this->assertStringNotContainsString('%1$s', $masked);
        $this->assertStringNotContainsString('%2$s', $masked);
        $this->assertStringContainsString('Showing', $masked, 'the words still need translating');
        $this->assertStringContainsString('results', $masked);
    }

    #[Test]
    public function a_nested_icu_message_is_masked_as_one_unit(): void
    {
        // Shredding it would let a translator reorder the plural machinery.
        [, $map] = $this->guard->mask('{n, plural, other {# of {total} items}}');

        $this->assertCount(1, $map);
        $this->assertSame('{n, plural, other {# of {total} items}}', $map[0]);
    }

    #[Test]
    public function a_translation_that_kept_every_token_passes(): void
    {
        [$masked] = $this->guard->mask('Showing %1$s of %2$s');
        // A plausible translation: same sentinels, different words and order.
        $translated = preg_replace('/Showing (.+) of (.+)/u', 'Affichage de $1 sur $2', $masked);

        $this->assertTrue($this->guard->preserved($masked, (string) $translated));
    }

    #[Test]
    public function reordering_tokens_is_allowed(): void
    {
        // Target-language word order legitimately moves %1$s after %2$s.
        [$masked] = $this->guard->mask('%1$s of %2$s');
        $ids = $this->guard->tokens($masked);
        $reversed = "\u{2062}{$ids[1]}\u{2062} de \u{2062}{$ids[0]}\u{2062}";

        $this->assertTrue($this->guard->preserved($masked, $reversed));
    }

    #[Test]
    public function a_translation_that_dropped_a_token_is_rejected(): void
    {
        [$masked] = $this->guard->mask('Showing %1$s of %2$s');
        $ids = $this->guard->tokens($masked);
        $lossy = "Affichage \u{2062}{$ids[0]}\u{2062}";

        $this->assertFalse($this->guard->preserved($masked, $lossy));
    }

    #[Test]
    public function a_translation_that_mangled_a_token_is_rejected(): void
    {
        // The real failure mode: the engine spaces or translates the sentinel.
        [$masked] = $this->guard->mask('Showing %1$s results');

        $this->assertFalse($this->guard->preserved($masked, 'Affichage de resultats'));
    }

    #[Test]
    public function an_icu_message_is_flagged_for_a_human(): void
    {
        // No engine can invent the plural categories a target language needs.
        $result = $this->guard->analyze('{count, plural, one {# field} other {# fields}}');

        $this->assertTrue($result['needs_human']);
    }

    #[Test]
    public function a_plain_named_placeholder_does_not_need_a_human(): void
    {
        $result = $this->guard->analyze('Delete "{title}"?');

        $this->assertFalse($result['needs_human']);
        $this->assertTrue($result['translatable']);
    }

    #[Test]
    public function a_string_that_is_only_tokens_is_not_translatable(): void
    {
        // Nothing to send; translating it would only risk breaking it.
        $result = $this->guard->analyze('%1$s');

        $this->assertFalse($result['translatable']);
    }

    #[Test]
    public function an_unbalanced_brace_is_left_alone_rather_than_swallowing_the_string(): void
    {
        $source = 'Broken {open and then some more text';

        [$masked, $map] = $this->guard->mask($source);

        $this->assertSame($source, $this->guard->unmask($masked, $map));
        $this->assertStringContainsString('some more text', $masked);
    }
}
