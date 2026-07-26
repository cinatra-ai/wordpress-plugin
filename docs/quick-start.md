---
slug: wordpress
title: WordPress quick start
description: Install the plugin, connect it to your Cinatra instance, and open the assistant — all from this page.
navOrder: 2
tier: first-party
lifecycle: active
cinatraCompat: ">=1.2 <2"
integrationVersion: "0.1.3"
sourceRepo: https://github.com/cinatra-ai/wordpress-plugin
supportUrl: https://docs.cinatra.ai/resources/support/
marketplaceUrl: https://marketplace.cinatra.ai/extensions/wordpress
---

# WordPress quick start

This page takes you from nothing to a working assistant in your WordPress admin.
Follow it top to bottom — you can finish setup here without leaving the page.

## Before you start

You need:

- A WordPress site (5.9 or later) where you are an **administrator** (the
  `manage_options` capability).
- A **Cinatra instance** you can sign in to — your own self-hosted instance or a
  cloud instance.

## 1. Install the plugin

Install **Cinatra for WordPress**, then activate it:

- **From WordPress.org** (recommended): in `wp-admin`, go to
  **Plugins → Add New**, search for "Cinatra", install, and click **Activate**.
- **From a zip**: go to **Plugins → Add New → Upload Plugin**, upload the plugin
  zip, install, and **Activate**.

## 2. (Optional) Install the MCP Adapter for content-editing tools

The chat assistant works on its own. To let it *edit* content with AI tools, also
install the
[WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter/releases/latest):
download the latest release zip and install it from
**Plugins → Add New → Upload Plugin**. The **Settings → Cinatra** page shows a
status indicator for the adapter and links to the release if it is not active.

## 3. Connect to your Cinatra instance

1. In `wp-admin`, open **Settings → Cinatra**.
2. Enter your **Cinatra instance URL**.
3. Click **Connect with Cinatra**.
4. On the Cinatra consent screen, **approve** the connection.

That is it for credentials. The site exchanges an authorization code server-side
and stores the connection for you — no API key is copy-pasted, and nothing
secret is exposed to your browser. (If your environment cannot use one-click
Connect, the **Settings → Cinatra** page also has manual fields for the instance
URL, API key, and agent instance ID.)

## 4. Open the assistant

Open any post in the WordPress editor. Click the **floating Cinatra button** to
open the chat panel, and ask it to tighten a paragraph, add a section, or fix
metadata on the post you are editing.

You are done — you set up and used the integration without leaving this page.

## Next steps

- [Use it](./use-it.md) — what the assistant can do day to day.
- [Settings & permissions](./settings-and-permissions.md) — who can use it and
  what it is allowed to touch.
- [Troubleshooting](./troubleshooting.md) — if Connect or the assistant does not
  behave as expected.
