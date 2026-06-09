# Technology Stack

**Analysis Date:** 2026-06-09

## Languages

**Primary:**
- PHP 7.4+ - All plugin logic (`cinatra.php`, `uninstall.php`)

**Secondary:**
- JavaScript (inline, no build step) - Admin settings UI dynamic behavior and fallback widget button (`cinatra.php`, lines 330-363 and 455-494)
- HTML/CSS (inline, server-rendered) - Settings page markup and floating button styles (`cinatra.php`)

## Runtime

**Environment:**
- WordPress 5.9+ (WordPress plugin, runs inside the WP request lifecycle)
- PHP 7.4 minimum; CI tests against PHP 8.2

**Package Manager:**
- None — no Composer, no npm. Zero external PHP or JS dependencies.
- Lockfile: Not applicable

## Frameworks

**Core:**
- WordPress Plugin API — hooks (`add_action`, `add_filter`), Settings API (`register_setting`), REST API (`register_rest_route`), Options API (`get_option`, `update_option`)

**Testing:**
- PHP syntax linting via `php -l` (CI only, no unit test framework)
- WordPress Plugin Check (`WordPress/plugin-check-action@v1`) — informational, non-blocking

**Build/Dev:**
- No build step — plugin is a single PHP file shipped directly
- CI: GitHub Actions (`.github/workflows/ci.yml`)

## Key Dependencies

**Critical:**
- WordPress core — provides all APIs the plugin uses (Settings API, REST API, Options, admin hooks, `wp_enqueue_script`, `wp_localize_script`)
- `mcp-adapter/mcp-adapter.php` (optional companion plugin) — WordPress MCP Adapter; enables AI tool access from the chat widget. Plugin detects its presence and shows an admin notice if missing.

**Infrastructure:**
- Cinatra instance (self-hosted, operator-controlled) — the plugin fetches `/api/wordpress/bundle.js` from this instance at runtime to load the chat widget JS bundle.

## Configuration

**Environment:**
- All configuration is stored in WordPress options (wp_options table), not environment variables or .env files.
- Required settings: `cinatra_url` (base URL of self-hosted Cinatra instance), `cinatra_api_key` (bearer token), `cinatra_instance_id` (agent instance ID), `cinatra_webhook_secret` (shared HMAC secret).
- Settings managed via WordPress admin at Settings > Cinatra (`options-general.php?page=cinatra`).

**Build:**
- No build config files. The plugin consists of `cinatra.php` and `uninstall.php` only.

## Platform Requirements

**Development:**
- PHP 7.4+
- WordPress 5.9+
- GitHub Actions for CI (php-lint, readme-validate, plugin-check jobs)

**Production:**
- Standard WordPress hosting with PHP 7.4+
- WordPress 5.9 – 6.8 (tested up to 6.8)
- Network access from the browser (not server) to the configured Cinatra instance URL
- Optional: WordPress MCP Adapter plugin installed alongside

---

*Stack analysis: 2026-06-09*
