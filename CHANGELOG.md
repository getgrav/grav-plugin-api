# v1.0.0-rc.8
## 05/17/2026

1. [](#new)
    * **New `GET /admin/languages` endpoint.** Enumerates `user/plugins/admin2/languages/*.yaml` and returns each available admin UI locale with its native name and RTL flag. Distinct from `GET /languages` (which lists *site content* languages from `system.yaml`) — the admin UI language and site content language are different concepts and shouldn't be conflated.
    * **`POST /pages` now accepts a `kind` parameter.** Mirrors classic admin's three-way add-page split: `page` (default — folder + `<template>.md`, unchanged behaviour), `folder` (creates the folder only, no `.md` file), or `module` (a modular sub-page — the slug is automatically prefixed with `_` per Grav's modular-folder convention). Validation rejects any other value.
    * **`GET /blueprints/pages` now accepts `?modular=true`.** Returns `Pages::modularTypes()` instead of `Pages::types()` so the admin can populate a Module-specific template picker (the four `modular/*` templates a theme provides, rather than every standard template).
    * **`GET /translations/{lang}` now returns `dir: 'rtl'|'ltr'`.** Lets admin2 set `<html dir>` and `window.__GRAV_I18N.dir` from the same payload it's already fetching, avoiding a second roundtrip on every locale switch.
1. [](#bugfix)
    * **`GET /translations/{lang}` no longer silently falls back to the default language when the requested locale isn't a *site content* language.** The validation was gating on `$language->getLanguages()` (the list configured under `system.languages.supported`), so a request for Hebrew on an English-only site quietly returned English strings. Admin UI translations and site content languages are independent — the endpoint now validates only the shape of the lang code and lets `Languages::flattenByLang()` decide whether the strings exist.
    * **Blueprint labels now translate against the user's admin language, not the site default.** Every `/blueprints/*` endpoint (pages, plugins, themes, users, permissions, config, plugin pages, page types) used to call `$lang->translate()` with no language hint, so labels resolved against Grav's globally-active content language. The same admin-next user could see their topbar nav in Hebrew (those come from `/translations/he` which knows the language) and the System config page still in English (those come pre-resolved here). `BlueprintController` now reads each request's authenticated user, resolves their effective `adminLanguage` via `PreferencesResolver`, and passes `[$adminLanguage, 'en']` as the language fallback chain on every translate call. Falls back cleanly to English when no user is attached.
    * **Modular templates now save with the correct on-disk filename.** `POST /pages` with template `modular/hero` was writing `<folder>/modular/hero.md` (creating a nested `modular/` subdirectory inside the new page folder); it now correctly writes `<folder>/hero.md`. The `modular/` prefix in the template key is purely for template resolution, never part of the filename.

# v1.0.0-rc.7
## 05/14/2026

1. [](#new)
    * **New `/admin-next/preferences` endpoint family for admin2 UI settings.** `GET /admin-next/preferences` returns a single resolved payload of branding, site defaults, the caller's overrides, and the merged effective values. `PATCH /admin-next/preferences/user` saves the current user's overrides (debounced from the SPA); `DELETE` clears them. `PATCH /admin-next/preferences/site` writes site-wide defaults (super-admin only) and routes Tier B keys (overridable per-user) to `user/config/admin-next.yaml > ui.defaults` and Tier A2 keys (site-only behavioral — auto-save, real-time collab, menubar links) to `ui.settings`. `PATCH /admin-next/branding` and `POST/DELETE /admin-next/branding/logo` handle the site logo (mode/text plus light/dark image uploads stored under `user://media/admin-next/`). The new `PreferencesResolver` service in `classes/Api/Services/` mirrors the dashboard-layout pattern: built-in defaults overlaid with site values overlaid with per-user overrides, all schema-validated on write.

# v1.0.0-rc.6
## 05/13/2026

1. [](#new)
    * **Plugin log files now show up in the admin Logs viewer.** A new `onApiLogFiles` event lets plugins register their own log file alongside the core `grav.log` / `email.log` / `scheduler.log` set, and `GET /system/logs` accepts a `?file=` query to pick which one to read. A companion `GET /system/logs/files` endpoint returns the registered list so the admin can populate a selector. File names are whitelisted by the registered set to prevent path traversal.
    * **`POST /pages` accepts `order: "auto"` for sibling-aware numeric prefixing.** Mirrors admin-classic's add-page behavior: when the parent already has numerically prefixed children the new page is created with the next free number; when no sibling uses a prefix the new page is created without one. Existing integer and `null` semantics are unchanged.
1. [](#improved)
    * **Blueprint serializer now passes through the `create` field property.** Lets array fields opt into admin2's constrained-dropdown rendering by setting `create: false` in their blueprint.
    * **Self-service paths on `/users` no longer require `api.users.read`.** A caller can fetch their own row (`GET /users/{me}`) and the user-form schema (`GET /blueprints/users`) with just `api.access` — symmetric with what `PATCH /users/{me}` already permitted. The blueprint endpoint is just the form definition with no per-user data leak; the show endpoint still requires `api.users.read` for anyone else's account.
    * **`GET /users` auto-filters to self for restricted callers.** Without `api.users.read` the listing endpoint used to 403 outright; it now returns a single-row paginated envelope containing only the caller's own user. Admins (super or `api.users.read`) still get the full listing as before.
    * **Admin login now flows through the standard `Login::login()` event chain.** The previous direct `User::authenticate()` call skipped `onUserLoginAuthenticate`, which meant the LDAP plugin (and any other auth-extending plugin that hooks that event) couldn't participate in admin2 logins. Auth requests now route through `Login::login()` with `'admin' => true` and `'authorize' => 'admin.login'`, with a clean fallback to direct authentication when the Login plugin isn't installed ([grav-plugin-admin2#9](https://github.com/getgrav/grav-plugin-admin2/issues/9)).
    * **Popularity visitor IPs now hashed with HMAC-SHA256 plus a server-private salt.** The legacy `sha1($ip)` hash was reversible via precomputed table over the IPv4 space, which under GDPR Recital 26 / Art. 4(1) kept the stored value classified as personal data. Hashing now uses `hash_hmac('sha256', $ip, $salt)` with an auto-generated 32-byte salt stored in `user/config/plugins/api.yaml` (never shipped with a default), treated like the JWT secret and redacted from config-API responses. Legacy hashes already on disk age out via the existing visitor-history cap, so no migration is needed ([grav-plugin-admin2#12](https://github.com/getgrav/grav-plugin-admin2/issues/12)).
1. [](#bugfix)
    * **API plugin now activates and dispatches correctly on Grav installs mounted at a subpath.** Both the activation check in `setup()` and the path-stripping in `ApiRouter::dispatch()` were comparing against the raw request path, which on a subpath install starts with the base — so `/<base>/api/...` requests fell through to a page-not-found 404. Both code paths now strip the base before testing the api prefix.
    * **Page Template selector now shows the right list for modular children.** Serializing a `modular/*` page blueprint was hardcoded to call `Pages::pageTypes('standard')`, so a modular child's template dropdown listed every standard template instead of the four `modular/*` ones the theme provides. Serializer now picks `'modular'` or `'standard'` based on the blueprint name. The merge of resolved options with whatever Grav core's `dynamicData()` may have already filled in was also changed to a replace, so themes can't end up with the standard and modular lists concatenated.

# v1.0.0-rc.5
## 05/08/2026

1. [](#improved)
    * **Blueprint label resolver now prefers `ICU.<key>` over the flat `<key>`.** Admin2 ships its canonical `PLUGIN_ADMIN.*` vocabulary under the ICU namespace; checking ICU first guarantees admin2 wins for every key it ships, even when admin classic (or any plugin still using the Grav 1 flat convention) is also present. The flat lookup remains as a transition fallback for keys admin2 doesn't ship — but only for keys contributed by *enabled* plugins (see below).
    * **Disabled plugins no longer influence translations.** Grav core's `flattenByLang()` reads every plugin's lang yaml regardless of enabled state, so a disabled plugin (most painfully, admin classic mid-migration on a Grav 2 site) used to leak its strings into both the `/translations/{lang}` payload served to admin2 and the server-side blueprint label resolver. Both code paths now consult a new `DisabledPluginLangIndex` service that returns the keys contributed exclusively by disabled plugins; those keys are stripped from the response and skipped in `translateLabel()`. Keys also shipped by an enabled plugin stay — the enabled plugin owns them.
    * **`/translations/{lang}` shadow-strips flat duplicates.** When the same key exists under both `<key>` and `ICU.<key>`, only the ICU side is sent to admin2. Admin2's client-side `t()` already preferred ICU, but stripping flat duplicates at the source shrinks the payload and removes ambiguity for any caller that might bypass the ICU-first lookup.

# v1.0.0-rc.4
## 05/06/2026

1. [](#improved)
    * **`POST /gpm/upgrade` now emits a `grav:update` invalidation header** alongside `gpm:update`, so admin clients can refresh cached version info (e.g. the sidebar `Grav v…` label) after a Grav core self-upgrade without waiting for a full page reload.

# v1.0.0-rc.3
## 05/05/2026

1. [](#bugfix)
    * **Config saves now land where Grav loads them.** When an environment overlay was active (e.g. a hostname-derived `user/<host>/config/`), `PATCH /config/{scope}` always wrote to base `user/config/` regardless — so any field already pinned in the env file silently shadowed the write and the change appeared to "succeed but not stick" (classic case: enabling a plugin pinned `enabled: false` in `user/localhost/config/plugins/`). Writes now follow Grav's active environment by default; the `X-Config-Environment` header still wins when set, and an explicitly-empty header opts back into a base write.

# v1.0.0-rc.2
## 05/05/2026

1. [](#bugfix)
    * **Module-page blueprints (`modular/hero`, `modular/feature`, etc.) now resolve.** `GET /blueprints/pages/{template}` was registered with FastRoute's default placeholder, which doesn't allow slashes — so the embedded `/` in module-page templates 404'd before the controller ran ([grav-plugin-admin2#1](https://github.com/getgrav/grav-plugin-admin2/issues/1)). Route placeholder widened to accept slashes; downstream resolver already handled slashed templates.
    * **Custom theme page blueprints (replace@, @extends.context, import@, ordering@) now render correctly.** The hand-rolled YAML resolver in `BlueprintController::loadPageBlueprint()` silently dropped `replace@` / `unset@` / `replace-<prop>@` directives, ignored `@extends.context`, and merged `import@` as a map instead of inline-inserting fields — so themes that override fields, switch the parent context, or import partials saw most of their customizations vanish. Resolver now delegates to Grav core's standard `Pages::blueprints()` pipeline (`Blueprint::load()->init()`), the same path admin-classic uses, which honors every BlueprintForm directive ([grav-plugin-admin2#3](https://github.com/getgrav/grav-plugin-admin2/issues/3)).
    * **`pagemediaselect` / `filepicker` field properties now round-trip.** Blueprint serializer's field-property whitelist was missing `preview_images`, `preview_image`, `on_demand`, `folder`, `filter`, `self`, `display`, `resize`, and `media_picker_field`, so themes that configured those props on a media picker saw them silently stripped from the API response.
    * **Folders prefixed `00.` now sort to the top of the page tree.** Default-sort branch in `PagesController::indexViaDefaultSort()` bucketed pages by `if ($page->order())`, but Flex's `Page::order()` returns `(int) 0` for `00.` — so `00.sections` landed in the "unordered" bucket and sorted alphabetically after every numbered sibling instead of first ([grav-plugin-admin2#5](https://github.com/getgrav/grav-plugin-admin2/issues/5)). Bucket check changed to `!== false` (the actual sentinel for unordered folders).

# v1.0.0-rc.1
## 05/04/2026

1. [](#new)
    * Fire new `onApiBlueprintResolved()` event
    * Add support for configurable ordering prefixes
1. [](#improved)
    * Page creation and reorder now match the digit width of the parent's existing children — adding into a 3-digit collection stays 3-digit, and reorder no longer renormalizes existing 3- or 4-digit prefixes back to two ([grav-plugin-admin#2492](https://github.com/getgrav/grav-plugin-admin/issues/2492)). New collections fall back to the new `system.pages.order_digits` setting.

# v1.0.0-beta.17
## 04/28/2026

1. [](#bugfix)
    * **Security: privilege escalation via blueprint-upload (GHSA-6xx2-m8wv-756h).** A user with only basic media-upload permission could plant an account file and instantly become a super-admin. The endpoint now restricts where files can land, who can target another user's avatar, and which file types are allowed.
    * **Security: hardened destination handling on `/blueprint-upload`.** Added pre-locator input validation that rejects path-traversal characters in the `destination` string before it ever reaches Grav's stream resolver.
    * **Security: path-traversal hardening in GPM endpoints.** `GET /gpm/{plugins,themes}/{slug}/...` (readme, changelog, blueprints, fields, etc.) now validates the `slug` parameter so a `..` slug can no longer reach files outside the package directory.

# v1.0.0-beta.16
## 04/28/2026

1. [](#bugfix)
    * **Fix: `POST /gpm/update-all` now updates packages instead of skipping all of them.** A regression introduced by beta.14's dep-resolution rewrite tripped a Grav core static-cache quirk and mis-labelled every outdated package as "already up to date (installed as a dependency)". The per-package `POST /gpm/update` endpoint was unaffected and remained a working workaround.

# v1.0.0-beta.15
## 04/27/2026

1. [](#new)
    * **`onApiBlueprintResolved` now fires for the user-account blueprint.** `GET /blueprints/users` previously returned the serialized `account.yaml` straight to the wire — plugins could extend page / plugin / theme blueprints via the event but had no way to add fields to the user form. The handler now fires `onApiBlueprintResolved` with `template: 'account'` and the requesting user, so admin2 can inject the state-toggle field (or other plugins can add their own fields) on a permission-aware basis.
    * **`onApiBlueprintResolved` now carries an explicit `context` discriminator, and theme blueprints now fire the event.** Every firing tags itself with `context: 'page' | 'plugin' | 'theme' | 'account'` alongside the existing `template` / `plugin` / `theme` keys. Lets listeners gate behavior to a specific blueprint family without inferring from which sibling key happens to be set — e.g. ai-translate auto-annotates only `context === 'page'` and lets plugins hook a separate `onAiTranslateAnnotateFields` event for opt-in coverage of other contexts. `themeBlueprint()` previously returned the serialized YAML straight to the wire and skipped the event entirely; it now fires `context: 'theme'` symmetrically with the others, so theme-targeted blueprint extensions are finally possible.
2. [](#bugfix)
    * **Dashboard layout no longer disables the user account.** `DashboardLayoutResolver::saveUserLayout()` was writing the per-user widget layout to `state.admin_next.dashboard` in the user's account YAML, but Grav's top-level `state:` field is the account-state string (`enabled` / `disabled`). Replacing it with a map flipped affected accounts to "Disabled" in the users list and broke any code that read `state === 'enabled'`. Storage moved to the top-level `admin_next.dashboard` key (no collision), and a one-time read-side migration in `migrateLegacyState()` lifts any pre-existing legacy data out of `state.*` and restores `state: enabled` (or `disabled` if a legacy `state.enabled: false` flag was present). Migration runs on the next dashboard read for each affected user; the save path also calls it as a safety net so an admin reordering widgets self-heals their own record.
    * **Security: privilege escalation via self-edit (GHSA-r945-h4vm-h736).** `PATCH /users/{username}` allowed any authenticated user with `api.access` to send an `access` payload against their own profile and self-promote to super admin. The self-edit branch only required `api.access` (not `api.users.write`), but the field whitelist still included `access` (and `state`) for everyone — overwriting `access.api.super` / `access.admin.super` on yourself granted full system control and a Twig-template path to RCE. `UsersController::update` now splits the whitelist into self-editable fields (email, fullname, title, language, content_editor, twofa_enabled) and admin-only fields (state, access); a non-manager that sends `access` or `state` in the body now gets a `403 Forbidden` with an explicit "requires the 'api.users.write' permission" message instead of having the field silently land. Managers (super-admin or `api.users.write`) keep full control over both fields, including on their own account. New regression test `UsersControllerUpdatePrivescTest` pins the boundary across five cases: low-priv self-edit of `access` rejected (and access map verified untouched), low-priv self-edit of `state` rejected, low-priv self-edit of plain profile fields succeeds, an admin updates another user's `access` field, and a user holding `api.users.write` self-edits their own `access`. `Grav\Framework\Acl\Permissions` and `Grav\Common\Utils::arrayFlattenDotNotation` were added as minimal stubs in `tests/Stubs/GravStubs.php` so `PermissionResolver` can be exercised in unit tests without the Grav core on the classpath.

# v1.0.0-beta.14
## 04/25/2026

1. [](#bugfix)
    * `core.recent-pages` dashboard widget's registered `defaultSize` was `sm` instead of `md` — out of sync with the `Default` preset, which sets it to `md`. Fresh installs (no saved user layout, no site layout) fell through to the registered default and rendered Recent Pages at `sm` even though clicking the Default preset would correctly snap it back to `md`. Bumped the registered `defaultSize` to `md` so a new account's first dashboard render matches the canonical Default layout. The other seven core widgets were already aligned with the preset, audited as part of the fix.
    * `POST /gpm/update-all` now enforces the same dependency validation as the per-package `POST /gpm/update` path. The old bulk flow iterated `getUpdatable()` and called `GpmService::update()` directly per slug with no checks, so a "Update All" click would happily update plugins whose blueprint declared `grav` (or `php`) requirements the running install didn't satisfy — the dep-resolution intelligence already living in `GPM::checkPackagesCanBeInstalled()` + `GPM::getDependencies()` was being bypassed entirely. The bulk path now runs each package through `getDependencies()` up-front: a Grav-too-old or PHP-too-old failure surfaces as a `failed[]` entry with the original GPM message (color tags stripped) so the toast reads "needy: One of the packages require Grav >=2.0.0-beta.2. Please update Grav to the latest release." instead of silently mis-updating. Plugin-level deps that themselves need an update are processed *before* the package that needs them, mirroring admin-classic's resolution order — a plugin update that requires `shortcode-core >= 5.0.0` will pull `shortcode-core` forward first. The response shape gains `skipped[]` (packages that were originally listed updatable but became current via cascade earlier in the batch — re-checked against a fresh GPM read each iteration, no double-update) and `cascaded_dependencies[]` (slugs installed/updated as deps of others in the batch), so callers can render "also updated as deps: x, y". Admin-next's three "Update All" toasts (dashboard, plugins, themes) now expand failure reasons inline (`"<slug>: <reason>"`) instead of just listing slugs, so the underlying constraint is visible without opening the network panel.
    * 6 new unit tests in `GpmControllerUpdateAllTest` cover the matrix: Grav-dep mismatch lands in `failed[]` and never invokes `updatePackage`; cascade install runs in correct order and the cascaded dep lands in `skipped[]` (not `updated[]`) on its own iteration; a throwing dep install aborts the parent update with a partial-failure message; `theme: true` is passed only for theme packages and `install_deps: false` is always set; an `updatePackage()` returning non-success surfaces as a `failed[]` entry; an empty batch returns four empty buckets. To enable mocking, `GpmController::getGpm()` was promoted from `private` to `protected` and the static `GpmService::install/update` calls in `updateAll()` now route through new protected `installPackage()` / `updatePackage()` wrappers — overridable in a test subclass without touching network or filesystem. A minimal `Grav\Common\GPM\GPM` stub was added to `tests/Stubs/GravStubs.php` so `createMock(GPM::class)` works when running the suite outside a Grav installation.

# v1.0.0-beta.13
## 04/25/2026

1. [](#new)
    * **Customizable dashboard widgets** — three new endpoints back the new admin-next dashboard's per-user / per-site customization. `GET /dashboard/widgets` returns the resolved widget list (visibility, size, order) merged from a built-in core registry + plugin contributions via `onApiDashboardWidgets` + the site-default layout (super-admin) + the current user's overrides. `PATCH /dashboard/layout` saves the user's layout (visibility/size/order per widget); `PATCH /dashboard/site-layout` saves the site-wide default and is super-admin only. The merge is layered: site-hidden widgets are dropped entirely from the user's view (cannot be re-enabled per-user), the user's overrides win for size/order on the rest, and any widget not in either layout falls back to its registered `defaultSize` / priority. A new `DashboardLayoutResolver` service owns the resolution and persistence; resolved widgets carry their `sizes[]` / `defaultSize` / icon / authorize permission, so the client can render the customize-mode size picker without a second round-trip. Plugin widgets are contributed by listening to `onApiDashboardWidgets` and pushing entries into `$event['widgets']`; each widget can declare its own permission gate so the resolver hides widgets the user lacks access to. Allowed sizes are validated server-side against a per-widget allowlist (`xs`, `sm`, `md`, `lg`, `xl`) and silently coerced back to `defaultSize` if a stale layout asks for an unsupported size.
    * **Notifications v2** — `GET /dashboard/notifications` now fetches from `https://getgrav.org/notifications2.json` and stores its cache under `user/data/notifications/{md5}_v2.yaml` (separate file so v1 caches don't collide). The new schema replaces the v1 "embedded HTML in `message`" approach with structured fields: `type` (`info` | `notice` | `warning` | `promo`), `icon` (emoji or icon name), `title`, `message` (markdown), `link` (whole-row click), `image` + `accent` (for `promo` cards), `action: {label, url}`, and `dependencies` (e.g. `admin: '>= 2.0.0'`). Lets admin-next render every notification natively in its own design language instead of dumping admin-classic CSS into the page.
    * **Password policy endpoint** at `GET /auth/password-policy` (public, no auth). Parses the configured `system.pwd_regex` into a structured `{ regex, min_length, rules[] }` response by recognizing the common lookahead form (`(?=.*\d)`, `(?=.*[a-z])`, `(?=.*[A-Z])`, `(?=.*\W)`, `.{N,}`) and mapping each to a human-readable rule label. Admins can override the auto-detected rules with an optional `system.pwd_rules: [{id, label, pattern}, …]` list for custom or localized messaging without touching `pwd_regex`. The same structure is piggybacked on the `GET /auth/setup` response so the first-run setup screen can render its strength meter without a second round-trip. `POST /auth/setup` additionally validates the first-user password against `pwd_regex` server-side (previously only enforced `>= 8` chars) — keeps the server authoritative regardless of what the UI is showing.
    * **HTTP method override** fallback for shared-hosting nginx configs that 405 `DELETE` / `PATCH` / `PUT` at the edge before the request ever reaches PHP. A new `MethodOverrideMiddleware` runs right after CORS/body-parse and transparently rewrites any `POST` that carries an `X-HTTP-Method-Override: DELETE|PATCH|PUT` header to the target method before dispatch, so the FastRoute handlers downstream see the semantic verb they expect. Only the three mutation verbs are honored (never `GET`), and the override is opt-in per request — clients that don't need it pay zero cost. Admin-next complements this with client-side auto-detection: a failed mutation that 405s is retried once as `POST + override`, and the fallback is cached in `sessionStorage` so subsequent requests in the same session skip straight to the compatible path.
    * **Destination-aware blueprint file uploads** at `POST /blueprint-upload` and `DELETE /blueprint-upload`. Accepts a blueprint `destination` (Grav stream like `theme://images/logo`, `user://assets`, `account://avatars`; `self@:subpath` relative to a blueprint owner; or a plain user-rooted relative path) plus a `scope` (`plugins/<slug>`, `themes/<slug>`, `pages/<route>`, `users/<username>`) and writes the uploaded file to the right place, mirroring admin-classic's `taskFilesUpload` semantics. Streams resolve through Grav's locator so symlinked theme/plugin folders (common in dev setups) work cleanly — the response returns a *logical* user-rooted path (`user/themes/quark2/images/logo/foo.png`) independent of realpath, so a subsequent `DELETE` round-trips through the symlink to remove the actual file. Enforces the usual safety gates: `..` traversal and absolute paths are rejected, filenames are sanitized, and the dangerous-extension allowlist is checked. This gives admin-next parity with admin-classic's file-field uploads for theme/plugin config forms that previously only worked on page-media contexts.
2. [](#improved)
    * **Rate limiter `excluded_paths`** — new `plugins.api.rate_limit.excluded_paths` config (defaults to `['/sync/']`) skips the per-user bucket for matching path prefixes. Phase 6 of the sync plugin fires steady ~90 req/min per editing client (1s pull + ~0.5s presence + per-keystroke pushes); the default 120 req/min anti-abuse bucket would trip an actively-typing user within a minute and put sync into a 429 stop/start loop. The bypass is gated by normal auth (sync still requires `api.access` + `api.collab.*`), so it's not a free pass — just removes the global anti-scraping limit from authenticated collaboration traffic. Operators can extend the list to exempt other high-frequency authenticated endpoints (e.g. polling integrations from internal services).
3. [](#bugfix)
    * `PATCH /config/{scope}` (and every other ETag-guarded endpoint) no longer returns a spurious `409 Conflict` when the admin is served behind Apache `mod_deflate` or an nginx build that applies gzip/br compression. Both servers weaken the ETag on compressed responses by suffixing it (`<hash>-gzip`, `<hash>-br`, sometimes `;gzip`), and clients echo that suffixed value back in `If-Match` on the next PATCH. The PATCH response body is typically uncompressed, so `generateEtag()` produced the bare hash and the strict equality check failed on every first save. `validateEtag()` now strips known transport suffixes (`-gzip`, `;gzip`, `-br`, `-deflate`) and the weak-validator prefix (`W/`) from inbound `If-Match` headers before comparing, so the hash round-trips cleanly through a compressing proxy. Invisible on `php -S` / MAMP; only reproduces behind real reverse proxies with content compression enabled.
    * `GET /users` no longer emits phantom entries for stray files in `user/accounts/`. Grav's Flex `FileStorage::buildIndex()` indexes every file in the accounts folder regardless of extension, so snapshot/backup files from other plugins (e.g. revisions-pro's `.rev` snapshots) surfaced as indexable "user" objects. `UsersController::indexViaFlex` now constrains the collection to keys matching the username pattern `[a-z0-9_-]+` before search / sort / pagination, so stray files are filtered out before they ever reach the serializer.
    * `PATCH /config/{scope}` now uses Grav's blueprint-aware merge (`Blueprint::mergeData()`) instead of a blind `array_replace_recursive`. The old recursive merge deep-merged map values at every level, so when a client sent a file field as `{}` after the user removed the last file, the old path keys from `$existing` survived the merge and the YAML kept referencing the deleted file. Blueprint-aware merge respects field-type semantics — `type: file` (and any other "collection of items" field) is REPLACED wholesale from the incoming body, so removing map entries actually propagates. Falls back to `array_replace_recursive` when no blueprint is available (rare — mostly test fixtures).
    * `DELETE /blueprint-upload` is now idempotent: a missing file returns `204 No Content` instead of `404 Not Found`. The endpoint's contract is "this file should not be on disk" — already-gone and just-deleted are indistinguishable end states, and surfacing a 404 forced clients into special-case error suppression. Real misuses (path resolves to a directory or something non-file) still error.

# v1.0.0-beta.12
## 04/22/2026

1. [](#new)
    * **Environment management API** at `GET /system/environments` (now returning a richer shape: `detected` host, `environments[]` with `name`, `label`, `exists`, `hasOverrides`) and `POST /system/environments` to create a new `user/env/<name>/config/` folder. Writes to `config/plugins/*`, `config/themes/*`, and `config/{system,site,media,security,…}` now honor a new `X-Config-Environment` request header that targets an existing env folder — empty/missing defaults to base `user/config/`, and a non-empty value that doesn't match an existing folder returns a clear `400` instead of silently creating anything. Env folders are **never** created implicitly; clients must opt in via `POST /system/environments`. A shared `EnvironmentService` owns resolution across `user/env/*` and legacy Grav 1.6 `user/<host>/` layouts so both the listing endpoint and the write path see the same set of envs.
    * **Differential config saves.** Config writes now persist only the delta against the relevant parent yaml, matching the hand-edit workflow instead of forking full defaulted copies. Parent resolution:
    * `system / site / media / security / scheduler / backups` → `system/config/<scope>.yaml` (Grav core defaults)
    * `plugins/<name>` → `user/plugins/<name>/<name>.yaml` (the plugin's own shipped defaults)
    * `themes/<name>` → `user/themes/<name>/<name>.yaml` (the theme's own shipped defaults)
    * Env-targeted writes (`X-Config-Environment` set) additionally layer `user/config/<scope>.yaml` on top of defaults, so env files only carry keys that differ from the effective base — move a key from base to env by setting it to a different value; leave it alone to inherit.
    * Defaults come from the raw yaml files on disk, **not** from blueprints — blueprints describe the admin form and routinely diverge from what actually loads at runtime. Sequential arrays (`languages.supported`, `pages.types`, etc.) are treated atomically: any difference retains the whole new list, avoiding the classic admin-classic bug where shortening a list silently merged removed entries back in. Ships with 24 unit tests covering diff semantics, deep merges, key-ordering tolerance, null overrides, and a full parent-resolution round trip against a tempdir Grav layout.
2. [](#bugfix)
    * Config saves no longer return a `409 Conflict` on every second edit when sensitive fields are present. `ConfigController::update()` was hashing the PATCH response body from the in-memory `$merged` (non-redacted) but the next save's `If-Match` validation hashed the redacted `config->get()` representation, so the etag the client stored was never going to match on the next round-trip. Both the response body and the `If-Match` comparison now flow through a single `configEtagData()` helper that reads via `config->get()` after the save and applies the same redaction, so the client's stored etag stays valid across consecutive saves and reflects the shape a fresh `GET` would return (including any blueprint defaults or type coercion applied server-side during the filter step).
    * Config writes no longer silently create `user/<hostname>/config/` folders on save. `writeConfigFile()` was resolving the target directory via `locator->findResource('config://', true, true)`, whose first match can be the hostname-derived env path Grav auto-infers when `user/env/` doesn't exist — then `mkdir` materialized the path on first save, producing orphan `user/localhost/config/`, `user/<ddev-host>/config/`, etc. that then began overriding `user/config/` on every subsequent read. The write path now explicitly resolves to `user://config` (or an existing `user/env/<env>/` when `X-Config-Environment` is set), and the `mkdir` is reserved for plugin/theme sub-directories inside an already-existing write root. Env roots must be created deliberately via `POST /system/environments`.

# v1.0.0-beta.11
## 04/21/2026

1. [](#bugfix)
    * `POST /gpm/install` and `POST /gpm/update` now install missing blueprint dependencies before installing the requested package — mirroring admin-classic's behavior via `GPM::checkPackagesCanBeInstalled()` + `GPM::getDependencies()`, which resolves version constraints, checks PHP/Grav requirements, and returns a slug-keyed `install` / `update` / `ignore` map. Previously the naive recursive branch in `GpmService::install()` passed the raw blueprint `dependencies:` list (arrays of `{name, version}`) back into itself where `array_map` silently filtered them all to `false`, so deps were never installed and the user got a half-wired plugin (e.g. installing `shortcode-ui` without `shortcode-core`). Response bodies and `onApiPackageInstalled` / `onApiPackageUpdated` events now carry a `dependencies: string[]` list of slugs that were installed alongside, and cache-invalidation tags cover each new dep so list views refresh accordingly. Failure modes are surfaced cleanly: requiring a newer Grav core, a newer PHP version, or hitting an incompatible version constraint between packages returns a `422 Unprocessable Entity` with the original `GPM::getDependencies()` error message (e.g. "One of the packages require Grav 1.8.0. Please update Grav to the latest release.") — the API never auto-upgrades Grav itself, matching admin-classic. CLI color markup (`<red>`, `<cyan>`, …) is stripped from the propagated message. Deps are installed one-at-a-time so mid-install failures report partial state — the 500 detail includes a "Dependencies already installed before failure: foo, bar." suffix so callers know exactly what got through before the failure.

# v1.0.0-beta.10
## 04/19/2026

1. [](#bugfix)
    * `GET /pages` now returns accurate `published` and `visible` values for every page. Flex-indexed `PageObject` instances expose an empty header during listings, so `$page->published()` / `visible()` fell back to Grav's default "true" even when the frontmatter explicitly set them to false — making draft / hidden pages indistinguishable from published ones in list/tree/columns views. `PageSerializer` now parses the YAML frontmatter directly from the `.md` file on disk (with multilang filename resolution: page language → active language → untyped default → glob) whenever it gets a flex page with an empty header, and re-exposes the full header dict alongside correct `published` / `visible` booleans.
    * `PATCH /pages/{route}` now reflects `published` / `visible` changes in the response without requiring a reload. Legacy `Page` caches `$this->published` and `$this->visible` at init and doesn't re-derive them from header mutations, so after updating the header the API was returning the pre-save values. The update controller now calls the `Page::published()` and `Page::visible()` setters in addition to header replacement whenever those fields are sent (either as top-level keys or nested under `header`), keeping the in-memory object in sync with the just-written file.

# v1.0.0-beta.9
## 04/17/2026

1. [](#bugfix)
    * `GET /blueprints/pages/{template}` now honours the newer `'@extends':` and `'@import':` directives (string or `{type, context}` array form) alongside the legacy `extends@:` / `import@:` spellings. Previously, page blueprints using the newer syntax silently lost their inheritance chain — fields defined in the parent (e.g. `content: type: markdown`, `header.media_order: type: pagemedia` from `system://blueprints/pages/default.yaml`) were dropped, leaving only the fields the child blueprint declared locally. Caused custom page templates in themes like Helios to render with raw text inputs instead of markdown editors / page media uploaders in admin-next.
    * `GET /blueprints/pages/{template}` now fires `Pages::getTypes()` before resolving, which triggers the `onGetPageBlueprints` event and registers plugin-contributed blueprint paths into the `blueprints://pages/` locator stream. Without this, blueprints declared by plugins (via `$types->scanBlueprints('plugin://.../blueprints')`) were unreachable from the API even when the plugin was subscribed correctly.

# v1.0.0-beta.8
## 04/17/2026

1. [](#new)
    * `description_html` field added to the plugin/theme package serializer. Plugin and theme `description` strings are YAML-authored and routinely contain inline markdown (links, bold, emphasis) that renders as literal syntax in admin UIs. The API now ships a safe-mode Parsedown rendering alongside the raw `description` so clients can `{@html}` it for detail views and strip tags for one-line list cards without reinventing a markdown pipeline. Present on `GET /gpm/plugins`, `GET /gpm/plugins/{slug}`, `GET /gpm/themes`, `GET /gpm/themes/{slug}`, and the `/gpm/repository/*` endpoints.
2. [](#bugfix)
    * `GET /pages/{route}?summary=true` no longer 500s on pages whose content contains plugin shortcodes that rely on the frontend Twig/theme environment (e.g. `[poll]`). Shortcode processing runs as part of Grav's `summary()` pipeline and can throw when it tries to render template partials that aren't wired up in the API request context. The page serializer now catches the failure and falls back to a plain-text rendering of the raw markdown (shortcodes stripped, trimmed to `summary_size` or 300 chars) so admin previews keep working.

# v1.0.0-beta.7
## 04/17/2026

1. [](#new)
    * **`X-API-Token` header** added as the preferred transport for JWT access tokens. Sidesteps FastCGI / PHP-FPM / CGI setups (notably MAMP's `mod_fastcgi`) that silently strip the standard `Authorization` header before it reaches PHP — a common source of 401 errors on shared hosts. Accepts either a bare JWT (`X-API-Token: eyJ...`) or the traditional Bearer form (`X-API-Token: Bearer eyJ...`). `Authorization: Bearer` still works as a fallback for standards-compliant clients on hosts that don't strip it.
    * `GET /me` now returns `grav_version` and `admin_version` so admin UIs can surface the running Grav core and admin plugin versions without a separate request. `admin_version` resolves to the enabled admin2 or admin-classic plugin blueprint.
    * `is_symlink` field added to the installed-package serializer (present on `GET /gpm/plugins`, `GET /gpm/plugins/{slug}`, `GET /gpm/themes`, `GET /gpm/themes/{slug}`). Detected via `is_link()` on the resolved `plugins://{slug}` or `themes://{slug}` path so admin UIs can flag symlinked packages.
    * `POST /pages/{route}/adopt-language` — claims an untyped base page file (e.g., `default.md`) as a specific language by renaming it in-place to `{template}.{lang}.md`. Pure filesystem rename + cache bust; content is untouched. Fails if the page already has an explicit file for that language, or if the page has no untyped base file. Fires `onApiBeforePageAdoptLanguage` / `onApiPageLanguageAdopted`. Enables "Save as English" workflows on sites that started single-language and later enabled multilang.
    * Page translation response (`GET /pages`, `GET /pages/{route}` with `?translations=true`) now includes two new fields to disambiguate Grav's fallback behaviour: `has_default_file` (true when an untyped `{template}.md` exists) and `explicit_language_files` (the subset of site languages with a real `{template}.{lang}.md` on disk). Needed because Grav reports the default lang in `translated_languages` whenever `default.md` exists — admin UIs can now tell whether each lang is backed by an explicit file or the implicit fallback.
2. [](#improved)
    * `JwtAuthenticator::extractBearerToken()` now reads `X-API-Token` first, then falls back to `Authorization: Bearer`, then `?token=` query param. When both custom and standard headers are set, the custom header wins (so clients can send both for maximum host compatibility without ambiguity).
    * OpenAPI spec, README, and Newman test runner updated to lead with `X-API-Token`.
    * Default CORS allow-headers list in `api.yaml` now includes `X-API-Token` alongside the existing entries, so cross-origin preflights succeed out of the box on fresh installs.
3. [](#bugfix)
    * `GET /me` no longer 500s when resolving the admin plugin version. Previous implementation called `$grav['plugins']->get($slug)->getBlueprint()`, but `->get()` returns a `Data` config object, not a `Plugin` instance (no `getBlueprint()` method). Now reads `plugins://{slug}/blueprints.yaml` directly via the locator, matching the pattern used for themes.
    * `POST /pages/{route}/adopt-language` no longer spuriously rejects the default language with "A translation already exists". The previous check used `$page->translatedLanguages()` which always includes the default lang when `default.md` exists (because it serves as a fallback). The guard now checks the filesystem directly for `{template}.{lang}.md`, so adoption proceeds whenever the concrete language file is genuinely absent.

# v1.0.0-beta.6
## 04/16/2026

1. [](#new)
    * `POST /gpm/update-all` — bulk update every updatable plugin + theme in one request (returns `{updated[], failed[]}`)
    * `POST /gpm/upgrade` — Grav core self-upgrade (refuses to run when Grav is installed via symlink)
    * `GET /gpm/updates` response now includes `grav.is_symlink` and counts Grav itself in `total` so admin UIs can show the true update count
    * Events `onApiBeforePackageUpdate` / `onApiPackageUpdated` / `onApiBeforeGravUpgrade` / `onApiGravUpgraded` fire around the new write operations
2. [](#improved)
    * `POST /gpm/update` auto-detects whether the slug is a theme and passes `theme: true` to the installer so theme updates land in the right directory
    * `GpmService` — all GPM write operations (install / update / remove / direct-install / self-upgrade) are now implemented locally in the API plugin, removing the hard dependency on `Grav\Plugin\Admin\Gpm`. admin2 users can manage packages without the classic admin plugin installed
3. [](#bugfix)
    * Previously `POST /gpm/update` called the admin plugin's Gpm helper, which meant admin2-only sites (no classic admin) got `500 Admin Plugin Required` when trying to update anything

# v1.0.0-beta.5
## 04/16/2026

1. [](#bugfix)
    * `/auth/token` now delegates password check to `User::authenticate()` so the core trait's plaintext-password fallback fires — restores long-standing Grav behavior (admin-classic, Login plugin, frontend login) where a `password:` declared directly in `user/accounts/*.yaml` auto-hashes on first successful login. Previous direct `Authentication::verify()` call required users to pre-populate `hashed_password`, which broke the "edit yaml and log in" workflow that operators rely on when the CLI is unavailable
    * Persist the auto-generated JWT secret on fresh installs. The previous `findResource(..., true, true)` call returned an array, the fallback concatenated that array into `"Array/..."`, and the write silently went nowhere — so every request minted a different secret, producing a login-then-immediately-expire loop on every fresh 2.0 install. Now resolves the path with default flags and logs+degrades gracefully if persistence genuinely fails.

# v1.0.0-beta.4
## 04/15/2026

1. [](#new)
    * Page-view popularity tracker — single-file flat-JSON store with `flock()`, replaces admin-classic's four-file scheme; subscribes `onPageInitialized` for frontend hits only
    * One-shot import + rename of legacy `daily/monthly/totals/visitors.json` into the new `popularity.json` (ISO-keyed, `pages` capped at 500)
    * `popularity.{enabled, history.daily/monthly/visitors, ignore}` config block in `api.yaml`
    * `raw_route` field on serialized pages so admin clients can navigate home / aliased pages correctly
2. [](#improved)
    * Strict super-user scoping: `isSuperAdmin()` honors only `access.api.super` (no fallback to `admin.super`); operators can grant API authority without admin-classic implications
    * `SetupController` writes a minimal admin-next-native account (`site.login` + `api.super` only), with race guards and explicit avatar/2FA reset to prevent flex-stored ghost data
    * `issueTokenPair()` lifted to `AbstractApiController` so setup, login, refresh, and 2FA share one token-shape source
    * Pages list / dashboard stats no longer skip the home page — the virtual pages-root is now distinguished by `$page->exists()` instead of by `route() === '/'`
    * Dashboard `popularity` endpoint reads from `PopularityStore` (handles legacy import transparently)
3. [](#bugfix)
    * Pages list and dashboard `pages.total` undercounted by 1 (the home page was being filtered out)

# v1.0.0-beta.3
## 04/15/2026

1. [new]
    * Add intial user funtionality
    * Add `ai.super` permissions
    * Add missing vendor library

# v1.0.0-beta.2
## 04/15/2026

1. [improved] 
    * Default `enabled` to `true` since the plugin is not installed by default and admin2 requires it

# v1.0.0-beta.1
## 04/12/2026

1. [new]
    * Initial beta release

