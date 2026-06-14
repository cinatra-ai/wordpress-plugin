<?php
/**
 * Uninstall handler for the Cinatra plugin.
 *
 * Runs only when the user deletes the plugin from WordPress (not on
 * deactivation). Removes every option the plugin created, including the
 * legacy cinatra_widget_* keys from the pre-rename plugin, on both single-site
 * and multisite installs. Also clears the short-lived connect transients.
 *
 * @package Cinatra
 */

// Exit if not called by WordPress during plugin uninstall.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Every option key this plugin (and its predecessor) may have created.
 */
function cinatra_uninstall_option_keys(): array {
    return [
        // Current keys.
        'cinatra_url',
        'cinatra_api_key',
        'cinatra_instance_id',
        'cinatra_webhook_secret',
        'cinatra_webhook_subscriptions',
        // Legacy keys from the pre-rename plugin (cinatra-widget.php).
        'cinatra_widget_url',
        'cinatra_widget_api_key',
        'cinatra_widget_instance_id',
    ];
}

/**
 * Delete all plugin options + transients in the current (or a switched) site
 * context.
 */
function cinatra_uninstall_cleanup_current_site(): void {
    foreach (cinatra_uninstall_option_keys() as $key) {
        delete_option($key);
    }

    // Per-user connect result + per-state connect transients. WordPress has no
    // wildcard transient delete, so sweep the options table for our prefixes.
    global $wpdb;
    $prefixes = [
        '_transient_cinatra_connect_result_',
        '_transient_timeout_cinatra_connect_result_',
        '_transient_cinatra_connect_state_',
        '_transient_timeout_cinatra_connect_state_',
    ];
    foreach ($prefixes as $prefix) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            )
        );
        if (is_array($names)) {
            foreach ($names as $option_name) {
                delete_option($option_name);
            }
        }
    }
}

if (is_multisite()) {
    // Multisite: clean each site, plus any network-level options.
    $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ((array) $site_ids as $site_id) {
        switch_to_blog((int) $site_id);
        cinatra_uninstall_cleanup_current_site();
        restore_current_blog();
    }
    foreach (cinatra_uninstall_option_keys() as $key) {
        delete_site_option($key);
    }
} else {
    cinatra_uninstall_cleanup_current_site();
}
