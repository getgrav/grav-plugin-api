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
| `GET` | `/taxonomy` | List all taxonomy types and values |

**Filtering pages:**
```
GET /api/v1/pages?published=true&template=post&parent=blog
```

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
| `GET` | `/system/info` | System information |
| `DELETE` | `/cache` | Clear cache |
| `GET` | `/system/logs` | Read logs |
| `POST` | `/system/backup` | Create a backup |
| `GET` | `/system/backups` | List backups |

### Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/auth/token` | Login (get JWT tokens) |
| `POST` | `/auth/refresh` | Refresh access token |
| `POST` | `/auth/revoke` | Revoke refresh token |

These endpoints do **not** require authentication.

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
| `api.pages.write` | Create, update, delete, move, copy pages |
| `api.media.read` | Read/list media files |
| `api.media.write` | Upload and delete media files |
| `api.config.read` | Read configuration |
| `api.config.write` | Update configuration |
| `api.users.read` | Read user accounts |
| `api.users.write` | Create, update, delete users |
| `api.system.read` | Read system info and logs |
| `api.system.write` | Clear cache, create backups |

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
│   │   ├── MediaController.php
│   │   ├── PagesController.php
│   │   ├── SystemController.php
│   │   └── UsersController.php
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
│   └── Serializers/
│       ├── SerializerInterface.php
│       ├── PageSerializer.php
│       ├── MediaSerializer.php
│       └── UserSerializer.php
└── tests/
    ├── bootstrap.php
    ├── Stubs/
    └── Unit/
```

## License

MIT License. See [LICENSE](LICENSE) for details.

## Credits

Built by [Team Grav](https://getgrav.org) for the Grav CMS community.
