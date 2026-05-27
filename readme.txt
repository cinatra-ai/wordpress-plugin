=== Cinatra ===
Contributors: cinatra
Tags: ai, chat, assistant
Requires at least: 5.9
Tested up to: 6.8
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embeds the Cinatra AI assistant in your WordPress admin so editors can draft and revise content with an in-context chat assistant.

== Description ==
Embeds the Cinatra AI assistant chat widget in your WordPress admin area. Adds a floating button at bottom-right that opens a chat panel on click. Provides a webhook-subscription REST registry and stores an HMAC secret that Cinatra uses to sign webhook requests it sends to this site. The assistant talks to your Cinatra instance over HTTP only; this plugin bundles no Cinatra code.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/cinatra/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. (Recommended) Install and activate the WordPress MCP Adapter plugin for AI tool access.
4. Generate credentials in Cinatra at /settings/connectors/wordpress-widget.
5. Paste the Cinatra URL, API key, and webhook secret into Settings > Cinatra.

== Changelog ==
= 0.1.0 =
* Initial public release: floating button widget, webhook-subscription REST registry, settings page, mcp-adapter notice, versioned plugin↔core contract (contractVersion v1).
