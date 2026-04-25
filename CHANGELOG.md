# v1.0.0-beta.13
## 04/24/2026

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

