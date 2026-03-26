<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Serializers;

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

        // Include enabled status for installed packages
        if ($options['installed'] ?? false) {
            $data['enabled'] = $this->isEnabled($resource, $options);
        }

        // Include update info if available
        if (isset($resource->available)) {
            $data['available_version'] = $resource->available;
            $data['updatable'] = !empty($resource->available);
        }

        // Include premium status
        if (isset($resource->premium)) {
            $data['premium'] = !empty($resource->premium);
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
}
