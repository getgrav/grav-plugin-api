<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Yaml;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Thin CRUD controller for the admin-relevant subset of the login plugin's
 * configuration surfaced in admin-next's "Login & Security" settings page.
 *
 * The page's blueprint (plugins/api/admin/blueprints/login-settings.yaml)
 * only includes admin-relevant fields — frontend-only settings (routes,
 * redirects, magic_link, user_registration, rememberme, etc.) are not
 * exposed here and must be edited via login.yaml directly or admin-classic.
 *
 * Save is a MERGE, not an overwrite: the existing login.yaml is loaded,
 * the submitted fields are recursively merged in, and the result is saved.
 * This preserves every key admin-next doesn't expose.
 */
class LoginSettingsController extends AbstractApiController
{
    /** Fields this page owns. Anything outside this list is preserved untouched. */
    private const ALLOWED_FIELDS = [
        'twofa_enabled',
        'max_login_count',
        'max_login_interval',
        'ipv6_subnet_size',
        'max_pw_resets_count',
        'max_pw_resets_interval',
    ];

    public function data(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.login-settings.read');

        $cfg = $this->grav['config'];

        return ApiResponse::create([
            'twofa_enabled'          => (bool) $cfg->get('plugins.login.twofa_enabled', false),
            'max_login_count'        => (int) $cfg->get('plugins.login.max_login_count', 5),
            'max_login_interval'     => (int) $cfg->get('plugins.login.max_login_interval', 10),
            'ipv6_subnet_size'       => (int) $cfg->get('plugins.login.ipv6_subnet_size', 64),
            'max_pw_resets_count'    => (int) $cfg->get('plugins.login.max_pw_resets_count', 2),
            'max_pw_resets_interval' => (int) $cfg->get('plugins.login.max_pw_resets_interval', 60),
        ]);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.login-settings.write');

        $body = $this->getRequestBody($request);

        // Filter down to allowed keys and coerce types — never trust client.
        $changes = [];
        foreach (self::ALLOWED_FIELDS as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $value = $body[$key];
            if ($key === 'twofa_enabled') {
                $changes[$key] = (bool) $value;
            } else {
                $changes[$key] = max(0, (int) $value);
            }
        }

        if ($changes === []) {
            return ApiResponse::create([
                'message' => 'No recognized fields to update.',
            ]);
        }

        $locator = $this->grav['locator'];
        $file = $locator->findResource('user://config/plugins/login.yaml', true, true);
        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ApiException(500, 'Save Failed', 'Unable to create config directory.');
        }

        $existing = [];
        if (file_exists($file)) {
            $parsed = Yaml::parse(file_get_contents($file) ?: '');
            if (is_array($parsed)) {
                $existing = $parsed;
            }
        }

        // Shallow merge at the top level is correct here — all our keys are
        // scalar top-level values in login.yaml, none are nested maps.
        $merged = array_replace($existing, $changes);

        if (file_put_contents($file, Yaml::dump($merged, 4, 2)) === false) {
            throw new ApiException(500, 'Save Failed', 'Unable to write login.yaml.');
        }

        // Reload config in this request so immediate reads reflect the change.
        $cfg = $this->grav['config'];
        foreach ($changes as $k => $v) {
            $cfg->set("plugins.login.{$k}", $v);
        }

        $this->fireEvent('onApiLoginSettingsSaved', [
            'changes' => $changes,
            'user' => $this->getUser($request),
        ]);

        return ApiResponse::create([
            'message' => 'Login settings saved.',
            'data' => $merged,
        ]);
    }
}
