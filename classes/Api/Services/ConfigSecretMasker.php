<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Data\Blueprint;

/**
 * Masks secret values (passwords, API keys, tokens) in config payloads before
 * they leave the API, and restores them again on save so a round-tripped mask
 * never overwrites the real secret.
 *
 * This is NOT demo-mode-specific: GET /config/{scope} previously returned SMTP
 * passwords, plugin licence keys and third-party API keys in plaintext to any
 * caller holding api.config.read. Masking runs for every user; demo mode just
 * made the pre-existing leak urgent to close.
 *
 * A field is treated as secret when EITHER signal fires:
 *   1. Its blueprint field type is `password`.
 *   2. Its leaf key matches a name heuristic (password/secret/api_key/token/…).
 *
 * The heuristic is a deliberate second net: core's own system.yaml blueprint
 * types `cache.redis.password` as plain `text`, so type-only detection would
 * still leak it.
 */
class ConfigSecretMasker
{
    /** Placeholder returned in place of a real secret value. */
    public const SENTINEL = '********';

    /**
     * Leaf-key name heuristic. Matches the final dotted segment, so
     * `mailer.smtp.password` and `cache.redis.password` both trip it even when
     * the blueprint types them as plain text.
     */
    private const SECRET_NAME_PATTERN = '/(password|passwd|passphrase|secret|api[_-]?key|apikey|private[_-]?key|licen[sc]e[_-]?key|access[_-]?token|client[_-]?secret|token)$/i';

    /**
     * Return a copy of $data with every secret scalar replaced by the sentinel.
     *
     * Only non-empty string secrets are masked — an unset/empty secret is left
     * empty, which also correctly signals "not configured" to the UI.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function mask(array $data, ?Blueprint $blueprint = null): array
    {
        foreach (self::secretLeafPaths($data, $blueprint) as $path) {
            $value = ConfigDiffer::valueAtPath($data, $path);
            if (is_string($value) && $value !== '') {
                $data = ConfigDiffer::setDotPath($data, $path, self::SENTINEL);
            }
        }
        return $data;
    }

    /**
     * Restore any masked secret that round-tripped back unchanged.
     *
     * The admin-next config form posts the WHOLE scope on save, so a secret the
     * user never touched returns as the literal sentinel. Without this, the next
     * unrelated save of that scope would persist `********` as the secret,
     * destroying it. For each secret path whose submitted value is exactly the
     * sentinel, restore the real value from $existing (read from disk, never
     * masked). A genuine change — an empty string, or any value that isn't the
     * sentinel — passes through untouched, so clearing or changing a secret
     * still works.
     *
     * @param array<mixed> $merged   Config about to be persisted.
     * @param array<mixed> $existing Current on-disk (unmasked) config.
     * @return array<mixed>
     */
    public static function restoreSentinels(array $merged, array $existing, ?Blueprint $blueprint = null): array
    {
        foreach (self::secretLeafPaths($merged, $blueprint) as $path) {
            if (ConfigDiffer::valueAtPath($merged, $path) === self::SENTINEL) {
                $merged = ConfigDiffer::setDotPath($merged, $path, ConfigDiffer::valueAtPath($existing, $path));
            }
        }
        return $merged;
    }

    /**
     * Dotted paths of every scalar leaf in $data that looks like a secret.
     *
     * We walk the actual data structure (not the blueprint) so masking still
     * works when no blueprint is available — the name heuristic alone catches
     * secrets in blueprint-less plugin config. When a blueprint IS present, the
     * `type: password` signal is consulted per field as well.
     *
     * @param array<mixed> $data
     * @return list<string>
     */
    private static function secretLeafPaths(array $data, ?Blueprint $blueprint): array
    {
        $schema = $blueprint?->schema();
        $out = [];
        foreach (self::scalarLeafPaths($data) as $path) {
            $lastSegment = strrchr($path, '.');
            $lastSegment = $lastSegment === false ? $path : substr($lastSegment, 1);

            $isSecret = preg_match(self::SECRET_NAME_PATTERN, $lastSegment) === 1;

            if (!$isSecret && $schema !== null) {
                $field = $schema->getProperty($path);
                $isSecret = is_array($field) && ($field['type'] ?? null) === 'password';
            }

            if ($isSecret) {
                $out[] = $path;
            }
        }
        return $out;
    }

    /**
     * Flatten $data to the dotted paths of its scalar leaves. Sequential (list)
     * arrays are treated atomically and skipped — a secret is always a scalar,
     * never a list — and only associative maps recurse, matching how ConfigDiffer
     * treats config structure elsewhere.
     *
     * @param array<mixed> $data
     * @return list<string>
     */
    private static function scalarLeafPaths(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                if (ConfigDiffer::isAssoc($value)) {
                    $out = array_merge($out, self::scalarLeafPaths($value, $path));
                }
                continue;
            }
            $out[] = $path;
        }
        return $out;
    }
}
