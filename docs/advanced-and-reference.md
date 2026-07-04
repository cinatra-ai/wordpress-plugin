---
slug: wordpress
title: WordPress advanced and reference
description: Architecture, the plugin–core contract, the REST surface, and links out to the canonical platform reference.
navOrder: 6
tier: first-party
lifecycle: active
cinatraCompat: ">=1.2 <2"
integrationVersion: "0.1.3"
sourceRepo: https://github.com/cinatra-ai/wordpress-plugin
supportUrl: https://docs.cinatra.ai/resources/support/
marketplaceUrl: https://marketplace.cinatra.ai/extensions/wordpress
---

# WordPress advanced and reference

This page covers the architecture and the moving parts behind the integration,
and links out to the canonical platform reference rather than duplicating it.

## How the credential path works

The plugin keeps the long-lived integration key on the server. A server-side
REST endpoint performs a server-to-server exchange with your Cinatra instance and
hands the browser only a short-lived, scope-bound stream token. That token, not
the integration key, is what the in-browser assistant uses — so the key never
reaches the page.

One-click **Connect with Cinatra** uses an authorization-code exchange with PKCE
(S256): the admin approves a consent screen, the site exchanges the code
server-side, and the resulting credential is stored server-side. A
connection-string (install-code) fallback is available for environments where the
redirect flow is not usable.

## The plugin–core contract

At boot, the widget negotiates a contract version with the instance by calling
the capabilities endpoint. The widget understands both the current and previous
contract versions; the instance advertises what it supports, and the widget picks
the newest mutually-supported version. Server-side token exchange is always
required — an instance that cannot mint short-lived tokens, or that offers no
mutually-supported version, causes the widget to show fallback chrome instead of
mounting. This is why the integration degrades gracefully against an un-upgraded
instance.

## REST surface (high level)

The plugin registers admin-gated REST endpoints under `wp-json/cinatra/v1/`:

- a **token** endpoint that performs the server-to-server exchange and returns a
  short-lived stream token (gated to administrators plus a request nonce), and
- a **webhooks** registry that stores the subscriptions enabling outbound
  `post_published` notifications. The notifications are signed
  (Standard-Webhooks) with per-site credentials issued by the connected Cinatra
  instance during Connect.

## Compatibility

- **WordPress:** 5.9 or later (tested up to 7.0).
- **PHP:** 7.4 or later.
- **Cinatra instance:** an instance that supports server-side token exchange and
  a mutually-supported assistant contract version.

## Reference and source

- **Source code, issues, and release notes:** the
  [plugin source repository](https://github.com/cinatra-ai/wordpress-plugin).
- **Marketplace listing:** the
  [Cinatra Marketplace](https://marketplace.cinatra.ai/extensions/wordpress).
- **Platform reference** (permissions, agents, the platform APIs): the canonical
  [References](/references/) chapter on docs.cinatra.ai. The WordPress
  integration does not duplicate that material here.
- **Managing any marketplace extension** (install, update, trust, remove):
  [Install & manage any marketplace extension](/integrations/install-and-manage-marketplace-extensions/).
