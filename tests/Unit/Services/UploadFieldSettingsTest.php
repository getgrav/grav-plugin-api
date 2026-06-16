<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Services\UploadFieldSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * {@see UploadFieldSettings} — the per-field upload settings (random_name,
 * avoid_overwriting, accept, filesize) that bring admin-next file fields to
 * parity with admin-classic. Parsing tolerates both JSON-body and multipart-
 * meta shapes; enforcement only ever tightens the controllers' security floor.
 */
#[CoversClass(UploadFieldSettings::class)]
class UploadFieldSettingsTest extends TestCase
{
    #[Test]
    public function absent_params_produce_an_inert_object(): void
    {
        $settings = UploadFieldSettings::fromParams([]);

        self::assertTrue($settings->isEmpty());
        self::assertFalse($settings->randomName);
        self::assertFalse($settings->avoidOverwriting);
        self::assertSame([], $settings->accept);
        self::assertNull($settings->filesizeMb);
    }

    #[Test]
    public function booleans_parse_from_multipart_string_truthies(): void
    {
        self::assertTrue(UploadFieldSettings::fromParams(['random_name' => '1'])->randomName);
        self::assertTrue(UploadFieldSettings::fromParams(['random_name' => 'true'])->randomName);
        self::assertTrue(UploadFieldSettings::fromParams(['avoid_overwriting' => 'on'])->avoidOverwriting);
        self::assertFalse(UploadFieldSettings::fromParams(['random_name' => '0'])->randomName);
        self::assertFalse(UploadFieldSettings::fromParams(['random_name' => 'false'])->randomName);
    }

    #[Test]
    public function accept_parses_from_both_csv_string_and_array(): void
    {
        self::assertSame(
            ['image/*', '.pdf'],
            UploadFieldSettings::fromParams(['accept' => 'image/*, .pdf'])->accept
        );
        self::assertSame(
            ['image/png', '.jpg'],
            UploadFieldSettings::fromParams(['accept' => ['image/png', ' .jpg ', '']])->accept
        );
    }

    #[Test]
    public function filesize_parses_only_positive_numbers(): void
    {
        self::assertSame(5.0, UploadFieldSettings::fromParams(['filesize' => '5'])->filesizeMb);
        self::assertSame(2.5, UploadFieldSettings::fromParams(['filesize' => 2.5])->filesizeMb);
        self::assertNull(UploadFieldSettings::fromParams(['filesize' => '0'])->filesizeMb);
        self::assertNull(UploadFieldSettings::fromParams(['filesize' => 'huge'])->filesizeMb);
    }

    #[Test]
    public function assert_filesize_enforces_the_per_field_limit(): void
    {
        $settings = UploadFieldSettings::fromParams(['filesize' => 1]);

        // 1 MB exactly is fine; one byte over the limit is rejected.
        $settings->assertFilesize(1_048_576);
        $this->expectException(ValidationException::class);
        $settings->assertFilesize(1_048_577);
    }

    #[Test]
    public function assert_filesize_is_a_noop_without_a_limit_or_known_size(): void
    {
        UploadFieldSettings::none()->assertFilesize(999_999_999);
        UploadFieldSettings::fromParams(['filesize' => 1])->assertFilesize(null);

        // No exception thrown.
        $this->addToAssertionCount(2);
    }

    #[Test]
    public function assert_accepted_matches_extensions_and_mime_globs(): void
    {
        UploadFieldSettings::fromParams(['accept' => '.png'])->assertAccepted('photo.png');
        UploadFieldSettings::fromParams(['accept' => 'image/*'])->assertAccepted('photo.png');
        UploadFieldSettings::fromParams(['accept' => '*'])->assertAccepted('anything.bin');

        $this->addToAssertionCount(3);
    }

    #[Test]
    public function assert_accepted_rejects_a_non_matching_type(): void
    {
        $this->expectException(ValidationException::class);
        UploadFieldSettings::fromParams(['accept' => 'image/*'])->assertAccepted('notes.txt');
    }

    #[Test]
    public function resolve_filename_randomizes_and_lowercases_keeping_the_extension(): void
    {
        $name = UploadFieldSettings::fromParams(['random_name' => '1'])
            ->resolveFilename('My Photo.PNG', sys_get_temp_dir());

        self::assertMatchesRegularExpression('/^[a-z0-9]{15}\.png$/', $name);
    }

    #[Test]
    public function resolve_filename_only_prefixes_on_an_actual_conflict(): void
    {
        $dir = sys_get_temp_dir() . '/grav_api_ufs_' . uniqid();
        mkdir($dir, 0775, true);
        try {
            $settings = UploadFieldSettings::fromParams(['avoid_overwriting' => true]);

            // No conflict — name is untouched.
            self::assertSame('logo.png', $settings->resolveFilename('logo.png', $dir));

            // Conflict — datetime-prefixed.
            file_put_contents($dir . '/logo.png', 'x');
            self::assertMatchesRegularExpression(
                '/^\d{14}-logo\.png$/',
                $settings->resolveFilename('logo.png', $dir)
            );
        } finally {
            @unlink($dir . '/logo.png');
            @rmdir($dir);
        }
    }
}
