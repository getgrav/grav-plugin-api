# Grav API Plugin

A RESTful API for [Grav CMS](https://getgrav.org) that provides full headless access to your site's content, media, configuration, users, and system management.

Built for the AI-native era — designed to work seamlessly with AI agents, MCP servers, CLI tools, mobile apps, and custom frontends.

## Requirements

- Grav CMS 1.8+
- PHP 8.3+
- Login Plugin 3.8+

## Installation

### GPM (preferred)

```bash
bin/grav install api
```

### Manual

1. Download or clone this repository into `user/plugins/api`
2. Run `composer install` in the plugin directory
3. Enable the plugin in Admin or via `user/config/plugins/api.yaml`

## Quick Start

### 1. Enable the Plugin

```yaml
# user/config/plugins/api.yaml
enabled: true
```

### 2. Generate an API Key

**Via CLI** (recommended for initial setup):

```bash
bin/plugin api keys:generate --user=admin --name="My First Key"
```

**Via Admin Panel**: Go to a user's profile — the **API Keys** section lets you generate, view, and revoke keys with optional expiry dates.

The generated key is shown **once** — save it immediately.

### 3. Make Your First Request

```bash
curl https://yoursite.com/api/v1/pages \
  -H "X-API-Key: grav_abc123..."
```

## Environments

Grav supports multiple environments (e.g., `localhost`, `staging.mysite.com`, `mysite.com`) with per-environment config overrides stored in `user/env/{environment}/config/`. The API respects this system via the optional `X-Grav-Environment` header.

```bash
# Explicitly target an environment
curl -H "X-Grav-Environment: mysite.com" -H "X-API-Key: ..." https://yoursite.com/api/v1/pages
```

If the header is omitted, the API defaults to Grav's auto-detected environment (derived from the hostname). When the header specifies a different environment, Grav reinitializes its config and cache context for that environment before processing the request.

**Discover available environments:**

```bash
curl -H "X-API-Key: ..." https://yoursite.com/api/v1/system/environments
```

Returns the current environment and all environment-specific overrides found in `user/env/`:

```json
{
  "data": {
    "current": "localhost",
    "environments": [
      {"name": "default", "active": true},
      {"name": "mysite.com", "active": false}
    ]
  }
}
```

## Authentication

The API supports three authentication methods. All three provide the same level of access — the authenticated user's permissions apply regardless of which method is used. When a request is received, the API tries each method in order until one succeeds.

### API Key (recommended for servers, CLI, automation)

Long-lived credentials ideal for server-to-server integrations, CLI tools, MCP servers, and CI/CD pipelines. Keys don't expire by default (optional expiry can be set), and persist until explicitly revoked.

```bash
# Via header (recommended)
curl -H "X-API-Key: grav_abc123..." https://yoursite.com/api/v1/pages

# Via query parameter (useful for quick debugging — less secure, visible in logs)
curl https://yoursite.com/api/v1/pages?api_key=grav_abc123...
```

Keys are stored as bcrypt hashes in `user/data/api-keys.yaml`. Each key is associated with a user, can be named, given an optional expiry, and independently revoked. Generate keys via CLI (`bin/plugin api keys:generate`) or the admin panel.

### JWT Bearer Token (recommended for browser apps, mobile apps)

Short-lived credentials ideal for SPAs, mobile apps, and any client-side application where long-lived secrets shouldn't be stored. Access tokens expire after 1 hour (configurable), and refresh tokens allow obtaining new access tokens without re-entering credentials.

**Step 1 — Login** (exchange credentials for tokens):

```bash
curl -X POST https://yoursite.com/api/v1/auth/token \
  -H "Content-Type: application/json" \
  -d '{"username": "admin", "password": "your-password"}'
```

Response:
```json
{
  "data": {
    "access_token": "eyJ...",
    "refresh_token": "eyJ...",
    "token_type": "Bearer",
    "expires_in": 3600
  }
}
```

**Step 2 — Use the access token** for API calls:

```bash
curl -H "Authorization: Bearer eyJ..." https://yoursite.com/api/v1/pages
```

**Step 3 — Refresh** before the access token expires (the old refresh token is automatically revoked — token rotation):

```bash
curl -X POST https://yoursite.com/api/v1/auth/refresh \
  -H "Content-Type: application/json" \
  -d '{"refresh_token": "eyJ..."}'
```

**Step 4 — Revoke** when the user logs out (returns 204 No Content):

```bash
curl -X POST https://yoursite.com/api/v1/auth/revoke \
  -H "Content-Type: application/json" \
  -d '{"refresh_token": "eyJ..."}'
```

### Session Passthrough (for admin panel integration)

If a user has an active Grav admin session, the API recognizes it automatically. This enables the current admin UI (or a future SPA admin) to call the API from the browser without separate authentication — no API key or JWT needed.

### Which method should I use?

| Use Case | Method | Why |
|----------|--------|-----|
| CLI tools, scripts | API Key | Simple, long-lived, no token management |
| MCP servers, AI agents | API Key | Persistent, no expiry concerns |
| Server-to-server | API Key | Static credential, easy to rotate |
| Mobile app | JWT | Short-lived, secure for client-side storage |
| SPA / browser frontend | JWT | Tokens in memory, refresh flow handles expiry |
| Admin panel extensions | Session | Seamless — already logged in |

## API Endpoints

All endpoints are prefixed with `/api/v1`. All responses use a standard JSON envelope.

### Pages

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/pages` | List pages (filterable, sortable, paginated) |
| `GET` | `/pages/{route}` | Get a single page |
| `POST` | `/pages` | Create a new page |
| `PATCH` | `/pages/{route}` | Update a page (partial) |
| `DELETE` | `/pages/{route}` | Delete a page |
| `POST` | `/pages/{route}/move` | Move a page |
| `POST` | `/pages/{route}/copy` | Copy a page |
| `POST` | `/pages/{route}/reorder` | Reorder child pages |
| `POST` | `/pages/batch` | Batch operations on multiple pages |
| `GET` | `/taxonomy` | List all taxonomy types and values |

**Filtering pages:**
```
GET /api/v1/pages?published=true&template=post&parent=blog
```

**Lazy-loading children** (for tree/miller column views):
```bash
# Direct children only (one level deep) — ideal for lazy-loading
GET /api/v1/pages?children_of=/blog

# Top-level pages only
GET /api/v1/pages?children_of=/

# Alternative: root-level pages
GET /api/v1/pages?root=true
```

Unlike `parent` (which returns all descendants), `children_of` returns only **direct children** — pages exactly one level below the given route. Combined with the `has_children` field in page responses, this enables efficient lazy-loading page trees and miller column interfaces.

**Sorting:**
```
GET /api/v1/pages?sort=date&order=desc
```

Allowed sort fields: `date`, `title`, `slug`, `modified`, `order`

**Pagination:**
```
GET /api/v1/pages?page=2&per_page=10
```

**Getting rendered HTML:**
```
GET /api/v1/pages/blog/my-post?render=true
```

**Including children:**
```
GET /api/v1/pages/blog?children=true&children_depth=2
```

**Reordering children:**
```bash
curl -X POST https://yoursite.com/api/v1/pages/blog/reorder \
  -H "X-API-Key: ..." -H "Content-Type: application/json" \
  -d '{"order": ["third-post", "first-post", "second-post"]}'
```

**Batch operations** (publish, unpublish, delete, copy — up to 50 items):
```bash
curl -X POST https://yoursite.com/api/v1/pages/batch \
  -H "X-API-Key: ..." -H "Content-Type: application/json" \
  -d '{"operation": "publish", "routes": ["/blog/draft-1", "/blog/draft-2"]}'
```

### Multi-Language

All page endpoints support the `?lang=xx` query parameter to target a specific language. Grav stores translations as separate files (e.g., `default.en.md`, `default.fr.md`).

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/languages` | List configured site languages |
| `GET` | `/pages/{route}/languages` | List available/missing translations for a page |
| `POST` | `/pages/{route}/translate` | Create a new translation |

```bash
# Get a page in French
GET /api/v1/pages/about?lang=fr

# List pages in German
GET /api/v1/pages?lang=de

# Create a French translation
curl -X POST https://yoursite.com/api/v1/pages/about/translate \
  -H "X-API-Key: ..." -H "Content-Type: application/json" \
  -d '{"lang": "fr", "title": "À propos", "content": "# Bienvenue"}'

# Delete only the French translation (keeps other languages)
DELETE /api/v1/pages/about?lang=fr

# Include translation info in page response
GET /api/v1/pages/about?translations=true
```

### Media

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/pages/{route}/media` | List media for a page |
| `POST` | `/pages/{route}/media` | Upload media to a page |
| `DELETE` | `/pages/{route}/media/{filename}` | Delete page media |
| `GET` | `/media` | List site-level media |
| `POST` | `/media` | Upload site-level media |
| `DELETE` | `/media/{filename}` | Delete site-level media |

**Uploading:**
```bash
curl -X POST https://yoursite.com/api/v1/pages/blog/my-post/media \
  -H "X-API-Key: grav_abc123..." \
  -F "file=@photo.jpg"
```

### Configuration

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/config/{scope}` | Read configuration |
| `PATCH` | `/config/{scope}` | Update configuration |

Scopes: `system`, `site`, `plugins/{name}`, `themes/{name}`

```bash
# Read site config
curl -H "X-API-Key: ..." https://yoursite.com/api/v1/config/site

# Update a plugin config
curl -X PATCH https://yoursite.com/api/v1/config/plugins/markdown \
  -H "X-API-Key: ..." \
  -H "Content-Type: application/json" \
  -d '{"extra": true}'
```

### Users

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/users` | List users |
| `POST` | `/users` | Create a user |
| `GET` | `/users/{username}` | Get user details |
| `PATCH` | `/users/{username}` | Update a user |
| `DELETE` | `/users/{username}` | Delete a user |
| `GET` | `/users/{username}/api-keys` | List API keys |
| `POST` | `/users/{username}/api-keys` | Generate an API key |
| `DELETE` | `/users/{username}/api-keys/{keyId}` | Revoke an API key |

### System

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/system/environments` | List available environments |
| `GET` | `/system/info` | System information |
| `DELETE` | `/cache` | Clear cache |
| `GET` | `/system/logs` | Read logs |
| `POST` | `/system/backup` | Create a backup |
| `GET` | `/system/backups` | List backups |

### GPM (Package Manager)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/gpm/plugins` | List installed plugins (with update status) |
| `GET` | `/gpm/plugins/{slug}` | Get installed plugin details |
| `GET` | `/gpm/themes` | List installed themes (with update status) |
| `GET` | `/gpm/themes/{slug}` | Get installed theme details |
| `GET` | `/gpm/updates` | Check for available updates |
| `POST` | `/gpm/install` | Install a plugin or theme |
| `POST` | `/gpm/remove` | Remove a plugin or theme |
| `POST` | `/gpm/update` | Update a specific package |
| `POST` | `/gpm/update-all` | Update all packages |
| `POST` | `/gpm/upgrade` | Self-upgrade Grav core |
| `POST` | `/gpm/direct-install` | Install from URL or zip upload |
| `GET` | `/gpm/search` | Search repository (plugins + themes) |
| `GET` | `/gpm/repository/plugins` | Browse available plugins |
| `GET` | `/gpm/repository/themes` | Browse available themes |
| `GET` | `/gpm/repository/{slug}` | Get repository package details |

**Installing a package:**
```bash
curl -X POST https://yoursite.com/api/v1/gpm/install \
  -H "X-API-Key: ..." -H "Content-Type: application/json" \
  -d '{"package": "shortcode-core", "type": "plugin"}'
```

**Installing a premium package** (pass the license inline, or pre-register it via the license-manager plugin):
```bash
curl -X POST https://yoursite.com/api/v1/gpm/install \
  -H "X-API-Key: ..." -H "Content-Type: application/json" \
  -d '{"package": "typhoon", "type": "theme", "license": "A1B2C3D4-E5F6A7B8-C9D0E1F2-A3B4C5D6"}'
```

**Searching the repository:**
```bash
# Search across all plugins and themes
GET /api/v1/gpm/search?q=email

# Search plugins only
GET /api/v1/gpm/repository/plugins?q=form

# Search themes only
GET /api/v1/gpm/repository/themes?q=blog
```

Searches match against slug, name, description, author, and keywords. All repository endpoints support pagination (`?page=2&per_page=50`).

### Scheduler & Tools

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/scheduler/jobs` | List all scheduler jobs with status |
| `GET` | `/scheduler/status` | Get cron installation status |
| `GET` | `/scheduler/history` | Job execution history (paginated) |
| `POST` | `/scheduler/run` | Trigger scheduler run manually |
| `GET` | `/reports` | Generate system reports |

### Dashboard

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/dashboard/notifications` | Get system notifications |
| `POST` | `/dashboard/notifications/{id}/hide` | Dismiss a notification |
| `GET` | `/dashboard/feed` | Get getgrav.org news feed |
| `GET` | `/dashboard/stats` | Dashboard statistics snapshot |

### Webhooks

Webhooks send HTTP POST notifications to external URLs when content changes via the API.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/webhooks` | List configured webhooks |
| `POST` | `/webhooks` | Create a webhook |
| `GET` | `/webhooks/{id}` | Get webhook details |
| `PATCH` | `/webhooks/{id}` | Update a webhook |
| `DELETE` | `/webhooks/{id}` | Delete a webhook |
| `GET` | `/webhooks/{id}/deliveries` | View delivery log (paginated) |
| `POST` | `/webhooks/{id}/test` | Send a test payload |

**Creating a webhook:**
```bash
curl -X POST https://yoursite.com/api/v1/webhooks \
  -H "X-API-Key: ..." -H "Content-Type: application/json" \
  -d '{
    "url": "https://yourapp.com/webhook-receiver",
    "events": ["page.created", "page.updated", "page.deleted"],
    "enabled": true
  }'
```

The response includes a `secret` for verifying payload signatures. Use `"events": ["*"]` to subscribe to all events.

**Available events:**

| Event | Trigger |
|-------|---------|
| `page.created` | Page created |
| `page.updated` | Page updated |
| `page.deleted` | Page deleted |
| `page.moved` | Page moved |
| `page.translated` | Translation created |
| `pages.reordered` | Children reordered |
| `media.uploaded` | Media uploaded |
| `media.deleted` | Media deleted |
| `user.created` | User created |
| `user.updated` | User updated |
| `user.deleted` | User deleted |
| `config.updated` | Config changed |
| `gpm.installed` | Package installed |
| `gpm.removed` | Package removed |
| `grav.upgraded` | Grav core upgraded |

**Payload format:**
```json
{
  "event": "page.created",
  "timestamp": "2026-03-26T20:00:00+00:00",
  "webhook_id": "wh_abc123...",
  "data": {
    "page": {"route": "/blog/new-post", "title": "New Post", "slug": "new-post"},
    "route": "/blog/new-post"
  }
}
```

**Security:** Each delivery includes an `X-Grav-Signature` header containing an HMAC-SHA256 hash of the payload body, signed with the webhook's `secret`. Verify it in your receiver:

```php
$signature = hash_hmac('sha256', $rawBody, $webhookSecret);
$valid = hash_equals($signature, $_SERVER['HTTP_X_GRAV_SIGNATURE']);
```

**Reliability:** Failed deliveries (5xx responses or timeouts) are retried up to 3 times with exponential backoff. After 5 consecutive failures, the webhook is automatically disabled. The failure count resets on any successful delivery.

**Note:** Webhooks fire only for changes made through the API. Changes via the admin panel or direct filesystem edits use different code paths and won't trigger webhooks.

### Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/auth/token` | Login (get JWT tokens) |
| `POST` | `/auth/refresh` | Refresh access token |
| `POST` | `/auth/revoke` | Revoke refresh token |

These endpoints do **not** require authentication.

### Translations

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/translations/{lang}` | Get all translation strings for a language |

This endpoint does **not** require authentication (translation strings are not sensitive).

**Get all English translations:**

```bash
curl -s "https://yoursite.com/api/v1/translations/en"
```

**Filter by prefix** (for faster partial loads):

```bash
curl -s "https://yoursite.com/api/v1/translations/en?prefix=PLUGIN_ADMIN"
```

```json
{
  "data": {
    "lang": "en",
    "count": 1248,
    "checksum": "6101ee5fcfeabc085cea537e4583038f",
    "strings": {
      "PLUGIN_ADMIN.TITLE": "Title",
      "PLUGIN_ADMIN.CONTENT": "Content",
      "PLUGIN_ADMIN.OPTIONS": "Options",
      "PLUGIN_ADMIN.PUBLISHING": "Publishing",
      "..."
    }
  }
}
```

The `checksum` can be used for cache invalidation — only re-fetch when the checksum changes. The `prefix` parameter enables a two-phase loading strategy: fetch a small subset for immediate use, then load the full set in the background.

### Blueprints

Blueprints provide form schema definitions used to render configuration and content editing interfaces. The API resolves blueprint inheritance (`extends@`, `import@`) and returns a normalized JSON structure suitable for client-side form rendering.

| Method | Endpoint | Description | Permission |
|--------|----------|-------------|------------|
| `GET` | `/blueprints/pages` | List available page templates | `api.pages.read` |
| `GET` | `/blueprints/pages/{template}` | Get resolved blueprint for a page template | `api.pages.read` |
| `GET` | `/blueprints/plugins/{plugin}` | Get blueprint for a plugin's configuration | `api.config.read` |
| `GET` | `/blueprints/themes/{theme}` | Get blueprint for a theme's configuration | `api.config.read` |
| `GET` | `/blueprints/config/{scope}` | Get blueprint for system config (`system`, `site`, `media`) | `api.config.read` |

**List page templates:**

```bash
curl -s "https://yoursite.com/api/v1/blueprints/pages" \
  -H "X-API-Key: YOUR_KEY"
```

```json
{
  "data": [
    { "type": "default", "label": "Default" },
    { "type": "blog", "label": "Blog" },
    { "type": "item", "label": "Item" }
  ]
}
```

**Get a page blueprint (resolved with inheritance):**

```bash
curl -s "https://yoursite.com/api/v1/blueprints/pages/blog" \
  -H "X-API-Key: YOUR_KEY"
```

```json
{
  "data": {
    "name": "blog",
    "title": "blog",
    "child_type": "item",
    "validation": "loose",
    "fields": [
      {
        "name": "tabs",
        "type": "tabs",
        "fields": [
          {
            "name": "content",
            "type": "tab",
            "title": "Content",
            "fields": [
              { "name": "header.title", "type": "text", "label": "Title" },
              { "name": "content", "type": "markdown" },
              { "name": "header.media_order", "type": "pagemedia", "label": "Page Media" }
            ]
          },
          {
            "name": "blog",
            "type": "tab",
            "title": "Blog Config",
            "fields": [
              { "name": "header.content.limit", "type": "text", "label": "Max Item Count", "validate": { "required": true, "type": "int" } },
              { "name": "header.content.order.by", "type": "select", "label": "Order By", "options": { "folder": "Folder", "title": "Title", "date": "Date" } },
              { "name": "header.content.pagination", "type": "toggle", "label": "Pagination" }
            ]
          }
        ]
      }
    ]
  }
}
```

The resolved blueprint includes all inherited fields from parent blueprints (e.g., a theme's `blog.yaml` extending the system `default.yaml`), with `extends@` and `import@` directives fully resolved.

**Supported field types** in the serialized output include: `text`, `textarea`, `select`, `toggle`, `checkbox`, `radio`, `markdown`, `editor`, `filepicker`, `pagemedia`, `taxonomy`, `list`, `array`, `tabs`, `tab`, `section`, `fieldset`, `columns`, `column`, `spacer`, `display`, `hidden`, and more. Unknown field types are passed through with their properties intact for client-side fallback rendering.

**Get a plugin blueprint:**

```bash
curl -s "https://yoursite.com/api/v1/blueprints/plugins/email" \
  -H "X-API-Key: YOUR_KEY"
```

## Response Format

### Success

```json
{
  "data": { ... },
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 47,
      "total_pages": 3
    }
  },
  "links": {
    "self": "/api/v1/pages?page=1&per_page=20",
    "next": "/api/v1/pages?page=2&per_page=20",
    "last": "/api/v1/pages?page=3&per_page=20"
  }
}
```

Non-paginated responses omit `meta` and `links`.

Page objects include a `has_children` boolean field indicating whether the page has child pages, enabling tree/column UIs to show expand indicators without loading children upfront.

### Errors (RFC 7807)

```json
{
  "status": 404,
  "title": "Not Found",
  "detail": "Page not found at route: /blog/missing-post"
}
```

Validation errors include field-level details:

```json
{
  "status": 422,
  "title": "Unprocessable Entity",
  "detail": "Missing required fields: title, route",
  "errors": [
    {"field": "title", "message": "The 'title' field is required."},
    {"field": "route", "message": "The 'route' field is required."}
  ]
}
```

## Concurrency Control

Write endpoints support optimistic concurrency via ETags:

1. `GET` responses include an `ETag` header
2. Send `If-Match: "<etag>"` with your `PATCH`/`DELETE` request
3. If the resource changed since your last read, you get a `409 Conflict`

This prevents accidental overwrites when multiple clients edit the same content.

## Rate Limiting

Enabled by default: 120 requests per 60-second window, per authenticated user (or per IP for unauthenticated requests).

Response headers on every request:
```
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 117
X-RateLimit-Reset: 1711382460
```

Exceeding the limit returns `429 Too Many Requests`.

Configure in `api.yaml`:
```yaml
rate_limit:
  enabled: true
  requests: 120
  window: 60
```

## CORS

Enabled by default for all origins. Configure allowed origins, methods, and headers in the admin panel or `api.yaml`:

```yaml
cors:
  enabled: true
  origins:
    - https://myapp.example.com
    - https://admin.example.com
  methods: [GET, POST, PATCH, DELETE, OPTIONS]
  headers: [Content-Type, Authorization, X-API-Key, If-Match]
  credentials: false
```

## Permissions

The API uses Grav's built-in ACL system. Available permissions:

| Permission | Description |
|------------|-------------|
| `api.access` | Basic API access (required for all authenticated requests) |
| `api.pages.read` | Read pages and taxonomy |
| `api.pages.write` | Create, update, delete, move, copy, reorder, batch pages |
| `api.media.read` | Read/list media files |
| `api.media.write` | Upload and delete media files |
| `api.config.read` | Read configuration |
| `api.config.write` | Update configuration |
| `api.users.read` | Read user accounts |
| `api.users.write` | Create, update, delete users |
| `api.system.read` | Read system info, logs, dashboard, notifications, feed |
| `api.system.write` | Clear cache, create backups, dismiss notifications |
| `api.gpm.read` | List packages, check updates, browse/search repository |
| `api.gpm.write` | Install, remove, update packages |
| `api.scheduler.read` | View scheduler jobs, status, history |
| `api.scheduler.write` | Trigger scheduler runs |
| `api.webhooks.read` | View webhooks and delivery logs |
| `api.webhooks.write` | Create, update, delete, test webhooks |

Users with `admin.super` bypass all permission checks.

## CLI Commands

Manage API keys from the command line:

```bash
# Generate a new key (interactive prompts if flags omitted)
bin/plugin api keys:generate --user=admin --name="My Key"

# Generate with expiry (30 days)
bin/plugin api keys:generate --user=admin --name="Temp Key" --expiry=30

# List all keys for a user
bin/plugin api keys:list --user=admin

# Revoke a key (interactive selection if key-id omitted)
bin/plugin api keys:revoke --user=admin [key-id]
```

## Extending the API

Other Grav plugins can register their own API routes by listening to the `onApiRegisterRoutes` event:

```php
// In your plugin class
public static function getSubscribedEvents(): array
{
    return [
        'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
    ];
}

public function onApiRegisterRoutes(Event $event): void
{
    $routes = $event['routes'];

    $routes->get('/comments/{pageRoute:.+}', [CommentsApiController::class, 'index']);
    $routes->post('/comments/{pageRoute:.+}', [CommentsApiController::class, 'create']);

    // Group related routes
    $routes->group('/webhooks', function ($group) {
        $group->get('', [WebhookController::class, 'index']);
        $group->post('', [WebhookController::class, 'create']);
        $group->delete('/{id}', [WebhookController::class, 'delete']);
    });
}
```

Your controller should extend `AbstractApiController` to get access to all the standard helpers (auth, pagination, response building, etc).

## Events

The API fires events before and after all write operations, allowing plugins to react, validate, modify data, or cancel operations.

### Page Events

| Event | When | Event Data |
|-------|------|------------|
| `onApiBeforePageCreate` | Before a page is saved | `route`, `header`, `content`, `template`, `lang` (modifiable by reference) |
| `onApiPageCreated` | After page creation | `page` (PageInterface), `route`, `lang` |
| `onApiBeforePageUpdate` | Before a page is updated | `page` (PageInterface), `data` (request body, modifiable by reference) |
| `onApiPageUpdated` | After page update | `page` (PageInterface) |
| `onApiBeforePageDelete` | Before a page is deleted | `page` (PageInterface), `lang` (if language-specific delete) |
| `onApiPageDeleted` | After page deletion | `route`, `lang` (if language-specific delete) |
| `onApiPageMoved` | After page move | `page` (PageInterface), `old_route`, `new_route` |
| `onApiBeforePageTranslate` | Before a translation is created | `page`, `lang`, `header`, `content` (modifiable by reference) |
| `onApiPageTranslated` | After translation created | `page`, `route`, `lang` |
| `onApiBeforePagesReorder` | Before children are reordered | `parent` (PageInterface), `order` (slug array) |
| `onApiPagesReordered` | After children reordered | `parent` (PageInterface), `order` |

### Media Events

| Event | When | Event Data |
|-------|------|------------|
| `onApiBeforeMediaUpload` | Before each file is saved | `page`, `filename`, `type`, `size` |
| `onApiMediaUploaded` | After upload completes | `page`, `filenames` (array) |
| `onApiBeforeMediaDelete` | Before a media file is deleted | `page`, `filename` |
| `onApiMediaDeleted` | After media deletion | `page`, `filename` |

### Config Events

| Event | When | Event Data |
|-------|------|------------|
| `onApiConfigUpdated` | After config is saved | `scope`, `data` |

### User Events

| Event | When | Event Data |
|-------|------|------------|
| `onApiUserCreated` | After user creation | `user` (UserInterface) |
| `onApiUserUpdated` | After user update | `user` (UserInterface) |
| `onApiBeforeUserDelete` | Before user deletion | `user` (UserInterface) |
| `onApiUserDeleted` | After user deletion | `username` |

### GPM Events

| Event | When | Event Data |
|-------|------|------------|
| `onApiBeforePackageInstall` | Before package install | `package`, `type` |
| `onApiPackageInstalled` | After package installed | `package`, `type` |
| `onApiBeforePackageRemove` | Before package removal | `package`, `type` |
| `onApiPackageRemoved` | After package removed | `package`, `type` |
| `onApiBeforeGravUpgrade` | Before Grav upgrade | `current_version`, `available_version` |
| `onApiGravUpgraded` | After Grav upgraded | `previous_version`, `new_version` |

### Route Registration

| Event | When | Event Data |
|-------|------|------------|
| `onApiRegisterRoutes` | During router initialization | `routes` (ApiRouteCollector) |

### Using Events in Your Plugin

**React to content changes** (e.g., clear a search index when pages change):

```php
public static function getSubscribedEvents(): array
{
    return [
        'onApiPageCreated' => ['onApiPageCreated', 0],
        'onApiPageUpdated' => ['onApiPageUpdated', 0],
        'onApiPageDeleted' => ['onApiPageDeleted', 0],
    ];
}

public function onApiPageCreated(Event $event): void
{
    $page = $event['page'];
    $this->searchIndex->add($page);
}

public function onApiPageUpdated(Event $event): void
{
    $page = $event['page'];
    $this->searchIndex->update($page);
}

public function onApiPageDeleted(Event $event): void
{
    $route = $event['route'];
    $this->searchIndex->remove($route);
}
```

**Validate or modify data before save** (e.g., enforce content rules):

```php
public function onApiBeforePageCreate(Event $event): void
{
    $header = &$event['header'];
    $content = &$event['content'];

    // Auto-add a timestamp
    $header['api_created'] = date('c');

    // Reject empty content
    if (empty(trim($content))) {
        throw new \RuntimeException('Page content cannot be empty.');
    }
}
```

**Reject file uploads** (e.g., enforce image-only policy):

```php
public function onApiBeforeMediaUpload(Event $event): void
{
    $type = $event['type'];
    if (!str_starts_with($type, 'image/')) {
        throw new \RuntimeException('Only image uploads are allowed.');
    }
}
```

### Admin-Compatible Events

In addition to the `onApi*` events above, the API plugin fires the same `onAdmin*` events that Grav's admin plugin fires. This ensures third-party plugins that subscribe to admin events (SEO Magic, Auto Date, Mega Frontmatter, etc.) work correctly regardless of whether changes come from the admin UI or the API.

Both event families fire for every operation — `onAdmin*` events first, then `onApi*` events.

#### Events Fired

| Event | Controller | Methods | Event Data (matches admin plugin signatures) |
|-------|-----------|---------|----------------------------------------------|
| `onAdminCreatePageFrontmatter` | Pages | `create` | `header` (array, modifiable), `data` (request body) |
| `onAdminSave` | Pages | `create`, `update`, `translate` | `object` (Page, by reference), `page` (Page, by reference) |
| `onAdminAfterSave` | Pages | `create`, `update`, `translate` | `object` (Page), `page` (Page) |
| `onAdminAfterDelete` | Pages | `delete` | `object` (Page), `page` (Page) |
| `onAdminAfterSaveAs` | Pages | `move` | `path` (new filesystem path) |
| `onAdminAfterAddMedia` | Media | `uploadPageMedia` | `object` (Page), `page` (Page) |
| `onAdminAfterDelMedia` | Media | `deletePageMedia` | `object` (Page), `page` (Page), `media` (Media), `filename` (string) |
| `onAdminSave` | Users | `create`, `update` | `object` (User, by reference) |
| `onAdminAfterSave` | Users | `create`, `update` | `object` (User) |
| `onAdminSave` | Config | `update` | `object` (Data, by reference) |
| `onAdminAfterSave` | Config | `update` | `object` (Data) |

#### Event Ordering

For a page create operation, events fire in this order:

1. `onApiBeforePageCreate` — API before event
2. `onAdminCreatePageFrontmatter` — admin frontmatter injection
3. `onAdminSave` — admin pre-save (plugins can modify the page)
4. `onAdminAfterSave` — admin post-save (indexing, notifications)
5. `onApiPageCreated` — API after event (triggers webhooks)

#### Example: Existing Plugin Compatibility

Plugins that already listen for admin events will automatically work with the API — no code changes needed:

```php
// This SEO Magic listener fires for both admin UI saves and API saves
public static function getSubscribedEvents(): array
{
    return [
        'onAdminAfterSave'   => ['onObjectSave', 0],
        'onAdminAfterDelete' => ['onObjectDelete', 0],
    ];
}
```

## Configuration Reference

Full configuration in `user/config/plugins/api.yaml`:

```yaml
enabled: true
route: /api
version_prefix: v1

auth:
  api_keys_enabled: true
  jwt_enabled: true
  jwt_secret: ''          # Auto-generated on first use
  jwt_algorithm: HS256
  jwt_expiry: 3600        # Access token lifetime (seconds)
  jwt_refresh_expiry: 604800  # Refresh token lifetime (seconds)
  session_enabled: true

cors:
  enabled: true
  origins: ['*']
  methods: [GET, POST, PATCH, DELETE, OPTIONS]
  headers: [Content-Type, Authorization, X-API-Key, If-Match, If-None-Match]
  expose_headers: [ETag, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset]
  max_age: 86400
  credentials: false

rate_limit:
  enabled: true
  requests: 120
  window: 60

pagination:
  default_per_page: 20
  max_per_page: 100
```

## OpenAPI Specification

A complete OpenAPI 3.0 specification is included at [`openapi.yaml`](openapi.yaml). Import it into:

- **Grav Docs** via the [API Doc Import](https://github.com/getgrav/grav-plugin-api-doc-import) plugin
- **Postman** for interactive testing
- **Swagger UI** for browsable documentation
- **Any OpenAPI-compatible tool** for client SDK generation

## Development

### Running Tests

```bash
composer install
composer test
```

The test suite runs standalone without a Grav installation (using stubs for Grav core classes). For integration tests within a Grav instance:

```bash
vendor/bin/phpunit --group integration
```

### Project Structure

```
grav-plugin-api/
├── api.php                          # Plugin entry point
├── api.yaml                         # Default configuration
├── blueprints.yaml                  # Admin UI configuration
├── permissions.yaml                 # ACL permission definitions
├── openapi.yaml                     # OpenAPI 3.0 specification
├── composer.json
├── classes/Api/
│   ├── ApiRouter.php                # FastRoute dispatcher + middleware chain
│   ├── ApiRouteCollector.php        # Plugin route registration helper
│   ├── Auth/
│   │   ├── AuthenticatorInterface.php
│   │   ├── ApiKeyAuthenticator.php
│   │   ├── JwtAuthenticator.php
│   │   ├── SessionAuthenticator.php
│   │   └── ApiKeyManager.php
│   ├── Controllers/
│   │   ├── AbstractApiController.php
│   │   ├── AuthController.php
│   │   ├── ConfigController.php
│   │   ├── DashboardController.php
│   │   ├── GpmController.php
│   │   ├── MediaController.php
│   │   ├── PagesController.php
│   │   ├── SchedulerController.php
│   │   ├── SystemController.php
│   │   ├── UsersController.php
│   │   └── WebhookController.php
│   ├── Exceptions/
│   │   ├── ApiException.php
│   │   ├── ConflictException.php
│   │   ├── ForbiddenException.php
│   │   ├── NotFoundException.php
│   │   ├── UnauthorizedException.php
│   │   └── ValidationException.php
│   ├── Middleware/
│   │   ├── AuthMiddleware.php
│   │   ├── CorsMiddleware.php
│   │   ├── JsonBodyParserMiddleware.php
│   │   └── RateLimitMiddleware.php
│   ├── Response/
│   │   ├── ApiResponse.php
│   │   └── ErrorResponse.php
│   ├── Serializers/
│   │   ├── SerializerInterface.php
│   │   ├── PageSerializer.php
│   │   ├── MediaSerializer.php
│   │   ├── PackageSerializer.php
│   │   └── UserSerializer.php
│   └── Webhooks/
│       ├── WebhookManager.php
│       └── WebhookDispatcher.php
└── tests/
    ├── bootstrap.php
    ├── Stubs/
    └── Unit/
```

## License

MIT License. See [LICENSE](LICENSE) for details.

## Credits

Built by [Team Grav](https://getgrav.org) for the Grav CMS community.
