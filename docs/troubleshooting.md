---
slug: wordpress
title: WordPress troubleshooting
description: Diagnose and fix the most common WordPress integration issues — connection, the assistant, content tools, and webhooks.
navOrder: 5
tier: first-party
lifecycle: active
cinatraCompat: ">=1.2 <2"
integrationVersion: "0.1.3"
sourceRepo: https://github.com/cinatra-ai/wordpress-plugin
supportUrl: https://docs.cinatra.ai/resources/support/
marketplaceUrl: https://marketplace.cinatra.ai/extensions/wordpress
---

# WordPress troubleshooting

If something is not working, find the closest symptom below. If none of these
resolve it, [contact support](https://docs.cinatra.ai/resources/support/).

## Connect fails or the assistant shows fallback chrome

- **Symptom:** Connecting fails, or the assistant panel shows a fallback message
  instead of the chat.
- **Cause:** The instance URL is wrong, the instance is unreachable, or the
  instance cannot mint short-lived tokens / has no mutually-supported contract
  version. The plugin requires server-side token exchange — there is no
  long-lived-key fallback.
- **Fix:** Re-check the **Cinatra instance URL** on **Settings → Cinatra**,
  confirm the instance is reachable from your site, and confirm the instance has
  the matching token-exchange and capabilities endpoints deployed. Then run
  **Connect with Cinatra** again.

## The assistant button does not appear

- **Symptom:** No floating Cinatra button in `wp-admin`.
- **Cause:** Your user is not a WordPress administrator, or the plugin is not
  active.
- **Fix:** Confirm the plugin is **active** under **Plugins**, and confirm your
  user has the `manage_options` capability — the assistant is administrator-only.

## AI content-editing tools are missing

- **Symptom:** The assistant answers in chat but cannot edit content with tools.
- **Cause:** The [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter)
  is not installed or not active.
- **Fix:** Install and activate the adapter, then re-check the adapter status
  indicator on **Settings → Cinatra**.

## Publish notifications are not arriving

- **Symptom:** A subscription target does not receive a `post_published`
  notification.
- **Cause:** No subscription target is registered for that case, the shared
  webhook secret is missing, or the receiver rejects the signature.
- **Fix:** On [settings & permissions](./settings-and-permissions.md), confirm a
  subscription target is registered and the **webhook secret** is set, and verify
  the receiver checks the signature with the same secret. The plugin sends
  outbound signed webhooks only.

## Still stuck?

- Re-read the [quick start](./quick-start.md) to confirm each setup step.
- Check the WordPress plugin/PHP error log for plugin messages.
- [Contact support](https://docs.cinatra.ai/resources/support/) with the symptom,
  your WordPress version, and the plugin version.
