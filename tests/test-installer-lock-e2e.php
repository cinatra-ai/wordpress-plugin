<?php
/**
 * Isolated end-to-end test: a held install-lock short-circuits a second
 * concurrent form submit BEFORE any download happens (cinatra-ai/cinatra#2021
 * S6, PR zeta, design D3 "install lock/transient against double-click
 * races", §10 acceptance map "double-click/lock test").
 *
 * Runs in its OWN process (`php tests/test-installer-lock-e2e.php`) because
 * the real admin_post_cinatra_install_mcp_adapter handler ends every request
 * via cinatra_connect_redirect_to_settings() -> wp_safe_redirect() + a REAL,
 * unconditional PHP `exit;` -- correct production behavior, but fatal to a
 * shared test file with assertions after it (see tests/test-installer.php's
 * file header for the full reasoning). A shutdown function runs the
 * assertions and sets the final exit code AFTER that exit -- PHP shutdown
 * functions still run, and can still override the process's exit code, even
 * after a bare `exit;` (verified empirically before relying on it here).
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/installer-test-stubs.php';
require dirname(__DIR__) . '/cinatra.php';

$GLOBALS['cinatra_test']['caps']             = ['install_plugins' => true, 'activate_plugins' => true];
$GLOBALS['cinatra_test']['current_user_id']  = 7;

// Simulate an in-flight first request that already holds the lock.
cinatra_installer_acquire_lock('mcp_adapter');

$fixture = make_fixture_file('must never be read -- the lock should short-circuit before any download');
$GLOBALS['cinatra_test']['installer']['download_result'] = $fixture;

$failures = 0;
register_shutdown_function(function () use ($fixture, &$failures) {
    $check = function ($label, $cond) use (&$failures) {
        if ($cond) {
            echo "  PASS  $label\n";
        } else {
            echo "  FAIL  $label\n";
            $failures++;
        }
    };

    $check(
        'a second submit while the lock is held never calls download_url',
        $GLOBALS['cinatra_test']['installer']['download_url_calls'] === []
    );
    $audit_log = $GLOBALS['cinatra_test']['options'][CINATRA_INSTALLER_AUDIT_LOG_OPTION] ?? [];
    $check(
        'the locked outcome is audit-logged',
        !empty($audit_log) && end($audit_log)['outcome'] === 'locked'
    );
    $result_key = 'cinatra_installer_result_' . $GLOBALS['cinatra_test']['current_user_id'];
    $check(
        'an error notice is queued for the admin',
        ($GLOBALS['cinatra_test']['transients'][$result_key]['type'] ?? null) === 'error'
    );

    @unlink($fixture);

    if ($failures > 0) {
        echo "\n$failures check(s) FAILED\n";
        exit(1);
    }
    echo "\nAll checks passed.\n";
    exit(0);
});

cinatra_test_do_action('admin_post_cinatra_install_mcp_adapter');

// If we ever reach here, the handler didn't redirect+exit as expected --
// that's itself a finding (the "never automatic" / always-terminates
// invariant would be broken), so make it loud rather than silently exiting 0.
echo "FAIL  the handler should have exited via cinatra_connect_redirect_to_settings() but returned normally\n";
exit(1);
