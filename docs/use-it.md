---
slug: wordpress
title: Use the WordPress integration
description: Day-to-day editing with the in-admin assistant and the post-published webhook.
navOrder: 3
tier: first-party
lifecycle: active
cinatraCompat: ">=1.2 <2"
integrationVersion: "0.1.3"
sourceRepo: https://github.com/cinatra-ai/wordpress-plugin
supportUrl: https://docs.cinatra.ai/resources/support/
marketplaceUrl: https://marketplace.cinatra.ai/extensions/wordpress
---

# Use the WordPress integration

Once you have [connected the plugin](./quick-start.md), the assistant lives in
your WordPress admin and works on whatever you are editing.

## Edit content with the assistant

1. Open a post or page in the WordPress editor.
2. Click the **floating Cinatra button** to open the chat panel.
3. Ask for what you want in plain language, for example:
   - "Tighten the opening paragraph."
   - "Add a short section about pricing."
   - "Fix the meta description for SEO."
4. Review the assistant's suggestion before applying it. You stay in control —
   the assistant proposes; you decide what lands in the post.

The chat panel works as a conversation, so you can refine a request ("make it
shorter", "more formal") without starting over.

### Content-editing tools

For the assistant to *act on* content (not just suggest text in the chat), the
[WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) must be
installed and active. Without it, the assistant still answers in chat, but the
AI content-editing tools are unavailable. The **Settings → Cinatra** page shows
whether the adapter is active.

## Notify Cinatra when a post is published

The plugin can send an outbound, signed `post_published` webhook each time a post
is published, so a Cinatra agent or another system can react to new content.

- The plugin sends **outbound** signed webhooks only — it does not receive or
  verify inbound webhooks.
- Each notification is signed (Standard-Webhooks) with a per-site secret issued
  by your Cinatra instance during Connect, so the receiver can verify it came
  from your site.

You register and manage the subscriptions on the
[settings & permissions](./settings-and-permissions.md) page; the signing
credentials are provisioned automatically when you connect (or reconnect).

## Tips

- Keep requests scoped to the post you are editing — the assistant works best on
  the content in front of it.
- If a request does not land, check [troubleshooting](./troubleshooting.md) for
  the assistant, the connection, and the MCP Adapter status.
