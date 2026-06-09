<!-- refreshed: 2026-06-09 -->
# Architecture

**Analysis Date:** 2026-06-09

## System Overview

```text
┌──────────────────────────────────────────────────────────────┐
│                    WordPress Admin (Browser)                  │
│  admin_footer hook → #cinatra-root mount + fallback button   │
└───────────────────────────┬──────────────────────────────────┘
                            │ HTTP (bundle.js load + API calls)
                            ▼
┌──────────────────────────────────────────────────────────────┐
│              Self-hosted Cinatra Instance                     │
│  {cinatra_url}/api/wordpress/bundle.js  (widget JS bundle)   │
│  {cinatra_url}/...                      (data/chat API)       │
└──────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────┐
│  WordPress Plugin — cinatra.php (single-file)                │
│                                                              │
│  WordPress Hooks Layer                                       │
│  ├── admin_init        → register_setting, migration         │
│  ├── admin_menu        → settings page registration          │
│  ├── admin_enqueue_scripts → bundle.js + config injection    │
│  ├── admin_footer      → #cinatra-root DOM + fallback UI     │
│  ├── admin_notices     → dependency + config warnings        │
│  └── rest_api_init     → /wp-json/cinatra/v1/webhooks        │
│                                                              │
│  Sanitizers / Validators                                     │
│  ├── cinatra_sanitize_url_option()                           │
│  ├── cinatra_sanitize_subscriptions_option()                 │
│  ├── cinatra_validate_event_type()                           │
│  ├── cinatra_validate_target_url()                           │
│  ├── cinatra_sanitize_target_url()                           │
│  └── cinatra_sanitize_post_types()                           │
│                                                              │
│  REST Handlers                                               │
│  ├── cinatra_rest_list_webhooks()                            │
│  ├── cinatra_rest_create_webhook()                           │
│  └── cinatra_rest_delete_webhook()                           │
│                                                              │
│  Storage Helpers                                             │
│  ├── cinatra_get_webhook_subscriptions()                     │
│  └── cinatra_save_webhook_subscriptions()                    │
└─────────────────────────────────────────────────────────────-┘
                            │ wp_options table
                            ▼
┌──────────────────────────────────────────────────────────────┐
│  WordPress Database (wp_options)                             │
│  cinatra_url, cinatra_api_key, cinatra_instance_id,          │
│  cinatra_webhook_secret, cinatra_webhook_subscriptions (JSON)│
└──────────────────────────────────────────────────────────────┘
```

## Component Responsibilities

| Component | Responsibility | File |
|-----------|----------------|------|
| Settings registration | Registers five wp_options, attaches sanitizers, runs legacy migration on `admin_init` | `cinatra.php` (line 33–63) |
| Legacy migration | One-shot copy of `cinatra_widget_*` option keys to new `cinatra_*` keys | `cinatra.php` (`cinatra_migrate_legacy_options`, line 75–91) |
| Sanitizers / validators | Input validation and sanitization for all stored values and REST args | `cinatra.php` (lines 95–175) |
| Settings page | Renders HTML form at **Settings → Cinatra**; `manage_options` gated | `cinatra.php` (`cinatra_render_settings_page`, line 195–317) |
| Settings-page JS | Live-updates connector URL display in API Key / Webhook Secret descriptions | `cinatra.php` (lines 324–365, inline via `wp_add_inline_script`) |
| Admin notices | Warns about missing MCP Adapter and missing Agent Instance ID; scoped to plugin screen only | `cinatra.php` (lines 369–396) |
| Widget enqueue | Loads `{cinatra_url}/api/wordpress/bundle.js` and injects `CinatraConfig` via `wp_localize_script` | `cinatra.php` (lines 404–419) |
| Admin footer mount | Outputs `#cinatra-root` div, fallback button, inline CSS, fallback error panel, health-check JS | `cinatra.php` (lines 425–495) |
| REST API — webhooks | CRUD registry at `/wp-json/cinatra/v1/webhooks`; `manage_options` gated | `cinatra.php` (lines 499–628) |
| Storage helpers | `cinatra_get_webhook_subscriptions` / `cinatra_save_webhook_subscriptions` (JSON in wp_options) | `cinatra.php` (lines 620–628) |
| Uninstall cleanup | Removes all `cinatra_*` and legacy `cinatra_widget_*` options; multisite-aware | `uninstall.php` |

## Pattern Overview

**Overall:** Single-file WordPress plugin following the standard WordPress hook/filter pattern. All logic is contained in `cinatra.php`; no classes, no autoloading, no Composer dependencies, no bundled JS/CSS assets.

**Key Characteristics:**
- Entirely procedural PHP with anonymous-function hook callbacks and named standalone functions for REST handlers and sanitizers.
- No external PHP dependencies — relies entirely on WordPress core APIs (`register_setting`, `wp_enqueue_script`, `wp_localize_script`, `register_rest_route`, `get_option`/`update_option`, etc.).
- Remote widget delivery: the chat widget JS is fetched at runtime from the user-configured Cinatra instance (`{cinatra_url}/api/wordpress/bundle.js`); the plugin ships no client-side code for the widget itself.
- Configuration stored entirely in `wp_options` (five keys). Webhook subscriptions stored as a JSON-encoded array in a single option, capped at 100 entries.
- Contract versioning: `CINATRA_CONTRACT_VERSION = 'v1'` is injected via `CinatraConfig` so the Cinatra instance can reject incompatible plugin versions.

## Layers

**WordPress Hooks Layer:**
- Purpose: Wires plugin behaviour into WordPress lifecycle events
- Location: `cinatra.php` (anonymous closures passed to `add_action` / `add_filter`)
- Contains: `admin_init`, `admin_menu`, `admin_enqueue_scripts`, `admin_footer`, `admin_notices`, `rest_api_init`, `plugin_action_links_*`
- Depends on: WordPress core APIs, named functions defined below hooks
- Used by: WordPress core (called by WP hook system)

**Settings & Sanitization Layer:**
- Purpose: Validates and cleans all user-supplied input before storage
- Location: `cinatra.php` (named functions `cinatra_sanitize_*`, `cinatra_validate_*`, `cinatra_migrate_legacy_options`)
- Contains: URL sanitizer, subscriptions JSON sanitizer, event-type/target-URL/post-types validators
- Depends on: WordPress sanitization helpers (`sanitize_text_field`, `esc_url_raw`, `sanitize_key`, `post_type_exists`)
- Used by: `register_setting` callbacks, REST route `args` schema

**REST API Layer:**
- Purpose: Exposes webhook subscription CRUD to the Cinatra instance
- Location: `cinatra.php` (route registrations lines 499–551; handlers `cinatra_rest_list_webhooks`, `cinatra_rest_create_webhook`, `cinatra_rest_delete_webhook`)
- Contains: GET, POST, DELETE handlers for `/wp-json/cinatra/v1/webhooks`
- Depends on: Storage helpers, sanitizers/validators, `WP_REST_Request`/`WP_REST_Response`
- Used by: Cinatra instance (calls these endpoints over HTTP)

**Storage Layer:**
- Purpose: Abstracts reading/writing webhook subscriptions to wp_options
- Location: `cinatra.php` (`cinatra_get_webhook_subscriptions`, `cinatra_save_webhook_subscriptions`)
- Contains: JSON encode/decode around `get_option`/`update_option`
- Depends on: WordPress `get_option`/`update_option`
- Used by: REST handlers

**Presentation Layer:**
- Purpose: Renders admin settings UI and injects widget mount point
- Location: `cinatra.php` (`cinatra_render_settings_page`, `admin_footer` hook, `admin_enqueue_scripts` hook)
- Contains: HTML settings form, inline admin JS, widget root div, fallback button/error panel
- Depends on: WordPress settings API, `wp_add_inline_script`, `wp_localize_script`
- Used by: WordPress admin rendering

## Data Flow

### Settings Save Flow

1. Administrator opens **Settings → Cinatra** (`cinatra_render_settings_page`, `cinatra.php` line 195)
2. Submits form to `options.php` (standard WordPress Settings API POST)
3. WordPress calls `cinatra_sanitize_url_option` / `sanitize_text_field` / `cinatra_sanitize_subscriptions_option` (registered via `register_setting`)
4. Sanitized values written to `wp_options` table

### Widget Load Flow

1. Any wp-admin page load triggers `admin_enqueue_scripts` hook
2. Plugin checks `manage_options` capability and that `cinatra_url` and `cinatra_api_key` are set (`cinatra.php` line 404–419)
3. `wp_enqueue_script('cinatra', '{cinatra_url}/api/wordpress/bundle.js', ...)` — browser fetches JS from Cinatra instance
4. `wp_localize_script` injects `CinatraConfig` object: `contractVersion`, `cinatraUrl`, `apiKey`, `instanceId`, `wpAdminUrl`
5. `admin_footer` hook outputs `#cinatra-root` div and fallback button (`cinatra.php` line 425–495)
6. Remote bundle JS mounts into `#cinatra-root` and sets `data-cinatra-mounted="true"` on it; fallback button hides itself via `MutationObserver`

### Webhook Registration Flow (Cinatra → WordPress)

1. Cinatra instance calls `POST /wp-json/cinatra/v1/webhooks` with `event_type`, `target_url`, optional `post_types`
2. REST route validates args via schema callbacks (`cinatra_validate_event_type`, `cinatra_validate_target_url`, `cinatra_sanitize_post_types`)
3. `cinatra_rest_create_webhook` checks for duplicates (409) and cap of 100 subscriptions (409), then appends new subscription
4. `cinatra_save_webhook_subscriptions` JSON-encodes and writes to `wp_options`
5. Returns 201 with the new subscription object

### Uninstall Flow

1. Administrator deletes plugin from WordPress admin → WordPress calls `uninstall.php`
2. `uninstall.php` iterates all `cinatra_*` option keys (including legacy `cinatra_widget_*`)
3. On multisite: switches to each blog in turn and deletes options, then restores

**State Management:**
- All persistent state stored in `wp_options` (five keys: `cinatra_url`, `cinatra_api_key`, `cinatra_instance_id`, `cinatra_webhook_secret`, `cinatra_webhook_subscriptions`)
- No in-memory or transient state between requests
- Webhook subscriptions stored as a single JSON-encoded array option (not a custom table)

## Key Abstractions

**CinatraConfig (JS object):**
- Purpose: The PHP-to-JS data bridge; passes connection settings to the remotely loaded widget
- Examples: injected via `wp_localize_script` in `cinatra.php` line 412–418
- Pattern: WordPress `wp_localize_script` — serialized as a JS global before the enqueued script tag

**Webhook Subscription Record:**
- Purpose: Represents one event-type/target-URL subscription registered by the Cinatra instance
- Shape: `{id: uuid, event_type: string, target_url: string, post_types: string[], created_at: ISO8601}`
- Storage: packed into `cinatra_webhook_subscriptions` JSON option

**Contract Version:**
- Purpose: Allows Cinatra core to reject incompatible plugin versions
- Location: `CINATRA_CONTRACT_VERSION` constant, `cinatra.php` line 24; passed in `CinatraConfig`

## Entry Points

**Plugin bootstrap:**
- Location: `cinatra.php` (WordPress loads this file on plugin activation / each request)
- Triggers: WordPress plugin loader
- Responsibilities: Defines constants, registers all hooks/actions/filters

**Uninstall:**
- Location: `uninstall.php`
- Triggers: WordPress plugin deletion (checks `WP_UNINSTALL_PLUGIN`)
- Responsibilities: Removes all plugin options from wp_options; multisite-aware

**REST API:**
- Location: `cinatra.php` lines 499–628 (registered via `rest_api_init`)
- Triggers: HTTP requests to `/wp-json/cinatra/v1/webhooks` and `/wp-json/cinatra/v1/webhooks/{id}`
- Responsibilities: Webhook subscription CRUD, `manage_options` permission gating

## Architectural Constraints

- **Threading:** Single-threaded PHP-FPM / Apache request lifecycle; no background workers or queues.
- **Global state:** Five `define()` constants at file top (`CINATRA_PLUGIN_VERSION`, `CINATRA_CONTRACT_VERSION`, `CINATRA_THEME_*`). No mutable PHP globals.
- **Circular imports:** None — single-file plugin; no imports.
- **Capability gating:** Every hook callback, REST permission callback, and settings page renderer checks `current_user_can('manage_options')` before acting.
- **Remote code execution (deferred concern):** The plugin loads executable JS from a user-configured origin via `wp_enqueue_script`. This is flagged as a WordPress.org submission blocker (see `docs/superpowers/specs/2026-06-08-cinatra-wporg-submission-design.md` §4); the architectural decision on whether to ship the widget bundle inside the plugin or expose a data API is pending.
- **Subscription storage limit:** Webhook subscriptions are capped at 100 entries (`cinatra_rest_create_webhook`, line 581) to prevent unbounded option growth.

## Anti-Patterns

### Remote executable code loaded from user-controlled URL

**What happens:** `wp_enqueue_script('cinatra', '{cinatra_url}/api/wordpress/bundle.js', ...)` loads and executes JS from an arbitrary URL entered by the admin.
**Why it's wrong:** WordPress.org Guideline #8 prohibits loading executable code from remote/external sources. Because the Cinatra instance is self-hosted at an unpredictable domain, the "fixed vendor domain" exception does not apply. This also exposes the `apiKey` to whatever that remote code does (C6 in the compliance spec).
**Do this instead:** Ship the widget JS inside the plugin (GPL source, per Guideline #2) and call the Cinatra instance only as a data/API backend. See `docs/superpowers/specs/2026-06-08-cinatra-wporg-submission-design.md` §4 for the converged recommendation.

### Inline `<script>` in `admin_footer` hook

**What happens:** The `admin_footer` hook outputs a `<script>` block directly with `echo '<script>...'`.
**Why it's wrong:** WordPress Plugin Check flags direct inline script output; the settings-page JS was already corrected to use `wp_add_inline_script`, but the admin-footer fallback block still uses a raw echo.
**Do this instead:** Register a script handle and attach inline JS via `wp_add_inline_script`, or move logic to a separately registered script file.

## Error Handling

**Strategy:** Defensive validation at all input boundaries; no exceptions thrown; HTTP status codes used in REST responses.

**Patterns:**
- Sanitize callbacks return safe empty values (`''`, `[]`, `'[]'`) on invalid input rather than propagating bad data.
- REST handlers return `WP_REST_Response` with explicit HTTP status codes (400, 404, 409, 201).
- Fallback UI: if the remote widget bundle fails to load, the fallback button shows an error panel with a `fetch` HEAD-check result (`cinatra.php` lines 455–494).

## Cross-Cutting Concerns

**Logging:** None — plugin uses no custom logging. Errors surfaced via admin notices (settings screen only) or HTTP status codes in REST responses.
**Validation:** All user input passes through named sanitizer/validator functions before storage or use. REST args schema handles REST-layer validation declaratively.
**Authentication:** All admin-facing surfaces require `manage_options` capability. REST endpoints use `permission_callback` returning `current_user_can('manage_options')`. The plugin stores a `cinatra_webhook_secret` for Cinatra to sign outbound requests, but does not itself verify inbound HMAC signatures (configuration storage only).

---

*Architecture analysis: 2026-06-09*
