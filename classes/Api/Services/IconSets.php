<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;
use Grav\Common\Yaml;
use RocketTheme\Toolbox\Event\Event;

/**
 * Icon sets a theme or plugin hands the admin's `iconpicker` field.
 *
 * The picker lists Font Awesome, which a theme that draws its own inline SVG
 * icons never loads, so an icon picked there would not show on the site. A
 * blueprint field names a set instead (`icons: encore`), and whoever owns the
 * set fills it in on `onApiIconSets`:
 *
 *     public function onApiIconSets(Event $event): void
 *     {
 *         $event['sets']['encore'] = [
 *             'label' => 'Encore',
 *             'icons' => [
 *                 'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
 *                 'clock'  => 'fa:clock',
 *             ],
 *             // or a YAML file of name: markup lines instead of `icons`, named by a
 *             // theme://, plugin://, user://, plugins:// or themes:// stream URL:
 *             // 'file' => 'theme://icons.yaml',
 *         ];
 *     }
 *
 * An icon is SVG markup (a whole `<svg>` or only what goes inside one, on a
 * 24px grid unless the `<svg>` says otherwise) or any IconSpec the admin
 * already takes. Markup never reaches the browser: it is read here into the
 * structured `{type: svg, viewBox, elements}` form, keeping only the shapes
 * and attributes Admin2's icon renderer allows, and the browser checks them
 * again before drawing. The picker saves the icon's name.
 *
 * The event fires once per request, however many fields ask.
 */
final class IconSets
{
    /** Shapes and attributes kept from markup, the same list Admin2's icon renderer allows. */
    private const TAGS = ['path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse'];
    private const ATTRS = [
        'd', 'cx', 'cy', 'r', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'rx', 'ry',
        'width', 'height', 'points', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
    ];

    /** The streams a set's `file` may name. */
    private const STREAMS = ['theme', 'plugin', 'user', 'plugins', 'themes'];

    /** Most icons a set may list; a set larger than this is cut off. */
    private const MAX_ICONS = 2000;

    /** @var array<string, mixed>|null the sets as their owners registered them */
    private ?array $registered = null;

    /** @var array<string, array<string, mixed>|null> */
    private array $resolved = [];

    /**
     * @param \Closure(string): (string|null)|null $resolve turns a stream URL into a path on disk
     */
    public function __construct(private readonly Grav $grav, private readonly ?\Closure $resolve = null) {}

    /**
     * The set called `$name`, ready for a field, or null when nobody
     * registered it or it holds no icon that can be drawn.
     *
     * @return array{name: string, label: string, icons: list<array{name: string, icon: mixed}>}|null
     */
    public function get(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        if (!array_key_exists($name, $this->resolved)) {
            $set = $this->registered()[$name] ?? null;
            $this->resolved[$name] = is_array($set) ? $this->normalize($name, $set) : null;
        }

        return $this->resolved[$name];
    }

    /** @return array<string, mixed> */
    private function registered(): array
    {
        if ($this->registered === null) {
            $event = new Event(['sets' => []]);
            try {
                $this->grav->fireEvent('onApiIconSets', $event);
                $sets = $event['sets'];
            } catch (\Throwable) {
                $sets = [];
            }
            $this->registered = is_array($sets) ? $sets : [];
        }

        return $this->registered;
    }

    /**
     * @param array<string, mixed> $set
     * @return array{name: string, label: string, icons: list<array{name: string, icon: mixed}>}|null
     */
    private function normalize(string $name, array $set): ?array
    {
        $icons = $set['icons'] ?? null;
        if (!is_array($icons) && is_string($set['file'] ?? null)) {
            $icons = $this->readFile($set['file']);
        }
        if (!is_array($icons)) {
            return null;
        }

        $list = [];
        foreach ($icons as $iconName => $icon) {
            if (count($list) >= self::MAX_ICONS) {
                break;
            }
            $iconName = trim((string) $iconName);
            $spec = self::spec($icon);
            if ($iconName === '' || $spec === null) {
                continue;
            }
            $list[] = ['name' => $iconName, 'icon' => $spec];
        }

        if ($list === []) {
            return null;
        }

        $label = $set['label'] ?? null;

        return [
            'name' => $name,
            'label' => is_string($label) && trim($label) !== '' ? trim($label) : $name,
            'icons' => $list,
        ];
    }

    /**
     * Reads a set's YAML file. Only a Grav stream URL (`theme://icons.yaml`) is
     * accepted, resolved through the locator, so a registered set can't point
     * the API at an arbitrary file: no plain or absolute path, no `..`, and the
     * file the stream resolves to has to sit inside that stream's own folders
     * once symlinks are followed, and be a `.yaml` or `.yml` file.
     *
     * @return array<string|int, mixed>|null
     */
    private function readFile(string $file): ?array
    {
        try {
            if (!preg_match('~^(' . implode('|', self::STREAMS) . ')://(.+)$~', $file, $m)) {
                return null;
            }
            [, $scheme, $rest] = $m;
            if (str_contains($file, "\0") || in_array('..', preg_split('~[/\\\\]~', $rest) ?: [], true)) {
                return null;
            }

            $path = $this->locate($file);
            $real = $path === null ? false : realpath($path);
            if ($real === false || !is_file($real) || !preg_match('/\.ya?ml$/i', $real)) {
                return null;
            }

            $inside = false;
            foreach ($this->bases($scheme) as $base) {
                $base = realpath($base);
                if ($base !== false && PathContainment::isWithin($real, $base)) {
                    $inside = true;
                    break;
                }
            }
            if (!$inside) {
                return null;
            }

            $data = Yaml::parse((string) file_get_contents($real));

            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function locate(string $url): ?string
    {
        $path = $this->resolve
            ? ($this->resolve)($url)
            : $this->grav['locator']->findResource($url, true);

        return is_string($path) ? $path : null;
    }

    /**
     * The folders a stream covers (a theme's own and its parent's, for
     * example), which a file read through it has to stay inside.
     *
     * @return list<string>
     */
    private function bases(string $scheme): array
    {
        if ($this->resolve) {
            $base = ($this->resolve)($scheme . '://');

            return is_string($base) ? [$base] : [];
        }

        $bases = $this->grav['locator']->findResources($scheme . '://', true);

        return is_array($bases) ? array_values(array_filter($bases, 'is_string')) : [];
    }

    /**
     * One icon as an IconSpec: markup is read into structured SVG data, a
     * string that is not markup (`fa:clock`, `class:ti ti-user`) and a
     * structured spec pass through as they are.
     */
    public static function spec(mixed $icon): mixed
    {
        if (is_array($icon)) {
            return isset($icon['type']) && is_string($icon['type']) ? $icon : null;
        }
        if (!is_string($icon)) {
            return null;
        }

        $icon = trim($icon);
        if ($icon === '') {
            return null;
        }

        return str_contains($icon, '<') ? self::fromMarkup($icon) : $icon;
    }

    /** @return array{type: string, viewBox: string, elements: list<array{tag: string, attrs: array<string, string>}>}|null */
    public static function fromMarkup(string $markup): ?array
    {
        if (strlen($markup) > 20000) {
            return null;
        }

        // Only what goes inside an <svg> is common in a theme's icon table, so
        // give it one when it has none. Comments and processing instructions go.
        $markup = (string) preg_replace('/<\?.*?\?>|<!--.*?-->|<!DOCTYPE[^>]*>/is', '', $markup);
        if (!preg_match('/^\s*<svg[\s>]/i', $markup)) {
            $markup = '<svg viewBox="0 0 24 24">' . $markup . '</svg>';
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadXML($markup, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $loaded ? $dom->documentElement : null;
        if (!$root || strtolower($root->localName) !== 'svg') {
            return null;
        }

        $viewBox = trim($root->getAttribute('viewBox'));
        if ($viewBox === '' || strlen($viewBox) > 64 || !preg_match('/^[0-9.+\-\s]+$/', $viewBox)) {
            $viewBox = '0 0 24 24';
        }

        $elements = [];
        foreach ($root->getElementsByTagName('*') as $node) {
            $tag = strtolower($node->localName);
            if (!in_array($tag, self::TAGS, true)) {
                continue;
            }
            $attrs = [];
            foreach (self::ATTRS as $attr) {
                if ($node->hasAttribute($attr)) {
                    $attrs[$attr] = trim($node->getAttribute($attr));
                }
            }
            if ($attrs !== []) {
                $elements[] = ['tag' => $tag, 'attrs' => $attrs];
            }
        }

        return $elements === [] ? null : ['type' => 'svg', 'viewBox' => $viewBox, 'elements' => $elements];
    }
}
