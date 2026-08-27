<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\SystemController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * {@see \Grav\Plugin\Api\Controllers\SystemController::translationChainFor()} and
 * {@see \Grav\Plugin\Api\Controllers\SystemController::stripIcuShadowedKeys()}.
 *
 * Grav keeps one language bucket per code exactly as spelled on disk and
 * `flattenByLang()` reads a single bucket with no fallback. Admin2 ships
 * `en-US.yaml` while Grav core and every plugin ship `en.yaml`, and
 * `normalizeLangCode()` coerces a request for `en` up to `en-US` — so
 * `/translations/en-US` used to return admin2's own strings and nothing else.
 * No core strings, no plugin strings, which left the SPA humanizing every
 * `PLUGIN_*` key it was asked for (trilbymedia/grav-plugin-git-sync#259).
 */
class TranslationLanguageChainTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    /**
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function chainCases(): array
    {
        return [
            'region code reaches its bare subtag' => ['en-US', ['en-US', 'en']],
            'non-English region code'             => ['ru-RU', ['ru-RU', 'ru']],
            'script subtag'                       => ['zh-Hans', ['zh-Hans', 'zh']],
            'bare code stands alone'              => ['ca', ['ca']],
            'bare English stands alone'           => ['en', ['en']],
        ];
    }

    /**
     * @param array<int, string> $expected
     */
    #[Test]
    #[DataProvider('chainCases')]
    public function chain_is_most_specific_first(string $lang, array $expected): void
    {
        $ref = new \ReflectionMethod(SystemController::class, 'translationChainFor');

        $this->assertSame($expected, $ref->invoke(null, $lang));
    }

    #[Test]
    public function icu_shadow_strip_survives_a_cross_bucket_merge(): void
    {
        // The per-bucket strip can't catch this: the flat key comes from the bare
        // bucket and the ICU twin that shadows it from the region bucket, so each
        // one looks unshadowed on its own.
        $merged = [
            'PLUGIN_ADMIN.SAVE'     => 'stale flat value from a Grav 1 plugin',
            'ICU.PLUGIN_ADMIN.SAVE' => 'Save',
            'PLUGIN_FORM.SUBMIT'    => 'Submit',
        ];

        $ref = new \ReflectionMethod(SystemController::class, 'stripIcuShadowedKeys');
        $result = $ref->invoke(null, $merged);

        $this->assertArrayNotHasKey('PLUGIN_ADMIN.SAVE', $result);
        $this->assertSame('Save', $result['ICU.PLUGIN_ADMIN.SAVE']);
        $this->assertSame('Submit', $result['PLUGIN_FORM.SUBMIT'], 'unshadowed flat keys must survive');
    }

    #[Test]
    public function requested_code_wins_over_its_bare_subtag(): void
    {
        $chain = (new \ReflectionMethod(SystemController::class, 'translationChainFor'))
            ->invoke(null, 'en-US');

        // buildTranslationChain() merges least-specific first and lets later
        // buckets overwrite, so reversing the chain must put the bare subtag
        // ahead of the requested code for a region translation to win.
        $this->assertSame(['en', 'en-US'], array_reverse($chain));
    }
}
