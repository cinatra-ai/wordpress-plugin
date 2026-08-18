# Cinatra for WordPress

Embeds the [Cinatra](https://cinatra.ai) AI assistant in your WordPress admin
so content editors and administrators can draft, rewrite, and improve content in
a chat panel right where they work. The assistant talks to your own Cinatra
instance — you choose which one, and all traffic runs over HTTP only. Access is
restricted to WordPress administrators (`manage_options` capability).

## Documentation

The full documentation for this integration is the WordPress hub on
docs.cinatra.ai: **https://docs.cinatra.ai/integrations/wordpress/** — Overview,
Quick start, Use it, Settings & permissions, Troubleshooting, and Advanced &
reference. The same six chapters are available in this repository's
[`docs/`](docs/) folder.

## Works with

- WordPress (5.9 or later; tested up to 7.0)
- Your self-hosted or cloud Cinatra instance
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) (optional
  companion — required only for AI content-editing tools)

## Capabilities

- Adds a floating assistant button in the WordPress admin that opens a chat
  panel.
- Ships the assistant widget JavaScript **locally** (`assets/cinatra-widget.js`,
  served via `plugins_url()`) — no executable code is fetched from a remote
  server at runtime.
- Keeps the site's integration credential on the server. It is used only for
  server-to-server calls (Connect, the site-inventory handshake, signed publish
  notifications) and never reaches the browser.
- Holds no user credential at all. Sign-in happens inside the Cinatra assistant
  window, on the Cinatra origin; the WordPress page neither starts it nor
  receives anything from it. The page sends the assistant window one message
  carrying public selectors only — which instance, which site, which post is
  open — at embed protocol version 2.
- Negotiates the assistant capability/contract handshake inside that window,
  against the connected instance.
- Provides a webhook-subscription REST registry (`/wp-json/cinatra/v1/webhooks`)
  that enables `post_published` notifications per post type. When a post is
  published, the plugin signs the notification as a
  [Standard-Webhooks](https://www.standardwebhooks.com/) request and delivers it
  to the connected Cinatra instance's generic webhook endpoint, using a signing
  secret and webhook binding id issued by the instance during Connect (there is
  nothing to paste manually; reconnect once after upgrading to provision them).
  The plugin sends outbound signed webhooks; it does not receive or verify
  inbound webhooks.
- Offers one-click **Connect with Cinatra** provisioning: an admin enters the
  instance URL and approves a consent screen; the site exchanges an
  authorization code (PKCE S256) server-side at `/api/connect/token` and stores
  the credential server-side — no key is copy-pasted or exposed to the browser.
  A connection-string (install-code) fallback is available.
- Exposes a **Settings → Cinatra** admin page for Connect, plus manual/advanced
  fields for the Cinatra URL, API key, and agent instance ID. (The webhook
  signing credentials are provisioned by Connect and are not manually
  editable.)

## Install

1. Install and activate the plugin (from WordPress.org once published, or upload
   the zip).
2. (Recommended) Install the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter/releases/latest)
   for AI content-editing tools. The adapter is distributed via GitHub Releases;
   download the ZIP and install it from **Plugins → Add New → Upload Plugin**.
3. In WordPress, open **Settings → Cinatra**, enter your Cinatra instance URL,
   and click **Connect with Cinatra**.
4. Approve the connection on the Cinatra consent screen. The credential is
   provisioned and stored automatically — no manual key entry is needed.
   (Advanced: manual fields remain available for environments where Connect is
   not used.)

The Settings → Cinatra page shows a status indicator for the MCP Adapter and
links to the GitHub release if it is not active.

## Plugin ↔ core contract

The assistant conversation renders inside a sandboxed, Cinatra-served
`/embed/assistant` iframe that owns the session **and the credential**. The AG-UI
capability/contract handshake runs **client-side inside that iframe** against the
unified assistant broker (`GET /api/assistants/chat/capabilities`), and the
conversational wire is `POST /api/assistants/chat`.

The parent side of the embed protocol is at **version 2**. The shell sends one
message, `cinatra.embed.context`, carrying public selectors only — no credential
field, and a recursive guard refuses a credential-shaped *value* in either
direction. It mints no token, calls no broker, and runs no sign-in: the frame
starts its own PKCE transaction same-origin and opens the hosted sign-in as a
top-level Cinatra window. The iframe sandbox is therefore
`allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox` —
the two popup grants are what make that window possible, and every other
escalation stays denied.

> Requires a Cinatra instance that serves the **version-2 embed protocol**
> (`/embed/assistant` + `POST /api/widget-auth/frame/{init,token}`), the unified
> assistant broker (`POST /api/assistants/chat` +
> `GET /api/assistants/chat/capabilities`), and one-click connect
> (`/connect/authorize` + `/api/connect/token`). There is no fallback to
> protocol 1: `POST /api/widget-auth/{init,token}` answer `410 Gone`, and the
> version literal does not negotiate downward — that is deliberate, because
> protocol 1 is precisely the version in which the site handled the user's
> credential. The legacy `/api/agents/{slug}/capabilities` negotiation and
> `/api/agents/{slug}/stream` relay were retired earlier.

## Development

### Requirements

- PHP 7.4 or later
- [Composer](https://getcomposer.org/)
- WordPress 5.9 or later (for local testing)

### Setup

```sh
git clone https://github.com/cinatra-ai/wordpress-plugin
cd wordpress-plugin
composer install
```

### Linting

```sh
composer lint          # PHP_CodeSniffer with WordPress Coding Standards
```

The project uses [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer)
with `wp-coding-standards/wpcs` and `phpcompatibility/phpcompatibility-wp`.
The ruleset is in `phpcs.xml.dist`.

### Tests

```sh
node tools/generate-wordpress-org-assets.mjs   # regenerate .wordpress-org assets (see .wordpress-org/README.md for prerequisites)
node tests/test-widget-negotiation.mjs         # widget bootstrap negotiation tests
php tests/test-token-broker.php                # token-broker unit tests
php tests/test-publish-emitter.php             # publish-emitter unit tests
```

### Releasing

The plugin is released to WordPress.org via the SVN deploy workflow. Bump the
`Stable tag` in `readme.txt` and the `Version` in `cinatra.php`, then push the
tag. See the repo's release workflow for the full steps.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). The bundled assistant widget
(`assets/cinatra-widget.js`) is derived from the Cinatra project under
Apache-2.0 (see the file's SPDX header and [NOTICE](NOTICE));
the plugin as a whole remains GPL-2.0-or-later.
