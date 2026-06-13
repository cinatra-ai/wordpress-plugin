<?php
/**
 * Plugin Name: Cinatra
 * Plugin URI: https://cinatra.ai
 * Description: Embeds the Cinatra AI assistant chat widget in WordPress admin. Floating button bottom-right; opens chat panel on click.
 * Version: 0.2.0
 * Author: Cinatra
 * Requires at least: 5.9
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

// Bump this version whenever the vendored widget asset or plugin UI changes so
// browsers and WordPress invalidate their cached copy of cinatra-widget.js.
// Keep CINATRA_THEME_* values in sync with the canonical Cinatra brand tokens.
define('CINATRA_PLUGIN_VERSION', '0.2.0');
// Plugin↔core wire-contract version. Cinatra rejects unknown versions with an
// admin-visible error. See the cinatra repo: contracts/wp-drupal-assistant/.
// v2 drops the browser-side apiKey: the widget is served locally and streams
// with a short-lived token minted by the same-origin REST broker below.
define('CINATRA_CONTRACT_VERSION', 'v2');
define('CINATRA_THEME_ACCENT',          '#2d4a8a');
define('CINATRA_THEME_ACCENT_HOVER',    '#243e78');
define('CINATRA_THEME_ACCENT_SOFT',     '#e6ede7');
define('CINATRA_THEME_ACCENT_SOFT_HOV', '#d8e7db');
define('CINATRA_THEME_LOGO_COLOR',      '#7a2e3a');

// ---------------------------------------------------------------------------

add_action('admin_init', function () {
    cinatra_migrate_legacy_options();
    register_setting('cinatra_options', 'cinatra_url');
    register_setting('cinatra_options', 'cinatra_api_key');
    register_setting('cinatra_options', 'cinatra_instance_id');
    register_setting('cinatra_options', 'cinatra_webhook_secret');
    register_setting('cinatra_options', 'cinatra_webhook_subscriptions', [
        'type'    => 'string',
        'default' => '[]',
    ]);
});

/**
 * One-shot migration of options saved by the pre-rename plugin
 * (cinatra-widget.php, which used cinatra_widget_* option keys).
 *
 * For each renamed key: if the new option is unset but the legacy option holds
 * a value, copy it across and delete the legacy option. Idempotent — once the
 * legacy options are gone (or the new ones already set) this is a no-op. The
 * webhook_secret / webhook_subscriptions keys were never renamed and are left
 * untouched.
 */
function cinatra_migrate_legacy_options(): void {
    $renamed = [
        'cinatra_widget_url'         => 'cinatra_url',
        'cinatra_widget_api_key'     => 'cinatra_api_key',
        'cinatra_widget_instance_id' => 'cinatra_instance_id',
    ];
    foreach ($renamed as $legacy_key => $new_key) {
        $legacy_value = get_option($legacy_key, null);
        if ($legacy_value === null) {
            continue;
        }
        if (get_option($new_key, '') === '') {
            update_option($new_key, $legacy_value);
        }
        delete_option($legacy_key);
    }
}

// ---------------------------------------------------------------------------

add_action('admin_menu', function () {
    add_options_page(
        'Cinatra Settings',
        'Cinatra',
        'manage_options',
        'cinatra',
        'cinatra_render_settings_page'
    );
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=cinatra')) . '">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

function cinatra_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>Cinatra Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('cinatra_options'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="cinatra_url">Cinatra URL</label></th>
                    <td>
                        <input
                            type="text"
                            id="cinatra_url"
                            name="cinatra_url"
                            value="<?php echo esc_attr(get_option('cinatra_url', '')); ?>"
                            class="regular-text"
                            placeholder="https://app.cinatra.ai"
                        />
                        <p class="description">Base URL of your Cinatra instance (e.g. <code>https://app.cinatra.ai</code>).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cinatra_api_key">API Key</label></th>
                    <td>
                        <input
                            type="text"
                            id="cinatra_api_key"
                            name="cinatra_api_key"
                            value="<?php echo esc_attr(get_option('cinatra_api_key', '')); ?>"
                            class="regular-text"
                        />
                        <p class="description" id="cinatra_api_key_desc">Bearer token from Cinatra at <span id="cinatra_api_key_path"><?php echo esc_url(get_option('cinatra_url', '')); ?>/settings/connectors/wordpress-widget</span>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cinatra_instance_id">Agent Instance ID</label></th>
                    <td>
                        <input
                            type="text"
                            id="cinatra_instance_id"
                            name="cinatra_instance_id"
                            value="<?php echo esc_attr(get_option('cinatra_instance_id', '')); ?>"
                            class="regular-text"
                            placeholder="e.g. wp-prod"
                        />
                        <p class="description">WordPress instance ID copied from Cinatra at <code>/settings/connectors/wordpress-widget</code>. Required for the editor agent to resolve which WordPress site to edit.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cinatra_webhook_secret">Webhook Secret</label></th>
                    <td>
                        <input
                            type="password"
                            id="cinatra_webhook_secret"
                            name="cinatra_webhook_secret"
                            value="<?php echo esc_attr(get_option('cinatra_webhook_secret', '')); ?>"
                            class="regular-text"
                        />
                        <p class="description">HMAC secret shared with Cinatra at <span id="cinatra_webhook_path"><?php echo esc_url(get_option('cinatra_url', '')); ?>/settings/connectors/wordpress-widget</span>. Cinatra signs the <code>X-Cinatra-Sig-256</code> header with it on the webhook requests it sends to this site.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <script>
    (function () {
        var urlInput = document.getElementById('cinatra_url');
        var pathSpans = [
            document.getElementById('cinatra_api_key_path'),
            document.getElementById('cinatra_webhook_path'),
        ];
        function updatePaths() {
            var base = (urlInput.value || '').replace(/\/+$/, '');
            var path = '/settings/connectors/wordpress-widget';
            var full = base + path;
            pathSpans.forEach(function (el) {
                if (!el) return;
                if (base) {
                    el.innerHTML = '<a href="' + encodeURI(full) + '" target="_blank" rel="noopener noreferrer">' + full + '</a>';
                } else {
                    el.textContent = path;
                }
            });
        }
        updatePaths();
        urlInput.addEventListener('input', updatePaths);
    })();
    </script>
    <?php
}

// ---------------------------------------------------------------------------

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!is_plugin_active('mcp-adapter/mcp-adapter.php')) {
        echo '<div class="notice notice-warning"><p><strong>Cinatra:</strong> Install the WordPress MCP Adapter plugin to enable AI tool access from the chat widget. The widget will still work without it.</p></div>';
    }
    $instance_id = get_option('cinatra_instance_id', '');
    $cinatra_url = get_option('cinatra_url', '');
    if (!empty($cinatra_url) && empty($instance_id)) {
        $settings_url = esc_url(admin_url('options-general.php?page=cinatra'));
        echo '<div class="notice notice-error"><p><strong>Cinatra:</strong> The <strong>Agent Instance ID</strong> is not set — the AI assistant will not be able to edit content. <a href="' . $settings_url . '">Configure it here</a> (copy from Cinatra at <code>/settings/connectors/wordpress-widget</code>).</p></div>';
    }
});

// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Enqueue the LOCAL widget asset and pass a secret-free config (contract v2).
//
// The widget JS is shipped inside this plugin (assets/cinatra-widget.js) — it is
// never remote-loaded from the Cinatra instance, so wp-admin never executes
// third-party code fetched over HTTP. The long-lived integration key
// (cinatra_api_key) is NOT exposed to the browser: the widget exchanges it for a
// short-lived, origin/audience/scope-bound stream token via the same-origin REST
// broker route below (cinatra/v1/token). See wp#4 / cinatra#220.
// ---------------------------------------------------------------------------

add_action('admin_enqueue_scripts', 'cinatra_enqueue_widget');

/**
 * Enqueue the locally-vendored widget asset and localize a secret-free config.
 * Named (not anonymous) so it stays unit-testable and overridable.
 */
function cinatra_enqueue_widget(): void {
    if (!current_user_can('manage_options')) return;
    $url     = get_option('cinatra_url', '');
    $api_key = get_option('cinatra_api_key', '');
    // Without an instance URL + integration key there is nothing for the broker
    // to talk to; keep the widget off rather than mount a broken assistant.
    if (empty($url) || empty($api_key)) return;
    wp_enqueue_script(
        'cinatra',
        plugins_url('assets/cinatra-widget.js', __FILE__),
        [],
        CINATRA_PLUGIN_VERSION,
        true
    );
    $instance_id = get_option('cinatra_instance_id', '');
    wp_localize_script('cinatra', 'CinatraConfig', [
        'contractVersion' => CINATRA_CONTRACT_VERSION,
        'cinatraUrl'      => rtrim($url, '/'),
        // No apiKey. The browser obtains a short-lived token from this endpoint.
        'tokenEndpoint'   => rest_url('cinatra/v1/token'),
        'nonce'           => wp_create_nonce('wp_rest'),
        'instanceId'      => $instance_id,
        'wpAdminUrl'      => admin_url(),
    ]);
}

// ---------------------------------------------------------------------------
// Mount point + inline fallback button — always visible even when Cinatra is down
// ---------------------------------------------------------------------------

add_action('admin_footer', function () {
    if (!current_user_can('manage_options')) return;
    $url = get_option('cinatra_url', '');
    if (empty($url)) return;
    $url_js = esc_js(rtrim($url, '/'));
    echo '<div id="cinatra-root"></div>';
    echo '<style>
#cw-fallback-btn{position:fixed;bottom:66px;right:36px;width:32px;height:32px;border-radius:9999px;background:' . CINATRA_THEME_ACCENT_SOFT . ';border:1.5px solid ' . CINATRA_THEME_LOGO_COLOR . ';cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(0,0,0,.18);z-index:9999999;transition:background .15s;padding:0;}
#cw-fallback-btn:hover{background:' . CINATRA_THEME_ACCENT_SOFT_HOV . ';}
#cw-fallback-error{position:fixed;bottom:110px;right:24px;width:280px;background:#fff;color:#18181b;border:1px solid #e4e4e7;border-radius:16px;box-shadow:0 16px 48px rgba(0,0,0,.2);padding:16px 16px 16px 16px;z-index:9999999;font:14px/1.5 -apple-system,sans-serif;display:none;}
#cw-fallback-error .cw-fe-header{display:flex;align-items:flex-start;justify-content:space-between;margin:0 0 6px;}
#cw-fallback-error .cw-fe-title{font-weight:600;margin:0;}
#cw-fallback-error .cw-fe-close{background:none;border:none;cursor:pointer;font-size:18px;line-height:1;color:#71717a;padding:0 0 0 8px;flex-shrink:0;}
#cw-fallback-error .cw-fe-close:hover{color:#18181b;}
#cw-fallback-error .cw-fe-msg{margin:0;color:#52525b;font-size:13px;}
</style>';
    // SVG paths match the canonical CINATRA_LOGO brand mark — keep in sync.
    echo '<button id="cw-fallback-btn" title="Cinatra AI Assistant" aria-label="Cinatra AI Assistant">
  <svg width="22" height="14" viewBox="0 0 512 320" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M72 214 C 72 200 96 190 130 188 C 168 186 196 200 256 210 C 316 220 358 214 400 200 C 426 192 440 196 440 208 C 440 222 420 234 388 242 C 340 254 288 256 256 256 C 202 256 132 248 100 238 C 80 232 72 224 72 214 Z" fill="' . CINATRA_THEME_LOGO_COLOR . '"/>
    <path d="M146 188 C 150 130 176 86 212 72 C 226 66 240 64 252 64 C 262 64 270 70 268 80 L 264 100 C 272 88 288 82 300 82 C 332 82 356 118 362 188 Z" fill="' . CINATRA_THEME_LOGO_COLOR . '"/>
  </svg>
</button>';
    echo '<div id="cw-fallback-error">
  <div class="cw-fe-header">
    <p class="cw-fe-title">Cinatra is unavailable</p>
    <button class="cw-fe-close" id="cw-fe-close" aria-label="Close">&times;</button>
  </div>
  <p class="cw-fe-msg" id="cw-fe-msg">Could not connect to your Cinatra instance.</p>
</div>';
    echo '<script>
(function(){
  var btn=document.getElementById("cw-fallback-btn");
  var box=document.getElementById("cw-fallback-error");
  var msg=document.getElementById("cw-fe-msg");
  var cls=document.getElementById("cw-fe-close");
  var cu="' . $url_js . '";
  var ok=false;
  var root=document.getElementById("cinatra-root");
  if(root){
    new MutationObserver(function(_,o){
      if(root.dataset.cinatraMounted==="true"){
        btn.style.display="none";
        box.style.display="none";
        ok=true;
        o.disconnect();
      }
    }).observe(root,{attributes:true});
  }
  btn.addEventListener("click",function(){
    if(ok)return;
    // The widget JS is local; reachability now means "can the browser reach the
    // instance API at all?". Probe the auth-free capabilities endpoint.
    fetch(cu+"/api/agents/wordpress-content-editor/capabilities",{method:"GET",cache:"no-store",signal:AbortSignal.timeout(4000)})
      .then(function(r){
        msg.textContent=r.ok
          ?"Cinatra is reachable but the widget has not loaded yet. Try refreshing the page."
          :"Cinatra returned HTTP "+r.status+". Check your instance at: "+cu;
        box.style.display="block";
      })
      .catch(function(){
        msg.textContent="Cannot reach "+cu+". Check that your Cinatra instance is running.";
        box.style.display="block";
      });
  });
  cls.addEventListener("click",function(){box.style.display="none";});
  document.addEventListener("click",function(e){
    if(box.style.display==="none")return;
    if(!box.contains(e.target)&&e.target!==btn){box.style.display="none";}
  });
})();
</script>';
});

// ---------------------------------------------------------------------------

add_action('rest_api_init', function () {
    // Short-lived stream-token broker. The browser calls this same-origin route
    // (with a wp_rest nonce); the PHP backend holds the long-lived integration
    // key, performs a server-to-server token exchange with the Cinatra instance,
    // and returns ONLY the short-lived token to the browser. The long-lived key
    // never leaves the server. See wp#4 / cinatra#220.
    register_rest_route('cinatra/v1', '/token', [
        'methods'             => 'POST',
        'callback'            => 'cinatra_rest_mint_token',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);

    register_rest_route('cinatra/v1', '/webhooks', [
        [
            'methods'             => 'GET',
            'callback'            => 'cinatra_rest_list_webhooks',
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'cinatra_rest_create_webhook',
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ],
    ]);

    register_rest_route('cinatra/v1', '/webhooks/(?P<id>[\w-]+)', [
        'methods'             => 'DELETE',
        'callback'            => 'cinatra_rest_delete_webhook',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'args' => [
            'id' => [
                'required' => true,
                'type'     => 'string',
            ],
        ],
    ]);
});

/**
 * Agent slug for the WordPress content-editor assistant. The token + stream +
 * capabilities endpoints all live under /api/agents/{slug}/ on the instance.
 */
const CINATRA_AGENT_SLUG = 'wordpress-content-editor';

/**
 * Normalize a URL down to its scheme://host[:port] origin, lowercased, no
 * trailing slash / path / query / fragment. Returns '' if the input has no
 * usable scheme+host. The instance binds the minted token to this exact origin.
 */
function cinatra_site_origin(string $url): string {
    $parts = wp_parse_url($url);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
    if (!empty($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }
    return $origin;
}

/**
 * Mint a short-lived Cinatra stream token via server-to-server exchange.
 *
 * The browser sends the wp_rest nonce; this callback (gated to manage_options)
 * reads the long-lived integration key from wp_options and POSTs it to the
 * instance's token endpoint, returning only the short-lived token JSON to the
 * caller. The long-lived key is never sent to the browser.
 */
function cinatra_rest_mint_token(WP_REST_Request $request): WP_REST_Response {
    // CSRF: a valid wp_rest nonce must accompany the cookie-authenticated call.
    $nonce = $request->get_header('X-WP-Nonce');
    if (empty($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
        return new WP_REST_Response(['error' => 'Invalid or missing nonce.'], 403);
    }

    $url     = rtrim((string) get_option('cinatra_url', ''), '/');
    $api_key = (string) get_option('cinatra_api_key', '');
    if (empty($url) || empty($api_key)) {
        return new WP_REST_Response(
            ['error' => 'Cinatra URL or API key is not configured.'],
            500
        );
    }

    // Bind to the origin the BROWSER will present when it streams. The widget
    // runs in wp-admin, so its Origin header is the admin origin (admin_url()),
    // which can legitimately differ from the front-end home origin (WP_HOME vs
    // WP_SITEURL, or admin-over-SSL setups). The instance re-checks this exact
    // origin at stream-consume time, so it must match the admin origin.
    $origin = cinatra_site_origin(admin_url());
    if (empty($origin)) {
        return new WP_REST_Response(
            ['error' => 'Could not derive this site origin.'],
            500
        );
    }

    $params           = $request->get_json_params();
    $contract_version = CINATRA_CONTRACT_VERSION;
    if (is_array($params) && !empty($params['contractVersion'])) {
        $candidate = sanitize_text_field((string) $params['contractVersion']);
        // Only accept the versions this plugin knows; otherwise pin to ours.
        if (in_array($candidate, ['v1', 'v2'], true)) {
            $contract_version = $candidate;
        }
    }

    $token_endpoint = $url . '/api/agents/' . CINATRA_AGENT_SLUG . '/token';
    $body           = [
        'contractVersion' => $contract_version,
        'origin'          => $origin,
        'sub'             => 'wp-user-' . get_current_user_id(),
        'scope'           => CINATRA_AGENT_SLUG . '.stream',
    ];

    $response = wp_remote_post($token_endpoint, [
        'timeout' => 10,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ],
        'body'    => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
        // Log the transport detail server-side; return a generic message so we
        // never reflect low-level/internal error text to the browser.
        error_log('[cinatra] token endpoint unreachable: ' . $response->get_error_message());
        return new WP_REST_Response(
            ['error' => 'Could not reach the Cinatra instance. Check the connector URL, or contact your administrator.'],
            502
        );
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $raw    = (string) wp_remote_retrieve_body($response);
    $json   = json_decode($raw, true);

    if ($status < 200 || $status >= 300 || !is_array($json) || empty($json['token'])) {
        // Do NOT reflect the upstream body to the browser — it could contain
        // instance internals. Log the detail server-side for admins; return a
        // generic, actionable message. Always 502: from the browser's
        // perspective the upstream Cinatra instance failed the exchange
        // (bad/rotated key, origin not configured, unreachable, malformed).
        $detail = (is_array($json) && !empty($json['error']))
            ? (string) $json['error']
            : substr($raw, 0, 500);
        error_log('[cinatra] token exchange failed (HTTP ' . $status . '): ' . $detail);
        return new WP_REST_Response(
            ['error' => 'Cinatra could not issue a session token. Check the connector settings, or contact your administrator.'],
            502
        );
    }

    // Return ONLY the short-lived token envelope to the browser.
    return new WP_REST_Response([
        'token'           => (string) $json['token'],
        'tokenType'       => isset($json['tokenType']) ? (string) $json['tokenType'] : 'Bearer',
        'expiresIn'       => isset($json['expiresIn']) ? (int) $json['expiresIn'] : 300,
        'expiresAt'       => isset($json['expiresAt']) ? (string) $json['expiresAt'] : null,
        'contractVersion' => isset($json['contractVersion']) ? (string) $json['contractVersion'] : $contract_version,
        'scope'           => isset($json['scope']) ? (string) $json['scope'] : (CINATRA_AGENT_SLUG . '.stream'),
    ], 200);
}

function cinatra_rest_list_webhooks(WP_REST_Request $request): WP_REST_Response {
    return rest_ensure_response(cinatra_get_webhook_subscriptions());
}

function cinatra_rest_create_webhook(WP_REST_Request $request): WP_REST_Response {
    $params     = $request->get_json_params();
    if (!is_array($params)) {
        $params = [];
    }
    $event_type = sanitize_text_field((string) ($params['event_type'] ?? ''));
    $target_url = esc_url_raw((string) ($params['target_url'] ?? ''));
    $post_types = array_values(array_map('sanitize_key', (array) ($params['post_types'] ?? [])));

    if (empty($event_type) || empty($target_url)) {
        return new WP_REST_Response(
            ['error' => 'event_type and target_url are required.'],
            400
        );
    }

    $existing = cinatra_get_webhook_subscriptions();

    // Dedupe: if a subscription with the same event_type + target_url already exists, return it with 409
    foreach ($existing as $subscription) {
        if (($subscription['event_type'] ?? '') === $event_type &&
            ($subscription['target_url'] ?? '') === $target_url) {
            return new WP_REST_Response($subscription, 409);
        }
    }

    $new_subscription = [
        'id'         => wp_generate_uuid4(),
        'event_type' => $event_type,
        'target_url' => $target_url,
        'post_types' => $post_types,
        'created_at' => gmdate('c'),
    ];

    $existing[] = $new_subscription;
    cinatra_save_webhook_subscriptions($existing);

    return new WP_REST_Response($new_subscription, 201);
}

function cinatra_rest_delete_webhook(WP_REST_Request $request): WP_REST_Response {
    $id      = sanitize_text_field((string) $request->get_param('id'));
    $current = cinatra_get_webhook_subscriptions();
    $updated = array_values(array_filter(
        $current,
        function ($s) use ($id) {
            return ($s['id'] ?? '') !== $id;
        }
    ));

    if (count($updated) === count($current)) {
        return new WP_REST_Response(['error' => 'Subscription not found.'], 404);
    }

    cinatra_save_webhook_subscriptions($updated);
    return new WP_REST_Response(['deleted' => true]);
}

function cinatra_get_webhook_subscriptions(): array {
    $raw = get_option('cinatra_webhook_subscriptions', '[]');
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function cinatra_save_webhook_subscriptions(array $subscriptions): void {
    update_option('cinatra_webhook_subscriptions', json_encode(array_values($subscriptions)));
}

