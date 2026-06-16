<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Utils;
use Grav\Plugin\Api\Exceptions\ValidationException;

use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;

/**
 * Per-field upload settings for blueprint `type: file` fields.
 *
 * Carries the subset of Grav's core upload settings (MediaUploadTrait's
 * `$_upload_defaults` and the form plugin's per-field schema) that the API
 * honors, so admin-next file fields behave like admin-classic ones:
 *
 *   - random_name        randomize the stored filename
 *   - avoid_overwriting  datetime-prefix on a name conflict instead of clobbering
 *   - accept             mime / extension allowlist
 *   - filesize           per-field maximum size in MB
 *
 * TRUST MODEL: these values arrive from the client (the blueprint the SPA
 * renders), exactly as `destination`/`scope` already do on the blueprint-upload
 * endpoint. They can only *further restrict* an upload (`accept`, `filesize`)
 * or change the *output filename* (`random_name`, `avoid_overwriting`) — never
 * relax the immovable server-side security floor (dangerous/forbidden
 * extensions, accounts image-only, the hard size cap, traversal guards), which
 * each controller enforces separately and never delegates to the client.
 */
final class UploadFieldSettings
{
    /**
     * @param string[] $accept
     */
    private function __construct(
        public readonly bool $randomName = false,
        public readonly bool $avoidOverwriting = false,
        public readonly array $accept = [],
        public readonly ?float $filesizeMb = null,
    ) {
    }

    /**
     * A settings object with nothing active — every upload behaves as before.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Build from an associative array of request parameters (parsed body /
     * uploaded-file metadata). Missing or unrecognized keys fall back to the
     * inert default, so a request that carries no field settings is a no-op.
     *
     * @param array<string, mixed> $params
     */
    public static function fromParams(array $params): self
    {
        return new self(
            randomName: self::toBool($params['random_name'] ?? false),
            avoidOverwriting: self::toBool($params['avoid_overwriting'] ?? false),
            accept: self::toAcceptList($params['accept'] ?? null),
            filesizeMb: self::toFilesize($params['filesize'] ?? null),
        );
    }

    /**
     * Whether any field-level setting is active.
     */
    public function isEmpty(): bool
    {
        return !$this->randomName
            && !$this->avoidOverwriting
            && $this->accept === []
            && $this->filesizeMb === null;
    }

    /**
     * Enforce the per-field maximum filesize (MB). The endpoint's own hard cap
     * is applied separately and always wins; this only tightens it.
     */
    public function assertFilesize(?int $size): void
    {
        if ($this->filesizeMb === null || $this->filesizeMb <= 0 || $size === null) {
            return;
        }

        $max = (int) round($this->filesizeMb * 1_048_576);
        if ($size > $max) {
            $label = rtrim(rtrim(number_format($this->filesizeMb, 2), '0'), '.');
            throw new ValidationException(
                sprintf('File exceeds the maximum allowed size of %s MB for this field.', $label)
            );
        }
    }

    /**
     * Enforce the field's `accept` allowlist (mime types such as `image/*`, or
     * extensions such as `.pdf`). Mirrors the form plugin's matching, including
     * deriving the mime from the filename rather than trusting the browser.
     */
    public function assertAccepted(string $filename): void
    {
        if ($this->accept === []) {
            return;
        }

        $mime = Utils::getMimeByFilename($filename);

        foreach ($this->accept as $type) {
            if ($type === '') {
                continue;
            }
            if ($type === '*') {
                return;
            }

            $isMime = str_contains($type, '/');
            $pattern = '#' . str_replace(['.', '*', '+'], ['\.', '.*', '\+'], $type) . '$#';
            $subject = $isMime ? $mime : $filename;

            if (preg_match($pattern, $subject)) {
                return;
            }
        }

        throw new ValidationException(
            sprintf("File '%s' does not match the accepted types for this field.", $filename)
        );
    }

    /**
     * Decide the final stored filename, applying `random_name` then
     * `avoid_overwriting` against the resolved target directory.
     *
     * Both transforms preserve the file extension (random names re-append it;
     * the conflict guard only prepends a datetime), so a caller that already
     * validated the extension on the incoming name does not need to re-check.
     */
    public function resolveFilename(string $filename, string $targetDir): string
    {
        if ($this->randomName) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $random = Utils::generateRandomString(15);
            $filename = strtolower($extension !== '' ? "{$random}.{$extension}" : $random);
        }

        if ($this->avoidOverwriting && is_file($targetDir . '/' . $filename)) {
            $filename = date('YmdHis') . '-' . $filename;
        }

        return $filename;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool) $value;
    }

    /**
     * Accept may arrive as an array (JSON body) or a comma-separated string
     * (multipart meta). Normalize to a trimmed list of non-empty entries.
     *
     * @return string[]
     */
    private static function toAcceptList(mixed $value): array
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    private static function toFilesize(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $filesize = (float) $value;
        return $filesize > 0 ? $filesize : null;
    }
}
