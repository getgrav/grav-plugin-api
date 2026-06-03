<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Data\Blueprints;
use Grav\Common\Data\Data;
use Grav\Common\Yaml;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Services\ConfigDiffer;
use Grav\Plugin\Api\Services\EnvironmentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ConfigController extends AbstractApiController
{
    /**
     * Tool-managed scopes that carry execution- or security-sensitive sinks and
     * must never be reachable through the generic api.config.read/write
     * permissions a non-super "configuration admin" can hold.
     *
     * `scheduler` is the critical case: scheduler.custom_jobs[].command is fed
     * straight into a Symfony Process by Job::run(), so write access to this
     * scope is arbitrary command execution. The Scheduler tool is super-only in
     * admin-classic, and these scopes are already excluded from index() listing
     * because they "belong to tools" — but resolveConfigKey()/scopeFileName()
     * still accept them, so without this guard a user holding only
     * api.config.write could escalate to RCE (GHSA-wx62). Require API super
     * authority for these scopes regardless of the generic config permission.
     */
    private const PRIVILEGED_SCOPES = ['scheduler', 'backups'];

    /**
     * Security-sensitive scopes that any config reader may VIEW but only an API
     * super user may WRITE. Unlike PRIVILEGED_SCOPES (tool-managed, fully
     * hidden from index() and blocked for read+write), these stay listed and
     * readable (a non-super "configuration admin" can still inspect them), but
     * must not persist changes, because they steer site-wide execution and
     * security behavior: `system` carries `twig.safe_functions` (PHP functions
     * callable from trusted templates) and `security` owns the Twig content
     * sandbox and XSS/CSP settings. The inheritable `admin.configuration`
     * permission would otherwise let a non-super admin weaken these
     * (GHSA-9wg2-prc3-vx89). Write-only gate; reads are intentionally left open.
     */
    private const SUPER_WRITE_SCOPES = ['system', 'security'];

    /**
     * GET /config - List available configuration sections.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.read');

        /** @var \RocketTheme\Toolbox\ResourceLocator\UniformResourceIterator $iterator */
        $iterator = $this->grav['locator']->getIterator('blueprints://config');

        $configurations = [];
        foreach ($iterator as $file) {
            if ($file->isDir() || !preg_match('/^[^.].*.yaml$/', $file->getFilename())) {
                continue;
            }
            $name = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            // Skip scheduler and backups (they belong to tools)
            if (in_array($name, ['scheduler', 'backups', 'streams'], true)) {
                continue;
            }
            $configurations[$name] = true;
        }

        // Sort and enforce canonical ordering: system, site first; info last
        ksort($configurations);
        $configurations = ['system' => true, 'site' => true] + $configurations + ['info' => true];

        return ApiResponse::create(array_keys($configurations));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.read');

        $scope = $this->getRouteParam($request, 'scope');
        $this->assertScopeAllowed($request, $scope);
        $configKey = $this->resolveConfigKey($scope);

        if ($this->config->get($configKey) === null) {
            throw new NotFoundException("Configuration scope '{$scope}' not found.");
        }

        // Body is the full merged config; the ETag keys off the persisted delta
        // for the same write target a subsequent PATCH would resolve, so the
        // client's stored ETag still validates on the next save.
        $targetEnv = $this->resolveTargetEnv($request);
        $etag = $this->generateEtag($this->configEtagBasis($scope, $targetEnv));

        return $this->respondWithEtag($this->configEtagData($configKey), 200, [], $etag);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.write');

        $scope = $this->getRouteParam($request, 'scope');
        $this->assertScopeAllowed($request, $scope);
        $this->assertScopeWritable($request, $scope);
        $configKey = $this->resolveConfigKey($scope);
        $existing = $this->config->get($configKey);

        if ($existing === null) {
            throw new NotFoundException("Configuration scope '{$scope}' not found.");
        }

        // Write target: X-Config-Environment selects an existing env folder; empty = base.
        $targetEnv = $this->resolveTargetEnv($request);

        // ETag validation — key off the persisted delta, the same basis show()
        // and the previous save's response used, so If-Match matches.
        $this->validateEtag($request, $this->generateEtag($this->configEtagBasis($scope, $targetEnv)));

        $body = $this->getRequestBody($request);

        if (empty($body)) {
            throw new ValidationException('Request body must contain configuration values to update.');
        }


        // Load the blueprint and apply field-type filtering (e.g., commalist → array)
        $blueprint = $this->loadBlueprint($scope);

        // Merge provided values with existing config. Prefer Grav's
        // blueprint-aware merge — it REPLACES map values at blueprint-defined
        // leaf fields instead of deep-merging them, which is what we want for
        // e.g. `type: file` fields whose keys are file paths: when the user
        // removes a file the client drops that key, and a blind deep-merge
        // would revive it from $existing. Fall back to our list-aware
        // mergePatch only when no blueprint is available (rare — mostly test
        // fixtures); plain array_replace_recursive would corrupt YAML lists.
        if ($blueprint !== null && is_array($existing)) {
            $merged = $blueprint->mergeData($existing, $body);
        } else {
            $merged = is_array($existing) ? $this->mergePatch($existing, $body) : $body;
        }

        $obj = new Data($merged, $blueprint);
        $obj->filter(true, true);

        // Set the config file on the Data object so plugins (e.g., revisions-pro)
        // can read the file path for revision tracking.
        $configFile = $this->resolveConfigFile($scope, $targetEnv);
        if ($configFile) {
            $obj->file(\RocketTheme\Toolbox\File\YamlFile::instance($configFile));
        }

        // Set the AdminProxy route so plugins that detect context from the admin
        // route (e.g., revisions-pro getDataType) work correctly in API context.
        $admin = $this->grav['admin'] ?? null;
        if ($admin && property_exists($admin, 'route')) {
            $admin->route = $this->scopeToAdminRoute($scope);
        }

        // Allow plugins to modify config before save
        $this->fireAdminEvent('onAdminSave', ['object' => &$obj]);

        // Extract (potentially modified) data back from the Data object
        $merged = $obj->toArray();

        // Update in-memory config
        $this->config->set($configKey, $merged);

        // Persist to the appropriate YAML file
        $this->writeConfigFile($scope, $merged, $targetEnv);

        // Clear config cache
        $this->grav['cache']->clearCache('standard');

        $this->fireAdminEvent('onAdminAfterSave', ['object' => $obj]);
        $this->fireEvent('onApiConfigUpdated', ['scope' => $scope, 'data' => $merged]);

        // Emit invalidations — plugin config changes also invalidate the plugins list.
        $tags = ['config:update:' . $scope];
        if (str_starts_with($scope, 'plugins/')) {
            $pluginName = substr($scope, 8);
            $tags[] = 'plugins:update:' . $pluginName;
            $tags[] = 'plugins:list';
        }

        // Response body is the full merged config; the ETag keys off the
        // persisted delta, so the client's stored ETag stays valid for the
        // next save even though default-equal values aren't written to disk.
        $etag = $this->generateEtag($this->configEtagBasis($scope, $targetEnv));
        return $this->respondWithEtag($this->configEtagData($configKey), 200, $tags, $etag);
    }

    /**
     * Full merged config for a scope — the response body for show()/update().
     * The admin form needs every value to render, so this stays the complete
     * config->get() snapshot. ETag stability is handled separately by
     * configEtagBasis(); see why the two must diverge there.
     */
    private function configEtagData(string $configKey): array
    {
        $data = $this->config->get($configKey);
        return is_array($data) ? $data : ['value' => $data];
    }

    /**
     * Representation the ETag is hashed from: the *persisted delta* (values
     * that override the parent), NOT the full merged config.
     *
     * The delta is the only representation that survives the save→reload round-trip.
     * writeConfigFile() stores only the delta, so a value equal to its default
     * (e.g. `system.pages.events.twig: true`) is present in the in-memory
     * config right after config->set() but absent once the file is reloaded
     * from disk on the next request. Hashing the full config therefore yielded
     * a different ETag on the following save and broke If-Match with a 409
     * (getgrav/grav-plugin-admin2#28). The delta is invariant because it is
     * defined relative to the parent: a default-equal value is stripped on
     * both sides of the round-trip. Canonicalized so key order can't shift the
     * hash either.
     */
    private function configEtagBasis(string $scope, ?string $targetEnv): array
    {
        $current = $this->config->get($this->resolveConfigKey($scope));
        $current = is_array($current) ? $current : ['value' => $current];

        $differ = new ConfigDiffer($this->grav);
        $delta = $differ->diff($current, $differ->parent($scope, $targetEnv));

        return ConfigDiffer::canonicalize($delta);
    }

    /**
     * Resolve the scope route parameter to a Grav config key.
     *
     * Supported scopes:
     *   - system          -> 'system'
     *   - site            -> 'site'
     *   - plugins/{name}  -> 'plugins.{name}'
     *   - themes/{name}   -> 'themes.{name}'
     */
    /**
     * Map a config scope to the admin route format that plugins expect.
     */
    private function scopeToAdminRoute(string $scope): string
    {
        return match (true) {
            str_starts_with($scope, 'plugins/') => '/' . $scope,
            str_starts_with($scope, 'themes/') => '/' . $scope,
            default => '/config/' . $scope,
        };
    }

    /**
     * Resolve the config file path for a given scope.
     *
     * Writes land in base user/config/ unless $targetEnv is a non-empty string
     * matching an existing user/env/<env>/ folder. We deliberately avoid the
     * `config://` stream here because its first resolved path can be an env
     * folder Grav auto-inferred from the hostname — that would create an
     * unintended user/<host>/ folder on save.
     */
    private function resolveConfigFile(string $scope, ?string $targetEnv = null): ?string
    {
        try {
            return $this->resolveWriteDir($targetEnv) . '/' . $this->scopeFileName($scope);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Load the blueprint for the given config scope.
     *
     * Blueprints define field types (e.g., commalist) that determine how
     * values are coerced — without this, arrays may be saved as strings.
     */
    private function loadBlueprint(string $scope): ?\Grav\Common\Data\Blueprint
    {
        try {
            $blueprintKey = match (true) {
                in_array($scope, ['system', 'site', 'media', 'security', 'scheduler', 'backups']) => 'config/' . $scope,
                str_starts_with($scope, 'plugins/') => 'plugins/' . substr($scope, 8),
                str_starts_with($scope, 'themes/') => 'themes/' . substr($scope, 7),
                default => null,
            };

            if ($blueprintKey === null) {
                return null;
            }

            $blueprints = new Blueprints();
            return $blueprints->get($blueprintKey);
        } catch (\Exception) {
            // If blueprint can't be loaded, save without filtering
            return null;
        }
    }

    /**
     * Reject access to execution- or security-sensitive, tool-managed scopes
     * unless the caller is an API super user. See PRIVILEGED_SCOPES (GHSA-wx62).
     */
    private function assertScopeAllowed(ServerRequestInterface $request, ?string $scope): void
    {
        if ($scope !== null && in_array($scope, self::PRIVILEGED_SCOPES, true)
            && !$this->isSuperAdmin($this->getUser($request))) {
            throw new ForbiddenException(
                "Configuration scope '{$scope}' is tool-managed and restricted to API super users."
            );
        }
    }

    /**
     * Reject WRITES to security-sensitive scopes unless the caller is an API
     * super user. Reads/listing remain open. See SUPER_WRITE_SCOPES
     * (GHSA-9wg2-prc3-vx89).
     */
    private function assertScopeWritable(ServerRequestInterface $request, ?string $scope): void
    {
        if ($scope !== null && in_array($scope, self::SUPER_WRITE_SCOPES, true)
            && !$this->isSuperAdmin($this->getUser($request))) {
            throw new ForbiddenException(
                "Configuration scope '{$scope}' can only be modified by an API super user."
            );
        }
    }

    private function resolveConfigKey(?string $scope): string
    {
        if ($scope === null || $scope === '') {
            throw new ValidationException('Configuration scope is required.');
        }

        return match (true) {
            $scope === 'system' => 'system',
            $scope === 'site' => 'site',
            $scope === 'media' => 'media',
            $scope === 'security' => 'security',
            $scope === 'scheduler' => 'scheduler',
            $scope === 'backups' => 'backups',
            str_starts_with($scope, 'plugins/') => 'plugins.' . substr($scope, 8),
            str_starts_with($scope, 'themes/') => 'themes.' . substr($scope, 7),
            default => throw new NotFoundException("Unknown configuration scope '{$scope}'."),
        };
    }

    /**
     * Resolve the scope to a filesystem path and write the YAML config file.
     *
     * We persist only the delta vs the parent (defaults for base writes;
     * defaults+base for env writes). This mirrors how developers hand-edit
     * Grav configs — every file contains only the values that actually
     * override something lower in the stack.
     */
    private function writeConfigFile(string $scope, mixed $data, ?string $targetEnv = null): void
    {
        $filePath = $this->resolveWriteDir($targetEnv) . '/' . $this->scopeFileName($scope);

        $full = is_array($data) ? $data : ['value' => $data];
        $differ = new ConfigDiffer($this->grav);
        $parent = $differ->parent($scope, $targetEnv);
        $delta = $differ->diff($full, $parent);

        // No overrides and no pre-existing file → don't create an empty placeholder.
        if ($delta === [] && !is_file($filePath)) {
            return;
        }

        // Only ever create plugin/theme sub-dirs inside an existing base or env
        // write dir. We never create env roots — those must be opted into
        // explicitly via POST /system/environments.
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($filePath, Yaml::dump($delta));
    }

    /**
     * Where config writes land.
     *
     * Base user/config/ by default. When $targetEnv is set, the matching
     * user/env/<env>/config/ is used — but only if it already exists, we
     * never implicitly create env folders.
     */
    private function resolveWriteDir(?string $targetEnv = null): string
    {
        if ($targetEnv !== null && $targetEnv !== '') {
            $dir = (new EnvironmentService($this->grav))->envConfigRoot($targetEnv);
            if ($dir === null) {
                throw new ValidationException("Environment '{$targetEnv}' does not exist. Create it first via POST /system/environments.");
            }
            return $dir;
        }

        $userConfig = $this->grav['locator']->findResource('user://config', true);
        if (!$userConfig) {
            throw new \RuntimeException('Base user/config directory not found.');
        }
        return $userConfig;
    }

    /**
     * Where a write should land for this request.
     *
     *   header present + non-empty → that env (validated, must exist on disk)
     *   header present + empty     → explicit base write (opt out of auto-detection)
     *   header absent              → Grav's currently-active env if it has a
     *                                config dir on disk; otherwise base
     *
     * The auto-detect branch keeps writes consistent with reads: config is
     * loaded with `user/<active-env>/config` overlaid on `user/config`, so
     * persisting to base when an env overlay exists lets the env file silently
     * shadow the write. (See: enabling a plugin that's pinned `enabled: false`
     * in a hostname-derived env folder.)
     */
    private function resolveTargetEnv(ServerRequestInterface $request): ?string
    {
        if (!$request->hasHeader('X-Config-Environment')) {
            return (new EnvironmentService($this->grav))->activeEnvironment();
        }

        $name = trim($request->getHeaderLine('X-Config-Environment'));
        if ($name === '') return null;

        if (!EnvironmentService::isValidName($name)) {
            throw new ValidationException("Invalid X-Config-Environment header: '{$name}'.");
        }
        return $name;
    }

    /**
     * Filename for a scope, relative to a config directory.
     */
    private function scopeFileName(string $scope): string
    {
        return match (true) {
            in_array($scope, ['system', 'site', 'media', 'security', 'scheduler', 'backups'], true) => $scope . '.yaml',
            str_starts_with($scope, 'plugins/') => 'plugins/' . substr($scope, 8) . '.yaml',
            str_starts_with($scope, 'themes/') => 'themes/' . substr($scope, 7) . '.yaml',
            default => throw new NotFoundException("Unknown configuration scope '{$scope}'."),
        };
    }

}
