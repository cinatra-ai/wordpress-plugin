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
  instance is older than the version-2 embed protocol this plugin speaks. There
  is no fallback to an older protocol, on purpose: the older one required the
  site to handle your sign-in credential.
- **Fix:** Re-check the **Cinatra instance URL** on **Settings → Cinatra**,
  confirm the instance is reachable from your site, and update the instance if it
  predates the version-2 embed protocol. Then run **Connect with Cinatra** again.

## The assistant asks me to sign in every time

- **Symptom:** The assistant panel shows a sign-in prompt after a reload, or on
  each new browser session.
- **Cause:** This is expected. Your sign-in is held by the Cinatra assistant
  window, not by the WordPress page, and it is short-lived on purpose. The site
  cannot keep it for you, which is exactly why it cannot leak it either.
- **Fix:** Sign in again in the window that opens. If you already have a Cinatra
  session in that browser, you are returned straight away without typing.

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
- **Cause:** No subscription is registered for that case, or the site has no
  server-issued webhook signing credentials (for example the plugin was updated
  on an existing connection, or the Cinatra URL / instance ID changed).
- **Fix:** On [settings & permissions](./settings-and-permissions.md), confirm a
  subscription is registered and the **Publish Webhooks** status shows
  provisioned; if it does not, reconnect the site to your Cinatra instance
  ("Connect with Cinatra") to provision the credentials. The plugin sends
  outbound signed webhooks only.

## Still stuck?

- Re-read the [quick start](./quick-start.md) to confirm each setup step.
- Check the WordPress plugin/PHP error log for plugin messages.
- [Contact support](https://docs.cinatra.ai/resources/support/) with the symptom,
  your WordPress version, and the plugin version.
