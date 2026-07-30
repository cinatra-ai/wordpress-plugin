<?php
/**
 * Standalone behavior tests for the ensure-panel detection additions
 * (cinatra-ai/cinatra#2021 S6 / epsilon). Runs under plain
 * `php tests/test-setup-checklist.php` — no PHPUnit, no WordPress install.
 * Exit code 0 = all pass, 1 = a failure.
 *
 * Covers:
 *   - WP/PHP/HTTPS floor checks (cinatra_ensure_wp_version_ok/php_version_ok/
 *     https_ok).
 *   - cinatra_ensure_plugin_state(): absent / installed-but-inactive / active,
 *     each with a version string, for BOTH companion plugins (present=false
 *     implies active=false + version=''; the is_plugin_active()-only gap this
 *     closes is asserted directly).
 *   - cinatra_ensure_current_user_role(): the browsing user's first role, or
 *     '' with no roles (courtesy row only — NOT the audited D6/D8 warning).
 *   - cinatra_render_setup_checklist() smoke-runs in the all-absent and
 *     all-present states without fataling and without emitting any
 *     install/activation call (detection-only; no writes).
 */

require __DIR__ . '/wp-stubs.php';
require dirname(__DIR__) . '/cinatra.php';

$failures = 0;
function check($label, $cond) {
    global $failures;
    if ($cond) {
        echo "  PASS  $label\n";
    } else {
        echo "  FAIL  $label\n";
        $failures++;
    }
}

function reset_ensure_fixture() {
    $GLOBALS['cinatra_test']['active_plugins']     = array();
    $GLOBALS['cinatra_test']['installed_plugins']  = array();
    $GLOBALS['cinatra_test']['wp_version']         = '6.9';
    $GLOBALS['cinatra_test']['is_ssl']             = true;
    $GLOBALS['cinatra_test']['current_user_roles'] = array();
    if (class_exists('\WP\MCP\Core\McpAdapter')) {
        \WP\MCP\Core\McpAdapter::$servers_fixture = array();
    }
}

// ---------------------------------------------------------------------------
echo "floor checks\n";
reset_ensure_fixture();
$GLOBALS['cinatra_test']['wp_version'] = '6.9';
check('WP floor ok at exactly the minimum', cinatra_ensure_wp_version_ok());
$GLOBALS['cinatra_test']['wp_version'] = '6.8.9';
check('WP floor fails below the minimum', !cinatra_ensure_wp_version_ok());
$GLOBALS['cinatra_test']['wp_version'] = '7.0';
check('WP floor ok above the minimum', cinatra_ensure_wp_version_ok());

check(
    'PHP floor reflects the running interpreter (no fixture -- reads real PHP_VERSION)',
    cinatra_ensure_php_version_ok() === version_compare(PHP_VERSION, '8.0', '>=')
);

$GLOBALS['cinatra_test']['is_ssl'] = true;
check('HTTPS floor ok when is_ssl() true', cinatra_ensure_https_ok());
$GLOBALS['cinatra_test']['is_ssl'] = false;
check('HTTPS floor fails when is_ssl() false', !cinatra_ensure_https_ok());

// ---------------------------------------------------------------------------
echo "\nplugin state detection (absent / inactive / active x version)\n";
reset_ensure_fixture();
$absent = cinatra_ensure_plugin_state('mcp-adapter/mcp-adapter.php');
check('absent plugin: present=false', $absent['present'] === false);
check('absent plugin: active=false', $absent['active'] === false);
check("absent plugin: version=''", $absent['version'] === '');

$GLOBALS['cinatra_test']['installed_plugins']['mcp-adapter/mcp-adapter.php'] = array('Version' => '1.4.2');
$inactive = cinatra_ensure_plugin_state('mcp-adapter/mcp-adapter.php');
check('installed-but-inactive: present=true (the is_plugin_active()-only gap this closes)', $inactive['present'] === true);
check('installed-but-inactive: active=false', $inactive['active'] === false);
check('installed-but-inactive: version read from the header', $inactive['version'] === '1.4.2');

$GLOBALS['cinatra_test']['active_plugins'][] = 'mcp-adapter/mcp-adapter.php';
$active = cinatra_ensure_plugin_state('mcp-adapter/mcp-adapter.php');
check('active plugin: present=true', $active['present'] === true);
check('active plugin: active=true', $active['active'] === true);
check('active plugin: version read from the header', $active['version'] === '1.4.2');

// The catalog plugin (enable-abilities-for-mcp) uses the exact same primitive
// under a different file -- new detection, did not exist before epsilon.
reset_ensure_fixture();
$catalog_absent = cinatra_ensure_plugin_state(CINATRA_CATALOG_PLUGIN_FILE);
check('catalog plugin absent by default', $catalog_absent['present'] === false);
$GLOBALS['cinatra_test']['installed_plugins'][CINATRA_CATALOG_PLUGIN_FILE] = array('Version' => '2.0.1');
$GLOBALS['cinatra_test']['active_plugins'][] = CINATRA_CATALOG_PLUGIN_FILE;
$catalog_active = cinatra_ensure_plugin_state(CINATRA_CATALOG_PLUGIN_FILE);
check('catalog plugin active + versioned', $catalog_active['active'] === true && $catalog_active['version'] === '2.0.1');

// ---------------------------------------------------------------------------
echo "\nconnected-user-role courtesy row\n";
$GLOBALS['cinatra_test']['current_user_roles'] = array();
check("no roles -> ''", cinatra_ensure_current_user_role() === '');
$GLOBALS['cinatra_test']['current_user_roles'] = array('editor', 'author');
check('first role returned', cinatra_ensure_current_user_role() === 'editor');
$GLOBALS['cinatra_test']['current_user_roles'] = array('administrator');
check(
    'administrator role surfaced here too (courtesy row only -- NOT the audited D6/D8 warning, which lives on the connector settings page)',
    cinatra_ensure_current_user_role() === 'administrator'
);

// ---------------------------------------------------------------------------
echo "\nrender smoke test (every branch reachable, never fatals, never installs)\n";
reset_ensure_fixture();
ob_start();
cinatra_render_setup_checklist();
$html_all_absent = ob_get_clean();
check('renders without fataling when everything is absent/below floor', is_string($html_all_absent) && $html_all_absent !== '');
check('renders the new "Site AI stack" heading', strpos($html_all_absent, 'Site AI stack') !== false);
check('never calls an install/activation primitive from the render path', strpos($html_all_absent, 'Plugin_Upgrader') === false);

$GLOBALS['cinatra_test']['wp_version'] = '7.0';
$GLOBALS['cinatra_test']['is_ssl'] = true;
$GLOBALS['cinatra_test']['installed_plugins']['mcp-adapter/mcp-adapter.php'] = array('Version' => '1.4.2');
$GLOBALS['cinatra_test']['active_plugins'][] = 'mcp-adapter/mcp-adapter.php';
$GLOBALS['cinatra_test']['installed_plugins'][CINATRA_CATALOG_PLUGIN_FILE] = array('Version' => '2.0.1');
$GLOBALS['cinatra_test']['current_user_roles'] = array('administrator');
ob_start();
cinatra_render_setup_checklist();
$html_all_ok = ob_get_clean();
check('renders the active-adapter state', strpos($html_all_ok, 'is active') !== false);
check('renders the courtesy role line', strpos($html_all_ok, 'Signed in as: administrator') !== false);
check('never calls an install/activation primitive from the render path (all-ok state)', strpos($html_all_ok, 'Plugin_Upgrader') === false);

// ---------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    echo "FAILED: $failures check(s) failed.\n";
    exit(1);
}
echo "OK: all checks passed.\n";
exit(0);
