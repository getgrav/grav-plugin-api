<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit;

use Grav\Plugin\Api\Services\ThumbnailService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Directories the plugin creates must stay group-writable (getgrav/grav#4295).
 *
 * The documented shared-host setup runs the web server and the CLI as
 * different users in one group. A directory the web user creates as 0755 locks
 * the CLI user out of it (`bin/grav clearcache` fails with Permission denied,
 * and it can't even chmod what it doesn't own), so every mkdir() asks for 0775
 * and leaves the rest to the umask. Single-user installs are unaffected.
 */
class DirectoryModeTest extends TestCase
{
    #[Test]
    public function no_mkdir_in_the_plugin_drops_group_write(): void
    {
        $root = dirname(__DIR__, 2) . '/classes';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $checked = 0;
        $offenders = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/\bmkdir\([^;]*?,\s*(0o?[0-7]{3})\b/', $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$mode, $offset]) {
                $checked++;
                if ((octdec(str_replace('o', '', $mode)) & 0020) === 0) {
                    $line = substr_count($source, "\n", 0, $offset) + 1;
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1) . ":{$line} ({$mode})";
                }
            }
        }

        // A scan that finds nothing proves nothing; the plugin has ~20 of these.
        self::assertGreaterThan(10, $checked, 'The mkdir() scan matched suspiciously few calls.');
        self::assertSame([], $offenders, 'mkdir() without group write: ' . implode(', ', $offenders));
    }

    #[Test]
    public function thumbnail_cache_directory_is_created_group_writable(): void
    {
        $base = sys_get_temp_dir() . '/api-dirmode-' . bin2hex(random_bytes(4));
        $dir = $base . '/cache/api/thumbnails';
        $umask = umask(0002);

        try {
            new ThumbnailService($dir);

            self::assertSame(0775, fileperms($dir) & 0777);
            self::assertSame(0775, fileperms($base . '/cache') & 0777);
        } finally {
            umask($umask);
            foreach ([$dir, $base . '/cache/api', $base . '/cache', $base] as $path) {
                @rmdir($path);
            }
        }
    }
}
