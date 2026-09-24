<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Response;

use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Response\ResponseCompressor;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseCompressor::class)]
class ResponseCompressorTest extends TestCase
{
    private function large(): \Psr\Http\Message\ResponseInterface
    {
        return ApiResponse::create(array_fill(0, 2000, ['title' => 'A page title', 'route' => '/some/route']));
    }

    private function gzipRequest(string $method = 'GET', string $encoding = 'gzip, deflate, br')
    {
        return TestHelper::createMockRequest(method: $method, headers: ['Accept-Encoding' => $encoding]);
    }

    #[Test]
    public function large_json_is_gzipped_and_decodes_to_the_same_body(): void
    {
        $response = $this->large();
        $plain = (string) $response->getBody();

        $out = (new ResponseCompressor())->compress($this->gzipRequest(), $response);

        self::assertSame('gzip', $out->getHeaderLine('Content-Encoding'));
        self::assertStringContainsString('Accept-Encoding', $out->getHeaderLine('Vary'));
        self::assertSame($plain, gzdecode((string) $out->getBody()));
        self::assertSame('application/json', $out->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function small_bodies_are_left_alone_but_still_vary(): void
    {
        $out = (new ResponseCompressor())->compress($this->gzipRequest(), ApiResponse::create(['ok' => true]));

        self::assertFalse($out->hasHeader('Content-Encoding'));
        self::assertSame('Accept-Encoding', $out->getHeaderLine('Vary'));
        self::assertSame('{"data":{"ok":true}}', (string) $out->getBody());
    }

    #[Test]
    public function off_mode_changes_nothing(): void
    {
        $response = $this->large();
        $out = (new ResponseCompressor('off'))->compress($this->gzipRequest(), $response);

        self::assertSame($response, $out);
    }

    #[Test]
    public function head_and_clients_without_gzip_get_the_plain_body(): void
    {
        $compressor = new ResponseCompressor();

        self::assertFalse($compressor->compress($this->gzipRequest('HEAD'), $this->large())->hasHeader('Content-Encoding'));
        self::assertFalse($compressor->compress($this->gzipRequest('GET', 'identity'), $this->large())->hasHeader('Content-Encoding'));
        self::assertFalse($compressor->compress($this->gzipRequest('GET', 'gzip;q=0, br'), $this->large())->hasHeader('Content-Encoding'));
        self::assertFalse($compressor->compress(TestHelper::createMockRequest(), $this->large())->hasHeader('Content-Encoding'));
    }

    #[Test]
    public function not_modified_downloads_and_encoded_bodies_pass_through(): void
    {
        $compressor = new ResponseCompressor();
        $big = str_repeat('{"a":1}', 5000);

        $notModified = new Response(304, ['Content-Type' => 'application/json'], '');
        self::assertSame($notModified, $compressor->compress($this->gzipRequest(), $notModified));

        $download = new Response(200, ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="x.json"'], $big);
        self::assertFalse($compressor->compress($this->gzipRequest(), $download)->hasHeader('Content-Encoding'));

        $encoded = new Response(200, ['Content-Type' => 'application/json', 'Content-Encoding' => 'br'], $big);
        self::assertSame('br', $compressor->compress($this->gzipRequest(), $encoded)->getHeaderLine('Content-Encoding'));

        $html = new Response(200, ['Content-Type' => 'text/html'], str_repeat('<p>x</p>', 5000));
        self::assertSame($html, $compressor->compress($this->gzipRequest(), $html));
    }

    #[Test]
    public function a_shutdown_that_rewrites_the_encoding_disables_compression(): void
    {
        $out = (new ResponseCompressor('auto', true))->compress($this->gzipRequest(), $this->large());

        self::assertFalse($out->hasHeader('Content-Encoding'));
    }

    #[Test]
    public function existing_vary_is_extended_not_replaced(): void
    {
        $response = $this->large()->withHeader('Vary', 'Origin');
        $out = (new ResponseCompressor())->compress($this->gzipRequest(), $response);

        self::assertSame(['Origin', 'Accept-Encoding'], $out->getHeader('Vary'));
    }

    #[Test]
    public function accept_encoding_parsing(): void
    {
        $c = new ResponseCompressor();

        self::assertTrue($c->acceptsGzip('gzip'));
        self::assertTrue($c->acceptsGzip('br;q=1.0, gzip;q=0.8, *;q=0.1'));
        self::assertTrue($c->acceptsGzip('x-gzip'));
        self::assertTrue($c->acceptsGzip('*'));
        self::assertFalse($c->acceptsGzip(''));
        self::assertFalse($c->acceptsGzip('br, deflate'));
        self::assertFalse($c->acceptsGzip('gzip;q=0'));
        self::assertFalse($c->acceptsGzip('*;q=0'));
        self::assertFalse($c->acceptsGzip('gzip;q=0, *'));
    }
}
