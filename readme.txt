=== Cinatra ===
Contributors: ordnas
Tags: ai, chat, assistant
Requires at least: 5.9
Tested up to: 7.0
Stable tag: 0.1.2
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds an AI assistant to your WordPress admin, powered by your own Cinatra instance, so you can draft and improve content right where you work.

== Description ==
This plugin puts an AI assistant inside your WordPress admin. A small button sits in the bottom-right corner of wp-admin; click it to open a chat panel and ask the assistant to help you draft a post, rewrite a paragraph, tighten a headline, or answer a question while you work.

Because it runs through your own [Cinatra](https://cinatra.ai) instance, it isn't a generic writing tool. It can draw on what your Cinatra instance is set up to do, including your AI agents and the tools, data, and knowledge you have connected, and bring that capability straight into your CMS. (For the assistant to use WordPress AI tools, also install the companion WordPress MCP Adapter plugin; the assistant still works without it.)

The plugin is built for safe connections: your long-lived integration key stays on the server and is never exposed to the browser — the assistant streams through a short-lived, site-bound token instead.

== Installation ==
1. Install the plugin from the Plugins screen in WordPress, or upload the plugin folder and activate it from the Plugins menu.
2. Go to Settings → Cinatra.
3. Enter the web address (URL) of your Cinatra instance and click "Connect with Cinatra".
4. Approve the connection when Cinatra asks. Once your instance confirms it supports the assistant, the button appears in your admin. (Older or incompatible instances will not load the assistant.)

== Frequently Asked Questions ==

= What does the assistant help me do? =
It's an AI assistant built right into the editor. It helps you draft, rewrite, shorten, retitle, and improve content and answer questions while you work. Because it runs through your own Cinatra instance, it isn't a generic writing tool — it can draw on what your instance is set up to do, including your AI agents and the tools, data, and knowledge you've connected, and bring that capability straight into your CMS.

= How do I connect it? =
Go to Settings → Cinatra, enter the web address of your Cinatra instance, click "Connect with Cinatra", and approve the connection.

= Who can use the assistant? =
Anyone who can manage your WordPress site (site administrators). The assistant and its settings are only shown to those users.

= Do I need a Cinatra account? =
You need access to a running Cinatra instance. Cinatra is an open source AI platform that you or your organisation host and connect the assistant to — learn more and get the source at https://www.cinatra.ai. Once your instance is running, open the Cinatra settings, enter the instance's web address, and connect.

== Screenshots ==
1. The Cinatra assistant in action — the chat panel open over the post editor, asked to rewrite the article's title. It shows a clear before-and-after of the headline change it applied.
2. Sign in with your Cinatra account. The assistant works with your own permissions, so you only do what you are already allowed to do on the site.
3. Connect the plugin to your Cinatra instance from the Cinatra settings page: enter your instance's web address and click the Connect with Cinatra button. No key is copied or pasted.

== Changelog ==
= 0.1.2 =
* Fix a screenshot caption that broke the plugin page's image rendering.

= 0.1.1 =
* One-click "Connect with Cinatra": enter your instance's web address and approve a consent screen — no more copying and pasting a key.
* Safer connections: your long-lived integration key now stays on the server and is never exposed to the browser; the assistant streams through a short-lived, site-bound token.
* The assistant can use your WordPress AI tools through the chat when the companion WordPress MCP Adapter plugin is installed, so it can do more than plain text editing.
* More reliable connections to self-hosted Cinatra instances, including instances you run on your own infrastructure.
* The assistant now requires a Cinatra instance that supports the assistant connection; it no longer loads against older, incompatible instances.

= 0.1.0 =
* Initial release.
