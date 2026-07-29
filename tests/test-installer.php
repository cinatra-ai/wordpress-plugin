<?php
/**
 * Standalone behavior tests for the one-click plugin installer
 * (cinatra-ai/cinatra#2021 S6, PR zeta). Runs under plain
 * `php tests/test-installer.php` -- no PHPUnit, no WordPress install. Exit
 * code 0 = all pass, 1 = a failure.
 *
 * A NOTE ON WHAT "happy path" COVERAGE MEANS HERE (read this before adding a
 * test): the MCP Adapter flow's checksum step compares against a REAL,
 * production sha256 pin (CINATRA_MCP_ADAPTER_PIN_SHA256) baked from the
 * actual released ZIP. SHA-256 is one-way by design, so no test fixture
 * content can be crafted whose hash equals that real value (finding a
 * preimage is cryptographically infeasible) -- and this test suite
 * deliberately does NOT fetch the real release artifact over the network to
 * get one (these tests must stay hermetic, matching every other file in this
 * directory). The checksum-MISMATCH path is therefore what gets a full
 * end-to-end test against the real handler (below) -- which is also the
 * more security-critical property to prove deterministically. The
 * checksum-MATCH mechanism, and everything that happens AFTER a checksum
 * passes (install / post-install identity check / activation), are each
 * covered independently at the smaller, purpose-built seams
 * (cinatra_installer_verify_checksum(), cinatra_installer_finish_install())
 * using self-consistent fixture content -- together these prove the same
 * properties an end-to-end "happy path" test would, without the impossible
 * cryptographic requirement. The eafm (wordpress.org catalog) flow has NO
 * checksum step at all, so it IS covered fully end-to-end, happy path
 * included.
 *
 * Covers (design §3/§10 acceptance map):
 *   - checksum-mismatch-aborts (end-to-end, mcp_adapter handler)
 *   - re-hash independently re-detects a same-path content change
 *     (cinatra_installer_verify_checksum, called twice)
 *   - symlink / realpath-escape rejection (cinatra_installer_path_is_safe)
 *   - post-install identity-mismatch (cinatra_installer_finish_install)
 *   - capability-gate-at-handler (current_user_can re-checked in the handler,
 *     not just at render)
 *   - install-lock acquire/release (double-click guard mechanism)
 *   - eafm catalog flow: plugins_api() failure, end-to-end
 *   - install-succeeds-but-identity-matches / activation success and failure
 *     (cinatra_installer_finish_install)
 *
 * NOT in this file (see the two dedicated isolated-process test files
 * instead): a full end-to-end double-click test and a full end-to-end eafm
 * happy-path test. Both of those scenarios fall through to
 * cinatra_connect_redirect_to_settings(), which calls a REAL, unconditional
 * PHP `exit;` after redirecting -- correct production behavior (every
 * admin-post handler must end the request), but fatal to a shared test file
 * with more assertions after it. See tests/test-installer-lock-e2e.php and
 * tests/test-installer-eafm-happy-path-e2e.php, each run in its own process
 * (a shutdown function runs their assertions and sets the final exit code
 * after the real exit -- PHP shutdown functions still run, and can still
 * override the process's exit code, even after a bare `exit;`).
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/installer-test-stubs.php';
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

function reset_fixture() {
    $GLOBALS['cinatra_test'] = [
        'options'            => [],
        'caps'               => ['install_plugins' => true, 'activate_plugins' => true],
        'current_user_can'   => true,
        'current_user_id'    => 7,
        'valid_nonces'       => [],
        'home_url'           => 'https://blog.example',
        'transients'         => [],
        'active_plugins'     => [],
        'installer'          => [
            'nonce_checks'        => [],
            'nonce_fails'         => [],
            'download_url_calls'  => [],
            'download_result'     => null,
            'wp_filesystem_calls' => 0,
            'wp_filesystem_result'=> true,
            'install_calls'       => [],
            'install_result'      => true,
            'plugin_info_result'  => '',
            'plugins_api_calls'   => [],
            'plugins_api_result'  => null,
            'installed_plugins'   => [],
            'activate_calls'      => [],
            'activate_result'     => null,
        ],
    ];
}

// make_fixture_file() is defined in installer-test-stubs.php (shared with the
// two isolated E2E test files).

// ---------------------------------------------------------------------------
// cinatra_installer_path_is_safe()
// ---------------------------------------------------------------------------
reset_fixture();
$safe_path = make_fixture_file();
check('a real temp file inside the temp dir is safe', cinatra_installer_path_is_safe($safe_path) === true);

$link_path = $safe_path . '-link';
if (@symlink($safe_path, $link_path)) {
    check('a symlinked temp path is rejected', cinatra_installer_path_is_safe($link_path) === false);
    unlink($link_path);
} else {
    // Some sandboxes disallow symlink() (e.g. restrictive filesystem perms);
    // do not fail the suite over an environment limitation, but do not
    // silently skip a security-relevant assertion either -- surface it.
    echo "  SKIP  symlink rejection (symlink() unavailable in this environment)\n";
}

$outside_path = __FILE__; // Real file, but NOT inside the (stubbed) temp dir.
check('a path outside the temp dir is rejected', cinatra_installer_path_is_safe($outside_path) === false);
check('a nonexistent path is rejected', cinatra_installer_path_is_safe($safe_path . '-does-not-exist') === false);
unlink($safe_path);

// ---------------------------------------------------------------------------
// cinatra_installer_verify_checksum() -- match, mismatch, and the re-hash
// property (same function called twice must independently re-detect a
// content change, never cache the first call's result).
// ---------------------------------------------------------------------------
reset_fixture();
$fixture_path = make_fixture_file('known-good-bytes');
$real_hash    = hash_file('sha256', $fixture_path);

check('verify_checksum: matches its own real hash', cinatra_installer_verify_checksum($fixture_path, $real_hash) === true);
check('verify_checksum: rejects a wrong hash', cinatra_installer_verify_checksum($fixture_path, str_repeat('0', 64)) === false);
check('verify_checksum: rejects a nonexistent file', cinatra_installer_verify_checksum($fixture_path . '-nope', $real_hash) === false);

// The re-hash property: call verify_checksum against the SAME expected value
// twice, mutating the real file on disk in between -- simulating exactly the
// same-host race the second (D3 step 6) call exists to catch. The first call
// must pass; a FRESH call after the mutation must independently fail (not
// reuse/cached the first call's true).
check('verify_checksum call #1 (before mutation) passes', cinatra_installer_verify_checksum($fixture_path, $real_hash) === true);
file_put_contents($fixture_path, 'swapped-bytes-mid-race');
check('verify_checksum call #2 (after a same-path mutation) independently re-detects the change', cinatra_installer_verify_checksum($fixture_path, $real_hash) === false);
unlink($fixture_path);

// Injected-hasher form (used only by this test, never by production code) --
// exercises the exact "same path, two calls, differing underlying bytes"
// shape without relying on real filesystem timing. cinatra_installer_verify_checksum()
// requires the path to exist (file_exists()) before ever consulting the
// hasher, so this still needs a real file -- its CONTENT is irrelevant here
// since the injected hasher ignores it.
$hasher_fixture_path = make_fixture_file('content is irrelevant -- the hasher below is injected');
$calls = 0;
$flip_flop_hasher = function ($path) use (&$calls) {
    $calls++;
    return $calls === 1 ? 'hash-a' : 'hash-b';
};
check('injected-hasher call #1 matches', cinatra_installer_verify_checksum($hasher_fixture_path, 'hash-a', $flip_flop_hasher) === true);
check('injected-hasher call #2 (simulated swap) fails', cinatra_installer_verify_checksum($hasher_fixture_path, 'hash-a', $flip_flop_hasher) === false);
unlink($hasher_fixture_path);

// ---------------------------------------------------------------------------
// cinatra_installer_finish_install() -- no hashing, so no preimage
// constraint; this is where install/identity/activation coverage lives.
// ---------------------------------------------------------------------------
reset_fixture();
$GLOBALS['cinatra_test']['installer']['install_result'] = true;
$GLOBALS['cinatra_test']['installer']['installed_plugins'] = ['mcp-adapter/mcp-adapter.php' => ['Version' => '0.5.0']];
$result = cinatra_installer_finish_install('/tmp/fake-verified-path.zip', 'mcp-adapter/mcp-adapter.php', '0.5.0', true);
check('finish_install: success + identity match + activate -> ok, activated', $result['ok'] === true && 'activated' === $result['detail']);
check('finish_install: activate_plugin was called with the right plugin file', $GLOBALS['cinatra_test']['installer']['activate_calls'] === ['mcp-adapter/mcp-adapter.php']);
check('finish_install: the verified LOCAL PATH was handed to Plugin_Upgrader, never a URL', $GLOBALS['cinatra_test']['installer']['install_calls'] === ['/tmp/fake-verified-path.zip']);

reset_fixture();
$GLOBALS['cinatra_test']['installer']['install_result'] = true;
$GLOBALS['cinatra_test']['installer']['installed_plugins'] = ['mcp-adapter/mcp-adapter.php' => ['Version' => '9.9.9']]; // Mismatched.
$result = cinatra_installer_finish_install('/tmp/fake-verified-path.zip', 'mcp-adapter/mcp-adapter.php', '0.5.0', true);
check('finish_install: post-install identity mismatch -> not ok, identity_mismatch', $result['ok'] === false && 'identity_mismatch' === $result['outcome']);
check('finish_install: identity mismatch NEVER activates', $GLOBALS['cinatra_test']['installer']['activate_calls'] === []);

reset_fixture();
$GLOBALS['cinatra_test']['installer']['install_result'] = new WP_Error('destination_exists', 'Destination folder already exists.');
$result = cinatra_installer_finish_install('/tmp/x.zip', 'mcp-adapter/mcp-adapter.php', '0.5.0', true);
check('finish_install: Plugin_Upgrader install failure -> not ok, install_failed', $result['ok'] === false && 'install_failed' === $result['outcome']);
check('finish_install: install failure NEVER activates', $GLOBALS['cinatra_test']['installer']['activate_calls'] === []);

reset_fixture();
$GLOBALS['cinatra_test']['installer']['install_result'] = true;
$GLOBALS['cinatra_test']['installer']['plugin_info_result'] = 'enable-abilities-for-mcp/enable-abilities-for-mcp.php';
$GLOBALS['cinatra_test']['installer']['activate_result'] = null; // WP core success shape.
$result = cinatra_installer_finish_install('https://downloads.wordpress.org/plugin/x.zip', null, null, true);
check('finish_install: eafm flow (no expected_version) skips the identity check entirely', $result['ok'] === true && 'activated' === $result['detail']);
check('finish_install: eafm plugin_file resolved via Plugin_Upgrader::plugin_info() when not statically known', $result['plugin_file'] === 'enable-abilities-for-mcp/enable-abilities-for-mcp.php');

reset_fixture();
$GLOBALS['cinatra_test']['installer']['install_result'] = true;
$GLOBALS['cinatra_test']['installer']['plugin_info_result'] = 'enable-abilities-for-mcp/enable-abilities-for-mcp.php';
$GLOBALS['cinatra_test']['installer']['activate_result'] = new WP_Error('could_not_activate', 'nope');
$result = cinatra_installer_finish_install('https://downloads.wordpress.org/plugin/x.zip', null, null, true);
check('finish_install: activation failure after a successful install -> not ok, but plugin stays installed', $result['ok'] === false && false !== strpos($result['detail'], 'activate:'));

reset_fixture();
$GLOBALS['cinatra_test']['installer']['install_result'] = true;
$GLOBALS['cinatra_test']['installer']['plugin_info_result'] = 'enable-abilities-for-mcp/enable-abilities-for-mcp.php';
$result = cinatra_installer_finish_install('https://downloads.wordpress.org/plugin/x.zip', null, null, false);
check('finish_install: activate=false -> installed_only, activate_plugin never called', $result['ok'] === true && 'installed_only' === $result['detail'] && $GLOBALS['cinatra_test']['installer']['activate_calls'] === []);

// ---------------------------------------------------------------------------
// cinatra_installer_acquire_lock() / release_lock() -- double-click guard.
// ---------------------------------------------------------------------------
reset_fixture();
check('lock: first acquire succeeds', cinatra_installer_acquire_lock('mcp_adapter') === true);
check('lock: a second acquire while held fails (double-click is a no-op)', cinatra_installer_acquire_lock('mcp_adapter') === false);
cinatra_installer_release_lock('mcp_adapter');
check('lock: acquire succeeds again after release', cinatra_installer_acquire_lock('mcp_adapter') === true);
check('lock: the two flows do not share a lock key', cinatra_installer_acquire_lock('eafm') === true);

// ---------------------------------------------------------------------------
// cinatra_installer_require_capability() -- capability + nonce gate, RE-CHECKED
// at the handler (not inferred from render-time gating).
// ---------------------------------------------------------------------------
reset_fixture();
$GLOBALS['cinatra_test']['caps'] = ['install_plugins' => false, 'activate_plugins' => true];
try {
    cinatra_installer_require_capability('cinatra_install_mcp_adapter');
    check('capability gate: missing install_plugins throws (never silently proceeds)', false);
} catch (Cinatra_Test_WPDieException $e) {
    check('capability gate: missing install_plugins throws with a 403', ($e->args['response'] ?? null) === 403);
}

reset_fixture();
$GLOBALS['cinatra_test']['caps'] = ['install_plugins' => true, 'activate_plugins' => false];
try {
    cinatra_installer_require_capability('cinatra_install_mcp_adapter');
    check('capability gate: missing activate_plugins throws (BOTH caps required, not just install_plugins)', false);
} catch (Cinatra_Test_WPDieException $e) {
    check('capability gate: missing activate_plugins throws with a 403', ($e->args['response'] ?? null) === 403);
}

reset_fixture();
check('capability gate: both caps present passes through (no exception)', (function () {
    try {
        cinatra_installer_require_capability('cinatra_install_mcp_adapter');
        return true;
    } catch (Cinatra_Test_WPDieException $e) {
        return false;
    }
})());

// ---------------------------------------------------------------------------
// End-to-end: admin_post_cinatra_install_mcp_adapter -- checksum-mismatch
// aborts BEFORE any install call is reached (the core fail-closed property;
// see the file header for why this is the flow's end-to-end "happy path"
// counterpart instead of a checksum-match run).
// ---------------------------------------------------------------------------
reset_fixture();
$mismatch_fixture = make_fixture_file('definitely-not-the-real-mcp-adapter-zip-bytes');
$GLOBALS['cinatra_test']['installer']['download_result'] = $mismatch_fixture;
cinatra_test_do_action('admin_post_cinatra_install_mcp_adapter');
check('mcp_adapter E2E: a real-but-wrong file fails the checksum check', $GLOBALS['cinatra_test']['installer']['install_calls'] === []);
check('mcp_adapter E2E: checksum mismatch never calls Plugin_Upgrader::install()', empty($GLOBALS['cinatra_test']['installer']['install_calls']));
$installer_result_key = 'cinatra_installer_result_' . $GLOBALS['cinatra_test']['current_user_id'];
check('mcp_adapter E2E: an error notice is queued for the admin', ($GLOBALS['cinatra_test']['transients'][$installer_result_key]['type'] ?? null) === 'error');
$audit_log = $GLOBALS['cinatra_test']['options'][CINATRA_INSTALLER_AUDIT_LOG_OPTION] ?? [];
check('mcp_adapter E2E: the checksum-mismatch outcome is audit-logged', end($audit_log)['outcome'] === 'checksum_mismatch');
check('mcp_adapter E2E: the temp file was cleaned up on the failure exit path', !file_exists($mismatch_fixture));
check('mcp_adapter E2E: the lock was released (not left held) after the failure', cinatra_installer_acquire_lock('mcp_adapter') === true);
cinatra_installer_release_lock('mcp_adapter');

// download_url() itself failing (WP_Error, e.g. no outbound network access).
reset_fixture();
$GLOBALS['cinatra_test']['installer']['download_result'] = new WP_Error('http_request_failed', 'Could not resolve host.');
cinatra_test_do_action('admin_post_cinatra_install_mcp_adapter');
check('mcp_adapter E2E: a download failure never reaches the checksum step or Plugin_Upgrader', $GLOBALS['cinatra_test']['installer']['install_calls'] === []);
$audit_log = $GLOBALS['cinatra_test']['options'][CINATRA_INSTALLER_AUDIT_LOG_OPTION] ?? [];
check('mcp_adapter E2E: a download failure is audit-logged as download_failed', end($audit_log)['outcome'] === 'download_failed');

// Capability failure end-to-end (the handler's OWN re-check, not just render-time).
reset_fixture();
$GLOBALS['cinatra_test']['caps'] = ['install_plugins' => false, 'activate_plugins' => false];
$GLOBALS['cinatra_test']['installer']['download_result'] = make_fixture_file();
try {
    cinatra_test_do_action('admin_post_cinatra_install_mcp_adapter');
    check('mcp_adapter E2E: a non-capable user is refused at the handler (never reaches download_url)', false);
} catch (Cinatra_Test_WPDieException $e) {
    check('mcp_adapter E2E: a non-capable user is refused at the handler with a 403', ($e->args['response'] ?? null) === 403);
}
check('mcp_adapter E2E: download_url was never called for a non-capable user', $GLOBALS['cinatra_test']['installer']['download_url_calls'] === []);

// Double-click / full eafm happy-path E2E: see tests/test-installer-lock-e2e.php
// and tests/test-installer-eafm-happy-path-e2e.php -- both scenarios fall
// through to cinatra_connect_redirect_to_settings()'s real `exit;` (see the
// file header), so they run isolated rather than inline here.

// ---------------------------------------------------------------------------
// End-to-end: admin_post_cinatra_install_eafm -- the failure path returns
// early (never reaches the redirect/exit), so it's safe to run inline here.
// ---------------------------------------------------------------------------

// eafm: plugins_api() itself fails (e.g. wp.org unreachable) -- never reaches
// Plugin_Upgrader, hardcoded slug is the only thing ever sent.
reset_fixture();
$GLOBALS['cinatra_test']['installer']['plugins_api_result'] = new WP_Error('plugins_api_failed', 'Could not reach the API.');
cinatra_test_do_action('admin_post_cinatra_install_eafm');
check('eafm E2E: a plugins_api() failure never reaches Plugin_Upgrader', $GLOBALS['cinatra_test']['installer']['install_calls'] === []);
check('eafm E2E: the requested slug is ALWAYS the hardcoded catalog slug, never user input', $GLOBALS['cinatra_test']['installer']['plugins_api_calls'][0]['slug'] === CINATRA_INSTALLER_EAFM_SLUG);

if ($failures > 0) {
    echo "\n$failures check(s) FAILED\n";
    exit(1);
}
echo "\nAll checks passed.\n";
exit(0);
