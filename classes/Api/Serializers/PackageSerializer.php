<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Serializers;

use Closure;
use Grav\Common\GPM\Licenses;
use Grav\Plugin\Api\Services\ConfigShadows;
use Parsedown;

class PackageSerializer implements SerializerInterface
{
    private static ?Parsedown $parsedown = null;

    /** Shared across a listing so the active env and server keys resolve once. */
    private ?ConfigShadows $shadows = null;

    /**
     * @param Closure(string):?string|null $translator Resolves a package-level
     *        string that is a translation key to its translation, returning
     *        null for anything it can't positively translate — literal prose
     *        included. Supplied by GpmController from TranslatesAdminLabels;
     *        absent everywhere else, which leaves values untouched.
     */
    public function __construct(private readonly ?Closure $translator = null)
    {
    }

    public function serialize(object $resource, array $options = []): array
    {
        // Translate before rendering: the markdown in a description belongs to
        // the translated text, not to the key (#39).
        $description = self::text($this->translateValue($resource->description ?? null));

        // Cast: YAML types bare scalars, so a blueprint carrying `version: 1.0`
        // hands us the float 1, not the string "1.0". Emitting that as a JSON
        // number breaks every client that treats a version as text — one such
        // package installed was enough to blank the admin's Plugins and Info
        // pages for the whole site.
        $data = [
            'slug' => $resource->slug ?? null,
            'name' => self::text($this->translateValue($resource->name ?? null)),
            'version' => self::text($resource->version ?? null),
            'type' => $options['type'] ?? null,
            'description' => $description,
            'description_html' => $this->renderMarkdown($description),
            'author' => $this->serializeAuthor($resource),
            'homepage' => $resource->homepage ?? $resource->url ?? null,
        ];

        // Blueprint links the admin surfaces as "Documentation" / "Report an
        // Issue". Only emit when the blueprint actually sets them so clients can
        // hide the link rather than render a dead one.
        if (!empty($resource->docs)) {
            $data['docs'] = $resource->docs;
        }
        if (!empty($resource->bugs)) {
            $data['bugs'] = $resource->bugs;
        }

        // Include enabled status + symlink detection for installed packages
        if ($options['installed'] ?? false) {
            $data['enabled'] = $this->isEnabled($resource, $options);
            $data['is_symlink'] = $this->isSymlinked($resource, $options);
            if (($options['type'] ?? 'plugin') === 'plugin') {
                $data['enabled_source'] = $this->enabledSource($resource, $options);
            }
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
            $premiumValue = static function (string $key) use ($premium) {
                $value = is_object($premium) ? ($premium->{$key} ?? null) : ($premium[$key] ?? null);

                return is_string($value) && $value !== '' ? $value : null;
            };

            $data['premium'] = true;
            // Not against the slug alone: a package sold inside a wider licence
            // names that licence's product in `premium.license_product`, and the
            // key filed under it covers this one. Checking only the slug reads a
            // licence the customer holds as one they do not, and the admin
            // offers them a Buy button for something they have already bought.
            $data['licensed'] = Licenses::resolve($slug, $premium) !== '';

            // Not every premium package is sold from the Grav Premium store.
            // A package may name its own storefront, in which case the client
            // sends the buyer there instead of to licensing.getgrav.org/buy.
            // `vendor` is purely cosmetic — it lets the admin say who sells
            // this, rather than implying everything premium is Grav Premium.
            $vendor = $premiumValue('vendor');
            if ($vendor) {
                $data['vendor'] = $vendor;
            }

            $checkoutUrl = $premiumValue('checkout_url');
            $permalink = $premiumValue('permalink');
            if ($checkoutUrl) {
                $data['purchase_url'] = $checkoutUrl;
            } elseif ($permalink) {
                $data['purchase_url'] = 'https://licensing.getgrav.org/buy/' . $permalink;
            }
        }

        // Include dependencies
        if (!empty($resource->dependencies)) {
            $data['dependencies'] = $resource->dependencies;
        }

        // Include compatibility metadata. Grav core resolves
        // `compatibility.grav` / `compatibility.api` (and infers grav from the
        // dependencies array as a fallback). Any keys core doesn't currently
        // resolve (e.g. a future `compatibility.php`) come straight from the
        // blueprint via the `compatibility_raw` fallback below.
        $compatibility = $this->normalizeCompatibility($resource->compatibility ?? null);
        $rawCompat = is_object($resource) && method_exists($resource, 'toArray')
            ? ($resource->toArray()['compatibility'] ?? null)
            : null;
        if (is_array($rawCompat)) {
            foreach ($rawCompat as $key => $value) {
                if (!isset($compatibility[$key])) {
                    $compatibility[$key] = is_array($value) ? array_map('strval', $value) : (string) $value;
                }
            }
        }
        if (!empty($compatibility)) {
            $data['compatibility'] = $compatibility;
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

    /**
     * Where a plugin's live `enabled` comes from when it is not base config:
     * the booted environment's file or a GRAV_CONFIG__* server variable. Null
     * otherwise. Without this the admin shows a toggle that saves to base and
     * changes nothing (getgrav/grav-plugin-sync-mercure#1).
     *
     * @return array{source: string, environment: ?string, file: ?string}|null
     */
    private function enabledSource(object $resource, array $options): ?array
    {
        $slug = (string) ($resource->slug ?? $options['slug_key'] ?? '');
        try {
            $this->shadows ??= new ConfigShadows(\Grav\Common\Grav::instance());
            return $this->shadows->pluginEnabledSource($slug);
        } catch (\Throwable) {
            return null;
        }
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

    /**
     * Resolve a package-level string that may be a translation key.
     *
     * A package's `name` and `description` come from its own blueprints.yaml,
     * where an author may write literal prose or — following the same
     * convention every other label in that file follows — a translation key.
     * Both have to work: the value is handed to the translator, and anything it
     * can't positively translate comes back exactly as authored (#39).
     *
     * `author.name` and `keywords` are left out on purpose. They are proper
     * nouns and tag words, never keyed, and running a person's name through a
     * translation lookup is not something anyone asked for.
     */
    /**
     * Normalize a blueprint scalar to a string, preserving null.
     *
     * Only strings are left alone; ints, floats and bools become their text
     * form. Anything non-scalar (a stray array in the blueprint) is dropped to
     * null rather than coerced into "Array".
     */
    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function translateValue(mixed $value): mixed
    {
        if (!is_string($value) || $value === '' || $this->translator === null) {
            return $value;
        }

        return ($this->translator)($value) ?? $value;
    }

    /**
     * Render a plugin/theme description as safe HTML. Descriptions are
     * YAML-authored and routinely contain inline markdown (links, bold,
     * emphasis) that renders as literal syntax in UIs without processing.
     * Returns null for empty input so clients can trivially fall back.
     */
    private function renderMarkdown(?string $markdown): ?string
    {
        if ($markdown === null || $markdown === '') {
            return null;
        }
        if (self::$parsedown === null) {
            self::$parsedown = new Parsedown();
            // Untrusted YAML input — sanitize any inline HTML and disable unsafe protocols.
            self::$parsedown->setSafeMode(true);
            self::$parsedown->setBreaksEnabled(false);
        }
        return self::$parsedown->text($markdown);
    }

    /**
     * Normalize Grav's resolved compatibility array into a stable client shape.
     * Strips empty keys so consumers don't render `Grav: ` with nothing after.
     *
     * @param mixed $compatibility
     * @return array<string, mixed>
     */
    private function normalizeCompatibility($compatibility): array
    {
        if (!is_array($compatibility)) {
            return [];
        }
        $out = [];
        foreach ($compatibility as $key => $value) {
            if (is_array($value)) {
                $value = array_values(array_filter(array_map('strval', $value), 'strlen'));
                if (!empty($value)) {
                    $out[(string) $key] = $value;
                }
            } elseif ($value !== null && $value !== '') {
                $out[(string) $key] = (string) $value;
            }
        }
        return $out;
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
