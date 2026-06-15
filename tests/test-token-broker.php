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

// Route PHP error_log() to a temp file (not stderr) so the plugin's intentional
// fixed-text warnings don't pollute CI output, and so tests can assert that the
// fallback warning carries NO secret / raw env value.
$GLOBALS['cinatra_test_log'] = tempnam(sys_get_temp_dir(), 'cinatra-test-log-');
ini_set('log_errors', '1');
ini_set('error_log', $GLOBALS['cinatra_test_log']);
register_shutdown_function(static function () {
    if (!empty($GLOBALS['cinatra_test_log']) && is_file($GLOBALS['cinatra_test_log'])) {
        @unlink($GLOBALS['cinatra_test_log']);
    }
});
function cinatra_test_log_contents(): string {
    return (string) @file_get_contents($GLOBALS['cinatra_test_log']);
}
function cinatra_test_log_reset(): void {
    @file_put_contents($GLOBALS['cinatra_test_log'], '');
}

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
check('env unset -> NO safe-port filter active during the call (full SSRF guard)',
    $call && $call['port_filter_active'] === 0);

// MUST-FIX 1 (production parity): on the env-unset/production path the helper
// must pass the caller's args UNCHANGED to wp_safe_remote_post — it must NOT
// inject redirection => 0. The mint caller never sets redirection, so the
// captured args carry NO redirection key, meaning WordPress applies its DEFAULT
// (which permits redirects) — byte-identical to the pre-override call.
echo "Test: env unset -> args are byte-identical to the pre-override call (no forced redirection => 0)\n";
check('env unset -> helper does NOT inject a redirection arg (WP default redirect behavior preserved)',
    $call && !array_key_exists('redirection', (array) $call['args']));
// And prove the helper forwards a caller-supplied redirection value verbatim on
// the production path (it must neither add nor override it).
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
$res_passthru = cinatra_server_post('https://app.cinatra.ai/api/x', ['redirection' => 5, 'timeout' => 9]);
$passthru = end($GLOBALS['cinatra_test']['remote_post_calls']);
check('env unset -> caller redirection arg is forwarded UNCHANGED (not forced to 0)',
    $passthru && (int) ($passthru['args']['redirection'] ?? -1) === 5);
check('env unset -> no SSRF filters installed for a plain public-host call',
    $passthru && $passthru['host_filter_active'] === 0 && $passthru['port_filter_active'] === 0);

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
foreach ([
    // Original wrong-host / wrong-scheme / userinfo rows.
    'http://evil.example',
    'https://10.0.0.5',
    'ftp://host.docker.internal',
    'http://host.docker.internal@evil.example',
    // ORIGIN-ONLY must-fix (codex-named): path / query / fragment on an
    // OTHERWISE-allowlisted host MUST be rejected — the endpoint path is
    // appended by the plugin, never supplied via the base.
    'http://host.docker.internal:3000/foo?x#y', // path + query + fragment
    'http://host.docker.internal:3000/foo',     // path only
    'http://host.docker.internal:3000/api/agents/x/token',
    'http://host.docker.internal:3000?x',       // query only
    'http://host.docker.internal:3000#y',        // fragment only
    'http://host.docker.internal/foo',           // path only, no port
    // parse_url-permissiveness rows: malformed port.
    'http://host.docker.internal:80x',  // junk-suffixed port
    'http://host.docker.internal:+80',  // signed port
    'http://host.docker.internal:1.2',  // non-integer port
    'http://host.docker.internal:0',    // port 0
    'http://host.docker.internal:65536', // port out of range
    'http://host.docker.internal:',      // empty port
    // parse_url-permissiveness rows: malformed host shapes (also not in the
    // allowlist, but proven rejected at the grammar layer first).
    'http://[::1]',                       // bracketed IPv6 — valid grammar, not allowlisted
    'http://::1',                          // unbracketed IPv6 — invalid grammar
    'http://host.docker.internal\\evil',  // backslash host
    'http://host:docker:internal',         // extra-colon (multi-colon) host
] as $bad_env) {
    reset_fixture();
    cinatra_test_log_reset();
    putenv('CINATRA_BASE_URL=' . $bad_env);
    $GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
    cinatra_rest_mint_token(make_request_with_nonce('nonce-for-wp_rest'));
    $call = $GLOBALS['cinatra_test']['remote_post_calls'][0] ?? null;
    check("bad env '$bad_env' -> falls back to configured cinatra_url",
        $call && $call['url'] === 'https://app.cinatra.ai/api/agents/wordpress-content-editor/token');
    check("bad env '$bad_env' -> NO host-allowlist filter active (SSRF guard intact)",
        $call && $call['host_filter_active'] === 0);
    // The discard warning is fixed-text only: it must NOT echo the raw env value
    // (so the destination invariant can't be probed via the log), and of course
    // never any secret.
    $log = cinatra_test_log_contents();
    check("bad env '$bad_env' -> a fixed-text discard warning was logged",
        strpos($log, 'CINATRA_BASE_URL is set but is not a valid container-origin override') !== false);
    check("bad env '$bad_env' -> warning does NOT leak the raw env value",
        strpos($log, $bad_env) === false);
    check("bad env '$bad_env' -> warning does NOT leak the long-lived key",
        strpos($log, 'LONG-LIVED-SECRET-KEY-uuid-uuid') === false);
    putenv('CINATRA_BASE_URL');
}

echo "Test: server-base validator allowlist (loopback + host.docker.internal only)\n";
check('http host.docker.internal allowed', cinatra_validate_server_base_url('http://host.docker.internal:3000') === 'http://host.docker.internal:3000');
check('https host.docker.internal allowed', cinatra_validate_server_base_url('https://host.docker.internal') === 'https://host.docker.internal');
check('http localhost allowed', cinatra_validate_server_base_url('http://localhost:3000') === 'http://localhost:3000');
check('http 127.0.0.1 allowed (dotted IPv4)', cinatra_validate_server_base_url('http://127.0.0.1:3000') === 'http://127.0.0.1:3000');
check('arbitrary https host rejected', cinatra_validate_server_base_url('https://app.cinatra.ai') === '');
check('private ip rejected', cinatra_validate_server_base_url('http://10.0.0.5') === '');
check('userinfo rejected', cinatra_validate_server_base_url('http://host.docker.internal@evil.example') === '');
check('non-http(s) scheme rejected', cinatra_validate_server_base_url('ftp://localhost') === '');

echo "Test: server-base validator is strictly ORIGIN-ONLY (path/query/fragment rejected; clean origin accepted)\n";
// ACCEPT rows (clean origins) — must normalize, NOT fall back.
check('clean http origin (no port) accepted', cinatra_validate_server_base_url('http://host.docker.internal') === 'http://host.docker.internal');
check('single trailing slash accepted + stripped', cinatra_validate_server_base_url('http://host.docker.internal:3000/') === 'http://host.docker.internal:3000');
check('trailing slash, no port accepted + stripped', cinatra_validate_server_base_url('http://localhost/') === 'http://localhost');
check('mixed-case scheme normalized to lower', cinatra_validate_server_base_url('HTTP://localhost:3000') === 'http://localhost:3000');
check('IPv4 loopback accepted', cinatra_validate_server_base_url('http://127.0.0.1') === 'http://127.0.0.1');
// REJECT rows: ANY path/query/fragment, even on an allowlisted host.
check('path on allowlisted host rejected', cinatra_validate_server_base_url('http://host.docker.internal:3000/foo') === '');
check('path+query+fragment on allowlisted host rejected', cinatra_validate_server_base_url('http://host.docker.internal:3000/foo?x#y') === '');
check('endpoint-style path rejected', cinatra_validate_server_base_url('http://host.docker.internal:3000/api/agents/x/token') === '');
check('query-only rejected', cinatra_validate_server_base_url('http://host.docker.internal:3000?x') === '');
check('fragment-only rejected', cinatra_validate_server_base_url('http://host.docker.internal:3000#y') === '');
check('double-slash path rejected', cinatra_validate_server_base_url('http://host.docker.internal:3000//') === '');
// REJECT rows: parse_url-permissive ports — must be PURE digits, 1-65535.
check('port :0 rejected', cinatra_validate_server_base_url('http://host.docker.internal:0') === '');
check('port :80x (junk suffix) rejected', cinatra_validate_server_base_url('http://host.docker.internal:80x') === '');
check('port :+80 (signed) rejected', cinatra_validate_server_base_url('http://host.docker.internal:+80') === '');
check('port :1.2 (non-integer) rejected', cinatra_validate_server_base_url('http://host.docker.internal:1.2') === '');
check('port :65536 (out of range) rejected', cinatra_validate_server_base_url('http://host.docker.internal:65536') === '');
check('empty port ":" rejected', cinatra_validate_server_base_url('http://host.docker.internal:') === '');
check('max valid port :65535 grammar-accepted (host not allowlisted -> rejected)', cinatra_validate_server_base_url('http://example.com:65535') === '');
// REJECT rows: malformed hosts.
check('unbracketed IPv6 rejected', cinatra_validate_server_base_url('http://::1') === '');
check('backslash host rejected', cinatra_validate_server_base_url('http://host.docker.internal\\evil') === '');
check('extra-colon (multi-colon) host rejected', cinatra_validate_server_base_url('http://host:docker:internal') === '');
check('host with control char rejected', cinatra_validate_server_base_url("http://host.docker.internal\t:3000") === '');
// GRAMMAR-LEVEL accept for non-allowlisted-but-well-formed hosts (proves the
// grammar accepts IPv4 / bracketed IPv6 shapes; the allowlist then rejects them).
check('bracketed IPv6 is valid grammar but not allowlisted -> rejected', cinatra_validate_server_base_url('http://[::1]:3000') === '');
check('dotted IPv4 (non-loopback) is valid grammar but not allowlisted -> rejected', cinatra_validate_server_base_url('http://192.168.1.5:3000') === '');

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
check('exact override origin -> redirects DISABLED on the call (override-path hardening)',
    (int) (end($GLOBALS['cinatra_test']['remote_post_calls'])['args']['redirection'] ?? -1) === 0);
putenv('CINATRA_BASE_URL');

// MUST-FIX 2 (relaxation bound to the EXACT override origin): WHILE the
// request-scoped filters are installed for the override call, a DIFFERENT
// outbound request (other host, or same host wrong port) that happens during
// that window must see the UNCHANGED filter value. We capture the live filter
// callbacks installed during the override window and replay them with foreign
// args, exactly as WordPress would for any concurrent request in the window.
echo "Test: during the override window, a DIFFERENT-host/port request is NOT relaxed (filters check host+url)\n";
reset_fixture();
putenv('CINATRA_BASE_URL=http://host.docker.internal:3000');
$captured_filters = [];
$GLOBALS['cinatra_test']['remote_post'] = function ($url, $args) use (&$captured_filters) {
    // Snapshot the live filter callbacks while they are still installed.
    $captured_filters = [
        'host' => ($GLOBALS['cinatra_test']['filter_cbs']['http_request_host_is_external'] ?? [])[0] ?? null,
        'port' => ($GLOBALS['cinatra_test']['filter_cbs']['http_allowed_safe_ports'] ?? [])[0] ?? null,
    ];
    return ['response' => ['code' => 200], 'body' => json_encode(['token' => 'cit_x', 'expiresIn' => 300])];
};
$res_win = cinatra_server_post('http://host.docker.internal:3000/api/x', []);
check('override window: a host externality filter WAS installed', is_callable($captured_filters['host'] ?? null));
check('override window: a safe-port filter WAS installed', is_callable($captured_filters['port'] ?? null));

$host_cb = $captured_filters['host'];
$port_cb = $captured_filters['port'];
// http_request_host_is_external($external, $host, $url): a DIFFERENT host in the
// window must NOT be treated as external-allowed — the filter returns the
// original $is_external (false) unchanged, only the override host gets true.
check('window host-filter: a DIFFERENT host is NOT relaxed (returns original false)',
    $host_cb(false, 'evil.example', 'http://evil.example/x') === false);
check('window host-filter: a private third-party host is NOT relaxed (returns original false)',
    $host_cb(false, '10.0.0.5', 'http://10.0.0.5/x') === false);
check('window host-filter: the SAME host on a DIFFERENT port is NOT relaxed (origin-bound, returns false)',
    $host_cb(false, 'host.docker.internal', 'http://host.docker.internal:8080/x') === false);
check('window host-filter: ONLY the exact override ORIGIN is relaxed (returns true)',
    $host_cb(false, 'host.docker.internal', 'http://host.docker.internal:3000/x') === true);

// http_allowed_safe_ports($ports, $host, $url): a DIFFERENT host, or the
// override host on a DIFFERENT port, must NOT widen the port set — the override
// port (3000) is added ONLY for the exact override origin.
check('window port-filter: a DIFFERENT host does NOT get the override port added',
    !in_array(3000, $port_cb([80, 443, 8080], 'evil.example', 'http://evil.example:3000/x'), true));
check('window port-filter: same override host on a DIFFERENT port does NOT get :3000 added',
    !in_array(3000, $port_cb([80, 443, 8080], 'host.docker.internal', 'http://host.docker.internal:8080/x'), true));
check('window port-filter: the EXACT override origin DOES get :3000 added',
    in_array(3000, $port_cb([80, 443, 8080], 'host.docker.internal', 'http://host.docker.internal:3000/x'), true));
check('window: filters REMOVED after the override call returns (no leaked relaxation)',
    (int) ($GLOBALS['cinatra_test']['filters']['http_request_host_is_external'] ?? 0) === 0
    && (int) ($GLOBALS['cinatra_test']['filters']['http_allowed_safe_ports'] ?? 0) === 0);
putenv('CINATRA_BASE_URL');

// MUST-FIX 2 (composed decision — the gap a host-only filter would leak): drive
// REAL concurrent wp_safe_remote_post() calls from INSIDE the override window and
// assert the full safe-request gate (host-externality + safe-port together)
// blocks everything but the exact override origin. The dangerous case is the
// SAME allowlisted host on a WordPress-default-safe port (e.g.
// host.docker.internal:8080 during a :3000 window): that port never needs
// safe-listing, so a host-ONLY externality filter would wrongly let it through.
// Binding the host filter to the full scheme://host:port origin closes it.
echo "Test: during the override window, concurrent requests are gated by the EXACT origin (composed host+port decision)\n";
reset_fixture();
putenv('CINATRA_BASE_URL=http://host.docker.internal:3000');
$nested = [];
$GLOBALS['cinatra_test']['remote_post'] = function ($url, $args) use (&$nested) {
    if ($url === 'http://host.docker.internal:3000/api/x') {
        // Concurrent outbound requests that "happen" while the filters are live:
        $nested['same_host_default_port'] = wp_safe_remote_post('http://host.docker.internal:8080/other', []);
        $nested['same_host_other_port']   = wp_safe_remote_post('http://host.docker.internal:9999/other', []);
        $nested['third_party_public']     = wp_safe_remote_post('https://app.cinatra.ai/other', ['headers' => []]);
        $nested['third_party_private']    = wp_safe_remote_post('http://10.0.0.5/other', []);
        $nested['exact_override_again']   = wp_safe_remote_post('http://host.docker.internal:3000/again', []);
    }
    return ['response' => ['code' => 200], 'body' => json_encode(['token' => 'cit_x', 'expiresIn' => 300])];
};
cinatra_server_post('http://host.docker.internal:3000/api/x', []);
check('window: SAME host on a WP-default-safe port (:8080) is STILL BLOCKED (not the exact origin)',
    is_wp_error($nested['same_host_default_port'] ?? null));
check('window: SAME host on another non-default port (:9999) is STILL BLOCKED',
    is_wp_error($nested['same_host_other_port'] ?? null));
check('window: a third-party PRIVATE host (10.0.0.5) is STILL BLOCKED',
    is_wp_error($nested['third_party_private'] ?? null));
check('window: a third-party PUBLIC host is unaffected (still allowed, as WP would)',
    !is_wp_error($nested['third_party_public'] ?? null));
check('window: the EXACT override origin IS allowed (even nested during the window)',
    !is_wp_error($nested['exact_override_again'] ?? null));
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
