=== Cinatra ===
Contributors: cinatra
Tags: ai, chat, assistant
Requires at least: 5.9
Tested up to: 7.0
Stable tag: 0.2.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embeds the Cinatra AI assistant in your WordPress admin so administrators can draft and revise content with an in-context chat assistant.

== Description ==
Embeds the Cinatra AI assistant chat widget in your WordPress admin area. Adds a floating button at bottom-right that opens a chat panel on click. The widget JavaScript is shipped inside this plugin and served locally — no executable code is fetched from a remote server.

The plugin also maintains a webhook-subscription registry (a small REST endpoint that stores subscription records) and lets you store a shared webhook secret. The secret is shared with your Cinatra instance so Cinatra can sign the requests it sends; this plugin only stores the value and the subscription registry — it does not receive or verify inbound signed webhooks itself.

The plugin treats your Cinatra instance purely as a data API. The long-lived integration credential is stored on your server and is never sent to the browser. When an administrator uses the assistant, the plugin's own REST endpoint performs a server-to-server exchange with your Cinatra instance and hands the browser only a short-lived, single-purpose access token that expires within minutes.

= Connecting =
You can connect in one click: enter your Cinatra instance URL on the Settings → Cinatra page and click "Connect with Cinatra". You are sent to Cinatra to approve the connection, and the integration credential is then provisioned automatically and stored on your server — you never copy or paste a key. If a browser redirect is not possible, paste the one-time connection string Cinatra gives you instead. Manual fields remain available for advanced setups.

= Who can use it =
All of the plugin's surface (the settings page, the assistant widget, and the REST endpoints) requires the `manage_options` capability — that is, WordPress administrators.

== External services ==
This plugin connects to the Cinatra instance whose URL you configure in Settings → Cinatra (your own per-customer Cinatra deployment, e.g. https://app.cinatra.ai or a self-hosted instance). It is not a third-party SaaS the plugin chooses for you.

What is sent, and when:
* When you click "Connect with Cinatra", your browser is redirected to your instance's consent page and your site then performs a server-to-server code exchange to receive the integration credential. No credential is exposed to the browser.
* When an administrator opens the assistant, the browser requests static capability/version metadata from your instance (no content, no credentials).
* When an administrator sends a chat message, the plugin's server-side REST endpoint exchanges your stored integration credential for a short-lived token (server-to-server; the credential itself never reaches the browser), and the browser then streams the conversation — the message text plus the current post's id, type and status — directly to your instance to generate a reply.

The Cinatra instance is operated by you (or your Cinatra provider) under its own terms and privacy policy. Cinatra's terms and privacy information: https://cinatra.ai/legal/ and https://cinatra.ai/privacy/.

The widget also requests the Archivo brand font stylesheet from Google Fonts (https://fonts.googleapis.com) for its own chrome; this is best-effort and the widget falls back to your system font if it is blocked. Google Fonts terms: https://developers.google.com/fonts/faq and Google's privacy policy: https://policies.google.com/privacy.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/cinatra/`, or install it from the Plugins screen.
2. Activate the plugin through the Plugins menu in WordPress.
3. (Recommended) Install and activate the WordPress MCP Adapter plugin for AI tool access.
4. Go to Settings → Cinatra, enter your Cinatra instance URL, and click "Connect with Cinatra".
5. Approve the connection on the Cinatra consent screen. The credential is provisioned and stored automatically.

== Frequently Asked Questions ==

= What is a Cinatra instance? =
Cinatra is a self-hosted AI platform. You bring your own backend: the plugin connects to the Cinatra instance whose URL you configure. There is no central SaaS endpoint chosen for you.

= Who can use the assistant? =
Only WordPress administrators. The settings page, the assistant widget, and the plugin's REST endpoints all require the `manage_options` capability.

= What data leaves my site? =
See the "External services" section above. In short: capability/version metadata, the chat message text, and the current post's id/type/status are sent to your configured Cinatra instance. Your integration credential is held server-side and is never sent to the browser.

= Is the WordPress MCP Adapter plugin required? =
No. It is recommended because it enables AI tool access from the chat widget, but the assistant works without it.

= Where does the widget JavaScript come from? =
It ships inside this plugin and is served locally from your own site. No executable code is fetched from a remote server.

= How do I disconnect / remove my data? =
Deleting the plugin runs its uninstall routine, which removes all of the plugin's stored options (the instance URL, credential, instance ID, webhook secret, and subscription registry) on both single-site and multisite installs.

== Screenshots ==
1. The Settings → Cinatra page with one-click "Connect with Cinatra" and the manual/advanced configuration fields.
2. The floating Cinatra assistant button at the bottom-right of wp-admin.
3. The assistant chat panel open, ready to draft or revise content.

== Upgrade Notice ==
= 0.2.0 =
Widget JavaScript now ships locally (no remote code), the long-lived credential stays server-side behind a short-lived-token broker, and one-click "Connect with Cinatra" replaces manual key entry. Requires the matching Cinatra instance update.

== Changelog ==
= 0.2.0 =
* Widget JavaScript is now shipped locally inside the plugin instead of being loaded from the Cinatra instance — no remote code execution in wp-admin.
* The long-lived integration credential is no longer exposed to the browser. A new server-side REST endpoint (cinatra/v1/token, manage_options + nonce) exchanges it for a short-lived stream token; the browser streams with that token only.
* Added one-click "Connect with Cinatra" provisioning (server-side code exchange; connection-string fallback). The manual key field is no longer required.
* Capability/version negotiation at boot; contract bumped to v2 (the secret-free bundle config). Falls back to the long-lived flow against older instances.
* Hardened settings sanitization, output escaping, and REST validation; admin notices scoped to the settings screen; secret fields no longer prefill into the DOM.
* Added `uninstall.php` (removes all plugin options on single-site and multisite) and a Text Domain for translation.
* readme: added External Services disclosure, FAQ, Screenshots, Upgrade Notice, and a bundled-code licensing note.
* Requires the matching Cinatra instance change (cinatra#220 / cinatra#221: token-exchange + capabilities + connect endpoints). Against an un-upgraded instance the assistant degrades gracefully.
= 0.1.0 =
* Initial public release: floating button widget, webhook-subscription REST registry, settings page, mcp-adapter notice, versioned plugin↔core contract (contractVersion v1).

== License & bundled code ==
This plugin is licensed GPL-2.0-or-later. It bundles `assets/cinatra-widget.js`, a human-readable copy of the Cinatra widget originally licensed Apache-2.0 (cinatra-ai/cinatra). Apache-2.0 is compatible with the GPL via the GPLv3 ("or-later") route, so the combined work is distributed as GPL v3 or later for that component. The Apache-2.0 NOTICE is preserved in the file header and in the bundled NOTICE file. See LICENSE and NOTICE.
