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
plugin registers the abilities `cinatra/post-read` and
`cinatra/post-update-if-unchanged` in the `cinatra` category. The MCP Adapter
exposes them as the MCP tools `cinatra-post-read` and
`cinatra-post-update-if-unchanged`. Without the Abilities API, the plugin
registers nothing and works as before. The abilities do nothing until a
caller invokes them.

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
`db_error`. A refusal is a normal result with the fields `success` (false), `code`,
`message` and `data`, and never contains post content.

**`cinatra/post-update-if-unchanged`**. Input: `post_id`,
`if_unmodified_since` (the `post_modified_gmt` of the read that the change is
based on), `expected` (the `title`, `content`, `excerpt`, `status` and
`row_token` from that read, always all five), and one or more of `title`,
`content`, `excerpt` and `status` (only `draft`; the ability never
publishes). It writes published posts and drafts only.

What it guarantees: the reviewed change is stored only if the post's row is
unchanged since the read. The ability reads the row, checks the permission,
the status, the edit lock and that the row is the reviewed one (the
modification time, the four values and the row token), and then sends ONE
`UPDATE` that changes the row only while every column still holds the value
that it read. The database applies that statement atomically. If it changes
the row, the change is stored. If it changes no row, the row changed after
the read, and the answer is `post_changed` with nothing written. There is no
transaction and no lock.

It writes only the requested fields, `post_status` `draft` when requested,
and the two modification times. The values are stored as sent, except that
WordPress's kses filter runs on each written field when WordPress has
installed it for the user (a user without the `unfiltered_html` capability):
it removes HTML that the user may not write. `sanitized_fields` names each
field that kses changed.

After a stored write, the ability clears the post's cache and runs the hooks
that WordPress runs after it saves a post, in the same order and with the
same arguments: `transition_post_status`, `edit_post`, `post_updated` (where
WordPress stores a revision), `save_post`, `wp_insert_post` and
`wp_after_insert_post`, with the post as stored and the post before the save.
A hook that changes the post then makes a separate, later save; the answer
reports what this save stored.

What it does not do, compared with a save in the editor: it does not run the
site's other save filters (`wp_insert_post_data`, the other per-field save
filters) or the `pre_post_update` action before the write, so the reviewed
values are stored as reviewed.

The answer on success is the stored row as `cinatra/post-read` returns it,
plus `sanitized_fields`. The refusal codes are `invalid`, `not_found`,
`unsupported_status`, `post_locked`, `post_changed` and `db_error`. If the
database connection is lost during the `UPDATE`, WordPress reconnects and
sends it again; the first attempt may have been stored, so the answer is
`db_error` and says that the outcome is not known.

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
