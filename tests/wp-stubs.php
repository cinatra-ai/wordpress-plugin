<?php
/**
 * Minimal WordPress function/class stubs so cinatra.php can be `require`d and its
 * REST/enqueue logic exercised under plain `php` (no WordPress install, no DB).
 *
 * This is deliberately tiny and dependency-free: the CI image already has PHP,
 * and these tests gate behavior (no secret leak, broker contract) without the
 * full WP-PHPUnit harness. Test bodies drive behavior through the mutable
 * $GLOBALS['cinatra_test'] fixture below.
 */

// ---------------------------------------------------------------------------
// Mutable test fixture — tests set these before invoking plugin code.
// ---------------------------------------------------------------------------
$GLOBALS['cinatra_test'] = [
    'options'              => [],   // option_name => value
    'current_user_can'     => true, // return value for current_user_can()
    'current_user_id'      => 7,
    'valid_nonces'         => ['wp_rest'],
    'home_url'             => 'https://blog.example',
    'enqueued_scripts'     => [],   // handle => [src, deps, ver, in_footer]
    'enqueued_styles'      => [],   // handle => [src, deps, ver, media]
    'inline_styles'        => [],   // handle => [css, ...]
    'inline_scripts'       => [],   // handle => [js, ...]
    'transients'           => [],   // key => value
    'localized'            => [],   // handle => [object_name => data]
    'remote_post'          => null, // canned wp_remote_post response/WP_Error
    'remote_post_calls'    => [],   // captured wp_remote_post args (+ filters_active snapshot)
    'filters'              => [],   // hook => count of currently-registered callbacks
    'filter_cbs'           => [],   // hook => [live callbacks] (for safe-request replay)
    'active_plugins'       => [],   // active plugin files for is_plugin_active() stub
];

// ---------------------------------------------------------------------------
// Constants / guards
// ---------------------------------------------------------------------------
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// ---------------------------------------------------------------------------
// Hook + i18n stubs (no-ops / capture)
// ---------------------------------------------------------------------------
function add_action($hook, $cb, $priority = 10, $args = 1) {
    // Captured in a global OUTSIDE $GLOBALS['cinatra_test'] so reset_fixture()
    // (which replaces that array wholesale) never wipes hooks registered at
    // plugin load time. Only the option-lifecycle hooks are ever REPLAYED (see
    // update_option below); everything else is capture-only.
    $GLOBALS['cinatra_test_actions'][$hook][] = $cb;
    return true;
}
function cinatra_test_do_action($hook, ...$args) {
    foreach ($GLOBALS['cinatra_test_actions'][$hook] ?? [] as $cb) {
        call_user_func_array($cb, $args);
    }
}
function add_filter($hook, $cb, $priority = 10, $args = 1) {
    // Track filters by hook so tests can assert request-scoped add/remove, AND
    // keep the live callbacks so the HTTP stub can replay WordPress's real
    // safe-request validation (host-externality + safe-port).
    $GLOBALS['cinatra_test']['filters'][$hook] = ($GLOBALS['cinatra_test']['filters'][$hook] ?? 0) + 1;
    $GLOBALS['cinatra_test']['filter_cbs'][$hook][] = $cb;
    return true;
}
function remove_filter($hook, $cb, $priority = 10) {
    if (!empty($GLOBALS['cinatra_test']['filters'][$hook])) {
        $GLOBALS['cinatra_test']['filters'][$hook]--;
    }
    if (!empty($GLOBALS['cinatra_test']['filter_cbs'][$hook])) {
        // Remove one matching callback instance (request-scoped add/remove pairs).
        foreach ($GLOBALS['cinatra_test']['filter_cbs'][$hook] as $i => $stored) {
            if ($stored === $cb) { unset($GLOBALS['cinatra_test']['filter_cbs'][$hook][$i]); break; }
        }
    }
    return true;
}

/**
 * Replay WordPress's safe-request gate the way wp_http_validate_url() does:
 * a loopback/private host is BLOCKED unless an http_request_host_is_external
 * filter returns truthy for it, and a non-default port is BLOCKED unless it is
 * in http_allowed_safe_ports (default 80/443/8080). Returns true if the request
 * would be permitted, false if WordPress would reject it.
 */
function cinatra_test_safe_request_allowed($url) {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $port = (int) parse_url($url, PHP_URL_PORT);
    $private = in_array($host, ['localhost', '127.0.0.1', '::1', 'host.docker.internal'], true)
        || (bool) preg_match('/^(10\.|192\.168\.|127\.|169\.254\.|0\.)/', $host);
    if ($private) {
        $is_external = false;
        // WordPress passes ($external, $host, $url) to this filter — replay all
        // three so the plugin's exact-origin host check sees the real request.
        foreach ($GLOBALS['cinatra_test']['filter_cbs']['http_request_host_is_external'] ?? [] as $cb) {
            $is_external = $cb($is_external, $host, $url);
        }
        if (!$is_external) { return false; }
    }
    $safe_ports = [80, 443, 8080];
    // WordPress passes ($ports, $host, $url) to this filter — replay all three so
    // the plugin's exact-origin (host+port) port check sees the real request.
    foreach ($GLOBALS['cinatra_test']['filter_cbs']['http_allowed_safe_ports'] ?? [] as $cb) {
        $safe_ports = $cb($safe_ports, $host, $url);
    }
    if ($port > 0 && !in_array($port, $safe_ports, true)) { return false; }
    return true;
}
function register_setting() { return true; }
function register_rest_route() { return true; }
function __($text, $domain = 'default') { return $text; }
function esc_html__($text, $domain = 'default') { return $text; }
function esc_attr__($text, $domain = 'default') { return $text; }
function esc_html($t) { return $t; }
function esc_url($url) { return $url; }
function esc_url_raw($url, $protocols = null) { return $url; }
function esc_attr($t) { return $t; }
function esc_js($t) { return $t; }
function admin_url($path = '') { return 'https://blog.example/wp-admin/' . ltrim($path, '/'); }
function plugin_basename($f) { return basename($f); }
function settings_fields() {}
function submit_button($text = '', $type = 'primary', $name = 'submit', $wrap = true) {}
function wp_nonce_field($action = -1) {}
function sanitize_hex_color($color) {
    return (is_string($color) && preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color)) ? $color : '';
}
function post_type_exists($pt) { return in_array($pt, ['post', 'page'], true); }
// MCP Adapter detection: fixture-controlled via $GLOBALS['cinatra_test']['active_plugins'].
// The cinatra_mcp_adapter_active() function requires is_plugin_active() which normally
// loads wp-admin/includes/plugin.php; stub it here so tests do not need a real WP install.
function is_plugin_active($plugin) {
    return in_array($plugin, (array) ($GLOBALS['cinatra_test']['active_plugins'] ?? []), true);
}
// HTML sanitisation stubs (pass-through for tests; the tag/attribute filter is irrelevant
// in the test harness since no real HTML output is asserted in these tests).
function wp_kses($text, $allowed_html, $allowed_protocols = []) { return $text; }
function wp_kses_post($text) { return $text; }

// ---------------------------------------------------------------------------
// Post / publish-emitter stubs (wp#48). Behavior is driven by the fixture:
//   $GLOBALS['cinatra_test']['post_types']   => post_type => ['public' => bool]
//   $GLOBALS['cinatra_test']['is_revision']  => bool (wp_is_post_revision)
//   $GLOBALS['cinatra_test']['is_autosave']  => bool (wp_is_post_autosave)
// A WP_Post stub carries the fields the emitter reads.
// ---------------------------------------------------------------------------
class WP_Post {
    public $ID;
    public $post_type;
    public $post_title;
    public $post_status;
    public $post_modified_gmt;
    public $permalink;
    // Additional content fields the Abilities API read/update path serializes
    // (wp#1214). Declared (not dynamic) so PHP 8.2 does not emit a deprecation.
    public $post_content;
    public $post_excerpt;
    public $post_name;
    public function __construct(array $fields = []) {
        $this->ID = $fields['ID'] ?? 0;
        $this->post_type = $fields['post_type'] ?? 'post';
        $this->post_title = $fields['post_title'] ?? '';
        $this->post_status = $fields['post_status'] ?? 'publish';
        $this->post_modified_gmt = $fields['post_modified_gmt'] ?? '2026-06-24 12:00:00';
        $this->permalink = $fields['permalink'] ?? '';
        $this->post_content = $fields['post_content'] ?? '';
        $this->post_excerpt = $fields['post_excerpt'] ?? '';
        $this->post_name = $fields['post_name'] ?? '';
    }
}
function get_the_title($post) {
    return $post instanceof WP_Post ? (string) $post->post_title : '';
}
function get_permalink($post) {
    return $post instanceof WP_Post ? (string) $post->permalink : '';
}
function get_post_type_object($post_type) {
    $registry = $GLOBALS['cinatra_test']['post_types'] ?? [
        'post' => ['public' => true],
        'page' => ['public' => true],
    ];
    if (!array_key_exists($post_type, $registry)) {
        return null;
    }
    return (object) $registry[$post_type];
}
function wp_is_post_revision($post) { return (bool) ($GLOBALS['cinatra_test']['is_revision'] ?? false); }
function wp_is_post_autosave($post) { return (bool) ($GLOBALS['cinatra_test']['is_autosave'] ?? false); }

// ---------------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------------
function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['cinatra_test']['options'])
        ? $GLOBALS['cinatra_test']['options'][$name]
        : $default;
}
function update_option($name, $value) {
    // WP-core semantics (the plugin's option-lifecycle hooks depend on them):
    // an unchanged value is a no-op that fires NOTHING; a missing option is an
    // add (fires add_option_{$name}($name, $value)); a real change fires
    // update_option_{$name}($old, $value, $name).
    $exists = array_key_exists($name, $GLOBALS['cinatra_test']['options']);
    $old    = $exists ? $GLOBALS['cinatra_test']['options'][$name] : null;
    if ($exists && $old === $value) {
        return false;
    }
    $GLOBALS['cinatra_test']['options'][$name] = $value;
    if ($exists) {
        cinatra_test_do_action('update_option_' . $name, $old, $value, $name);
    } else {
        cinatra_test_do_action('add_option_' . $name, $name, $value);
    }
    return true;
}
function delete_option($name) {
    unset($GLOBALS['cinatra_test']['options'][$name]);
    return true;
}

// ---------------------------------------------------------------------------
// Auth / nonces / user
// ---------------------------------------------------------------------------
function current_user_can($cap) {
    // Cap-aware when a per-capability map is provided (e.g. edit_post vs
    // publish_posts differ); otherwise the single boolean drives every check.
    // Backward compatible: tests that never set ['caps'] behave as before.
    $caps = $GLOBALS['cinatra_test']['caps'] ?? null;
    if (is_array($caps) && array_key_exists($cap, $caps)) {
        return (bool) $caps[$cap];
    }
    return (bool) $GLOBALS['cinatra_test']['current_user_can'];
}
function get_current_user_id() { return (int) $GLOBALS['cinatra_test']['current_user_id']; }
function wp_create_nonce($action = -1) { return 'nonce-for-' . $action; }
function wp_verify_nonce($nonce, $action = -1) {
    return in_array($action, $GLOBALS['cinatra_test']['valid_nonces'], true)
        && $nonce === ('nonce-for-' . $action)
        ? 1 : false;
}
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }

// ---------------------------------------------------------------------------
// Transients (in-memory)
// ---------------------------------------------------------------------------
function set_transient($key, $value, $ttl = 0) {
    $GLOBALS['cinatra_test']['transients'][$key] = $value;
    return true;
}
function get_transient($key) {
    return $GLOBALS['cinatra_test']['transients'][$key] ?? false;
}
function delete_transient($key) {
    unset($GLOBALS['cinatra_test']['transients'][$key]);
    return true;
}

// ---------------------------------------------------------------------------
// URLs
// ---------------------------------------------------------------------------
function home_url($path = '') { return $GLOBALS['cinatra_test']['home_url'] . $path; }
function rest_url($path = '') { return 'https://blog.example/wp-json/' . ltrim($path, '/'); }
function plugins_url($path = '', $plugin = '') {
    return 'https://blog.example/wp-content/plugins/cinatra/' . ltrim($path, '/');
}
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }

// ---------------------------------------------------------------------------
// Sanitizers / JSON
// ---------------------------------------------------------------------------
function sanitize_text_field($s) { return trim((string) $s); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function wp_json_encode($data) { return json_encode($data); }

// ---------------------------------------------------------------------------
// Enqueue capture
// ---------------------------------------------------------------------------
function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false) {
    $GLOBALS['cinatra_test']['enqueued_scripts'][$handle] = [
        'src' => $src, 'deps' => $deps, 'ver' => $ver, 'in_footer' => $in_footer,
    ];
}
function wp_localize_script($handle, $object_name, $data) {
    $GLOBALS['cinatra_test']['localized'][$handle][$object_name] = $data;
    return true;
}
function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false, $media = 'all') {
    $GLOBALS['cinatra_test']['enqueued_styles'][$handle] = [
        'src' => $src, 'deps' => $deps, 'ver' => $ver, 'media' => $media,
    ];
}
function wp_add_inline_style($handle, $data) {
    $GLOBALS['cinatra_test']['inline_styles'][$handle][] = $data;
    return true;
}
function wp_add_inline_script($handle, $data, $position = 'after') {
    $GLOBALS['cinatra_test']['inline_scripts'][$handle][] = $data;
    return true;
}

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------
function wp_remote_post($url, $args = []) {
    // Snapshot the host/port filter state AT call time so tests can prove the
    // request-scoped SSRF relaxation is active during the request (and the
    // remove_filter finally restores it to 0 afterwards).
    $GLOBALS['cinatra_test']['remote_post_calls'][] = [
        'url'  => $url,
        'args' => $args,
        'host_filter_active' => (int) ($GLOBALS['cinatra_test']['filters']['http_request_host_is_external'] ?? 0),
        'port_filter_active' => (int) ($GLOBALS['cinatra_test']['filters']['http_allowed_safe_ports'] ?? 0),
    ];
    $canned = $GLOBALS['cinatra_test']['remote_post'];
    return $canned instanceof Closure ? $canned($url, $args) : $canned;
}
function wp_safe_remote_post($url, $args = []) {
    // Model the real wp_safe_remote_post() SSRF/safe-port gate: a request WP
    // would block never reaches the network, so it returns a WP_Error and we do
    // NOT record a remote_post_call (matching production). The plugin's
    // request-scoped filters must make the override host:port pass this gate.
    if (!cinatra_test_safe_request_allowed($url)) {
        return new WP_Error('http_request_failed', 'wp_safe_remote_post blocked an unsafe URL (host/port): ' . $url);
    }
    return wp_remote_post($url, $args);
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wp_remote_retrieve_response_code($resp) { return $resp['response']['code'] ?? 0; }
function wp_remote_retrieve_body($resp) { return $resp['body'] ?? ''; }

// ---------------------------------------------------------------------------
// Minimal classes
// ---------------------------------------------------------------------------
class WP_Error {
    private $message;
    public function __construct($code = '', $message = '') { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}

class WP_REST_Response {
    public $data;
    public $status;
    public function __construct($data = null, $status = 200) {
        $this->data = $data;
        $this->status = $status;
    }
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
}

function rest_ensure_response($response) {
    return $response instanceof WP_REST_Response ? $response : new WP_REST_Response($response);
}

class WP_REST_Request {
    private $headers = [];
    private $json = null;
    private $params = [];
    public function set_header($name, $value) { $this->headers[strtolower($name)] = $value; }
    public function get_header($name) { return $this->headers[strtolower($name)] ?? null; }
    public function set_json_params($params) { $this->json = $params; }
    public function get_json_params() { return $this->json; }
    public function set_param($name, $value) { $this->params[$name] = $value; }
    public function get_param($name) { return $this->params[$name] ?? null; }
}
