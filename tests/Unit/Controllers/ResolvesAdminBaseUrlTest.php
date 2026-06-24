<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Plugin\Api\Controllers\ResolvesAdminBaseUrl;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Regression coverage for GHSA-5xc4-j99p-cp4m (pre-auth reset/invite token
 * poisoning). The base URL used to build self-referential email links must
 * only ever be the server's own origin or an explicitly allowlisted CORS
 * origin — never an arbitrary host taken from the request body or headers.
 */
#[Group('security')]
class ResolvesAdminBaseUrlTest extends TestCase
{
    public const SERVER_URL = 'https://admin.example.com';

    /**
     * Build an object that exposes the trait under test, wired to a Grav whose
     * own root URL is SERVER_URL and the given list of allowlisted CORS origins.
     */
    private function buildResolver(array $corsOrigins = []): object
    {
        $uri = new class {
            public function rootUrl($includeHost = false)
            {
                // includeHost → full origin; otherwise the (empty) base path.
                return $includeHost ? ResolvesAdminBaseUrlTest::SERVER_URL : '';
            }
        };

        $grav = TestHelper::createMockGrav(['uri' => $uri]);
        $config = TestHelper::createMockConfig([
            'plugins' => ['api' => ['cors' => ['origins' => $corsOrigins]]],
        ]);

        return new class ($grav, $config) {
            use ResolvesAdminBaseUrl;

            public function __construct(public $grav, public $config) {}

            public function resolve(
                mixed $clientBaseUrl,
                ServerRequestInterface $request,
                array $stripSuffixes = ['/forgot'],
            ): string {
                return $this->resolveAdminBaseUrl($clientBaseUrl, $request, $stripSuffixes);
            }
        };
    }

    #[Test]
    public function body_url_matching_server_origin_is_accepted(): void
    {
        $resolver = $this->buildResolver();
        $request = TestHelper::createMockRequest();

        $result = $resolver->resolve(self::SERVER_URL, $request);

        self::assertSame(self::SERVER_URL, $result);
    }

    #[Test]
    public function body_url_matching_allowlisted_cors_origin_is_accepted(): void
    {
        $resolver = $this->buildResolver(['https://app.example.com']);
        $request = TestHelper::createMockRequest();

        $result = $resolver->resolve('https://app.example.com', $request);

        self::assertSame('https://app.example.com', $result);
    }

    #[Test]
    public function attacker_body_url_is_rejected_and_falls_back_to_server(): void
    {
        // The core GHSA-5xc4-j99p-cp4m vector: a client-supplied admin_base_url
        // pointing at an attacker host must never be used for the reset link.
        $resolver = $this->buildResolver();
        $request = TestHelper::createMockRequest();

        $result = $resolver->resolve('http://attacker.com', $request);

        self::assertSame(self::SERVER_URL, $result);
        self::assertStringNotContainsString('attacker.com', $result);
    }

    #[Test]
    public function attacker_referer_is_rejected_and_falls_back_to_server(): void
    {
        $resolver = $this->buildResolver();
        $request = TestHelper::createMockRequest(
            headers: ['Referer' => 'http://attacker.com/forgot'],
        );

        $result = $resolver->resolve(null, $request);

        self::assertSame(self::SERVER_URL, $result);
        self::assertStringNotContainsString('attacker.com', $result);
    }

    #[Test]
    public function attacker_origin_header_is_rejected_and_falls_back_to_server(): void
    {
        $resolver = $this->buildResolver();
        $request = TestHelper::createMockRequest(
            headers: ['Origin' => 'http://attacker.com'],
        );

        $result = $resolver->resolve(null, $request);

        self::assertSame(self::SERVER_URL, $result);
        self::assertStringNotContainsString('attacker.com', $result);
    }

    #[Test]
    public function allowlisted_referer_strips_known_suffix(): void
    {
        // A Referer from the real admin (server origin) with the /forgot path
        // is accepted, and the /forgot suffix is trimmed back to the root.
        $resolver = $this->buildResolver();
        $request = TestHelper::createMockRequest(
            headers: ['Referer' => self::SERVER_URL . '/forgot'],
        );

        $result = $resolver->resolve(null, $request);

        self::assertSame(self::SERVER_URL, $result);
    }

    #[Test]
    public function wildcard_cors_origin_does_not_trust_arbitrary_host(): void
    {
        // `*` is meaningful only for reflecting unauthenticated CORS responses;
        // it must never let an attacker host receive a secret reset token.
        $resolver = $this->buildResolver(['*']);
        $request = TestHelper::createMockRequest();

        $result = $resolver->resolve('http://attacker.com', $request);

        self::assertSame(self::SERVER_URL, $result);
    }

    #[Test]
    public function origin_match_is_case_insensitive_on_host(): void
    {
        $resolver = $this->buildResolver(['https://app.example.com']);
        $request = TestHelper::createMockRequest();

        $result = $resolver->resolve('https://APP.example.com', $request);

        // Accepted (host comparison is case-insensitive per the URL spec).
        self::assertSame('https://APP.example.com', $result);
    }

    #[Test]
    public function non_http_scheme_is_rejected(): void
    {
        $resolver = $this->buildResolver();
        $request = TestHelper::createMockRequest();

        $result = $resolver->resolve('javascript:alert(1)', $request);

        self::assertSame(self::SERVER_URL, $result);
    }
}
