# Coding Conventions

**Analysis Date:** 2026-06-09

## Naming Patterns

**Files:**
- Main plugin file uses plugin slug as filename: `cinatra.php`
- Uninstall file follows WordPress standard: `uninstall.php`
- All PHP files use lowercase with hyphens for multi-word names (WordPress convention)

**Functions:**
- All public/global functions are prefixed with the plugin slug: `cinatra_` (e.g., `cinatra_migrate_legacy_options`, `cinatra_render_settings_page`, `cinatra_rest_list_webhooks`)
- Sanitizer functions follow the pattern `cinatra_sanitize_<thing>` (e.g., `cinatra_sanitize_url_option`, `cinatra_sanitize_subscriptions_option`)
- Validator functions follow the pattern `cinatra_validate_<thing>` (e.g., `cinatra_validate_event_type`, `cinatra_validate_target_url`)
- REST callback functions follow the pattern `cinatra_rest_<verb>_<resource>` (e.g., `cinatra_rest_list_webhooks`, `cinatra_rest_create_webhook`, `cinatra_rest_delete_webhook`)
- Private/closure functions registered via `add_action`/`add_filter` are anonymous lambdas — no prefix needed since they are not global

**Variables:**
- snake_case throughout: `$new_subscription`, `$current_url`, `$bundle_url`
- Local variables scoped inside closures use short descriptive names: `$url`, `$api_key`, `$instance_id`
- Option keys use the plugin prefix: `cinatra_url`, `cinatra_api_key`, `cinatra_webhook_secret`

**Constants:**
- UPPER_SNAKE_CASE with plugin prefix: `CINATRA_PLUGIN_VERSION`, `CINATRA_CONTRACT_VERSION`, `CINATRA_THEME_ACCENT`
- Theme constants grouped together with alignment: `CINATRA_THEME_ACCENT`, `CINATRA_THEME_ACCENT_HOVER`, `CINATRA_THEME_ACCENT_SOFT`, etc.

**Types/Return Types:**
- PHP 7.4+ return type hints on named functions: `function cinatra_migrate_legacy_options(): void`, `function cinatra_sanitize_url_option($value): string`, `function cinatra_get_webhook_subscriptions(): array`
- Parameter type hints omitted on public sanitizer/validator functions (for WordPress callback compatibility) but return types are always declared

## Code Style

**Formatting:**
- No dedicated formatter config detected (no `.prettierrc`, no `phpcs.xml`, no `phpstan.neon` in the repo)
- Indentation: 4 spaces for PHP
- Opening braces on same line for closures and control flow; opening braces on next line not used
- One blank line between logical sections; multiple blank lines used for major section separators
- Inline CSS/JS embedded directly in PHP files (no separate asset files in the repo)

**Linting:**
- CI runs `php -l` (PHP syntax check only) on all `.php` files: `.github/workflows/ci.yml`
- WordPress Plugin Check action (`WordPress/plugin-check-action@v1`) runs as non-blocking (`continue-on-error: true`) — informational only
- No PHPCS/WPCS enforcement at this time (noted in CI comments as future work)

## Import Organization

Not applicable — the plugin uses no `require`/`include` statements beyond the WordPress core `require_once ABSPATH . 'wp-admin/includes/plugin.php'` (conditionally included inside a hook callback when needed). There is no Composer autoloader or PSR-4 structure.

## Error Handling

**Patterns:**
- Early return/exit at the top of every file if the WordPress constant guard is not met: `if (!defined('ABSPATH')) exit;` in `cinatra.php`; `if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }` in `uninstall.php`
- REST endpoint handlers return `WP_REST_Response` with explicit HTTP status codes: 201 for creation, 400 for validation failures, 404 for not found, 409 for duplicates or limit exceeded
- Validation errors on REST routes use the route `args` schema (`validate_callback`, `sanitize_callback`) before the callback executes — the callback itself does a secondary guard (`if ($event_type === '' || $target_url === '')`) as a belt-and-suspenders check
- `json_decode` results are always checked with `is_array()` before use; fallback to `[]` or `'[]'` on invalid input
- No exceptions are thrown; WordPress-style return-value error handling is used throughout

## Logging

**Framework:** None — the plugin does not log anything. No `error_log()` calls, no WP_DEBUG checks.

**Patterns:** Not applicable. Errors surface to the user via WordPress admin notices (e.g., missing MCP Adapter notice, missing Agent Instance ID notice in `cinatra_render_settings_page` area via `admin_notices` hook).

## Comments

**When to Comment:**
- PHPDoc blocks on every named function with `@package` tag in `uninstall.php`; inline `/** … */` doc comments on named functions in `cinatra.php` explaining intent and constraints
- Inline comments explain non-obvious decisions: why private IP addresses are not rejected in `cinatra_sanitize_url_option`, why the subscription limit exists in `cinatra_rest_create_webhook`
- Section dividers use `// ---------------------------------------------------------------------------` to visually separate major logical areas within the single-file plugin
- Translators comments for `sprintf`-based i18n strings follow WordPress convention: `/* translators: %s: … */`

**JSDoc/TSDoc:** Not applicable — inline JavaScript is minimal and embedded as heredoc strings.

## Function Design

**Size:** Functions are short and single-purpose. The longest named function is `cinatra_render_settings_page` (~80 lines including HTML). REST handler functions are 15–30 lines each.

**Parameters:** Named functions minimally parameterized — sanitizer/validator functions take `$value` only (matching WordPress callback signatures). REST handlers take `WP_REST_Request $request` with full type hint.

**Return Values:** Explicit return types on all named functions. REST handlers always return `WP_REST_Response` (via `new WP_REST_Response(...)` or `rest_ensure_response(...)`). Sanitizers return the canonical sanitized type (`string`, `array`). Validators return `bool`.

## Module Design

**Exports:** Single-file plugin (`cinatra.php`). All named functions are global (no namespace). Plugin prefix `cinatra_` on every public symbol prevents conflicts.

**Barrel Files:** Not applicable — single-file architecture with no modules.

## WordPress-Specific Conventions

- All output escaped at point of output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses()` used consistently throughout `cinatra_render_settings_page`
- i18n: all user-visible strings wrapped in `__()` or `esc_html_e()` with text domain `'cinatra'`
- Settings registered via `register_setting()` with `sanitize_callback` — never trust raw `$_POST`
- `wp_localize_script()` used to pass PHP config to JavaScript (not inline `<script>` variable injection)
- `wp_add_inline_script()` used for admin JS attached to a registered dummy handle (Plugin Check compliance)
- Capability check `current_user_can('manage_options')` at the top of every action callback that touches sensitive data or output

---

*Convention analysis: 2026-06-09*
