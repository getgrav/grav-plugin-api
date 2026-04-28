<?php

/**
 * Minimal stub classes for Grav CMS types used in the API plugin.
 *
 * These are loaded ONLY when the real Grav classes are not available
 * (i.e., when the plugin is tested outside of a Grav installation).
 * They provide just enough structure for the plugin classes to be
 * instantiated and tested in isolation.
 */

// Only declare stubs if the real classes don't exist.
// This file is loaded by the bootstrap's fallback autoloader.

namespace Grav\Common\Config {
    if (!class_exists(\Grav\Common\Config\Config::class, false)) {
        class Config
        {
            public function __construct(protected array $items = []) {}

            public function get(string $key, mixed $default = null): mixed
            {
                $segments = explode('.', $key);
                $current = $this->items;

                foreach ($segments as $segment) {
                    if (!is_array($current) || !array_key_exists($segment, $current)) {
                        return $default;
                    }
                    $current = $current[$segment];
                }

                return $current;
            }

            public function set(string $key, mixed $value): void
            {
                $segments = explode('.', $key);
                $current = &$this->items;

                foreach ($segments as $i => $segment) {
                    if ($i === count($segments) - 1) {
                        $current[$segment] = $value;
                    } else {
                        if (!isset($current[$segment]) || !is_array($current[$segment])) {
                            $current[$segment] = [];
                        }
                        $current = &$current[$segment];
                    }
                }
            }
        }
    }
}

namespace Grav\Common {
    if (!class_exists(\Grav\Common\Yaml::class, false)) {
        // Thin shim over symfony/yaml so tests that need YAML parsing run
        // without the full Grav core on the classpath.
        abstract class Yaml
        {
            public static function parse(string $data): array
            {
                $parsed = \Symfony\Component\Yaml\Yaml::parse($data);
                return is_array($parsed) ? $parsed : [];
            }

            public static function dump(mixed $data, ?int $inline = null, ?int $indent = null): string
            {
                return \Symfony\Component\Yaml\Yaml::dump($data, $inline ?? 5, $indent ?? 2);
            }
        }
    }

    if (!class_exists(\Grav\Common\Grav::class, false)) {
        class Grav implements \ArrayAccess
        {
            private static ?self $instance = null;
            private array $services = [];

            /** @var array<int, array{name: string, event: object}> Recorded event firings for test assertions. */
            private array $firedEvents = [];

            public static function instance(): static
            {
                if (self::$instance === null) {
                    self::$instance = new static();
                }
                return self::$instance;
            }

            /** Reset the singleton (useful between tests). */
            public static function resetInstance(): void
            {
                self::$instance = null;
            }

            /**
             * Fire a Grav event (stub implementation).
             * Records the event for later assertion in tests.
             */
            public function fireEvent(string $name, ?object $event = null): object
            {
                $event = $event ?? new \stdClass();
                $this->firedEvents[] = ['name' => $name, 'event' => $event];
                return $event;
            }

            /**
             * Get all recorded fired events (for test assertions).
             * @return array<int, array{name: string, event: object}>
             */
            public function getFiredEvents(): array
            {
                return $this->firedEvents;
            }

            /** Clear the recorded events list. */
            public function clearFiredEvents(): void
            {
                $this->firedEvents = [];
            }

            public function offsetExists(mixed $offset): bool
            {
                return isset($this->services[$offset]);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->services[$offset] ?? null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                $this->services[$offset] = $value;
            }

            public function offsetUnset(mixed $offset): void
            {
                unset($this->services[$offset]);
            }
        }
    }
}

namespace Grav\Common\User\Interfaces {
    if (!interface_exists(\Grav\Common\User\Interfaces\UserInterface::class, false)) {
        interface UserInterface
        {
            public function get(string $key, mixed $default = null): mixed;
            public function set(string $key, mixed $value): void;
            public function save(): void;
            public function exists(): bool;
        }
    }

    if (!interface_exists(\Grav\Common\User\Interfaces\UserCollectionInterface::class, false)) {
        interface UserCollectionInterface extends \Traversable
        {
            public function load(string $username): UserInterface;
        }
    }
}

namespace Grav\Common\Page\Interfaces {
    if (!interface_exists(\Grav\Common\Page\Interfaces\PageInterface::class, false)) {
        interface PageInterface
        {
            public function route($var = null): ?string;
            public function slug($var = null): string;
            public function order($var = null): ?int;
            public function path($var = null): ?string;
            public function title($var = null): string;
            public function isModule(): bool;
            public function children(): \Traversable;
        }
    }
}

namespace Grav\Common\Filesystem {
    if (!class_exists(\Grav\Common\Filesystem\Folder::class, false)) {
        class Folder
        {
            public static function move(string $source, string $target): void
            {
                if (is_dir($source)) {
                    rename($source, $target);
                }
            }
        }
    }
}

namespace Grav\Framework\Psr7 {
    if (!class_exists(\Grav\Framework\Psr7\Response::class, false)) {
        /**
         * Minimal PSR-7 Response implementation for testing.
         */
        class Response implements \Psr\Http\Message\ResponseInterface
        {
            /** @var array<string, string[]> */
            private array $headerValues = [];
            /** @var string */
            private string $body;

            public function __construct(
                private int $statusCode = 200,
                array $headers = [],
                string $body = '',
                private string $protocolVersion = '1.1',
                private string $reasonPhrase = '',
            ) {
                foreach ($headers as $name => $value) {
                    $this->headerValues[strtolower($name)] = [
                        'original' => $name,
                        'values' => is_array($value) ? $value : [$value],
                    ];
                }
                $this->body = $body;
            }

            public function getStatusCode(): int { return $this->statusCode; }

            public function withStatus(int $code, string $reasonPhrase = ''): static
            {
                $clone = clone $this;
                $clone->statusCode = $code;
                $clone->reasonPhrase = $reasonPhrase;
                return $clone;
            }

            public function getReasonPhrase(): string { return $this->reasonPhrase; }
            public function getProtocolVersion(): string { return $this->protocolVersion; }
            public function withProtocolVersion(string $version): static
            {
                $clone = clone $this;
                $clone->protocolVersion = $version;
                return $clone;
            }

            public function getHeaders(): array
            {
                $result = [];
                foreach ($this->headerValues as $info) {
                    $result[$info['original']] = $info['values'];
                }
                return $result;
            }

            public function hasHeader(string $name): bool
            {
                return isset($this->headerValues[strtolower($name)]);
            }

            public function getHeader(string $name): array
            {
                return $this->headerValues[strtolower($name)]['values'] ?? [];
            }

            public function getHeaderLine(string $name): string
            {
                return implode(', ', $this->getHeader($name));
            }

            public function withHeader(string $name, $value): static
            {
                $clone = clone $this;
                $clone->headerValues[strtolower($name)] = [
                    'original' => $name,
                    'values' => is_array($value) ? $value : [$value],
                ];
                return $clone;
            }

            public function withAddedHeader(string $name, $value): static
            {
                $clone = clone $this;
                $lower = strtolower($name);
                $existing = $clone->headerValues[$lower]['values'] ?? [];
                $clone->headerValues[$lower] = [
                    'original' => $clone->headerValues[$lower]['original'] ?? $name,
                    'values' => array_merge($existing, is_array($value) ? $value : [$value]),
                ];
                return $clone;
            }

            public function withoutHeader(string $name): static
            {
                $clone = clone $this;
                unset($clone->headerValues[strtolower($name)]);
                return $clone;
            }

            public function getBody(): \Psr\Http\Message\StreamInterface
            {
                $content = $this->body;
                return new class ($content) implements \Psr\Http\Message\StreamInterface {
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

            public function withBody(\Psr\Http\Message\StreamInterface $body): static
            {
                $clone = clone $this;
                $clone->body = (string) $body;
                return $clone;
            }
        }
    }
}

namespace RocketTheme\Toolbox\Event {
    if (!class_exists(\RocketTheme\Toolbox\Event\Event::class, false)) {
        /**
         * Minimal Event stub that supports array access for event data.
         */
        class Event implements \ArrayAccess
        {
            private array $data;

            public function __construct(array $data = [])
            {
                $this->data = $data;
            }

            public function offsetExists(mixed $offset): bool
            {
                return array_key_exists($offset, $this->data);
            }

            public function &offsetGet(mixed $offset): mixed
            {
                return $this->data[$offset];
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                $this->data[$offset] = $value;
            }

            public function offsetUnset(mixed $offset): void
            {
                unset($this->data[$offset]);
            }

            public function toArray(): array
            {
                return $this->data;
            }
        }
    }
}

namespace Grav\Common\Page {
    if (!class_exists(\Grav\Common\Page\Page::class, false)) {
        /**
         * Minimal Page stub for testing controllers that instantiate Page directly.
         */
        class Page
        {
            private ?string $filePath = null;
            private object $header;
            private string $rawMarkdown = '';
            private string $template = 'default';
            private string $name = 'default.md';
            private ?string $path = null;
            private ?string $route = null;
            private ?string $slug = null;
            private ?int $order = null;
            private ?string $lang = null;

            public function __construct()
            {
                $this->header = new \stdClass();
            }

            public function filePath(?string $path = null): ?string
            {
                if ($path !== null) {
                    $this->filePath = $path;
                    // Derive path (directory) from filePath
                    $this->path = dirname($path);
                }
                return $this->filePath;
            }

            public function header($var = null)
            {
                if ($var !== null) {
                    $this->header = is_array($var) ? (object) $var : $var;
                }
                return $this->header;
            }

            public function rawMarkdown(?string $var = null): string
            {
                if ($var !== null) {
                    $this->rawMarkdown = $var;
                }
                return $this->rawMarkdown;
            }

            public function template(?string $var = null): string
            {
                if ($var !== null) {
                    $this->template = $var;
                }
                return $this->template;
            }

            public function name(?string $var = null): string
            {
                if ($var !== null) {
                    $this->name = $var;
                }
                return $this->name;
            }

            public function path(?string $var = null): ?string
            {
                if ($var !== null) {
                    $this->path = $var;
                }
                return $this->path;
            }

            public function route($var = null): ?string
            {
                if ($var !== null) {
                    $this->route = $var;
                }
                return $this->route;
            }

            public function slug($var = null): ?string
            {
                if ($var !== null) {
                    $this->slug = $var;
                }
                return $this->slug;
            }

            public function order($var = null): ?int
            {
                if ($var !== null) {
                    $this->order = $var;
                }
                return $this->order;
            }

            public function language(?string $var = null): ?string
            {
                if ($var !== null) {
                    $this->lang = $var;
                }
                return $this->lang;
            }

            public function title($var = null): string
            {
                return $this->header->title ?? '';
            }

            public function save($reorder = true): void
            {
                // No-op in tests — the actual file writing is not needed
            }

            public function isModule(): bool
            {
                return false;
            }

            public function children(): \Traversable
            {
                return new \ArrayIterator([]);
            }

            public function translatedLanguages(): array
            {
                return [];
            }

            public function file(): ?object
            {
                return null;
            }

            public function content($var = null): string
            {
                return $this->rawMarkdown;
            }
        }
    }

    if (!class_exists(\Grav\Common\Page\Media::class, false)) {
        /**
         * Minimal Media stub.
         */
        class Media
        {
            public function __construct(private readonly ?string $path = null) {}

            public function all(): array
            {
                return [];
            }
        }
    }
}

namespace Grav\Common\GPM {
    if (!class_exists(\Grav\Common\GPM\GPM::class, false)) {
        /**
         * Minimal GPM stub. Methods are intentionally non-final and present
         * here only so PHPUnit's createMock() can produce a mock subclass.
         * Behavior is supplied per-test via mock expectations.
         */
        class GPM
        {
            public function __construct(bool $refresh = false, $callback = null) {}

            public function getUpdatable(): array { return []; }
            public function isUpdatable(string $slug): bool { return false; }
            public function checkPackagesCanBeInstalled(array $slugs): void {}
            public function getDependencies(array $slugs): array { return []; }
        }
    }
}

namespace Grav\Common {
    if (!class_exists(\Grav\Common\Utils::class, false)) {
        /**
         * Minimal Utils stub. Currently only arrayFlattenDotNotation() is
         * exercised by unit tests (via PermissionResolver).
         */
        class Utils
        {
            public static function arrayFlattenDotNotation(array $array, string $prepend = ''): array
            {
                $results = [];
                foreach ($array as $key => $value) {
                    if (is_array($value) && !empty($value)) {
                        $results = array_merge($results, self::arrayFlattenDotNotation($value, $prepend . $key . '.'));
                    } else {
                        $results[$prepend . $key] = $value;
                    }
                }
                return $results;
            }
        }
    }
}

namespace Grav\Framework\Acl {
    if (!class_exists(\Grav\Framework\Acl\Permissions::class, false)) {
        /**
         * Minimal Permissions stub so PermissionResolver can be constructed.
         * Only resolvedMap() touches getInstances(); resolve() reads only the
         * user's access array, so most unit tests get away with an empty stub.
         */
        class Permissions
        {
            /** @return array<string, object> */
            public function getInstances(): array { return []; }
        }
    }
}

namespace Grav\Common\Data {
    if (!class_exists(\Grav\Common\Data\Data::class, false)) {
        /**
         * Minimal Data stub for config wrapping.
         */
        class Data
        {
            public function __construct(private array $items = []) {}

            public function toArray(): array
            {
                return $this->items;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->items[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->items[$key] = $value;
            }
        }
    }
}
