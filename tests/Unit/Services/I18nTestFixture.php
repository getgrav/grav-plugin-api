<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

/**
 * Shared helpers for the translation-editor service tests.
 *
 * These services only ever touch a handful of container entries — `locator`,
 * `config`, `cache`, `setup`, `language` and `languages` — so the companion
 * `I18nFake*` doubles are hand-rolled rather than mocked, keeping each test
 * reflecting exactly what the service walks. Same approach as
 * {@see EnvironmentServiceTest}.
 */
final class I18nTestFixture
{
    public static function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
