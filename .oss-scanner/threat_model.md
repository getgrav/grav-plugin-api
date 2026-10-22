# Threat model: Grav API plugin

The scanner reads this file before it starts. It covers the Grav API plugin (`getgrav/grav-plugin-api`). Severity follows Grav's own policy, [SECURITY.md](https://github.com/getgrav/grav/blob/develop/SECURITY.md) in core, which rates an issue by **whether it crosses a trust boundary**, not by what a CVSS calculator says.

## What this project does and where untrusted input enters

The API plugin is the REST API for Grav 2.0, served under `/api/v1`. It is the entire backend of the Admin2 single-page admin, so everything an administrator can do in Grav 2.0 goes through it: pages, media, configuration, users and groups, API keys, package installs (GPM), backups, the scheduler, translations and reports. It is also used directly by integrations with API keys.

Treat every HTTP request under `/api/v1` as untrusted: JSON and multipart bodies, query strings, route parameters, uploaded files and zip packages, and headers (`X-API-Key`, `X-API-Token`, `Authorization`, `Origin`, `Referer`, `X-HTTP-Method-Override`). Content one account stores (page frontmatter and markdown, media metadata, user profile fields, translation overrides) and another account later sees in Admin2 is also untrusted.

### Where the code is

| Path | What it is |
| --- | --- |
| `/src` | The plugin checkout (`develop`). Patches belong here. |
| `/var/www/grav` | A working Grav site built from the latest `grav-admin` release, served by Apache with `.htaccess` enabled. |
| `/var/www/grav/user/plugins/api` | A symlink to `/src`, so the site runs this checkout and edits are live immediately. |
| `/src/classes/Api/ApiRouter.php` | Middleware order, the public-route list, and every core route. |
| `/src/classes/Api/Controllers/` | One controller per resource; `AbstractApiController.php` holds the permission helpers. |
| `/src/classes/Api/Auth/`, `/src/classes/Api/Middleware/` | API key, JWT and session authenticators, CORS, same-origin guard, demo mode, rate limiting. |

Grav core and the other bundled plugins (`admin2`, `login`, `form`, `email`, `flex-objects`) are in the site but live in their own repositories. If a bug is in their code, say which repository owns it rather than patching it here.

### Trust model

- **Anonymous visitors** are untrusted. They can reach only the public routes: everything under `/api/v1/auth/` (token, refresh, 2FA verify, forgot and reset password, invitations, first-run setup, captcha, SSO), `/api/v1/translations/`, `/api/v1/thumbnails/` and `/api/v1/ping`, plus any route a plugin adds through `onApiCollectPublicRoutes`. Reaching anything else without credentials is a finding.
- **Authenticated accounts** must stay within the permissions in `permissions.yaml`. `api.access` is the entry gate, then each resource has its own `read`/`write` permission (`api.pages.write`, `api.users.write`, `api.system.backup`, and so on). Page-level permissions in a page's `header.permissions` (`PageAcl.php`) are also a boundary.
- **Super users** (`api.super` or `admin.super`) are trusted. Editing configuration, creating users, installing or direct-installing packages from a zip or URL, running scheduler jobs (which are shell commands), and creating or downloading backups are their job, not a vulnerability.
- **Scoped API keys** must never act outside their scopes, even when the key's owner is a super user. The chokepoint is `requirePermission()` and `scopeAllows()` in `AbstractApiController.php`; a permission check that calls `isSuperAdmin()` or a bare permission string instead is the most common bug in this plugin (`tests/Unit/ScopeCapChokepointTest.php` explains the history).
- **Demo accounts** (`api.demo`) are read-only except for routes listed in `demo.writable` / `demo.allow_routes`. Writing anything else is a finding.
- **Publishers** (accounts with `api.pages.write` but no super) write page content. Content running in the publisher's own scope is the feature. Content that executes script in a super user's Admin2 session, or that runs Twig when `security.twig_content` forbids it for that account, crosses a boundary.
- **First-run setup**: `POST /api/v1/auth/setup` creates the first super user only while the site has no accounts at all. That window on a brand new install is by design. Creating an account through it once any account exists is critical.
- **Operator files are trusted**: `user/config/**`, `user/config/plugins/api-private.php` (the JWT secret), `user/data/api-keys.yaml`, the environment and the filesystem.

## Components that matter most / least

Highest priority:

1. Authentication: `Auth/` (API key, JWT, session authenticators), `AuthController` (token, refresh, revoke, 2FA, forgot and reset password), `SetupController`, `InvitationsController`, `SsoController` (state, `sanitizeReturnTo`), token revocation and `api_tokens_valid_after`.
2. Authorization: every `requirePermission()` call and every place that skips it, the scope cap for API keys, `PermissionResolver`, `PageAcl`, demo mode, and the `api.super` checks in `UsersController` and `GroupsController` (an account granting itself or others more than it holds).
3. CSRF on cookie-authenticated writes (`SameOriginGuard`, `AuthMiddleware::isForgeableWrite`), CORS (`CorsMiddleware`), and the session-sharing code in `ApiRouter::protectSharedSession`.
4. File handling: `MediaController`, `HandlesMediaUploads`, `BlueprintUploadController`, avatar and logo uploads, `rawSiteMedia`, `thumbnail`, backup download and delete, and any request value joined into a filesystem path (traversal, extension and SVG checks).
5. Stored XSS that reaches Admin2: values the API returns that Admin2 renders, including `admin-next/fields/*.js`, which runs inside the admin.
6. Server-side request forgery in `WebhookDispatcher` and GPM direct-install from a URL (the URL is super-only, but the request must not be steerable by a lower account).
7. YAML and config writers: `ConfigController` / `ConfigStore` (which scopes need super), `TranslationOverrideStore`, `PagesController` frontmatter merges.

Lower priority or out of scope:

- `vendor/` (firebase/php-jwt, nikic/fast-route, trilbymedia/cap-php). Report upstream unless this plugin uses a library unsafely.
- `openapi.yaml` and `grav-api.postman_collection.json` (documentation, generated), `tests/`, `package*.json`.
- Grav core and the other plugins in `/var/www/grav`; they are enrolled or maintained separately.

## How to exercise it

- Run `grav-start` first (there is no init system). The site is at `http://127.0.0.1/`, the API at `http://127.0.0.1/api/v1/`, Admin2 at `http://127.0.0.1/admin`. `grav-stop` stops Apache. Grav's log is `/var/www/grav/logs/grav.log`.
- Accounts: `admin` / `Admin-pass-1` (super user), `editor` / `Editor-pass-1` (`api.access` plus pages and media read/write only), `member` / `Member-pass-1` (front-end login only, no API access). They are YAML files in `/var/www/grav/user/accounts/`.
- Get a token: `curl -s -X POST -H 'Content-Type: application/json' -d '{"username":"editor","password":"Editor-pass-1"}' http://127.0.0.1/api/v1/auth/token`, then send it as `X-API-Token: <access_token>`. Admin2 itself sends both that header and the site session cookie.
- API keys are created as a logged-in user through the users routes in `ApiRouter.php`, can carry a `scopes` list, and are sent as `X-API-Key: grav_...`.
- Every email the site sends (password reset, invitations) is written to `/var/mail-catcher/*.eml` instead of being delivered, so reset and invite tokens can be read from there.
- Unit tests: `api-phpunit` runs the plugin's PHPUnit suite against the current `/src` (it syncs `/src` into `/opt/api-dev`, which has the dev dependencies), for example `api-phpunit --filter ScopeCapChokepointTest`. Add a regression test with each patch where you can.
- Run Grav CLI commands as the web server user with `grav-cli`, for example `grav-cli bin/grav clear`.

## How you rate severity

| Severity | When to use |
| --- | --- |
| **Critical** | An **unauthenticated** attacker gets remote code execution, reads site data that is not public (accounts, config, private files), or gains super-user-equivalent control. No Grav account needed. |
| **High** | A **cross-trust-boundary** issue: a lower-privilege account (or an anonymous visitor through a stored payload) runs code, reads data or takes actions in a higher-privilege session. Examples: stored XSS that fires in a super user's Admin2 session, a publisher or editor granting themselves `api.super`, a scoped API key acting outside its scopes, CSRF that changes permissions. |
| **Moderate** | An authenticated account does something **outside its documented permissions**, but the impact stays within its own session or its own tier. Examples: an editor reading another resource family's data they could not otherwise read, a demo account making a write. |
| **Low** | A super user doing something within the capabilities they already hold. Grav does not publish advisories for these; report them only as hardening. |

Rate by the starting access and the boundary crossed, not by the CVSS calculator, which assumes `PR:N` far too often.

## Anything to leave alone

These are closed without an advisory under Grav's policy, so please do not report them as vulnerabilities:

- Anything a super user can do within their granted capabilities (see the trust model above).
- Bypasses of `Security::detectXss()` or `Utils::isDangerousFunction()` in core. Both are heuristic denylists, not security boundaries. Content that renders **unescaped at an output sink** is in scope; show the sink.
- Account or email enumeration through login, registration or password-reset messages. This is documented default behavior.
- Self-XSS, or anything that needs the victim to paste a payload into their own session.
- Links in reset and invitation emails built from the request `Host` header **when no trusted host is configured** (`system.custom_base_url` or `plugins.login.site_host`). This is documented, and Admin2 shows a warning on the dashboard until it is set. A link that ignores a configured trusted host is in scope.
- Missing hardening with no demonstrated exploit: absent security headers, rate limits we do not have, non-constant-time comparisons without a practical timing oracle.
- Dependency CVEs without a reachable call path from this plugin.
- Anything that first needs the attacker to install a plugin or write arbitrary files on the server (they could already run their own PHP).

## How we would like reports

Each report should state the account the attacker starts with, what they end up with, and which trust boundary is crossed. Include a reproducer that runs against this image (curl commands are ideal) and a patch against `/src`. If the root cause is in Grav core or another plugin, name that repository.
