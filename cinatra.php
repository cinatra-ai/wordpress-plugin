<?php
/**
 * Plugin Name: Cinatra
 * Plugin URI: https://cinatra.ai
 * Description: Embeds the Cinatra AI assistant chat widget in WordPress admin. Floating button bottom-right; opens chat panel on click.
 * Version: 0.2.3
 * Author: Cinatra
 * Requires at least: 5.9
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cinatra
 *
 * @package Cinatra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bump this version whenever the vendored widget asset or plugin UI changes so
// browsers and WordPress invalidate their cached copy of cinatra-widget.js.
// Keep CINATRA_THEME_* values in sync with the canonical Cinatra brand tokens.
define( 'CINATRA_PLUGIN_VERSION', '0.2.3' );
// Plugin↔core wire-contract version. Cinatra rejects unknown versions with an
// admin-visible error. See the cinatra repo: contracts/wp-drupal-assistant/.
// v2 drops the browser-side apiKey: the widget is served locally and streams
// with a short-lived token minted by the same-origin REST broker below.
define( 'CINATRA_CONTRACT_VERSION', 'v2' );
define( 'CINATRA_THEME_ACCENT', '#2d4a8a' );
define( 'CINATRA_THEME_ACCENT_HOVER', '#243e78' );
define( 'CINATRA_THEME_ACCENT_SOFT', '#e6ede7' );
define( 'CINATRA_THEME_ACCENT_SOFT_HOV', '#d8e7db' );
define( 'CINATRA_THEME_LOGO_COLOR', '#7a2e3a' );
// Upper bound on stored webhook subscriptions (DoS / unbounded-option guard).
define( 'CINATRA_MAX_WEBHOOK_SUBSCRIPTIONS', 50 );

// ---------------------------------------------------------------------------

add_action(
	'admin_init',
	function () {
		cinatra_migrate_legacy_options();
		register_setting(
			'cinatra_options',
			'cinatra_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'cinatra_sanitize_url',
				'default'           => '',
			)
		);
		register_setting(
			'cinatra_options',
			'cinatra_api_key',
			array(
				'type'              => 'string',
				// Closure captures the option name (register_setting registers the
				// sanitize filter with only 1 arg, so $option is NOT passed through).
				// A blank submission must keep the stored secret, not wipe it.
				'sanitize_callback' => function ( $value ) {
					return cinatra_sanitize_secret_keep_existing( $value, 'cinatra_api_key' );
				},
				'default'           => '',
			)
		);
		register_setting(
			'cinatra_options',
			'cinatra_instance_id',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'cinatra_options',
			'cinatra_webhook_secret',
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $value ) {
					return cinatra_sanitize_secret_keep_existing( $value, 'cinatra_webhook_secret' );
				},
				'default'           => '',
			)
		);
		register_setting(
			'cinatra_options',
			'cinatra_webhook_subscriptions',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'cinatra_sanitize_subscriptions_json',
				'default'           => '[]',
			)
		);
	}
);

/**
 * Sanitize a Cinatra instance URL. Only http/https schemes are kept and the
 * value is normalized via esc_url_raw; anything else collapses to ''.
 *
 * @param mixed $value Raw option value submitted for the instance URL.
 * @return string Normalized URL, or '' if invalid.
 */
function cinatra_sanitize_url( $value ): string {
	$value = is_string( $value ) ? trim( $value ) : '';
	if ( '' === $value ) {
		return '';
	}
	$clean = esc_url_raw( $value, array( 'http', 'https' ) );
	return is_string( $clean ) ? $clean : '';
}

/**
 * Sanitize a credential/secret. Unlike sanitize_text_field this preserves the
 * exact printable token characters (it must not silently mutate a bearer token
 * or HMAC secret): it strips control characters and whitespace and caps the
 * length, but does not collapse internal characters.
 *
 * @param mixed $value Raw credential/secret value to sanitize.
 * @return string Sanitized secret (control chars stripped, trimmed, capped).
 */
function cinatra_sanitize_secret( $value ): string {
	if ( ! is_string( $value ) ) {
		return '';
	}
	// Drop CR/LF and other control chars; trim surrounding whitespace.
	$value = preg_replace( '/[\x00-\x1F\x7F]+/', '', $value );
	$value = trim( (string) $value );
	if ( strlen( $value ) > 4096 ) {
		$value = substr( $value, 0, 4096 );
	}
	return $value;
}

/**
 * Secret sanitizer for settings-form fields rendered blank: a submitted EMPTY
 * value means "keep the stored secret" (the field is never prefilled, so an
 * unchanged form must not wipe the credential). A non-empty value replaces it.
 * register_setting passes the option name as the 2nd arg (WP 5.5+).
 *
 * @param mixed  $value  Raw submitted value for the secret field.
 * @param string $option Option name whose stored value is kept when blank.
 * @return string The sanitized new secret, or the existing stored secret if blank.
 */
function cinatra_sanitize_secret_keep_existing( $value, $option = '' ): string {
	$clean = cinatra_sanitize_secret( is_string( $value ) ? $value : '' );
	if ( '' === $clean && is_string( $option ) && '' !== $option ) {
		return (string) get_option( $option, '' );
	}
	return $clean;
}

/**
 * Sanitize the webhook-subscriptions option: decode the JSON, keep only
 * well-formed subscription records (sanitizing each field), cap the count, and
 * re-encode. Invalid input collapses to an empty list rather than persisting
 * untrusted JSON verbatim.
 *
 * @param mixed $value Raw option value (JSON string or array of subscriptions).
 * @return string JSON-encoded array of sanitized subscription records.
 */
function cinatra_sanitize_subscriptions_json( $value ): string {
	$decoded = is_string( $value ) ? json_decode( $value, true ) : ( is_array( $value ) ? $value : null );
	if ( ! is_array( $decoded ) ) {
		return '[]';
	}
	$clean = array();
	foreach ( $decoded as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$event_type = sanitize_text_field( (string) ( $row['event_type'] ?? '' ) );
		$target_url = esc_url_raw( (string) ( $row['target_url'] ?? '' ), array( 'http', 'https' ) );
		if ( '' === $event_type || ! is_string( $target_url ) || '' === $target_url ) {
			continue;
		}
		$post_types = array_values(
			array_filter(
				array_map(
					'sanitize_key',
					(array) ( $row['post_types'] ?? array() )
				)
			)
		);
		$clean[]    = array(
			'id'         => sanitize_text_field( (string) ( $row['id'] ?? wp_generate_uuid4() ) ),
			'event_type' => $event_type,
			'target_url' => $target_url,
			'post_types' => $post_types,
			'created_at' => sanitize_text_field( (string) ( $row['created_at'] ?? gmdate( 'c' ) ) ),
		);
		if ( count( $clean ) >= CINATRA_MAX_WEBHOOK_SUBSCRIPTIONS ) {
			break;
		}
	}
	return wp_json_encode( array_values( $clean ) );
}

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
	$renamed = array(
		'cinatra_widget_url'         => 'cinatra_url',
		'cinatra_widget_api_key'     => 'cinatra_api_key',
		'cinatra_widget_instance_id' => 'cinatra_instance_id',
	);
	foreach ( $renamed as $legacy_key => $new_key ) {
		$legacy_value = get_option( $legacy_key, null );
		if ( null === $legacy_value ) {
			continue;
		}
		if ( '' === get_option( $new_key, '' ) ) {
			update_option( $new_key, $legacy_value );
		}
		delete_option( $legacy_key );
	}
}

// ---------------------------------------------------------------------------

add_action(
	'admin_menu',
	function () {
		add_options_page(
			__( 'Cinatra Settings', 'cinatra' ),
			__( 'Cinatra', 'cinatra' ),
			'manage_options',
			'cinatra',
			'cinatra_render_settings_page'
		);
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=cinatra' ) ) . '">' . __( 'Settings', 'cinatra' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);

/**
 * Whether a stored secret exists for the given option. Used to render the
 * "leave blank to keep existing" hint WITHOUT echoing the secret into the DOM.
 *
 * @param string $option Option name to check for a stored secret.
 * @return bool True if a non-empty secret is stored for the option.
 */
function cinatra_has_secret( string $option ): bool {
	return get_option( $option, '' ) !== '';
}

/**
 * Render the Cinatra settings page (Settings → Cinatra) in wp-admin.
 *
 * Capability-gated to manage_options; surfaces the one-time connect notice,
 * the Connect-with-Cinatra form, and the manual-configuration fallback.
 *
 * @return void
 */
function cinatra_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Surface the result of a Connect handshake (set by the admin-post callback)
	// as a one-time dismissible notice. Read from a per-user transient so the
	// message never persists past the next page view.
	cinatra_render_connect_result_notice();

	$configured_url = get_option( 'cinatra_url', '' );
	$is_connected   = '' !== $configured_url && '' !== get_option( 'cinatra_api_key', '' );
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Cinatra Settings', 'cinatra' ); ?></h1>

		<div class="card" style="max-width:680px;">
			<h2 style="margin-top:0;"><?php echo esc_html__( 'Connect to Cinatra', 'cinatra' ); ?></h2>
			<p>
				<?php echo esc_html__( 'Enter your Cinatra instance URL and click Connect. You will be sent to Cinatra to approve the connection; the integration credential is then provisioned automatically and stored on this server. You never copy or paste a key.', 'cinatra' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cinatra_connect_start" />
				<?php wp_nonce_field( 'cinatra_connect_start' ); ?>
				<p>
					<label for="cinatra_connect_url"><strong><?php echo esc_html__( 'Cinatra instance URL', 'cinatra' ); ?></strong></label><br />
					<input
						type="url"
						id="cinatra_connect_url"
						name="cinatra_connect_url"
						value="<?php echo esc_attr( $configured_url ); ?>"
						class="regular-text"
						placeholder="https://app.cinatra.ai"
						inputmode="url"
						autocomplete="off"
					/>
				</p>
				<p>
					<?php submit_button( __( 'Connect with Cinatra', 'cinatra' ), 'primary', 'submit', false ); ?>
					<?php if ( $is_connected ) : ?>
						<span class="description" style="margin-left:8px;">
							<?php echo esc_html__( 'Currently connected. Reconnecting replaces the stored credential.', 'cinatra' ); ?>
						</span>
					<?php endif; ?>
				</p>
			</form>
			<details>
				<summary style="cursor:pointer;"><?php echo esc_html__( 'No browser redirect? Use a connection string instead', 'cinatra' ); ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
					<input type="hidden" name="action" value="cinatra_connect_install_code" />
					<?php wp_nonce_field( 'cinatra_connect_install_code' ); ?>
					<p>
						<label for="cinatra_connection_string"><?php echo esc_html__( 'Connection string from Cinatra', 'cinatra' ); ?></label><br />
						<input
							type="text"
							id="cinatra_connection_string"
							name="cinatra_connection_string"
							class="large-text"
							autocomplete="off"
							spellcheck="false"
						/>
					</p>
					<p><?php submit_button( __( 'Connect with code', 'cinatra' ), 'secondary', 'submit', false ); ?></p>
				</form>
			</details>
		</div>

		<h2><?php echo esc_html__( 'Advanced / manual configuration', 'cinatra' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Most sites should use Connect above. These fields let you set or override the connection manually.', 'cinatra' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'cinatra_options' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="cinatra_url"><?php echo esc_html__( 'Cinatra URL', 'cinatra' ); ?></label></th>
					<td>
						<input
							type="url"
							id="cinatra_url"
							name="cinatra_url"
							value="<?php echo esc_attr( get_option( 'cinatra_url', '' ) ); ?>"
							class="regular-text"
							placeholder="https://app.cinatra.ai"
							autocomplete="off"
						/>
						<p class="description">
							<?php
							printf(
								/* translators: %s: example URL */
								esc_html__( 'Base URL of your Cinatra instance (e.g. %s).', 'cinatra' ),
								'<code>https://app.cinatra.ai</code>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cinatra_api_key"><?php echo esc_html__( 'API Key', 'cinatra' ); ?></label></th>
					<td>
						<input
							type="password"
							id="cinatra_api_key"
							name="cinatra_api_key"
							value=""
							class="regular-text"
							autocomplete="off"
							placeholder="<?php echo cinatra_has_secret( 'cinatra_api_key' ) ? esc_attr__( '(stored — leave blank to keep)', 'cinatra' ) : ''; ?>"
						/>
						<p class="description" id="cinatra_api_key_desc">
							<?php echo esc_html__( 'Bearer token from Cinatra at', 'cinatra' ); ?>
							<span id="cinatra_api_key_path"><?php echo esc_html( cinatra_connector_path_display() ); ?></span>.
							<?php echo esc_html__( 'Leave blank to keep the stored value.', 'cinatra' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cinatra_instance_id"><?php echo esc_html__( 'Agent Instance ID', 'cinatra' ); ?></label></th>
					<td>
						<input
							type="text"
							id="cinatra_instance_id"
							name="cinatra_instance_id"
							value="<?php echo esc_attr( get_option( 'cinatra_instance_id', '' ) ); ?>"
							class="regular-text"
							placeholder="e.g. wp-prod"
							autocomplete="off"
						/>
						<p class="description"><?php echo esc_html__( 'WordPress instance ID copied from Cinatra. Required for the agent to resolve which WordPress site to edit.', 'cinatra' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cinatra_webhook_secret"><?php echo esc_html__( 'Webhook Secret', 'cinatra' ); ?></label></th>
					<td>
						<input
							type="password"
							id="cinatra_webhook_secret"
							name="cinatra_webhook_secret"
							value=""
							class="regular-text"
							autocomplete="off"
							placeholder="<?php echo cinatra_has_secret( 'cinatra_webhook_secret' ) ? esc_attr__( '(stored — leave blank to keep)', 'cinatra' ) : ''; ?>"
						/>
						<p class="description">
							<?php echo esc_html__( 'A shared secret stored here and on your Cinatra instance. Cinatra uses it on its own side to sign the requests it makes; this plugin only stores the value and maintains a subscription registry. It does not receive or verify inbound signed webhooks.', 'cinatra' ); ?>
							<?php echo esc_html__( 'Leave blank to keep the stored value.', 'cinatra' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Plain-text display of the Cinatra connector path for the settings hints. The
 * JS in cinatra-settings.js upgrades the matching span(s) into a real link via
 * DOM APIs (no innerHTML) once a base URL is present.
 */
function cinatra_connector_path_display(): string {
	$base = rtrim( (string) get_option( 'cinatra_url', '' ), '/' );
	$path = '/settings/connectors/wordpress-widget';
	return '' !== $base ? $base . $path : $path;
}

/**
 * Enqueue the settings-page enhancement script ONLY on the Cinatra settings
 * screen. Replaces the previous inline <script> (Plugin Check: no inline JS).
 */
add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'settings_page_cinatra' !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'cinatra-settings',
			plugins_url( 'assets/cinatra-settings.js', __FILE__ ),
			array(),
			CINATRA_PLUGIN_VERSION,
			true
		);
	}
);

// ---------------------------------------------------------------------------

/**
 * Admin notices, scoped to the Cinatra settings screen only (Guideline #11 —
 * do not show plugin notices site-wide), capability-gated, and escaped.
 */
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'settings_page_cinatra' !== $screen->id ) {
			return;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Cinatra:', 'cinatra' ),
				esc_html__( 'Install the WordPress MCP Adapter plugin to enable AI tool access from the chat widget. The widget will still work without it.', 'cinatra' )
			);
		}
		$instance_id = get_option( 'cinatra_instance_id', '' );
		$cinatra_url = get_option( 'cinatra_url', '' );
		if ( ! empty( $cinatra_url ) && empty( $instance_id ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Cinatra:', 'cinatra' ),
				esc_html__( 'The Agent Instance ID is not set — the AI assistant will not be able to edit content. Set it in the manual configuration below.', 'cinatra' )
			);
		}
	}
);

// ---------------------------------------------------------------------------
// "Connect with Cinatra" — one-click provisioning (cinatra#221 contract v1).
//
// Flow:
// 1. Admin enters the instance URL and clicks Connect (POST to admin-post,
// WP nonce + manage_options).
// 2. We mint a random `state` + PKCE verifier/challenge (S256), stash them in
// a short-lived per-state transient, and redirect the BROWSER to
// {instance}/connect/authorize?... (external redirect — validated host).
// 3. Cinatra approves and redirects back to
// {site}/wp-admin/admin-post.php?action=cinatra_connect_callback&code&state.
// 4. The callback (manage_options) validates state, exchanges the code
// SERVER-SIDE at {instance}/api/connect/token (grant_type=authorization_code
// + code_verifier), and stores the returned credential server-side. The
// credential never touches the browser.
//
// Fallback: the admin pastes a connection string (install_code) which we
// exchange with grant_type=install_code.
// ---------------------------------------------------------------------------

const CINATRA_CONNECT_CLIENT            = 'wordpress';
const CINATRA_CONNECT_SCOPE             = 'connector:provision';
const CINATRA_CONNECT_CALLBACK_ACTION   = 'cinatra_connect_callback';
const CINATRA_CONNECT_STATE_TTL         = 600; // Seconds.
const CINATRA_CONNECT_RESULT_KEY_PREFIX = 'cinatra_connect_result_';
const CINATRA_CONNECT_STATE_PREFIX      = 'cinatra_connect_state_';

/**
 * Validate a user-supplied Cinatra instance URL for use as an OUTBOUND redirect
 * / API target. Returns the normalized scheme://host[:port] base (no trailing
 * slash) or '' on rejection. https is required except for loopback hosts.
 *
 * @param string $raw User-supplied instance URL.
 * @return string Normalized scheme://host[:port] base, or '' if rejected.
 */
function cinatra_validate_instance_url( string $raw ): string {
	$raw = trim( $raw );
	if ( '' === $raw || preg_match( '/[\x00-\x1F\x7F]/', $raw ) ) {
		return '';
	}
	$parts = wp_parse_url( $raw );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}
	$scheme = strtolower( $parts['scheme'] );
	$host   = strtolower( $parts['host'] );
	// No userinfo (https://user:pass@host can disguise the real origin).
	if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
		return '';
	}
	$is_loopback = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
	// Allow https anywhere; allow http only for local loopback dev. Reject everything else.
	$allowed = ( 'https' === $scheme ) || ( 'http' === $scheme && $is_loopback );
	if ( ! $allowed ) {
		return '';
	}
	$base = $scheme . '://' . $host;
	if ( ! empty( $parts['port'] ) ) {
		$base .= ':' . (int) $parts['port'];
	}
	return $base;
}

/**
 * Host allowlist for the OPTIONAL server-to-server base-URL override
 * (CINATRA_BASE_URL). These are the only hosts a containerized dev topology can
 * legitimately point the PHP→Cinatra calls at: container IPv4 loopback, or the
 * Docker host-gateway alias that docker-compose wires via
 * `extra_hosts: host.docker.internal:host-gateway`.
 *
 * IPv6 loopback (::1) is intentionally NOT listed: it would need bracketed-host
 * normalization (http://[::1]:port) that this validator does not implement, and
 * docker-compose never targets it. Add it only with proper [::1] handling.
 */
const CINATRA_SERVER_BASE_ALLOWED_HOSTS = array( 'localhost', '127.0.0.1', 'host.docker.internal' );

/**
 * Validate the CINATRA_BASE_URL server-to-server override down to its
 * scheme://host[:port] origin, or '' if it is unusable / not in the dev-topology
 * allowlist.
 *
 * This is DELIBERATELY separate from cinatra_validate_instance_url() (which
 * gates admin-entered connect URLs and browser redirects, and intentionally
 * rejects http://host.docker.internal). This validator exists ONLY for the
 * trusted, operator-set container override and therefore:
 *   - allows http OR https, but ONLY for the fixed container host allowlist
 *     (loopback + host.docker.internal) — never an arbitrary public/private host;
 *   - rejects userinfo and control characters.
 * It is never used to widen the shared validator, so production behavior (env
 * unset) is wholly unaffected.
 *
 * @param string $raw Raw CINATRA_BASE_URL value.
 * @return string Normalized scheme://host[:port] origin, or '' if rejected.
 */
function cinatra_validate_server_base_url( string $raw ): string {
	$raw = trim( $raw );
	if ( '' === $raw || preg_match( '/[\x00-\x1F\x7F]/', $raw ) ) {
		return '';
	}
	$parts = wp_parse_url( $raw );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}
	if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
		return '';
	}
	$scheme = strtolower( $parts['scheme'] );
	$host   = strtolower( $parts['host'] );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return '';
	}
	// Narrow host allowlist — the override may ONLY target the container's own
	// loopback or the Docker host-gateway alias, never an arbitrary host.
	if ( ! in_array( $host, CINATRA_SERVER_BASE_ALLOWED_HOSTS, true ) ) {
		return '';
	}
	$base = $scheme . '://' . $host;
	if ( ! empty( $parts['port'] ) ) {
		$base .= ':' . (int) $parts['port'];
	}
	return $base;
}

/**
 * Resolve the base URL for a server-to-server (PHP→Cinatra) HTTP call.
 *
 * Precedence: a VALIDATED CINATRA_BASE_URL env override (containerized dev
 * topology — docker-compose sets it to the host-gateway base reachable from
 * inside the container) wins; otherwise the browser-facing base ($browser_base,
 * e.g. the stored cinatra_url) is used unchanged.
 *
 * In production CINATRA_BASE_URL is unset, so this returns $browser_base and the
 * runtime behavior is identical to before this override existed. The override
 * ONLY changes the TRANSPORT target of the outbound POST; it is never persisted
 * and never handed to the browser.
 *
 * @param string $browser_base The browser-facing base (validated cinatra_url / instance URL).
 * @return string The base URL to use for the server-to-server request.
 */
function cinatra_server_base_url( string $browser_base ): string {
	$env = getenv( 'CINATRA_BASE_URL' );
	if ( is_string( $env ) && '' !== trim( $env ) ) {
		$override = cinatra_validate_server_base_url( $env );
		if ( '' !== $override ) {
			return $override;
		}
	}
	return $browser_base;
}

/**
 * Perform a server-to-server (PHP→Cinatra) POST.
 *
 * SECURITY: in production (CINATRA_BASE_URL unset, or set to anything outside the
 * container-host allowlist) this is a plain wp_safe_remote_post — the full
 * WordPress SSRF protection (loopback/private-host denylist) is retained.
 *
 * ONLY when an explicit, VALIDATED CINATRA_BASE_URL override is the target host
 * do we add two request-scoped filters for the duration of this single call,
 * then remove them in a finally block:
 *   - http_request_host_is_external: WordPress's wp_http_validate_url() REJECTS
 *     a loopback/private host unless this filter returns truthy ("treat as an
 *     allowed external host"). WordPress passes ($external, $host, $url); we
 *     return true for EXACTLY the one validated override host (and leave
 *     WordPress's decision untouched for any other host).
 *   - http_allowed_safe_ports: safe requests only permit ports 80/443/8080 by
 *     default, so a dev port like :3000 would otherwise be blocked. WordPress
 *     passes ($ports, $host, $url); we add the override's port ONLY when the
 *     request targets the override host AND port (the exact origin), and return
 *     $ports unchanged for any other host/port.
 * We keep wp_safe_remote_post (never the unguarded wp_remote_post) so every
 * other safe-request protection still applies; the only things relaxed are the
 * loopback/host-gateway denylist and the safe-port set, and only for the one
 * operator-trusted host:port.
 *
 * PRODUCTION PARITY: when there is NO override in effect (CINATRA_BASE_URL unset,
 * or set to anything outside the container-host allowlist) this is a bare
 * wp_safe_remote_post( $endpoint, $args ) with the caller's args UNCHANGED — no
 * forced redirection, no filters — so it is byte-identical to the pre-override
 * call that used WordPress's default args (which permit redirects). The
 * redirection => 0 hardening is applied ONLY on the override path, where
 * disabling redirects is correct: it stops a 3xx from bouncing the request —
 * carrying the Bearer key — to a host outside the one validated override origin.
 * No browser/user input influences the host on either path.
 *
 * SSRF SCOPE: both request-scoped filters are bound to the EXACT override origin
 * (scheme+host+port) via the $host/$url args WordPress passes them, so even while
 * they are installed for the call window they relax NOTHING but that one origin —
 * any other outbound request during the window sees the unchanged value.
 *
 * @param string $endpoint The full request URL (already built from cinatra_server_base_url()).
 * @param array  $args     wp_safe_remote_post args.
 * @return array|WP_Error The wp_safe_remote_post result.
 */
function cinatra_server_post( string $endpoint, array $args ) {
	// Only relax the SSRF denylist when this endpoint's EXACT origin
	// (scheme://host[:port]) is the validated CINATRA_BASE_URL override (a
	// trusted, operator-set container signal). Matching the full origin — not
	// just the host — keeps the relaxation tied to the one host:port we
	// safe-list below, so a same-host/different-port URL never inherits it.
	$override = cinatra_server_base_url( '' ); // '' browser-base => non-empty only if env validates.
	$allow    = ( '' !== $override && cinatra_site_origin( $endpoint ) === $override );

	if ( ! $allow ) {
		// PRODUCTION PATH: byte-identical to the pre-override call — WordPress's
		// default args (which permit redirects), no SSRF-filter relaxation.
		return wp_safe_remote_post( $endpoint, $args );
	}

	// OVERRIDE PATH (hardened): never follow redirects on the internal call so a
	// 3xx cannot bounce the Bearer-key request off the one validated host.
	$args['redirection'] = 0;

	// Request-scoped filters bound to the EXACT override origin. WordPress passes
	// the requested ($host, $url) to BOTH filters; we compare the request URL's
	// FULL origin (scheme://host[:port]) — via the same cinatra_site_origin()
	// used for the entry guard above — to the validated override origin, and
	// relax ONLY on an exact match. Anything else (different host, OR the same
	// host on a different scheme/port) sees the value WordPress would have used,
	// so no other outbound request in the window is affected. Note the host
	// filter MUST also be origin-bound: a same-host/WP-default-safe-port request
	// (e.g. host.docker.internal:8080 during a :3000 window) is a loopback/private
	// host that arrives with $is_external=false; returning true for it on a
	// host-only check would wrongly relax it even though its port never needed
	// safe-listing. Both filters are removed in the finally no matter how the
	// request returns.
	$override_port = (int) wp_parse_url( $override, PHP_URL_PORT );
	$permit_host   = static function ( $is_external, $request_host, $request_url = '' ) use ( $override ) {
		// Only treat the EXACT override origin as an allowed external host; every
		// other host (incl. the same host on another scheme/port) keeps WordPress's
		// own decision unchanged.
		return ( '' !== (string) $request_url && cinatra_site_origin( (string) $request_url ) === $override )
			? true
			: $is_external;
	};
	$permit_port   = static function ( $ports, $request_host, $request_url = '' ) use ( $override, $override_port ) {
		// Widen the safe-port set ONLY when this request targets the exact override
		// origin (scheme+host+port). Any other host/scheme/port sees $ports unchanged.
		if ( $override_port > 0 && '' !== (string) $request_url
			&& cinatra_site_origin( (string) $request_url ) === $override
			&& ! in_array( $override_port, (array) $ports, true ) ) {
			$ports[] = $override_port;
		}
		return $ports;
	};
	add_filter( 'http_request_host_is_external', $permit_host, 10, 3 );
	add_filter( 'http_allowed_safe_ports', $permit_port, 10, 3 );
	try {
		return wp_safe_remote_post( $endpoint, $args );
	} finally {
		remove_filter( 'http_request_host_is_external', $permit_host, 10 );
		remove_filter( 'http_allowed_safe_ports', $permit_port, 10 );
	}
}

/** The exact, contract-pinned callback redirect_uri for this site. */
function cinatra_connect_redirect_uri(): string {
	return admin_url( 'admin-post.php?action=' . CINATRA_CONNECT_CALLBACK_ACTION );
}

/**
 * Base64url with no padding.
 *
 * @param string $bin Raw binary string to encode.
 * @return string The base64url-encoded value (no '=' padding).
 */
function cinatra_base64url( string $bin ): string {
	return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url data encoding, not code obfuscation.
}

/**
 * Store a short-lived result message for the current user, surfaced once on the
 * settings page. $type is 'success' | 'error'.
 *
 * @param string $type    Result type, 'success' or 'error'.
 * @param string $message Human-readable message to surface on the settings page.
 * @return void
 */
function cinatra_set_connect_result( string $type, string $message ): void {
	set_transient(
		CINATRA_CONNECT_RESULT_KEY_PREFIX . get_current_user_id(),
		array(
			'type'    => $type,
			'message' => $message,
		),
		60
	);
}

/** Render + clear the one-time connect result notice on the settings page. */
function cinatra_render_connect_result_notice(): void {
	$key    = CINATRA_CONNECT_RESULT_KEY_PREFIX . get_current_user_id();
	$result = get_transient( $key );
	if ( ! is_array( $result ) || empty( $result['message'] ) ) {
		return;
	}
	delete_transient( $key );
	$class = ( 'success' === $result['type'] ) ? 'notice-success' : 'notice-error';
	printf(
		'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $class ),
		esc_html( (string) $result['message'] )
	);
}

/** Redirect back to the settings page after a connect attempt. */
function cinatra_connect_redirect_to_settings(): void {
	wp_safe_redirect( admin_url( 'options-general.php?page=cinatra' ) );
	exit;
}

/**
 * Step 1 — start the redirect handshake.
 */
add_action(
	'admin_post_cinatra_connect_start',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'cinatra' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'cinatra_connect_start' );

		$raw  = isset( $_POST['cinatra_connect_url'] ) ? wp_unslash( $_POST['cinatra_connect_url'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw value is immediately strictly validated by cinatra_validate_instance_url(); sanitize_text_field would corrupt valid opaque strings.
		$base = cinatra_validate_instance_url( is_string( $raw ) ? $raw : '' );
		if ( '' === $base ) {
			cinatra_set_connect_result( 'error', __( 'Enter a valid https Cinatra instance URL.', 'cinatra' ) );
			cinatra_connect_redirect_to_settings();
		}

		// PKCE S256 + random state.
		$verifier     = cinatra_base64url( random_bytes( 48 ) );
		$challenge    = cinatra_base64url( hash( 'sha256', $verifier, true ) );
		$state        = cinatra_base64url( random_bytes( 24 ) );
		$redirect_uri = cinatra_connect_redirect_uri();

		set_transient(
			CINATRA_CONNECT_STATE_PREFIX . hash( 'sha256', $state ),
			array(
				'user_id'       => get_current_user_id(),
				'instance_url'  => $base,
				'redirect_uri'  => $redirect_uri,
				'code_verifier' => $verifier,
				'created_at'    => time(),
			),
			CINATRA_CONNECT_STATE_TTL
		);

		$widget_origin = cinatra_site_origin( admin_url() );
		$authorize_url = $base . '/connect/authorize?' . http_build_query(
			array(
				'client'                => CINATRA_CONNECT_CLIENT,
				'redirect_uri'          => $redirect_uri,
				'state'                 => $state,
				'scope'                 => CINATRA_CONNECT_SCOPE,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
				'widget_origin'         => $widget_origin,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		// External redirect to the admin-supplied (validated) instance host. Use
		// wp_safe_redirect with a TEMPORARY allowed-hosts filter scoped to exactly
		// the validated host, so the redirect target is allow-listed rather than
		// bypassing the safe-redirect machinery.
		$instance_host = wp_parse_url( $base, PHP_URL_HOST );
		$allow_host    = function ( $hosts ) use ( $instance_host ) {
			if ( is_string( $instance_host ) && '' !== $instance_host ) {
				$hosts[] = $instance_host;
			}
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_host );
		wp_safe_redirect( $authorize_url );
		remove_filter( 'allowed_redirect_hosts', $allow_host );
		exit;
	}
);

/**
 * Step 4 — handle the redirect back and exchange the code server-side.
 */
add_action(
	'admin_post_cinatra_connect_callback',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'cinatra' ), '', array( 'response' => 403 ) );
		}

		// CSRF for this OAuth callback is the single-use `state` (validated against
		// a per-state transient bound to the current user below) — a WP nonce
		// cannot survive the external redirect round-trip. Inputs ARE sanitized.
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $code || '' === $state ) {
			cinatra_set_connect_result( 'error', __( 'Cinatra did not return an authorization code. Connection cancelled.', 'cinatra' ) );
			cinatra_connect_redirect_to_settings();
		}

		$state_key = CINATRA_CONNECT_STATE_PREFIX . hash( 'sha256', $state );
		$stored    = get_transient( $state_key );
		delete_transient( $state_key ); // Single-use, consume immediately.

		if ( ! is_array( $stored )
		|| (int) ( $stored['user_id'] ?? 0 ) !== get_current_user_id()
		|| empty( $stored['instance_url'] )
		|| empty( $stored['code_verifier'] ) ) {
			cinatra_set_connect_result( 'error', __( 'This connection request expired or did not match. Please try again.', 'cinatra' ) );
			cinatra_connect_redirect_to_settings();
		}

		$result = cinatra_connect_exchange(
			(string) $stored['instance_url'],
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client'        => CINATRA_CONNECT_CLIENT,
				'redirect_uri'  => (string) ( $stored['redirect_uri'] ?? cinatra_connect_redirect_uri() ),
				'code_verifier' => (string) $stored['code_verifier'],
			)
		);

		cinatra_connect_apply_result( $result );
		cinatra_connect_redirect_to_settings();
	}
);

/**
 * Fallback — exchange a pasted connection string (install_code).
 */
add_action(
	'admin_post_cinatra_connect_install_code',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'cinatra' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'cinatra_connect_install_code' );

		$raw    = isset( $_POST['cinatra_connection_string'] ) ? wp_unslash( $_POST['cinatra_connection_string'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw value is immediately parsed/strictly validated by cinatra_parse_connection_string(); sanitize_text_field would corrupt valid opaque strings.
		$parsed = cinatra_parse_connection_string( is_string( $raw ) ? $raw : '' );
		if ( null === $parsed ) {
			cinatra_set_connect_result( 'error', __( 'That connection string is not valid.', 'cinatra' ) );
			cinatra_connect_redirect_to_settings();
		}

		$result = cinatra_connect_exchange(
			$parsed['instance_url'],
			array(
				'grant_type'   => 'install_code',
				'install_code' => $parsed['install_code'],
				'client'       => CINATRA_CONNECT_CLIENT,
			)
		);

		cinatra_connect_apply_result( $result );
		cinatra_connect_redirect_to_settings();
	}
);

/**
 * Parse a connection string of the form `cinatra-connect:<base64url(json)>` or a
 * plain JSON `{"url":"https://…","install_code":"…"}`. Returns
 * ['instance_url' => normalized, 'install_code' => string] or null.
 *
 * @param string $raw Raw connection string (prefixed base64url JSON or plain JSON).
 * @return array|null Parsed ['instance_url' => string, 'install_code' => string], or null if invalid.
 */
function cinatra_parse_connection_string( string $raw ): ?array {
	$raw = trim( $raw );
	if ( '' === $raw ) {
		return null;
	}
	$json = null;
	if ( stripos( $raw, 'cinatra-connect:' ) === 0 ) {
		$payload = substr( $raw, strlen( 'cinatra-connect:' ) );
		$decoded = base64_decode( strtr( $payload, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64url data decoding, not code obfuscation; output feeds json_decode().
		if ( false !== $decoded ) {
			$json = json_decode( $decoded, true );
		}
	} else {
		$json = json_decode( $raw, true );
	}
	if ( ! is_array( $json ) ) {
		return null;
	}
	$url  = cinatra_validate_instance_url( (string) ( $json['url'] ?? $json['instance_url'] ?? '' ) );
	$code = cinatra_sanitize_secret( (string) ( $json['install_code'] ?? $json['code'] ?? '' ) );
	if ( '' === $url || '' === $code ) {
		return null;
	}
	return array(
		'instance_url' => $url,
		'install_code' => $code,
	);
}

/**
 * Server-to-server token exchange against {instance}/api/connect/token. Returns
 * a normalized array: ['ok' => bool, 'response' => array|null].
 *
 * @param string $instance_url Target Cinatra instance URL.
 * @param array  $body         Request body for the token exchange.
 * @return array Normalized result: ['ok' => bool, 'response' => array|null].
 */
function cinatra_connect_exchange( string $instance_url, array $body ): array {
	$base = cinatra_validate_instance_url( $instance_url );
	if ( '' === $base ) {
		return array(
			'ok'       => false,
			'response' => null,
		);
	}
	// $base is the browser/admin-facing instance origin (it is what we persist as
	// cinatra_url on success). The TRANSPORT target may be redirected to the
	// container-reachable base via CINATRA_BASE_URL; the stored value is always
	// $base, never the override (see cinatra_connect_apply_result()).
	$server_base = cinatra_server_base_url( $base );
	$response    = cinatra_server_post(
		$server_base . '/api/connect/token',
		array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $response ) ) {
		error_log( '[cinatra] connect token exchange transport error: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional server-side error logging; detail is never reflected to the browser.
		return array(
			'ok'       => false,
			'response' => null,
		);
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	$json   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( $status < 200 || $status >= 300 || ! is_array( $json ) || empty( $json['credential'] ) ) {
		error_log( '[cinatra] connect token exchange failed (HTTP ' . $status . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional server-side error logging; detail is never reflected to the browser.
		return array(
			'ok'       => false,
			'response' => null,
		);
	}
	// Bind the stored URL to the instance the credential came from.
	$json['__instance_url'] = $base;
	return array(
		'ok'       => true,
		'response' => $json,
	);
}

/**
 * Persist a successful exchange to wp_options and set the result notice. The
 * long-lived credential is stored server-side; nothing is returned to the
 * browser beyond a generic success/failure notice.
 *
 * @param array $result Normalized exchange result from cinatra_connect_exchange().
 * @return void
 */
function cinatra_connect_apply_result( array $result ): void {
	if ( empty( $result['ok'] ) || ! is_array( $result['response'] ?? null ) ) {
		cinatra_set_connect_result( 'error', __( 'Could not complete the connection. Check the URL and try again, or contact your administrator.', 'cinatra' ) );
		return;
	}
	$r            = $result['response'];
	$instance_url = cinatra_validate_instance_url( (string) ( $r['__instance_url'] ?? $r['url'] ?? '' ) );
	if ( '' !== $instance_url ) {
		update_option( 'cinatra_url', $instance_url );
	}
	update_option( 'cinatra_api_key', cinatra_sanitize_secret( (string) $r['credential'] ) );
	if ( ! empty( $r['cinatraInstanceId'] ) ) {
		update_option( 'cinatra_instance_id', sanitize_text_field( (string) $r['cinatraInstanceId'] ) );
	}
	if ( ! empty( $r['webhookSecret'] ) ) {
		update_option( 'cinatra_webhook_secret', cinatra_sanitize_secret( (string) $r['webhookSecret'] ) );
	}
	cinatra_set_connect_result( 'success', __( 'Connected to Cinatra. The integration credential is stored on this server.', 'cinatra' ) );
}

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

add_action( 'admin_enqueue_scripts', 'cinatra_enqueue_widget' );

/**
 * Enqueue the locally-vendored widget asset and localize a secret-free config.
 * Named (not anonymous) so it stays unit-testable and overridable.
 */
function cinatra_enqueue_widget(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$url     = get_option( 'cinatra_url', '' );
	$api_key = get_option( 'cinatra_api_key', '' );
	// Without an instance URL + integration key there is nothing for the broker
	// to talk to; keep the widget off rather than mount a broken assistant.
	if ( empty( $url ) || empty( $api_key ) ) {
		return;
	}

	// Fallback chrome (always shown until the widget mounts). Stylesheet + JS
	// are enqueued (not inline) so Plugin Check sees no bare <style>/<script>.
	wp_enqueue_style(
		'cinatra-fallback',
		plugins_url( 'assets/cinatra-fallback.css', __FILE__ ),
		array(),
		CINATRA_PLUGIN_VERSION
	);
	// Per-request theme colors as CSS custom properties, attached to the
	// registered handle (allowed by Plugin Check, unlike a bare <style> echo).
	wp_add_inline_style(
		'cinatra-fallback',
		sprintf(
			':root{--cinatra-accent-soft:%1$s;--cinatra-accent-soft-hover:%2$s;--cinatra-logo-color:%3$s;}',
			sanitize_hex_color( CINATRA_THEME_ACCENT_SOFT ),
			sanitize_hex_color( CINATRA_THEME_ACCENT_SOFT_HOV ),
			sanitize_hex_color( CINATRA_THEME_LOGO_COLOR )
		)
	);
	wp_enqueue_script(
		'cinatra-fallback',
		plugins_url( 'assets/cinatra-fallback.js', __FILE__ ),
		array(),
		CINATRA_PLUGIN_VERSION,
		true
	);
	wp_localize_script(
		'cinatra-fallback',
		'CinatraFallback',
		array(
			'cinatraUrl' => rtrim( $url, '/' ),
			'i18n'       => array(
				'noUrl'             => __( 'No Cinatra instance URL is configured.', 'cinatra' ),
				'reachableNoWidget' => __( 'Cinatra is reachable but the widget has not loaded yet. Try refreshing the page.', 'cinatra' ),
				/* translators: %s: HTTP status code */
				'httpStatus'        => __( 'Cinatra returned HTTP %s. Check your instance.', 'cinatra' ),
				/* translators: %s: instance URL */
				'unreachable'       => __( 'Cannot reach %s. Check that your Cinatra instance is running.', 'cinatra' ),
			),
		)
	);

	wp_enqueue_script(
		'cinatra',
		plugins_url( 'assets/cinatra-widget.js', __FILE__ ),
		array(),
		CINATRA_PLUGIN_VERSION,
		true
	);
	$instance_id = get_option( 'cinatra_instance_id', '' );
	wp_localize_script(
		'cinatra',
		'CinatraConfig',
		array(
			'contractVersion' => CINATRA_CONTRACT_VERSION,
			'cinatraUrl'      => rtrim( $url, '/' ),
			// No apiKey. The browser obtains a short-lived token from this endpoint.
			'tokenEndpoint'   => rest_url( 'cinatra/v1/token' ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'instanceId'      => $instance_id,
			'wpAdminUrl'      => admin_url(),
		)
	);
}

// ---------------------------------------------------------------------------
// Mount point + inline fallback button — always visible even when Cinatra is down
// ---------------------------------------------------------------------------

add_action(
	'admin_footer',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$url     = get_option( 'cinatra_url', '' );
		$api_key = get_option( 'cinatra_api_key', '' );
		// Only render when the widget is actually enqueued (same guard as the
		// enqueue), so the fallback button never appears without its script/styles.
		if ( empty( $url ) || empty( $api_key ) ) {
			return;
		}

		// Markup only — no inline <style>/<script>. Styling comes from the enqueued
		// cinatra-fallback.css; behaviour from the enqueued cinatra-fallback.js.
		// The SVG uses currentColor so the brand color is applied via CSS.
		echo '<div id="cinatra-root"></div>';
		?>
	<button id="cw-fallback-btn" title="<?php echo esc_attr__( 'Cinatra AI Assistant', 'cinatra' ); ?>" aria-label="<?php echo esc_attr__( 'Cinatra AI Assistant', 'cinatra' ); ?>" style="color:<?php echo esc_attr( sanitize_hex_color( CINATRA_THEME_LOGO_COLOR ) ); ?>;">
		<svg width="22" height="14" viewBox="0 0 512 320" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
		<path d="M72 214 C 72 200 96 190 130 188 C 168 186 196 200 256 210 C 316 220 358 214 400 200 C 426 192 440 196 440 208 C 440 222 420 234 388 242 C 340 254 288 256 256 256 C 202 256 132 248 100 238 C 80 232 72 224 72 214 Z" fill="currentColor"/>
		<path d="M146 188 C 150 130 176 86 212 72 C 226 66 240 64 252 64 C 262 64 270 70 268 80 L 264 100 C 272 88 288 82 300 82 C 332 82 356 118 362 188 Z" fill="currentColor"/>
		</svg>
	</button>
	<div id="cw-fallback-error">
		<div class="cw-fe-header">
		<p class="cw-fe-title"><?php echo esc_html__( 'Cinatra is unavailable', 'cinatra' ); ?></p>
		<button class="cw-fe-close" id="cw-fe-close" aria-label="<?php echo esc_attr__( 'Close', 'cinatra' ); ?>">&times;</button>
		</div>
		<p class="cw-fe-msg" id="cw-fe-msg"><?php echo esc_html__( 'Could not connect to your Cinatra instance.', 'cinatra' ); ?></p>
	</div>
		<?php
	}
);

// ---------------------------------------------------------------------------

add_action(
	'rest_api_init',
	function () {
		// Short-lived stream-token broker. The browser calls this same-origin route
		// (with a wp_rest nonce); the PHP backend holds the long-lived integration
		// key, performs a server-to-server token exchange with the Cinatra instance,
		// and returns ONLY the short-lived token to the browser. The long-lived key
		// never leaves the server. See wp#4 / cinatra#220.
		register_rest_route(
			'cinatra/v1',
			'/token',
			array(
				'methods'             => 'POST',
				'callback'            => 'cinatra_rest_mint_token',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'cinatra/v1',
			'/webhooks',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => 'cinatra_rest_list_webhooks',
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				),
				array(
					'methods'             => 'POST',
					'callback'            => 'cinatra_rest_create_webhook',
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'args'                => array(
						'event_type' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $value ) {
								return is_string( $value ) && '' !== $value && strlen( $value ) <= 100;
							},
						),
						'target_url' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => function ( $value ) {
								return esc_url_raw( (string) $value, array( 'http', 'https' ) );
							},
							'validate_callback' => function ( $value ) {
								$clean = esc_url_raw( (string) $value, array( 'http', 'https' ) );
								return is_string( $clean ) && '' !== $clean;
							},
						),
						'post_types' => array(
							'required'          => false,
							'type'              => 'array',
							'sanitize_callback' => function ( $value ) {
								return array_values(
									array_filter(
										array_map(
											'sanitize_key',
											(array) $value
										),
										function ( $pt ) {
											return post_type_exists( $pt );
										}
									)
								);
							},
						),
					),
				),
			)
		);

		register_rest_route(
			'cinatra/v1',
			'/webhooks/(?P<id>[\w-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => 'cinatra_rest_delete_webhook',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}
);

/**
 * Agent slug for the WordPress content-editor assistant. The token + stream +
 * capabilities endpoints all live under /api/agents/{slug}/ on the instance.
 */
const CINATRA_AGENT_SLUG = 'wordpress-content-editor';

/**
 * Normalize a URL down to its scheme://host[:port] origin, lowercased, no
 * trailing slash / path / query / fragment. Returns '' if the input has no
 * usable scheme+host. The instance binds the minted token to this exact origin.
 *
 * @param string $url URL to reduce to its origin.
 * @return string The scheme://host[:port] origin, or '' if no usable scheme+host.
 */
function cinatra_site_origin( string $url ): string {
	$parts = wp_parse_url( $url );
	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}
	$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
	if ( ! empty( $parts['port'] ) ) {
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
 *
 * @param WP_REST_Request $request The incoming REST request (carries the wp_rest nonce + JSON params).
 * @return WP_REST_Response The short-lived token envelope, or an error response.
 */
function cinatra_rest_mint_token( WP_REST_Request $request ): WP_REST_Response {
	// CSRF: a valid wp_rest nonce must accompany the cookie-authenticated call.
	$nonce = $request->get_header( 'X-WP-Nonce' );
	if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return new WP_REST_Response( array( 'error' => __( 'Invalid or missing nonce.', 'cinatra' ) ), 403 );
	}

	$url     = rtrim( (string) get_option( 'cinatra_url', '' ), '/' );
	$api_key = (string) get_option( 'cinatra_api_key', '' );
	if ( empty( $url ) || empty( $api_key ) ) {
		return new WP_REST_Response(
			array( 'error' => __( 'Cinatra URL or API key is not configured.', 'cinatra' ) ),
			500
		);
	}

	// Bind to the origin the BROWSER will present when it streams. The widget
	// runs in wp-admin, so its Origin header is the admin origin (admin_url()),
	// which can legitimately differ from the front-end home origin (WP_HOME vs
	// WP_SITEURL, or admin-over-SSL setups). The instance re-checks this exact
	// origin at stream-consume time, so it must match the admin origin.
	$origin = cinatra_site_origin( admin_url() );
	if ( empty( $origin ) ) {
		return new WP_REST_Response(
			array( 'error' => __( 'Could not derive this site origin.', 'cinatra' ) ),
			500
		);
	}

	$params           = $request->get_json_params();
	$contract_version = CINATRA_CONTRACT_VERSION;
	if ( is_array( $params ) && ! empty( $params['contractVersion'] ) ) {
		$candidate = sanitize_text_field( (string) $params['contractVersion'] );
		// Only accept the versions this plugin knows; otherwise pin to ours.
		if ( in_array( $candidate, array( 'v1', 'v2' ), true ) ) {
			$contract_version = $candidate;
		}
	}

	// The token is minted server-to-server: route the TRANSPORT to the
	// container-reachable base when CINATRA_BASE_URL is set (dev/container
	// topology), else to the configured cinatra_url (production, unchanged). The
	// origin bound into $body above stays the BROWSER origin (admin_url()) — the
	// instance re-checks that at stream-consume time, so it must NOT be rewritten.
	$server_base    = cinatra_server_base_url( $url );
	$token_endpoint = $server_base . '/api/agents/' . CINATRA_AGENT_SLUG . '/token';
	$body           = array(
		'contractVersion' => $contract_version,
		'origin'          => $origin,
		'sub'             => 'wp-user-' . get_current_user_id(),
		'scope'           => CINATRA_AGENT_SLUG . '.stream',
	);

	$response = cinatra_server_post(
		$token_endpoint,
		array(
			'timeout' => 10,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		// Log the transport detail server-side; return a generic message so we
		// never reflect low-level/internal error text to the browser.
		error_log( '[cinatra] token endpoint unreachable: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional server-side error logging; detail is never reflected to the browser.
		return new WP_REST_Response(
			array( 'error' => __( 'Could not reach the Cinatra instance. Check the connector URL, or contact your administrator.', 'cinatra' ) ),
			502
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$raw    = (string) wp_remote_retrieve_body( $response );
	$json   = json_decode( $raw, true );

	if ( $status < 200 || $status >= 300 || ! is_array( $json ) || empty( $json['token'] ) ) {
		// Do NOT reflect the upstream body to the browser — it could contain
		// instance internals. Log the detail server-side for admins; return a
		// generic, actionable message. Always 502: from the browser's
		// perspective the upstream Cinatra instance failed the exchange
		// (bad/rotated key, origin not configured, unreachable, malformed).
		$detail = ( is_array( $json ) && ! empty( $json['error'] ) )
			? (string) $json['error']
			: substr( $raw, 0, 500 );
		error_log( '[cinatra] token exchange failed (HTTP ' . $status . '): ' . $detail ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional server-side error logging; detail is never reflected to the browser.
		return new WP_REST_Response(
			array( 'error' => __( 'Cinatra could not issue a session token. Check the connector settings, or contact your administrator.', 'cinatra' ) ),
			502
		);
	}

	// Return ONLY the short-lived token envelope to the browser.
	return new WP_REST_Response(
		array(
			'token'           => (string) $json['token'],
			'tokenType'       => isset( $json['tokenType'] ) ? (string) $json['tokenType'] : 'Bearer',
			'expiresIn'       => isset( $json['expiresIn'] ) ? (int) $json['expiresIn'] : 300,
			'expiresAt'       => isset( $json['expiresAt'] ) ? (string) $json['expiresAt'] : null,
			'contractVersion' => isset( $json['contractVersion'] ) ? (string) $json['contractVersion'] : $contract_version,
			'scope'           => isset( $json['scope'] ) ? (string) $json['scope'] : ( CINATRA_AGENT_SLUG . '.stream' ),
		),
		200
	);
}

/**
 * REST: list the stored webhook subscriptions.
 *
 * @param WP_REST_Request $request The incoming REST request (unused; required by the callback signature).
 * @return WP_REST_Response The current subscription list.
 */
function cinatra_rest_list_webhooks( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required WP_REST_Request callback signature; this endpoint takes no request args.
	return rest_ensure_response( cinatra_get_webhook_subscriptions() );
}

/**
 * REST: create a webhook subscription from sanitized/validated request args.
 *
 * @param WP_REST_Request $request The incoming REST request (event_type, target_url, post_types).
 * @return WP_REST_Response The created subscription (201), an existing dupe (409), or an error.
 */
function cinatra_rest_create_webhook( WP_REST_Request $request ): WP_REST_Response {
	// Inputs were sanitized/validated by the route 'args' schema.
	$event_type = (string) $request->get_param( 'event_type' );
	$target_url = (string) $request->get_param( 'target_url' );
	$post_types = array_values( (array) ( $request->get_param( 'post_types' ) ?? array() ) );

	if ( '' === $event_type || '' === $target_url ) {
		return new WP_REST_Response(
			array( 'error' => __( 'event_type and target_url are required.', 'cinatra' ) ),
			400
		);
	}

	$existing = cinatra_get_webhook_subscriptions();

	// Cap the number of stored subscriptions (unbounded-option / DoS guard).
	if ( count( $existing ) >= CINATRA_MAX_WEBHOOK_SUBSCRIPTIONS ) {
		return new WP_REST_Response(
			array( 'error' => __( 'Maximum number of webhook subscriptions reached.', 'cinatra' ) ),
			409
		);
	}

	// Dedupe: if a subscription with the same event_type + target_url already exists, return it with 409.
	foreach ( $existing as $subscription ) {
		if ( ( $subscription['event_type'] ?? '' ) === $event_type &&
			( $subscription['target_url'] ?? '' ) === $target_url ) {
			return new WP_REST_Response( $subscription, 409 );
		}
	}

	$new_subscription = array(
		'id'         => wp_generate_uuid4(),
		'event_type' => $event_type,
		'target_url' => $target_url,
		'post_types' => $post_types,
		'created_at' => gmdate( 'c' ),
	);

	$existing[] = $new_subscription;
	cinatra_save_webhook_subscriptions( $existing );

	return new WP_REST_Response( $new_subscription, 201 );
}

/**
 * REST: delete a webhook subscription by id.
 *
 * @param WP_REST_Request $request The incoming REST request (carries the 'id' path param).
 * @return WP_REST_Response { deleted: true } on success, or a 404 error if not found.
 */
function cinatra_rest_delete_webhook( WP_REST_Request $request ): WP_REST_Response {
	$id      = sanitize_text_field( (string) $request->get_param( 'id' ) );
	$current = cinatra_get_webhook_subscriptions();
	$updated = array_values(
		array_filter(
			$current,
			function ( $s ) use ( $id ) {
				return ( $s['id'] ?? '' ) !== $id;
			}
		)
	);

	if ( count( $updated ) === count( $current ) ) {
		return new WP_REST_Response( array( 'error' => __( 'Subscription not found.', 'cinatra' ) ), 404 );
	}

	cinatra_save_webhook_subscriptions( $updated );
	return new WP_REST_Response( array( 'deleted' => true ) );
}

/**
 * Read the stored webhook subscriptions from wp_options.
 *
 * @return array Decoded list of subscription records (empty array if unset/invalid).
 */
function cinatra_get_webhook_subscriptions(): array {
	$raw     = get_option( 'cinatra_webhook_subscriptions', '[]' );
	$decoded = json_decode( $raw, true );
	return is_array( $decoded ) ? $decoded : array();
}

/**
 * Persist the webhook subscriptions to wp_options as a JSON array.
 *
 * @param array $subscriptions Subscription records to store.
 * @return void
 */
function cinatra_save_webhook_subscriptions( array $subscriptions ): void {
	update_option( 'cinatra_webhook_subscriptions', wp_json_encode( array_values( $subscriptions ) ) );
}

