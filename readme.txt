=== Cinatra ===
Contributors: ordnas
Tags: ai, chat, assistant
Requires at least: 5.9
Tested up to: 7.0
Stable tag: 0.1.4
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds an AI assistant to your WordPress admin, powered by your own Cinatra instance, so you can draft and improve content right where you work.

== Description ==
This plugin puts an AI assistant inside your WordPress admin. A small button sits in the bottom-right corner of wp-admin; click it to open a chat panel and ask the assistant to help you draft a post, rewrite a paragraph, tighten a headline, or answer a question while you work.

Because it runs through your own [Cinatra](https://cinatra.ai) instance, it isn't a generic writing tool. It can draw on what your Cinatra instance is set up to do, including your AI agents and the tools, data, and knowledge you have connected, and bring that capability straight into your CMS.

The plugin is built for safe connections: your long-lived integration key stays on the server and is never exposed to the browser — the assistant streams through a short-lived, site-bound token instead.

= WordPress AI tools (recommended companion) =
To let the assistant read and edit your WordPress content — drafting posts, updating pages, working with your media — install the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter/releases/latest) alongside this plugin and activate it. The adapter gives the assistant access to your site through the Model Context Protocol (MCP).

The MCP Adapter is distributed via GitHub Releases and is not in the wordpress.org directory; download the latest release ZIP and install it from the Plugins > Add New > Upload Plugin screen, just like any other plugin.

The Cinatra assistant chat widget works without the adapter — you can use it to ask questions and have conversations — but WordPress AI tools (reading and editing your content) require the adapter to be active. The plugin surfaces a clear setup notice and status indicator on its settings page so you always know whether AI tools are enabled.

== Installation ==
1. Install the plugin from the Plugins screen in WordPress, or upload the plugin folder and activate it from the Plugins menu.
2. Go to Settings → Cinatra.
3. Enter the web address (URL) of your Cinatra instance and click "Connect with Cinatra".
4. Approve the connection when Cinatra asks. Once your instance confirms it supports the assistant, the button appears in your admin. (Older or incompatible instances will not load the assistant.)
5. (Optional but recommended) To enable WordPress AI tools: download the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter/releases/latest) ZIP, install it from Plugins > Add New > Upload Plugin, and activate it. The Settings → Cinatra page shows a status indicator confirming whether AI tools are active.

== Frequently Asked Questions ==

= What does the assistant help me do? =
It's an AI assistant built right into the editor. It helps you draft, rewrite, shorten, retitle, and improve content and answer questions while you work. Because it runs through your own Cinatra instance, it isn't a generic writing tool — it can draw on what your instance is set up to do, including your AI agents and the tools, data, and knowledge you've connected, and bring that capability straight into your CMS.

= How do I connect it? =
Go to Settings → Cinatra, enter the web address of your Cinatra instance, click "Connect with Cinatra", and approve the connection.

= Who can use the assistant? =
Anyone who can manage your WordPress site (site administrators). The assistant and its settings are only shown to those users.

= Do I need a Cinatra account? =
You need access to a running Cinatra instance. Cinatra is an open source AI platform that you or your organisation host and connect the assistant to — learn more and get the source at https://www.cinatra.ai. Once your instance is running, open the Cinatra settings, enter the instance's web address, and connect.

= What is the WordPress MCP Adapter and do I need it? =
The WordPress MCP Adapter (available at https://github.com/WordPress/mcp-adapter) is a companion plugin that gives the Cinatra assistant access to your WordPress content via the Model Context Protocol (MCP). Without it, the assistant works as a general chat tool; with it, the assistant can read and edit your posts, pages, and other content directly.

It is not in the wordpress.org directory, so you install it by downloading the ZIP from GitHub and uploading it from the Plugins screen. The Cinatra settings page shows whether it is active and links to the download if it is not.

= Why isn't the WordPress MCP Adapter listed as a required plugin? =
WordPress supports plugin-to-plugin dependencies via the `Requires Plugins:` header, but only for plugins that are in the wordpress.org directory. The WordPress MCP Adapter is distributed via GitHub Releases, not the directory, so adding a hard dependency would break plugin review and give users no install link. The dependency is therefore declared as a formal soft dependency: the plugin detects the adapter's presence at runtime, gates the AI-tools path on it, and shows an actionable notice when it is absent.

== Screenshots ==
1. The Cinatra assistant in action — the chat panel open over the post editor, asked to rewrite the article's title. It shows a clear before-and-after of the headline change it applied.
2. Sign in with your Cinatra account. The assistant works with your own permissions, so you only do what you are already allowed to do on the site.
3. Connect the plugin to your Cinatra instance from the Cinatra settings page: enter your instance's web address and click the Connect with Cinatra button. No key is copied or pasted.

== Changelog ==
= 0.1.4 =
* Maintenance release tracking the Cinatra v0.1.4 milestone. Documentation and project-page improvements only (new docs/ guides for the Integrations hub, refreshed README); no change to the plugin code or behavior shipped to your site.

= 0.1.3 =
* New: when you publish a post, the plugin can notify your connected Cinatra instance so the assistant knows about your newly-published content. Turn it on by adding a "post_published" webhook in your Cinatra settings; the notification is signed and sent only to your configured Cinatra address.

= 0.1.2 =
* Formalize the dependency on the WordPress MCP Adapter for the AI-tools path (#62): the plugin now detects the adapter at runtime, surfaces a clear "AI tools setup" card with a status indicator and direct download link on the settings page, and passes a `mcpAdapterActive` flag to the widget so it can show a clear "install the adapter to enable tools" state rather than a silent absence. No `Requires Plugins:` header is added (the adapter is not on wordpress.org). readme.txt updated with installation instructions and an FAQ entry for the adapter.
* Fix a screenshot caption that broke the plugin page's image rendering.

= 0.1.1 =
* One-click "Connect with Cinatra": enter your instance's web address and approve a consent screen — no more copying and pasting a key.
* Safer connections: your long-lived integration key now stays on the server and is never exposed to the browser; the assistant streams through a short-lived, site-bound token.
* The assistant can use your WordPress AI tools through the chat when the companion WordPress MCP Adapter plugin is installed, so it can do more than plain text editing.
* More reliable connections to self-hosted Cinatra instances, including instances you run on your own infrastructure.
* The assistant now requires a Cinatra instance that supports the assistant connection; it no longer loads against older, incompatible instances.

= 0.1.0 =
* Initial release.
