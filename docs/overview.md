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
connect, and the site's integration key stays on the server. Your own sign-in is
separate from the site's: you sign in inside the Cinatra assistant window, and
the WordPress page never receives or holds your credential.

## What you get

- **An in-admin assistant.** A floating assistant button in `wp-admin` opens a
  chat panel on the post you are already editing, so you can tighten a lead, add
  a section, or fix metadata without leaving the editor.
- **A locally-served widget.** The assistant JavaScript ships with the plugin
  and is served from your site — no executable code is fetched from a remote
  server at runtime.
- **Credentials the page never touches.** The site's integration key stays on the
  server and is used only for server-to-server calls. Your sign-in belongs to you
  and Cinatra: it happens inside the assistant window, and the page is not a
  party to it.
- **Outbound publish webhooks.** When a post is published, the plugin can sign
  (Standard-Webhooks) and send a `post_published` notification to your connected
  Cinatra instance, using credentials the instance issues during Connect.

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
