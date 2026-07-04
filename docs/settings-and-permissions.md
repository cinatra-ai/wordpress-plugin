---
slug: wordpress
title: WordPress settings and permissions
description: Configure the connection, webhooks, and the access model that controls who can use the assistant and what it can touch.
navOrder: 4
tier: first-party
lifecycle: active
cinatraCompat: ">=1.2 <2"
integrationVersion: "0.1.3"
sourceRepo: https://github.com/cinatra-ai/wordpress-plugin
supportUrl: https://docs.cinatra.ai/resources/support/
marketplaceUrl: https://marketplace.cinatra.ai/extensions/wordpress
---

# WordPress settings and permissions

All configuration lives on the **Settings → Cinatra** admin page in `wp-admin`.

## Settings

### Connection

- **Connect with Cinatra (recommended).** Enter your Cinatra instance URL and
  click **Connect with Cinatra**, then approve the consent screen. The
  connection is provisioned and stored server-side automatically.
- **Manual / advanced fields.** For environments that cannot use one-click
  Connect, the page exposes manual fields for the Cinatra instance URL, the API
  key, and the agent instance ID.

### Webhooks

- **Signing credentials.** Outbound `post_published` notifications are signed
  (Standard-Webhooks) with a secret and webhook binding id issued by your
  Cinatra instance during **Connect** — there is nothing to paste manually. The
  page shows whether they are provisioned; if not (for example after updating
  the plugin on an existing connection), reconnect once to provision them.
- **Subscription targets.** Register the subscriptions that enable a signed
  `post_published` notification when a post is published. Delivery always goes
  to your connected Cinatra instance.
- Changing the Cinatra URL or the agent instance ID invalidates the signing
  credentials (they belong to the previous instance); reconnect to re-provision
  them.

### MCP Adapter status

The page shows a status indicator for the
[WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) and links to
its release if it is not active. The adapter is required only for AI
content-editing tools.

## Permissions

### Who can use the integration in WordPress

The assistant and the **Settings → Cinatra** page are restricted to WordPress
**administrators** — the `manage_options` capability. Non-administrators do not
see the assistant button or the settings page. Manage WordPress capabilities
with your site's normal roles and capabilities administration.

### What the integration can do in Cinatra

What the assistant can do is governed by the credential you connect and the
permissions you grant it in your Cinatra instance, through the same access model
as the rest of the platform. Grant the narrowest set that lets the assistant do
its job, and review the requested capabilities before you connect. For the
platform's permission model, see the canonical
[References](/references/) chapter on docs.cinatra.ai.

## Trust and credential handling

- **The integration key stays on the server.** A server-side REST endpoint
  performs a server-to-server exchange with your instance and hands the browser
  only a short-lived, scope-bound stream token. The integration key never
  reaches the browser.
- **The widget is served locally.** The assistant JavaScript ships with the
  plugin and is served from your own site — no executable code is fetched from a
  remote server at runtime.
- **Outbound webhooks are signed.** `post_published` notifications are signed
  (Standard-Webhooks) with a per-site secret issued by your Cinatra instance so
  the receiver can verify their origin. The plugin never receives or verifies
  inbound webhooks.

For installing, updating, granting permissions to, and removing marketplace
extensions in general, see
[Install & manage any marketplace extension](/integrations/install-and-manage-marketplace-extensions/).
