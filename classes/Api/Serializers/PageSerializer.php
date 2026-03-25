<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Serializers;

use DateTimeImmutable;
use DateTimeZone;
use Grav\Common\Page\Interfaces\PageInterface;

class PageSerializer implements SerializerInterface
{
    public function serialize(object $resource, array $options = []): array
    {
        /** @var PageInterface $resource */
        $includeContent = $options['include_content'] ?? true;
        $renderContent = $options['render_content'] ?? false;
        $includeChildren = $options['include_children'] ?? false;
        $childrenDepth = $options['children_depth'] ?? 1;
        $includeMedia = $options['include_media'] ?? true;

        $data = [
            'route' => $resource->route(),
            'slug' => $resource->slug(),
            'title' => $resource->title(),
            'template' => $resource->template(),
            'header' => $this->serializeHeader($resource->header()),
            'taxonomy' => $resource->taxonomy(),
            'published' => $resource->published(),
            'visible' => $resource->visible(),
            'routable' => $resource->routable(),
            'date' => $this->formatTimestamp($resource->date()),
            'modified' => $this->formatTimestamp($resource->modified()),
            'order' => $resource->order(),
        ];

        if ($includeContent) {
            $data['content'] = $resource->rawMarkdown();
        }

        if ($renderContent) {
            $data['content_html'] = $resource->content();
        }

        if ($includeMedia) {
            $data['media'] = $this->serializeMedia($resource);
        }

        if ($includeChildren && $childrenDepth > 0) {
            $data['children'] = $this->serializeChildren(
                $resource,
                $options,
                $childrenDepth,
            );
        }

        return $data;
    }

    /**
     * Serialize a collection of pages.
     */
    public function serializeCollection(iterable $pages, array $options = []): array
    {
        $result = [];

        foreach ($pages as $page) {
            $result[] = $this->serialize($page, $options);
        }

        return $result;
    }

    /**
     * Convert page header object to an associative array.
     */
    private function serializeHeader(object|null $header): array
    {
        if ($header === null) {
            return [];
        }

        return json_decode(json_encode($header), true) ?: [];
    }

    /**
     * Serialize the media collection attached to a page.
     */
    private function serializeMedia(PageInterface $page): array
    {
        $media = $page->media();
        $result = [];

        foreach ($media->all() as $filename => $medium) {
            $result[] = [
                'filename' => $medium->filename,
                'type' => $medium->get('mime'),
                'size' => $medium->get('size'),
            ];
        }

        return $result;
    }

    /**
     * Recursively serialize children pages up to the specified depth.
     */
    private function serializeChildren(PageInterface $page, array $options, int $depth): array
    {
        $childOptions = array_merge($options, [
            'include_children' => $depth > 1,
            'children_depth' => $depth - 1,
        ]);

        $result = [];

        foreach ($page->children() as $child) {
            $result[] = $this->serialize($child, $childOptions);
        }

        return $result;
    }

    /**
     * Format a Unix timestamp as ISO 8601.
     */
    private function formatTimestamp(int|null $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === 0) {
            return null;
        }

        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DateTimeImmutable::ATOM);
    }
}
