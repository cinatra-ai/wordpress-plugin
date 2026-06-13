=== Cinatra ===
Contributors: cinatra
Tags: ai, chat, assistant
Requires at least: 5.9
Tested up to: 6.8
Stable tag: 0.2.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embeds the Cinatra AI assistant in your WordPress admin so editors can draft and revise content with an in-context chat assistant.

== Description ==
Embeds the Cinatra AI assistant chat widget in your WordPress admin area. Adds a floating button at bottom-right that opens a chat panel on click. The widget JavaScript is shipped inside this plugin and served locally — no executable code is fetched from a remote server. Provides a webhook-subscription REST registry and stores an HMAC secret that Cinatra uses to sign webhook requests it sends to this site.

The plugin treats your Cinatra instance purely as a data API. The long-lived integration key you configure is stored on your server and is never sent to the browser. When an editor uses the assistant, the plugin's own REST endpoint performs a server-to-server exchange with your Cinatra instance and hands the browser only a short-lived, single-purpose access token that expires within minutes.

== External services ==
This plugin connects to the Cinatra instance whose URL you configure in Settings > Cinatra (your own per-customer Cinatra deployment, e.g. https://app.cinatra.ai or a self-hosted instance). It is not a third-party SaaS the plugin chooses for you.

What is sent, and when:
* When an editor opens the assistant, the browser requests static capability/version metadata from your instance (no content, no credentials).
* When an editor sends a chat message, the plugin's server-side REST endpoint exchanges your configured integration key for a short-lived token (server-to-server; the key itself never reaches the browser), and the browser then streams the conversation — the message text plus the current post's id, type and status — directly to your instance to generate a reply.

The Cinatra instance is operated by you (or your Cinatra provider) under its own terms and privacy policy. Cinatra's terms and privacy information: https://cinatra.ai/legal/ and https://cinatra.ai/privacy/.

The widget also requests the Archivo brand font stylesheet from Google Fonts (https://fonts.googleapis.com) for its own chrome; this is best-effort and the widget falls back to your system font if it is blocked. Google Fonts terms: https://developers.google.com/fonts/faq and Google's privacy policy: https://policies.google.com/privacy.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/cinatra/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. (Recommended) Install and activate the WordPress MCP Adapter plugin for AI tool access.
4. Generate credentials in Cinatra at /settings/connectors/wordpress-widget.
5. Paste the Cinatra URL, API key, and webhook secret into Settings > Cinatra.

== Changelog ==
= 0.2.0 =
* Widget JavaScript is now shipped locally inside the plugin instead of being loaded from the Cinatra instance — no remote code execution in wp-admin.
* The long-lived integration key is no longer exposed to the browser. A new server-side REST endpoint (cinatra/v1/token, manage_options + nonce) exchanges it for a short-lived stream token; the browser streams with that token only.
* Capability/version negotiation at boot; contract bumped to v2 (the secret-free bundle config). Falls back to the long-lived flow against older instances.
* readme: added an External Services disclosure describing the data-only Cinatra API.
* Requires the matching Cinatra instance change (cinatra#220: token-exchange + capabilities endpoints). Against an un-upgraded instance the assistant degrades gracefully.
= 0.1.0 =
* Initial public release: floating button widget, webhook-subscription REST registry, settings page, mcp-adapter notice, versioned plugin↔core contract (contractVersion v1).
