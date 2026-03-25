<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Static helper methods for building lightweight stubs used across the test suite.
 *
 * All request stubs are anonymous class implementations of PSR interfaces.
 * Config/Grav/User stubs come from our test Stubs/GravStubs.php and are
 * real instances of the Grav types (either the genuine classes or our
 * minimal stubs depending on the environment).
 */
final class TestHelper
{
    /**
     * Create a stub PSR-7 ServerRequest.
     */
    public static function createMockRequest(
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
        string $body = '',
        array $queryParams = [],
        array $serverParams = [],
        array $attributes = [],
    ): ServerRequestInterface {
        return new class ($method, $path, $headers, $body, $queryParams, $serverParams, $attributes) implements ServerRequestInterface {
            public function __construct(
                private readonly string $method,
                private readonly string $path,
                private readonly array $headers,
                private readonly string $body,
                private readonly array $queryParams,
                private readonly array $serverParams,
                private array $attributes,
            ) {}

            public function getMethod(): string { return $this->method; }

            public function getUri(): UriInterface
            {
                $path = $this->path;
                return new class ($path) implements UriInterface {
                    public function __construct(private readonly string $path) {}
                    public function getScheme(): string { return 'https'; }
                    public function getAuthority(): string { return ''; }
                    public function getUserInfo(): string { return ''; }
                    public function getHost(): string { return 'localhost'; }
                    public function getPort(): ?int { return null; }
                    public function getPath(): string { return $this->path; }
                    public function getQuery(): string { return ''; }
                    public function getFragment(): string { return ''; }
                    public function withScheme(string $scheme): static { return clone $this; }
                    public function withUserInfo(string $user, ?string $password = null): static { return clone $this; }
                    public function withHost(string $host): static { return clone $this; }
                    public function withPort(?int $port): static { return clone $this; }
                    public function withPath(string $path): static { return clone $this; }
                    public function withQuery(string $query): static { return clone $this; }
                    public function withFragment(string $fragment): static { return clone $this; }
                    public function __toString(): string { return $this->path; }
                };
            }

            public function getBody(): StreamInterface
            {
                $body = $this->body;
                return new class ($body) implements StreamInterface {
                    public function __construct(private readonly string $content) {}
                    public function __toString(): string { return $this->content; }
                    public function close(): void {}
                    public function detach() { return null; }
                    public function getSize(): ?int { return strlen($this->content); }
                    public function tell(): int { return 0; }
                    public function eof(): bool { return true; }
                    public function isSeekable(): bool { return false; }
                    public function seek(int $offset, int $whence = SEEK_SET): void {}
                    public function rewind(): void {}
                    public function isWritable(): bool { return false; }
                    public function write(string $string): int { return 0; }
                    public function isReadable(): bool { return true; }
                    public function read(int $length): string { return $this->content; }
                    public function getContents(): string { return $this->content; }
                    public function getMetadata(?string $key = null): mixed { return null; }
                };
            }

            public function getQueryParams(): array { return $this->queryParams; }
            public function getServerParams(): array { return $this->serverParams; }

            public function getHeaderLine(string $name): string
            {
                foreach ($this->headers as $key => $value) {
                    if (strcasecmp($key, $name) === 0) {
                        return $value;
                    }
                }
                return '';
            }

            public function getHeader(string $name): array
            {
                foreach ($this->headers as $key => $value) {
                    if (strcasecmp($key, $name) === 0) {
                        return [$value];
                    }
                }
                return [];
            }

            public function hasHeader(string $name): bool
            {
                foreach ($this->headers as $key => $value) {
                    if (strcasecmp($key, $name) === 0) {
                        return true;
                    }
                }
                return false;
            }

            public function getHeaders(): array { return $this->headers; }

            public function getAttribute(string $name, mixed $default = null): mixed
            {
                return $this->attributes[$name] ?? $default;
            }

            public function withAttribute(string $name, mixed $value): static
            {
                $clone = clone $this;
                $clone->attributes[$name] = $value;
                return $clone;
            }

            public function getRequestTarget(): string { return $this->path; }
            public function withRequestTarget(string $requestTarget): static { return clone $this; }
            public function withMethod(string $method): static { return clone $this; }
            public function withUri(UriInterface $uri, bool $preserveHost = false): static { return clone $this; }
            public function getProtocolVersion(): string { return '1.1'; }
            public function withProtocolVersion(string $version): static { return clone $this; }
            public function withHeader(string $name, $value): static { return clone $this; }
            public function withAddedHeader(string $name, $value): static { return clone $this; }
            public function withoutHeader(string $name): static { return clone $this; }
            public function withBody(StreamInterface $body): static { return clone $this; }
            public function getCookieParams(): array { return []; }
            public function withCookieParams(array $cookies): static { return clone $this; }
            public function withQueryParams(array $query): static { return clone $this; }
            public function getUploadedFiles(): array { return []; }
            public function withUploadedFiles(array $uploadedFiles): static { return clone $this; }
            public function getParsedBody(): mixed { return null; }
            public function withParsedBody($data): static { return clone $this; }
            public function getAttributes(): array { return $this->attributes; }
            public function withoutAttribute(string $name): static {
                $clone = clone $this;
                unset($clone->attributes[$name]);
                return $clone;
            }
        };
    }

    /**
     * Create a Config instance from a nested data array.
     *
     * Returns a real Grav\Common\Config\Config (or our stub equivalent).
     */
    public static function createMockConfig(array $data = []): Config
    {
        return new Config($data);
    }

    /**
     * Create a mock user that duck-types Grav\Common\User\Interfaces\UserInterface.
     *
     * The returned object has a public $username property and supports
     * get(), set(), save(), and exists().
     */
    public static function createMockUser(
        string $username = 'testuser',
        array $data = [],
        bool $exists = true,
    ): UserInterface {
        return new class ($username, $data, $exists) implements UserInterface {
            public readonly string $username;

            public function __construct(
                string $username,
                private array $data,
                private readonly bool $existsFlag,
            ) {
                $this->username = $username;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function save(): void
            {
                // no-op in tests
            }

            public function exists(): bool
            {
                return $this->existsFlag;
            }
        };
    }

    /**
     * Create a Grav container instance with given services.
     *
     * Returns the Grav singleton (reset between calls).
     */
    public static function createMockGrav(array $services = []): Grav
    {
        Grav::resetInstance();
        $grav = Grav::instance();
        foreach ($services as $key => $value) {
            $grav[$key] = $value;
        }
        return $grav;
    }

    /**
     * Create a mock accounts collection that is iterable and supports load().
     */
    public static function createMockAccounts(array $users = []): object
    {
        return new class ($users) implements \IteratorAggregate {
            /** @param array<string, object> $users keyed by username */
            public function __construct(private readonly array $users) {}

            public function getIterator(): \ArrayIterator
            {
                return new \ArrayIterator($this->users);
            }

            public function load(string $username): object
            {
                return $this->users[$username] ?? self::nonExistentUser($username);
            }

            private static function nonExistentUser(string $username): object
            {
                return new class ($username) {
                    public readonly string $username;
                    public function __construct(string $username)
                    {
                        $this->username = $username;
                    }
                    public function exists(): bool { return false; }
                    public function get(string $key, mixed $default = null): mixed { return $default; }
                };
            }
        };
    }
}
