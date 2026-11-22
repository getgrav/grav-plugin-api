<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Plugin\Api\Services\UnsubscribedHandlers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RocketTheme\Toolbox\Event\Event;
use RuntimeException;

/**
 * Plugins written for classic admin subscribe to admin-only events behind an
 * isAdmin() check, which is false while plugins initialise on an API request.
 * The bridge calls those handlers directly, once, and only for enabled plugins.
 */
#[CoversClass(UnsubscribedHandlers::class)]
class UnsubscribedHandlersTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    /** @var list<string> */
    private array $warnings = [];

    private function plugin(string $name, bool $throws = false): object
    {
        $calls = &$this->calls;

        return new class ($name, $throws, $calls) {
            /** @param list<string> $calls */
            public function __construct(public string $name, private bool $throws, private array &$calls)
            {
            }

            public function onGetPageBlueprints(Event $event): void
            {
                if ($this->throws) {
                    throw new RuntimeException('boom');
                }
                $this->calls[] = $this->name;
                $event['seen'] = array_merge($event['seen'] ?? [], [$this->name]);
            }
        };
    }

    /**
     * @param list<object> $plugins
     * @param list<mixed>  $listeners
     * @param list<string> $enabled
     */
    private function bridge(array $plugins, array $listeners = [], array $enabled = ['classic', 'subscribed', 'broken']): Event
    {
        $event = new Event();
        UnsubscribedHandlers::call(
            'onGetPageBlueprints',
            $event,
            $plugins,
            $listeners,
            fn (string $slug): bool => in_array($slug, $enabled, true),
            function (string $message): void {
                $this->warnings[] = $message;
            },
        );

        return $event;
    }

    #[Test]
    public function an_unsubscribed_handler_is_called_with_the_event(): void
    {
        $event = $this->bridge([$this->plugin('classic')]);

        $this->assertSame(['classic'], $this->calls);
        $this->assertSame(['classic'], $event['seen']);
    }

    #[Test]
    public function a_subscribed_handler_is_not_called_a_second_time(): void
    {
        $subscribed = $this->plugin('subscribed');

        $this->bridge([$subscribed, $this->plugin('classic')], [[$subscribed, 'onGetPageBlueprints']]);

        $this->assertSame(['classic'], $this->calls);
    }

    #[Test]
    public function a_disabled_plugin_is_skipped(): void
    {
        $this->bridge([$this->plugin('classic'), $this->plugin('off')]);

        $this->assertSame(['classic'], $this->calls);
    }

    #[Test]
    public function a_plugin_without_the_handler_is_skipped(): void
    {
        $plain = new class {
            public string $name = 'classic';
        };

        $this->bridge([$plain]);

        $this->assertSame([], $this->calls);
    }

    #[Test]
    public function a_failing_handler_is_logged_and_the_rest_still_run(): void
    {
        $this->bridge([$this->plugin('broken', true), $this->plugin('classic')]);

        $this->assertSame(['classic'], $this->calls);
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('onGetPageBlueprints handler', $this->warnings[0]);
        $this->assertStringContainsString('boom', $this->warnings[0]);
    }
}
