---
slug: wordpress
title: WordPress integration overview
description: Embed a Cinatra AI editing assistant inside the WordPress admin so authors improve content where they already work.
navOrder: 1
tier: first-party
lifecycle: active
cinatraCompat: ">=1.2 <2"
integrationVersion: "0.1.3"
sourceRepo: https://github.com/cinatra-ai/wordpress-plugin
supportUrl: https://docs.cinatra.ai/resources/support/
marketplaceUrl: https://marketplace.cinatra.ai/extensions/wordpress
---

# WordPress integration overview

The Cinatra for WordPress plugin embeds a Cinatra AI assistant directly in your
WordPress admin, so authors and administrators can draft, rewrite, and improve
content in a chat panel right where they already work — no copy-pasting between
tabs, and no moving your content out of WordPress.

The assistant talks to *your own* Cinatra instance: you choose which instance to
connect, and the long-lived integration key stays on the server. The browser
only ever receives a short-lived, scope-bound stream token, so the credential
never reaches the page.

## What you get

- **An in-admin assistant.** A floating assistant button in `wp-admin` opens a
  chat panel on the post you are already editing, so you can tighten a lead, add
  a section, or fix metadata without leaving the editor.
- **A locally-served widget.** The assistant JavaScript ships with the plugin
  and is served from your site — no executable code is fetched from a remote
  server at runtime.
- **Server-held credentials.** A server-side endpoint exchanges your integration
  key for a short-lived stream token. The integration key never reaches the
  browser.
- **Outbound publish webhooks.** When a post is published, the plugin can sign
  and send a `post_published` notification to subscription targets you register.

## Who it is for

This is a **first-party** integration, built and supported by the Cinatra team
and shipped as a marketplace extension. Access inside WordPress is restricted to
administrators (the `manage_options` capability).

## Where to go next

- New here? Start with the [quick start](./quick-start.md) — you can finish setup
  without leaving that page.
- Already connected? See [use it](./use-it.md) for day-to-day editing.
- Locking things down? See [settings & permissions](./settings-and-permissions.md).
- For platform-wide material, see the canonical [Guides](/guides/) and
  [References](/references/) chapters on docs.cinatra.ai.
