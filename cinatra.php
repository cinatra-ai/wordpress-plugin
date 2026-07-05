<?php
/**
 * Plugin Name: Cinatra
 * Plugin URI: https://cinatra.ai
 * Description: Embeds the Cinatra AI assistant chat widget in WordPress admin. Floating button bottom-right; opens chat panel on click.
 * Version: 0.1.5
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
define( 'CINATRA_PLUGIN_VERSION', '0.1.5' );
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
// WordPress MCP Adapter (https://github.com/WordPress/mcp-adapter) detection.
//
// The adapter is a SOFT / OPTIONAL dependency: the base Cinatra chat widget
// works without it. The adapter is REQUIRED for the AI-tools path (the ability
// for the assistant to read and edit WordPress content via MCP). Because the
// adapter is distributed via GitHub Releases and is not on the wordpress.org
// directory, a hard `Requires Plugins:` header is intentionally NOT added (it
// would break wp.org Plugin Review and give users no install link). See #62.
//
// Detection strategy: check whether the adapter's known plugin file is active.
// The adapter registers its own REST routes, so absence is detectable at
// runtime without requiring the adapter to be installed. The slug
// `mcp-adapter/mcp-adapter.php` is the adapter's canonical plugin file path
// (verified from the WordPress/mcp-adapter GitHub repository). If the adapter
// ever moves to wp.org under a different slug, this constant should be updated.
define( 'CINATRA_MCP_ADAPTER_PLUGIN_FILE', 'mcp-adapter/mcp-adapter.php' );
define( 'CINATRA_MCP_ADAPTER_RELEASE_URL', 'https://github.com/WordPress/mcp-adapter/releases/latest' );

/**
 * Detect whether the WordPress MCP Adapter plugin is installed and active.
 *
 * The adapter is the recommended companion for the AI-tools path. When it is
 * absent the base chat widget still works, but the assistant cannot use
 * WordPress AI tools (reading/editing content via MCP). This function is the
 * single source of truth for that state; all notices and config flags read it.
 *
 * @return bool True if the adapter plugin is active (AI-tools path enabled).
 */
function cinatra_mcp_adapter_active(): bool {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return (bool) is_plugin_active( CINATRA_MCP_ADAPTER_PLUGIN_FILE );
}

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
		// cinatra_webhook_secret is intentionally NOT registered as a settings
		// field any more (cinatra#974): the publish-webhook signing secret is
		// server-issued by the connect token exchange as a PAIR with
		// cinatra_webhook_binding_id, and a manually pasted secret could never
		// carry the paired binding id — so the pair is programmatic-only and
		// the settings form never reads or writes it.
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

		<?php cinatra_render_setup_checklist(); ?>

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
					<th scope="row"><?php echo esc_html__( 'Publish Webhooks', 'cinatra' ); ?></th>
					<td>
						<p>
							<?php if ( cinatra_webhook_pair_configured() ) : ?>
								<strong><?php echo esc_html__( 'Provisioned.', 'cinatra' ); ?></strong>
								<?php echo esc_html__( 'Publish events are signed and delivered to your Cinatra instance.', 'cinatra' ); ?>
							<?php else : ?>
								<strong><?php echo esc_html__( 'Not provisioned.', 'cinatra' ); ?></strong>
								<?php echo esc_html__( 'Use "Connect with Cinatra" (or reconnect) to provision publish webhooks. The signing credentials are issued by your Cinatra instance during the connection — there is nothing to paste here.', 'cinatra' ); ?>
							<?php endif; ?>
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
 * Render a "Setup checklist" card on the settings page. Surfaces the key
 * optional dependency — the WordPress MCP Adapter — with a clear status
 * indicator and a direct link to its GitHub release. The checklist makes the
 * dependency first-class on the settings UI without burying it in prose.
 *
 * The card is always rendered (even when everything is configured) so admins
 * can see at a glance which capabilities are enabled.
 *
 * @return void
 */
function cinatra_render_setup_checklist(): void {
	$mcp_active = cinatra_mcp_adapter_active();
	if ( $mcp_active ) {
		$mcp_icon   = '&#10003;'; // Unicode U+2713 CHECK MARK.
		$mcp_class  = 'cinatra-check-ok';
		$mcp_status = __( 'WordPress MCP Adapter is active — AI tools enabled.', 'cinatra' );
		$mcp_extra  = '';
	} else {
		$mcp_icon   = '&#9679;'; // Unicode U+25CF BLACK CIRCLE, used as a status dot.
		$mcp_class  = 'cinatra-check-pending';
		$mcp_status = __( 'WordPress MCP Adapter is not active — AI tools are not available.', 'cinatra' );
		$mcp_extra  = sprintf(
			'<br /><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( CINATRA_MCP_ADAPTER_RELEASE_URL ),
			esc_html__( 'Download the WordPress MCP Adapter from GitHub', 'cinatra' )
		);
	}
	?>
	<div class="card" style="max-width:680px;margin-top:24px;">
		<h2 style="margin-top:0;"><?php echo esc_html__( 'AI tools setup', 'cinatra' ); ?></h2>
		<p class="description">
			<?php echo esc_html__( 'The Cinatra assistant works without the adapter below, but installing it unlocks WordPress AI tools — letting the assistant read and edit your site content directly from the chat.', 'cinatra' ); ?>
		</p>
		<ul style="list-style:none;margin:0;padding:0;">
			<li class="<?php echo esc_attr( $mcp_class ); ?>" style="margin-bottom:8px;">
				<span aria-hidden="true" style="margin-right:6px;"><?php echo wp_kses_post( $mcp_icon ); ?></span>
				<?php echo esc_html( $mcp_status ); ?>
				<?php
				echo wp_kses(
					$mcp_extra,
					array(
						'a'  => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
						'br' => array(),
					)
				);
				?>
			</li>
		</ul>
		<p class="description" style="margin-top:12px;">
			<?php
			printf(
				/* translators: %s: URL to WordPress MCP Adapter releases */
				esc_html__( 'The WordPress MCP Adapter is distributed via GitHub Releases (%s) and is not in the wordpress.org directory. Install it manually and activate it like any other plugin.', 'cinatra' ),
				'<a href="' . esc_url( CINATRA_MCP_ADAPTER_RELEASE_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'WordPress/mcp-adapter', 'cinatra' ) . '</a>'
			);
			?>
		</p>
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
		if ( ! cinatra_mcp_adapter_active() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a></p></div>',
				esc_html__( 'WordPress AI tools are not enabled:', 'cinatra' ),
				esc_html__( 'To let the Cinatra assistant read and edit your WordPress content, install and activate the WordPress MCP Adapter. The chat widget works without it.', 'cinatra' ),
				esc_url( CINATRA_MCP_ADAPTER_RELEASE_URL ),
				esc_html__( 'Get the WordPress MCP Adapter', 'cinatra' )
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
		if ( cinatra_webhook_reconnect_needed() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Cinatra publish webhooks are paused:', 'cinatra' ),
				esc_html__( 'This site has a publish-webhook subscription but no server-issued webhook credentials. Reconnect this site to your Cinatra instance ("Connect with Cinatra") to provision them — publish events are not delivered until then.', 'cinatra' )
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
// cinatra#974: the webhook-contract value sent in the token exchange and
// echoed back by a host that provisions the Standard-Webhooks pair.
const CINATRA_WEBHOOK_CONTRACT = 'standard-webhooks';

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
 *   - accepts ONLY a clean ORIGIN (scheme://host[:port], optional single
 *     trailing '/'): ANY path, query, fragment, or userinfo is rejected. The
 *     endpoint path is appended by the plugin, never supplied via the base, so
 *     the API-key-bearing POST can only ever reach the validated origin.
 *
 * The accepted shape is decided by an ANCHORED raw-string match BEFORE trusting
 * PHP's permissive parse_url (which accepts junk hosts/ports): the regex pins
 * the WHOLE string to ^scheme://host[:port]/?$, so a path/query/fragment or a
 * malformed host/port can never slip past it. parse_url is then used only to
 * lower-case + recompose the already-validated pieces.
 *
 * Grammar (linear classes only — NO nested quantifiers, ReDoS-safe):
 *   scheme = https? (case-insensitive)
 *   host   = DNS hostname  : label('.'label)*  label=[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?
 *          | dotted IPv4   : octet '.' octet '.' octet '.' octet (each 0-255)
 *          | bracketed IPv6: '[' [0-9A-Fa-f:]+ ']'
 *   port   = 1-65535 (validated numerically; ':0', ':80x', ':+80', ':1.2',
 *            ':65536', and an empty ':' are all rejected)
 *
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

	// Origin-only grammar, anchored to the WHOLE string. Each alternative class
	// is linear (no nested quantifiers, no `(X+)+`) so the match is ReDoS-safe.
	// - host: DNS hostname OR dotted-quad OR bracketed IPv6.
	// - an OPTIONAL single trailing '/' is the only path allowed; anything
	// else after the authority (a real path, '?', '#', userinfo '@') fails.
	$label     = '[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?';
	$dns       = $label . '(?:\.' . $label . ')*';
	$ipv4_oct  = '(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])';
	$ipv4      = $ipv4_oct . '(?:\.' . $ipv4_oct . '){3}';
	$ipv6      = '\[[0-9A-Fa-f:]+\]';
	$host_re   = '(?:' . $dns . '|' . $ipv4 . '|' . $ipv6 . ')';
	$port_re   = '(?:[0-9]+)';
	$origin_re = '#^https?://' . $host_re . '(?::' . $port_re . ')?/?$#i';
	if ( ! preg_match( $origin_re, $raw ) ) {
		return '';
	}

	// Grammar passed: parse_url now only LOWER-CASES + recomposes the already
	// validated pieces. parse_url alone is too permissive to trust for the
	// accept decision; the anchored regex above is what makes it safe.
	$parts = wp_parse_url( $raw );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}
	// Defense-in-depth: the grammar already excludes userinfo, but never trust a
	// single layer for the API-key destination.
	if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
		return '';
	}
	$scheme = strtolower( $parts['scheme'] );
	$host   = strtolower( $parts['host'] );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return '';
	}
	// Port must be a real 1-65535 number. The grammar guarantees it is all
	// digits when present; re-check the numeric range and reject :0.
	$port = null;
	if ( isset( $parts['port'] ) && '' !== (string) $parts['port'] ) {
		$port = (int) $parts['port'];
		if ( $port < 1 || $port > 65535 ) {
			return '';
		}
	}
	// Narrow host allowlist — the override may ONLY target the container's own
	// loopback or the Docker host-gateway alias, never an arbitrary host.
	if ( ! in_array( $host, CINATRA_SERVER_BASE_ALLOWED_HOSTS, true ) ) {
		return '';
	}
	$base = $scheme . '://' . $host;
	if ( null !== $port ) {
		$base .= ':' . $port;
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
		// The override was SET but is not a clean, allowlisted ORIGIN — discard it
		// and fall back to the browser base. Log a FIXED-TEXT warning only: the
		// raw env value (and never any secret) is intentionally NOT included, so
		// the destination invariant cannot be probed via the log.
		error_log( '[cinatra] CINATRA_BASE_URL is set but is not a valid container-origin override; ignoring it and using the configured Cinatra URL.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional server-side warning; fixed text only, never the raw env value or any secret.
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
				'grant_type'       => 'authorization_code',
				'code'             => $code,
				'client'           => CINATRA_CONNECT_CLIENT,
				'redirect_uri'     => (string) ( $stored['redirect_uri'] ?? cinatra_connect_redirect_uri() ),
				'code_verifier'    => (string) $stored['code_verifier'],
				// cinatra#974: capability signal — this plugin signs publish
				// webhooks as Standard-Webhooks against the generic /webhook
				// route. A host that understands it echoes `webhookContract`
				// and returns the PAIRED webhookSecret + webhookBindingId; an
				// older host ignores the field.
				'webhook_contract' => CINATRA_WEBHOOK_CONTRACT,
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
				'grant_type'       => 'install_code',
				'install_code'     => $parsed['install_code'],
				'client'           => CINATRA_CONNECT_CLIENT,
				// cinatra#974: same capability signal as the redirect path.
				'webhook_contract' => CINATRA_WEBHOOK_CONTRACT,
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
 * Webhook pair semantics (cinatra#974, mirroring the drupal-module):
 * cinatra_webhook_secret + cinatra_webhook_binding_id are a PAIR written
 * together — a secret is only usable against the binding it was minted with.
 * The pair is stored ONLY from a response that ECHOES
 * `webhookContract: "standard-webhooks"` AND carries both halves. When the
 * echo is present but the pair is omitted (a transient binding-mint failure on
 * the host) an existing pair for the SAME instance is kept — the next
 * reconnect re-mints idempotently. When the echo is ABSENT (an older host, or
 * one rolled back below the contract) any stored pair is DISCARDED (codex:
 * such a host serves the plugin's binding as a legacy-bridge row, which
 * rejects Standard-Webhooks headers — keeping the pair would 401 every
 * publish with no "not provisioned" signal). A pair belonging to a DIFFERENT
 * instance can never survive either way: the cinatra_url / instance-id option
 * hooks below clear it the moment the stored identity changes, BEFORE the
 * pair write at the end of this function.
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
	// Always overwrite the instance id from THIS connection — empty when the
	// host returned none (codex: an only-when-present update could leave a
	// stale identity from a previous instance and mask an identity change).
	update_option( 'cinatra_instance_id', sanitize_text_field( (string) ( $r['cinatraInstanceId'] ?? '' ) ) );

	// PAIRED webhook persistence — LAST, so the identity-change hooks above
	// have already cleared any stale pair before the new one lands.
	$contract   = (string) ( $r['webhookContract'] ?? '' );
	$secret     = cinatra_sanitize_secret( (string) ( $r['webhookSecret'] ?? '' ) );
	$binding_id = cinatra_sanitize_webhook_binding_id( (string) ( $r['webhookBindingId'] ?? '' ) );
	if ( CINATRA_WEBHOOK_CONTRACT === $contract && '' !== $secret && '' !== $binding_id ) {
		update_option( 'cinatra_webhook_secret', $secret );
		update_option( 'cinatra_webhook_binding_id', $binding_id );
	} elseif ( CINATRA_WEBHOOK_CONTRACT !== $contract ) {
		cinatra_clear_webhook_pair();
	}
	// else: echo present, pair omitted — keep whatever pair survives (same
	// instance keeps its working pair; a changed instance was already cleared
	// by the option hooks).
	cinatra_set_connect_result( 'success', __( 'Connected to Cinatra. The integration credential is stored on this server.', 'cinatra' ) );
}

/**
 * Delete the paired publish-webhook credentials (secret + server-issued
 * binding id). The two options are only ever written together by
 * cinatra_connect_apply_result(), so they are only ever cleared together.
 *
 * @return void
 */
function cinatra_clear_webhook_pair(): void {
	delete_option( 'cinatra_webhook_secret' );
	delete_option( 'cinatra_webhook_binding_id' );
}

/**
 * Whether the paired publish-webhook credentials are configured.
 *
 * @return bool True when BOTH the signing secret and the binding id are stored.
 */
function cinatra_webhook_pair_configured(): bool {
	return '' !== (string) get_option( 'cinatra_webhook_secret', '' )
		&& '' !== (string) get_option( 'cinatra_webhook_binding_id', '' );
}

/**
 * Whether the settings screen should prompt for a reconnect: the site is
 * connected and has a publish-webhook subscription, but no server-issued
 * webhook pair (e.g. the plugin was updated before its Cinatra instance, or
 * the connection predates the pair). Publish events are NOT delivered in this
 * state (there is deliberately no legacy fallback).
 *
 * @return bool True when a reconnect would (re)enable publish webhooks.
 */
function cinatra_webhook_reconnect_needed(): bool {
	if ( cinatra_webhook_pair_configured() ) {
		return false;
	}
	if ( '' === (string) get_option( 'cinatra_url', '' ) || '' === (string) get_option( 'cinatra_api_key', '' ) ) {
		return false;
	}
	foreach ( cinatra_get_webhook_subscriptions() as $subscription ) {
		if ( 'post_published' === ( $subscription['event_type'] ?? '' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Sanitize a server-issued webhook binding id: an opaque URL-safe token (the
 * host mints base64url). Anything outside [A-Za-z0-9_-]{1,128} collapses to ''
 * so the value can never smuggle a path segment into the webhook URL.
 *
 * @param string $value Raw binding id from the exchange response.
 * @return string The validated binding id, or '' if rejected.
 */
function cinatra_sanitize_webhook_binding_id( string $value ): string {
	$value = trim( $value );
	return preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $value ) ? $value : '';
}

// ---------------------------------------------------------------------------
// Clear the webhook pair the moment the connected-instance IDENTITY changes
// (cinatra#974, mirroring the drupal-module): a binding id minted by one
// instance must never be targeted at another — the emitter would send signed
// webhook material for the OLD instance to the NEW origin. WordPress fires
// update_option_{option} only on a REAL value change and add_option_{option}
// when the option is (re)created, so together these cover the connect-flow
// overwrite, a manual settings edit of the URL / instance id, and a
// delete-then-re-add. Ordering inside cinatra_connect_apply_result() is safe:
// the identity options are written BEFORE the pair, so a cross-instance
// reconnect clears the old pair here and then stores the fresh one.
// (delete_option is intentionally not hooked: with no identity there is no
// emission target, and any later re-add lands on add_option_{option}.)
// ---------------------------------------------------------------------------

/**
 * Clear the pair on a real identity change — the
 * update_option_{cinatra_url|cinatra_instance_id} callback.
 *
 * @param mixed $old_value Previous option value.
 * @param mixed $value     New option value.
 * @return void
 */
function cinatra_on_instance_identity_changed( $old_value, $value ): void {
	if ( (string) $old_value === (string) $value ) {
		return; // update_option only fires on change; belt-and-braces.
	}
	cinatra_clear_webhook_pair();
}

/**
 * Clear any stored pair when an identity option is (re)created — the
 * add_option_{cinatra_url|cinatra_instance_id} callback (a re-added identity
 * is always a new identity).
 *
 * @param string $option Option name (unused; WP passes it first).
 * @param mixed  $value  New option value (unused).
 * @return void
 */
function cinatra_on_instance_identity_added( $option, $value ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required add_option_{option} callback signature.
	cinatra_clear_webhook_pair();
}

add_action( 'update_option_cinatra_url', 'cinatra_on_instance_identity_changed', 10, 2 );
add_action( 'update_option_cinatra_instance_id', 'cinatra_on_instance_identity_changed', 10, 2 );
add_action( 'add_option_cinatra_url', 'cinatra_on_instance_identity_added', 10, 2 );
add_action( 'add_option_cinatra_instance_id', 'cinatra_on_instance_identity_added', 10, 2 );

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
			'contractVersion'   => CINATRA_CONTRACT_VERSION,
			'cinatraUrl'        => rtrim( $url, '/' ),
			// No apiKey. The browser obtains a short-lived token from this endpoint.
			'tokenEndpoint'     => rest_url( 'cinatra/v1/token' ),
			// Required-login (cinatra#410): same-origin broker relays for the
			// per-user PKCE handshake. The long-lived cnx_ key stays server-side;
			// these routes present it server-to-server to /api/widget-auth/*.
			'authInitEndpoint'  => rest_url( 'cinatra/v1/widget-auth/init' ),
			'authTokenEndpoint' => rest_url( 'cinatra/v1/widget-auth/token' ),
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'instanceId'        => $instance_id,
			'wpAdminUrl'        => admin_url(),
			// Feature-gate for the AI-tools path. True when the WordPress MCP
			// Adapter (WordPress/mcp-adapter) is installed and active, giving the
			// assistant access to WordPress content via MCP. False when the adapter
			// is absent: the base chat widget still loads, but the widget should
			// surface a clear "install the adapter to enable tools" state instead
			// of a silent absence. Implements wordpress-plugin#62.
			'mcpAdapterActive'  => cinatra_mcp_adapter_active(),
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

			// Required-login (cinatra#410): per-user PKCE handshake relays. The
			// browser POSTs here (same-origin, wp_rest nonce, manage_options); the
			// PHP backend presents the long-lived cnx_ key server-to-server to the
			// instance widget-auth endpoints and returns ONLY the upstream envelope
			// (no key, no internals). Mirrors /token above.
			register_rest_route(
				'cinatra/v1',
				'/widget-auth/init',
				array(
					'methods'             => 'POST',
					'callback'            => 'cinatra_rest_widget_auth_init',
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				)
			);

			register_rest_route(
				'cinatra/v1',
				'/widget-auth/token',
				array(
					'methods'             => 'POST',
					'callback'            => 'cinatra_rest_widget_auth_token',
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
				// Assert THIS site's origin on the server-to-server mint. The
				// instance's cnx_ arm on /api/agents/{slug}/token enforces a
				// paired Origin === the credential's bound connect-site origin
				// and FAILS CLOSED on a missing Origin — without this header
				// every cnx_-paired site gets a 401 on the cit_ mint. Same
				// identity assertion the widget-auth relays already send (the
				// credential hash must also match the same connect-site row, so
				// this grants no trust). $origin is validated non-empty above.
				'Origin'        => $origin,
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
 * Remove the long-lived integration key from a string before it is logged.
 *
 * The widget-auth relays carry the per-user code/codeVerifier outbound and the
 * long-lived key in the Authorization header; a buggy/proxy/debug upstream could
 * echo any of those into an error string. We cannot enumerate every transient
 * secret, but the durable, highest-value one — the long-lived key — is redacted
 * here so it can never land in a WordPress log. Mirrors the Drupal broker's
 * scrub(). A blank key returns the text unchanged.
 *
 * @param string $text    The text about to be logged.
 * @param string $api_key The long-lived integration key to redact.
 * @return string The text with the key replaced by [redacted].
 */
function cinatra_scrub_secret( string $text, string $api_key ): string {
	if ( '' === $api_key ) {
		return $text;
	}
	return str_replace( $api_key, '[redacted]', $text );
}

/**
 * Shared server-to-server relay for the per-user widget-auth handshake
 * (cinatra#410). Validates the wp_rest nonce, presents the long-lived cnx_ key
 * server-to-server to the instance's /api/widget-auth/{init,token} endpoint with
 * the caller-whitelisted JSON, and returns ONLY the whitelisted upstream
 * envelope to the browser. The long-lived key never reaches JS, and upstream
 * error bodies are never reflected (generic message only). Mirrors the
 * cinatra_rest_mint_token transport.
 *
 * @param WP_REST_Request $request The incoming REST request (wp_rest nonce + JSON params).
 * @param string          $segment The upstream path segment ('init' or 'token').
 * @param array           $fields  Whitelisted request-field names to forward.
 * @param array           $passthrough Whitelisted response-field names to return.
 * @return WP_REST_Response The upstream envelope (whitelisted), or an error response.
 */
function cinatra_rest_widget_auth_relay( WP_REST_Request $request, string $segment, array $fields, array $passthrough ): WP_REST_Response {
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

	// Forward ONLY whitelisted JSON fields the widget is allowed to set. The
	// instance derives the rest (txn binding, the agent's instances config key,
	// the user identity from the authenticated login). We never forward arbitrary
	// keys, and never echo the long-lived key.
	$params  = $request->get_json_params();
	$forward = array();
	if ( is_array( $params ) ) {
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $params ) && null !== $params[ $field ] ) {
				$forward[ $field ] = $params[ $field ];
			}
		}
	}

	// Route the TRANSPORT to the container-reachable base when CINATRA_BASE_URL is
	// set (dev/container topology), else the configured cinatra_url (production).
	$server_base = cinatra_server_base_url( $url );
	$endpoint    = $server_base . '/api/widget-auth/' . $segment;

	// Assert THIS site's own origin on the server-to-server relay. The instance's
	// /api/widget-auth/{init,token} enforces a paired Origin === the `cnx_`
	// credential's bound connect-site origin (fail-closed: a missing Origin is
	// rejected). We derive the origin from admin_url() — the SAME source the
	// connect handshake used to register this site's `widget_origin` (see the
	// connect-start payload) — so a split front-end/admin origin install still
	// asserts the registered origin and matches. The relay cannot spoof another
	// site because the credential_hash must ALSO match the same connect-site row,
	// so this header is identity assertion, not a trust grant. The browser never
	// reaches this endpoint (server-to-server only).
	$site_origin   = cinatra_site_origin( admin_url() );
	$relay_headers = array(
		'Authorization' => 'Bearer ' . $api_key,
		'Content-Type'  => 'application/json',
		'Accept'        => 'application/json',
	);
	if ( '' !== $site_origin ) {
		$relay_headers['Origin'] = $site_origin;
	}

	$response = cinatra_server_post(
		$endpoint,
		array(
			'timeout' => 10,
			'headers' => $relay_headers,
			'body'    => wp_json_encode( $forward ),
		)
	);

	if ( is_wp_error( $response ) ) {
		// Log the transport detail server-side; return a generic message so we
		// never reflect low-level/internal error text to the browser. SCRUB the
		// long-lived key first: a buggy/proxy upstream error could echo the
		// outgoing Authorization: Bearer cnx_... back into the message, and these
		// widget-auth relays carry the per-user code/codeVerifier, so the key must
		// never reach WP logs.
		error_log( '[cinatra] widget-auth ' . $segment . ' endpoint unreachable: ' . cinatra_scrub_secret( $response->get_error_message(), $api_key ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional server-side error logging; the long-lived key is scrubbed and the detail is never reflected to the browser.
		return new WP_REST_Response(
			array( 'error' => __( 'Could not reach the Cinatra instance. Check the connector URL, or contact your administrator.', 'cinatra' ) ),
			502
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$raw    = (string) wp_remote_retrieve_body( $response );
	$json   = json_decode( $raw, true );

	if ( $status < 200 || $status >= 300 || ! is_array( $json ) ) {
		// Never reflect the upstream body to the browser. Log server-side (with
		// the long-lived key scrubbed); return a generic, actionable message.
		// Always 502 from the browser's view.
		$detail = ( is_array( $json ) && ! empty( $json['error'] ) ) ? (string) $json['error'] : substr( $raw, 0, 500 );
		error_log( '[cinatra] widget-auth ' . $segment . ' failed (HTTP ' . $status . '): ' . cinatra_scrub_secret( $detail, $api_key ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional server-side error logging; the long-lived key is scrubbed and the detail is never reflected to the browser.
		return new WP_REST_Response(
			array( 'error' => __( 'Cinatra could not complete sign-in. Check the connector settings, or contact your administrator.', 'cinatra' ) ),
			502
		);
	}

	// Return ONLY the whitelisted upstream fields to the browser.
	$out = array();
	foreach ( $passthrough as $key ) {
		if ( array_key_exists( $key, $json ) ) {
			$out[ $key ] = $json[ $key ];
		}
	}
	$resp = new WP_REST_Response( $out, 200 );
	// The redeem response carries the opaque per-user token; never cache it.
	$resp->header( 'Cache-Control', 'no-store, private' );
	return $resp;
}

/**
 * REST: start the per-user widget-auth PKCE handshake (cinatra#410).
 *
 * Forwards the PKCE challenge + state to /api/widget-auth/init server-to-server
 * (presenting the long-lived cnx_ key) and returns the {txnId, authorizeUrl,
 * instanceId} envelope. The browser opens authorizeUrl as the hosted login
 * popup; raw credentials never touch this CMS DOM.
 *
 * @param WP_REST_Request $request The incoming REST request.
 * @return WP_REST_Response The init envelope, or an error response.
 */
function cinatra_rest_widget_auth_init( WP_REST_Request $request ): WP_REST_Response {
	return cinatra_rest_widget_auth_relay(
		$request,
		'init',
		array( 'client', 'agentSlug', 'codeChallenge', 'codeChallengeMethod', 'state', 'instanceId' ),
		array( 'txnId', 'authorizeUrl', 'instanceId' )
	);
}

/**
 * REST: redeem the authorization code for the opaque per-user token (cinatra#410).
 *
 * Forwards {grantType, client, agentSlug, code, codeVerifier} to
 * /api/widget-auth/token server-to-server (presenting the long-lived cnx_ key)
 * and returns the {token: cwu_..., tokenType, expiresIn, scope} envelope. The
 * browser sends that token on the dual-token stream (cinatra#408).
 *
 * @param WP_REST_Request $request The incoming REST request.
 * @return WP_REST_Response The token envelope, or an error response.
 */
function cinatra_rest_widget_auth_token( WP_REST_Request $request ): WP_REST_Response {
	return cinatra_rest_widget_auth_relay(
		$request,
		'token',
		array( 'grantType', 'client', 'agentSlug', 'code', 'codeVerifier' ),
		array( 'token', 'tokenType', 'expiresIn', 'scope' )
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

// ---------------------------------------------------------------------------
// Publish emitter (wp#48). On a post transitioning INTO 'publish' this fires a
// signed server-to-server webhook to the connected Cinatra instance so the
// agent can react to newly-published content.
//
// WIRE CONTRACT — pinned to the cinatra GENERIC inbound-webhook facility
// (cinatra#340/#974: src/app/webhook/[vendor]/[slug]/[hook]/[bindingId] +
// packages/webhooks verifyInbound; the drupal-module emitter is the sibling):
// - TARGET   : {cinatra_url}/webhook/cinatra-ai/wordpress-mcp-connector/
// post-published/{binding_id} — the host-owned generic route. The binding id
// is SERVER-ISSUED, returned by the connect token exchange PAIRED with the
// signing secret (see cinatra_connect_apply_result()), and carries the
// connected-site identity on the cinatra side. The transport base is resolved
// through cinatra_server_base_url() and the POST goes via the SSRF-safe
// cinatra_server_post(); the plugin never posts to an operator-entered
// target_url (that would be an SSRF surface). There is NO legacy fallback:
// without a stored pair the emitter is a quiet no-op and the settings screen
// prompts for a reconnect (owner ruling on cinatra#974 — the superseded
// /api/webhooks/wordpress vendor route is being retired).
// - SIGNING  : Standard-Webhooks. The stored `whsec_` secret is base64-decoded
// (after the prefix strip) into the HMAC key; the signed content is
// "{webhook-id}.{webhook-timestamp}.{body}" and the header set is
// webhook-id / webhook-timestamp / webhook-signature
// ("v1,<base64(hmac-sha256)>"). The body is JSON-encoded ONCE and the same
// exact bytes are signed and sent. The signature math is pinned by a golden
// vector generated with the reference standardwebhooks JS library (the exact
// library the host verifies with) — see tests/test-publish-emitter.php.
// - PAYLOAD  : the exact strict schema the wordpress-mcp-connector handler
// re-validates — { event:"post_published", postId:int>0, postType:string,
// title:string, url?:string, siteUrl:string, issuedAt:string } (unchanged
// from the legacy route; only transport + signing moved).
// - IDEMPOTENCY: webhook-id is STABLE per publish event (derived from the
// site instance id + post id + post_modified_gmt) so a retried delivery
// carries the same id and the host's idempotency ledger dedupes.
//
// SAFETY: emission is fire-and-forget — it NEVER blocks or fails a publish, only
// ever posts to the operator-configured cinatra_url via the SSRF-safe helper
// (no arbitrary host), and logs only fixed text + an HTTP status on failure
// (never the secret, signature, raw body, title, or any upstream response body).
// ---------------------------------------------------------------------------

// The generic-route path for the WordPress post-published hook. The trailing
// segment is the server-issued binding id, appended at build time. Path
// segments before it are the connector package's vendor/slug and the declared
// hook id (the wordpress-mcp-connector cinatra.webhooks declaration).
const CINATRA_WEBHOOK_HOOK_PATH = '/webhook/cinatra-ai/wordpress-mcp-connector/post-published/';

// Standard-Webhooks secret prefix, stripped before base64-decoding the key.
const CINATRA_WHSEC_PREFIX = 'whsec_';

/**
 * Build the publish-webhook endpoint URL for the configured Cinatra instance.
 *
 * Resolves the transport base through cinatra_server_base_url() (so a validated
 * CINATRA_BASE_URL container override redirects the TRANSPORT in dev, while
 * production uses the configured cinatra_url unchanged) and appends the
 * generic-route path + the server-issued binding id. Returns '' when the
 * instance URL or the binding id is not configured — there is deliberately NO
 * legacy-route fallback.
 *
 * @return string The full endpoint URL, or '' when not fully configured.
 */
function cinatra_publish_webhook_endpoint(): string {
	$base = rtrim( (string) get_option( 'cinatra_url', '' ), '/' );
	if ( '' === $base ) {
		return '';
	}
	$binding_id = cinatra_sanitize_webhook_binding_id( (string) get_option( 'cinatra_webhook_binding_id', '' ) );
	if ( '' === $binding_id ) {
		return '';
	}
	$transport = cinatra_server_base_url( $base );
	return rtrim( $transport, '/' ) . CINATRA_WEBHOOK_HOOK_PATH . rawurlencode( $binding_id );
}

/**
 * Compute the Standard-Webhooks v1 signature header value.
 *
 * The key is the base64-decoded secret (after the optional whsec_ prefix
 * strip); the signed content is "{id}.{timestamp}.{body}"; the header value is
 * "v1," followed by the base64 of the raw HMAC-SHA256. This matches the
 * standardwebhooks reference libraries byte-for-byte (the cinatra host
 * verifies with exactly that library); the byte-equivalence is pinned by a
 * golden vector generated with the reference JS library.
 *
 * @param string $secret     The stored webhook secret (whsec_-prefixed base64).
 * @param string $message_id The webhook-id header value.
 * @param int    $timestamp  Seconds since epoch (the webhook-timestamp header value).
 * @param string $body       The exact request body bytes.
 * @return string|null The "v1,<base64>" signature, or null when the secret does not decode.
 */
function cinatra_webhook_sign( string $secret, string $message_id, int $timestamp, string $body ): ?string {
	$encoded = $secret;
	if ( 0 === strpos( $encoded, CINATRA_WHSEC_PREFIX ) ) {
		$encoded = substr( $encoded, strlen( CINATRA_WHSEC_PREFIX ) );
	}
	$key = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Standard-Webhooks keys are base64-encoded by spec; this derives the HMAC key, not code.
	if ( ! is_string( $key ) || '' === $key ) {
		return null;
	}
	$content = $message_id . '.' . $timestamp . '.' . $body;
	return 'v1,' . base64_encode( hash_hmac( 'sha256', $content, $key, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard-Webhooks signatures are base64-encoded raw HMAC bytes by spec; nothing is obfuscated.
}

/**
 * Whether a stored webhook subscription enables 'post_published' emission for
 * the given post.
 *
 * Returns true iff a subscription row has event_type === 'post_published' AND
 * its post_types filter is either empty (all post types) or includes the post's
 * type. The subscription registry stays the enable + post-type filter (its
 * existing purpose); the network destination is ALWAYS the fixed simple route,
 * so the operator-entered (free-form) target_url is intentionally NOT matched
 * here — normalizing it against the host route would be brittle and could
 * silently drop legitimate publishes.
 *
 * @param WP_Post $post The post being published.
 * @return bool True when at least one matching subscription enables emission.
 */
function cinatra_publish_emit_enabled_for_post( WP_Post $post ): bool {
	$post_type = (string) $post->post_type;
	foreach ( cinatra_get_webhook_subscriptions() as $subscription ) {
		if ( 'post_published' !== ( $subscription['event_type'] ?? '' ) ) {
			continue;
		}
		$post_types = (array) ( $subscription['post_types'] ?? array() );
		if ( array() === $post_types || in_array( $post_type, $post_types, true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a value is a well-formed ABSOLUTE http(s) URL with a host.
 *
 * Used to gate the optional `url` payload field so it satisfies the host
 * schema's Zod .url() (which rejects relative URLs); a permalink that is not a
 * clean absolute URL is omitted rather than sent.
 *
 * @param string $url Candidate URL.
 * @return bool True when $url is an absolute http/https URL with a host.
 */
function cinatra_is_absolute_http_url( string $url ): bool {
	if ( '' === $url ) {
		return false;
	}
	$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
	$host   = wp_parse_url( $url, PHP_URL_HOST );
	return ( 'http' === $scheme || 'https' === $scheme )
		&& is_string( $host ) && '' !== $host;
}

/**
 * Build the strict publish-webhook payload for a post (exactly the host schema).
 *
 * @param WP_Post $post The published post.
 * @return array The payload array: event, postId, postType, title, url, siteUrl, issuedAt.
 */
function cinatra_build_publish_payload( WP_Post $post ): array {
	$payload = array(
		'event'    => 'post_published',
		'postId'   => (int) $post->ID,
		'postType' => (string) $post->post_type,
		'title'    => (string) get_the_title( $post ),
		'siteUrl'  => home_url(),
		'issuedAt' => gmdate( 'c' ),
	);
	// The host schema validates `url` as an ABSOLUTE URL (Zod .url()). Include it
	// ONLY when get_permalink() yields a well-formed absolute http(s) URL; a
	// relative/custom-filtered permalink would otherwise fail the host's strict
	// parse and reject the whole payload. `url` is optional, so omit it instead.
	$permalink = get_permalink( $post );
	if ( is_string( $permalink ) && cinatra_is_absolute_http_url( $permalink ) ) {
		$payload['url'] = $permalink;
	}
	return $payload;
}

/**
 * Build the STABLE per-publish-event idempotency id.
 *
 * Stable across retries of the SAME publish event (so the host can dedupe a
 * re-delivered webhook): derived from the site instance id, the post id, and
 * the post's last-modified GMT timestamp. NOT random per send.
 *
 * @param WP_Post $post The published post.
 * @return string The webhook id (e.g. "wp-<instance>-<postId>-<modified-epoch>").
 */
function cinatra_publish_webhook_id( WP_Post $post ): string {
	$instance_id = (string) get_option( 'cinatra_instance_id', '' );
	if ( '' === $instance_id ) {
		// Fall back to the site home so the id is still stable + site-scoped when
		// no instance id is configured.
		$instance_id = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}
	// Stable revision component from the post's last-modified GMT. Prefer the
	// parsed epoch; if the stored timestamp is unparseable (strtotime false),
	// fall back to a sanitized form of the raw value so the id is still stable
	// and NEVER ends in an empty suffix.
	$modified = (string) $post->post_modified_gmt;
	$epoch    = strtotime( $modified . ' UTC' );
	if ( false !== $epoch ) {
		$revision = (string) $epoch;
	} else {
		$revision = preg_replace( '/[^0-9A-Za-z]+/', '', $modified );
		if ( '' === (string) $revision ) {
			$revision = '0';
		}
	}
	return 'wp-' . $instance_id . '-' . (int) $post->ID . '-' . $revision;
}

/**
 * Emit a signed 'post_published' webhook when a post transitions INTO publish.
 *
 * Bound to transition_post_status. Fire-and-forget and fully non-fatal: every
 * bail is quiet and nothing here can block or fail the publish itself.
 *
 * @param string  $new_status The new post status.
 * @param string  $old_status The previous post status.
 * @param WP_Post $post       The post object (transition_post_status passes it third).
 * @return void
 */
function cinatra_emit_post_published( $new_status, $old_status, $post ): void {
	// Only a genuine transition INTO publish (skip publish->publish edits and
	// any non-publish status change).
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}
	if ( ! $post instanceof WP_Post ) {
		return;
	}
	// Never fire for revisions / autosaves.
	if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
		return;
	}
	// Attachments are a public post type but represent media, not editorial
	// content — never emit for them even if a subscription matches all types.
	if ( 'attachment' === $post->post_type ) {
		return;
	}
	// Only public post types (skip revisions and internal/non-public types).
	$type_object = get_post_type_object( $post->post_type );
	if ( null === $type_object || empty( $type_object->public ) ) {
		return;
	}

	// Quiet bails: no instance configured, no server-issued webhook pair
	// (endpoint requires the binding id; the secret is its pair — a partial
	// configuration means "webhooks not provisioned": the settings screen
	// prompts for a reconnect, and there is deliberately NO legacy fallback),
	// or no subscription enabling this post's type.
	$endpoint = cinatra_publish_webhook_endpoint();
	$secret   = (string) get_option( 'cinatra_webhook_secret', '' );
	if ( '' === $endpoint || '' === $secret ) {
		return;
	}
	if ( ! cinatra_publish_emit_enabled_for_post( $post ) ) {
		return;
	}

	// Encode the body ONCE; sign and send the SAME exact bytes. Bail quietly if
	// encoding fails (an empty signed body would only be rejected by the host).
	$raw_body = wp_json_encode( cinatra_build_publish_payload( $post ) );
	if ( ! is_string( $raw_body ) || '' === $raw_body ) {
		return;
	}
	$message_id = cinatra_publish_webhook_id( $post );
	$timestamp  = time();
	$signature  = cinatra_webhook_sign( $secret, $message_id, $timestamp, $raw_body );
	if ( null === $signature ) {
		// A malformed stored secret (not base64) — fail CLOSED with fixed text,
		// never the value, and no HTTP request.
		error_log( '[cinatra] publish webhook skipped: the stored webhook secret is not a valid Standard-Webhooks secret.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional fixed-text server-side warning; never logs the secret value.
		return;
	}

	// Blocking with a short timeout. We deliberately keep blocking => true: a
	// non-blocking request can drop the request BODY in some WordPress HTTP
	// transports, which would invalidate the signed delivery. The short timeout
	// caps the worst-case added publish latency when the instance is slow/down.
	// Delivery is best-effort — a failure is logged (fixed text) and NEVER blocks
	// or fails the publish. Durable retry/queueing is intentionally out of scope
	// here; the STABLE webhook-id is what lets the host's idempotency ledger
	// dedupe a future re-delivery if a retry path is added later.
	$response = cinatra_server_post(
		$endpoint,
		array(
			'timeout'  => 4,
			'blocking' => true,
			'headers'  => array(
				'Content-Type'      => 'application/json',
				'Accept'            => 'application/json',
				'webhook-id'        => $message_id,
				'webhook-timestamp' => (string) $timestamp,
				'webhook-signature' => $signature,
			),
			'body'     => $raw_body,
		)
	);

	if ( is_wp_error( $response ) ) {
		// Fixed text only — never the secret, signature, body, title, or upstream
		// detail.
		error_log( '[cinatra] publish webhook transport failed.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional fixed-text server-side warning; never logs any secret, signature, payload, or upstream detail.
		return;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		error_log( '[cinatra] publish webhook rejected: HTTP ' . $code ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional fixed-text + status-only server-side warning; never logs any secret, signature, payload, or upstream body.
	}
}
add_action( 'transition_post_status', 'cinatra_emit_post_published', 10, 3 );

