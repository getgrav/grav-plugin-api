<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Exceptions;

use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ConflictException;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\UnauthorizedException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiException::class)]
#[CoversClass(NotFoundException::class)]
#[CoversClass(ForbiddenException::class)]
#[CoversClass(UnauthorizedException::class)]
#[CoversClass(ValidationException::class)]
#[CoversClass(ConflictException::class)]
class ExceptionTest extends TestCase
{
    #[Test]
    public function api_exception_properties(): void
    {
        $exception = new ApiException(
            statusCode: 418,
            errorTitle: "I'm a Teapot",
            detail: 'Short and stout.',
            headers: ['X-Teapot' => 'yes'],
        );

        self::assertSame(418, $exception->getStatusCode());
        self::assertSame("I'm a Teapot", $exception->getErrorTitle());
        self::assertSame('Short and stout.', $exception->getMessage());
        self::assertSame(['X-Teapot' => 'yes'], $exception->getHeaders());

        // The code property of RuntimeException should be the HTTP status
        self::assertSame(418, $exception->getCode());
    }

    #[Test]
    public function api_exception_with_previous(): void
    {
        $previous = new \RuntimeException('Root cause');
        $exception = new ApiException(500, 'Internal Server Error', 'Something broke.', previous: $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function not_found_exception_defaults(): void
    {
        $exception = new NotFoundException();

        self::assertSame(404, $exception->getStatusCode());
        self::assertSame('Not Found', $exception->getErrorTitle());
        self::assertSame('The requested resource was not found.', $exception->getMessage());
        self::assertSame([], $exception->getHeaders());
    }

    #[Test]
    public function not_found_exception_custom_detail(): void
    {
        $exception = new NotFoundException('Page /blog/missing was not found.');

        self::assertSame(404, $exception->getStatusCode());
        self::assertSame('Page /blog/missing was not found.', $exception->getMessage());
    }

    #[Test]
    public function forbidden_exception_defaults(): void
    {
        $exception = new ForbiddenException();

        self::assertSame(403, $exception->getStatusCode());
        self::assertSame('Forbidden', $exception->getErrorTitle());
        self::assertSame('You do not have permission to perform this action.', $exception->getMessage());
        self::assertSame([], $exception->getHeaders());
    }

    #[Test]
    public function unauthorized_exception_has_www_authenticate_header(): void
    {
        $exception = new UnauthorizedException();

        self::assertSame(401, $exception->getStatusCode());
        self::assertSame('Unauthorized', $exception->getErrorTitle());
        self::assertSame('Authentication is required.', $exception->getMessage());

        $headers = $exception->getHeaders();
        self::assertArrayHasKey('WWW-Authenticate', $headers);
        self::assertSame('Bearer', $headers['WWW-Authenticate']);
    }

    #[Test]
    public function unauthorized_exception_custom_detail(): void
    {
        $exception = new UnauthorizedException('Token has expired.');

        self::assertSame(401, $exception->getStatusCode());
        self::assertSame('Token has expired.', $exception->getMessage());
        self::assertSame('Bearer', $exception->getHeaders()['WWW-Authenticate']);
    }

    #[Test]
    public function validation_exception_includes_errors(): void
    {
        $errors = [
            ['field' => 'email', 'message' => 'Email is required.'],
            ['field' => 'name', 'message' => 'Name must not be empty.'],
        ];

        $exception = new ValidationException('Validation failed.', $errors);

        self::assertSame(422, $exception->getStatusCode());
        self::assertSame('Unprocessable Entity', $exception->getErrorTitle());
        self::assertSame('Validation failed.', $exception->getMessage());
        self::assertSame($errors, $exception->getValidationErrors());
    }

    #[Test]
    public function validation_exception_defaults(): void
    {
        $exception = new ValidationException();

        self::assertSame(422, $exception->getStatusCode());
        self::assertSame('The request data is invalid.', $exception->getMessage());
        self::assertSame([], $exception->getValidationErrors());
    }

    #[Test]
    public function conflict_exception_defaults(): void
    {
        $exception = new ConflictException();

        self::assertSame(409, $exception->getStatusCode());
        self::assertSame('Conflict', $exception->getErrorTitle());
        self::assertSame('The resource has been modified. Refresh and try again.', $exception->getMessage());
        self::assertSame([], $exception->getHeaders());
    }

    #[Test]
    public function conflict_exception_custom_detail(): void
    {
        $exception = new ConflictException('ETag mismatch for page /about.');

        self::assertSame(409, $exception->getStatusCode());
        self::assertSame('ETag mismatch for page /about.', $exception->getMessage());
    }

    #[Test]
    public function all_exceptions_extend_api_exception(): void
    {
        self::assertInstanceOf(ApiException::class, new NotFoundException());
        self::assertInstanceOf(ApiException::class, new ForbiddenException());
        self::assertInstanceOf(ApiException::class, new UnauthorizedException());
        self::assertInstanceOf(ApiException::class, new ValidationException());
        self::assertInstanceOf(ApiException::class, new ConflictException());
    }

    #[Test]
    public function api_exception_extends_runtime_exception(): void
    {
        self::assertInstanceOf(\RuntimeException::class, new ApiException(500, 'Error', 'test'));
    }
}
