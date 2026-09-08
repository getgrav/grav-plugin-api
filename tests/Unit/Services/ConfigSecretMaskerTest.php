<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Plugin\Api\Services\ConfigSecretMasker;
use PHPUnit\Framework\TestCase;

/**
 * Masking secrets on the way out, and putting them back on the way in.
 *
 * The second half is the one that destroys data when it is wrong. The config
 * form posts the whole scope back on every save, so every secret in that scope
 * is written again each time somebody changes a checkbox — and a secret the
 * user never saw and never touched has to survive that.
 */
final class ConfigSecretMaskerTest extends TestCase
{
    public function testAValueThatLooksLikeASecretIsMaskedRatherThanSent(): void
    {
        $masked = ConfigSecretMasker::mask([
            'from' => 'shop@example.com',
            'providers' => ['mailgun' => ['secret' => 'the-real-one', 'set_up_at' => 1788906703]],
        ]);

        self::assertSame(ConfigSecretMasker::SENTINEL, $masked['providers']['mailgun']['secret']);
        self::assertSame('shop@example.com', $masked['from'], 'and nothing else is touched');
        self::assertSame(1788906703, $masked['providers']['mailgun']['set_up_at']);
    }

    public function testTheSentinelComingBackUnchangedIsRestored(): void
    {
        $existing = ['providers' => ['mailgun' => ['secret' => 'the-real-one']]];
        $merged = ['providers' => ['mailgun' => ['secret' => ConfigSecretMasker::SENTINEL]]];

        $out = ConfigSecretMasker::restoreSentinels($merged, $existing);

        self::assertSame('the-real-one', $out['providers']['mailgun']['secret']);
    }

    /**
     * The bug this exists for, found on a live store.
     *
     * A merchant changed one From address on a plugin's settings form. That
     * plugin keeps a webhook signing secret per provider under a config key its
     * blueprint does not declare, and the save came back without those keys at
     * all rather than with the sentinel in them. The restore walked the paths
     * in what was submitted, so it never looked at a path that was no longer
     * there — and five registered webhooks were left posting at addresses that
     * had stopped existing, with nothing on any screen to say so.
     */
    public function testASecretMissingFromTheSubmissionIsRestoredRatherThanDropped(): void
    {
        $existing = [
            'from' => 'old@example.com',
            'providers' => [
                'mailgun' => ['secret' => 'mailgun-real', 'set_up_at' => 1788906703],
                'postmark' => ['secret' => 'postmark-real', 'set_up_at' => 1788902326],
            ],
        ];

        // What the form sent back: the changed field, and the provider block
        // with every masked value stripped out of it.
        $merged = [
            'from' => 'new@example.com',
            'providers' => [
                'mailgun' => ['set_up_at' => 1788906703],
                'postmark' => ['set_up_at' => 1788902326],
            ],
        ];

        $out = ConfigSecretMasker::restoreSentinels($merged, $existing);

        self::assertSame('mailgun-real', $out['providers']['mailgun']['secret']);
        self::assertSame('postmark-real', $out['providers']['postmark']['secret']);
        self::assertSame('new@example.com', $out['from'], 'the change the merchant actually made still lands');
    }

    /**
     * And the other direction, which is why absent and empty cannot be treated
     * the same: somebody removing a secret on purpose posts an empty string.
     */
    public function testClearingASecretOnPurposeStillClearsIt(): void
    {
        $existing = ['providers' => ['mailgun' => ['secret' => 'mailgun-real']]];
        $merged = ['providers' => ['mailgun' => ['secret' => '']]];

        $out = ConfigSecretMasker::restoreSentinels($merged, $existing);

        self::assertSame('', $out['providers']['mailgun']['secret']);
    }

    /** A genuinely new secret is not overwritten by the old one either. */
    public function testAChangedSecretPassesThrough(): void
    {
        $existing = ['providers' => ['mailgun' => ['secret' => 'old']]];
        $merged = ['providers' => ['mailgun' => ['secret' => 'new']]];

        self::assertSame('new', ConfigSecretMasker::restoreSentinels($merged, $existing)['providers']['mailgun']['secret']);
    }
}
