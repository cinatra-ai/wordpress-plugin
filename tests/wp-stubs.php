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
    'remote_post_calls'    => [],   // captured wp_remote_post args
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
function add_action($hook, $cb, $priority = 10, $args = 1) { return true; }
function add_filter($hook, $cb, $priority = 10, $args = 1) { return true; }
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

// ---------------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------------
function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['cinatra_test']['options'])
        ? $GLOBALS['cinatra_test']['options'][$name]
        : $default;
}
function update_option($name, $value) {
    $GLOBALS['cinatra_test']['options'][$name] = $value;
    return true;
}
function delete_option($name) {
    unset($GLOBALS['cinatra_test']['options'][$name]);
    return true;
}

// ---------------------------------------------------------------------------
// Auth / nonces / user
// ---------------------------------------------------------------------------
function current_user_can($cap) { return (bool) $GLOBALS['cinatra_test']['current_user_can']; }
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
    $GLOBALS['cinatra_test']['remote_post_calls'][] = ['url' => $url, 'args' => $args];
    $canned = $GLOBALS['cinatra_test']['remote_post'];
    return $canned instanceof Closure ? $canned($url, $args) : $canned;
}
function wp_safe_remote_post($url, $args = []) {
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
