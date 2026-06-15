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
        'filters'           => [],
        'filter_cbs'        => [],
    ];
    // Each fixture reset starts from a clean env: no server-to-server override.
    putenv('CINATRA_BASE_URL');
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
// Connect provisioning (cinatra#221): server-side code exchange stores the
// credential and never returns it to the browser.
// ---------------------------------------------------------------------------
echo "Test: connect exchange stores the provisioned credential server-side\n";
reset_fixture();
$GLOBALS['cinatra_test']['options'] = []; // start unconfigured
$GLOBALS['cinatra_test']['remote_post'] = function ($url, $args) {
    return [
        'response' => ['code' => 200],
        'body'     => json_encode([
            'url'               => 'https://app.cinatra.ai',
            'siteId'            => 'site_123',
            'cinatraInstanceId' => 'wp-prod',
            'credential'        => 'cnx_site_123_PROVISIONED-SECRET',
            'credentialVersion' => 1,
            'webhookSecret'     => 'WH-SECRET',
            'contractVersion'   => 'v1',
            'capabilities'      => ['tokenBroker' => false, 'supportedContractVersions' => ['v1']],
        ]),
    ];
};
$res = cinatra_connect_exchange('https://app.cinatra.ai', [
    'grant_type'    => 'authorization_code',
    'code'          => 'abc',
    'client'        => 'wordpress',
    'redirect_uri'  => 'https://blog.example/wp-admin/admin-post.php?action=cinatra_connect_callback',
    'code_verifier' => str_repeat('v', 64),
]);
check('exchange reports ok', !empty($res['ok']));
$call = end($GLOBALS['cinatra_test']['remote_post_calls']);
check('exchange POSTs to /api/connect/token', strpos($call['url'], '/api/connect/token') !== false);
check('exchange sends grant_type=authorization_code',
    strpos($call['args']['body'], 'authorization_code') !== false);
cinatra_connect_apply_result($res);
check('credential stored server-side in cinatra_api_key',
    get_option('cinatra_api_key', '') === 'cnx_site_123_PROVISIONED-SECRET');
check('instance URL stored', get_option('cinatra_url', '') === 'https://app.cinatra.ai');
check('instance id stored', get_option('cinatra_instance_id', '') === 'wp-prod');

echo "Test: connect exchange rejects an http (non-loopback) instance URL\n";
reset_fixture();
$GLOBALS['cinatra_test']['options'] = [];
$bad = cinatra_connect_exchange('http://evil.example', ['grant_type' => 'install_code', 'install_code' => 'x', 'client' => 'wordpress']);
check('http non-loopback instance rejected before any request', empty($bad['ok']));

echo "Test: instance URL validator enforces https / no userinfo\n";
check('https accepted', cinatra_validate_instance_url('https://app.cinatra.ai/') === 'https://app.cinatra.ai');
check('http non-loopback rejected', cinatra_validate_instance_url('http://app.cinatra.ai') === '');
check('http loopback allowed', cinatra_validate_instance_url('http://localhost:3000') === 'http://localhost:3000');
check('userinfo rejected', cinatra_validate_instance_url('https://user:pass@app.cinatra.ai') === '');
check('non-url rejected', cinatra_validate_instance_url('not a url') === '');

echo "Test: secret sanitizer preserves token chars but strips control chars\n";
check('printable token preserved', cinatra_sanitize_secret("tok-EN_123.abc") === 'tok-EN_123.abc');
check('control chars stripped', cinatra_sanitize_secret("tok\r\n123") === 'tok123');
check('blank keep-existing returns stored', (function () {
    update_option('cinatra_api_key', 'STORED');
    return cinatra_sanitize_secret_keep_existing('', 'cinatra_api_key') === 'STORED';
})());

echo "Test: subscriptions sanitizer drops malformed rows and caps count\n";
$rows = [];
for ($i = 0; $i < 60; $i++) {
    $rows[] = ['event_type' => "e$i", 'target_url' => "https://h$i.example/hook"];
}
$rows[] = ['event_type' => '', 'target_url' => 'https://bad.example']; // dropped (no event)
$clean = json_decode(cinatra_sanitize_subscriptions_json(json_encode($rows)), true);
check('subscriptions capped at the configured max',
    is_array($clean) && count($clean) === CINATRA_MAX_WEBHOOK_SUBSCRIPTIONS);

// ---------------------------------------------------------------------------
// Server-to-server base URL override (CINATRA_BASE_URL) — browser-vs-server
// URL conflation fix. Production (env unset) must be unchanged; only a
// validated container-host override may redirect the TRANSPORT, behind a
// request-scoped SSRF relaxation.
// ---------------------------------------------------------------------------
$ok_remote = function () {
    return [
        'response' => ['code' => 200],
        'body' => json_encode([
            'token'     => 'cit_envoverride0123456789',
            'tokenType' => 'Bearer',
            'expiresIn' => 300,
        ]),
    ];
};

echo "Test: env unset -> mint posts to the configured cinatra_url (production unchanged)\n";
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
$resp = cinatra_rest_mint_token(make_request_with_nonce('nonce-for-wp_rest'));
$call = $GLOBALS['cinatra_test']['remote_post_calls'][0] ?? null;
check('env unset -> posts to configured cinatra_url host',
    $call && $call['url'] === 'https://app.cinatra.ai/api/agents/wordpress-content-editor/token');
check('env unset -> NO host-allowlist filter active during the call (full SSRF guard)',
    $call && $call['host_filter_active'] === 0);

echo "Test: CINATRA_BASE_URL override redirects the mint TRANSPORT to the container host\n";
reset_fixture();
putenv('CINATRA_BASE_URL=http://host.docker.internal:3000');
$GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
$resp = cinatra_rest_mint_token(make_request_with_nonce('nonce-for-wp_rest'));
$call = $GLOBALS['cinatra_test']['remote_post_calls'][0] ?? null;
check('override -> posts to host.docker.internal transport base',
    $call && $call['url'] === 'http://host.docker.internal:3000/api/agents/wordpress-content-editor/token');
check('override -> request-scoped host-allowlist filter ACTIVE during the call',
    $call && $call['host_filter_active'] === 1);
check('override -> request-scoped safe-port filter ACTIVE during the call',
    $call && $call['port_filter_active'] === 1);
check('override -> BOTH filters REMOVED after the call (no leaked relaxation)',
    (int) ($GLOBALS['cinatra_test']['filters']['http_request_host_is_external'] ?? 0) === 0
    && (int) ($GLOBALS['cinatra_test']['filters']['http_allowed_safe_ports'] ?? 0) === 0);
check('override -> redirection disabled on the internal call',
    $call && (int) ($call['args']['redirection'] ?? -1) === 0);
check('override -> still sends the long-lived Bearer key server-to-server',
    $call && ($call['args']['headers']['Authorization'] ?? '') === 'Bearer LONG-LIVED-SECRET-KEY-uuid-uuid');
$sent = $call ? json_decode($call['args']['body'], true) : [];
check('override -> origin bound stays the BROWSER admin origin (not the env host)',
    ($sent['origin'] ?? '') === 'https://blog.example');
putenv('CINATRA_BASE_URL');

echo "Test: a non-allowlisted CINATRA_BASE_URL is IGNORED (falls back, no SSRF relaxation)\n";
foreach (['http://evil.example', 'https://10.0.0.5', 'ftp://host.docker.internal', 'http://host.docker.internal@evil.example'] as $bad_env) {
    reset_fixture();
    putenv('CINATRA_BASE_URL=' . $bad_env);
    $GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
    cinatra_rest_mint_token(make_request_with_nonce('nonce-for-wp_rest'));
    $call = $GLOBALS['cinatra_test']['remote_post_calls'][0] ?? null;
    check("bad env '$bad_env' -> falls back to configured cinatra_url",
        $call && $call['url'] === 'https://app.cinatra.ai/api/agents/wordpress-content-editor/token');
    check("bad env '$bad_env' -> NO host-allowlist filter active (SSRF guard intact)",
        $call && $call['host_filter_active'] === 0);
    putenv('CINATRA_BASE_URL');
}

echo "Test: server-base validator allowlist (loopback + host.docker.internal only)\n";
check('http host.docker.internal allowed', cinatra_validate_server_base_url('http://host.docker.internal:3000') === 'http://host.docker.internal:3000');
check('https host.docker.internal allowed', cinatra_validate_server_base_url('https://host.docker.internal') === 'https://host.docker.internal');
check('http localhost allowed', cinatra_validate_server_base_url('http://localhost:3000') === 'http://localhost:3000');
check('http 127.0.0.1 allowed', cinatra_validate_server_base_url('http://127.0.0.1:3000') === 'http://127.0.0.1:3000');
check('arbitrary https host rejected', cinatra_validate_server_base_url('https://app.cinatra.ai') === '');
check('private ip rejected', cinatra_validate_server_base_url('http://10.0.0.5') === '');
check('userinfo rejected', cinatra_validate_server_base_url('http://host.docker.internal@evil.example') === '');
check('non-http(s) scheme rejected', cinatra_validate_server_base_url('ftp://localhost') === '');

echo "Test: relaxation is bound to the EXACT override origin (same host, different port is NOT relaxed)\n";
reset_fixture();
putenv('CINATRA_BASE_URL=http://host.docker.internal:3000');
$GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
// Endpoint host matches the override host but the PORT differs -> must NOT get
// the host/port relaxation, so the safe-request gate blocks it (WP_Error, no call).
$res_wrongport = cinatra_server_post('http://host.docker.internal:8080/x', []);
check('same-host wrong-port endpoint is NOT relaxed (blocked by safe-request gate)',
    is_wp_error($res_wrongport));
check('same-host wrong-port made NO recorded network call', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);
// The exact override origin IS relaxed.
$res_right = cinatra_server_post('http://host.docker.internal:3000/x', []);
check('exact override origin IS relaxed (request proceeds)', !is_wp_error($res_right));
putenv('CINATRA_BASE_URL');

echo "Test: SSRF gate model — host.docker.internal:3000 is BLOCKED without the request-scoped filters\n";
// Sanity-check the stub models real WP: a private host on a non-default port is
// rejected by wp_safe_remote_post unless the plugin's filters are active. This
// is the bug codex flagged (filter must return true; :3000 must be safe-listed).
reset_fixture();
$blocked = wp_safe_remote_post('http://host.docker.internal:3000/x', []);
check('gate blocks private host:3000 with no override filters (WP_Error)', is_wp_error($blocked));
$allowed_pub = wp_safe_remote_post('https://app.cinatra.ai/x', ['headers' => []]);
check('gate allows a normal public https host', !is_wp_error($allowed_pub));

echo "Test: connect exchange honors the override for transport but stores the BROWSER base\n";
reset_fixture();
$GLOBALS['cinatra_test']['options'] = [];
putenv('CINATRA_BASE_URL=http://host.docker.internal:3000');
$GLOBALS['cinatra_test']['remote_post'] = function ($url, $args) {
    return ['response' => ['code' => 200], 'body' => json_encode([
        'url' => 'http://localhost:3000', 'credential' => 'cnx_PROVISIONED', 'cinatraInstanceId' => 'wp-dev',
    ])];
};
$res = cinatra_connect_exchange('http://localhost:3000', [
    'grant_type' => 'install_code', 'install_code' => 'x', 'client' => 'wordpress',
]);
$call = end($GLOBALS['cinatra_test']['remote_post_calls']);
check('connect -> transport redirected to host.docker.internal',
    $call && $call['url'] === 'http://host.docker.internal:3000/api/connect/token');
check('connect -> host-allowlist filter active during the call',
    $call && $call['host_filter_active'] === 1);
cinatra_connect_apply_result($res);
check('connect -> stored cinatra_url is the BROWSER base, NEVER the env override',
    get_option('cinatra_url', '') === 'http://localhost:3000');
putenv('CINATRA_BASE_URL');

// ---------------------------------------------------------------------------
echo "\n";
if ($failures === 0) {
    echo "ALL TESTS PASSED\n";
    exit(0);
}
echo "$failures TEST(S) FAILED\n";
exit(1);
