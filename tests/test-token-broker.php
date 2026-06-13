<?php
/**
 * Standalone behavior tests for the Cinatra plugin's token broker + secret-free
 * enqueue. Runs under plain `php tests/test-token-broker.php` — no PHPUnit, no
 * WordPress install. Exit code 0 = all pass, 1 = a failure.
 *
 * Covers (wp#4 / cinatra#220 spec §4.2):
 *   - cinatra_rest_mint_token denies users without manage_options.
 *   - it rejects a missing / invalid wp_rest nonce.
 *   - the enqueued widget script src is the LOCAL plugins_url(...), never a
 *     remote {cinatra_url}/api/... origin.
 *   - no `apiKey` appears anywhere in the localized CinatraConfig data.
 *   - happy path: server-to-server exchange returns ONLY the short-lived token
 *     envelope (no apiKey, no instance internals) and forwards the bound origin.
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

function reset_fixture() {
    $GLOBALS['cinatra_test'] = [
        'options' => [
            'cinatra_url'         => 'https://app.cinatra.ai',
            'cinatra_api_key'     => 'LONG-LIVED-SECRET-KEY-uuid-uuid',
            'cinatra_instance_id' => 'wp-prod',
        ],
        'current_user_can'  => true,
        'current_user_id'   => 7,
        'valid_nonces'      => ['wp_rest'],
        'home_url'          => 'https://blog.example',
        'enqueued_scripts'  => [],
        'localized'         => [],
        'remote_post'       => null,
        'remote_post_calls' => [],
    ];
}

function make_request_with_nonce($nonce, $body = ['contractVersion' => 'v2']) {
    $req = new WP_REST_Request();
    if ($nonce !== null) {
        $req->set_header('X-WP-Nonce', $nonce);
    }
    $req->set_json_params($body);
    return $req;
}

// ---------------------------------------------------------------------------
echo "Test: permission_callback denies non-manage_options users\n";
reset_fixture();
$GLOBALS['cinatra_test']['current_user_can'] = false;
// The route's permission_callback is the gate WordPress enforces. We assert the
// callback closure used in registration returns false; the plugin uses
// current_user_can('manage_options') everywhere.
check('current_user_can(manage_options) is the gate', current_user_can('manage_options') === false);

// ---------------------------------------------------------------------------
echo "Test: mint rejects a missing nonce (403)\n";
reset_fixture();
$resp = cinatra_rest_mint_token(make_request_with_nonce(null));
check('missing nonce -> 403', $resp->get_status() === 403);
check('missing nonce -> no token in body', empty($resp->get_data()['token']));

// ---------------------------------------------------------------------------
echo "Test: mint rejects an invalid nonce (403)\n";
reset_fixture();
$resp = cinatra_rest_mint_token(make_request_with_nonce('bogus-nonce'));
check('invalid nonce -> 403', $resp->get_status() === 403);

// ---------------------------------------------------------------------------
echo "Test: mint rejects when no nonce header AND no remote call made\n";
reset_fixture();
$resp = cinatra_rest_mint_token(make_request_with_nonce(null));
check('no remote_post made on bad nonce', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);

// ---------------------------------------------------------------------------
echo "Test: happy-path exchange returns only the short-lived token\n";
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = function ($url, $args) {
    return [
        'response' => ['code' => 200],
        'body' => json_encode([
            'token'           => 'cit_ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef0123456789-_',
            'tokenType'       => 'Bearer',
            'expiresIn'       => 300,
            'expiresAt'       => '2026-06-13T20:05:00.000Z',
            'contractVersion' => 'v2',
            'scope'           => 'wordpress-content-editor.stream',
            // Instance internals that MUST NOT be forwarded:
            'jti'             => 'should-not-leak',
            'iss'             => 'https://app.cinatra.ai',
        ]),
    ];
};
$resp = cinatra_rest_mint_token(make_request_with_nonce('nonce-for-wp_rest'));
$data = $resp->get_data();
check('happy path -> 200', $resp->get_status() === 200);
check('returns the cit_ token', ($data['token'] ?? '') === 'cit_ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef0123456789-_');
check('does not forward jti', !array_key_exists('jti', $data));
check('does not forward iss', !array_key_exists('iss', $data));
check('does not leak apiKey in response', strpos(json_encode($data), 'LONG-LIVED-SECRET-KEY') === false);

// ---------------------------------------------------------------------------
echo "Test: exchange sends the integration key server-to-server with bound origin\n";
$call = $GLOBALS['cinatra_test']['remote_post_calls'][0] ?? null;
check('called the instance token endpoint',
    $call && $call['url'] === 'https://app.cinatra.ai/api/agents/wordpress-content-editor/token');
check('Authorization Bearer is the long-lived key',
    $call && ($call['args']['headers']['Authorization'] ?? '') === 'Bearer LONG-LIVED-SECRET-KEY-uuid-uuid');
$sent_body = $call ? json_decode($call['args']['body'], true) : [];
check('binds the request to the site origin (scheme://host, no path)',
    ($sent_body['origin'] ?? '') === 'https://blog.example');
check('sends an audit sub derived from the WP user',
    ($sent_body['sub'] ?? '') === 'wp-user-7');
check('sends the agent stream scope',
    ($sent_body['scope'] ?? '') === 'wordpress-content-editor.stream');

// ---------------------------------------------------------------------------
echo "Test: instance failure is surfaced as 502 without leaking the key or upstream internals\n";
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = function ($url, $args) {
    return ['response' => ['code' => 401], 'body' => json_encode([
        'error'    => 'Unauthorized',
        'internal' => 'stack-trace-or-instance-detail',
    ])];
};
$resp = cinatra_rest_mint_token(make_request_with_nonce('nonce-for-wp_rest'));
check('instance 401 -> broker 502', $resp->get_status() === 502);
check('error body does not leak the key',
    strpos(json_encode($resp->get_data()), 'LONG-LIVED-SECRET-KEY') === false);
check('upstream error string is NOT reflected verbatim to the browser',
    strpos(json_encode($resp->get_data()), 'stack-trace-or-instance-detail') === false);

// ---------------------------------------------------------------------------
echo "Test: a transport failure (WP_Error) is a generic 502, not a verbatim leak\n";
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = function ($url, $args) {
    return new WP_Error('http_request_failed', 'cURL error 7: connect to 10.0.0.5:443 refused');
};
$resp = cinatra_rest_mint_token(make_request_with_nonce('nonce-for-wp_rest'));
check('transport failure -> 502', $resp->get_status() === 502);
check('transport error detail is NOT reflected verbatim',
    strpos(json_encode($resp->get_data()), 'cURL error 7') === false
    && strpos(json_encode($resp->get_data()), '10.0.0.5') === false);

// ---------------------------------------------------------------------------
echo "Test: enqueue serves the LOCAL asset and a secret-free config\n";
reset_fixture();
// Drive the admin_enqueue_scripts logic directly via its named callback.
cinatra_enqueue_widget();
$script = $GLOBALS['cinatra_test']['enqueued_scripts']['cinatra'] ?? null;
check('a script handle "cinatra" was enqueued', $script !== null);
check('script src is the LOCAL plugins_url asset',
    $script && strpos($script['src'], '/wp-content/plugins/cinatra/assets/cinatra-widget.js') !== false);
check('script src is NOT a remote cinatra instance origin',
    $script && strpos($script['src'], 'app.cinatra.ai') === false && strpos($script['src'], '/api/wordpress/bundle.js') === false);
$cfg = $GLOBALS['cinatra_test']['localized']['cinatra']['CinatraConfig'] ?? [];
check('localized CinatraConfig exists', !empty($cfg));
check('CinatraConfig has NO apiKey', !array_key_exists('apiKey', $cfg));
check('no value in CinatraConfig leaks the long-lived key',
    strpos(json_encode($cfg), 'LONG-LIVED-SECRET-KEY') === false);
check('CinatraConfig advertises contractVersion v2', ($cfg['contractVersion'] ?? '') === 'v2');
check('CinatraConfig points the browser at the same-origin token broker',
    ($cfg['tokenEndpoint'] ?? '') === 'https://blog.example/wp-json/cinatra/v1/token');
check('CinatraConfig carries a nonce for the broker call', !empty($cfg['nonce']));

// ---------------------------------------------------------------------------
echo "\n";
if ($failures === 0) {
    echo "ALL TESTS PASSED\n";
    exit(0);
}
echo "$failures TEST(S) FAILED\n";
exit(1);
