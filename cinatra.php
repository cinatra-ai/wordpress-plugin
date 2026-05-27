<?php
/**
 * Plugin Name: Cinatra
 * Plugin URI: https://cinatra.ai
 * Description: Embeds the Cinatra AI assistant chat widget in WordPress admin. Floating button bottom-right; opens chat panel on click.
 * Version: 0.1.0
 * Author: Cinatra
 * Requires at least: 5.9
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

// Bump this version whenever the Cinatra bundle or plugin UI changes so browsers
// and WordPress invalidate their cached copy of bundle.js.
// Keep CINATRA_THEME_* values in sync with the canonical Cinatra brand tokens.
define('CINATRA_PLUGIN_VERSION', '0.1.0');
// Plugin↔core wire-contract version. Cinatra rejects unknown versions with an
// admin-visible error. See the cinatra repo: contracts/wp-drupal-assistant/.
define('CINATRA_CONTRACT_VERSION', 'v1');
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
// Enqueue widget bundle from Cinatra and pass config
// ---------------------------------------------------------------------------

add_action('admin_enqueue_scripts', function () {
    if (!current_user_can('manage_options')) return;
    $url     = get_option('cinatra_url', '');
    $api_key = get_option('cinatra_api_key', '');
    if (empty($url) || empty($api_key)) return;
    $bundle_url = rtrim($url, '/') . '/api/wordpress/bundle.js';
    wp_enqueue_script('cinatra', $bundle_url, [], CINATRA_PLUGIN_VERSION, true);
    $instance_id = get_option('cinatra_instance_id', '');
    wp_localize_script('cinatra', 'CinatraConfig', [
        'contractVersion' => CINATRA_CONTRACT_VERSION,
        'cinatraUrl'      => rtrim($url, '/'),
        'apiKey'          => $api_key,
        'instanceId'      => $instance_id,
        'wpAdminUrl'      => admin_url(),
    ]);
});

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
    fetch(cu+"/api/wordpress/bundle.js",{method:"HEAD",cache:"no-store",signal:AbortSignal.timeout(4000)})
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

