<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;
use RocketTheme\Toolbox\Event\Event;
use Throwable;

/**
 * Calls event handlers belonging to plugins that never got the chance to
 * subscribe during an API request.
 *
 * Plugins written for classic admin commonly register admin-only handlers
 * inside an `isAdmin()` check in onPluginsInitialized. `isAdmin()` is simply
 * `isset($grav['admin'])`, and during an API request the admin proxy is not
 * registered until routing, which happens after every plugin has already
 * initialised. Those plugins therefore never subscribed, and firing the event
 * alone would reach nobody: the customisation silently stops working the
 * moment a site moves to the new admin.
 *
 * Rather than register the admin proxy earlier, which would put every API
 * request into admin scope before it has been authenticated, the API bridges
 * a short list of events: any enabled plugin exposing a method named after
 * the event that is not already listening gets called directly. Plugins that
 * did subscribe are skipped, so nothing runs twice.
 *
 * Bridged events: onAdminPageTypes, onAdminModularPageTypes (the Add Page and
 * Add Module lists), onGetPageBlueprints and onGetPageTemplates (page types a
 * plugin contributes, with their blueprints and templates).
 */
final class UnsubscribedHandlers
{
    public static function notify(Grav $grav, string $name, Event $event): void
    {
        $plugins = $grav['plugins'] ?? null;
        if (!$plugins) {
            return;
        }

        self::call(
            $name,
            $event,
            $plugins,
            $grav['events']->getListeners($name),
            static fn (string $slug): bool => (bool) $grav['config']->get("plugins.{$slug}.enabled", false),
            static fn (string $message) => $grav['log']->warning($message),
        );
    }

    /**
     * @param iterable<mixed> $plugins   The loaded plugin instances.
     * @param array<mixed>    $listeners The listeners already registered for $name.
     * @param callable(string): bool $isEnabled
     * @param callable(string): mixed $warn
     */
    public static function call(
        string $name,
        Event $event,
        iterable $plugins,
        array $listeners,
        callable $isEnabled,
        callable $warn,
    ): void {
        $subscribed = [];
        foreach ($listeners as $listener) {
            if (is_array($listener) && isset($listener[0]) && is_object($listener[0])) {
                $subscribed[spl_object_id($listener[0])] = true;
            }
        }

        foreach ($plugins as $plugin) {
            if (!is_object($plugin)
                || isset($subscribed[spl_object_id($plugin)])
                || !method_exists($plugin, $name)) {
                continue;
            }

            // Disabled plugins are still instantiated in the collection, and
            // Plugins::init() skips them when subscribing. Do the same here.
            $slug = $plugin->name ?? null;
            if (!is_string($slug) || $slug === '' || !$isEnabled($slug)) {
                continue;
            }

            try {
                $plugin->{$name}($event);
            } catch (Throwable $e) {
                // One misbehaving plugin must not take the rest down with it.
                $warn(sprintf('[api] %s handler in %s failed: %s', $name, get_class($plugin), $e->getMessage()));
            }
        }
    }
}
