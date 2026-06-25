<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Data\Blueprint;
use Grav\Common\Page\Pages;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Services\ConfigScopes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;
use Throwable;

class BlueprintController extends AbstractApiController
{
    use TranslatesAdminLabels;

    /**
     * Whitelist of callable patterns allowed by the resolve endpoint.
     * Only static methods from known Grav namespaces are permitted.
     */
    private const RESOLVE_ALLOWED_NAMESPACES = [
        'Grav\\Common\\',
        'Grav\\Plugin\\',
    ];

    /**
     * GET /data/resolve?callable=\Grav\Common\Page\Pages::pageTypes
     *
     * Generic endpoint for resolving data-options@ directives used in blueprints.
     * Returns the array result of calling a whitelisted static PHP method.
     * Client should cache responses — these are effectively static data.
     */
    public function resolveData(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');

        $query = $request->getQueryParams();
        $callable = $query['callable'] ?? null;

        if (!$callable || !is_string($callable)) {
            throw new ValidationException(['callable' => ['The callable query parameter is required.']]);
        }

        $callable = ltrim($callable, '\\');

        // Validate against whitelist
        $allowed = false;
        foreach (self::RESOLVE_ALLOWED_NAMESPACES as $ns) {
            if (str_starts_with($callable, $ns)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new ValidationException(['callable' => ['Callable is not in the allowed namespace list.']]);
        }

        // Ensure Pages subsystem for Page-related callables
        if (str_contains($callable, 'Page')) {
            $this->ensurePagesEnabled();
        }

        if (!str_contains($callable, '::')) {
            throw new ValidationException(['callable' => ['Callable must be in Class::method format.']]);
        }

        [$class, $method] = explode('::', $callable, 2);
        $class = '\\' . $class;

        if (!class_exists($class) || !method_exists($class, $method)) {
            // admin-classic ships permission-filtered page-type wrappers
            // (\Grav\Plugin\AdminPlugin::pagesTypes / ::pagesModularTypes).
            // admin-next is designed to run without admin-classic, but a
            // blueprint or a stale compiled-blueprint cache can still reference
            // those callables — in which case the class isn't loaded and the
            // hard guard below would 500 the template selector
            // (grav-plugin-admin2#41). Fall back to core's always-available
            // equivalent rather than throwing.
            if (in_array($method, ['pagesTypes', 'pagesModularTypes'], true)) {
                // Honor an explicit `?type=` (the client sends it from the page's
                // modular/standard context); only fall back to the method-name
                // default when it's absent, so a modular page doesn't get the
                // standard list and an empty template selector (admin2#41).
                $type = $query['type'] ?? ($method === 'pagesModularTypes' ? 'modular' : 'standard');
                return ApiResponse::create($this->normalizeOptions(Pages::pageTypes($type)));
            }

            throw new NotFoundException("Callable '{$callable}' not found.");
        }

        // For pageTypes(), pass the type arg so it returns standard or modular
        if ($method === 'pageTypes') {
            $type = $query['type'] ?? 'standard';
            $result = $class::$method($type);
        } else {
            $result = $class::$method();
        }

        if (!is_array($result)) {
            return ApiResponse::create([]);
        }

        return ApiResponse::create($this->normalizeOptions($result));
    }

    /**
     * Normalize a [key => label] map to the [{value, label}] format the
     * admin-next SelectField expects for `data-options@` results.
     *
     * @param array<string|int, mixed> $options
     * @return list<array{value: string, label: string}>
     */
    private function normalizeOptions(array $options): array
    {
        $normalized = [];
        foreach ($options as $key => $label) {
            $normalized[] = [
                'value' => (string) $key,
                'label' => is_string($label) ? $label : (string) $key,
            ];
        }

        return $normalized;
    }

    /**
     * GET /blueprints/pages - List available page blueprints (templates).
     */
    public function pageTypes(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');
        $this->primeAdminLanguages($request);
        $this->ensurePagesEnabled();

        // `?modular=true` returns modular templates (those whose Twig template
        // file is prefixed with `_`, intended as sub-pages of a modular parent)
        // instead of regular page templates. Mirrors the split classic admin
        // makes between "Add Page" and "Add Module".
        $params = $request->getQueryParams();
        $modular = isset($params['modular'])
            && in_array(strtolower((string) $params['modular']), ['1', 'true', 'yes'], true);

        $types = $modular ? Pages::modularTypes() : Pages::types();
        $result = [];

        foreach ($types as $type => $label) {
            $result[] = [
                'type' => $type,
                'label' => is_string($label) ? $label : $type,
            ];
        }

        return ApiResponse::create($result);
    }

    /**
     * GET /blueprints/pages/{template} - Get resolved blueprint for a page template.
     */
    public function pageBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');
        $this->primeAdminLanguages($request);

        $template = $this->getRouteParam($request, 'template');

        $blueprint = $this->loadPageBlueprint($template, $this->getUser($request));

        if (!$blueprint) {
            throw new NotFoundException("Blueprint for template '{$template}' not found.");
        }

        $data = $this->serializeBlueprint($blueprint, $template);

        // Fire event to allow plugins to modify the serialized blueprint fields
        // (e.g., editor-pro overrides editor/markdown field types). The
        // explicit `context` discriminator lets listeners gate behavior to a
        // specific blueprint family (e.g. ai-translate annotates only pages).
        $event = new Event([
            'context' => 'page',
            'fields' => $data['fields'],
            'template' => $template,
            'user' => $this->getUser($request),
        ]);
        $this->grav->fireEvent('onApiBlueprintResolved', $event);
        $data['fields'] = $event['fields'];

        return ApiResponse::create($data);
    }

    /**
     * GET /blueprints/plugins/{plugin} - Get resolved blueprint for a plugin.
     */
    public function pluginBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.read');
        $this->primeAdminLanguages($request);

        $pluginName = $this->getRouteParam($request, 'plugin');
        $pluginPath = $this->grav['locator']->findResource("plugin://{$pluginName}");

        if (!$pluginPath || !file_exists($pluginPath . '/blueprints.yaml')) {
            throw new NotFoundException("Blueprint for plugin '{$pluginName}' not found.");
        }

        $blueprint = new Blueprint($pluginPath . '/blueprints.yaml');
        $blueprint->load();

        $data = $this->serializeBlueprint($blueprint, $pluginName);

        // Fire event to allow plugins to modify serialized fields
        $event = new Event([
            'context' => 'plugin',
            'fields' => $data['fields'],
            'plugin' => $pluginName,
            'user' => $this->getUser($request),
        ]);
        $this->grav->fireEvent('onApiBlueprintResolved', $event);
        $data['fields'] = $event['fields'];

        return ApiResponse::create($data);
    }

    /**
     * GET /blueprints/themes/{theme} - Get resolved blueprint for a theme.
     */
    public function themeBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.read');
        $this->primeAdminLanguages($request);

        $themeName = $this->getRouteParam($request, 'theme');
        $themesPath = $this->grav['locator']->findResource('themes://');
        $themePath = $themesPath . '/' . $themeName;

        if (!is_dir($themePath) || !file_exists($themePath . '/blueprints.yaml')) {
            throw new NotFoundException("Blueprint for theme '{$themeName}' not found.");
        }

        $blueprint = new Blueprint($themePath . '/blueprints.yaml');
        $blueprint->load();

        $data = $this->serializeBlueprint($blueprint, $themeName);

        // Fire event so plugins can extend / annotate theme blueprints, with
        // an explicit `context` discriminator so listeners (e.g. ai-translate)
        // can scope behavior to a specific blueprint family.
        $event = new Event([
            'context' => 'theme',
            'fields' => $data['fields'],
            'theme' => $themeName,
            'user' => $this->getUser($request),
        ]);
        $this->grav->fireEvent('onApiBlueprintResolved', $event);
        $data['fields'] = $event['fields'];

        return ApiResponse::create($data);
    }

    /**
     * GET /blueprints/users - Get the user account blueprint.
     */
    public function userBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        // The user blueprint is just the form schema, not user data — every
        // authenticated user needs it to render their own profile form, even
        // those without api.users.read.
        $this->requirePermission($request, 'api.access');
        $this->primeAdminLanguages($request);

        $blueprintPath = $this->grav['locator']->findResource('blueprints://user/account.yaml');

        if (!$blueprintPath) {
            $blueprintPath = $this->grav['locator']->findResource('system://blueprints/user/account.yaml');
        }

        if (!$blueprintPath) {
            throw new NotFoundException('User account blueprint not found.');
        }

        $blueprint = new Blueprint($blueprintPath);
        $blueprint->load();

        $data = $this->serializeBlueprint($blueprint, 'account');

        // Fire event so plugins can extend the user blueprint (e.g. admin2
        // injects the account-state toggle, since core's account.yaml has
        // no field for it).
        $event = new Event([
            'context' => 'account',
            'fields' => $data['fields'],
            'template' => 'account',
            'user' => $this->getUser($request),
        ]);
        $this->grav->fireEvent('onApiBlueprintResolved', $event);
        $data['fields'] = $event['fields'];

        return ApiResponse::create($data);
    }

    /**
     * GET /blueprints/groups - User group edit blueprint (user/group.yaml).
     */
    public function groupBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        return $this->loadGroupBlueprint($request, 'group', 'group');
    }

    /**
     * GET /blueprints/groups/new - User group creation blueprint (user/group_new.yaml).
     */
    public function groupNewBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        return $this->loadGroupBlueprint($request, 'group_new', 'group_new');
    }

    private function loadGroupBlueprint(
        ServerRequestInterface $request,
        string $name,
        string $context,
    ): ResponseInterface {
        $this->requirePermission($request, 'api.users.read');
        $this->primeAdminLanguages($request);

        $path = $this->grav['locator']->findResource("blueprints://user/{$name}.yaml")
            ?: $this->grav['locator']->findResource("system://blueprints/user/{$name}.yaml");

        if (!$path) {
            throw new NotFoundException("Group blueprint '{$name}' not found.");
        }

        $blueprint = new Blueprint($path);
        $blueprint->load();

        $data = $this->serializeBlueprint($blueprint, $name);

        $event = new Event([
            'context' => $context,
            'fields' => $data['fields'],
            'template' => $name,
            'user' => $this->getUser($request),
        ]);
        $this->grav->fireEvent('onApiBlueprintResolved', $event);
        $data['fields'] = $event['fields'];

        return ApiResponse::create($data);
    }

    /**
     * GET /blueprints/config/accounts - Flex accounts configuration blueprint
     * (the form behind the "Configuration" tab on the Users page).
     *
     * Delegates to FlexDirectory::getDirectoryBlueprint() — the same code path
     * admin-classic uses. That loads blueprints://flex/shared/configure.yaml
     * (the Caching tab) as the base and embeds the user-accounts blueprint's
     * `blueprints.configure.fields` (Compatibility tab via import@) as sibling
     * tabs. Reimplementing this by hand would silently drop the Caching tab
     * (the shared form isn't reachable from the user-accounts blueprint alone).
     */
    public function accountsConfigBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.read');
        $this->primeAdminLanguages($request);

        $flex = $this->grav['flex_objects'] ?? null;
        if (!$flex) {
            throw new NotFoundException('Flex Objects is not available — Accounts configuration requires it.');
        }

        $directory = $flex->getDirectory('user-accounts');
        if (!$directory) {
            throw new NotFoundException('user-accounts flex directory is not registered.');
        }

        $blueprint = $directory->getDirectoryBlueprint();

        $data = $this->serializeBlueprint($blueprint, 'accounts');
        if (empty($data['title'])) {
            $data['title'] = 'Accounts Configuration';
        }

        $event = new Event([
            'context' => 'config',
            'fields' => $data['fields'],
            'template' => 'accounts',
            'user' => $this->getUser($request),
        ]);
        $this->grav->fireEvent('onApiBlueprintResolved', $event);
        $data['fields'] = $event['fields'];

        return ApiResponse::create($data);
    }

    /**
     * GET /blueprints/users/permissions - Get all registered permission actions.
     */
    public function permissionsBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.users.read');
        $this->primeAdminLanguages($request);

        /** @var \Grav\Framework\Acl\Permissions $permissions */
        $permissions = $this->grav['permissions'];

        $sections = [];
        foreach ($permissions as $name => $action) {
            $sections[] = $this->serializePermissionAction($action, $name);
        }

        return ApiResponse::create($sections);
    }

    /**
     * Recursively serialize a permission action and its children.
     */
    private function serializePermissionAction(object $action, string $name): array
    {
        $rawLabel = $action->label ?? $name;
        $label = $this->translateLabel($rawLabel);

        $data = [
            'name' => $name,
            'label' => $label,
        ];

        // Check for child actions
        $children = [];
        if ($action instanceof \IteratorAggregate || $action instanceof \Traversable) {
            foreach ($action as $child) {
                // Use $child->name which has the full dotted path (e.g. "admin.login")
                $children[] = $this->serializePermissionAction($child, $child->name ?? $name);
            }
        }

        if ($children) {
            $data['children'] = $children;
        }

        return $data;
    }


    /**
     * GET /blueprints/plugins/{plugin}/pages/{pageId} - Get custom page blueprint for a plugin.
     */
    public function pluginPageBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.read');
        $this->primeAdminLanguages($request);

        $plugin = $this->getRouteParam($request, 'plugin');
        $pageId = $this->getRouteParam($request, 'pageId');

        $pluginPath = $this->grav['locator']->findResource("plugin://{$plugin}");

        if (!$pluginPath) {
            throw new NotFoundException("Plugin '{$plugin}' not found.");
        }

        $blueprintFile = $pluginPath . '/admin/blueprints/' . basename($pageId) . '.yaml';

        // Fallback: when the dedicated admin/blueprints/{pageId}.yaml is missing
        // and the page id matches the plugin slug, treat the plugin's main
        // blueprints.yaml as the page blueprint. Lets plugins whose admin-next
        // settings page is just the existing plugin form skip maintaining a
        // duplicate YAML — algolia-pro keeps its dedicated page blueprint, but
        // simpler plugins (git-sync) reuse the one they already have.
        if (!file_exists($blueprintFile) && $pageId === $plugin && file_exists($pluginPath . '/blueprints.yaml')) {
            $blueprintFile = $pluginPath . '/blueprints.yaml';
        }

        if (!file_exists($blueprintFile)) {
            throw new NotFoundException("Page blueprint '{$pageId}' not found for plugin '{$plugin}'.");
        }

        $blueprint = new Blueprint($blueprintFile);
        $blueprint->load();

        $data = $this->serializeBlueprint($blueprint, $pageId);

        // Fire event so plugins (notably flex-objects) can extend plugin
        // page blueprints — e.g. inject the shared Flex configure tabs
        // (Caching) when the owning plugin manages a Flex directory.
        $event = new Event([
            'context'  => 'plugin-page',
            'fields'   => $data['fields'],
            'plugin'   => $plugin,
            'page_id'  => $pageId,
            'user'     => $this->getUser($request),
        ]);
        $this->grav->fireEvent('onApiBlueprintResolved', $event);
        $data['fields'] = $event['fields'];

        return ApiResponse::create($data);
    }

    /**
     * GET /blueprints/config/{scope} - Get blueprint for system/site config.
     */
    public function configBlueprint(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.read');
        $this->primeAdminLanguages($request);

        $scope = $this->getRouteParam($request, 'scope');

        // Core scopes ship system blueprints; custom scopes are site-authored
        // top-level configs (the cookbook "add a custom yaml file" recipe). Any
        // other scope — including core/system blueprints like `streams` — is
        // rejected. See {@see ConfigScopes::isCustom()} for the security gate.
        if (!in_array($scope, ConfigScopes::CORE, true) && !ConfigScopes::isCustom($this->grav, $scope)) {
            throw new NotFoundException("Config blueprint scope '{$scope}' not found.");
        }

        // Use the blueprints:// stream to find config blueprints so that
        // plugin overrides (e.g., admin's media.yaml) are resolved correctly.
        $realPath = $this->grav['locator']->findResource("blueprints://config/{$scope}.yaml");

        if (!$realPath) {
            // Fallback to system blueprints directly
            $realPath = $this->grav['locator']->findResource("system://blueprints/config/{$scope}.yaml");
        }

        if (!$realPath) {
            throw new NotFoundException("Config blueprint for '{$scope}' not found.");
        }

        $blueprint = new Blueprint($realPath);
        $blueprint->load();

        return ApiResponse::create($this->serializeBlueprint($blueprint, $scope));
    }

    /**
     * Load a fully-resolved page blueprint via Grav core's standard pipeline.
     *
     * Delegates to Pages::blueprints() (= Blueprints::loadFile() → Blueprint::load()->init())
     * — the same path admin-classic uses. This honors every BlueprintForm
     * directive (replace@, unset@, replace-<prop>@, ordering@, import@ with
     * inline insertion, @extends with context, config-default@, etc.), and
     * fires onBlueprintCreated so plugins can extend the result.
     *
     * Earlier versions hand-rolled YAML merging here to dodge a perceived
     * memory-exhaustion risk in the full pipeline. In practice Grav core
     * runs this code on every page edit in admin-classic without trouble,
     * and the hand-rolled path silently dropped most BlueprintForm directives
     * (see grav-plugin-admin2#3).
     */
    private function loadPageBlueprint(string $template, ?UserInterface $user = null): ?Blueprint
    {
        $this->ensurePagesEnabled();

        /** @var Pages $pages */
        $pages = $this->grav['pages'];

        try {
            $blueprint = $pages->blueprints($template);
        } catch (\RuntimeException) {
            return null;
        }

        // An orphan template — one with no blueprint of its own, e.g. a page
        // left on a template that the current theme doesn't define after a
        // theme switch — resolves to an empty blueprint with no fields. Grav
        // core only falls back to `default` when the lookup *throws*, which a
        // missing blueprint file does not: it returns the empty blueprint
        // instead. Mirror admin-classic and fall back to the default page
        // blueprint so the editor always shows the standard page form rather
        // than a blank pane.
        if (!$blueprint->fields()) {
            try {
                $blueprint = $pages->blueprints('default');
            } catch (\RuntimeException) {
                return null;
            }
        }

        $this->injectSecurityTab($blueprint, $user);

        return $blueprint;
    }

    /**
     * Inject the page Security tab into a resolved page blueprint.
     *
     * Page-type blueprints (default.yaml etc.) don't carry the Security tab —
     * in admin-classic it's the Flex pages wrapper (blueprints://flex/pages.yaml)
     * that adds it via `import@: { type: partials/security }`. Admin-next loads
     * the plain page-type blueprint instead, so the tab goes missing. We
     * replicate the Flex wrapper here: load the same security partial and embed
     * it as a tab, positioned right after `advanced` to match classic ordering.
     *
     * The partial only sets frontmatter (header.access, header.permissions.*)
     * that grav-core already understands — nothing else changes.
     *
     * The partial's `_admin` (Page Permissions) section carries a
     * `security@: {or: [admin.super, admin.configuration.pages]}` gate. Core
     * evaluates that against `$grav['user']`, but during an API request that's
     * the guest user — so the gate fails for everyone and stamps the section
     * with `validate: ignore`. We evaluate the gate ourselves against the real
     * authenticated API user, accepting the API authority equivalents
     * (api.super / api.config): authorized users get the section clean and
     * editable, everyone else only sees the ungated Page Access section.
     */
    private function injectSecurityTab(Blueprint $blueprint, ?UserInterface $user = null): void
    {
        // Only page blueprints that wrap their fields in a `tabs` container can
        // host the Security tab. Skip anything with a different layout.
        $tabs = $blueprint->get('form/fields/tabs');
        if (!is_array($tabs) || ($tabs['type'] ?? null) !== 'tabs') {
            return;
        }

        // Respect a template/plugin that already defines its own Security tab.
        if ($blueprint->get('form/fields/tabs/fields/security') !== null) {
            return;
        }

        try {
            $security = new Blueprint('partials/security');
            $security->setContext('blueprints://pages');
            $security->load()->init();
        } catch (Throwable) {
            return;
        }

        $securityFields = $security->fields();
        if (empty($securityFields)) {
            return;
        }

        // Gate the Page Permissions section on API authority. `_site` (Page
        // Access) is ungated and always shown.
        $canManagePermissions = $user !== null
            && ($this->isSuperAdmin($user) || $this->hasPermission($user, 'api.config'));

        if (isset($securityFields['_admin'])) {
            if ($canManagePermissions) {
                // Clear the guest-induced `validate: ignore` so the section is
                // fully editable (baseline has no ignore flags of its own).
                $this->clearValidateIgnore($securityFields['_admin']);
            } else {
                unset($securityFields['_admin']);
            }
        }

        if (empty($securityFields)) {
            return;
        }

        // Turn the two `acl_picker` fields (Page Access, Page Groups) into the
        // dedicated admin-next web components with their dropdown options baked
        // in server-side. See decorateAclPickerFields() for why.
        $this->decorateAclPickerFields($securityFields);

        $securityTab = [
            'type' => 'tab',
            'title' => 'PLUGIN_ADMIN.SECURITY',
            'fields' => $securityFields,
        ];

        // Insert after the core `advanced` tab so the order matches classic
        // (Content, Options, Advanced, Security, …plugin tabs). Fall back to
        // appending if no `advanced` tab is present.
        $rebuilt = [];
        $inserted = false;
        foreach ((array) ($tabs['fields'] ?? []) as $key => $value) {
            $rebuilt[$key] = $value;
            if ($key === 'advanced') {
                $rebuilt['security'] = $securityTab;
                $inserted = true;
            }
        }
        if (!$inserted) {
            $rebuilt['security'] = $securityTab;
        }

        $blueprint->set('form/fields/tabs/fields', $rebuilt);
    }

    /**
     * Recursively remove the `validate: ignore` flag that core's blueprint
     * init stamps on a `security@`-gated field (and its children) when the
     * gate fails. Leaves the rest of each `validate` block intact.
     */
    private function clearValidateIgnore(array &$field): void
    {
        if (isset($field['validate']) && is_array($field['validate'])) {
            unset($field['validate']['ignore']);
            if ($field['validate'] === []) {
                unset($field['validate']);
            }
        }

        if (isset($field['fields']) && is_array($field['fields'])) {
            foreach ($field['fields'] as &$child) {
                if (is_array($child)) {
                    $this->clearValidateIgnore($child);
                }
            }
            unset($child);
        }
    }

    /**
     * Replace the page security `acl_picker` fields with their admin-next web
     * components and bake their dropdown options in server-side.
     *
     * admin-next's native FieldRenderer claims `acl_picker` before the custom
     * field registry, and `data_type` (access vs permissions) isn't part of
     * the serialized field props — so a stock `acl_picker` can't render the
     * classic row picker. We remap each field to a distinct custom type that
     * falls through to the plugin web component:
     *   - data_type: access      → `acl-access`      (Allowed/Denied per action)
     *   - data_type: permissions → `acl-permissions` (CRUD per group)
     *
     * The option lists (access actions / user groups) need `$grav['permissions']`
     * and the groups directory, and the access-actions endpoint is gated on
     * `api.users.read` which a page editor may not hold — so we resolve them
     * here and attach as `options`, sparing the component an extra (possibly
     * forbidden) round-trip.
     */
    private function decorateAclPickerFields(array &$fields): void
    {
        foreach ($fields as $key => &$field) {
            if (!is_array($field)) {
                continue;
            }

            $type = $field['type'] ?? null;

            if ($type === 'acl_picker') {
                $dataType = $field['data_type'] ?? null;
                if ($dataType === 'access') {
                    $field['type'] = 'acl-access';
                    $field['options'] = $this->buildAccessActionOptions();
                } elseif ($dataType === 'permissions') {
                    $field['type'] = 'acl-permissions';
                    $field['options'] = $this->buildGroupOptions();
                }
                unset($field['data_type']);
            }

            if (isset($field['fields']) && is_array($field['fields'])) {
                $this->decorateAclPickerFields($field['fields']);
            }
        }
        unset($field);
    }

    /**
     * Resolve the option list for a `users` field — every account that meets
     * the field's access/group requirements. Config props on the field:
     *
     *   access: api.pages.write          # min permission (string or list, any-of)
     *   groups: [editors, authors]       # group membership (string or list, any-of)
     *
     * With neither set, every account is listed. Super admins (API or classic)
     * always qualify. The value stored is the username, so existing plain
     * username-array fields round-trip unchanged.
     *
     * @return array<string, string> username => label, insertion order preserved
     */
    private function resolveUserFieldOptions(array $field): array
    {
        $accessList = $this->toStringList($field['access'] ?? null);
        $groupList = $this->toStringList($field['groups'] ?? null);

        $options = [];
        try {
            $accounts = $this->grav['accounts'] ?? null;
            if (!$accounts) {
                return $options;
            }
            foreach ($this->getAccountUsernames() as $username) {
                $account = $accounts->load($username);
                if (!$account || !$account->exists()) {
                    continue;
                }
                if (!$this->userMeetsRequirements($account, $accessList, $groupList)) {
                    continue;
                }
                $fullname = (string) ($account->get('fullname') ?? '');
                $options[(string) $username] = $fullname !== ''
                    ? sprintf('%s (%s)', $fullname, $username)
                    : (string) $username;
            }
        } catch (Throwable) {
            // Fall through with whatever was collected.
        }

        return $options;
    }

    /**
     * Whether an account satisfies a `users` field's access/group filter.
     * Empty filter → everyone qualifies; super admins always qualify.
     *
     * @param list<string> $accessList
     * @param list<string> $groupList
     */
    private function userMeetsRequirements(object $account, array $accessList, array $groupList): bool
    {
        if (!$accessList && !$groupList) {
            return true;
        }
        if ($this->isSuperAdmin($account) || (bool) $account->get('access.admin.super')) {
            return true;
        }
        foreach ($accessList as $permission) {
            if ($this->hasPermission($account, $permission)) {
                return true;
            }
        }
        if ($groupList) {
            $userGroups = (array) $account->get('groups', []);
            foreach ($groupList as $group) {
                if (in_array($group, $userGroups, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Normalize a scalar-or-list blueprint config value into a list of
     * non-empty strings.
     *
     * @return list<string>
     */
    private function toStringList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn ($v) => (string) $v, (array) $value),
            static fn (string $s) => $s !== '',
        ));
    }

    /**
     * Enumerate user-account usernames from the accounts storage directory.
     * Mirrors UsersController's listing without depending on its private API.
     *
     * @return list<string>
     */
    private function getAccountUsernames(): array
    {
        $locator = $this->grav['locator'];
        $dir = $locator->findResource('account://', true) ?: $locator->findResource('user://accounts', true);
        if (!$dir || !is_dir($dir)) {
            return [];
        }

        $usernames = [];
        foreach (new \DirectoryIterator($dir) as $file) {
            if ($file->isDot() || !$file->isFile() || $file->getExtension() !== 'yaml') {
                continue;
            }
            $usernames[] = $file->getBasename('.yaml');
        }
        sort($usernames);

        return $usernames;
    }

    /**
     * Build the Page Access dropdown options from the registered ACL actions,
     * e.g. `admin.login` → "Login to Admin (admin.login)". Mirrors the
     * `data_type: access` option list in admin-classic's acl_picker.
     *
     * @return array<string, string> value => label, insertion order preserved
     */
    private function buildAccessActionOptions(): array
    {
        $options = [];
        try {
            $permissions = $this->grav['permissions'] ?? null;
            if ($permissions && method_exists($permissions, 'getInstances')) {
                foreach ($permissions->getInstances() as $action) {
                    $name = $action->name ?? null;
                    if (!$name || ($action->visible ?? true) === false) {
                        continue;
                    }
                    // Short label only — the picker shows the dotted action
                    // name (the option value) as secondary text and derives the
                    // tree nesting from it.
                    $options[(string) $name] = $this->translateLabel($action->label ?? $name);
                }
            }
        } catch (Throwable) {
            // Fall through with whatever was collected.
        }

        return $options;
    }

    /**
     * Build the Page Groups dropdown options: every user group plus the two
     * special ACL targets that grav-core understands for pages. Mirrors the
     * `data_type: permissions` option list in admin-classic's acl_picker.
     *
     * @return array<string, string> value => label, insertion order preserved
     */
    private function buildGroupOptions(): array
    {
        $options = [];

        try {
            $flex = $this->grav['flex'] ?? $this->grav['flex_objects'] ?? null;
            $directory = $flex && method_exists($flex, 'getDirectory') ? $flex->getDirectory('user-groups') : null;
            if ($directory) {
                foreach ($directory->getCollection() as $key => $group) {
                    $name = (is_object($group) && method_exists($group, 'get') ? $group->get('groupname') : null) ?: (string) $key;
                    $label = (is_object($group) && method_exists($group, 'get') ? $group->get('readableName') : null) ?: $name;
                    $options[(string) $name] = (string) $label;
                }
            }
        } catch (Throwable) {
            // Fall through to config-based enumeration.
        }

        if (!$options) {
            foreach ((array) $this->grav['config']->get('groups', []) as $name => $group) {
                $label = is_array($group) ? ($group['readableName'] ?? $name) : $name;
                $options[(string) $name] = (string) $label;
            }
        }

        // Special ACL targets understood by grav-core for page permissions.
        $options['authors'] = $this->translateLabel('PLUGIN_ADMIN.PAGE_AUTHORS') . ' (Special)';
        $options['defaults'] = 'Default ACL (Special)';

        return $options;
    }

    /**
     * Ensure the Pages subsystem is initialized.
     * Many data-options@ directives reference Pages:: methods that need this.
     */
    protected function ensurePagesEnabled(): void
    {
        if ($this->pagesEnabled) {
            return;
        }
        $pages = $this->grav['pages'];
        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        }
        $this->pagesEnabled = true;
    }

    protected bool $pagesEnabled = false;

    /**
     * Resolve a data-*@ directive by calling the referenced PHP callable.
     * Supports format: '\Grav\Common\Utils::timezones' or ['method', 'args']
     */
    protected function resolveDataDirective(mixed $directive): ?array
    {
        try {
            $callable = is_array($directive) ? ($directive[0] ?? null) : $directive;
            if (!is_string($callable)) {
                return null;
            }

            $callable = ltrim($callable, '\\');

            // Parse Class::method format
            if (str_contains($callable, '::')) {
                [$class, $method] = explode('::', $callable, 2);
                $class = '\\' . $class;

                // Ensure Pages subsystem is available for Page-related callables
                if (str_contains($class, 'Page')) {
                    $this->ensurePagesEnabled();
                }

                if (class_exists($class) && method_exists($class, $method)) {
                    // pageTypes() needs a type arg. Use the current serialization
                    // context (modular if we're serializing a `modular/*` blueprint,
                    // standard otherwise) so the template selector gets the right
                    // list baked in.
                    if ($method === 'pageTypes') {
                        $result = $class::$method($this->pageTypeContext);
                    } else {
                        $result = $class::$method();
                    }
                    return is_array($result) ? $result : null;
                }
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Serialize a Blueprint object into a JSON-friendly structure.
     */
    /**
     * Page-type context for the current serialization pass. Read by
     * resolveDataDirective() when expanding `Pages::pageTypes` so a modular
     * template's blueprint gets the modular template list instead of the
     * default 'standard' list.
     */
    private string $pageTypeContext = 'standard';

    protected function serializeBlueprint(Blueprint $blueprint, string $name): array
    {
        $form = $blueprint->form();
        $fields = $blueprint->fields();

        // Modular page templates live under `modular/` (e.g. `modular/hero`).
        // Track this so Pages::pageTypes resolves to the modular list for the
        // template field instead of the standard list.
        $this->pageTypeContext = str_starts_with($name, 'modular/') ? 'modular' : 'standard';

        return [
            'name' => $name,
            'title' => $form['title'] ?? $blueprint->get('name') ?? $name,
            'type' => $blueprint->get('type') ?? null,
            'child_type' => $blueprint->get('child_type') ?? null,
            'validation' => $form['validation'] ?? 'loose',
            'fields' => $this->serializeFields($fields),
        ];
    }

    /**
     * Recursively serialize blueprint fields into a structure
     * suitable for client-side form rendering.
     */
    protected function serializeFields(array $fields, string $prefix = '', string $parent = ''): array
    {
        $result = [];

        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }

            $type = $field['type'] ?? null;

            // Leading-dot relative naming. A child keyed `.optionA` binds under
            // its container's own name rather than the (transparent) layout
            // prefix, so `.optionA` inside a section named `header.sectionName`
            // resolves to `header.sectionName.optionA` and saves nested. This
            // mirrors core's BlueprintSchema::getFieldKey(); without it the bare
            // `.optionA` reached the SPA and its values never saved.
            if (is_string($name) && isset($name[0]) && $name[0] === '.') {
                $base = $parent !== '' ? $parent : rtrim($prefix, '.');
                $fieldPath = $base !== '' ? $base . $name : substr($name, 1);
            } else {
                $fieldPath = $prefix !== '' ? "{$prefix}.{$name}" : (string) $name;
            }

            // `users` field type: a reusable, permission-filtered user picker.
            // Resolve its dropdown options from the field's own `access:` /
            // `groups:` config so any blueprint can drop one in without extra
            // server code. Stuffing the options back onto $field lets the
            // normal options pipeline (translate + assoc→array) handle them.
            if ($type === 'users') {
                $field['options'] = $this->resolveUserFieldOptions($field);
            }

            $serialized = [
                'name' => $fieldPath,
                'type' => $type ?? 'text',
            ];

            // Copy standard properties
            $props = [
                'label', 'help', 'placeholder', 'default', 'description', 'content',
                'size', 'classes', 'id', 'style', 'title', 'text',
                'disabled', 'readonly', 'toggleable', 'highlight',
                'minlength', 'maxlength', 'min', 'max', 'step',
                'rows', 'cols', 'multiple', 'yaml',
                'markdown', 'prepend', 'append', 'underline',
                'options', 'selectize', 'value_only', 'create',
                'destination', 'accept', 'random_name', 'avoid_overwriting', 'filesize', 'limit',
                'use', 'key', 'controls', 'collapsed',
                'show_all', 'show_modular', 'show_root', 'show_slug',
                'placeholder_key', 'placeholder_value', 'value_type',
                'btnLabel', 'placement', 'sortby', 'sortby_dir',
                'sort', 'collapsible', 'min_height', 'selectunique',
                'condition', 'wrapper_classes',
                'provider', 'translate',
                'page_field', 'page_template', 'success_msg', 'error_msg',
                // pagemediaselect / filepicker
                'preview_images', 'preview_image', 'on_demand', 'folder', 'filter',
                'self', 'display', 'resize', 'media_picker_field',
                // colorpicker — opt out of the alpha slider with `alpha: false`.
                'alpha',
            ];

            foreach ($props as $prop) {
                if (isset($field[$prop])) {
                    $serialized[$prop] = $field[$prop];
                }
            }

            // Translate string properties that may contain language keys.
            // `append`/`prepend` carry unit labels like GRAV.NICETIME.DAY_PLURAL;
            // without translating them here the SPA receives the raw key and
            // humanizes it to "Day Plural" (admin2#64) — the GRAV.* core
            // namespace isn't in the SPA's client string table.
            foreach (['label', 'title', 'description', 'help', 'placeholder', 'text', 'content', 'success_msg', 'error_msg', 'append', 'prepend'] as $textProp) {
                if (isset($serialized[$textProp]) && is_string($serialized[$textProp])) {
                    $serialized[$textProp] = $this->translateLabel($serialized[$textProp]);
                }
            }

            // `display` field with a `file:` reference — load the file contents
            // into `content` so the SPA can render it. Mirrors the classic form
            // template's `read_file(field.file)`. The path is blueprint-authored
            // (trusted), so we resolve it through Grav's stream locator just like
            // core does. Done after translation so markdown bodies aren't run
            // through the language lookup.
            if ($type === 'display' && !empty($field['file']) && empty($serialized['content'])) {
                $fileContent = $this->readDisplayFile((string) $field['file']);
                if ($fileContent !== null) {
                    $serialized['content'] = $fileContent;
                }
            }

            // Translate option labels
            if (isset($serialized['options']) && is_array($serialized['options'])) {
                foreach ($serialized['options'] as $optKey => $optLabel) {
                    if (is_string($optLabel)) {
                        $serialized['options'][$optKey] = $this->translateLabel($optLabel);
                    }
                }
            }

            // Resolve data-options@ directives (dynamic options from PHP callables).
            // Grav core's Blueprint::dynamicData() may have already populated
            // $serialized['options'] using a stateless call; we replace it with
            // our resolution because we have page-type context for pageTypes.
            if (isset($field['data-options@'])) {
                $directive = $field['data-options@'];
                $resolved = $this->resolveDataDirective($directive);
                if ($resolved !== null && count($resolved) > 0) {
                    $serialized['options'] = $resolved;
                } else {
                    // Include the directive reference so client can resolve via /data/resolve
                    $serialized['data_options'] = is_string($directive) ? $directive : ($directive[0] ?? null);
                }
            }

            // Convert options from {key: label} object to [{value, label}] array
            // to preserve insertion order (JS re-sorts numeric object keys)
            if (isset($serialized['options']) && is_array($serialized['options'])) {
                $ordered = [];
                foreach ($serialized['options'] as $optKey => $optLabel) {
                    $ordered[] = [
                        'value' => $this->normalizeOptionScalar($optKey),
                        'label' => $this->normalizeOptionScalar($optLabel),
                    ];
                }
                $serialized['options'] = $ordered;
            }

            // Validation rules
            if (isset($field['validate']) && is_array($field['validate'])) {
                $serialized['validate'] = $field['validate'];
            }

            // Handle nested fields (structural containers)
            if (isset($field['fields']) && is_array($field['fields'])) {
                // For layout containers, don't add prefix (fields bind to their own names)
                $layoutTypes = ['tabs', 'tab', 'section', 'fieldset', 'columns', 'column', 'page-exists', 'elements', 'element'];
                $childPrefix = in_array($type, $layoutTypes, true) ? $prefix : $fieldPath;

                // Always pass this field's resolved name as the parent so any
                // leading-dot children bind under it, even when the container is
                // a transparent layout type that leaves $childPrefix untouched.
                $serialized['fields'] = $this->serializeFields($field['fields'], $childPrefix, $fieldPath);
            }

            $result[] = $serialized;
        }

        return $result;
    }

    /**
     * Resolve a `display` field's `file:` reference to its raw contents.
     *
     * Accepts a Grav stream (e.g. `plugins://login-oauth2/README.md`) or a path
     * already inside the Grav root. Anything that resolves outside the root, or
     * that doesn't exist, returns null so the field simply renders nothing —
     * matching the classic template's silent behaviour.
     */
    protected function readDisplayFile(string $file): ?string
    {
        $locator = $this->grav['locator'];

        // Stream URI (scheme://...) — let the locator resolve it.
        if (strpos($file, '://') !== false) {
            $path = $locator->findResource($file, true);
        } else {
            // Bare path — anchor to the Grav root and confirm it stays inside.
            $root = rtrim(GRAV_ROOT, '/\\');
            $candidate = realpath($root . '/' . ltrim($file, '/\\'));
            $path = ($candidate && strpos($candidate, $root) === 0) ? $candidate : false;
        }

        if (!$path || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }

    /**
     * Stringify an option key or label for the client.
     *
     * With strict YAML (system.strict_mode.yaml_compat: false) Grav parses
     * blueprints with the native YAML 1.1 parser, which reads unquoted
     * Yes/No/On/Off/y/n option labels as booleans. Left as booleans they
     * render as a blank button or a literal "true"; mapping them back to
     * Yes/No keeps these (Grav 1.7-era) blueprints working without asking
     * authors — or end users — to quote every label. Option keys are never
     * booleans (PHP casts bool array keys to 1/0), so they just stringify.
     */
    private function normalizeOptionScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return (string) $value;
    }
}
