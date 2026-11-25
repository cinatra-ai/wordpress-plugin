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
`post_modified_gmt` and `permalink` of a post or page, and `row_token`. The
values come directly from the database, not from a cache.

`row_token` is a fingerprint of every column of the post's row in the posts
table, computed from the same row that the read returns. It is the SHA-256
hash, as 64 lower-case hexadecimal characters, of this encoding: the ASCII text
`cinatra-post-row-v1`, then each column in byte order of its name, as a 4-byte
big-endian length of the name, the name, then the byte `0x00` for NULL, or the
byte `0x01`, a 4-byte big-endian length of the value and the value as stored.
The token covers the posts row only. Post meta and terms (categories, tags)
are not in the row, so the token does not show changes to them.

**`cinatra/post-update-if-unchanged`**. Input: `post_id`,
`if_unmodified_since` (the `post_modified_gmt` of the read that the change is
based on), `expected` (the `title`, `content`, `excerpt`, `status` and
`row_token` from that read), and one or more of `title`, `content`, `excerpt`
and `status`.

The ability changes only the fields that it is asked to change. In one database
transaction, it does these steps:

1. It locks the post row.
2. It checks the `edit_post` capability again against the row that it locked.
   Thus a change of author or type before the lock is seen.
3. It compares `post_modified_gmt`, the four `expected` values byte for byte,
   and `expected.row_token` with the token of the locked row. If another save
   occurred between the read and the write, also a save in the same second or
   a save of any other column of the row (for example the slug, the type or
   the author), the write is refused.
4. It builds the full post data as WordPress does for an update and runs the
   site's `wp_insert_post_data` filters over it (see below).
5. It runs `pre_post_update` with that full data, and then writes only the
   requested fields, `post_status` `draft` when requested, and the two
   modification times, with one database update.

If a step refuses, the transaction is rolled back, nothing is written, and no
WordPress update hook runs.

- `expected` must always contain all five values, `title`, `content`,
  `excerpt`, `status` and `row_token`, exactly as the read returned them, also
  for fields that are not written. For example, a change to draft only sends
  all five values in `expected` and `"status": "draft"`. A change to any
  column of the row refuses with `post_changed`. `data.changed` names
  `post_modified_gmt` and each of the four fields that differ, and
  `row_token` when the row differs at all.
- The ability writes only published posts and drafts. Other statuses have
  their own WordPress flows and are refused with `unsupported_status`:
  `trash` (restoring from the trash goes through WordPress's untrash flow),
  `future`, `private`, `pending` and `auto-draft`.
- `status` may only be `"draft"`. If `status` is not given, the status does
  not change. Thus an edit to a published page is live immediately. The
  ability never publishes: a site filter that would change the status is
  refused (see below).
- Each written field goes through the same save filters that WordPress applies
  to that field on a normal save by that user, including the site's own save
  filters. For users without the `unfiltered_html` capability, WordPress
  removes disallowed HTML. Thus the stored value of a written field can be
  different from the sent value. The answer contains the stored values, the
  new `post_modified_gmt`, and `sanitized_fields`, which names each written
  field that a filter changed. Fields that are not written go through no
  filter and are not changed. Thus a restricted user can change the title of a
  page whose content contains HTML that their role may not write.
- The ability does not change the slug, the dates, the author, the parent, the
  menu order, the comment settings, the categories, the tags or other terms.
  A title change does not change the slug of a published page. A draft without
  a set date keeps its date.
- Title, content and excerpt cannot all become empty, as in WordPress.
- After the commit, the ability clears the post's cache and runs the hooks that
  WordPress runs after an update, in the same order and with the same
  arguments: `transition_post_status` (with `{old}_to_{new}` and
  `{new}_{post_type}`), `edit_post_{post_type}`, `edit_post`, `post_updated`
  (WordPress then stores a revision), `save_post_{post_type}`, `save_post`,
  `wp_insert_post` and `wp_after_insert_post`.
- The site's save policy applies. As in WordPress, the ability builds the
  full post data (all the columns that WordPress builds for an update: the
  locked row with the requested fields, the status and the new modification
  times) and passes it, slashed, through `wp_insert_post_data` with the same
  arguments as WordPress (the data, the post array, the unsanitized post
  array, and `true` for an update). The stored values of the requested fields
  and the modification times are the filters' results. `sanitized_fields`
  also names a field that one of these filters changed. If a filter changes
  the status that this save stores, or any column that this save does not
  write (for example the slug, the author or a date), or adds or removes a
  column, the write is refused with `site_policy_conflict`. `data.changed`
  names those columns. Nothing is written, and no hook runs.
- `pre_post_update` runs after the filters, with the full filtered data,
  unslashed, inside the transaction, before the update, as in WordPress. It
  runs only when the write goes ahead. It is an action, so it cannot stop the
  write.
- A hook that changes the post after the commit makes a separate, later save.
  The answer reports what this save stored.
- The posts table must use the InnoDB storage engine, because other engines
  cannot lock a row. Each write reads the engine again inside its transaction,
  after the row lock. On other engines, the write is refused.

Both abilities require the `edit_post` capability for the specific post, and
check it against the row that they read. An unknown post, a post that is not a
post or a page, and a post that the user cannot edit give the same `not_found`
answer.

A refusal is a normal result, not an error:
`{ "success": false, "code": "...", "message": "...", "data": { "current_modified_gmt": ... } }`.
The codes are `post_changed` (`data.changed` names the fields that differ),
`site_policy_conflict` (`data.changed` names the columns that a site filter
would change), `post_locked` (another user is editing the post),
`unsupported_status` (`data.current_status` names the status), `invalid`,
`not_found`, `unsupported_storage` and `db_error`. After a refusal, nothing
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
