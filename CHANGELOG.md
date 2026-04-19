# v1.0.0-beta.10
## 04/19/2026

1. [](#bugfix)
    * `POST /gpm/install` and `POST /gpm/update` now install missing blueprint dependencies before installing the requested package — mirroring admin-classic's behavior via `GPM::checkPackagesCanBeInstalled()` + `GPM::getDependencies()`, which resolves version constraints, checks PHP/Grav requirements, and returns a slug-keyed `install` / `update` / `ignore` map. Previously the naive recursive branch in `GpmService::install()` passed the raw blueprint `dependencies:` list (arrays of `{name, version}`) back into itself where `array_map` silently filtered them all to `false`, so deps were never installed and the user got a half-wired plugin (e.g. installing `shortcode-ui` without `shortcode-core`). Response bodies and `onApiPackageInstalled` / `onApiPackageUpdated` events now carry a `dependencies: string[]` list of slugs that were installed alongside, and cache-invalidation tags cover each new dep so list views refresh accordingly.
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

