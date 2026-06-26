# Cinatra for WordPress

Embeds the [Cinatra](https://cinatra.ai) AI assistant in your WordPress admin
so content editors and administrators can draft, rewrite, and improve content in
a chat panel right where they work. The assistant talks to your own Cinatra
instance — you choose which one, and all traffic runs over HTTP only. Access is
restricted to WordPress administrators (`manage_options` capability).

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
- Keeps the long-lived integration key on the server. A server-side REST
  endpoint (`/wp-json/cinatra/v1/token`, gated to `manage_options` + a
  `wp_rest` nonce) performs a server-to-server exchange with the instance and
  hands the browser only a short-lived, scope-bound stream token. The
  integration key never reaches the browser.
- Negotiates capabilities and contract version with the instance at boot and
  degrades gracefully against older instances.
- Provides a webhook-subscription REST registry (`/wp-json/cinatra/v1/webhooks`)
  and stores a shared webhook secret. When a post is published, the plugin signs
  and sends a `post_published` notification to each matching subscription target
  using that secret. The plugin sends outbound signed webhooks; it does not
  receive or verify inbound webhooks.
- Offers one-click **Connect with Cinatra** provisioning: an admin enters the
  instance URL and approves a consent screen; the site exchanges an
  authorization code (PKCE S256) server-side at `/api/connect/token` and stores
  the credential server-side — no key is copy-pasted or exposed to the browser.
  A connection-string (install-code) fallback is available.
- Exposes a **Settings → Cinatra** admin page for Connect, plus manual/advanced
  fields for the Cinatra URL, API key, agent instance ID, and webhook secret.

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

The widget negotiates a contract version with the instance at boot by calling
the capabilities endpoint. Both v2 and v1 are understood by the widget; the
instance advertises which it supports and the widget picks the newest mutual
version. In all cases `supportsTokenExchange: true` is required — there is no
long-lived-key fallback. An instance that cannot mint short-lived tokens, or
that returns no mutually-supported version, causes the widget to show the
fallback chrome rather than mounting. The contract schemas live in the cinatra
repository under `contracts/wp-drupal-assistant/`.

> Requires the matching Cinatra instance changes for the token-exchange
> (`/api/agents/{slug}/token`), capabilities (`/api/agents/{slug}/capabilities`),
> and one-click connect (`/connect/authorize` + `/api/connect/token`) endpoints.
> Until those are deployed, the assistant degrades gracefully against an
> un-upgraded instance.

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

### Regenerating WordPress.org assets

The banner and icon images in `.wordpress-org/` are generated deterministically
from the design repository. See [`.wordpress-org/README.md`](.wordpress-org/README.md)
for full instructions.

### Releasing

The plugin is released to WordPress.org via the SVN deploy workflow. Bump the
`Stable tag` in `readme.txt` and the `Version` in `cinatra.php`, then push the
tag. See the repo's release workflow for the full steps.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). The bundled assistant widget
(`assets/cinatra-widget.js`) is derived from the Cinatra project under
Apache-2.0 (see the file's SPDX header and [NOTICE](NOTICE));
the plugin as a whole remains GPL-2.0-or-later.
