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
5. It runs `pre_post_update` with that full data, checks that it is still in
   its own transaction (see below), and then writes only the requested
   fields, `post_status` `draft` when requested, and the two modification
   times, with one database update. That update applies only while the row
   is still exactly the locked row (see below).
6. It reads the row again in the same transaction and checks that the row is
   exactly the locked row with the columns that this save wrote (see below).

If a step refuses, the transaction is rolled back, the post's object cache is
cleared through WordPress (`clean_post_cache()`, so that no request gets a
version of the post that a callback cached during the transaction), nothing
is written, and no after-save hook runs (none of the hooks that WordPress runs after an update,
from `transition_post_status` to `wp_after_insert_post`). If the site
suspended cache invalidation (`wp_suspend_cache_invalidation()`), the ability
lifts the suspension for this one clear and then restores it, because a copy
cached inside a rolled-back transaction never matched the database. Then it
checks that the cache holds no copy of the post, or one equal to the stored
row. If a copy that differs stays (an object-cache drop-in that ignores
deletes), the ability cannot remove it without writing the cache, so the
refusal adds `data.cache_stale` `true` and says so in its message (not for
`not_found`, `permission_unverified` and `unsupported_storage`, which stay
identical). A refusal before
the write fires no save hook: no after-save hook and no `pre_post_update`.
The rollback's cache clear fires WordPress's `clean_post_cache` action, which
is not a save hook. `pre_post_update` may have fired when the
refusal happens at or after the write (`db_error`, or the check in step 6),
because an action cannot be undone. The database changes that its callbacks
made in the transaction are rolled back with it.

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
  status that this save stores (`draft` when requested, otherwise the current
  status) goes through the status save filters that WordPress applies on an
  update (`pre_post_status` and `status_save_pre`). If such a site policy
  returns a different status, for example because it does not allow a
  direct unpublish, the write is refused with `site_policy_conflict`. The
  ability never publishes.
- Each written field goes through the same save filters that WordPress applies
  to that field on a normal save by that user, including the site's own save
  filters. For users without the `unfiltered_html` capability, WordPress
  removes disallowed HTML. Thus the stored value of a written field can be
  different from the sent value. The answer contains the stored values, the
  new `post_modified_gmt`, and `sanitized_fields`, which names each written
  field that a filter changed. As in WordPress, the fields that are not
  written also go through their save filters, but only to build the post
  array that WordPress passes to `wp_insert_post_data` (see below). Their
  stored values are not changed. Thus a restricted user can change the title
  of a page whose content contains HTML that their role may not write.
- The ability does not change the slug, the dates, the author, the parent, the
  menu order, the comment settings, the categories, the tags or other terms.
  A title change does not change the slug of a published page. A draft without
  a set date keeps its date.
- Empty content is decided as in WordPress: the post counts as empty when
  its content, title and excerpt are all empty for PHP (for example the
  content `"0"` counts as empty) and its post type supports the editor, the
  title and the excerpt (by default posts do, pages do not support the
  excerpt). The site's `wp_insert_post_empty_content` filters then decide,
  with the same arguments as in WordPress, and can also refuse a save that
  is not empty. A refused save gives `empty_content`.
- After the commit, the ability clears the post's cache through WordPress
  (`clean_post_cache()`) and runs the hooks that WordPress runs after an
  update, in the same order and with the same arguments:
  `transition_post_status` (with `{old}_to_{new}` and `{new}_{post_type}`),
  `edit_post_{post_type}`, `edit_post`, `post_updated` (WordPress then stores
  a revision), `save_post_{post_type}`, `save_post`, `wp_insert_post` and
  `wp_after_insert_post`.
- After it clears the cache, the ability checks that `get_post()` returns the
  committed row, over every column. If it does not (the site suspended cache
  invalidation with `wp_suspend_cache_invalidation()`, or an object-cache
  drop-in ignores it), the save is still committed and the answer is a
  success with `data.cache_stale` `true` (otherwise `false`). The hooks then
  get a post object that the ability builds from the committed row, as
  WordPress builds one from the database (`sanitize_post()` in the `raw`
  context and `new WP_Post`), without writing it to the cache: that object
  is the post argument of every hook above and the `$post_after` of
  `post_updated`; `$post_before` is the post before the save, as always.
  Code that calls `get_post()` itself, for example the revision that
  WordPress stores on `post_updated`, can still get the old cached post, and
  the site can keep serving it until its cache expires. The ability does not
  resume a suspended cache invalidation: WordPress leaves that decision to
  the code that suspended it.
- The site's save policy applies. As in WordPress, the ability builds the
  full post data (all the columns that WordPress builds for an update: the
  locked row with the requested fields, the status and the new modification
  times) and passes it, slashed, through `wp_insert_post_data` with the same
  arguments as WordPress: the data, the post array, the unsanitized post
  array, and `true` for an update. Each column of the data has the type
  WordPress gives it on an update: `post_parent` and `menu_order` are
  integers, every other column is a string (`post_author` too, as WordPress
  holds it). `pre_post_update` gets the same types. The post array and the unsanitized post
  array are built as `wp_update_post()` and `wp_insert_post()` build them:
  the existing post as `get_post()` returns it as an array (every column and
  `filter`, `ancestors`, `page_template`, `post_category` and `tags_input`),
  slashed and merged with the requested fields. That merged array is the
  unsanitized post array. The post array is the same array with WordPress's
  defaults, without `filter`, after all its fields went through their save
  filters. As in WordPress, the post array contains the stored modification
  times, not the new ones. One difference: WordPress gives a draft without a
  set date the current date on each update; this ability keeps the date, so
  both arrays contain the stored date. The stored values of the requested fields
  and the modification times are the filters' results. `sanitized_fields`
  also names a field that one of these filters changed. If a filter changes
  the status that this save stores, or any column that this save does not
  write (for example the slug, the author or a date), or adds or removes a
  column, the write is refused with `site_policy_conflict`. `data.changed`
  names those columns. Nothing is written, and no after-save hook runs.
- The ability proves three times that it is still in its own transaction on
  its own connection: after the row lock and before any site code runs (the
  permission check's filters, the save filters, `wp_insert_post_data`,
  `pre_post_update`), right before the update, and after the update, right
  before the `COMMIT`. Right after `START TRANSACTION`, before the locked
  read, it sets a savepoint with a random name
  (`SAVEPOINT cinatra_lock_<random>`). Each proof needs the same database
  connection and a successful `RELEASE SAVEPOINT` of that name (the savepoint
  is then set again). Outside a transaction MySQL and MariaDB accept
  `SAVEPOINT` but keep nothing, so the `RELEASE` fails with error 1305; a
  `COMMIT`, a statement that commits implicitly (also when a new transaction
  is started afterwards) and a reconnect all remove the savepoint. This works
  on MySQL and MariaDB without any privilege (`@@in_transaction` exists only
  in MariaDB, and `information_schema.INNODB_TRX` needs the `PROCESS`
  privilege).
  - The first proof fails: no site code has run, so the write is refused
    with `transaction_conflict` (`data.cause` `transaction_lost` or
    `connection_replaced`) and nothing was written.
  - The second proof fails: site code has run, and what it stored itself may
    have been committed outside the transaction. The refusal says that the
    post was not changed by this save and sets `data.site_changes_possible`
    `true`.
  - The third proof is the one that decides. WordPress runs the site's
    `query` filters on every statement that the ability sends, so site code
    can run between any two of them, also between the second proof and the
    update (a filter that commits there makes the update run with
    autocommit, where it is durable at once). No condition inside the
    update can tell, on both MySQL and MariaDB, whether it runs inside the
    transaction, and WordPress offers no supported way to send a statement
    without the `query` filters. So the write is proven after it ran. If the
    proof fails, the update cannot be undone: the ability reads the row and
    answers `write_outcome_unknown` (`data.cause` `matches`, `differs` or
    `unreadable`) with `data.site_changes_possible` `true`, never a success
    and never "nothing was written", and no after-save hook runs. A refusal
    that was decided after the update is checked the same way: if the
    transaction was lost and the update changed no row, the refusal stands
    with `data.site_changes_possible` `true`; otherwise the row is read as
    above.
  - What remains: a filter that commits at the ability's own `COMMIT` (after
    the third proof) commits the same proven work, so the answer stays
    correct. Site code that reads the savepoint name from the statement and
    sets it again in a new transaction after committing can defeat the
    proof; that is deliberate interference, which no check on the same
    connection can rule out.
- A failed database read is a database error, never `not_found`: the locked
  read, the engine check and the read of `cinatra/post-read` answer
  `db_error` with `data.cause` `lock_wait_timeout` (error 1205, another save
  holds the row lock), `deadlock` (error 1213) or `read_failed`, after a
  `ROLLBACK`. A row that does not exist stays `not_found`.
- The update itself is conditional: `WHERE ID = <id>` and, for every column
  of the locked row (the columns that the row token covers), `col IS NULL`
  when the column was NULL, otherwise
  `SHA2(CONVERT(col USING <connection character set>), 256)` equal to the
  SHA-256 of the value that was read. The server hashes the same bytes that
  were read, so the comparison is byte-exact and does not depend on the
  column's collation (a case-insensitive or trailing-space-insensitive
  collation would make a plain `=` accept a different value). If the update
  changes no row, the ability reads the row: if it is the row that this save
  stores, the write was identical (success). Otherwise the condition did not
  match and nothing was written: on the same connection the row stayed locked
  by this save, so code inside the save changed it (`site_policy_conflict`,
  `data.changed` names the columns, also a column that this save writes); if
  the connection was replaced during the update (WordPress repeats a
  statement after a reconnect, outside the transaction), another save may
  have changed it (`post_changed`).
- `pre_post_update` runs after the filters, with the full filtered data,
  unslashed, inside the transaction, before the update, as in WordPress. It
  runs only when the write goes ahead. It is an action, so it cannot stop the
  write.
- After the update and before the commit, the ability reads the row again in
  the same transaction, while it still holds the lock. Each column that this
  save wrote must hold the written value, and every other column must hold
  the value of the locked row. Thus a callback (`pre_post_update`, a save
  filter or `wp_insert_post_data`) that writes the posts row directly cannot
  widen the write, for example publish the post or change its slug. If the
  row differs, the transaction is rolled back and the write is refused with
  `site_policy_conflict`. `data.changed` names the columns.
- Limit: a callback's database writes to other tables (for example post meta
  or terms) through the WordPress database connection are part of the same
  transaction on InnoDB tables. They are rolled back on a refusal and
  committed on success. The ability does not check them. Writes to tables
  that do not use InnoDB, writes through another connection, and effects
  outside the database (the object cache, files, emails, HTTP requests) are
  not rolled back. A callback that ends the transaction itself (`COMMIT`, or
  a statement that commits implicitly, such as `CREATE TABLE`) also commits
  the changes made up to that point, so a later refusal cannot roll them
  back.
- If a callback throws an exception or an error inside the transaction, the
  transaction is rolled back, and the exception continues to the caller
  unchanged. If a callback ends the request inside the transaction (`exit`
  or `wp_die()`), the ability rolls back on WordPress's `shutdown` action,
  before the other shutdown callbacks, on the same database connection.
  This cannot cover a PHP shutdown function that a site registered before
  WordPress loaded (it runs earlier, inside the open transaction), a process
  that is killed or a PHP error that stops the request before shutdown runs;
  in those cases the database rolls the transaction back when the connection
  closes, unless the site uses a persistent database connection.
- The write owns its transaction or it does not run. In MySQL and MariaDB,
  starting a transaction commits a transaction that is already open on the
  same connection. So before it starts, the write refuses with
  `transaction_conflict`, and does not start, commit or write anything,
  when `data.cause` is one of these:
  - `nested`: the call came from a callback inside this ability's own save
    (for example from `pre_post_update`). The outer save continues
    unchanged. A call from a hook after the commit is not nested.
  - `autocommit_off`: the connection has `autocommit` set to 0. Then a
    transaction is always open, so the write cannot own one.
  - `open_transaction`: another transaction is open on the connection, also
    one that has not written anything yet.

  The ability finds an open transaction with a savepoint probe, which needs
  no privilege: `SAVEPOINT cinatra_probe_<random>`, then `ROLLBACK TO
  SAVEPOINT` of that name. With no transaction open, MySQL and MariaDB accept
  the `SAVEPOINT` but do not keep it, so the `ROLLBACK TO` fails with error
  1305 (the savepoint does not exist): no transaction is open. Inside a
  transaction both statements succeed and change nothing; the ability then
  removes its savepoint with `RELEASE SAVEPOINT`. None of these statements
  commits a transaction. The name has a random part for each call, because a
  `SAVEPOINT` replaces a savepoint of the same name. The expected error is
  kept out of the database error output. Reading `autocommit` must work, and
  the probe must give one of these two answers: any other result (another
  error, or a connection that reports no error number) refuses the write
  with `unsupported_storage`, without starting a transaction.
- A `COMMIT` counts only when it succeeds on the connection that started the
  transaction. If it fails (the server may have committed and only the
  answer was lost), or WordPress reconnected during the save (it repeats a
  statement on a new connection after "server has gone away"; the server
  then rolled the transaction back, and later statements ran outside it), the
  outcome is unknown until the ability reads the row again, after a
  `ROLLBACK` and a connection check (`wpdb::check_connection()`). The answer
  is then never a success, and no after-save hook runs: after the `ROLLBACK`
  the row is no longer locked, so another save can store the very values and
  times that this save intended, and a row equal to the intended one does not
  prove that this save stored it. If the row is the row before the save,
  nothing was written (`db_error`, or the refusal that was decided).
  Otherwise the answer is `write_outcome_unknown`: `data.cause` is `matches`
  (the row holds exactly the intended change), `differs` (it holds something
  else) or `unreadable`, and the caller must read the post before it tries
  again.
- A hook that changes the post after the commit makes a separate, later save.
  The answer reports what this save stored.
- The posts table must use the InnoDB storage engine, because other engines
  cannot lock a row. Each write reads the engine again inside its transaction,
  after the row lock. On other engines, the write is refused.

Both abilities require the `edit_post` capability for the specific post. They
check it only against the row that they read, not against a cached copy, so a
stale cache can neither allow nor refuse the caller. Before the ability runs,
the Abilities API checks only that the caller is logged in. A capability
check there would read the post from the object cache, and a site can grant
`edit_post` without any of the general editing capabilities. An unknown
post, a post that is not a post or a page, and a post that the user cannot
edit give the same `not_found` answer.

WordPress decides `edit_post` on the post that `get_post()` returns, which can
come from the object cache. The abilities never write the object cache
themselves. Before the capability check, they compare the post that
`get_post()` returns with the row that they read, over every column of the
row (with the row token's encoding). If it differs, they invalidate the post's
cache through WordPress (`clean_post_cache()`), and `get_post()` loads the post
from the database again. The post must equal the row before the capability
check and again after it. If it does not (for example, an object-cache drop-in
keeps an older entry, or site code changes what `get_post()` returns), the
decision cannot be verified. The read then reads the row again once, because
another save can occur between its read and its check. The write holds the
row lock, so it refuses at once. The refusal is `permission_unverified`:
nothing is read or written, and the answer contains no post content. A site's
capability policy that reads post meta or terms reads data outside the row,
which this check does not cover.

A refusal is a normal result, not an error:
`{ "success": false, "code": "...", "message": "...", "data": { "current_modified_gmt": ... } }`.
The codes are `post_changed` (`data.changed` names the fields that differ),
`site_policy_conflict` (`data.changed` names the columns that a site policy
or site code would change), `post_locked` (another user is editing the post),
`unsupported_status` (`data.current_status` names the status),
`permission_unverified` (the permission decision could not be verified against
the current post; this shows that the post exists), `transaction_conflict`
(`data.cause` names the reason), `empty_content`,
`invalid`, `not_found`, `unsupported_storage`, `db_error` and
`write_outcome_unknown` (`data.cause` is `matches`, `differs` or
`unreadable`: the database did not confirm the save, or the transaction was
found lost after the write, and it is not known whether this save stored the
change; with `data.site_changes_possible` `true`, site code may also have
stored changes). `db_error` carries `data.cause` `lock_wait_timeout`,
`deadlock` or `read_failed` when a read failed. A refusal with
`data.site_changes_possible` `true` (`transaction_conflict`, or another code
whose transaction was found lost after the write changed no row) means that
this save did not change the post, but site code that ran during the save may
have stored changes of its own. After any other refusal, nothing is written. A refusal never
contains post content.
`current_modified_gmt` is `null` when the caller may not see it. The Abilities
API itself refuses an input that does not match the input schema, and a call
by a user who is not logged in, before the ability runs. An MCP client then
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
