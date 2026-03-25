<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Response;

use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ErrorResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(ErrorResponse::class)]
class ErrorResponseTest extends TestCase
{
    #[Test]
    public function create_returns_problem_json(): void
    {
        $response = ErrorResponse::create(400, 'Bad Request', 'Something was wrong.');

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertStringContainsString(
            'application/problem+json',
            $response->getHeaderLine('Content-Type'),
        );
    }

    #[Test]
    public function create_includes_status_title_detail(): void
    {
        $response = ErrorResponse::create(422, 'Unprocessable Entity', 'Name is required.');

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(422, $body['status']);
        self::assertSame('Unprocessable Entity', $body['title']);
        self::assertSame('Name is required.', $body['detail']);
    }

    #[Test]
    public function create_with_custom_headers(): void
    {
        $response = ErrorResponse::create(
            429,
            'Too Many Requests',
            'Rate limit exceeded.',
            ['Retry-After' => '60'],
        );

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('60', $response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function create_includes_cache_control(): void
    {
        $response = ErrorResponse::create(500, 'Error', 'Oops.');

        self::assertSame('no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function from_exception(): void
    {
        $exception = new ApiException(
            statusCode: 404,
            errorTitle: 'Not Found',
            detail: 'Page /missing not found.',
        );

        $response = ErrorResponse::fromException($exception);

        self::assertSame(404, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(404, $body['status']);
        self::assertSame('Not Found', $body['title']);
        self::assertSame('Page /missing not found.', $body['detail']);
    }

    #[Test]
    public function from_validation_exception_includes_errors(): void
    {
        $errors = [
            ['field' => 'title', 'message' => 'Title is required.'],
            ['field' => 'slug', 'message' => 'Slug must be unique.'],
        ];

        $exception = new ValidationException(
            detail: 'Validation failed.',
            errors: $errors,
        );

        $response = ErrorResponse::fromException($exception);

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('errors', $body);
        self::assertCount(2, $body['errors']);
        self::assertSame('Title is required.', $body['errors'][0]['message']);
        self::assertSame('slug', $body['errors'][1]['field']);
    }

    #[Test]
    public function from_exception_with_no_validation_errors_has_no_errors_key(): void
    {
        $exception = new ApiException(
            statusCode: 403,
            errorTitle: 'Forbidden',
            detail: 'Access denied.',
        );

        $response = ErrorResponse::fromException($exception);

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayNotHasKey('errors', $body);
    }

    #[Test]
    public function from_exception_preserves_custom_headers(): void
    {
        $exception = new ApiException(
            statusCode: 401,
            errorTitle: 'Unauthorized',
            detail: 'Token expired.',
            headers: ['WWW-Authenticate' => 'Bearer'],
        );

        $response = ErrorResponse::fromException($exception);

        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    }
}
