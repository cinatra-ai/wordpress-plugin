# Codebase Structure

**Analysis Date:** 2026-06-09

## Directory Layout

```
cinatra-ai/wordpress-plugin/       # Repo root (also the plugin root for WP install)
├── cinatra.php                    # Single-file plugin — all PHP logic
├── uninstall.php                  # Plugin deletion cleanup
├── readme.txt                     # WordPress.org submission readme
├── README.md                      # Developer/GitHub readme
├── LICENSE                        # GPL-2.0-or-later
├── .github/
│   ├── CODEOWNERS                 # Code ownership config
│   └── workflows/
│       └── ci.yml                 # GitHub Actions: php-lint, readme-validate, plugin-check
└── docs/
    └── superpowers/
        └── specs/
            └── 2026-06-08-cinatra-wporg-submission-design.md  # WP.org compliance design doc
```

## Directory Purposes

**Root (`/`):**
- Purpose: The WordPress plugin directory — contains everything WordPress needs to install and run the plugin.
- Contains: The main plugin file, uninstall file, and WordPress.org submission files.
- Key files: `cinatra.php` (all plugin logic), `uninstall.php` (cleanup), `readme.txt` (WP.org), `README.md` (GitHub)

**`.github/workflows/`:**
- Purpose: CI pipeline definitions.
- Contains: `ci.yml` with three jobs: `php-lint` (syntax check all PHP files), `readme-validate` (checks required WP.org headers and that `Stable tag` matches `Version`), `plugin-check` (WordPress Plugin Check action, informational/non-blocking).
- Key files: `.github/workflows/ci.yml`

**`docs/superpowers/specs/`:**
- Purpose: Design and compliance decision documents.
- Contains: Dated spec files recording architectural decisions and compliance findings.
- Key files: `docs/superpowers/specs/2026-06-08-cinatra-wporg-submission-design.md`

## Key File Locations

**Entry Points:**
- `cinatra.php`: Plugin bootstrap — WordPress loads this file; all hooks registered at parse time.
- `uninstall.php`: Deletion handler — WordPress calls this when the plugin is deleted from the admin.

**Configuration:**
- `cinatra.php` (lines 21–29): Plugin version constants (`CINATRA_PLUGIN_VERSION`, `CINATRA_CONTRACT_VERSION`) and brand theme constants (`CINATRA_THEME_*`).
- `readme.txt`: WordPress.org headers (`Requires at least`, `Tested up to`, `Stable tag`, `Requires PHP`).

**Core Logic:**
- `cinatra.php` (lines 33–628): All plugin PHP — settings registration, sanitizers, settings page render, admin enqueue, admin footer widget mount, REST API.

**Uninstall:**
- `uninstall.php`: Removes all `cinatra_*` and legacy `cinatra_widget_*` options; multisite-aware.

**CI:**
- `.github/workflows/ci.yml`: PHP lint + readme validation gate; plugin-check (informational).

**Design Docs:**
- `docs/superpowers/specs/2026-06-08-cinatra-wporg-submission-design.md`: WP.org compliance findings, deferred architectural decisions.

## Naming Conventions

**Files:**
- Plugin main file: lowercase plugin-slug name (`cinatra.php`)
- WordPress standard lifecycle file: `uninstall.php`
- WordPress.org submission readme: `readme.txt` (lowercase, `.txt` extension required by WP.org)
- GitHub readme: `README.md` (uppercase, Markdown)
- Spec/design docs: `YYYY-MM-DD-kebab-case-title.md` under `docs/superpowers/specs/`

**PHP Functions:**
- All plugin functions prefixed with `cinatra_` (e.g., `cinatra_sanitize_url_option`, `cinatra_rest_create_webhook`, `cinatra_get_webhook_subscriptions`)
- Sanitizers: `cinatra_sanitize_<thing>()`
- Validators: `cinatra_validate_<thing>()`
- REST handlers: `cinatra_rest_<verb>_<resource>()`
- Storage helpers: `cinatra_get_<resource>()` / `cinatra_save_<resource>()`

**PHP Constants:**
- All constants prefixed `CINATRA_` in `UPPER_SNAKE_CASE` (e.g., `CINATRA_PLUGIN_VERSION`, `CINATRA_THEME_ACCENT`)

**WordPress Option Keys:**
- Current: `cinatra_url`, `cinatra_api_key`, `cinatra_instance_id`, `cinatra_webhook_secret`, `cinatra_webhook_subscriptions`
- Legacy (migrated away): `cinatra_widget_url`, `cinatra_widget_api_key`, `cinatra_widget_instance_id`

**WordPress Hooks / Script Handles:**
- Script handles: `cinatra` (widget bundle), `cinatra-admin` (settings-page JS)
- Settings group: `cinatra_options`
- Menu slug: `cinatra`
- REST namespace: `cinatra/v1`

## Where to Add New Code

**New plugin setting:**
1. Add `register_setting('cinatra_options', 'cinatra_<name>', [...])` in the `admin_init` hook block (`cinatra.php` line 33).
2. Add a sanitizer function named `cinatra_sanitize_<name>` following the existing pattern.
3. Add the option key to the `$cinatra_options` array in `uninstall.php`.
4. Add the input field to `cinatra_render_settings_page` in `cinatra.php`.
5. Pass the value to the widget via `wp_localize_script` in the `admin_enqueue_scripts` hook if the widget bundle needs it.

**New REST endpoint:**
- Add a `register_rest_route('cinatra/v1', ...)` call inside the existing `rest_api_init` hook closure (`cinatra.php` line 499).
- Add a named handler function prefixed `cinatra_rest_<verb>_<resource>` following existing handler patterns.
- Use declarative `args` schema with `validate_callback` and `sanitize_callback` per the existing webhook pattern.

**New admin notice:**
- Add inside the existing `admin_notices` hook closure (`cinatra.php` line 369), keeping the `settings_page_cinatra` screen guard in place (Guideline #11).

**New sanitizer/validator:**
- Add a named function prefixed `cinatra_sanitize_` or `cinatra_validate_` near the existing sanitizers block (`cinatra.php` lines 95–175).

**New design/spec document:**
- Place in `docs/superpowers/specs/` with filename `YYYY-MM-DD-kebab-case-title.md`.

## Special Directories

**`.github/`:**
- Purpose: GitHub-specific configuration (CODEOWNERS, Actions workflows).
- Generated: No
- Committed: Yes
- Note: Should be excluded from the WordPress.org SVN/zip distribution.

**`docs/`:**
- Purpose: Design documentation and compliance specs; not part of the WordPress plugin runtime.
- Generated: No
- Committed: Yes
- Note: Should be excluded from the WordPress.org SVN/zip distribution.

**`.planning/codebase/`:**
- Purpose: GSD codebase map documents (auto-generated by the `/gsd-map-codebase` command).
- Generated: Yes (by GSD tooling)
- Committed: Optional (team choice)
- Note: Must be excluded from the WordPress.org SVN/zip distribution.

---

*Structure analysis: 2026-06-09*
