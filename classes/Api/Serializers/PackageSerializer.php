<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Serializers;

use Grav\Common\GPM\Licenses;

class PackageSerializer implements SerializerInterface
{
    public function serialize(object $resource, array $options = []): array
    {
        $data = [
            'slug' => $resource->slug ?? null,
            'name' => $resource->name ?? null,
            'version' => $resource->version ?? null,
            'type' => $options['type'] ?? null,
            'description' => $resource->description ?? null,
            'author' => $this->serializeAuthor($resource),
            'homepage' => $resource->homepage ?? $resource->url ?? null,
        ];

        // Include enabled status + symlink detection for installed packages
        if ($options['installed'] ?? false) {
            $data['enabled'] = $this->isEnabled($resource, $options);
            $data['is_symlink'] = $this->isSymlinked($resource, $options);
        }

        // Include update info if available
        if (isset($resource->available)) {
            $data['available_version'] = $resource->available;
            $data['updatable'] = !empty($resource->available);
        }

        // Include premium status and purchase info
        if (!empty($resource->premium)) {
            $slug = $resource->slug ?? $options['slug_key'] ?? '';
            $premium = $resource->premium;
            $permalink = is_object($premium) ? ($premium->permalink ?? null) : ($premium['permalink'] ?? null);

            $data['premium'] = true;
            $data['licensed'] = !empty(Licenses::get($slug));

            if ($permalink) {
                $data['purchase_url'] = 'https://licensing.getgrav.org/buy/' . $permalink;
            }
        }

        // Include dependencies
        if (!empty($resource->dependencies)) {
            $data['dependencies'] = $resource->dependencies;
        }

        // Include keywords/tags
        if (!empty($resource->keywords)) {
            $data['keywords'] = $resource->keywords;
        }

        // Include icon
        if (!empty($resource->icon)) {
            $data['icon'] = $resource->icon;
        }

        // Include screenshot URL for themes (from GPM repository data)
        if (!empty($resource->screenshot)) {
            $screenshot = $resource->screenshot;
            // GPM returns just a filename — resolve to full URL
            if (!str_starts_with($screenshot, 'http')) {
                $screenshot = 'https://getgrav.org/images/' . $screenshot;
            }
            $data['screenshot'] = $screenshot;
        }

        return $data;
    }

    /**
     * Serialize a collection of packages.
     */
    public function serializeCollection(iterable $packages, array $options = []): array
    {
        $result = [];

        foreach ($packages as $slug => $package) {
            $opts = array_merge($options, ['slug_key' => $slug]);
            $serialized = $this->serialize($package, $opts);
            // Ensure slug is set (some iterators use slug as key)
            if ($serialized['slug'] === null && is_string($slug)) {
                $serialized['slug'] = $slug;
            }
            $result[] = $serialized;
        }

        return $result;
    }

    private function serializeAuthor(object $resource): ?array
    {
        $author = $resource->author ?? null;

        if ($author === null) {
            return null;
        }

        if (is_object($author)) {
            return [
                'name' => $author->name ?? null,
                'email' => $author->email ?? null,
                'url' => $author->url ?? null,
            ];
        }

        if (is_array($author)) {
            return [
                'name' => $author['name'] ?? null,
                'email' => $author['email'] ?? null,
                'url' => $author['url'] ?? null,
            ];
        }

        return null;
    }

    private function isEnabled(object $resource, array $options): bool
    {
        $type = $options['type'] ?? 'plugin';
        $slug = $resource->slug ?? $options['slug_key'] ?? '';

        if ($type === 'plugin') {
            return (bool) (\Grav\Common\Grav::instance()['config']->get("plugins.{$slug}.enabled", false));
        }

        // For themes, check if it's the active theme
        $activeTheme = \Grav\Common\Grav::instance()['config']->get('system.pages.theme');
        return $slug === $activeTheme;
    }

    private function isSymlinked(object $resource, array $options): bool
    {
        $type = $options['type'] ?? 'plugin';
        $slug = $resource->slug ?? $options['slug_key'] ?? '';
        if (!$slug) {
            return false;
        }
        $scheme = $type === 'theme' ? 'themes' : 'plugins';
        $path = \Grav\Common\Grav::instance()['locator']->findResource("{$scheme}://{$slug}", true);
        return $path ? is_link($path) : false;
    }
}
