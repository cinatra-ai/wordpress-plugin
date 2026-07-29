<?php
/**
 * Shared stubs for the one-click installer tests (cinatra-ai/cinatra#2021 S6,
 * PR zeta) that no other test file in this directory needs yet: wp_die
 * (installer's capability gate), check_admin_referer (nonce gate), and the
 * WP_Filesystem/Plugin_Upgrader/plugins_api/get_plugins/activate_plugin/
 * download_url install-flow primitives. All are real WordPress user-space
 * functions/classes (never PHP builtins), so cinatra.php's own
 * `if (!function_exists(...)) require_once ...` guards skip the real
 * require entirely once these are defined here -- exactly like the existing
 * is_plugin_active() stub already does for cinatra_mcp_adapter_active().
 *
 * Require wp-stubs.php BEFORE this file (for $GLOBALS['cinatra_test'] and
 * the WP_Error class), and cinatra.php AFTER it (so these functions exist
 * before cinatra.php's function_exists() guards check for them).
 */

$GLOBALS['cinatra_test']['installer'] = $GLOBALS['cinatra_test']['installer'] ?? [
    'nonce_checks'         => [],
    'nonce_fails'          => [],
    'download_url_calls'   => [],
    'download_result'      => null,
    'wp_filesystem_calls'  => 0,
    'wp_filesystem_result' => true,
    'install_calls'        => [],
    'install_result'       => true,
    'plugin_info_result'   => '',
    'plugins_api_calls'    => [],
    'plugins_api_result'   => null,
    'installed_plugins'    => [],
    'activate_calls'       => [],
    'activate_result'      => null,
];

class Cinatra_Test_WPDieException extends Exception {
    public $args;
    public function __construct($message, $args = []) {
        parent::__construct((string) $message);
        $this->args = $args;
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = []) {
        throw new Cinatra_Test_WPDieException($message, $args);
    }
}
if (!function_exists('check_admin_referer')) {
    function check_admin_referer($action = -1, $query_arg = '_wpnonce') {
        $GLOBALS['cinatra_test']['installer']['nonce_checks'][] = $action;
        if (!empty($GLOBALS['cinatra_test']['installer']['nonce_fails'][$action])) {
            wp_die('Nonce check failed.', '', ['response' => 403]);
        }
        return 1;
    }
}
if (!function_exists('get_temp_dir')) {
    function get_temp_dir() {
        return rtrim(sys_get_temp_dir(), '/\\') . '/';
    }
}
if (!function_exists('wp_delete_file')) {
    function wp_delete_file($file) {
        if (is_string($file) && $file !== '' && file_exists($file)) {
            unlink($file);
        }
    }
}
if (!function_exists('wp_safe_redirect')) {
    // Record-only stub. The real `exit;` that follows this call inside
    // cinatra_connect_redirect_to_settings() is a bare language construct in
    // cinatra.php itself and cannot be intercepted here -- see the two
    // isolated E2E test files for how tests that reach it handle that.
    function wp_safe_redirect($location, $status = 302) {
        $GLOBALS['cinatra_test']['installer']['redirect_calls'][] = $location;
        return true;
    }
}
if (!function_exists('download_url')) {
    function download_url($url) {
        $GLOBALS['cinatra_test']['installer']['download_url_calls'][] = $url;
        $result = $GLOBALS['cinatra_test']['installer']['download_result'] ?? null;
        if ($result instanceof WP_Error) {
            return $result;
        }
        return (string) $result;
    }
}
if (!function_exists('WP_Filesystem')) {
    function WP_Filesystem() {
        $GLOBALS['cinatra_test']['installer']['wp_filesystem_calls'] =
            ($GLOBALS['cinatra_test']['installer']['wp_filesystem_calls'] ?? 0) + 1;
        return $GLOBALS['cinatra_test']['installer']['wp_filesystem_result'] ?? true;
    }
}
if (!class_exists('Automatic_Upgrader_Skin')) {
    class Automatic_Upgrader_Skin {
        public function __construct() {}
    }
}
if (!class_exists('Plugin_Upgrader')) {
    class Plugin_Upgrader {
        public function __construct($skin = null) {}
        public function install($package) {
            $GLOBALS['cinatra_test']['installer']['install_calls'][] = $package;
            return $GLOBALS['cinatra_test']['installer']['install_result'] ?? true;
        }
        public function plugin_info() {
            return (string) ($GLOBALS['cinatra_test']['installer']['plugin_info_result'] ?? '');
        }
    }
}
if (!function_exists('plugins_api')) {
    function plugins_api($action, $args = []) {
        $GLOBALS['cinatra_test']['installer']['plugins_api_calls'][] = $args;
        return $GLOBALS['cinatra_test']['installer']['plugins_api_result'] ?? new WP_Error('default_error', 'no fixture result set');
    }
}
if (!function_exists('get_plugins')) {
    function get_plugins() {
        return $GLOBALS['cinatra_test']['installer']['installed_plugins'] ?? [];
    }
}
if (!function_exists('activate_plugin')) {
    function activate_plugin($plugin_file) {
        $GLOBALS['cinatra_test']['installer']['activate_calls'][] = $plugin_file;
        return $GLOBALS['cinatra_test']['installer']['activate_result'] ?? null; // WP core: null = success.
    }
}

/** Make a small real temp fixture file with the given content; returns its path. */
function make_fixture_file($content = 'cinatra-test-fixture-bytes') {
    $path = tempnam(sys_get_temp_dir(), 'cinatra-installer-test-');
    file_put_contents($path, $content);
    return $path;
}
