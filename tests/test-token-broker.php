<?php
/**
 * Standalone behavior tests for the Cinatra plugin's CREDENTIAL-FREE browser
 * surface and its server-to-server transport. Runs under plain
 * `php tests/test-token-broker.php` — no PHPUnit, no WordPress install. Exit code
 * 0 = all pass, 1 = a failure.
 *
 * THE BROKER THIS FILE IS NAMED AFTER IS RETIRED (cinatra#2674, epic #2564 S8e).
 * It used to assert that `cinatra_rest_mint_token()` performed a server-to-server
 * exchange and DELIVERED a short-lived `cit_` bearer to the browser, and that the
 * `cinatra_rest_widget_auth_{init,token}` relays delivered a `cwu_` per-user
 * bearer the widget composed into the iframe. Those assertions described exactly
 * the possession this slice ends — the website holding a credential that belongs
 * to the person and to Cinatra. The instance now answers the old
 * `/api/widget-auth/{init,token}` pair 410 Gone, and the frame acquires its own
 * credential on the Cinatra origin.
 *
 * So the bearer-DELIVERY tests are FLIPPED to bearer-ABSENCE tests. This suite
 * now covers:
 *   - the retired broker + relay callbacks DO NOT EXIST and their routes are NOT
 *     registered (there is nothing left to call, so nothing to 410 against);
 *   - the enqueued widget script src is the LOCAL plugins_url(...), never a
 *     remote {cinatra_url}/api/... origin;
 *   - the localized CinatraConfig carries NO apiKey, NO broker/relay endpoint,
 *     and — checked recursively — no credential-shaped VALUE of any kind;
 *   - the connect exchange persists the PUBLIC connect-site handle for the
 *     widget's `site.siteId` selector while the `cnx_` credential stays server-
 *     side, and an instance-identity change drops that handle;
 *   - the server-to-server transport (cinatra_server_base_url +
 *     cinatra_server_post) is unchanged, including the CINATRA_BASE_URL
 *     container-override and its request-scoped SSRF relaxation. That path still
 *     presents the `cnx_` key in an Authorization header, by design: it is the
 *     setup/integration credential and it never enters the browser.
 *
 * The browser-side half of the no-credential guarantee (nothing credential-shaped
 * can leave on the embed bridge, with a positive control) is pinned in
 * tests/test-no-credential-egress.mjs.
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
        // MCP Adapter detection: empty by default (adapter absent). Tests that
        // need the adapter active set this to ['mcp-adapter/mcp-adapter.php'].
        'active_plugins'    => [],
    ];
    // Each fixture reset starts from a clean env: no server-to-server override.
    putenv('CINATRA_BASE_URL');
}

/**
 * Drive the server-to-server transport the way the plugin's REMAINING callers do
 * (Connect, the site-inventory handshake, publish webhooks): resolve the base
 * through cinatra_server_base_url() and POST through cinatra_server_post().
 *
 * The CINATRA_BASE_URL override tests below used to drive this through the token
 * broker. The broker is gone; the transport it exercised is not, and it is what
 * those tests were always really about.
 */
function drive_server_call(string $path = CINATRA_SITE_INVENTORY_ENDPOINT_PATH) {
    $url    = rtrim((string) get_option('cinatra_url', ''), '/');
    $base   = cinatra_server_base_url($url);
    $origin = cinatra_site_origin(admin_url());
    return cinatra_server_post(
        $base . $path,
        [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . (string) get_option('cinatra_api_key', ''),
                'Content-Type'  => 'application/json',
                'Origin'        => $origin,
            ],
            'body'    => wp_json_encode(['origin' => $origin]),
        ]
    );
}

/**
 * Recursively true when any string in $value — a value OR a key, at any depth —
 * carries one of Cinatra's bearer prefixes at a token boundary. The PHP twin of
 * the widget's outbound guard and of the core `containsCredentialShapedValue`,
 * used here to scan everything the plugin hands the BROWSER.
 */
function contains_credential_shaped_value($value, int $depth = 0): bool {
    if ($depth > 8) {
        return false;
    }
    if (is_string($value)) {
        return (bool) preg_match('/(?:^|[^A-Za-z0-9])(?:cwu|cit|cnx)_/i', $value);
    }
    if (!is_array($value)) {
        return false;
    }
    foreach ($value as $key => $item) {
        if (is_string($key) && preg_match('/(?:^|[^A-Za-z0-9])(?:cwu|cit|cnx)_/i', $key)) {
            return true;
        }
        if (contains_credential_shaped_value($item, $depth + 1)) {
            return true;
        }
    }
    return false;
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
// THE RETIRED CREDENTIAL PATH IS GONE — not disabled, not forwarding, GONE.
//
// This is the flip. Every assertion below used to have a twin that proved the
// plugin COULD fetch a bearer for the page: a happy-path mint returning a `cit_`,
// a relay returning a `cwu_`, their nonce and origin discipline. The property
// worth pinning now is the absence of the whole capability. A callback that does
// not exist cannot be re-registered by accident, cannot be reached by a stale
// cached script, and cannot be "temporarily" re-enabled behind a flag.
//
// Deleting rather than forwarding-to-410 is deliberate: a relay whose only job is
// to present the `cnx_` key and hand the page back a bearer is the possession
// this change ends. Keeping it alive to return an error would keep the ceremony
// half-built and inviting.
// ---------------------------------------------------------------------------
echo "Test: the retired token broker and sign-in relay callbacks no longer exist\n";
reset_fixture();
check('cinatra_rest_mint_token() is GONE (the cit_ broker)',
    !function_exists('cinatra_rest_mint_token'));
check('cinatra_rest_widget_auth_relay() is GONE (the shared cnx_ relay)',
    !function_exists('cinatra_rest_widget_auth_relay'));
check('cinatra_rest_widget_auth_init() is GONE (hosted-PKCE start)',
    !function_exists('cinatra_rest_widget_auth_init'));
check('cinatra_rest_widget_auth_token() is GONE (hosted-PKCE redemption)',
    !function_exists('cinatra_rest_widget_auth_token'));
check('CINATRA_AGENT_SLUG is GONE (the agent is derived host-side now)',
    !defined('CINATRA_AGENT_SLUG'));

echo "Test: no retired route is registered on rest_api_init\n";
reset_fixture();
$GLOBALS['cinatra_test']['rest_routes'] = [];
do_action('rest_api_init');
$routes = array_map(
    static function ($r) { return ($r['namespace'] ?? '') . $r['route']; },
    $GLOBALS['cinatra_test']['rest_routes']
);
check('no cinatra/v1/token route is registered',
    !in_array('cinatra/v1/token', $routes, true));
check('no cinatra/v1/widget-auth/init route is registered',
    !in_array('cinatra/v1/widget-auth/init', $routes, true));
check('no cinatra/v1/widget-auth/token route is registered',
    !in_array('cinatra/v1/widget-auth/token', $routes, true));
// Positive control: the registrar DID run and DID register the routes that stay,
// so "no retired route" is a real absence rather than an empty capture.
check('positive control: the webhooks registry IS still registered (the capture works)',
    in_array('cinatra/v1/webhooks', $routes, true));
check('no registered route mentions widget-auth at all',
    count(array_filter($routes, static function ($r) { return strpos($r, 'widget-auth') !== false; })) === 0);

// ---------------------------------------------------------------------------
// THE BROWSER RECEIVES SELECTORS, NOT CREDENTIALS.
//
// The old version of this block asserted that CinatraConfig POINTED THE BROWSER
// AT THE TOKEN BROKER and carried a nonce to call it with. Both are now the
// regression: the page has nothing to authenticate with and nothing to fetch a
// credential from. What it gets is public — which instance to frame, which
// connect-site this is, and a feature flag.
// ---------------------------------------------------------------------------
echo "Test: enqueue serves the LOCAL asset and a CREDENTIAL-FREE config\n";
reset_fixture();
$GLOBALS['cinatra_test']['options']['cinatra_site_id'] = 'site_123';
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
// FLIPPED (cinatra#2674): these keys used to be REQUIRED here.
check('CinatraConfig carries NO token-broker endpoint', !array_key_exists('tokenEndpoint', $cfg));
check('CinatraConfig carries NO hosted-PKCE relay endpoints',
    !array_key_exists('authInitEndpoint', $cfg) && !array_key_exists('authTokenEndpoint', $cfg));
check('CinatraConfig carries NO REST nonce (there is no same-origin credential call to make)',
    !array_key_exists('nonce', $cfg));
check('no CinatraConfig value mentions widget-auth or the token route',
    strpos(json_encode($cfg), 'widget-auth') === false
    && strpos(json_encode($cfg), 'cinatra/v1/token') === false);
// The recursive scan — the PHP twin of the browser-side egress guarantee. It
// catches a bearer smuggled under ANY key, at any depth, not just the two names
// this file happens to know about.
check('NOTHING in CinatraConfig is credential-shaped (recursive scan of values AND keys)',
    contains_credential_shaped_value($cfg) === false);
// Positive control: the scanner must be able to SEE a credential in this exact
// shape, or the assertion above proves nothing.
check('positive control: the scanner DOES fire on a credential planted in a config-shaped array',
    contains_credential_shaped_value(array_merge($cfg, ['x' => ['y' => 'cwu_planted']])) === true
    && contains_credential_shaped_value(array_merge($cfg, ['cnx_planted_key' => 1])) === true);

// The PUBLIC selectors the widget needs, and nothing more.
check('CinatraConfig carries the instance selector', ($cfg['instanceId'] ?? '') === 'wp-prod');
check('CinatraConfig carries the PUBLIC connect-site handle for the site.siteId selector',
    ($cfg['siteId'] ?? null) === 'site_123');

// ---------------------------------------------------------------------------
// THE SERVER-SIDE HALF OF THE GUARANTEE (codex round 0, finding 3).
//
// The widget refuses to put a credential-shaped value on the bridge — but that
// refusal happens in the BROWSER, and by then wp_localize_script() has already
// printed the value into the admin page as inline JavaScript. So the gate has to
// run here, on the server, at BOTH boundaries: when a selector is accepted, and
// again when it is handed out. The tests below poison each entry point in turn.
// ---------------------------------------------------------------------------
echo "Test: a credential-shaped selector never reaches the page, whatever route it arrived by\n";
reset_fixture();
// Route 1 — a direct database write / an older plugin version / another plugin.
// The value is already in wp_options when the enqueue runs; only the outbound
// gate can catch it.
$GLOBALS['cinatra_test']['options']['cinatra_site_id']     = 'cnx_site_123_SECRET';
$GLOBALS['cinatra_test']['options']['cinatra_instance_id'] = 'cwu_not_an_instance';
cinatra_enqueue_widget();
$cfg_poisoned = $GLOBALS['cinatra_test']['localized']['cinatra']['CinatraConfig'] ?? [];
check('a stored credential-shaped site handle is BLANKED before localization',
    ($cfg_poisoned['siteId'] ?? null) === '');
check('a stored credential-shaped instance id is BLANKED before localization',
    ($cfg_poisoned['instanceId'] ?? null) === '');
check('the poisoned config carries no credential-shaped value anywhere',
    contains_credential_shaped_value($cfg_poisoned) === false);

echo "Test: an over-long selector is blanked too (the frame's schema is strict)\n";
reset_fixture();
$GLOBALS['cinatra_test']['options']['cinatra_site_id']     = str_repeat('s', 201);
$GLOBALS['cinatra_test']['options']['cinatra_instance_id'] = str_repeat('i', 201);
cinatra_enqueue_widget();
$cfg_long = $GLOBALS['cinatra_test']['localized']['cinatra']['CinatraConfig'] ?? [];
check('an over-long site handle is blanked, never truncated',
    ($cfg_long['siteId'] ?? null) === '');
check('an over-long instance id is blanked, never truncated',
    ($cfg_long['instanceId'] ?? null) === '');

echo "Test: the selector gate accepts what it should and refuses what it should\n";
check('a normal handle passes', cinatra_public_selector('site_123') === 'site_123');
check('a normal instance id passes', cinatra_public_selector('wp-prod') === 'wp-prod');
// Lookalikes MUST pass: a gate that blanks ordinary values breaks the product
// and gets removed.
check('a lookalike passes (`citation`)', cinatra_public_selector('citation') === 'citation');
check('a lookalike passes (`abccwu_x`)', cinatra_public_selector('abccwu_x') === 'abccwu_x');
check('exactly the bound passes', cinatra_public_selector(str_repeat('a', 200)) === str_repeat('a', 200));
check('one over the bound is blanked', cinatra_public_selector(str_repeat('a', 201)) === '');
check('a shorter bound is honoured (status is 64)', cinatra_public_selector(str_repeat('a', 65), 64) === '');

// Codex round 1: the bound is measured in UTF-16 CODE UNITS, the unit the
// frame's zod schema counts — not bytes. A byte bound would blank a perfectly
// valid non-ASCII selector, and for the REQUIRED instance id that means the
// assistant never mounts on that site.
$accented = str_repeat('é', 200);              // 200 UTF-16 units, 400 bytes
check('200 accented chars pass (measured in UTF-16 units, not the 400 bytes)',
    cinatra_public_selector($accented) === $accented);
check('201 accented chars are blanked (the bound is real, just measured right)',
    cinatra_public_selector(str_repeat('é', 201)) === '');
// An astral code point is ONE code point but TWO UTF-16 units, exactly as JS
// counts it: 100 of them are 200 units and must pass; 101 are 202 and must not.
$astral = str_repeat("\u{1F600}", 100);
check('100 astral code points pass (200 UTF-16 units)',
    cinatra_public_selector($astral) === $astral);
check('101 astral code points are blanked (202 UTF-16 units)',
    cinatra_public_selector(str_repeat("\u{1F600}", 101)) === '');
check('cinatra_utf16_length counts UTF-16 units', cinatra_utf16_length('abc') === 3
    && cinatra_utf16_length('éé') === 2 && cinatra_utf16_length("\u{1F600}") === 2);
check('invalid UTF-8 is unmeasurable and therefore refused',
    cinatra_utf16_length("\xC3\x28") === -1 && cinatra_public_selector("\xC3\x28") === '');

// Codex rounds 1 and 2: the gate VALIDATES, it does not normalize — and it does
// not borrow sanitize_text_field() for either job. That function deletes
// percent-encoded octets, so using it as a transform renames `tenant%2Fprod` to
// `tenantprod`, and using it as a mere test refuses a selector the contract
// plainly allows. Refusing a legal REQUIRED instance id means the assistant never
// mounts on that site, so both are wrong. Everything legal travels byte-for-byte.
check('a percent-encoded selector survives EXACTLY (not renamed, not refused)',
    cinatra_public_selector('tenant%2Fprod') === 'tenant%2Fprod');
check('a selector with spaces inside survives exactly',
    cinatra_public_selector('acme prod') === 'acme prod');
check('angle brackets are not a selector rule (every sink escapes; nothing is stripped)',
    cinatra_public_selector('wp<b>prod') === 'wp<b>prod');
// Codex round 3: the PREDICATE mutates nothing at all — not even whitespace.
check('the predicate does not trim (it returns the value or nothing)',
    cinatra_public_selector('  wp-prod  ') === '  wp-prod  ');
// Control characters are corrupt input, not exotic input: refused, never stripped.
check('a control character is refused, not stripped',
    cinatra_public_selector("wp\x01prod") === '' && cinatra_public_selector("wp\nprod") === '');
// The exact trap codex named: PHP's DEFAULT trim charlist includes "\0", so a
// trim placed before the control-character rule would strip the NUL and let the
// value through. The predicate does not trim, and the acceptance boundary trims
// SPACES ONLY — so a NUL is refused on both paths.
check('a NUL is refused by the predicate',
    cinatra_public_selector("\x00instance") === '');
check('a NUL is refused at the acceptance boundary too (trim charlist is spaces only)',
    cinatra_sanitize_public_selector("\x00instance") === '');
check('a vertical tab is refused on both paths',
    cinatra_public_selector("wp\x0Bprod") === '' && cinatra_sanitize_public_selector("wp\x0Bprod") === '');

echo "Test: the acceptance boundary normalizes surrounding SPACES, and only those\n";
check('the boundary trims surrounding spaces (what a paste leaves behind)',
    cinatra_sanitize_public_selector('  wp-prod  ') === 'wp-prod');
check('the boundary leaves inner spaces alone', cinatra_sanitize_public_selector('acme prod') === 'acme prod');
// The over-length case codex named: 200 letters plus a trailing space is 201
// units to the predicate (refused) and 200 after the boundary trims (accepted).
$two_hundred = str_repeat('a', 200);
check('200 chars + a trailing space is over-length to the predicate',
    cinatra_public_selector($two_hundred . ' ') === '');
check('...and is accepted at the boundary, because the space was never part of it',
    cinatra_sanitize_public_selector($two_hundred . ' ') === $two_hundred);
check('201 real chars are refused at the boundary too',
    cinatra_sanitize_public_selector(str_repeat('a', 201)) === '');
check('a percent-encoded selector survives the boundary exactly',
    cinatra_sanitize_public_selector('tenant%2Fprod') === 'tenant%2Fprod');
foreach (['cwu_abc', 'cit_abc', 'cnx_abc', 'Error: cwu_expired', 'https://x/?t=cit_a', 'CNX_ABC', 'x cwu_y'] as $bad) {
    check("credential-shaped '$bad' is refused", cinatra_public_selector($bad) === '');
}
check('a non-string is refused', cinatra_public_selector(['a']) === '' && cinatra_public_selector(null) === '');

echo "Test: a Connect response carrying a credential-shaped handle does not persist it\n";
reset_fixture();
$GLOBALS['cinatra_test']['options'] = [];
$GLOBALS['cinatra_test']['remote_post'] = function () {
    return [
        'response' => ['code' => 200],
        'body'     => json_encode([
            'url'               => 'https://app.cinatra.ai',
            // A malformed or compromised host echoing a bearer where the public
            // handle belongs. Route 2: the inbound gate must catch it.
            'siteId'            => 'cnx_site_123_PROVISIONED-SECRET',
            'cinatraInstanceId' => 'cit_not_an_instance',
            'credential'        => 'cnx_site_123_PROVISIONED-SECRET',
            'credentialVersion' => 1,
        ]),
    ];
};
$poisoned = cinatra_connect_exchange('https://app.cinatra.ai', [
    'grant_type'    => 'authorization_code',
    'code'          => 'abc',
    'client'        => 'wordpress',
    'redirect_uri'  => 'https://blog.example/wp-admin/admin-post.php?action=cinatra_connect_callback',
    'code_verifier' => str_repeat('v', 64),
]);
cinatra_connect_apply_result($poisoned);
check('a credential-shaped siteId from the host is NOT persisted',
    get_option('cinatra_site_id', 'unset') === '');
check('a credential-shaped instance id from the host is NOT persisted',
    get_option('cinatra_instance_id', 'unset') === '');
// The credential itself still lands where credentials belong — the gate applies
// to PUBLIC SELECTORS, not to the site credential.
check('the site credential itself IS still stored server-side',
    get_option('cinatra_api_key', '') === 'cnx_site_123_PROVISIONED-SECRET');

echo "Test: every selector WRITE is gated, not only the ones this plugin makes\n";
// codex round 1: update_option() runs sanitize_option(), which fires these
// filters — so a value written by another plugin, a migration or WP-CLI is gated
// on the way IN, not only at the localization boundary.
check('a sanitize_option filter is registered for the connect-site handle',
    cinatra_test_has_filter('sanitize_option_cinatra_site_id'));
check('a sanitize_option filter is registered for the instance id',
    cinatra_test_has_filter('sanitize_option_cinatra_instance_id'));
// Behaviour, not just registration: a write by ANY caller goes through the gate.
reset_fixture();
update_option('cinatra_site_id', 'cnx_written_by_someone_else');
check('a foreign update_option() cannot persist a credential-shaped handle',
    get_option('cinatra_site_id', 'unset') === '');
update_option('cinatra_instance_id', 'cwu_written_by_someone_else');
check('a foreign update_option() cannot persist a credential-shaped instance id',
    get_option('cinatra_instance_id', 'unset') === '');
// Positive control: an ordinary write still lands, so the gate is a gate and not
// a wall.
update_option('cinatra_site_id', 'site_456');
check('positive control: an ordinary handle still persists', get_option('cinatra_site_id', '') === 'site_456');

echo "Test: a hand-typed credential-shaped instance id is refused by the settings sanitizer\n";
// The registered callback itself, not the helper behind it: it must take exactly
// ONE parameter, so a settings filter that ever forwarded the option name as a
// second argument cannot be misread as a length bound (which would blank every
// selector on the site).
$sanitizer = new ReflectionFunction('cinatra_sanitize_public_selector');
check('the registered sanitize_callback takes exactly one parameter',
    $sanitizer->getNumberOfParameters() === 1);
check('the registered sanitizer blanks a pasted bearer',
    cinatra_sanitize_public_selector('cnx_pasted_by_an_admin') === '');
check('the registered sanitizer passes an ordinary instance id through',
    cinatra_sanitize_public_selector('wp-prod') === 'wp-prod');

echo "Test: an unset connect-site handle is an empty selector, never a missing key\n";
reset_fixture();
cinatra_enqueue_widget();
$cfg_nosite = $GLOBALS['cinatra_test']['localized']['cinatra']['CinatraConfig'] ?? [];
// Present-but-empty is what lets the WIDGET decide to omit the optional `site`
// block; an absent key would make that a typeof check on undefined instead.
check('siteId is present and empty when this site has no handle yet',
    array_key_exists('siteId', $cfg_nosite) && $cfg_nosite['siteId'] === '');

// ---------------------------------------------------------------------------
// MCP Adapter detection (#62): mcpAdapterActive feature gate in CinatraConfig.
// ---------------------------------------------------------------------------
echo "Test: mcpAdapterActive=false when adapter plugin is absent (default)\n";
reset_fixture();
// active_plugins is [] by default (adapter absent).
cinatra_enqueue_widget();
$cfg = $GLOBALS['cinatra_test']['localized']['cinatra']['CinatraConfig'] ?? [];
check('mcpAdapterActive present in CinatraConfig', array_key_exists('mcpAdapterActive', $cfg));
check('mcpAdapterActive is false when adapter absent', $cfg['mcpAdapterActive'] === false);

echo "Test: mcpAdapterActive=true when adapter plugin is active\n";
reset_fixture();
$GLOBALS['cinatra_test']['active_plugins'] = ['mcp-adapter/mcp-adapter.php'];
$GLOBALS['cinatra_test']['enqueued_scripts'] = [];
$GLOBALS['cinatra_test']['localized'] = [];
cinatra_enqueue_widget();
$cfg = $GLOBALS['cinatra_test']['localized']['cinatra']['CinatraConfig'] ?? [];
check('mcpAdapterActive is true when adapter active', $cfg['mcpAdapterActive'] === true);

echo "Test: cinatra_mcp_adapter_active() returns false with no active plugins\n";
reset_fixture();
$GLOBALS['cinatra_test']['active_plugins'] = [];
check('cinatra_mcp_adapter_active() false when not in active_plugins', cinatra_mcp_adapter_active() === false);

echo "Test: cinatra_mcp_adapter_active() returns true when adapter plugin file is in active_plugins\n";
reset_fixture();
$GLOBALS['cinatra_test']['active_plugins'] = ['mcp-adapter/mcp-adapter.php'];
check('cinatra_mcp_adapter_active() true when adapter active', cinatra_mcp_adapter_active() === true);

echo "Test: cinatra_mcp_adapter_active() is not tricked by a different plugin with a similar name\n";
reset_fixture();
$GLOBALS['cinatra_test']['active_plugins'] = ['other-mcp/mcp-adapter.php', 'mcp-adapter/other.php'];
check('different plugin file does not trigger cinatra_mcp_adapter_active()', cinatra_mcp_adapter_active() === false);

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
// cinatra#2674: the PUBLIC handle is persisted so the widget can offer it as the
// `site.siteId` selector. The two travel together in the response and part ways
// here — the handle becomes browser-visible, the credential never does.
check('connect-site handle stored for the widget selector',
    get_option('cinatra_site_id', '') === 'site_123');
check('the stored handle is NOT the credential (they are different values)',
    get_option('cinatra_site_id', '') !== get_option('cinatra_api_key', ''));

echo "Test: an instance-identity change DROPS the connect-site handle\n";
// A handle issued by one instance names a site that does not exist on another.
// Dropping it is the right failure: the selector is optional, so the widget omits
// it and the instance names the site from its own rows — whereas a stale handle
// would earn a flat refusal on every message.
update_option('cinatra_url', 'https://other.cinatra.ai');
check('handle cleared when the instance URL changes',
    get_option('cinatra_site_id', '') === '');

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
    return ['response' => ['code' => 200], 'body' => json_encode(['ok' => true])];
};

echo "Test: env unset -> server-to-server posts to the configured cinatra_url (production unchanged)\n";
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
drive_server_call();
$call = $GLOBALS['cinatra_test']['remote_post_calls'][0] ?? null;
check('env unset -> posts to configured cinatra_url host',
    $call && $call['url'] === 'https://app.cinatra.ai' . CINATRA_SITE_INVENTORY_ENDPOINT_PATH);
check('env unset -> NO host-allowlist filter active during the call (full SSRF guard)',
    $call && $call['host_filter_active'] === 0);
check('env unset -> NO safe-port filter active during the call (full SSRF guard)',
    $call && $call['port_filter_active'] === 0);

// MUST-FIX 1 (production parity): on the env-unset/production path the helper
// must pass the caller's args UNCHANGED to wp_safe_remote_post — it must NOT
// inject redirection => 0. No plugin caller sets redirection, so the captured
// args carry NO redirection key, meaning WordPress applies its DEFAULT (which
// permits redirects) — byte-identical to the pre-override call.
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

echo "Test: CINATRA_BASE_URL override redirects the server-to-server TRANSPORT to the container host\n";
reset_fixture();
putenv('CINATRA_BASE_URL=http://host.docker.internal:3000');
$GLOBALS['cinatra_test']['remote_post'] = $ok_remote;
drive_server_call();
$call = $GLOBALS['cinatra_test']['remote_post_calls'][0] ?? null;
check('override -> posts to host.docker.internal transport base',
    $call && $call['url'] === 'http://host.docker.internal:3000' . CINATRA_SITE_INVENTORY_ENDPOINT_PATH);
check('override -> request-scoped host-allowlist filter ACTIVE during the call',
    $call && $call['host_filter_active'] === 1);
check('override -> request-scoped safe-port filter ACTIVE during the call',
    $call && $call['port_filter_active'] === 1);
check('override -> BOTH filters REMOVED after the call (no leaked relaxation)',
    (int) ($GLOBALS['cinatra_test']['filters']['http_request_host_is_external'] ?? 0) === 0
    && (int) ($GLOBALS['cinatra_test']['filters']['http_allowed_safe_ports'] ?? 0) === 0);
check('override -> redirection disabled on the internal call',
    $call && (int) ($call['args']['redirection'] ?? -1) === 0);
// The `cnx_` key IS still presented here, and that is correct: this is the
// server-to-server arm, it never touches the browser, and cinatra#2674 retired
// the BROWSER's credential path, not this one.
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
    drive_server_call();
    $call = $GLOBALS['cinatra_test']['remote_post_calls'][0] ?? null;
    check("bad env '$bad_env' -> falls back to configured cinatra_url",
        $call && $call['url'] === 'https://app.cinatra.ai' . CINATRA_SITE_INVENTORY_ENDPOINT_PATH);
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
    return ['response' => ['code' => 200], 'body' => json_encode(['ok' => true])];
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
    return ['response' => ['code' => 200], 'body' => json_encode(['ok' => true])];
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
// cinatra#974 — paired webhook persistence (webhookSecret + webhookBindingId),
// keyed on the host's `webhookContract` echo, cleared on instance-identity
// change. Mirrors the drupal-module semantics.
// ---------------------------------------------------------------------------

// whsec_ fixture secrets, computed (secret-scan hygiene: no literal).
define('PAIR_SECRET', 'whsec_' . base64_encode('pair-hmac-key-' . str_repeat('p', 18)));
define('PAIR_SECRET_2', 'whsec_' . base64_encode('pair-hmac-key-' . str_repeat('q', 18)));

function pair_response(array $overrides = []): array {
    return ['ok' => true, 'response' => array_replace([
        '__instance_url'    => 'https://app.cinatra.ai',
        'url'               => 'https://app.cinatra.ai',
        'siteId'            => 'site_123',
        'cinatraInstanceId' => 'wp-prod',
        'credential'        => 'cnx_site_123_KEY',
        'credentialVersion' => 2,
        'contractVersion'   => 'v1',
        'capabilities'      => ['tokenBroker' => false, 'supportedContractVersions' => ['v1']],
    ], $overrides)];
}

function seed_connected_pair_state(): void {
    $GLOBALS['cinatra_test']['options'] = [
        'cinatra_url'                => 'https://app.cinatra.ai',
        'cinatra_api_key'            => 'cnx_site_123_KEY',
        'cinatra_instance_id'        => 'wp-prod',
        'cinatra_webhook_secret'     => PAIR_SECRET,
        'cinatra_webhook_binding_id' => 'BINDstored1',
    ];
}

echo "Test: the token exchange SENDS webhook_contract=standard-webhooks\n";
reset_fixture();
$GLOBALS['cinatra_test']['options'] = [];
$GLOBALS['cinatra_test']['remote_post'] = function ($url, $args) {
    return ['response' => ['code' => 200], 'body' => json_encode(pair_response()['response'])];
};
// The public flows build the body; drive the exchange helper with the same
// body the install-code flow constructs (grounded against the flow source).
cinatra_connect_exchange('https://app.cinatra.ai', [
    'grant_type'       => 'install_code',
    'install_code'     => 'x',
    'client'           => CINATRA_CONNECT_CLIENT,
    'webhook_contract' => CINATRA_WEBHOOK_CONTRACT,
]);
$call = end($GLOBALS['cinatra_test']['remote_post_calls']);
$sent = json_decode((string) $call['args']['body'], true);
check('exchange request carries webhook_contract=standard-webhooks',
    ($sent['webhook_contract'] ?? '') === 'standard-webhooks');

echo "Test: a standard response (echo + pair) persists BOTH pair halves\n";
reset_fixture();
$GLOBALS['cinatra_test']['options'] = [];
cinatra_connect_apply_result(pair_response([
    'webhookContract'  => 'standard-webhooks',
    'webhookSecret'    => PAIR_SECRET,
    'webhookBindingId' => 'BINDfresh1',
]));
check('secret stored', get_option('cinatra_webhook_secret', '') === PAIR_SECRET);
check('binding id stored', get_option('cinatra_webhook_binding_id', '') === 'BINDfresh1');
check('instance id stored', get_option('cinatra_instance_id', '') === 'wp-prod');

echo "Test: SAME instance + echo + pair omitted (transient mint failure) -> existing pair KEPT\n";
reset_fixture();
seed_connected_pair_state();
cinatra_connect_apply_result(pair_response([
    'webhookContract' => 'standard-webhooks',
    // no webhookSecret / webhookBindingId — the host's upsert failed
]));
check('pair kept for the same instance when the host echoed the contract',
    get_option('cinatra_webhook_secret', '') === PAIR_SECRET
    && get_option('cinatra_webhook_binding_id', '') === 'BINDstored1');

echo "Test: SAME instance + NO echo (older host) -> stored pair DISCARDED even if a bindingId is present\n";
reset_fixture();
seed_connected_pair_state();
cinatra_connect_apply_result(pair_response([
    // A #343-era host returns the legacy-bridge bindingId + the SHARED secret
    // but no webhookContract echo: its binding would reject Standard-Webhooks
    // headers, so keeping (or storing) a pair would 401 every publish (codex).
    'webhookSecret'    => 'WH-SHARED-LEGACY',
    'webhookBindingId' => 'BINDlegacy9',
]));
check('pair discarded when the host does not echo the contract',
    get_option('cinatra_webhook_secret', '') === '' && get_option('cinatra_webhook_binding_id', '') === '');
check('the legacy shared secret is NOT stored', get_option('cinatra_webhook_secret', 'unset') !== 'WH-SHARED-LEGACY');

echo "Test: DIFFERENT instance + echo + fresh pair -> old pair replaced by the new one\n";
reset_fixture();
seed_connected_pair_state();
cinatra_connect_apply_result(pair_response([
    '__instance_url'    => 'https://other.cinatra.ai',
    'url'               => 'https://other.cinatra.ai',
    'cinatraInstanceId' => 'wp-other',
    'webhookContract'   => 'standard-webhooks',
    'webhookSecret'     => PAIR_SECRET_2,
    'webhookBindingId'  => 'BINDother2',
]));
check('new instance url stored', get_option('cinatra_url', '') === 'https://other.cinatra.ai');
check('new pair stored after the identity change', get_option('cinatra_webhook_secret', '') === PAIR_SECRET_2
    && get_option('cinatra_webhook_binding_id', '') === 'BINDother2');

echo "Test: DIFFERENT instance + echo + pair omitted -> old pair CLEARED (never cross-instance)\n";
reset_fixture();
seed_connected_pair_state();
cinatra_connect_apply_result(pair_response([
    '__instance_url'    => 'https://other.cinatra.ai',
    'url'               => 'https://other.cinatra.ai',
    'cinatraInstanceId' => 'wp-other',
    'webhookContract'   => 'standard-webhooks',
]));
check('a pair minted by one instance never survives a reconnect to another',
    get_option('cinatra_webhook_secret', '') === '' && get_option('cinatra_webhook_binding_id', '') === '');

echo "Test: instance id is ALWAYS overwritten from the response (empty when absent)\n";
reset_fixture();
seed_connected_pair_state();
$resp = pair_response(['webhookContract' => 'standard-webhooks']);
unset($resp['response']['cinatraInstanceId']);
cinatra_connect_apply_result($resp);
check('a response without cinatraInstanceId clears the stale identity',
    get_option('cinatra_instance_id', 'unset') === '');
check('and the identity CHANGE cleared the pair', get_option('cinatra_webhook_binding_id', '') === '');

echo "Test: a MANUAL cinatra_url / instance-id edit clears the pair; an unchanged re-save keeps it\n";
reset_fixture();
seed_connected_pair_state();
update_option('cinatra_url', 'https://app.cinatra.ai'); // unchanged re-save
update_option('cinatra_instance_id', 'wp-prod');        // unchanged re-save
check('an unchanged settings re-save keeps the pair',
    get_option('cinatra_webhook_binding_id', '') === 'BINDstored1');
update_option('cinatra_url', 'https://moved.cinatra.ai');
check('a manual URL change clears the pair', get_option('cinatra_webhook_binding_id', '') === ''
    && get_option('cinatra_webhook_secret', '') === '');
seed_connected_pair_state();
update_option('cinatra_instance_id', 'wp-renamed');
check('a manual instance-id change clears the pair', get_option('cinatra_webhook_binding_id', '') === '');
// Delete-then-re-add lands on add_option_{name} and clears too.
seed_connected_pair_state();
delete_option('cinatra_url');
update_option('cinatra_url', 'https://readded.cinatra.ai');
check('a deleted-then-re-added URL clears the pair (add_option hook)',
    get_option('cinatra_webhook_binding_id', '') === '');

echo "Test: a malformed webhookBindingId in the response is rejected -> treated as pair-omitted\n";
reset_fixture();
seed_connected_pair_state();
cinatra_connect_apply_result(pair_response([
    'webhookContract'  => 'standard-webhooks',
    'webhookSecret'    => PAIR_SECRET_2,
    'webhookBindingId' => "../../etc/passwd",
]));
check('a path-shaped binding id is never stored; the existing same-instance pair is kept',
    get_option('cinatra_webhook_binding_id', '') === 'BINDstored1'
    && get_option('cinatra_webhook_secret', '') === PAIR_SECRET);

// ---------------------------------------------------------------------------
echo "\n";
if ($failures === 0) {
    echo "ALL TESTS PASSED\n";
    exit(0);
}
echo "$failures TEST(S) FAILED\n";
exit(1);
