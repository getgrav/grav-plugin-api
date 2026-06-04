<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\ConfigController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * {@see \Grav\Plugin\Api\Controllers\AbstractApiController::normalizeEtag()} —
 * a compressing front-end appends a transport suffix to the ETag and the client
 * echoes it back verbatim in If-Match, so the suffix must be stripped before
 * comparing against the stored hash. Missing `zstd` here surfaced as a false
 * 409 "modified elsewhere" on mod_zstd servers (getgrav/grav-plugin-admin2#28).
 */
class EtagNormalizationTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function etagCases(): array
    {
        $hash = '8fe605d9c21bc107eeceba0c63c93baa';

        return [
            'bare'              => ["\"{$hash}\"", $hash],
            'gzip suffix'       => ["\"{$hash}-gzip\"", $hash],
            'gzip semicolon'    => ["\"{$hash};gzip\"", $hash],
            'brotli suffix'     => ["\"{$hash}-br\"", $hash],
            'deflate suffix'    => ["\"{$hash}-deflate\"", $hash],
            'zstd suffix'       => ["\"{$hash}-zstd\"", $hash],
            'weak marker'       => ["W/\"{$hash}-zstd\"", $hash],
            'no quotes'         => ["{$hash}-zstd", $hash],
        ];
    }

    #[Test]
    #[DataProvider('etagCases')]
    public function strips_transport_suffixes_and_wrappers(string $input, string $expected): void
    {
        Grav::resetInstance();
        $controller = new ConfigController(Grav::instance(), new Config());

        $ref = new \ReflectionMethod($controller, 'normalizeEtag');

        $this->assertSame($expected, $ref->invoke($controller, $input));
    }
}
