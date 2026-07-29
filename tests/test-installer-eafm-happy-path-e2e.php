<?php
/**
 * Isolated end-to-end test: the wordpress.org catalog (Abilities) plugin
 * install happy path, in full, through the real admin-post handler
 * (cinatra-ai/cinatra#2021 S6, PR zeta, design §10 "happy-path test").
 *
 * This flow has NO checksum step (D3: wordpress.org's own signed-transport
 * infrastructure is the trust anchor for the catalog install, not this
 * plugin), so -- unlike the MCP Adapter side-load -- its happy path carries
 * no cryptographic preimage constraint and CAN be exercised fully end-to-end.
 *
 * Runs in its OWN process (`php tests/test-installer-eafm-happy-path-e2e.php`)
 * for the same reason as tests/test-installer-lock-e2e.php: a successful run
 * falls through to cinatra_connect_redirect_to_settings()'s real,
 * unconditional `exit;`. A shutdown function runs the assertions and sets
 * the final exit code after that exit.
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/installer-test-stubs.php';
require dirname(__DIR__) . '/cinatra.php';

$GLOBALS['cinatra_test']['caps']            = ['install_plugins' => true, 'activate_plugins' => true];
$GLOBALS['cinatra_test']['current_user_id'] = 7;

$GLOBALS['cinatra_test']['installer']['plugins_api_result'] = (object) [
    'slug'          => 'enable-abilities-for-mcp',
    'download_link' => 'https://downloads.wordpress.org/plugin/enable-abilities-for-mcp.2.0.20.zip',
];
$GLOBALS['cinatra_test']['installer']['install_result']      = true;
$GLOBALS['cinatra_test']['installer']['plugin_info_result']  = 'enable-abilities-for-mcp/enable-abilities-for-mcp.php';
$GLOBALS['cinatra_test']['installer']['activate_result']     = null; // WP core: null = activate_plugin() success.

// Simulate the admin opting in to activation on the same form submit (the
// handler reads this directly from $_POST -- see cinatra.php's
// admin_post_cinatra_install_eafm handler).
$_POST['cinatra_install_activate'] = '1';

$failures = 0;
register_shutdown_function(function () use (&$failures) {
    $check = function ($label, $cond) use (&$failures) {
        if ($cond) {
            echo "  PASS  $label\n";
        } else {
            echo "  FAIL  $label\n";
            $failures++;
        }
    };

    $check(
        'Plugin_Upgrader was called with the wp.org download_link (not a local path)',
        $GLOBALS['cinatra_test']['installer']['install_calls'] === ['https://downloads.wordpress.org/plugin/enable-abilities-for-mcp.2.0.20.zip']
    );
    $check(
        'the hardcoded catalog slug was requested from plugins_api(), never user input',
        ($GLOBALS['cinatra_test']['installer']['plugins_api_calls'][0]['slug'] ?? null) === CINATRA_INSTALLER_EAFM_SLUG
    );
    $check(
        'activate_plugin was called (with the resolved plugin file) after a successful install',
        $GLOBALS['cinatra_test']['installer']['activate_calls'] === ['enable-abilities-for-mcp/enable-abilities-for-mcp.php']
    );
    $audit_log = $GLOBALS['cinatra_test']['options'][CINATRA_INSTALLER_AUDIT_LOG_OPTION] ?? [];
    $check(
        'the success outcome is audit-logged',
        !empty($audit_log) && end($audit_log)['outcome'] === 'success'
    );
    $result_key = 'cinatra_installer_result_' . $GLOBALS['cinatra_test']['current_user_id'];
    $check(
        'a success notice is queued for the admin',
        ($GLOBALS['cinatra_test']['transients'][$result_key]['type'] ?? null) === 'success'
    );
    $check(
        'the install lock was released (not left held) after a successful run',
        cinatra_installer_acquire_lock('eafm') === true
    );

    if ($failures > 0) {
        echo "\n$failures check(s) FAILED\n";
        exit(1);
    }
    echo "\nAll checks passed.\n";
    exit(0);
});

cinatra_test_do_action('admin_post_cinatra_install_eafm');

echo "FAIL  the handler should have exited via cinatra_connect_redirect_to_settings() but returned normally\n";
exit(1);
