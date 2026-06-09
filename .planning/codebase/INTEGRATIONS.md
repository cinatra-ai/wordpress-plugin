# External Integrations

**Analysis Date:** 2026-06-09

## APIs & External Services

**Cinatra AI Instance (primary integration):**
- Cinatra — self-hosted AI assistant backend operated by the site owner (not a third-party SaaS)
  - SDK/Client: No SDK. Browser fetches JS bundle directly; plugin passes config via `wp_localize_script`.
  - Auth: Bearer token stored in WordPress option `cinatra_api_key`; passed to the browser as `CinatraConfig.apiKey`.
  - Bundle endpoint: `{cinatra_url}/api/wordpress/bundle.js` — loaded via `wp_enqueue_script` on every admin page for `manage_options` users.
  - Connector settings path: `{cinatra_url}/settings/connectors/wordpress-widget`
  - Contract versioning: plugin sends `contractVersion: 'v1'` in `CinatraConfig`; Cinatra rejects unknown versions.

**WordPress MCP Adapter (optional companion plugin):**
- Plugin slug: `mcp-adapter/mcp-adapter.php`
- Purpose: enables AI tool access (content editing actions) from within the chat widget
- Integration: presence detected via `is_plugin_active()`; admin notice shown on settings screen if absent
- No direct API calls from this plugin to MCP Adapter; the relationship is detected-only

## Data Storage

**Databases:**
- WordPress Options (wp_options table) — all persistent plugin data
  - `cinatra_url` — base URL of the Cinatra instance
  - `cinatra_api_key` — bearer token / API key
  - `cinatra_instance_id` — agent instance identifier
  - `cinatra_webhook_secret` — shared HMAC secret for webhook signature verification
  - `cinatra_webhook_subscriptions` — JSON array of registered webhook subscriptions (max 100 entries)
  - Legacy keys auto-migrated on `admin_init`: `cinatra_widget_url`, `cinatra_widget_api_key`, `cinatra_widget_instance_id`
  - Multisite-aware cleanup on uninstall (`uninstall.php`)

**File Storage:**
- Not applicable — plugin stores no files

**Caching:**
- Not applicable — no caching layer; uses WordPress options directly

## Authentication & Identity

**Auth Provider:**
- WordPress native capability system (`manage_options` capability gates all plugin functionality)
- API key auth toward Cinatra instance: bearer token (`cinatra_api_key`) passed to browser JS, used by the Cinatra widget when contacting the Cinatra instance
- Webhook HMAC: shared secret (`cinatra_webhook_secret`) stored in options; Cinatra signs outbound webhook requests with `X-Cinatra-Sig-256` header. The plugin stores the secret but does not itself verify inbound webhooks — Cinatra uses the secret.

## Monitoring & Observability

**Error Tracking:**
- Not detected — no third-party error tracking (no Sentry, Bugsnag, etc.)

**Logs:**
- No custom logging. Browser-side connectivity failures surface via an inline fallback UI (the floating button shows an error panel if `fetch` to `{cinatra_url}/api/wordpress/bundle.js` fails). WordPress core logging applies.

## CI/CD & Deployment

**Hosting:**
- Standard WordPress hosting (plugin distributed as a zip for manual install or via WordPress.org)

**CI Pipeline:**
- GitHub Actions (`.github/workflows/ci.yml`)
  - `php-lint`: `php -l` on all `.php` files, PHP 8.2
  - `readme-validate`: verifies `readme.txt` headers and version consistency with `cinatra.php`
  - `plugin-check`: `WordPress/plugin-check-action@v1` (non-blocking, `continue-on-error: true`)
  - Triggers: push to `main`, all pull requests

## Environment Configuration

**Required env vars:**
- None — no environment variables. All configuration is entered by the WordPress admin through the Settings UI and stored in wp_options.

**Secrets location:**
- API key and webhook secret stored in WordPress options (`cinatra_api_key`, `cinatra_webhook_secret`). No `.env` files detected.

## Webhooks & Callbacks

**Incoming (REST API endpoints exposed by this plugin):**
- `GET /wp-json/cinatra/v1/webhooks` — list registered webhook subscriptions; requires `manage_options`
- `POST /wp-json/cinatra/v1/webhooks` — register a new webhook subscription (fields: `event_type`, `target_url`, `post_types`); deduped by event_type+target_url; capped at 100 subscriptions; requires `manage_options`
- `DELETE /wp-json/cinatra/v1/webhooks/{id}` — delete a subscription by UUID; requires `manage_options`
- Note: the plugin registers subscriptions but does not dispatch webhook events itself — Cinatra reads the subscription list and fires events directly.

**Outgoing:**
- None from the server side. All outbound traffic is initiated by the administrator's browser (loading the Cinatra bundle JS and the admin chat session).

---

*Integration audit: 2026-06-09*
