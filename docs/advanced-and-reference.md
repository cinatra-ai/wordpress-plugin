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
plugin registers two abilities in the `cinatra` category. The MCP Adapter
exposes them as the MCP tools `cinatra-post-read` and
`cinatra-post-update-if-unchanged`. Without the Abilities API, the plugin
registers nothing and works as before. The abilities do nothing until a caller
invokes them.

**`cinatra/post-read`** (read-only). Input: `post_id`. Output: the raw stored
`post_title`, `post_content`, `post_excerpt`, `post_status`, `post_type`,
`post_modified_gmt` and `permalink` of a post or page. The values come directly
from the database, not from a cache.

**`cinatra/post-update-if-unchanged`**. Input: `post_id`,
`if_unmodified_since` (the `post_modified_gmt` of the read that the change is
based on), `expected` (the post status that was read, and the before-value of
each field that is written), and one or more of `title`, `content`, `excerpt`
and `status`. In one database transaction, the ability locks the post row,
compares `post_modified_gmt`, the post status and each `expected` value byte
for byte, and writes only when all of them are equal. If another save occurs between the read and the write, the write is
refused and nothing is written. The byte comparison also finds a second save in
the same second.

- `expected.status` is always required. It is the `post_status` from the read,
  and it is always compared, also when the status is not written. The status
  is part of what the review saw: an edit to a published page is live
  immediately.
- Each written `title`, `content` or `excerpt` must have an `expected` entry.
  An `expected` entry for one of these fields that is not written is refused.
- A status-only change sends, for example,
  `"expected": { "status": "publish" }, "status": "draft"`.
- `status` may only be `"draft"`. It moves the post to draft in the same save,
  for example when a review says to unpublish a published page. If `status` is
  not given, the status does not change. Thus an edit to a published page is
  live immediately. The ability never publishes.
- The ability applies the same filters that a normal save by that user
  applies. For users without the `unfiltered_html` capability, WordPress
  removes disallowed HTML. Thus the stored value can be different from the sent
  value. The answer contains the stored values, the new `post_modified_gmt`,
  and `sanitized_fields`, which names each field that a filter changed.
- The ability saves the full row that it read under the lock, with the written
  fields on top. A copy of the post in the object cache cannot write an old
  value back.
- The posts table must use the InnoDB storage engine, because other engines
  cannot lock a row. Each write reads the engine again inside its transaction,
  after the row lock. On other engines, the write is refused.
- `wp_update_post()` runs other plugins' save hooks inside the transaction. A
  plugin that writes through a different database connection is not part of
  the transaction.

Both abilities require the `edit_post` capability for the specific post. An
unknown post, a post that is not a post or a page, and a post that the user
cannot edit give the same `not_found` answer.

A refusal is a normal result, not an error:
`{ "success": false, "code": "...", "message": "...", "data": { "current_modified_gmt": ... } }`.
The codes are `post_changed` (`data.changed` names the fields that differ),
`post_locked` (another user is editing the post), `invalid`, `not_found`,
`unsupported_storage`, `update_failed` and `db_error`. After a refusal, nothing
is written, and a refusal never contains post content.
`current_modified_gmt` is `null` when the caller may not see it. The Abilities
API itself refuses an input that does not match the input schema, and a call
without the `edit_post` capability, before the ability runs. An MCP client then
receives an error result that contains only a message. Nothing is written in
those cases either.

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
