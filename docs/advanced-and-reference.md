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

There are two credentials, and the site holds exactly one of them.

**The site credential** is issued once, when you connect. One-click **Connect
with Cinatra** uses an authorization-code exchange with PKCE (S256): an
administrator approves a consent screen, the site exchanges the code
server-side, and the credential is stored server-side. A connection-string
(install-code) fallback is available where the redirect flow is not usable. This
credential identifies *the site* to your instance. It stays on your server. It is
used only for server-to-server calls — the connection itself, the site-inventory
handshake, and signed publish notifications — and it never reaches the browser.

**Your sign-in credential** is not the site's at all. When you use the assistant,
you sign in to Cinatra inside the Cinatra window, and the result goes back to
Cinatra and stops there. The WordPress page does not start that sign-in, does not
receive anything from it, and holds nothing afterwards. Earlier versions worked
differently: the site's own server started the sign-in, received your credential
back, and passed it into the assistant window. That is over — the site is no
longer a party to your sign-in.

What the page does hand the assistant window is a short list of **public
selectors**: which instance to talk to, which site this is, and which post is
open. None of them is a secret, and none of them grants anything: your instance
checks each one against its own records and refuses anything that does not match.

## The plugin–core contract

The assistant conversation renders inside a sandboxed, Cinatra-served
`/embed/assistant` iframe, which owns the session. The AG-UI capability/contract
handshake runs client-side **inside that iframe** against the unified assistant
broker (`GET /api/assistants/chat/capabilities`), and the conversational wire is
`POST /api/assistants/chat`. The WordPress side of the embed protocol is at
**version 2**: it sends one message carrying public selectors only, and it
deliberately cannot talk to a version-1 instance. Requires an instance that
serves the version-2 embed protocol; earlier instances are not supported. The
legacy `/api/agents/{slug}/capabilities` negotiation and `/api/agents/{slug}/stream`
relay were retired.

## REST surface (high level)

The plugin registers one admin-gated REST endpoint under `wp-json/cinatra/v1/`:

- a **webhooks** registry that stores the subscriptions enabling outbound
  `post_published` notifications. The notifications are signed
  (Standard-Webhooks) with per-site credentials issued by the connected Cinatra
  instance during Connect.

The earlier session-token and sign-in endpoints are removed. They existed to
fetch a credential for the browser, and the browser no longer takes one.

## Content abilities

When the WordPress Abilities API (WordPress 6.9 or later) is present, the
plugin registers the ability `cinatra/post-read` in the `cinatra` category.
The MCP Adapter exposes it as the MCP tool `cinatra-post-read`. Without the
Abilities API, the plugin registers nothing and works as before. The ability
does nothing until a caller invokes it.

**`cinatra/post-read`** (read-only). Input: `post_id`. Output: the raw stored
`post_title`, `post_content`, `post_excerpt`, `post_status`, `post_type`,
`post_modified_gmt` and `permalink` of a post or page, and `row_token`. The
values come directly from the database, not from a cache. The permalink is
computed from the row that was read, not by reading the post again.

`row_token` is a fingerprint of every column of the post's row in the posts
table, computed from the same row that the read returns. It is the SHA-256
hash, as 64 lower-case hexadecimal characters, of this encoding: the ASCII text
`cinatra-post-row-v1`, then each column in byte order of its name, as a 4-byte
big-endian length of the name, the name, then the byte `0x00` for NULL, or the
byte `0x01`, a 4-byte big-endian length of the value and the value as stored.
The token covers the posts row only. Post meta and terms (categories, tags)
are not in the row, so the token does not show changes to them.

The caller must be logged in and have the `edit_post` capability for the post.
A post that does not exist, a post that is not a post or a page, and a post
that the caller may not edit all give the same answer, `not_found`, so the
answer does not show whether the post exists. A failed database read gives
`db_error`. A refusal is a normal result, `{success: false, code, message,
data}`, and never contains post content.

## Compatibility

- **WordPress:** 5.9 or later (tested up to 7.1).
- **PHP:** 7.4 or later.
- **Cinatra instance:** an instance that serves the version-2 embed protocol and
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
