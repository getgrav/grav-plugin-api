<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Yaml;

/**
 * Names the modular types after their blueprints.
 *
 * Core's `Pages::modularTypes()` labels each type with its file name made
 * readable (`featured_product` becomes "Featured product"), so the admin's
 * Add Module list could never say what a theme calls its types. A type's own
 * blueprint (`blueprints/modular/<name>.yaml`) usually has a `title:`, and
 * that is the name the admin shows now; a type without one keeps the
 * readable file name.
 *
 * Only the blueprint that belongs to the type counts. A type that has a
 * template and no blueprint borrows core's `default.yaml`, and a blueprint
 * that `extends@` another inherits its title, so following either would name
 * every such type "Default". Reading the type's own file, unresolved, avoids
 * both.
 */
final class ModuleTypeTitles
{
    /** @var array<string, string|null> type to title, per request */
    private array $titles = [];

    /**
     * @param \Closure(string): array<int, mixed> $filesFor the blueprint files registered for a type, highest priority first (core's `Types` registry)
     * @param \Closure(string): string $translate turns a language key into the admin's wording
     * @param \Closure(string): (string|null) $resolve turns a stream URL (`theme://…`) into a path on disk
     */
    public function __construct(
        private readonly \Closure $filesFor,
        private readonly \Closure $translate,
        private readonly \Closure $resolve,
    ) {}

    /**
     * Swap in the blueprint title for every type that has one.
     *
     * @param array<string|int, mixed> $types type => label, as core lists them
     * @return array<string|int, mixed>
     */
    public function apply(array $types): array
    {
        foreach (array_keys($types) as $type) {
            $title = $this->title((string) $type);
            if ($title !== null) {
                $types[$type] = $title;
            }
        }

        return $types;
    }

    /** The title of `$type`'s own blueprint, or null when it has none. */
    public function title(string $type): ?string
    {
        if (array_key_exists($type, $this->titles)) {
            return $this->titles[$type];
        }

        return $this->titles[$type] = $this->lookUp($type);
    }

    private function lookUp(string $type): ?string
    {
        $name = basename($type);
        if ($name === '') {
            return null;
        }

        try {
            foreach (($this->filesFor)($type) as $file) {
                if (!is_string($file) || basename($file, '.yaml') !== $name) {
                    continue;
                }

                // The first file of the type's own name is the one the admin
                // builds the form from; a lower-priority copy is not consulted.
                $path = str_contains($file, '://') ? ($this->resolve)($file) : $file;
                if (!is_string($path) || !is_file($path)) {
                    return null;
                }

                $blueprint = Yaml::parse((string) file_get_contents($path));
                $title = is_array($blueprint) ? ($blueprint['title'] ?? null) : null;
                if (!is_string($title) || trim($title) === '') {
                    return null;
                }

                $translated = trim(($this->translate)(trim($title)));

                return $translated !== '' ? $translated : null;
            }
        } catch (\Throwable) {
            // A blueprint that does not parse keeps the readable file name.
        }

        return null;
    }
}
