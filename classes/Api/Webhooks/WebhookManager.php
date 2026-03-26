<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Webhooks;

use Grav\Common\Grav;
use RocketTheme\Toolbox\File\YamlFile;

class WebhookManager
{
    private string $storagePath;
    private string $deliveryPath;
    private ?array $webhooksCache = null;

    public function __construct()
    {
        $grav = Grav::instance();
        $this->storagePath = $grav['locator']->findResource('user://data/api', true, true);
        $this->deliveryPath = $this->storagePath . '/webhook-deliveries';
    }

    /**
     * Get all webhooks.
     *
     * @return array
     */
    public function getAll(): array
    {
        return $this->load();
    }

    /**
     * Get a webhook by ID.
     */
    public function get(string $id): ?array
    {
        $webhooks = $this->load();
        foreach ($webhooks as $webhook) {
            if ($webhook['id'] === $id) {
                return $webhook;
            }
        }
        return null;
    }

    /**
     * Create a new webhook.
     */
    public function create(array $data): array
    {
        $webhook = [
            'id' => 'wh_' . bin2hex(random_bytes(12)),
            'url' => $data['url'],
            'secret' => 'whsec_' . bin2hex(random_bytes(24)),
            'events' => $data['events'] ?? ['*'],
            'enabled' => $data['enabled'] ?? true,
            'headers' => $data['headers'] ?? [],
            'created' => time(),
            'failure_count' => 0,
        ];

        $webhooks = $this->load();
        $webhooks[] = $webhook;
        $this->save($webhooks);

        return $webhook;
    }

    /**
     * Update a webhook.
     */
    public function update(string $id, array $data): ?array
    {
        $webhooks = $this->load();

        foreach ($webhooks as &$webhook) {
            if ($webhook['id'] === $id) {
                if (isset($data['url'])) {
                    $webhook['url'] = $data['url'];
                }
                if (isset($data['events'])) {
                    $webhook['events'] = $data['events'];
                }
                if (isset($data['enabled'])) {
                    $webhook['enabled'] = (bool) $data['enabled'];
                }
                if (isset($data['headers'])) {
                    $webhook['headers'] = $data['headers'];
                }

                $this->save($webhooks);
                return $webhook;
            }
        }

        return null;
    }

    /**
     * Delete a webhook.
     */
    public function delete(string $id): bool
    {
        $webhooks = $this->load();
        $filtered = array_values(array_filter($webhooks, fn($w) => $w['id'] !== $id));

        if (count($filtered) === count($webhooks)) {
            return false;
        }

        $this->save($filtered);

        // Clean up delivery logs
        $deliveryDir = $this->deliveryPath . '/' . $id;
        if (is_dir($deliveryDir)) {
            $files = glob($deliveryDir . '/*.yaml');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($deliveryDir);
        }

        return true;
    }

    /**
     * Record a delivery log entry.
     */
    public function recordDelivery(string $webhookId, array $delivery): void
    {
        $dir = $this->deliveryPath . '/' . $webhookId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . '/' . $delivery['id'] . '.yaml';
        $yamlFile = YamlFile::instance($file);
        $yamlFile->content($delivery);
        $yamlFile->save();

        // Keep only last 50 deliveries
        $this->pruneDeliveries($webhookId, 50);
    }

    /**
     * Get delivery history for a webhook.
     */
    public function getDeliveries(string $webhookId, int $limit = 20, int $offset = 0): array
    {
        $dir = $this->deliveryPath . '/' . $webhookId;
        if (!is_dir($dir)) {
            return ['deliveries' => [], 'total' => 0];
        }

        $files = glob($dir . '/*.yaml');
        // Sort by modification time descending
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

        $total = count($files);
        $slice = array_slice($files, $offset, $limit);

        $deliveries = [];
        foreach ($slice as $file) {
            $yamlFile = YamlFile::instance($file);
            $deliveries[] = $yamlFile->content();
        }

        return ['deliveries' => $deliveries, 'total' => $total];
    }

    /**
     * Get webhooks matching a specific event.
     */
    public function getForEvent(string $event): array
    {
        $webhooks = $this->load();
        return array_filter($webhooks, function ($webhook) use ($event) {
            if (!($webhook['enabled'] ?? true)) {
                return false;
            }
            $events = $webhook['events'] ?? ['*'];
            return in_array('*', $events, true) || in_array($event, $events, true);
        });
    }

    /**
     * Increment failure count and auto-disable if threshold reached.
     */
    public function recordFailure(string $id): void
    {
        $webhooks = $this->load();
        foreach ($webhooks as &$webhook) {
            if ($webhook['id'] === $id) {
                $webhook['failure_count'] = ($webhook['failure_count'] ?? 0) + 1;
                if ($webhook['failure_count'] >= 5) {
                    $webhook['enabled'] = false;
                    $webhook['disabled_reason'] = 'Auto-disabled after 5 consecutive failures';
                }
                break;
            }
        }
        $this->save($webhooks);
    }

    /**
     * Reset failure count on successful delivery.
     */
    public function resetFailureCount(string $id): void
    {
        $webhooks = $this->load();
        foreach ($webhooks as &$webhook) {
            if ($webhook['id'] === $id) {
                $webhook['failure_count'] = 0;
                unset($webhook['disabled_reason']);
                break;
            }
        }
        $this->save($webhooks);
    }

    private function load(): array
    {
        if ($this->webhooksCache !== null) {
            return $this->webhooksCache;
        }

        $file = YamlFile::instance($this->storagePath . '/webhooks.yaml');
        $content = $file->content();
        $this->webhooksCache = $content['webhooks'] ?? [];

        return $this->webhooksCache;
    }

    private function save(array $webhooks): void
    {
        $file = YamlFile::instance($this->storagePath . '/webhooks.yaml');
        $file->content(['webhooks' => array_values($webhooks)]);
        $file->save();
        $this->webhooksCache = array_values($webhooks);
    }

    private function pruneDeliveries(string $webhookId, int $keep): void
    {
        $dir = $this->deliveryPath . '/' . $webhookId;
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*.yaml');
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

        $toDelete = array_slice($files, $keep);
        foreach ($toDelete as $file) {
            @unlink($file);
        }
    }
}
