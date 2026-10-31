<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Security;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Psr\Http\Message\UploadedFileInterface;

trait StoresMediaUploads
{
    /** Validate SVG in private temporary storage before publishing any bytes. */
    protected function storeUploadedFile(UploadedFileInterface $file, string $targetPath, string $filename): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'grav-api-upload-');
        if ($temporary === false) {
            throw new ValidationException('Unable to prepare the uploaded file.');
        }

        try {
            $file->moveTo($temporary);
            if ($this->isSvgUpload($temporary, $filename)) {
                try {
                    Security::sanitizeSVG($temporary);
                } catch (\Throwable $e) {
                    throw new ValidationException("'{$filename}' could not be sanitized as SVG, so it was not saved.", [], $e);
                }
                clearstatcache(true, $temporary);
                if (!is_file($temporary) || filesize($temporary) === 0) {
                    throw new ValidationException("'{$filename}' could not be read as SVG markup, so it was not saved.");
                }
            }

            if (!rename($temporary, $targetPath)) {
                throw new ValidationException('Unable to store the uploaded file.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** Detect SVG by filename and markup, including prefixed element names. */
    protected function isSvgUpload(string $path, string $filename): bool
    {
        if (in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['svg', 'svgz'], true)) {
            return true;
        }

        $head = @file_get_contents($path, false, null, 0, 1024);
        if ($head === false || $head === '' || $this->isRasterImage($head)) {
            return false;
        }

        if (method_exists(Security::class, 'isSvgFile') && Security::isSvgFile($filename, $path)) {
            return true;
        }

        // Inspect the decompressed header for compressed markup as well.
        if (str_starts_with($head, "\x1f\x8b")) {
            $inflated = @gzdecode((string)@file_get_contents($path));
            $head = $inflated === false ? $head : substr($inflated, 0, 1024);
        }

        return preg_match('/<(?:[A-Za-z_][\w.-]*:)?svg(?=[\s\/>])/i', $head) === 1;
    }

    /**
     * Whether the bytes start with the signature of a PNG, JPEG, GIF, WebP,
     * TIFF or ISO media (AVIF/HEIC) file.
     */
    private function isRasterImage(string $head): bool
    {
        return str_starts_with($head, "\x89PNG\r\n\x1a\n")
            || str_starts_with($head, "\xFF\xD8\xFF")
            || str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')
            || (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP')
            || str_starts_with($head, "II*\x00") || str_starts_with($head, "MM\x00*")
            || substr($head, 4, 4) === 'ftyp';
    }
}
