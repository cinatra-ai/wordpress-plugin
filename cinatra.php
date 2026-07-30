<?php
/**
 * Plugin Name: Cinatra
 * Plugin URI: https://cinatra.ai
 * Description: Embeds the Cinatra AI assistant chat widget in WordPress admin. Floating button bottom-right; opens chat panel on click.
 * Version: 0.1.6
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
define( 'CINATRA_PLUGIN_VERSION', '0.1.6' );
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
		<?php cinatra_render_installer_actions(); ?>

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

// ---------------------------------------------------------------------------
// Ensure-panel detection (cinatra-ai/cinatra#2021 S6 / epsilon). Everything in
// this section is READ-ONLY: it reports the site's WP/PHP/HTTPS floor state,
// the install state (absent / installed-but-inactive / active) + version of
// both AI-tools companion plugins, and the browsing user's role as a
// courtesy. No file is written and no plugin is installed or activated from
// this section — that is a separate, human-gated PR (wordpress-plugin S6 /
// zeta), additionally structurally gated by this repo's CODEOWNERS review
// requirement on this file (#99).
// ---------------------------------------------------------------------------

// The Abilities API ships in WordPress 6.9; both AI-tools companion plugins
// assume it. PHP 8.0 mirrors the floor the companion plugins themselves
// declare.
define( 'CINATRA_ENSURE_MIN_WP_VERSION', '6.9' );
define( 'CINATRA_ENSURE_MIN_PHP_VERSION', '8.0' );

// The wordpress.org catalog plugin ("Enable Abilities for MCP") complements
// the GitHub-distributed MCP Adapter: it registers a broad set of core
// content abilities and lets the site admin toggle each on/off. Plugin file
// verified against the plugin's own wordpress.org SVN trunk listing.
define( 'CINATRA_CATALOG_PLUGIN_FILE', 'enable-abilities-for-mcp/enable-abilities-for-mcp.php' );
define( 'CINATRA_CATALOG_PLUGIN_URL', 'https://wordpress.org/plugins/enable-abilities-for-mcp/' );

/**
 * WordPress-core-version floor check for the ensure panel.
 *
 * @return bool True when the running WordPress version meets the floor.
 */
function cinatra_ensure_wp_version_ok(): bool {
	return version_compare( get_bloginfo( 'version' ), CINATRA_ENSURE_MIN_WP_VERSION, '>=' );
}

/**
 * PHP-version floor check for the ensure panel.
 *
 * @return bool True when the running PHP version meets the floor.
 */
function cinatra_ensure_php_version_ok(): bool {
	return version_compare( PHP_VERSION, CINATRA_ENSURE_MIN_PHP_VERSION, '>=' );
}

/**
 * HTTPS floor check for the ensure panel. wp-admin is always loaded over the
 * site's actually-configured scheme, so is_ssl() at settings-page load time
 * reflects the site's HTTPS posture, not merely "was this one visit HTTPS".
 *
 * @return bool True when the current (representative) request is HTTPS.
 */
function cinatra_ensure_https_ok(): bool {
	return is_ssl();
}

/**
 * Full install-state read for a plugin file: present-but-inactive vs. active
 * vs. absent, plus the version string from the plugin header. Works WITHOUT
 * requiring the plugin to be active — get_plugins() reads the file header
 * directly — which is the detection primitive is_plugin_active() alone
 * cannot provide: that function collapses "not installed" and "installed,
 * not active" to the same false (cinatra-ai/cinatra#2021 D2).
 *
 * Fails safe: any environment where get_plugins()/is_plugin_active() cannot
 * be loaded reports "absent" rather than fataling — absence is always a
 * truthful, non-fatal answer here, never a crash.
 *
 * @param string $plugin_file Plugin file relative to the plugins directory,
 *                             e.g. 'mcp-adapter/mcp-adapter.php'.
 * @return array<string,mixed> { present: bool, active: bool, version: string }.
 *               present=false implies active=false and version=''.
 */
function cinatra_ensure_plugin_state( string $plugin_file ): array {
	$absent = array(
		'present' => false,
		'active'  => false,
		'version' => '',
	);
	if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
		if ( ! defined( 'ABSPATH' ) || ! is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			return $absent;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
		return $absent;
	}
	$all_plugins = get_plugins();
	if ( ! is_array( $all_plugins ) || ! isset( $all_plugins[ $plugin_file ] ) ) {
		return $absent;
	}
	return array(
		'present' => true,
		'active'  => (bool) is_plugin_active( $plugin_file ),
		'version' => (string) ( $all_plugins[ $plugin_file ]['Version'] ?? '' ),
	);
}

/**
 * The signed-in user's own role, for the ensure panel's courtesy row. This is
 * the BROWSING admin's role — it is NOT the audited "which role owns the
 * site's stored Application-Password credential" signal. That durable signal
 * is cinatra-ai/cinatra#2021's D6/D8 tri-state warning, computed server-side
 * from the site-inventory handshake and rendered on the connector settings
 * page; this row is a same-page convenience only.
 *
 * @return string The current user's primary role slug, or '' if none.
 */
function cinatra_ensure_current_user_role(): string {
	$user = wp_get_current_user();
	if ( ! ( $user instanceof WP_User ) || empty( $user->roles ) || ! is_array( $user->roles ) ) {
		return '';
	}
	$role = reset( $user->roles );
	return is_string( $role ) ? $role : '';
}

/**
 * Render one ensure-panel <li> row with a pass/pending status icon, matching
 * the pre-epsilon single-row markup byte-for-byte (no CSS/JS elsewhere needs
 * to change).
 *
 * @param bool   $ok      True renders the check-mark/"ok" state, false the
 *                        pending-dot state.
 * @param string $message Fully-escaped message HTML for this row (callers
 *                        build it via esc_html()/esc_html__()/sprintf() with
 *                        esc_html()/esc_url()-wrapped dynamic parts, same
 *                        convention as the rest of this file); rendered
 *                        through wp_kses_post() so an allow-listed <a>/<br>
 *                        fragment (e.g. an activation link) may pass through.
 * @return void
 */
function cinatra_render_checklist_row( bool $ok, string $message ): void {
	$icon  = $ok ? '&#10003;' : '&#9679;'; // Unicode U+2713 CHECK MARK / U+25CF BLACK CIRCLE.
	$class = $ok ? 'cinatra-check-ok' : 'cinatra-check-pending';
	?>
	<li class="<?php echo esc_attr( $class ); ?>" style="margin-bottom:8px;">
		<span aria-hidden="true" style="margin-right:6px;"><?php echo wp_kses_post( $icon ); ?></span>
		<?php echo wp_kses_post( $message ); ?>
	</li>
	<?php
}

/**
 * Render the ensure-panel checklist row for one AI-tools companion plugin:
 * active (ok), installed-but-inactive (pending, links to the Installed
 * Plugins screen so the admin can activate it), or absent (pending, links to
 * the manual download/info source). No install action happens here — see the
 * docblock on cinatra_render_setup_checklist().
 *
 * @param array<string,mixed> $state     From cinatra_ensure_plugin_state():
 *                                        { present: bool, active: bool, version: string }.
 * @param string              $name      Human-readable plugin name (already
 *                                        translated by the caller).
 * @param string              $link_url  Manual-install/info URL.
 * @param string              $link_text Link text for the manual-install/info
 *                                        URL (already translated by the caller).
 * @return void
 */
function cinatra_render_plugin_checklist_row( array $state, string $name, string $link_url, string $link_text ): void {
	// Version numbers are not translated; kept as plain, escaped text.
	$version_suffix = '' !== $state['version'] ? ' (v' . esc_html( $state['version'] ) . ')' : '';

	if ( $state['active'] ) {
		cinatra_render_checklist_row(
			true,
			sprintf(
				/* translators: 1: plugin name, 2: parenthesized version suffix shown when the installed version is known (may be empty) */
				esc_html__( '%1$s%2$s is active.', 'cinatra' ),
				esc_html( $name ),
				$version_suffix
			)
		);
		return;
	}

	if ( $state['present'] ) {
		$activate_link = sprintf(
			'<br /><a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Activate it on the Installed Plugins screen', 'cinatra' )
		);
		cinatra_render_checklist_row(
			false,
			sprintf(
				/* translators: 1: plugin name, 2: parenthesized version suffix shown when the installed version is known (may be empty), 3: "Activate it..." link HTML */
				esc_html__( '%1$s%2$s is installed but not active. %3$s', 'cinatra' ),
				esc_html( $name ),
				$version_suffix,
				$activate_link
			)
		);
		return;
	}

	$download_link = sprintf(
		'<br /><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
		esc_url( $link_url ),
		esc_html( $link_text )
	);
	cinatra_render_checklist_row(
		false,
		sprintf(
			/* translators: 1: plugin name, 2: download/info link HTML */
			esc_html__( '%1$s is not installed. %2$s', 'cinatra' ),
			esc_html( $name ),
			$download_link
		)
	);
}

/**
 * Render the "Site AI stack" card on the settings page (formerly the "Setup
 * checklist" / "AI tools setup" card — cinatra-ai/cinatra#2021 S6 upgrades
 * this card in place rather than adding a new page). Surfaces:
 *   - the WP/PHP/HTTPS floor the AI-tools companion plugins require;
 *   - install state (absent / installed-but-inactive / active) + version for
 *     both companion plugins (the WordPress MCP Adapter and the
 *     wordpress.org catalog plugin "Enable Abilities for MCP");
 *   - the signed-in user's role, as a courtesy (see
 *     cinatra_ensure_current_user_role() for the durable/audited version of
 *     this concern).
 *
 * Detection-only (cinatra-ai/cinatra#2021 S6 / epsilon): no plugin is
 * installed, activated, or written to from this function. The one-click
 * installer is a separate, human-gated PR (S6 / zeta) — see CODEOWNERS.
 *
 * The card is always rendered (even when everything is configured) so admins
 * can see at a glance which capabilities are enabled.
 *
 * @return void
 */
function cinatra_render_setup_checklist(): void {
	$wp_ok    = cinatra_ensure_wp_version_ok();
	$php_ok   = cinatra_ensure_php_version_ok();
	$https_ok = cinatra_ensure_https_ok();

	$mcp_state     = cinatra_ensure_plugin_state( CINATRA_MCP_ADAPTER_PLUGIN_FILE );
	$catalog_state = cinatra_ensure_plugin_state( CINATRA_CATALOG_PLUGIN_FILE );

	$current_role = cinatra_ensure_current_user_role();
	?>
	<div class="card" style="max-width:680px;margin-top:24px;">
		<h2 style="margin-top:0;"><?php echo esc_html__( 'Site AI stack', 'cinatra' ); ?></h2>
		<p class="description">
			<?php echo esc_html__( 'The Cinatra assistant works without the items below, but installing them unlocks WordPress AI tools — letting the assistant read and edit your site content directly from the chat.', 'cinatra' ); ?>
		</p>
		<ul style="list-style:none;margin:0;padding:0;">
			<?php
			cinatra_render_checklist_row(
				$wp_ok,
				$wp_ok
					? esc_html__( 'WordPress version meets the requirement for AI tools.', 'cinatra' )
					: sprintf(
						/* translators: 1: minimum required WordPress version, 2: the WordPress version this site is currently running */
						esc_html__( 'WordPress %1$s or newer is required for AI tools (this site is running %2$s).', 'cinatra' ),
						esc_html( CINATRA_ENSURE_MIN_WP_VERSION ),
						esc_html( get_bloginfo( 'version' ) )
					)
			);
			cinatra_render_checklist_row(
				$php_ok,
				$php_ok
					? esc_html__( 'PHP version meets the requirement for AI tools.', 'cinatra' )
					: sprintf(
						/* translators: 1: minimum required PHP version, 2: the PHP version this server is currently running */
						esc_html__( 'PHP %1$s or newer is required for AI tools (this server is running %2$s).', 'cinatra' ),
						esc_html( CINATRA_ENSURE_MIN_PHP_VERSION ),
						esc_html( PHP_VERSION )
					)
			);
			cinatra_render_checklist_row(
				$https_ok,
				$https_ok
					? esc_html__( 'Site is served over HTTPS.', 'cinatra' )
					: esc_html__( 'Site is not served over HTTPS — HTTPS is required for AI tools.', 'cinatra' )
			);
			cinatra_render_plugin_checklist_row(
				$mcp_state,
				__( 'WordPress MCP Adapter', 'cinatra' ),
				CINATRA_MCP_ADAPTER_RELEASE_URL,
				__( 'Download the WordPress MCP Adapter from GitHub', 'cinatra' )
			);
			cinatra_render_plugin_checklist_row(
				$catalog_state,
				__( 'Enable Abilities for MCP', 'cinatra' ),
				CINATRA_CATALOG_PLUGIN_URL,
				__( 'Get Enable Abilities for MCP from wordpress.org', 'cinatra' )
			);
			if ( '' !== $current_role ) {
				printf(
					'<li style="margin-bottom:8px;"><span class="description">%1$s</span></li>',
					sprintf(
						/* translators: %s: the signed-in user's WordPress role slug (e.g. "administrator") */
						esc_html__( 'Signed in as: %s.', 'cinatra' ),
						esc_html( $current_role )
					)
				);
			}
			?>
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

	// cinatra-ai/cinatra#2021 S6/eta — D5 trigger (a): an unconditional force
	// send immediately after a successful handshake (a fresh connect should
	// always report right away), plus (re)arm the daily cron fallback so an
	// admin-quiet site still gets covered even if this was a reconnect that
	// found the schedule already lost.
	cinatra_ensure_site_inventory_cron_scheduled();
	cinatra_send_site_inventory();
}

// ---------------------------------------------------------------------------
// One-click plugin installer (cinatra-ai/cinatra#2021 S6, PR zeta).
//
// This is the plugin's FIRST-EVER code that WRITES to the site's own plugin
// directory (installing/activating a plugin) rather than reading from or
// relaying through WordPress -- a new, higher-trust capability, not a variant
// of an existing one. See the CODEOWNERS entry on this file (cinatra-ai/
// wordpress-plugin#99): a human reviewer is structurally required on any
// change in this region, not just conventionally requested.
//
// Two independent install flows, both reachable ONLY from a single
// nonce-protected admin-post handler for an explicit admin form submit --
// never admin_init, WP-Cron, REST, or AJAX:
//
// 1. MCP Adapter (GitHub Releases ZIP, not on wordpress.org) -- a
// checksum-verified side-load. download_url() -> temp file -> reject a
// symlinked/escaped path -> hash_file('sha256') against the baked pin
// below -> a mismatch aborts closed, nothing is installed, ever -> a
// SECOND hash check immediately before Plugin_Upgrader::install()
// (closes as much of the TOCTOU window as PHP allows -- see the
// disclosed residual below) -> a post-install identity check against
// the same pin before offering activation.
// 2. Catalog plugin (enable-abilities-for-mcp, wordpress.org) -- the
// standard plugins_api()+Plugin_Upgrader flow every "Add Plugins" screen
// in WordPress core uses. No checksum step: wordpress.org's own
// signed-transport infrastructure is the trust anchor here, not this
// plugin.
//
// DISCLOSED, NOT-CLOSED RESIDUAL (accepted during the design's security review): PHP/Plugin_Upgrader
// has no atomic "hash-then-use" primitive. A same-host, same-filesystem-user
// attacker with write access to the WP temp directory could in principle
// mutate the verified file between the second hash check below and
// Plugin_Upgrader's own read of it. That attacker already has arbitrary code
// execution as the webserver user -- a strictly larger compromise than
// anything this installer could cause -- so it is explicitly out of scope,
// recorded here rather than silently omitted.
//
// Zip-slip / path traversal during extraction is NOT re-implemented here: the
// installer hands off to WP core's own Plugin_Upgrader::install(), which
// unpacks via WP core's own unzip_file() -- already hardened against
// traversal entries. No custom unzip code is written by this plugin.
//
// Both flows require current_user_can('install_plugins') AND
// current_user_can('activate_plugins'), re-checked FRESH in the handler
// (never cached/inferred from the button's mere presence in the DOM -- a
// common WP plugin bug class), take a short-lived lock so a double-click is
// a no-op rather than a race, and audit every outcome (success, checksum
// failure, identity mismatch, install failure) via an admin-visible notice
// plus a small bounded log option -- no silent failures.
// ---------------------------------------------------------------------------

// Pin for the GitHub side-load (D4): release-time-derived from cinatra's own
// docker/wordpress/pins.lock `mcpAdapter` block -- the SAME canonical pin the
// cinatra core repo's community-stack Docker image verifies against via
// `sha256sum -c` -- never an independently-typed literal that could silently
// drift from what cinatra's own gateway verifies against.
//
// Provenance: derived from cinatra-ai/cinatra @
// db9803e6509384d151af9e7791b7bcea9d2a77cc (the most recent commit to touch
// that file at pin-bake time). tests/fixtures/pins-lock-mcp-adapter.json is a
// checked-in fixture copy of the same block; tests/test-installer-pin-provenance.php
// diffs the two, so drift between them fails the build rather than diverging
// silently -- bump both, in the SAME reviewed PR, whenever this changes.
//
// EMERGENCY-UPDATE POSTURE (owner-ruled, OWNER RULINGS #3 in the design
// record): a CVE in the MCP Adapter itself has NO automatic remediation in
// v1 -- pinning to one exact version means the response is always cutting a
// new companion-plugin release with a new baked pin (never "just follow
// GitHub latest" silently), reviewed like any other pin bump in this
// codebase.
const CINATRA_MCP_ADAPTER_PIN_VERSION           = '0.5.0';
const CINATRA_MCP_ADAPTER_PIN_URL               = 'https://github.com/WordPress/mcp-adapter/releases/download/v0.5.0/mcp-adapter.zip';
const CINATRA_MCP_ADAPTER_PIN_SHA256            = 'a13f253c7bf4314b6cce7e238be2d5857eee66242bfe5ff5cb5576f74dc41593';
const CINATRA_MCP_ADAPTER_PIN_PROVENANCE_COMMIT = 'db9803e6509384d151af9e7791b7bcea9d2a77cc';

// Catalog plugin (wordpress.org) -- slug only, ALWAYS hardcoded, NEVER a
// user-controlled or remote-controlled value; nothing else about this flow's
// "identity" needs pinning since wp.org's own transport is the trust anchor.
const CINATRA_INSTALLER_EAFM_SLUG = 'enable-abilities-for-mcp';

// Install-lock TTL: long enough to cover a full download+verify+install round
// trip, short enough that a genuinely stuck lock self-clears quickly.
const CINATRA_INSTALLER_LOCK_TTL          = 90; // Seconds.
const CINATRA_INSTALLER_RESULT_KEY_PREFIX = 'cinatra_installer_result_';
const CINATRA_INSTALLER_AUDIT_LOG_OPTION  = 'cinatra_installer_audit_log';
const CINATRA_INSTALLER_AUDIT_LOG_MAX     = 20; // Bounded -- unbounded-option / DoS guard, same pattern as CINATRA_MAX_WEBHOOK_SUBSCRIPTIONS.

/**
 * Store a short-lived installer result message for the current user, surfaced
 * once on the settings page. Mirrors cinatra_set_connect_result()'s shape.
 *
 * @param string $type    'success' or 'error'.
 * @param string $message Human-readable, admin-facing message.
 * @return void
 */
function cinatra_set_installer_result( string $type, string $message ): void {
	set_transient(
		CINATRA_INSTALLER_RESULT_KEY_PREFIX . get_current_user_id(),
		array(
			'type'    => $type,
			'message' => $message,
		),
		60
	);
}

/** Render + clear the one-time installer result notice on the settings page. */
function cinatra_render_installer_result_notice(): void {
	$key    = CINATRA_INSTALLER_RESULT_KEY_PREFIX . get_current_user_id();
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

/**
 * Append one entry to the bounded installer audit log and fire an action hook
 * for any consuming code (e.g. an ensure-panel "last install attempt" line).
 * Never logs the download URL, a token, or a credential -- only the flow
 * name, a fixed-vocabulary outcome, and a short, sanitized detail string.
 *
 * @param string $flow    'mcp_adapter' or 'eafm'.
 * @param string $outcome One of: success, checksum_mismatch, identity_mismatch,
 *                        path_escape_rejected, download_failed, install_failed,
 *                        locked.
 * @param string $detail  Optional short, non-sensitive detail for the log line.
 * @return void
 */
function cinatra_installer_audit_log( string $flow, string $outcome, string $detail = '' ): void {
	$log   = get_option( CINATRA_INSTALLER_AUDIT_LOG_OPTION, array() );
	$log   = is_array( $log ) ? $log : array();
	$log[] = array(
		'time'    => time(),
		'user_id' => get_current_user_id(),
		'flow'    => $flow,
		'outcome' => $outcome,
		'detail'  => sanitize_text_field( $detail ),
	);
	if ( count( $log ) > CINATRA_INSTALLER_AUDIT_LOG_MAX ) {
		$log = array_slice( $log, -CINATRA_INSTALLER_AUDIT_LOG_MAX );
	}
	update_option( CINATRA_INSTALLER_AUDIT_LOG_OPTION, $log );
	/**
	 * Fires after EVERY installer attempt, success or failure (design §3 step
	 * 10) -- no silent failures. Args match the audit log entry above.
	 *
	 * @param string $flow    'mcp_adapter' or 'eafm'.
	 * @param string $outcome Fixed-vocabulary outcome (see cinatra_installer_audit_log()).
	 * @param string $detail  Short, sanitized, non-sensitive detail.
	 */
	do_action( 'cinatra_installer_attempt', $flow, $outcome, sanitize_text_field( $detail ) );
}

/**
 * Take a short-lived install lock so a double form-submit is a no-op rather
 * than a race on the same temp file / plugin directory.
 *
 * DISCLOSED LIMITATION (from the adversarial security review; not a bypass): a
 * transient backed by the options table is a best-effort duplicate-click
 * guard, not an atomic distributed lock -- two requests landing in the exact
 * same instant could both observe "not locked". Worst case is a duplicate
 * install attempt (each independently capability/nonce/checksum-gated on its
 * own merits), never a privilege or checksum bypass.
 *
 * @param string $flow 'mcp_adapter' or 'eafm'.
 * @return bool True if the lock was acquired (false = a lock is already held).
 */
function cinatra_installer_acquire_lock( string $flow ): bool {
	$key = 'cinatra_installer_lock_' . $flow;
	if ( false !== get_transient( $key ) ) {
		return false;
	}
	set_transient( $key, 1, CINATRA_INSTALLER_LOCK_TTL );
	return true;
}

/**
 * Release the install lock. Called on EVERY exit path (success or failure) so
 * a finished request never leaves a later click waiting out the full TTL.
 *
 * @param string $flow 'mcp_adapter' or 'eafm'.
 * @return void
 */
function cinatra_installer_release_lock( string $flow ): void {
	delete_transient( 'cinatra_installer_lock_' . $flow );
}

/**
 * Capability + nonce gate shared by both install handlers. Dies with a 403 on
 * failure. Re-checked HERE, at the handler, never inferred from render-time
 * gating alone (a common WP plugin bug class: the install button's mere
 * presence in the DOM proves nothing about the submitting request).
 *
 * @param string $nonce_action The wp_nonce_field() action name for this flow.
 * @return void
 */
function cinatra_installer_require_capability( string $nonce_action ): void {
	if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
		wp_die( esc_html__( 'You do not have permission to install plugins on this site.', 'cinatra' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( $nonce_action );
}

/**
 * Best-effort temp-file cleanup, called on EVERY exit path so cleanup is
 * never conditional on which branch (success/failure) was taken. A leftover
 * temp file in the OS temp dir is not itself a security issue (it is cleaned
 * up on the next attempt or by OS temp-dir GC), so failures here are swallowed.
 *
 * @param string $path Absolute path to the downloaded temp file.
 * @return void
 */
function cinatra_installer_cleanup_tmpfile( string $path ): void {
	if ( '' !== $path && file_exists( $path ) ) {
		wp_delete_file( $path ); // WP core wrapper (fires the wp_delete_file filter, then unlinks) -- the WPCS-preferred way to remove a file, over a bare unlink().
	}
}

/**
 * Verify a downloaded temp path is safe to hash/install: rejects a symlinked
 * temp path outright, and requires realpath() to resolve INSIDE the WP temp
 * directory (defends against a download helper somehow handing back a path
 * that escaped the expected directory).
 *
 * @param string $path Absolute path to the downloaded temp file.
 * @return bool True if the path is safe to proceed with.
 */
function cinatra_installer_path_is_safe( string $path ): bool {
	if ( '' === $path || ! file_exists( $path ) || is_link( $path ) ) {
		return false;
	}
	$real = realpath( $path );
	if ( false === $real ) {
		return false;
	}
	$temp_dir = realpath( get_temp_dir() );
	if ( false === $temp_dir ) {
		return false;
	}
	// Require $real to be INSIDE $temp_dir via a normalized, trailing-slash-
	// terminated directory prefix -- NOT a bare substring match, which a
	// crafted sibling directory name (e.g. "/tmpevil") could defeat.
	$temp_dir_with_slash = rtrim( $temp_dir, '/\\' ) . DIRECTORY_SEPARATOR;
	return 0 === strpos( $real, $temp_dir_with_slash );
}

/**
 * Verify a local file's sha256 against a pinned value. Used TWICE by the
 * mcp_adapter flow below (design D3 steps 5 and 6 -- the first check and the
 * re-hash immediately before install) so a same-request file mutation between
 * the two calls is independently re-detected, not assumed away.
 *
 * $hash_fn is an injectable seam used ONLY by tests (defaults to the real
 * hash_file()) -- production call sites never pass it.
 *
 * @param string        $path            Local file path to verify.
 * @param string        $expected_sha256 Pinned sha256 to compare against.
 * @param callable|null $hash_fn         Optional (string $path): string hasher, for tests.
 * @return bool True if the file's current sha256 matches the pin.
 */
function cinatra_installer_verify_checksum( string $path, string $expected_sha256, ?callable $hash_fn = null ): bool {
	if ( '' === $path || ! file_exists( $path ) ) {
		return false;
	}
	if ( null === $hash_fn ) {
		$hash_fn = static function ( string $p ): string {
			return (string) hash_file( 'sha256', $p );
		};
	}
	return hash_equals( $expected_sha256, (string) $hash_fn( $path ) );
}

/**
 * Steps 7-9 of the installer flow (D3/§3): hand the package to WP's own
 * Plugin_Upgrader, optionally verify the installed plugin's own header
 * against a pinned version (skipped when $expected_version is null -- the
 * wordpress.org catalog flow has no pin to check, by design), and optionally
 * activate. No hashing happens in this function -- it is reached only AFTER
 * the checksum step for the mcp_adapter flow, and not at all for the eafm
 * flow (which has no checksum step) -- so it is independently testable with
 * WP_Filesystem/Plugin_Upgrader/get_plugins/activate_plugin stubbed, with no
 * cryptographic preimage requirement on the test fixtures.
 *
 * @param string      $package           Verified local path (mcp_adapter) or a wordpress.org download URL (eafm -- Plugin_Upgrader fetches it itself, no second checksum-relevant network leg for the side-load path either way).
 * @param string|null $known_plugin_file Plugin file to identity-check + activate, if already known (mcp_adapter's static constant); null lets Plugin_Upgrader::plugin_info() resolve it after install (eafm).
 * @param string|null $expected_version  Pinned version the installed header must match; null skips the identity check entirely (eafm).
 * @param bool        $activate          Whether to activate on success.
 * @return array{ok:bool, outcome:string, detail:string, plugin_file:string}
 */
function cinatra_installer_finish_install( string $package, ?string $known_plugin_file, ?string $expected_version, bool $activate ): array {
	if ( ! class_exists( 'Plugin_Upgrader' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}
	if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
	}
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( ! WP_Filesystem() ) {
		return array(
			'ok'          => false,
			'outcome'     => 'install_failed',
			'detail'      => 'filesystem_credentials_required',
			'plugin_file' => '',
		);
	}

	$upgrader  = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
	$installed = $upgrader->install( $package ); // Local path (mcp_adapter) => no second network fetch (WP_Upgrader::download_package() returns an existing local path untouched, D3 step 6/7).

	if ( is_wp_error( $installed ) || true !== $installed ) {
		return array(
			'ok'          => false,
			'outcome'     => 'install_failed',
			'detail'      => is_wp_error( $installed ) ? $installed->get_error_code() : 'unknown',
			'plugin_file' => '',
		);
	}

	$plugin_file = $known_plugin_file ?? (string) $upgrader->plugin_info();
	if ( '' === $plugin_file ) {
		return array(
			'ok'          => false,
			'outcome'     => 'install_failed',
			'detail'      => 'plugin_file_unresolved',
			'plugin_file' => '',
		);
	}

	if ( null !== $expected_version ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins       = get_plugins();
		$installed_version = isset( $all_plugins[ $plugin_file ]['Version'] ) ? (string) $all_plugins[ $plugin_file ]['Version'] : '';
		if ( $expected_version !== $installed_version ) {
			// Post-install identity check FAILED: left installed-but-
			// unactivated for manual inspection -- never auto-removed, never
			// auto-activated, even though the checksum matched (D3 step 8).
			return array(
				'ok'          => false,
				'outcome'     => 'identity_mismatch',
				'detail'      => $installed_version,
				'plugin_file' => $plugin_file,
			);
		}
	}

	if ( ! $activate ) {
		return array(
			'ok'          => true,
			'outcome'     => 'success',
			'detail'      => 'installed_only',
			'plugin_file' => $plugin_file,
		);
	}

	if ( ! function_exists( 'activate_plugin' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$activated = activate_plugin( $plugin_file );
	if ( is_wp_error( $activated ) ) {
		return array(
			'ok'          => false,
			'outcome'     => 'install_failed',
			'detail'      => 'activate:' . $activated->get_error_code(),
			'plugin_file' => $plugin_file,
		);
	}
	return array(
		'ok'          => true,
		'outcome'     => 'success',
		'detail'      => 'activated',
		'plugin_file' => $plugin_file,
	);
}

/**
 * Handler for the checksum-verified MCP Adapter side-load (D3). Every
 * download_url()/hash_file()-driven check, the Plugin_Upgrader install call,
 * and activate_plugin() below are reachable ONLY from this one
 * nonce-protected admin-post callback for an explicit admin form submit -- no
 * admin_init, no WP-Cron, no REST callback, no AJAX shortcut reaches any of
 * this anywhere else in this plugin (tests/test-installer-invariants.php
 * asserts this statically).
 */
add_action(
	'admin_post_cinatra_install_mcp_adapter',
	function () {
		cinatra_installer_require_capability( 'cinatra_install_mcp_adapter' );

		if ( ! cinatra_installer_acquire_lock( 'mcp_adapter' ) ) {
			cinatra_installer_audit_log( 'mcp_adapter', 'locked' );
			cinatra_set_installer_result( 'error', __( 'An install is already in progress for the WordPress MCP Adapter. Please wait for it to finish.', 'cinatra' ) );
			cinatra_connect_redirect_to_settings();
		}

		$tmpfile = '';
		try {
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$download = download_url( CINATRA_MCP_ADAPTER_PIN_URL );
			if ( is_wp_error( $download ) ) {
				cinatra_installer_audit_log( 'mcp_adapter', 'download_failed', $download->get_error_code() );
				cinatra_set_installer_result( 'error', __( "Could not download the WordPress MCP Adapter. Check the server's outbound network access and try again.", 'cinatra' ) );
				return;
			}
			$tmpfile = $download;

			if ( ! cinatra_installer_path_is_safe( $tmpfile ) ) {
				cinatra_installer_audit_log( 'mcp_adapter', 'path_escape_rejected' );
				cinatra_set_installer_result( 'error', __( 'The downloaded file failed a safety check. Nothing was installed.', 'cinatra' ) );
				return;
			}

			// First checksum check (D3 step 5): fail closed -- nothing is
			// installed on a mismatch, ever.
			if ( ! cinatra_installer_verify_checksum( $tmpfile, CINATRA_MCP_ADAPTER_PIN_SHA256 ) ) {
				cinatra_installer_audit_log( 'mcp_adapter', 'checksum_mismatch' );
				cinatra_set_installer_result(
					'error',
					sprintf(
						/* translators: %s: URL of the GitHub release page */
						__( 'Checksum mismatch — the download may be corrupted or tampered with. Nothing was installed. Install manually from %s if this persists.', 'cinatra' ),
						CINATRA_MCP_ADAPTER_PIN_URL
					)
				);
				return;
			}

			// RE-HASH immediately before handing off to the upgrader (D3 step
			// 6) -- closes as much of the TOCTOU window as PHP allows (see
			// the disclosed residual in the section header above). Only the
			// verified LOCAL PATH goes to the upgrader below, never the URL.
			if ( ! cinatra_installer_verify_checksum( $tmpfile, CINATRA_MCP_ADAPTER_PIN_SHA256 ) ) {
				cinatra_installer_audit_log( 'mcp_adapter', 'checksum_mismatch' );
				cinatra_set_installer_result( 'error', __( 'Checksum mismatch on re-verification. Nothing was installed.', 'cinatra' ) );
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- covered by check_admin_referer() inside cinatra_installer_require_capability() above (one nonce for the whole form).
			$activate = isset( $_POST['cinatra_install_activate'] ) && '1' === $_POST['cinatra_install_activate'];
			$result   = cinatra_installer_finish_install( $tmpfile, CINATRA_MCP_ADAPTER_PLUGIN_FILE, CINATRA_MCP_ADAPTER_PIN_VERSION, $activate );

			cinatra_installer_audit_log( 'mcp_adapter', $result['outcome'], $result['detail'] );
			if ( $result['ok'] ) {
				cinatra_set_installer_result(
					'success',
					'activated' === $result['detail']
						? __( 'The WordPress MCP Adapter was installed, checksum-verified, and activated.', 'cinatra' )
						: __( 'The WordPress MCP Adapter was installed and checksum-verified. Activate it from the Plugins screen when ready.', 'cinatra' )
				);
			} elseif ( 'identity_mismatch' === $result['outcome'] ) {
				cinatra_set_installer_result( 'error', __( 'The WordPress MCP Adapter was installed, but its version does not match what this plugin expects. It has been LEFT INACTIVE for manual inspection — it was NOT auto-removed or auto-activated.', 'cinatra' ) );
			} else {
				cinatra_set_installer_result( 'error', __( 'The WordPress MCP Adapter download passed its checksum check, but installation failed. Nothing was activated.', 'cinatra' ) );
			}
		} finally {
			cinatra_installer_cleanup_tmpfile( $tmpfile );
			cinatra_installer_release_lock( 'mcp_adapter' );
		}

		cinatra_connect_redirect_to_settings();
	}
);

/**
 * Handler for the standard wordpress.org catalog install of the Abilities
 * plugin (D3). No checksum step: wordpress.org's own signed-transport
 * infrastructure is the trust anchor for this flow, matching every other
 * "Add Plugins" screen in WordPress core. Reachable only from this one
 * nonce-protected admin-post callback, same invariant as the flow above.
 */
add_action(
	'admin_post_cinatra_install_eafm',
	function () {
		cinatra_installer_require_capability( 'cinatra_install_eafm' );

		if ( ! cinatra_installer_acquire_lock( 'eafm' ) ) {
			cinatra_installer_audit_log( 'eafm', 'locked' );
			cinatra_set_installer_result( 'error', __( 'An install is already in progress for the Abilities plugin. Please wait for it to finish.', 'cinatra' ) );
			cinatra_connect_redirect_to_settings();
		}

		try {
			if ( ! function_exists( 'plugins_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}
			$api = plugins_api(
				'plugin_information',
				array(
					'slug'   => CINATRA_INSTALLER_EAFM_SLUG,
					'fields' => array( 'sections' => false ),
				)
			);
			if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
				cinatra_installer_audit_log( 'eafm', 'download_failed', is_wp_error( $api ) ? $api->get_error_code() : 'no_download_link' );
				cinatra_set_installer_result( 'error', __( 'Could not reach the wordpress.org plugin directory. Try again later.', 'cinatra' ) );
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- covered by check_admin_referer() inside cinatra_installer_require_capability() above (one nonce for the whole form).
			$activate = isset( $_POST['cinatra_install_activate'] ) && '1' === $_POST['cinatra_install_activate'];
			// No checksum step here (D3): wordpress.org's own signed-transport
			// infrastructure is the trust anchor for the catalog flow.
			$result = cinatra_installer_finish_install( (string) $api->download_link, null, null, $activate );

			cinatra_installer_audit_log( 'eafm', $result['outcome'], $result['detail'] );
			if ( $result['ok'] ) {
				cinatra_set_installer_result(
					'success',
					'activated' === $result['detail']
						? __( 'The Abilities plugin was installed and activated.', 'cinatra' )
						: __( 'The Abilities plugin was installed. Activate it from the Plugins screen when ready.', 'cinatra' )
				);
			} else {
				cinatra_set_installer_result( 'error', __( 'Installation of the Abilities plugin failed.', 'cinatra' ) );
			}
		} finally {
			cinatra_installer_release_lock( 'eafm' );
		}

		cinatra_connect_redirect_to_settings();
	}
);

/**
 * Render the two one-click install buttons (D3/ζ). Each is its own
 * nonce-protected form POSTing to admin-post.php. Capability-gated at RENDER
 * time here too (a UX courtesy that withholds the button entirely rather
 * than merely disabling it) -- the REAL gate is the fresh re-check inside
 * each handler above, since render-time-only gating is a common WP plugin
 * bug class. Deliberately a self-contained function (not folded into
 * cinatra_render_setup_checklist()) so this PR's diff stays surgically
 * separate from the ensure-panel detection work landing in the same file.
 *
 * @return void
 */
function cinatra_render_installer_actions(): void {
	cinatra_render_installer_result_notice();

	if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$mcp_active = cinatra_mcp_adapter_active();
	?>
	<div class="card" style="max-width:680px;margin-top:16px;">
		<h2 style="margin-top:0;"><?php echo esc_html__( 'One-click install', 'cinatra' ); ?></h2>
		<p class="description">
			<?php echo esc_html__( 'Installs are checksum-verified where applicable and never run automatically — each requires this explicit click.', 'cinatra' ); ?>
		</p>
		<?php if ( ! $mcp_active ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
				<input type="hidden" name="action" value="cinatra_install_mcp_adapter" />
				<?php wp_nonce_field( 'cinatra_install_mcp_adapter' ); ?>
				<label style="display:block;margin-bottom:6px;">
					<input type="checkbox" name="cinatra_install_activate" value="1" checked="checked" />
					<?php echo esc_html__( 'Activate after installing', 'cinatra' ); ?>
				</label>
				<?php
				submit_button(
					sprintf(
						/* translators: %s: pinned plugin version, e.g. 0.5.0 */
						__( 'Install WordPress MCP Adapter v%s (checksum-verified)', 'cinatra' ),
						CINATRA_MCP_ADAPTER_PIN_VERSION
					),
					'secondary',
					'submit',
					false
				);
				?>
			</form>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cinatra_install_eafm" />
			<?php wp_nonce_field( 'cinatra_install_eafm' ); ?>
			<label style="display:block;margin-bottom:6px;">
				<input type="checkbox" name="cinatra_install_activate" value="1" checked="checked" />
				<?php echo esc_html__( 'Activate after installing', 'cinatra' ); ?>
			</label>
			<?php submit_button( __( 'Install Abilities plugin (enable-abilities-for-mcp)', 'cinatra' ), 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
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
 * Agent slug for the WordPress content-editor assistant. The short-lived cit_
 * token is minted at /api/agents/{slug}/token on the instance (unchanged by the
 * S5 unified-broker cutover, cinatra#1221). The legacy /capabilities negotiation
 * and /stream relay under /api/agents/{slug}/ were deleted (cinatra#1991); the
 * conversation now runs against the unified broker (POST /api/assistants/chat)
 * inside the /embed/assistant iframe.
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

// ---------------------------------------------------------------------------
// S6 — integrated-render review: server-emitted region anchors + an
// authenticated preview URL (cinatra-ai/cinatra#2044, plugin half).
//
// Two host-facing capabilities, kept deliberately narrow:
//
// 1. REGION ANCHORS. The reviewer surface highlights the site's OWNED regions
// (the scope-manifest fields: title / content / excerpt) inside the captured
// page. Anchors come EXCLUSIVELY from this adapter — never reviewer-side CSS
// guessing (issue #2044). They are emitted at the content-filter level
// (the_title / the_content / the_excerpt) as invisible `data-cinatra-region`
// attributes, and ONLY while THIS plugin is rendering the previewed post
// (the `cinatra_preview_target` render flag is set). A normal front-end
// visitor never sets that flag, so the public page is byte-identical to
// before — no visual change, no DOM change, no unpublished data. This is the
// safe alternative to always-wrapping the_title (which fires for nav-menu
// items, widget titles, etc. and would inject markup site-wide).
//
// 2. AUTHENTICATED PREVIEW URL. A host-callable REST route,
// GET /wp-json/cinatra/v1/preview/<id>, returns the FULLY RENDERED page for
// a post — INCLUDING draft / pending / future — with the region anchors
// present, so the host's capture pipeline can screenshot the staged page.
// It is authenticated with the SAME connect-provisioned shared credential
// the publish emitter already uses: the Standard-Webhooks secret paired at
// connect time (`cinatra_webhook_secret`). The host proves knowledge of that
// secret with an HMAC signature over the request (webhook-id /
// webhook-timestamp / webhook-signature headers, verified byte-for-byte by
// the SAME cinatra_webhook_sign() used outbound) — the secret itself is
// never transmitted, and the comparison is constant-time. No valid signature
// (or the site is not host-connected) => the permission callback denies and
// WordPress returns 401 before any draft is loaded. This reuses the ESTABLISHED
// host<->plugin auth from prior waves rather than inventing a new credential:
// the browser-facing short-lived `cit_` site token is host-ISSUED and cannot
// be verified plugin-side, whereas the webhook secret is the one credential
// both ends already share and the plugin can verify locally.
// ---------------------------------------------------------------------------

/**
 * The scope-manifest field names this adapter owns and anchors. These are the
 * exact `data-cinatra-region` values the reviewer surface keys on.
 */
const CINATRA_PREVIEW_REGION_TITLE   = 'title';
const CINATRA_PREVIEW_REGION_CONTENT = 'content';
const CINATRA_PREVIEW_REGION_EXCERPT = 'excerpt';

/**
 * Replay/freshness window (seconds) for a signed preview request, mirroring the
 * Standard-Webhooks recommended tolerance the host signs with.
 */
const CINATRA_PREVIEW_TS_TOLERANCE = 300;

/**
 * The id of the post currently being rendered by the authenticated preview
 * endpoint, or 0 when not in a preview render. Read by the anchor filters so
 * they stay INERT on every normal front-end request (no visitor-visible change).
 *
 * @return int
 */
function cinatra_preview_target(): int {
	return (int) ( $GLOBALS['cinatra_preview_target'] ?? 0 );
}

/**
 * Title-region anchor (the_title filter): wrap ONLY the previewed post's title
 * in a title-region anchor, and ONLY during a preview render. Guarded on the id so the many
 * other the_title callers (nav-menu items, widget titles, adjacent-post links)
 * are never wrapped. Idempotent and inert outside preview.
 *
 * @param string   $title The post title.
 * @param int|null $id    The post id the title belongs to (the_title's 2nd arg).
 * @return string
 */
function cinatra_anchor_the_title( $title, $id = null ) {
	$target = cinatra_preview_target();
	if ( 0 === $target || (int) $id !== $target ) {
		return $title;
	}
	if ( false !== strpos( (string) $title, 'data-cinatra-region="' . CINATRA_PREVIEW_REGION_TITLE . '"' ) ) {
		return $title; // already anchored — deterministic, no double-wrap.
	}
	return sprintf(
		'<span class="cinatra-region" data-cinatra-region="%1$s" data-cinatra-post="%2$d">%3$s</span>',
		esc_attr( CINATRA_PREVIEW_REGION_TITLE ),
		$target,
		$title
	);
}

/**
 * Content-region anchor (the_content filter): wrap the previewed post's rendered content in a
 * content-region anchor, ONLY during a preview render and ONLY for the target
 * post (guarded on the global post id). Runs late so it wraps the fully-formed
 * content. Idempotent and inert outside preview.
 *
 * @param string $content The rendered post content.
 * @return string
 */
function cinatra_anchor_the_content( $content ) {
	$target = cinatra_preview_target();
	if ( 0 === $target ) {
		return $content;
	}
	$post = get_post();
	if ( ! ( $post instanceof WP_Post ) || (int) $post->ID !== $target ) {
		return $content;
	}
	if ( false !== strpos( (string) $content, 'data-cinatra-region="' . CINATRA_PREVIEW_REGION_CONTENT . '"' ) ) {
		return $content;
	}
	return sprintf(
		'<div class="cinatra-region" data-cinatra-region="%1$s" data-cinatra-post="%2$d">%3$s</div>',
		esc_attr( CINATRA_PREVIEW_REGION_CONTENT ),
		$target,
		$content
	);
}

/**
 * Excerpt-region anchor (the_excerpt filter): wrap the previewed post's excerpt in an excerpt-region
 * anchor, ONLY during a preview render and ONLY for the target post. Idempotent
 * and inert outside preview.
 *
 * @param string $excerpt The rendered post excerpt.
 * @return string
 */
function cinatra_anchor_the_excerpt( $excerpt ) {
	$target = cinatra_preview_target();
	if ( 0 === $target ) {
		return $excerpt;
	}
	$post = get_post();
	if ( ! ( $post instanceof WP_Post ) || (int) $post->ID !== $target ) {
		return $excerpt;
	}
	if ( false !== strpos( (string) $excerpt, 'data-cinatra-region="' . CINATRA_PREVIEW_REGION_EXCERPT . '"' ) ) {
		return $excerpt;
	}
	return sprintf(
		'<div class="cinatra-region" data-cinatra-region="%1$s" data-cinatra-post="%2$d">%3$s</div>',
		esc_attr( CINATRA_PREVIEW_REGION_EXCERPT ),
		$target,
		$excerpt
	);
}

// Registered globally but INERT unless a preview render sets the target flag, so
// there is zero effect on a normal front-end request. Late priority on content/
// excerpt so anchors wrap the finished markup.
add_filter( 'the_title', 'cinatra_anchor_the_title', 20, 2 );
add_filter( 'the_content', 'cinatra_anchor_the_content', 999 );
add_filter( 'the_excerpt', 'cinatra_anchor_the_excerpt', 999 );

/**
 * Permission callback for the preview route. Authenticates a HOST call with the
 * connect-provisioned Standard-Webhooks shared secret: the host signs the
 * canonical content "preview.<id>" and presents webhook-id / webhook-timestamp /
 * webhook-signature headers; we recompute with cinatra_webhook_sign() and
 * constant-time compare. Fails CLOSED — an unconnected site (no secret), a
 * missing/stale/forged signature, or a malformed stored secret all deny, so
 * WordPress returns 401 before any draft is loaded.
 *
 * @param WP_REST_Request $request The incoming request.
 * @return bool True only for a verified host signature.
 */
function cinatra_preview_authorize( WP_REST_Request $request ): bool {
	$secret = (string) get_option( 'cinatra_webhook_secret', '' );
	if ( '' === $secret ) {
		return false; // Site not host-connected: there is no preview credential.
	}

	$id_hdr  = (string) $request->get_header( 'webhook-id' );
	$ts_hdr  = (string) $request->get_header( 'webhook-timestamp' );
	$sig_hdr = (string) $request->get_header( 'webhook-signature' );
	if ( '' === $id_hdr || '' === $ts_hdr || '' === $sig_hdr || ! ctype_digit( $ts_hdr ) ) {
		return false;
	}

	$timestamp = (int) $ts_hdr;
	if ( abs( time() - $timestamp ) > CINATRA_PREVIEW_TS_TOLERANCE ) {
		return false; // Outside the freshness window — reject a replay.
	}

	$post_id = (int) $request->get_param( 'id' );
	if ( $post_id <= 0 ) {
		return false;
	}

	// The signed content binds the signature to THIS post id: a signature minted
	// for one post cannot be replayed against another.
	$expected = cinatra_webhook_sign( $secret, $id_hdr, $timestamp, 'preview.' . $post_id );
	if ( null === $expected ) {
		return false; // Malformed stored secret — fail closed, never guess.
	}

	// Standard-Webhooks permits a space-separated list of "vN,<base64>" values.
	foreach ( explode( ' ', $sig_hdr ) as $candidate ) {
		if ( '' !== $candidate && hash_equals( $expected, $candidate ) ) {
			// SINGLE-USE (best-effort): consume the webhook-id so a captured
			// request replayed SEQUENTIALLY is rejected inside the freshness
			// window. This is a read-only GET over TLS that only re-renders the
			// same draft, so the freshness window (300s) + transport is the
			// primary replay bound; the transient consume-once is added hardening.
			// The check→set is not cross-process atomic without a persistent
			// object cache (where wp_cache/transient add IS atomic), so a
			// simultaneous double-replay could still slip through — an accepted,
			// low-severity residual for a side-effect-free render. The host mints
			// a fresh id per attempt (like the outbound publish emitter), so a
			// legitimate retry is unaffected.
			$replay_key = 'cinatra_preview_seen_' . substr( hash( 'sha256', $id_hdr ), 0, 40 );
			if ( '' !== (string) get_transient( $replay_key ) ) {
				return false; // Already consumed — reject the replay.
			}
			set_transient( $replay_key, '1', CINATRA_PREVIEW_TS_TOLERANCE );
			return true;
		}
	}
	return false;
}

/**
 * Render a post (any status — draft/pending/future included) to a full HTML
 * page with the owned regions anchored. Sets the preview render flag for the
 * duration so the anchor filters activate for exactly this post, then restores
 * the previous global state. Theme header/footer are included as NON-decisional
 * context when the theme provides them (opt-out via the
 * `cinatra_preview_include_theme_chrome` filter); when it does not, a clean,
 * deterministic standalone document is returned so the capture is stable.
 *
 * @param WP_Post $post The post to render.
 * @return string The rendered HTML page.
 */
function cinatra_preview_render_post( WP_Post $post ): string {
	$prev_post                         = $GLOBALS['post'] ?? null;
	$prev_target                       = $GLOBALS['cinatra_preview_target'] ?? 0;
	$GLOBALS['post']                   = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Deliberately scope the global post to the previewed post so the_content/the_excerpt anchor the right region; restored below.
	$GLOBALS['cinatra_preview_target'] = (int) $post->ID;

	// try/finally so a thrown theme/filter hook can NEVER leave the render flag
	// or the global post contaminated for the rest of the request (which would
	// leak anchors into a later render). Restoration always runs.
	try {
		$title   = apply_filters( 'the_title', $post->post_title, $post->ID );
		$content = apply_filters( 'the_content', $post->post_content );
		$excerpt = apply_filters( 'the_excerpt', $post->post_excerpt );

		$body  = sprintf(
			'<article class="cinatra-preview" data-cinatra-preview-post="%1$d" data-cinatra-preview-status="%2$s">',
			(int) $post->ID,
			esc_attr( (string) $post->post_status )
		);
		$body .= '<h1 class="cinatra-preview-title">' . $title . '</h1>';
		$body .= '<div class="cinatra-preview-content">' . $content . '</div>';
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			$body .= '<div class="cinatra-preview-excerpt">' . $excerpt . '</div>';
		}
		$body .= '</article>';

		// Best-effort theme chrome as non-decisional context. Buffered so a theme
		// notice/echo can't corrupt the page boundary; degrades to a clean document
		// when the theme provides nothing (or is disabled by the filter).
		$header = '';
		$footer = '';
		if ( apply_filters( 'cinatra_preview_include_theme_chrome', true ) && function_exists( 'get_header' ) && function_exists( 'get_footer' ) ) {
			ob_start();
			get_header();
			$header = (string) ob_get_clean();
			ob_start();
			get_footer();
			$footer = (string) ob_get_clean();
		}

		if ( '' !== $header || '' !== $footer ) {
			return $header . $body . $footer;
		}

		return "<!DOCTYPE html>\n"
			. '<html><head><meta charset="utf-8">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<title>' . esc_html( wp_strip_all_tags( (string) $post->post_title ) ) . '</title>'
			. '</head><body>' . $body . '</body></html>';
	} finally {
		$GLOBALS['cinatra_preview_target'] = $prev_target;
		$GLOBALS['post']                   = $prev_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the global post captured above; always runs.
	}
}

/**
 * REST callback for the authenticated preview route. Auth is already enforced by
 * cinatra_preview_authorize(); here we load the post (any status), reject
 * non-previewable objects (missing / revision / autosave / non-public type), and
 * return the fully rendered, anchored page. The raw HTML is served as text/html
 * by cinatra_preview_serve_html() below; the structured payload is the carrier.
 *
 * @param WP_REST_Request $request The incoming request.
 * @return WP_REST_Response|WP_Error
 */
function cinatra_rest_render_preview( WP_REST_Request $request ) {
	$post_id = (int) $request->get_param( 'id' );
	$post    = get_post( $post_id );
	if ( ! ( $post instanceof WP_Post ) ) {
		return new WP_Error( 'cinatra_preview_not_found', __( 'Post not found.', 'cinatra' ), array( 'status' => 404 ) );
	}
	if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
		return new WP_Error( 'cinatra_preview_not_previewable', __( 'This object cannot be previewed.', 'cinatra' ), array( 'status' => 404 ) );
	}
	$type_object = get_post_type_object( $post->post_type );
	if ( null === $type_object || empty( $type_object->public ) ) {
		return new WP_Error( 'cinatra_preview_not_previewable', __( 'This post type cannot be previewed.', 'cinatra' ), array( 'status' => 404 ) );
	}

	$html = cinatra_preview_render_post( $post );

	$response = new WP_REST_Response(
		array(
			'cinatra_preview_html' => $html,
			'postId'               => (int) $post->ID,
			'postStatus'           => (string) $post->post_status,
			'regions'              => array(
				CINATRA_PREVIEW_REGION_TITLE,
				CINATRA_PREVIEW_REGION_CONTENT,
				CINATRA_PREVIEW_REGION_EXCERPT,
			),
		)
	);
	return $response;
}

/**
 * Serve the preview route as raw text/html (a screenshot-able page) instead of a
 * JSON envelope. Scoped strictly to the preview route AND to a response carrying
 * the `cinatra_preview_html` marker, so no other REST response is affected.
 *
 * @param bool             $served  Whether the request has already been served.
 * @param WP_REST_Response $result  The response object.
 * @param WP_REST_Request  $request The request object.
 * @return bool True when we served the raw HTML.
 */
function cinatra_preview_serve_html( $served, $result, $request ) {
	if ( $served || ! ( $result instanceof WP_REST_Response ) || ! ( $request instanceof WP_REST_Request ) ) {
		return $served;
	}
	// Match the EXACT preview route only (not a mere prefix), so a future sibling
	// route under the same namespace can never be captured by this handler.
	if ( ! preg_match( '#^/cinatra/v1/preview/\d+$#', (string) $request->get_route() ) ) {
		return $served;
	}
	$data = $result->get_data();
	if ( ! is_array( $data ) || ! isset( $data['cinatra_preview_html'] ) ) {
		return $served;
	}
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: no-store, private' );
	}
	// The payload is a full post render produced through the same the_content /
	// the_title / the_excerpt filter chain that renders the public page; it is
	// the page markup itself, so it is emitted verbatim (escaping it again would
	// double-encode the very HTML the capture pipeline must screenshot).
	echo $data['cinatra_preview_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered post markup from the the_content/the_title/the_excerpt filter chain (identical trust boundary to a normal front-end template render); re-escaping would corrupt the captured page.
	return true;
}
add_filter( 'rest_pre_serve_request', 'cinatra_preview_serve_html', 10, 3 );

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'cinatra/v1',
			'/preview/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => 'cinatra_rest_render_preview',
				'permission_callback' => 'cinatra_preview_authorize',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							return ctype_digit( (string) $value ) && (int) $value > 0;
						},
					),
				),
			)
		);
	}
);

// ---------------------------------------------------------------------------
// Handshake enrichment sender (cinatra-ai/cinatra#2021 S6 / eta). Reports this
// site's MCP server inventory to the cinatra core intake route
// (POST {cinatra_url}/api/connect/site-inventory, cinatra-ai/cinatra#2206 /
// S6-beta) so multi-server enrollment (cinatra-ai/cinatra#2018 S3) can enroll
// or retire discovered servers without any manual configuration core-side.
// Payload shape is pinned field-for-field to the cinatra repo's
// docs/internals/contracts/wp-site-inventory-contract.md; see
// cinatra_build_site_inventory_payload() below for the exact mapping.
//
// ONE shared builder/sender, THREE triggers (all call the same function --
// no duplicated payload-building logic):
// (a) an unconditional FORCE SEND immediately after a successful Connect
// handshake (cinatra_connect_apply_result()'s success branch) -- a
// fresh connect should always report immediately;
// (b) Settings -> Cinatra page load, gated by BOTH a locally-persisted
// last-attempt timestamp (>=60s + jitter -- matching, not racing, the
// server's own 60s per-site debounce) AND a locally-computed payload
// hash differing from the last ACCEPTED send -- an unchanged page
// reload makes no network call and does not advance inventorySeq;
// (c) a daily WP-Cron fallback (scheduled on plugin activation and on every
// successful connect) for admin-quiet sites -- always sends, no gate,
// since drift coverage (e.g. a third-party plugin silently adding an
// MCP server) is the entire point of this trigger.
//
// inventorySeq increments ONCE PER ACTUAL SEND ATTEMPT (contract-required
// monotonicity), never for a page load the local gate skipped before ever
// calling the sender. A best-effort transient lock (same pattern as the
// installer's double-click lock) serializes overlapping triggers so two
// concurrent sends don't allocate the same seq; the residual race is
// fail-closed either way (core's atomic anti-replay gate rejects a
// duplicate as stale and the next tick self-heals). A 429/4xx/5xx/transport
// failure is handled by simply NOT retrying inline -- the next cadence tick
// naturally resends with a fresh, higher inventorySeq, which the contract's
// monotonic-accept design always tolerates.
//
// Read-only from this site's perspective: this section only enumerates
// existing state (get_servers(), plugin headers, WP/PHP versions) and sends
// it outbound -- nothing here writes to the site, installs anything, or
// touches the installer/CODEOWNERS-gated regions above. Every failure mode is
// a SILENT no-op (no admin notice, no thrown exception, no retry loop); the
// worst case is a stale or missing inventory row core-side, which S3's own
// design already treats as fail-closed (fewer enrolled servers, never a
// crash).
// ---------------------------------------------------------------------------

const CINATRA_SITE_INVENTORY_CONTRACT_VERSION   = 'v1';
const CINATRA_SITE_INVENTORY_ENDPOINT_PATH      = '/api/connect/site-inventory';
const CINATRA_SITE_INVENTORY_MAX_SERVERS        = 100; // Mirrors core's SITE_INVENTORY_MAX_SERVERS cap (defense in depth; core enforces this too).
const CINATRA_SITE_INVENTORY_DEBOUNCE_SECONDS   = 60;  // Mirrors the core per-site debounce window (contract "Channel").
const CINATRA_SITE_INVENTORY_JITTER_MAX_SECONDS = 15;  // The local page-load gate waits slightly LONGER than the server window (matching, not racing -- D5).
const CINATRA_SITE_INVENTORY_CRON_HOOK          = 'cinatra_site_inventory_cron_send';
const CINATRA_SITE_INVENTORY_SEND_LOCK_TTL      = 30;  // Seconds -- covers the 10s HTTP timeout + build time with headroom.
// Client-side cap on the ENCODED payload, kept safely below the intake
// route's hard 256 KB body cap: an over-cap send would be rejected whole
// (and would still burn the debounce window), so the builder trims optional
// content (descriptions first, then trailing entries) to fit BEFORE sending.
const CINATRA_SITE_INVENTORY_MAX_BODY_BYTES = 245760; // 240 KB (route cap 256 KB minus headroom).
// Mirrors core's DEFAULT_ADAPTER_SERVER_REST_PATH (connector-instance-site-inventory-contract.ts).
const CINATRA_MCP_ADAPTER_DEFAULT_SERVER_REST_PATH = '/mcp/mcp-adapter-default-server';
// Mirror the contract's own server-entry identity patterns (ADAPTER_SERVER_ID_PATTERN /
// SERVER_NAMESPACE_PATTERN / SERVER_ROUTE_PATTERN) so a malformed third-party MCP
// server is skipped client-side rather than 400-ing the WHOLE payload core-side
// (the contract validates strictly -- one bad entry would otherwise block every
// other valid server this site is reporting).
const CINATRA_SITE_INVENTORY_SERVER_ID_PATTERN = '/^[a-z0-9][a-z0-9_.\-]{0,127}$/i';
const CINATRA_SITE_INVENTORY_NAMESPACE_PATTERN = '/^[a-z0-9][a-z0-9_\-]{0,63}$/i';
const CINATRA_SITE_INVENTORY_ROUTE_PATTERN     = '/^[a-z0-9][a-z0-9\/_\-]{0,127}$/i';

/**
 * Clip a value to a contract max length, as a string. Every bounded field in
 * the payload goes through this: the intake contract's schema is STRICT with
 * per-field max lengths, so ONE overlong value (a long plugin version header,
 * a long custom role slug, a long server description) would otherwise reject
 * the ENTIRE payload server-side. WordPress core polyfills mb_substr()
 * (wp-includes/compat.php) so it always exists on a live site; the
 * function_exists fallback keeps the standalone test harness (no WP loaded)
 * honest too.
 *
 * @param mixed $value Value to stringify and clip.
 * @param int   $max   Contract max length for the field.
 * @return string The clipped string (may be '').
 */
function cinatra_inventory_clip( $value, int $max ): string {
	$str = is_scalar( $value ) ? (string) $value : '';
	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $str, 0, $max );
	}
	return substr( $str, 0, $max );
}

/**
 * Capture which WordPress user's Application Password most recently
 * authenticated a request against this site (D5). WordPress core fires this
 * action on EVERY successful Application-Password-authenticated request
 * (REST or XML-RPC), regardless of route.
 *
 * This plugin has no other way to identify "the connected Application-
 * Password's owning user" that connectedUserRole must report: the plugin's
 * own Connect handshake provisions the unrelated cnx_ credential and never
 * creates or reads a WordPress Application Password. In practice cinatra's
 * own MCP connection is normally the sole/primary consumer of this site's
 * Application-Password-authenticated REST surface, so the MOST RECENTLY
 * OBSERVED authentication is the best available proxy -- an honest,
 * best-effort signal (surfacing only, per the contract's own note: "never an
 * authorization input"), not a cryptographically verified binding to one
 * specific integration.
 *
 * @param mixed $user The authenticated user (a WP_User on success).
 * @param mixed $item Unused; the application-password record (uuid, name, ...).
 * @return void
 */
function cinatra_capture_connected_app_user( $user, $item ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $item is part of the core hook signature; only the authenticated user id is needed here.
	if ( ! ( $user instanceof WP_User ) || (int) $user->ID <= 0 ) {
		return;
	}
	update_option( 'cinatra_connected_app_user_id', (int) $user->ID );
}
add_action( 'application_password_did_authenticate', 'cinatra_capture_connected_app_user', 10, 2 );

/**
 * Resolve the role to report as site.connectedUserRole (D5/D6): the primary
 * role of the WordPress user whose Application Password most recently
 * authenticated a request (see cinatra_capture_connected_app_user() above) --
 * NOT simply whichever admin happens to be browsing wp-admin when the
 * enrichment payload is built.
 *
 * Falls back to the CURRENT request's user only when no Application-Password
 * authentication has ever been observed yet (e.g. immediately after Connect,
 * before cinatra core has made its first authenticated MCP call). This
 * fallback deliberately favors the fail-CLOSED direction over a synthetic
 * "safe-looking" placeholder: the Connect flow itself is gated on
 * manage_options, so the acting admin is often an Administrator, and
 * reporting their real role means the cinatra-side least-privilege warning
 * (D6/D8) is more likely to surface, never less. When there is truly no user
 * context at all (e.g. a WP-Cron run before any signal exists), "none" is
 * returned -- not a real WordPress role slug, so it can never be mistaken for
 * a verified non-administrator state.
 *
 * The CURRENT-user fallback is itself gated to manage_options (CodeRabbit
 * r3679186099, defense in depth): every caller of cinatra_send_site_inventory()
 * that can run with an authenticated request context is already capability-
 * gated to manage_options before it gets here -- trigger (a) is the Connect
 * callback, trigger (b) is cinatra_maybe_send_site_inventory_on_settings_load()
 * -- so this check should never actually flip the outcome for either of them.
 * It exists so this function never trusts "whoever the current user happens
 * to be" as a general-purpose signal: trigger (c), the daily WP-Cron run,
 * normally executes via an unauthenticated loopback request (no current user
 * at all), but a site running in WordPress's ALTERNATE_WP_CRON mode executes
 * scheduled hooks INLINE on an arbitrary front-end visitor's own request --
 * so without this gate, a logged-in low-privilege visitor's page load could
 * still leak their role into a report they never triggered on purpose. With
 * no manage_options holder as the current user, this reports "none", exactly
 * like the true no-signal case below.
 *
 * KNOWN LIMIT (unverified telemetry, disclosed): on a site where MORE THAN
 * ONE Application-Password client is active (a backup service, a mobile app,
 * another integration), the most-recently-observed user may belong to the
 * OTHER client -- including understating privilege (cinatra's connection
 * owned by an administrator while a lower-privilege client authenticated
 * last). The consumer treats this field as an unverified hint, never a
 * verified-safe signal: the contract marks it "surfacing only, never an
 * authorization input", and the core-side least-privilege card renders an
 * explicit unknown/hint state rather than trusting silence or a
 * non-administrator name as proof of safety.
 *
 * @return string The role slug to report (never empty; clipped to the
 *                contract's 64-char field bound).
 */
function cinatra_resolve_connected_user_role(): string {
	$captured_id = (int) get_option( 'cinatra_connected_app_user_id', 0 );
	if ( $captured_id > 0 ) {
		$captured = get_userdata( $captured_id );
		if ( $captured instanceof WP_User && ! empty( $captured->roles ) ) {
			$role = reset( $captured->roles );
			if ( is_string( $role ) && '' !== $role ) {
				return cinatra_inventory_clip( $role, 64 );
			}
		}
	}
	// cinatra_ensure_current_user_role() (ensure-panel, epsilon) already reads
	// the BROWSING user's primary role via wp_get_current_user() -- reused here
	// as the documented fallback rather than a second implementation of the
	// same read. Gated to manage_options -- see the docblock above -- so a
	// current user who could not plausibly be the connected admin is never
	// reported in their place.
	$current_role = current_user_can( 'manage_options' ) ? cinatra_ensure_current_user_role() : '';
	return '' !== $current_role ? cinatra_inventory_clip( $current_role, 64 ) : 'none';
}

/**
 * Map one live MCP Adapter server (\WP\MCP\Core\McpServer, duck-typed -- see
 * the note below) to the wp-site-inventory-v1 server-entry shape.
 *
 * TRANSPORT/AUTH INTROSPECTION LIMIT (grounded against the adapter's own
 * source, WordPress/mcp-adapter version 0.5.0: includes/Core/McpServer.php,
 * includes/Core/McpTransportFactory.php): neither class exposes a public
 * getter for a server's registered transport class list after construction
 * -- the class names are consumed transiently by initialize_transports() to
 * instantiate transport objects and are never retained -- and the adapter
 * exposes no site-authoritative "requires its own auth" flag either. Two
 * honest, documented, conservative choices follow from that real API gap:
 *   - transports: HttpTransport is the ONLY transport implementation the
 *     adapter ships today (includes/Transport/ contains just the one class),
 *     and it maps to this contract's "streamable-http" -- every discovered
 *     server is reported that way. A future adapter release shipping
 *     additional transport classes would need this revisited once a public
 *     introspection point exists.
 *   - requiresDedicatedAuth: a server registered with a NON-default
 *     transport permission callback (get_transport_permission_callback() !==
 *     null) has opted out of the adapter's own default is_user_logged_in()
 *     gate -- the closest observable proxy for "this server's auth model may
 *     not be the one cinatra's connection uses." Reporting present-not-
 *     enrolled in that case (rather than guessing auto-enrollable) is the
 *     safe direction: it never silently claims an auth model this plugin
 *     cannot verify.
 *
 * A malformed identity (adapterServerId/namespace/route outside the
 * contract's own grammar) is skipped -- returns null -- rather than sent: the
 * contract validates the WHOLE payload strictly, so one bad entry from an
 * oddly-configured third-party MCP server must never block every OTHER valid
 * server this site is reporting.
 *
 * $server is intentionally untyped (duck-typed via method_exists(), mirroring
 * cinatra_register_mcp_content_server()'s existing $adapter handling) so
 * tests can exercise this against a fixture object without the real
 * WordPress/mcp-adapter plugin installed.
 *
 * @param mixed $server An MCP Adapter server object from get_servers().
 * @return array<string,mixed>|null The mapped server entry, or null to skip it.
 */
function cinatra_map_adapter_server_to_inventory_entry( $server ): ?array {
	if ( ! is_object( $server )
		|| ! method_exists( $server, 'get_server_id' )
		|| ! method_exists( $server, 'get_server_route_namespace' )
		|| ! method_exists( $server, 'get_server_route' )
		|| ! method_exists( $server, 'get_server_name' )
	) {
		return null;
	}

	$server_id = $server->get_server_id();
	$namespace = $server->get_server_route_namespace();
	$route     = $server->get_server_route();

	// Identity fields are never clipped (clipping would corrupt identity) --
	// a non-scalar or grammar-violating value skips the entry outright.
	if ( ! is_scalar( $server_id ) || ! is_scalar( $namespace ) || ! is_scalar( $route ) ) {
		return null;
	}
	$server_id = (string) $server_id;
	$namespace = (string) $namespace;
	$route     = (string) $route;

	if ( ! preg_match( CINATRA_SITE_INVENTORY_SERVER_ID_PATTERN, $server_id )
		|| ! preg_match( CINATRA_SITE_INVENTORY_NAMESPACE_PATTERN, $namespace )
		|| ! preg_match( CINATRA_SITE_INVENTORY_ROUTE_PATTERN, $route )
	) {
		return null;
	}

	$rest_path = '/' . $namespace . '/' . $route;
	$name      = cinatra_inventory_clip( $server->get_server_name(), 200 );
	if ( '' === $name ) {
		$name = $server_id;
	}

	// Fail toward LESS trust on the unknown: a duck-typed object that does not
	// even expose the permission-callback getter cannot prove it uses the
	// adapter's default auth model, so it is reported as requiring dedicated
	// auth (core surfaces it present-not-enrolled instead of auto-enrolling).
	$requires_dedicated_auth = method_exists( $server, 'get_transport_permission_callback' )
		? ( null !== $server->get_transport_permission_callback() )
		: true;

	$tool_count = 0;
	if ( method_exists( $server, 'get_tools' ) ) {
		$tools      = $server->get_tools();
		$tool_count = is_array( $tools ) ? count( $tools ) : 0;
	}

	$entry = array(
		'adapterServerId'       => $server_id,
		'namespace'             => $namespace,
		'route'                 => $route,
		'restPath'              => $rest_path,
		'name'                  => $name,
		'transports'            => array( 'streamable-http' ),
		'requiresDedicatedAuth' => $requires_dedicated_auth,
		'isDefault'             => ( CINATRA_MCP_ADAPTER_DEFAULT_SERVER_REST_PATH === $rest_path ),
		'toolCount'             => $tool_count,
	);

	if ( method_exists( $server, 'get_server_description' ) ) {
		$description = cinatra_inventory_clip( $server->get_server_description(), 2000 );
		if ( '' !== $description ) {
			$entry['description'] = $description;
		}
	}
	if ( method_exists( $server, 'get_server_version' ) ) {
		$version = cinatra_inventory_clip( $server->get_server_version(), 64 );
		if ( '' !== $version ) {
			$entry['version'] = $version;
		}
	}

	return $entry;
}

/**
 * Enumerate every MCP server the live WordPress MCP Adapter registry reports
 * and map each to the wp-site-inventory-v1 server-entry shape (D5). Mirrors
 * cinatra_ensure_content_server_ability_count()'s defensive-read style
 * (autoload=false class check, method_exists guards, catch-all \Throwable) --
 * this reads a live third-party registry the plugin does not control, so it
 * must degrade to an empty list, never fatal, on any unexpected shape.
 *
 * @return array<int,array<string,mixed>> Mapped server entries (possibly empty).
 */
function cinatra_collect_adapter_server_inventory(): array {
	// Autoload=false: a presence CHECK, not a load request (same reasoning as
	// cinatra_ensure_content_server_ability_count()).
	if ( ! class_exists( '\WP\MCP\Core\McpAdapter', false ) ) {
		return array();
	}
	try {
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'get_servers' ) ) {
			return array();
		}
		$registry = $adapter->get_servers();
		if ( ! is_array( $registry ) ) {
			return array();
		}
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- defensive catch-all; an empty list is itself the fail-safe signal, nothing to log to without a logging dependency.
		return array();
	}

	$entries  = array();
	$seen_ids = array();
	foreach ( $registry as $server ) {
		// Per-entry containment: any single server object whose getter throws
		// (or returns an uncastable value) skips ONLY that entry -- one broken
		// third-party MCP server must never crash the admin page load / cron /
		// handshake this send piggybacks on ("silent no-op" is a hard claim).
		try {
			$entry = cinatra_map_adapter_server_to_inventory_entry( $server );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- defensive catch-all; skipping the one broken entry IS the handling.
			continue;
		}
		if ( null === $entry ) {
			continue;
		}
		// The contract rejects duplicate adapterServerId values payload-wide --
		// dedupe here (first entry wins) so one misbehaving registry can never
		// poison every OTHER server's enrollment. The real adapter registry is
		// keyed by server id (duplicates structurally impossible there); this
		// guards the duck-typed/defensive path.
		if ( isset( $seen_ids[ $entry['adapterServerId'] ] ) ) {
			continue;
		}
		$seen_ids[ $entry['adapterServerId'] ] = true;

		$entries[] = $entry;
		if ( count( $entries ) >= CINATRA_SITE_INVENTORY_MAX_SERVERS ) {
			break;
		}
	}
	return $entries;
}

/**
 * Build the v1 wp-site-inventory payload (D5, single shared builder for all
 * three triggers). Mirrors docs/internals/contracts/wp-site-inventory-
 * contract.md (cinatra repo) field-for-field; the STRICT schema
 * (wpSiteInventoryV1Schema) rejects unknown keys, so every key added here is
 * intentional and no extra key is ever included.
 *
 * The inventorySeq field is READ, never incremented, here --
 * cinatra_send_site_inventory() increments it once per actual SEND ATTEMPT
 * (contract-required monotonicity), immediately before calling this function
 * for a send that is actually going out.
 *
 * @return array<string,mixed> The v1 payload (unsigned; the caller adds auth headers).
 */
function cinatra_build_site_inventory_payload(): array {
	$adapter_state = cinatra_ensure_plugin_state( CINATRA_MCP_ADAPTER_PLUGIN_FILE );
	$catalog_state = cinatra_ensure_plugin_state( CINATRA_CATALOG_PLUGIN_FILE );

	$adapter_version = null;
	$servers         = array();
	if ( $adapter_state['active'] ) {
		// site.adapterVersion=null is the contract's "adapter absent" signal
		// (servers[] MUST then be empty) -- gate on ACTIVE, not merely
		// installed, since get_servers() can only ever be called when active.
		$adapter_version = ( '' !== $adapter_state['version'] ) ? cinatra_inventory_clip( $adapter_state['version'], 64 ) : 'unknown';
		$servers         = cinatra_collect_adapter_server_inventory();
	}

	$abilities_version = ( '' !== $catalog_state['version'] ) ? cinatra_inventory_clip( $catalog_state['version'], 64 ) : null;

	// Every bounded string field is clipped to the contract's own max (the
	// schema is STRICT: one overlong plugin-header/version/role value would
	// reject the ENTIRE payload). wpVersion also guards min(1) -- a broken
	// get_bloginfo() must not produce an empty required field.
	$wp_version = cinatra_inventory_clip( get_bloginfo( 'version' ), 32 );
	if ( '' === $wp_version ) {
		$wp_version = 'unknown';
	}

	$payload = array(
		'contractVersion' => CINATRA_SITE_INVENTORY_CONTRACT_VERSION,
		'client'          => CINATRA_CONNECT_CLIENT,
		'inventorySeq'    => (int) get_option( 'cinatra_inventory_seq', 0 ),
		'collectedAt'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'site'            => array(
			'wpVersion'              => $wp_version,
			'phpVersion'             => cinatra_inventory_clip( PHP_VERSION, 32 ),
			'adapterVersion'         => $adapter_version,
			'abilitiesPluginVersion' => $abilities_version,
			'connectedUserRole'      => cinatra_resolve_connected_user_role(),
			'permalinkStructure'     => ( '' !== (string) get_option( 'permalink_structure', '' ) ) ? 'pretty' : 'plain',
		),
		'servers'         => $servers,
	);

	// claimedInstanceId is optional (disambiguation only, per the contract) --
	// include it when this site already knows its host-issued instance id, so
	// the intake route need not rely solely on the Origin match.
	$instance_id = (string) get_option( 'cinatra_instance_id', '' );
	if ( '' !== $instance_id ) {
		$payload['claimedInstanceId'] = cinatra_inventory_clip( $instance_id, 128 );
	}

	return cinatra_fit_site_inventory_payload( $payload );
}

/**
 * Fit a built payload under the intake route's body cap (client-side half of
 * the contract's 256 KB limit). An over-cap send would be rejected WHOLE --
 * and at worst case (100 servers x a 2000-char description each) the encoded
 * payload genuinely can exceed the cap, so optional content is shed in
 * priority order until it fits: first every server's optional description
 * (the bulkiest optional field), then trailing server entries. Identity and
 * required fields are never touched -- a trimmed payload is still a valid,
 * honest (if less descriptive) inventory, which beats a rejected one.
 *
 * @param array<string,mixed> $payload A built payload.
 * @return array<string,mixed> The payload, trimmed if needed to fit the cap.
 */
function cinatra_fit_site_inventory_payload( array $payload ): array {
	$encoded = wp_json_encode( $payload );
	if ( is_string( $encoded ) && strlen( $encoded ) <= CINATRA_SITE_INVENTORY_MAX_BODY_BYTES ) {
		return $payload;
	}

	// Shed pass 1: drop every optional description.
	foreach ( $payload['servers'] as $i => $entry ) {
		unset( $payload['servers'][ $i ]['description'] );
	}
	$payload['servers'] = array_values( $payload['servers'] );
	$encoded            = wp_json_encode( $payload );
	if ( is_string( $encoded ) && strlen( $encoded ) <= CINATRA_SITE_INVENTORY_MAX_BODY_BYTES ) {
		return $payload;
	}

	// Shed pass 2: drop trailing server entries until the payload fits (the
	// loop always terminates -- an empty servers list plus the bounded site
	// block is far below the cap).
	while ( ! empty( $payload['servers'] ) ) {
		array_pop( $payload['servers'] );
		$encoded = wp_json_encode( $payload );
		if ( is_string( $encoded ) && strlen( $encoded ) <= CINATRA_SITE_INVENTORY_MAX_BODY_BYTES ) {
			break;
		}
	}
	return $payload;
}

/**
 * Stable hash of a built payload for the local "did anything change since the
 * last accepted send" gate (D5, trigger (b) only -- never consulted by the
 * unconditional handshake force-send or the always-send daily cron).
 * inventorySeq/collectedAt are excluded: both change on every build by
 * construction and would defeat the "nothing changed" comparison.
 *
 * @param array<string,mixed> $payload A built payload, as from cinatra_build_site_inventory_payload().
 * @return string A sha256 hex digest of the content-relevant fields.
 */
function cinatra_hash_site_inventory_payload( array $payload ): string {
	$stable = $payload;
	unset( $stable['inventorySeq'], $stable['collectedAt'] );
	return hash( 'sha256', (string) wp_json_encode( $stable ) );
}

/**
 * Send the current site inventory to the cinatra core intake route (D5).
 * Shared by all three triggers -- see the call sites (Connect-handshake
 * success, the Settings-page-load gate, and the daily WP-Cron fallback) for
 * WHEN this actually fires; callers gate BEFORE calling this, never after.
 *
 * The inventorySeq field is incremented ONCE per call to this function (one
 * increment per actual SEND ATTEMPT, contract-required monotonicity) --
 * never call this speculatively.
 *
 * Every failure mode (not connected, transport error, non-2xx including 429)
 * is a silent no-op: this never throws, never surfaces an admin notice, and
 * NEVER retries inline -- the next cadence tick (cron / page load / a future
 * handshake) naturally retries with a fresh, higher inventorySeq, which the
 * contract's monotonic-accept design always tolerates.
 *
 * @return void
 */
function cinatra_send_site_inventory(): void {
	$url     = rtrim( (string) get_option( 'cinatra_url', '' ), '/' );
	$api_key = (string) get_option( 'cinatra_api_key', '' );
	if ( '' === $url || '' === $api_key ) {
		return; // Not connected -- nothing to report.
	}

	// Best-effort concurrency guard (same transient-lock pattern as the
	// installer's double-click lock): two overlapping triggers -- e.g. the
	// Connect force-send racing an admin_init page load -- would otherwise
	// read-modify-write the seq/timestamp options concurrently and could
	// allocate the SAME inventorySeq twice (a classic lost update). A
	// contended caller simply skips: another send is in flight with fresher
	// state, and the next cadence tick covers anything it missed. DISCLOSED
	// RESIDUAL: get_transient+set_transient is check-then-set, not atomic
	// (WordPress has no portable atomic increment without direct SQL), so a
	// microsecond-window race remains -- it is FAIL-CLOSED end to end: the
	// intake route's atomic anti-replay gate rejects a duplicate seq as
	// stale (applying nothing), and the next tick resends with a fresh
	// higher seq. Same accepted tradeoff as the installer lock.
	if ( false !== get_transient( 'cinatra_inventory_send_lock' ) ) {
		return;
	}
	set_transient( 'cinatra_inventory_send_lock', 1, CINATRA_SITE_INVENTORY_SEND_LOCK_TTL );

	try {
		// One increment per ATTEMPT, before the network call, so a failed/timed-out
		// attempt still advances the counter AND opens the local debounce window --
		// this is what makes "don't retry inline" actually hold at the page-load
		// trigger, since cinatra_inventory_last_attempt is what that gate reads.
		update_option( 'cinatra_inventory_seq', (int) get_option( 'cinatra_inventory_seq', 0 ) + 1 );
		update_option( 'cinatra_inventory_last_attempt', time() );

		$payload = cinatra_build_site_inventory_payload();

		// Assert THIS site's own origin, exactly like the widget-auth relays and
		// the token broker (cinatra_rest_mint_token(), cinatra_rest_widget_auth_relay())
		// -- the instance's cnx_ arm requires a paired Origin === the credential's
		// bound connect-site origin and fails closed on a missing/mismatched one.
		$origin = cinatra_site_origin( admin_url() );
		if ( '' === $origin ) {
			return;
		}

		$server_base = cinatra_server_base_url( $url );
		$response    = cinatra_server_post(
			$server_base . CINATRA_SITE_INVENTORY_ENDPOINT_PATH,
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Origin'        => $origin,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[cinatra] site-inventory send failed (transport): ' . cinatra_scrub_secret( $response->get_error_message(), $api_key ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional fixed-scope server-side warning; the long-lived key is scrubbed and no payload/instance detail is logged.
			return;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			// Includes 429 (server-side debounce) and any 4xx/5xx -- logged for
			// diagnosis, NEVER retried inline; the next trigger naturally resends
			// with a fresh, higher inventorySeq. Never reflects the upstream body.
			error_log( '[cinatra] site-inventory send rejected: HTTP ' . $status ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional fixed-text + status-only server-side warning; never logs the payload or any upstream response body.
			return;
		}

		// Accepted -- persist the hash so the page-load gate (trigger b) can skip
		// an unchanged resend until something actually changes.
		update_option( 'cinatra_inventory_last_hash', cinatra_hash_site_inventory_payload( $payload ) );
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- defensive catch-all: the sender piggybacks on admin page loads / cron / the connect handshake and must NEVER fatal them; skipping the send IS the handling (next tick retries).
		return;
	} finally {
		delete_transient( 'cinatra_inventory_send_lock' );
	}
}

/**
 * D5 trigger (b): Settings -> Cinatra page-load gate. Sends ONLY when the
 * current user could actually load that settings page AND connected AND the
 * local debounce window has elapsed AND the freshly-built payload differs
 * from the last accepted one -- a normal admin browsing the settings page
 * repeatedly should almost never even reach the server-side 60s debounce.
 *
 * Capability-gated to manage_options (CodeRabbit r3679186099), matching
 * cinatra_render_settings_page()'s own gate and add_options_page()'s
 * registration: admin_init fires for EVERY authenticated admin-area request,
 * BEFORE this settings page's own capability check runs later in the request
 * lifecycle. Without this gate here, any logged-in low-privilege user
 * hitting admin.php?page=cinatra would (a) trigger an outbound inventory
 * POST they have no business triggering, and (b) -- via
 * cinatra_resolve_connected_user_role()'s browsing-user fallback -- have
 * their OWN role reported as the site's connectedUserRole, the opposite of
 * this feature's fail-closed intent.
 *
 * @return void
 */
function cinatra_maybe_send_site_inventory_on_settings_load(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen identification (which admin page loaded); no form processing, no state change.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( 'cinatra' !== $page ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return; // Same gate as the settings page itself (add_options_page() / cinatra_render_settings_page()).
	}

	$url     = (string) get_option( 'cinatra_url', '' );
	$api_key = (string) get_option( 'cinatra_api_key', '' );
	if ( '' === $url || '' === $api_key ) {
		return; // Not connected.
	}

	$last_attempt = (int) get_option( 'cinatra_inventory_last_attempt', 0 );
	$jitter       = wp_rand( 0, CINATRA_SITE_INVENTORY_JITTER_MAX_SECONDS );
	if ( time() - $last_attempt < ( CINATRA_SITE_INVENTORY_DEBOUNCE_SECONDS + $jitter ) ) {
		return; // The local debounce window (matching, not racing, the server's) hasn't elapsed.
	}

	// Build a throwaway payload ONLY to compare its hash -- no seq increment,
	// no network call, for a comparison that finds nothing changed. Same
	// silent-containment posture as the sender itself: this runs on an admin
	// page load and must NEVER fatal it -- on any runtime error, skip the
	// hash shortcut and let the (self-contained) sender decide.
	try {
		$candidate = cinatra_build_site_inventory_payload();
		if ( cinatra_hash_site_inventory_payload( $candidate ) === (string) get_option( 'cinatra_inventory_last_hash', '' ) ) {
			return; // Nothing changed since the last accepted send.
		}
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- defensive catch-all: an unhashable candidate just skips the local shortcut; the sender below contains its own failures.
		// Fall through to the sender, which is itself fully contained.
		unset( $e );
	}

	cinatra_send_site_inventory();
}
add_action( 'admin_init', 'cinatra_maybe_send_site_inventory_on_settings_load' );

/**
 * Ensure the daily site-inventory WP-Cron fallback (D5 trigger (c)) is
 * scheduled. Idempotent -- safe to call from both plugin activation and every
 * successful Connect (covers a site that connected before this cron existed,
 * or whose schedule was somehow lost).
 *
 * @return void
 */
function cinatra_ensure_site_inventory_cron_scheduled(): void {
	if ( ! wp_next_scheduled( CINATRA_SITE_INVENTORY_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'daily', CINATRA_SITE_INVENTORY_CRON_HOOK );
	}
}
register_activation_hook( __FILE__, 'cinatra_ensure_site_inventory_cron_scheduled' );

/**
 * Unschedule the site-inventory cron on deactivation -- a deactivated plugin
 * must not keep firing background sends.
 *
 * @return void
 */
function cinatra_unschedule_site_inventory_cron(): void {
	wp_clear_scheduled_hook( CINATRA_SITE_INVENTORY_CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'cinatra_unschedule_site_inventory_cron' );

// D5 trigger (c): daily fallback, unconditional (cinatra_send_site_inventory()
// itself is the single "not connected -> no-op" guard, matching every other
// trigger; drift coverage is the entire point of this trigger, so it carries
// no debounce/hash gate of its own).
add_action( CINATRA_SITE_INVENTORY_CRON_HOOK, 'cinatra_send_site_inventory' );
