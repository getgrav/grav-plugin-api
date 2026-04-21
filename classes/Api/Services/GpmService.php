<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Cache;
use Grav\Common\Filesystem\Folder;
use Grav\Common\GPM\Common\Package;
use Grav\Common\GPM\GPM as GravGPM;
use Grav\Common\GPM\Installer;
use Grav\Common\GPM\Licenses;
use Grav\Common\GPM\Upgrader;
use Grav\Common\Grav;
use Grav\Common\HTTP\Response;
use Grav\Common\Utils;

/**
 * GpmService — GPM write operations (install / update / remove / direct-install / self-upgrade).
 *
 * This is a port of Grav\Plugin\Admin\Gpm that removes the dependency on the
 * classic admin plugin so admin-next / admin2 users can manage packages
 * without needing the old admin plugin installed.
 *
 * Admin-specific callsites (Admin::translate, Admin::getTempDir) have been
 * replaced with inlined English strings and a local temp-dir resolver.
 */
class GpmService
{
    /** @var GravGPM|null */
    protected static ?GravGPM $GPM = null;

    /**
     * Default options for install operations.
     *
     * @var array<string, mixed>
     */
    protected static array $options = [
        'destination'     => GRAV_ROOT,
        'overwrite'       => true,
        'ignore_symlinks' => true,
        'skip_invalid'    => true,
        'install_deps'    => false,
        'theme'           => false,
    ];

    public static function GPM(): GravGPM
    {
        if (self::$GPM === null) {
            self::$GPM = new GravGPM();
        }

        return self::$GPM;
    }

    /**
     * Install one or more packages.
     *
     * @param Package[]|string[]|string $packages
     * @param array<string, mixed> $options
     * @return string|bool
     */
    public static function install($packages, array $options)
    {
        $options = array_merge(self::$options, $options);

        if (!Installer::isGravInstance($options['destination']) || !Installer::isValidDestination($options['destination'],
                [Installer::EXISTS, Installer::IS_LINK])
        ) {
            return false;
        }

        $packages = is_array($packages) ? $packages : [$packages];
        $count = count($packages);

        $packages = array_filter(array_map(static function ($p) {
            return !is_string($p) ? ($p instanceof Package ? $p : false) : self::GPM()->findPackage($p);
        }, $packages));

        if (!$options['skip_invalid'] && $count !== count($packages)) {
            return false;
        }

        $messages = '';

        foreach ($packages as $package) {
            // Dependency resolution is the caller's responsibility (see
            // GpmController::install / ::update which use GPM::getDependencies()).
            // The blueprint `dependencies` structure is a list of
            // ['name' => slug, 'version' => constraint] entries, not slugs or
            // Package objects, so it can't be passed back into install().

            Installer::isValidDestination($options['destination'] . DS . $package->install_path);

            if (!$options['overwrite'] && Installer::lastErrorCode() === Installer::EXISTS) {
                return false;
            }

            if (!$options['ignore_symlinks'] && Installer::lastErrorCode() === Installer::IS_LINK) {
                return false;
            }

            $license = Licenses::get($package->slug);
            $local = static::download($package, $license);

            Installer::install(
                $local,
                $options['destination'],
                ['install_path' => $package->install_path, 'theme' => $options['theme']]
            );
            Folder::delete(dirname($local));

            $errorCode = Installer::lastErrorCode();
            if ($errorCode) {
                throw new \RuntimeException(Installer::lastErrorMsg());
            }

            if (count($packages) === 1) {
                $message = Installer::getMessage();
                if ($message) {
                    return $message;
                }

                $messages .= $message;
            }
        }

        Cache::clearCache();

        return $messages !== '' ? $messages : true;
    }

    /**
     * Update one or more packages.
     *
     * @param Package[]|string[]|string $packages
     * @param array<string, mixed> $options
     * @return string|bool
     */
    public static function update($packages, array $options)
    {
        $options['overwrite'] = true;

        return static::install($packages, $options);
    }

    /**
     * Uninstall one or more packages.
     *
     * @param Package[]|string[]|string $packages
     * @param array<string, mixed> $options
     * @return string|bool
     */
    public static function uninstall($packages, array $options)
    {
        $options = array_merge(self::$options, $options);

        $packages = (array) $packages;
        $count = count($packages);

        $packages = array_filter(array_map(static function ($p) {
            if (is_string($p)) {
                $p = strtolower($p);
                $plugin = self::GPM()->getInstalledPlugin($p);
                $p = $plugin ?: self::GPM()->getInstalledTheme($p);
            }

            return $p instanceof Package ? $p : false;
        }, $packages));

        if (!$options['skip_invalid'] && $count !== count($packages)) {
            return false;
        }

        foreach ($packages as $package) {
            $location = Grav::instance()['locator']->findResource($package->package_type . '://' . $package->slug);

            Installer::isValidDestination($location);

            if (!$options['ignore_symlinks'] && Installer::lastErrorCode() === Installer::IS_LINK) {
                return false;
            }

            Installer::uninstall($location);

            $errorCode = Installer::lastErrorCode();
            if ($errorCode && $errorCode !== Installer::IS_LINK && $errorCode !== Installer::EXISTS) {
                throw new \RuntimeException(Installer::lastErrorMsg());
            }

            if (count($packages) === 1) {
                $message = Installer::getMessage();
                if ($message) {
                    return $message;
                }
            }
        }

        Cache::clearCache();

        return true;
    }

    /**
     * Install a package directly from a local zip or remote URL.
     *
     * @param string $packageFile
     * @return string|bool
     */
    public static function directInstall(string $packageFile)
    {
        if ($packageFile === '') {
            return 'No package file provided.';
        }

        $tmpDir = static::getTempDir();
        $tmpZip = $tmpDir . '/Grav-' . uniqid('', false);

        if (Response::isRemote($packageFile)) {
            $zip = GravGPM::downloadPackage($packageFile, $tmpZip);
        } else {
            $zip = GravGPM::copyPackage($packageFile, $tmpZip);
        }

        if (!file_exists($zip)) {
            return 'Zip package not found.';
        }

        $tmpSource = $tmpDir . '/Grav-' . uniqid('', false);
        $extracted = Installer::unZip($zip, $tmpSource);

        if (!$extracted) {
            Folder::delete($tmpSource);
            Folder::delete($tmpZip);
            return 'Package extraction failed.';
        }

        $type = GravGPM::getPackageType($extracted);

        if (!$type) {
            Folder::delete($tmpSource);
            Folder::delete($tmpZip);
            return 'Not a valid Grav package.';
        }

        if ($type === 'grav') {
            Installer::isValidDestination(GRAV_ROOT . '/system');
            if (Installer::IS_LINK === Installer::lastErrorCode()) {
                Folder::delete($tmpSource);
                Folder::delete($tmpZip);
                return 'Cannot overwrite symlinks.';
            }

            static::upgradeGrav($zip, $extracted);
        } else {
            $name = GravGPM::getPackageName($extracted);

            if (!$name) {
                Folder::delete($tmpSource);
                Folder::delete($tmpZip);
                return 'Package name could not be determined.';
            }

            $installPath = GravGPM::getInstallPath($type, $name);
            $isUpdate = file_exists($installPath);

            Installer::isValidDestination(GRAV_ROOT . DS . $installPath);
            if (Installer::lastErrorCode() === Installer::IS_LINK) {
                Folder::delete($tmpSource);
                Folder::delete($tmpZip);
                return 'Cannot overwrite symlinks.';
            }

            Installer::install(
                $zip,
                GRAV_ROOT,
                ['install_path' => $installPath, 'theme' => $type === 'theme', 'is_update' => $isUpdate],
                $extracted
            );
        }

        Folder::delete($tmpSource);

        if (Installer::lastErrorCode()) {
            return Installer::lastErrorMsg();
        }

        Folder::delete($tmpZip);
        Cache::clearCache();

        return true;
    }

    /**
     * Self-upgrade Grav core to the latest release.
     *
     * @return bool
     */
    public static function selfupgrade(): bool
    {
        $upgrader = new Upgrader();

        if (!Installer::isGravInstance(GRAV_ROOT)) {
            return false;
        }

        if (is_link(GRAV_ROOT . DS . 'index.php')) {
            Installer::setError(Installer::IS_LINK);
            return false;
        }

        if (method_exists($upgrader, 'meetsRequirements') &&
            method_exists($upgrader, 'minPHPVersion') &&
            !$upgrader->meetsRequirements()) {
            $error = [];
            $error[] = '<p>Grav has increased the minimum PHP requirement.<br />';
            $error[] = 'You are currently running PHP <strong>' . phpversion() . '</strong>';
            $error[] = ', but PHP <strong>' . $upgrader->minPHPVersion() . '</strong> is required.</p>';
            Installer::setError(implode("\n", $error));
            return false;
        }

        $update = $upgrader->getAssets()['grav-update'];
        $tmp = static::getTempDir() . '/Grav-' . uniqid('', false);

        $file = static::downloadSelfupgrade($update, $tmp);
        $folder = Installer::unZip($file, $tmp . '/zip');

        static::upgradeGrav($file, $folder, false);

        $errorCode = Installer::lastErrorCode();

        Folder::delete($tmp);

        return !(is_string($errorCode) || ($errorCode & (Installer::ZIP_OPEN_ERROR | Installer::ZIP_EXTRACT_ERROR)));
    }

    /**
     * Download a GPM package zip into a temp directory.
     */
    private static function download(Package $package, ?string $license = null): string
    {
        $query = '';

        if ($package->premium) {
            $query = \json_encode(array_merge($package->premium, [
                'slug' => $package->slug,
                'license_key' => $license,
                'sid' => md5(GRAV_ROOT),
            ]));

            $query = '?d=' . base64_encode($query);
        }

        try {
            $contents = Response::get($package->zipball_url . $query, []);
        } catch (\Exception $e) {
            throw new \RuntimeException($e->getMessage());
        }

        $tmpDir = static::getTempDir() . '/Grav-' . uniqid('', false);
        Folder::mkdir($tmpDir);

        $badChars = array_merge(array_map('chr', range(0, 31)), ['<', '>', ':', '"', '/', '\\', '|', '?', '*']);

        $filename = $package->slug . str_replace($badChars, '', Utils::basename($package->zipball_url));
        $filename = preg_replace('/[\\\\\/:"*?&<>|]+/m', '-', $filename);

        file_put_contents($tmpDir . DS . $filename . '.zip', $contents);

        return $tmpDir . DS . $filename . '.zip';
    }

    /**
     * Download the Grav self-upgrade zip.
     *
     * @param array<string, mixed> $package
     */
    private static function downloadSelfupgrade(array $package, string $tmp): string
    {
        $output = Response::get($package['download'], []);
        Folder::mkdir($tmp);
        file_put_contents($tmp . DS . $package['name'], $output);

        return $tmp . DS . $package['name'];
    }

    /**
     * Run the Grav core upgrade install script against an extracted zip.
     */
    private static function upgradeGrav(string $zip, string $folder, bool $keepFolder = false): void
    {
        static $ignores = [
            'backup',
            'cache',
            'images',
            'logs',
            'tmp',
            'user',
            '.htaccess',
            'robots.txt',
        ];

        if (!is_dir($folder)) {
            Installer::setError('Invalid source folder');
        }

        try {
            $script = $folder . '/system/install.php';
            if ((file_exists($script) && $install = include $script) && is_callable($install)) {
                $install($zip);
            } else {
                Installer::install(
                    $zip,
                    GRAV_ROOT,
                    ['sophisticated' => true, 'overwrite' => true, 'ignore_symlinks' => true, 'ignores' => $ignores],
                    $folder,
                    $keepFolder
                );

                Cache::clearCache();
            }
        } catch (\Exception $e) {
            Installer::setError($e->getMessage());
        }
    }

    /**
     * Resolve a writable temporary directory, falling back to cache/tmp if tmp://
     * isn't configured.
     */
    private static function getTempDir(): string
    {
        try {
            $tmpDir = Grav::instance()['locator']->findResource('tmp://', true, true);
        } catch (\Exception $e) {
            $tmpDir = Grav::instance()['locator']->findResource('cache://', true, true) . '/tmp';
        }

        return $tmpDir;
    }
}
