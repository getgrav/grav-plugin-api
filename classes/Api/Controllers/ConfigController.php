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
use Grav\Plugin\Api\Services\ConfigScopes;
use Grav\Plugin\Api\Services\ConfigSecretMasker;
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
     * Core scopes index() lists as config tabs. It's {@see ConfigScopes::CORE}
     * minus `scheduler` (tool-managed: `custom_jobs[].command` is a command sink,
     * so it's never a generic config tab). `streams` isn't a CORE scope and is
     * likewise never listed. `backups` is listed for super users only (see
     * index()). Anything not here and not {@see ConfigScopes::isCustom()} is
     * omitted, so the list mirrors exactly what show()/update() accept.
     */
    private const LISTABLE_CORE_SCOPES = ['system', 'site', 'media', 'security', 'backups'];

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
     * Plugin config scopes (`plugins/<name>`) whose settings own the API's own
     * security posture — auth toggles, the CORS allow-list, and rate limiting.
     * Like SUPER_WRITE_SCOPES these stay readable to any config reader but may
     * only be WRITTEN by an API super user: a non-super `api.config.write`
     * caller must not be able to disable rate limiting or widen CORS for the
     * whole platform through PATCH /config/plugins/api (GHSA-4pqv-2qj5-38fp).
     */
    private const SUPER_WRITE_PLUGIN_SCOPES = ['api'];

    /**
     * GET /config - List available configuration sections.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.read');

        // `backups` is a PRIVILEGED_SCOPE: read+write are already gated to API
        // super users (assertScopeAllowed). Unlike `scheduler` — whose
        // custom_jobs[].command is a command-execution sink and stays
        // tool-only — the backups config (purge thresholds, profiles, schedule,
        // exclude paths) is safe to edit through the generic config form, so we
        // surface it in the scope list for supers. Non-supers never see it here
        // and would get a 403 from show()/update() regardless.
        $isSuper = $this->isSuperAdmin($this->getUser($request));

        /** @var \RocketTheme\Toolbox\ResourceLocator\UniformResourceIterator $iterator */
        $iterator = $this->grav['locator']->getIterator('blueprints://config');

        $configurations = [];
        foreach ($iterator as $file) {
            if ($file->isDir() || !preg_match('/^[^.].*.yaml$/', $file->getFilename())) {
                continue;
            }
            $name = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            // Only list scopes show()/update() will actually accept: the core
            // scopes this endpoint handles, plus site/plugin/theme-authored
            // custom scopes (the same gate resolveConfigKey() uses). Anything
            // else the merged stream yields — scheduler, streams, or a future
            // core system blueprint — would 404 on open, so it's not listed.
            if (!in_array($name, self::LISTABLE_CORE_SCOPES, true)
                && !ConfigScopes::isCustom($this->grav, $name)) {
                continue;
            }
            // backups is listed for super users only (see note above).
            if ($name === 'backups' && !$isSuper) {
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

        // Body is the full merged config resolved for the requested target, so
        // base/"Default" shows base config rather than the active env overlay.
        // The ETag keys off the persisted delta for the same write target a
        // subsequent PATCH would resolve, so the client's stored ETag still
        // validates on the next save.
        $targetEnv = $this->resolveTargetEnv($request);
        $etag = $this->generateEtag($this->configEtagBasis($scope, $targetEnv));

        // meta.overrides / meta.fallback drive the per-field override indicators
        // and the revert affordance in admin2 (see docs/config-overrides-revert).
        $meta = $this->overrideMeta($scope, $targetEnv);

        // Mask secret values (passwords, API keys, tokens) before the config
        // leaves the server. Applies to every caller, not just demo mode — a
        // plain api.config.read must not expose SMTP passwords or licence keys.
        $body = ConfigSecretMasker::mask($this->effectiveConfig($scope, $targetEnv), $this->loadBlueprint($scope));

        return $this->respondWithEtag($body, 200, [], $etag, $meta);
    }

    /**
     * POST /config/{scope}/revert — drop one or more overridden keys from the
     * active layer's file (or reset the whole scope), letting the value beneath
     * take over. Body: `{"keys": ["pages.theme", ...]}` or `{"reset": true}`.
     *
     * The active layer is the same write target show()/update() resolve from
     * X-Config-Environment: base `user/config/<scope>.yaml`, or an environment's
     * `user/env/<env>/config/<scope>.yaml`. Reverting a key there falls back to
     * the layer beneath (base → core/plugin defaults; env → base).
     */
    public function revert(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.write');

        $scope = $this->getRouteParam($request, 'scope');
        $this->assertScopeAllowed($request, $scope);
        $this->assertScopeWritable($request, $scope);
        $configKey = $this->resolveConfigKey($scope);

        if ($this->config->get($configKey) === null) {
            throw new NotFoundException("Configuration scope '{$scope}' not found.");
        }

        $targetEnv = $this->resolveTargetEnv($request);

        // Same ETag basis as show()/update(), so the client's stored If-Match validates.
        $this->validateEtag($request, $this->generateEtag($this->configEtagBasis($scope, $targetEnv)));

        $body = $this->getRequestBody($request);
        $reset = !empty($body['reset']);
        $keys = $body['keys'] ?? [];
        if (!$reset && (!is_array($keys) || $keys === [])) {
            throw new ValidationException('Provide a non-empty "keys" array or "reset": true.');
        }

        $filePath = $this->resolveConfigFile($scope, $targetEnv);

        if ($reset) {
            // Nuke the active layer's file entirely → falls back to the parent layer.
            if ($filePath && is_file($filePath)) {
                unlink($filePath);
            }
        } elseif ($filePath) {
            // The file already IS the persisted delta — drop each requested key,
            // prune empties, and rewrite, or remove the file if nothing remains.
            $delta = is_file($filePath) ? Yaml::parse((string) file_get_contents($filePath)) : [];
            if (!is_array($delta)) {
                $delta = [];
            }
            $differ = new ConfigDiffer($this->grav);
            foreach ($keys as $key) {
                if (is_string($key) && $key !== '') {
                    $delta = $differ->unsetDotPath($delta, $key);
                }
            }
            if ($delta === []) {
                if (is_file($filePath)) {
                    unlink($filePath);
                }
            } else {
                $dir = dirname($filePath);
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new \RuntimeException(sprintf('Unable to create directory "%s"', $dir));
                }
                file_put_contents($filePath, Yaml::dump($delta));
            }
        }

        // Refresh in-memory config + clear cache so the next read is correct.
        $effective = $this->effectiveConfig($scope, $targetEnv);
        $this->config->set($configKey, $effective);
        $this->grav['cache']->clearCache('standard');
        $this->fireEvent('onApiConfigUpdated', ['scope' => $scope, 'data' => $effective]);

        $tags = ['config:update:' . $scope];
        if (str_starts_with($scope, 'plugins/')) {
            $pluginName = substr($scope, 8);
            $tags[] = 'plugins:update:' . $pluginName;
            $tags[] = 'plugins:list';
        }

        $etag = $this->generateEtag($this->configEtagBasis($scope, $targetEnv));
        $meta = $this->overrideMeta($scope, $targetEnv);
        return $this->respondWithEtag(
            ConfigSecretMasker::mask($effective, $this->loadBlueprint($scope)),
            200,
            $tags,
            $etag,
            $meta
        );
    }

    /**
     * Override metadata for the active layer: which dotted leaf paths the
     * target's file actually overrides, and the value each would revert to.
     *
     * @return array{overrides: list<string>, fallback: array<string, mixed>}
     */
    private function overrideMeta(string $scope, ?string $targetEnv): array
    {
        $differ = new ConfigDiffer($this->grav);
        $parent = $differ->parent($scope, $targetEnv);
        $delta = $differ->diff($this->effectiveConfig($scope, $targetEnv), $parent);

        $overrides = ConfigDiffer::flattenLeaves($delta);
        $fallback = [];
        foreach ($overrides as $path) {
            $fallback[$path] = ConfigDiffer::valueAtPath($parent, $path);
        }

        return ['overrides' => $overrides, 'fallback' => $fallback];
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.write');

        $scope = $this->getRouteParam($request, 'scope');
        $this->assertScopeAllowed($request, $scope);
        $this->assertScopeWritable($request, $scope);
        $configKey = $this->resolveConfigKey($scope);

        if ($this->config->get($configKey) === null) {
            throw new NotFoundException("Configuration scope '{$scope}' not found.");
        }

        // Write target: X-Config-Environment selects an existing env folder; empty/default = base.
        $targetEnv = $this->resolveTargetEnv($request);

        // Edit against the baseline for THIS target, not the live (boot-env)
        // config — otherwise a save under base/"Default" would diff the active
        // env overlay against defaults and copy the overlay into user/config.
        $existing = $this->effectiveConfig($scope, $targetEnv);

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

        // Restore any masked secret that round-tripped back unchanged. show()
        // returns secrets as a sentinel (ConfigSecretMasker), and the form posts
        // the whole scope back on save — so a password the user never touched
        // arrives as the literal sentinel. Without this, the next unrelated save
        // would persist the sentinel and destroy the real secret. A genuine
        // change (empty, or any non-sentinel value) still passes through.
        if ($blueprint !== null && is_array($existing) && is_array($merged)) {
            $merged = ConfigSecretMasker::restoreSentinels($merged, $existing, $blueprint);
        }

        // Validate the submitted fields against the blueprint before persisting
        // (getgrav/grav-plugin-admin2#30). A `validate.required` field sent
        // empty now returns 422 instead of silently saving. The admin-next form
        // posts the whole config, not just edited fields, so pass $existing as
        // the baseline: only leaves that actually changed are validated. This
        // stops one pre-existing invalid value (e.g. a migrated system.yaml
        // setting) from blocking every save of the scope (getgrav/grav#4176).
        $this->validateChangedFields($body, $blueprint, is_array($existing) ? $existing : []);

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

        // Response body is the full merged config for the target (re-resolved
        // from disk so it matches a subsequent show()); the ETag keys off the
        // persisted delta, so the client's stored ETag stays valid for the
        // next save even though default-equal values aren't written to disk.
        $etag = $this->generateEtag($this->configEtagBasis($scope, $targetEnv));
        $meta = $this->overrideMeta($scope, $targetEnv);
        return $this->respondWithEtag(
            ConfigSecretMasker::mask($this->effectiveConfig($scope, $targetEnv), $blueprint),
            200,
            $tags,
            $etag,
            $meta
        );
    }

    /**
     * Full merged config for a scope, resolved for the requested write target —
     * the response body for show()/update() and the baseline a save edits.
     *
     * The live config->get() snapshot only ever represents the ONE environment
     * Grav booted under, and Grav resolves that once at boot and can't switch
     * mid-request. Any request can target a different env via X-Config-Environment
     * (most importantly base/"Default" while a hostname overlay is active), so we
     * always recompute the merge from YAML files (ConfigDiffer::effective). That
     * keeps "Default" showing — and saving against — base config, not the env
     * overlay, and stays correct for any other named target too.
     */
    private function effectiveConfig(string $scope, ?string $targetEnv): array
    {
        // Always resolve from YAML files for the requested target. We must NOT
        // shortcut to the live config->get() snapshot even when the target looks
        // like the booted environment: behind a reverse proxy Grav loads its
        // config overlay from the REAL connection host (e.g. `localhost` via
        // SERVER_NAME), which need not match the requested target. (Note
        // EnvironmentService::activeEnvironment() now reports that booted host,
        // not the forwarded one — but $targetEnv may still be any other env.)
        // ConfigDiffer::effective() is target-exact regardless of which host
        // booted the request, and already re-applies GRAV_CONFIG__* env-var
        // overrides; blueprint field defaults are filled client-side from the
        // blueprint, so the form stays complete.
        $data = (new ConfigDiffer($this->grav))->effective($scope, $targetEnv);
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
        $current = $this->effectiveConfig($scope, $targetEnv);

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
                in_array($scope, ConfigScopes::CORE) => 'config/' . $scope,
                str_starts_with($scope, 'plugins/') => 'plugins/' . substr($scope, 8),
                str_starts_with($scope, 'themes/') => 'themes/' . substr($scope, 7),
                ConfigScopes::isCustom($this->grav, $scope) => 'config/' . $scope,
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
        if ($scope === null) {
            return;
        }

        // A security-bearing plugin scope (e.g. plugins/api owns CORS, auth and
        // rate-limit settings) is write-gated to API super users, mirroring the
        // core SUPER_WRITE_SCOPES treatment (GHSA-4pqv-2qj5-38fp).
        $isSuperWritePlugin = str_starts_with($scope, 'plugins/')
            && in_array(substr($scope, 8), self::SUPER_WRITE_PLUGIN_SCOPES, true);

        if ((in_array($scope, self::SUPER_WRITE_SCOPES, true) || $isSuperWritePlugin)
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
            // Site-authored top-level config (cookbook custom yaml): the scope
            // name is its own config key (user/config/<scope>.yaml).
            ConfigScopes::isCustom($this->grav, $scope) => $scope,
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
        // Never persist values supplied through GRAV_CONFIG__* env vars (.env);
        // they're re-applied at runtime and writing them would leak secrets to disk.
        $full = $differ->stripEnvironmentOverrides($full, $scope);
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
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create directory "%s"', $dir));
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
     *   header present + env name      → that env (validated, must exist on disk)
     *   header present + `default`/base → explicit base write (the admin-next
     *                                     sentinel; non-empty so proxies/FPM
     *                                     can't strip it the way empty values
     *                                     get dropped)
     *   header present + empty          → explicit base write (legacy opt-out)
     *   header absent                   → Grav's currently-active env if it has
     *                                     a config dir on disk; otherwise base
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
        if ($name === '' || EnvironmentService::isReservedName($name)) {
            return null;
        }

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
            in_array($scope, ConfigScopes::CORE, true) => $scope . '.yaml',
            str_starts_with($scope, 'plugins/') => 'plugins/' . substr($scope, 8) . '.yaml',
            str_starts_with($scope, 'themes/') => 'themes/' . substr($scope, 7) . '.yaml',
            ConfigScopes::isCustom($this->grav, $scope) => $scope . '.yaml',
            default => throw new NotFoundException("Unknown configuration scope '{$scope}'."),
        };
    }

}
