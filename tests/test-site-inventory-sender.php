<?php
/**
 * Standalone behavior tests for the handshake enrichment sender
 * (cinatra-ai/cinatra#2021 S6 / eta). Runs under plain
 * `php tests/test-site-inventory-sender.php` -- no PHPUnit, no WordPress
 * install. Exit code 0 = all pass, 1 = a failure.
 *
 * Covers:
 *   - cinatra_build_site_inventory_payload(): shape conformance against the
 *     wp-site-inventory-v1 contract (docs/internals/contracts/wp-site-
 *     inventory-contract.md, cinatra repo) -- adapter-absent implies
 *     adapterVersion=null + servers=[]; adapter-active maps get_servers()
 *     into the exact server-entry shape (canonical restPath, bidirectional
 *     isDefault, requiresDedicatedAuth from the transport permission
 *     callback, toolCount); a malformed entry is skipped without dropping
 *     the rest of the payload; permalinkStructure pretty/plain; optional
 *     claimedInstanceId.
 *   - connectedUserRole precedence: the captured Application-Password user's
 *     role wins; falls back to the current browsing user; falls back to
 *     "none" with no signal at all -- never silently the wrong role.
 *   - cinatra_hash_site_inventory_payload(): stable across inventorySeq/
 *     collectedAt-only changes; differs on a real content change.
 *   - cinatra_send_site_inventory(): not-connected no-op (no seq increment,
 *     no network call); happy path (seq +1, Origin = this site's own origin,
 *     Authorization = the stored key, hash persisted only on 2xx); a 429/
 *     transport failure still counts as an ATTEMPT (seq +1, last_attempt
 *     advances) but never updates the hash and never retries inline; the
 *     long-lived key is never logged verbatim on a transport failure.
 *   - the page-load gate (trigger b): wrong page / not connected / within
 *     the local debounce window / unchanged hash all skip with NO network
 *     call and NO seq increment; window-elapsed + changed payload sends.
 *   - the Connect-handshake force-send (trigger a): ignores the debounce/
 *     hash gate entirely and also arms the daily cron.
 *   - the daily WP-Cron fallback (trigger c): always sends when connected,
 *     regardless of the local debounce/hash state; no-ops when not
 *     connected.
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/fixtures/mcp-adapter-stub.php';
require __DIR__ . '/fixtures/mcp-adapter-server-stub.php';
require dirname(__DIR__) . '/cinatra.php';

// Route PHP error_log() to a temp file (not stderr), mirroring
// test-token-broker.php, so tests can assert the long-lived key is scrubbed
// / never reflected on a logged failure.
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

function reset_inventory_fixture() {
    $GLOBALS['cinatra_test'] = [
        'options' => [
            'cinatra_url'         => 'https://app.cinatra.ai',
            'cinatra_api_key'     => 'cnx_site_123_LONG-LIVED-SECRET-KEY',
            'cinatra_instance_id' => 'wp-prod',
        ],
        'current_user_can'   => true,
        'current_user_id'    => 7,
        'current_user_roles' => [],
        'valid_nonces'       => ['wp_rest'],
        'home_url'           => 'https://blog.example',
        'remote_post'        => null,
        'remote_post_calls'  => [],
        'filters'            => [],
        'filter_cbs'         => [],
        'active_plugins'     => [],
        'installed_plugins'  => [],
        'wp_version'         => '6.9.1',
        'is_ssl'             => true,
        'users'              => [],
        'cron_scheduled'     => [],
    ];
    putenv('CINATRA_BASE_URL');
    \WP\MCP\Core\McpAdapter::$servers_fixture = [];
    // $_GET is real PHP superglobal state the page-load gate reads; tests
    // must reset it explicitly (no fixture wraps it).
    $_GET = [];
}

function set_adapter_active(string $version = '0.5.0'): void {
    $GLOBALS['cinatra_test']['active_plugins'][] = 'mcp-adapter/mcp-adapter.php';
    $GLOBALS['cinatra_test']['installed_plugins']['mcp-adapter/mcp-adapter.php'] = ['Version' => $version];
}

$ok_remote = function ($url, $args) {
    return ['response' => ['code' => 200], 'body' => json_encode(['accepted' => true])];
};

// ---------------------------------------------------------------------------
echo "Payload: adapter absent -> adapterVersion null, servers empty\n";
reset_inventory_fixture();
$payload = cinatra_build_site_inventory_payload();
check('contractVersion is v1', $payload['contractVersion'] === 'v1');
check('client is wordpress', $payload['client'] === 'wordpress');
check('adapterVersion is null when adapter inactive', $payload['site']['adapterVersion'] === null);
check('servers is empty when adapter inactive', $payload['servers'] === []);
check('abilitiesPluginVersion is null when catalog plugin absent', $payload['site']['abilitiesPluginVersion'] === null);
check('permalinkStructure defaults to plain (no option set)', $payload['site']['permalinkStructure'] === 'plain');
check('claimedInstanceId present (cinatra_instance_id is set in the fixture)', ($payload['claimedInstanceId'] ?? '') === 'wp-prod');
check('collectedAt looks like an offset ISO-8601 timestamp (Z suffix)', (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $payload['collectedAt']));

// ---------------------------------------------------------------------------
echo "\nPayload: permalinkStructure pretty when the option is set\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['options']['permalink_structure'] = '/%postname%/';
$payload = cinatra_build_site_inventory_payload();
check('permalinkStructure is pretty', $payload['site']['permalinkStructure'] === 'pretty');

// ---------------------------------------------------------------------------
echo "\nPayload: claimedInstanceId omitted when no instance id is stored\n";
reset_inventory_fixture();
unset($GLOBALS['cinatra_test']['options']['cinatra_instance_id']);
$payload = cinatra_build_site_inventory_payload();
check('claimedInstanceId key is absent (not merely empty)', !array_key_exists('claimedInstanceId', $payload));

// ---------------------------------------------------------------------------
echo "\nPayload: adapter active -> version reported + servers mapped\n";
reset_inventory_fixture();
set_adapter_active('0.6.1');
\WP\MCP\Core\McpAdapter::$servers_fixture = [
    'mcp-adapter-default-server' => new \WP\MCP\Core\FullStubMcpServer([
        'id' => 'mcp-adapter-default-server', 'namespace' => 'mcp', 'route' => 'mcp-adapter-default-server',
        'name' => 'MCP Adapter Default Server', 'version' => '0.6.1', 'tools' => ['a' => 1, 'b' => 2],
    ]),
    'fixture-vendor-server' => new \WP\MCP\Core\FullStubMcpServer([
        'id' => 'fixture-vendor-server', 'namespace' => 'mcp', 'route' => 'fixture-vendor-server',
        'name' => 'Fixture Vendor Server', 'description' => 'A vendor MCP server.', 'version' => '1.2.3',
        'tools' => ['x' => 1, 'y' => 2, 'z' => 3],
    ]),
    'fixture-dedicated-auth-server' => new \WP\MCP\Core\FullStubMcpServer([
        'id' => 'fixture-dedicated-auth-server', 'namespace' => 'mcp', 'route' => 'fixture-dedicated-auth-server',
        'name' => 'Fixture Dedicated-Auth Server', 'permission_callback' => function () { return true; },
    ]),
];
$payload = cinatra_build_site_inventory_payload();
check('adapterVersion reports the installed version', $payload['site']['adapterVersion'] === '0.6.1');
check('three servers mapped', count($payload['servers']) === 3);

$by_id = [];
foreach ($payload['servers'] as $entry) {
    $by_id[$entry['adapterServerId']] = $entry;
}
$default = $by_id['mcp-adapter-default-server'] ?? null;
check('default server restPath is canonical', $default && $default['restPath'] === '/mcp/mcp-adapter-default-server');
check('default server isDefault=true (matches the reserved default path)', $default && $default['isDefault'] === true);
check('default server toolCount reflects get_tools() count', $default && $default['toolCount'] === 2);
check('default server transports = [streamable-http] (the only shipped adapter transport)', $default && $default['transports'] === ['streamable-http']);
check('default server requiresDedicatedAuth=false (no custom permission callback)', $default && $default['requiresDedicatedAuth'] === false);

$vendor = $by_id['fixture-vendor-server'] ?? null;
check('vendor server restPath is canonical', $vendor && $vendor['restPath'] === '/mcp/fixture-vendor-server');
check('vendor server isDefault=false (not the reserved default path)', $vendor && $vendor['isDefault'] === false);
check('vendor server description included', $vendor && $vendor['description'] === 'A vendor MCP server.');
check('vendor server version included', $vendor && $vendor['version'] === '1.2.3');
check('vendor server toolCount = 3', $vendor && $vendor['toolCount'] === 3);

$dedicated = $by_id['fixture-dedicated-auth-server'] ?? null;
check(
    'a server with a non-default transport permission callback -> requiresDedicatedAuth=true',
    $dedicated && $dedicated['requiresDedicatedAuth'] === true
);
check('a server with no description key omits it entirely', $dedicated && !array_key_exists('description', $dedicated));
check('a server with no version key omits it entirely', $dedicated && !array_key_exists('version', $dedicated));

// ---------------------------------------------------------------------------
echo "\nPayload: abilitiesPluginVersion reads the catalog plugin's header regardless of active state\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['installed_plugins']['enable-abilities-for-mcp/enable-abilities-for-mcp.php'] = ['Version' => '2.0.24'];
$payload = cinatra_build_site_inventory_payload();
check('abilitiesPluginVersion reads the installed (not-necessarily-active) version', $payload['site']['abilitiesPluginVersion'] === '2.0.24');

// ---------------------------------------------------------------------------
echo "\nPayload: a malformed server entry is skipped, the rest of the payload survives\n";
reset_inventory_fixture();
set_adapter_active();
\WP\MCP\Core\McpAdapter::$servers_fixture = [
    'good' => new \WP\MCP\Core\FullStubMcpServer(['id' => 'good-server', 'namespace' => 'mcp', 'route' => 'good-route', 'name' => 'Good']),
    // Bad namespace: contains a slash, which SERVER_NAMESPACE_PATTERN forbids
    // (only the ROUTE pattern allows slashes).
    'bad' => new \WP\MCP\Core\FullStubMcpServer(['id' => 'bad-server', 'namespace' => 'not/valid', 'route' => 'x', 'name' => 'Bad']),
];
$payload = cinatra_build_site_inventory_payload();
check('exactly one server survives (the malformed one was skipped, not fatal)', count($payload['servers']) === 1);
check('the surviving server is the good one', ($payload['servers'][0]['adapterServerId'] ?? '') === 'good-server');

// ---------------------------------------------------------------------------
echo "\nPayload: an empty server name falls back to the server id (never an empty required field)\n";
reset_inventory_fixture();
set_adapter_active();
\WP\MCP\Core\McpAdapter::$servers_fixture = [
    new \WP\MCP\Core\FullStubMcpServer(['id' => 'no-name-server', 'namespace' => 'mcp', 'route' => 'r', 'name' => '']),
];
$payload = cinatra_build_site_inventory_payload();
check('name falls back to the server id', ($payload['servers'][0]['name'] ?? '') === 'no-name-server');

// ---------------------------------------------------------------------------
echo "\nconnectedUserRole precedence\n";
reset_inventory_fixture();
check('falls back to "none" with no captured user and no current user', cinatra_resolve_connected_user_role() === 'none');

$GLOBALS['cinatra_test']['current_user_roles'] = ['editor'];
check('falls back to the current browsing user when nothing was captured yet', cinatra_resolve_connected_user_role() === 'editor');

$GLOBALS['cinatra_test']['users'][42] = ['roles' => ['administrator']];
update_option('cinatra_connected_app_user_id', 42);
check('the CAPTURED Application-Password user wins over the current browsing user', cinatra_resolve_connected_user_role() === 'administrator');

echo "\ncinatra_capture_connected_app_user()\n";
reset_inventory_fixture();
cinatra_capture_connected_app_user(new WP_User(['editor'], 99), ['uuid' => 'abc']);
check('a valid WP_User is captured', (int) get_option('cinatra_connected_app_user_id', 0) === 99);

reset_inventory_fixture();
cinatra_capture_connected_app_user(new WP_Error('invalid', 'nope'), ['uuid' => 'abc']);
check('a non-WP_User (e.g. WP_Error) is never captured', get_option('cinatra_connected_app_user_id', 0) === 0);

reset_inventory_fixture();
cinatra_capture_connected_app_user(new WP_User(['subscriber'], 0), ['uuid' => 'abc']);
check('a WP_User with ID<=0 is never captured', get_option('cinatra_connected_app_user_id', 0) === 0);

echo "\napplication_password_did_authenticate integration (via do_action, not a direct call)\n";
reset_inventory_fixture();
do_action('application_password_did_authenticate', new WP_User(['administrator'], 5), ['uuid' => 'xyz']);
check('the hook is actually wired (do_action reaches the capture function)', (int) get_option('cinatra_connected_app_user_id', 0) === 5);

// ---------------------------------------------------------------------------
echo "\ncinatra_hash_site_inventory_payload() stability\n";
reset_inventory_fixture();
$p1 = cinatra_build_site_inventory_payload();
update_option('cinatra_inventory_seq', 5);
$p2 = cinatra_build_site_inventory_payload();
check('inventorySeq differs between the two builds (sanity)', $p1['inventorySeq'] !== $p2['inventorySeq']);
check('the hash is STABLE across an inventorySeq-only change', cinatra_hash_site_inventory_payload($p1) === cinatra_hash_site_inventory_payload($p2));

$p3 = $p1;
$p3['site']['wpVersion'] = '7.0.0';
check('the hash DIFFERS on a real content change', cinatra_hash_site_inventory_payload($p1) !== cinatra_hash_site_inventory_payload($p3));

// ---------------------------------------------------------------------------
echo "\ncinatra_send_site_inventory(): not connected -> silent no-op\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['options'] = [];
cinatra_send_site_inventory();
check('no network call when not connected', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);
check('inventorySeq NOT incremented when not connected', (int) get_option('cinatra_inventory_seq', 0) === 0);

// ---------------------------------------------------------------------------
echo "\ncinatra_send_site_inventory(): happy path\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
cinatra_send_site_inventory();
check('inventorySeq incremented by exactly 1', (int) get_option('cinatra_inventory_seq', 0) === 1);
check('last_attempt was recorded', (int) get_option('cinatra_inventory_last_attempt', 0) > 0);
check('last_hash was recorded on a 2xx accept', get_option('cinatra_inventory_last_hash', '') !== '');
$call = $GLOBALS['cinatra_test']['remote_post_calls'][0] ?? null;
check('posted to the site-inventory intake path', $call && strpos($call['url'], '/api/connect/site-inventory') !== false);
check('posted to the configured cinatra_url host', $call && strpos($call['url'], 'https://app.cinatra.ai') === 0);
check(
    'Authorization Bearer carries the stored cnx_ credential',
    $call && ($call['args']['headers']['Authorization'] ?? '') === 'Bearer cnx_site_123_LONG-LIVED-SECRET-KEY'
);
check(
    'Origin is THIS SITE\'s own origin (admin_url-derived), never the target cinatra host',
    $call && ($call['args']['headers']['Origin'] ?? '') === 'https://blog.example'
);
$sent = $call ? json_decode($call['args']['body'], true) : [];
check('the sent body inventorySeq matches the just-incremented option', ($sent['inventorySeq'] ?? -1) === 1);

// ---------------------------------------------------------------------------
echo "\ncinatra_send_site_inventory(): a 429 counts as an attempt but never updates the hash, never retries\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['remote_post'] = function ($url, $args) {
    return ['response' => ['code' => 429], 'body' => json_encode(['error' => 'rate_limited'])];
};
cinatra_test_log_reset();
cinatra_send_site_inventory();
check('inventorySeq STILL incremented (attempt happened, contract tolerates gaps)', (int) get_option('cinatra_inventory_seq', 0) === 1);
check('last_attempt STILL recorded (opens the local cooldown even on rejection)', (int) get_option('cinatra_inventory_last_attempt', 0) > 0);
check('last_hash is NOT recorded on a non-2xx response', get_option('cinatra_inventory_last_hash', '') === '');
check('exactly ONE network call was made (no inline retry)', count($GLOBALS['cinatra_test']['remote_post_calls']) === 1);
check('the rejection was logged (status only)', strpos(cinatra_test_log_contents(), 'HTTP 429') !== false);

// ---------------------------------------------------------------------------
echo "\ncinatra_send_site_inventory(): a transport failure scrubs the long-lived key from the log\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['remote_post'] = function ($url, $args) {
    return new WP_Error('http_request_failed', 'cURL error: could not reach cnx_site_123_LONG-LIVED-SECRET-KEY endpoint');
};
cinatra_test_log_reset();
cinatra_send_site_inventory();
check('inventorySeq still incremented on a transport failure', (int) get_option('cinatra_inventory_seq', 0) === 1);
check('last_hash NOT recorded on a transport failure', get_option('cinatra_inventory_last_hash', '') === '');
$log = cinatra_test_log_contents();
check('a failure notice was logged', strpos($log, 'site-inventory send failed') !== false);
check('the long-lived key is NEVER logged verbatim', strpos($log, 'cnx_site_123_LONG-LIVED-SECRET-KEY') === false);

// ---------------------------------------------------------------------------
echo "\npage-load gate (trigger b): wrong page is a no-op\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
$_GET['page'] = 'some-other-plugin';
cinatra_maybe_send_site_inventory_on_settings_load();
check('no network call on a different admin page', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);
check('no seq increment on a different admin page', (int) get_option('cinatra_inventory_seq', 0) === 0);

echo "\npage-load gate: not connected is a no-op\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['options'] = [];
$_GET['page'] = 'cinatra';
cinatra_maybe_send_site_inventory_on_settings_load();
check('no network call when not connected', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);

echo "\npage-load gate: within the local debounce window is a no-op (even though the payload would differ)\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
$_GET['page'] = 'cinatra';
update_option('cinatra_inventory_last_attempt', time()); // "just now" -- well inside the 60s+jitter window.
update_option('cinatra_inventory_last_hash', 'stale-hash-that-will-never-match');
cinatra_maybe_send_site_inventory_on_settings_load();
check('no network call within the debounce window', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);
check('no seq increment within the debounce window', (int) get_option('cinatra_inventory_seq', 0) === 0);

echo "\npage-load gate: window elapsed but the hash is UNCHANGED is a no-op\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
$_GET['page'] = 'cinatra';
update_option('cinatra_inventory_last_attempt', time() - 10000); // Long elapsed regardless of jitter.
$current_hash = cinatra_hash_site_inventory_payload(cinatra_build_site_inventory_payload());
update_option('cinatra_inventory_last_hash', $current_hash);
cinatra_maybe_send_site_inventory_on_settings_load();
check('no network call when nothing changed since the last accepted send', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);
check('no seq increment when nothing changed', (int) get_option('cinatra_inventory_seq', 0) === 0);

echo "\npage-load gate: window elapsed AND the payload changed -> sends\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
$_GET['page'] = 'cinatra';
update_option('cinatra_inventory_last_attempt', time() - 10000);
update_option('cinatra_inventory_last_hash', 'a-hash-that-can-never-match-the-real-payload');
cinatra_maybe_send_site_inventory_on_settings_load();
check('exactly one send when the window elapsed and the payload differs', count($GLOBALS['cinatra_test']['remote_post_calls']) === 1);
check('seq incremented by the gate-triggered send', (int) get_option('cinatra_inventory_seq', 0) === 1);

// ---------------------------------------------------------------------------
echo "\nConnect-handshake force-send (trigger a) ignores the debounce/hash gate and arms the cron\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['options'] = [];
$GLOBALS['cinatra_test']['remote_post'] = function ($url, $args) use ($ok_remote) {
    if (strpos($url, '/api/connect/token') !== false) {
        return ['response' => ['code' => 200], 'body' => json_encode([
            'url' => 'https://app.cinatra.ai', 'credential' => 'cnx_site_123_FRESH-KEY', 'cinatraInstanceId' => 'wp-fresh',
        ])];
    }
    return $ok_remote($url, $args);
};
check('cron is NOT scheduled before any connect/activation', wp_next_scheduled(CINATRA_SITE_INVENTORY_CRON_HOOK) === false);
// Set the SAME "just connected" timestamp/hash state the debounce gate would
// otherwise block on, to prove the force-send ignores it entirely.
update_option('cinatra_inventory_last_attempt', time());
$result = cinatra_connect_exchange('https://app.cinatra.ai', [
    'grant_type' => 'install_code', 'install_code' => 'x', 'client' => 'wordpress',
]);
cinatra_connect_apply_result($result);
$inventory_calls = array_values(array_filter(
    $GLOBALS['cinatra_test']['remote_post_calls'],
    function ($c) { return strpos($c['url'], '/api/connect/site-inventory') !== false; }
));
check('the handshake success path sent a site-inventory attempt despite the fresh last_attempt', count($inventory_calls) === 1);
check('the daily cron fallback is now armed', wp_next_scheduled(CINATRA_SITE_INVENTORY_CRON_HOOK) !== false);

// ---------------------------------------------------------------------------
echo "\nDaily WP-Cron fallback (trigger c): always sends when connected, ignoring the local gate\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
update_option('cinatra_inventory_last_attempt', time()); // Freshly inside the debounce window.
$current_hash = cinatra_hash_site_inventory_payload(cinatra_build_site_inventory_payload());
update_option('cinatra_inventory_last_hash', $current_hash); // AND nothing changed.
cinatra_test_do_action(CINATRA_SITE_INVENTORY_CRON_HOOK);
check('the cron trigger sends even though trigger (b) would have skipped', count($GLOBALS['cinatra_test']['remote_post_calls']) === 1);

echo "\nDaily WP-Cron fallback: no-op when not connected\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['options'] = [];
$GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
cinatra_test_do_action(CINATRA_SITE_INVENTORY_CRON_HOOK);
check('no network call from the cron trigger when not connected', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);

// ---------------------------------------------------------------------------
echo "\nActivation/deactivation cron scheduling\n";
reset_inventory_fixture();
check('activation hook was registered at plugin load time', !empty($GLOBALS['cinatra_test_activation_cbs']));
check('deactivation hook was registered at plugin load time', !empty($GLOBALS['cinatra_test_deactivation_cbs']));
cinatra_ensure_site_inventory_cron_scheduled();
$first_scheduled_at = wp_next_scheduled(CINATRA_SITE_INVENTORY_CRON_HOOK);
check('the cron was scheduled', $first_scheduled_at !== false);
cinatra_ensure_site_inventory_cron_scheduled();
check('a second call is idempotent (does not reschedule)', wp_next_scheduled(CINATRA_SITE_INVENTORY_CRON_HOOK) === $first_scheduled_at);
cinatra_unschedule_site_inventory_cron();
check('deactivation clears the schedule', wp_next_scheduled(CINATRA_SITE_INVENTORY_CRON_HOOK) === false);

// ---------------------------------------------------------------------------
// Hardening cases (adversarial-review round): hostile/broken server objects,
// duplicate ids, contract field bounds, the encoded-size cap, and the
// concurrent-send lock.
// ---------------------------------------------------------------------------

/** A server whose getter throws mid-mapping (a hostile/broken third-party MCP plugin). */
class Cinatra_Test_ThrowingGetterServer {
    public function get_server_id() { return 'throwing-server'; }
    public function get_server_route_namespace() { return 'mcp'; }
    public function get_server_route() { return 'throwing-route'; }
    public function get_server_name() { throw new RuntimeException('hostile getter'); }
}

/** A server whose id getter returns an uncastable object (no __toString). */
class Cinatra_Test_ObjectIdServer {
    public function get_server_id() { return new stdClass(); }
    public function get_server_route_namespace() { return 'mcp'; }
    public function get_server_route() { return 'object-id'; }
    public function get_server_name() { return 'Object Id'; }
}

/** A duck-typed server LACKING the transport-permission-callback getter. */
class Cinatra_Test_NoPermissionMethodServer {
    public function get_server_id() { return 'no-perm-method'; }
    public function get_server_route_namespace() { return 'mcp'; }
    public function get_server_route() { return 'no-perm'; }
    public function get_server_name() { return 'No Perm Method'; }
}

echo "\nHostile registry: a throwing getter skips ONLY that entry (no fatal, others survive)\n";
reset_inventory_fixture();
set_adapter_active();
\WP\MCP\Core\McpAdapter::$servers_fixture = [
    new Cinatra_Test_ThrowingGetterServer(),
    new \WP\MCP\Core\FullStubMcpServer(['id' => 'survivor', 'namespace' => 'mcp', 'route' => 'survivor', 'name' => 'Survivor']),
];
$payload = cinatra_build_site_inventory_payload();
check('the throwing entry was skipped, the good one survived', count($payload['servers']) === 1);
check('the surviving entry is the non-throwing server', ($payload['servers'][0]['adapterServerId'] ?? '') === 'survivor');

echo "\nHostile registry: an uncastable (object) id skips the entry (no fatal)\n";
reset_inventory_fixture();
set_adapter_active();
\WP\MCP\Core\McpAdapter::$servers_fixture = [
    new Cinatra_Test_ObjectIdServer(),
    new \WP\MCP\Core\FullStubMcpServer(['id' => 'ok-server', 'namespace' => 'mcp', 'route' => 'ok', 'name' => 'OK']),
];
$payload = cinatra_build_site_inventory_payload();
check('object-id entry skipped, valid one survived', count($payload['servers']) === 1
    && ($payload['servers'][0]['adapterServerId'] ?? '') === 'ok-server');

echo "\nDuck-typed server without the permission-callback getter -> requiresDedicatedAuth=true (fail toward less trust)\n";
reset_inventory_fixture();
set_adapter_active();
\WP\MCP\Core\McpAdapter::$servers_fixture = [new Cinatra_Test_NoPermissionMethodServer()];
$payload = cinatra_build_site_inventory_payload();
check('entry mapped', count($payload['servers']) === 1);
check('requiresDedicatedAuth defaults to TRUE when the getter is missing', ($payload['servers'][0]['requiresDedicatedAuth'] ?? false) === true);

echo "\nDuplicate adapterServerId values are deduped (first wins; contract rejects payload-wide dupes)\n";
reset_inventory_fixture();
set_adapter_active();
\WP\MCP\Core\McpAdapter::$servers_fixture = [
    'a' => new \WP\MCP\Core\FullStubMcpServer(['id' => 'dup-server', 'namespace' => 'mcp', 'route' => 'first-route', 'name' => 'First']),
    'b' => new \WP\MCP\Core\FullStubMcpServer(['id' => 'dup-server', 'namespace' => 'mcp', 'route' => 'second-route', 'name' => 'Second']),
];
$payload = cinatra_build_site_inventory_payload();
check('exactly one entry survives the dedupe', count($payload['servers']) === 1);
check('the FIRST entry won', ($payload['servers'][0]['route'] ?? '') === 'first-route');

echo "\nContract field bounds: overlong server fields are clipped, never sent overlong\n";
reset_inventory_fixture();
set_adapter_active(str_repeat('9', 100) . '.0'); // Overlong adapter version (>64).
\WP\MCP\Core\McpAdapter::$servers_fixture = [
    new \WP\MCP\Core\FullStubMcpServer([
        'id' => 'long-fields', 'namespace' => 'mcp', 'route' => 'long-fields',
        'name' => str_repeat('N', 300),
        'description' => str_repeat('D', 3000),
        'version' => str_repeat('V', 100),
    ]),
];
$payload = cinatra_build_site_inventory_payload();
$entry = $payload['servers'][0] ?? [];
check('name clipped to 200', strlen($entry['name'] ?? '') === 200);
check('description clipped to 2000', strlen($entry['description'] ?? '') === 2000);
check('server version clipped to 64', strlen($entry['version'] ?? '') === 64);
check('site.adapterVersion clipped to 64', strlen((string) $payload['site']['adapterVersion']) === 64);

echo "\nContract field bounds: an overlong custom role slug is clipped to 64\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['users'][7] = ['roles' => [str_repeat('r', 100)]];
update_option('cinatra_connected_app_user_id', 7);
$payload = cinatra_build_site_inventory_payload();
check('connectedUserRole clipped to 64', strlen($payload['site']['connectedUserRole']) === 64);

echo "\nEncoded-size cap: shed pass 1 drops descriptions so the payload fits the body cap\n";
reset_inventory_fixture();
set_adapter_active();
$big = [];
for ($i = 0; $i < 100; $i++) {
    $big[] = new \WP\MCP\Core\FullStubMcpServer([
        'id' => "bulk-server-$i", 'namespace' => 'mcp', 'route' => "bulk-server-$i",
        'name' => str_repeat('N', 200),
        'description' => str_repeat('D', 2000),
        'version' => str_repeat('7', 64),
    ]);
}
\WP\MCP\Core\McpAdapter::$servers_fixture = $big;
$payload = cinatra_build_site_inventory_payload();
$encoded_len = strlen((string) wp_json_encode($payload));
check('final encoded payload fits under the client-side cap', $encoded_len <= CINATRA_SITE_INVENTORY_MAX_BODY_BYTES);
check('all 100 entries were KEPT (descriptions shed instead)', count($payload['servers']) === 100);
check('descriptions were dropped by the shed pass', !array_key_exists('description', $payload['servers'][0]));

echo "\nEncoded-size cap: shed pass 2 drops trailing entries when descriptions alone are not enough\n";
// Hand-built payload with fields LONGER than the mapper could ever produce
// (the fit function must still converge on garbage input, not just on
// mapper-clipped entries).
$monster = [
    'contractVersion' => 'v1',
    'client' => 'wordpress',
    'inventorySeq' => 1,
    'collectedAt' => '2026-01-01T00:00:00Z',
    'site' => ['wpVersion' => '6.9', 'phpVersion' => '8.4', 'adapterVersion' => '1.0',
        'abilitiesPluginVersion' => null, 'connectedUserRole' => 'none', 'permalinkStructure' => 'plain'],
    'servers' => [],
];
for ($i = 0; $i < 100; $i++) {
    $monster['servers'][] = [
        'adapterServerId' => "monster-$i", 'namespace' => 'mcp', 'route' => "monster-$i",
        'restPath' => "/mcp/monster-$i", 'name' => str_repeat('X', 10000),
        'transports' => ['streamable-http'], 'requiresDedicatedAuth' => false,
        'isDefault' => false, 'toolCount' => 0,
    ];
}
$fitted = cinatra_fit_site_inventory_payload($monster);
check('pass-2 fitted payload is under the cap', strlen((string) wp_json_encode($fitted)) <= CINATRA_SITE_INVENTORY_MAX_BODY_BYTES);
check('pass-2 dropped trailing entries to get there', count($fitted['servers']) < 100);
check('pass-2 kept the LEADING entries', ($fitted['servers'][0]['adapterServerId'] ?? '') === 'monster-0');

echo "\nConcurrent-send lock: a held lock skips the send entirely (no seq burn, no network call)\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
set_transient('cinatra_inventory_send_lock', 1, 30); // Another send "in flight".
cinatra_send_site_inventory();
check('no network call while the lock is held', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);
check('inventorySeq NOT incremented while the lock is held', (int) get_option('cinatra_inventory_seq', 0) === 0);

echo "\nConcurrent-send lock: released after a normal send (success)\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
cinatra_send_site_inventory();
check('the send went through', count($GLOBALS['cinatra_test']['remote_post_calls']) === 1);
check('the lock was RELEASED afterward', get_transient('cinatra_inventory_send_lock') === false);

echo "\nConcurrent-send lock: released even when the transport fails\n";
reset_inventory_fixture();
$GLOBALS['cinatra_test']['remote_post'] = function ($url, $args) {
    return new WP_Error('http_request_failed', 'down');
};
cinatra_test_log_reset();
cinatra_send_site_inventory();
check('the lock was released after a transport failure', get_transient('cinatra_inventory_send_lock') === false);

// ---------------------------------------------------------------------------
echo "\n";
if ($failures === 0) {
    echo "ALL TESTS PASSED\n";
    exit(0);
}
echo "$failures TEST(S) FAILED\n";
exit(1);
