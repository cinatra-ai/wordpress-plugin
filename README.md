# Cinatra — WordPress plugin

Embeds the [Cinatra](https://cinatra.ai) AI assistant in the WordPress admin so
editors can draft and revise content with an in-context chat assistant. The
plugin treats your Cinatra instance as a versioned data API and talks to it over
HTTP only.

## What it does

- Adds a floating assistant button in the WordPress admin that opens a chat panel.
- Ships the assistant widget JavaScript **locally** (`assets/cinatra-widget.js`),
  served via `plugins_url()` — no executable code is fetched from a remote
  server. The vendored widget is derived from the Cinatra project under
  Apache-2.0 (see the file's SPDX header + NOTICE); the plugin as a whole stays
  GPL-2.0-or-later.
- Keeps the long-lived integration key on the server. A server-side REST
  endpoint (`/wp-json/cinatra/v1/token`, gated to `manage_options` + a
  `wp_rest` nonce) performs a server-to-server exchange with the instance and
  hands the browser only a short-lived, origin/audience/scope-bound stream
  token. The integration key never reaches the browser.
- Negotiates capabilities + contract version with the instance at boot and
  degrades gracefully against older instances.
- Provides a webhook-subscription REST registry (`/wp-json/cinatra/v1/webhooks`)
  and stores an HMAC secret that Cinatra uses to sign the `X-Cinatra-Sig-256`
  header on webhook requests it sends to this site.
- Exposes a **Settings → Cinatra** admin page for the Cinatra URL, API key,
  agent instance ID, and webhook secret.

## Install (end users)

1. Install & activate the plugin (from WordPress.org once published, or upload the zip).
2. (Recommended) Install the WordPress MCP Adapter plugin for AI tool access.
3. In Cinatra, open `/settings/connectors/wordpress-widget` and generate credentials.
4. In WordPress, open **Settings → Cinatra** and paste the Cinatra URL, API key,
   agent instance ID, and webhook secret. Save.

## Plugin ↔ core contract

The plugin sends `contractVersion: "v2"` in its bootstrap and token-exchange
calls. Cinatra validates it and rejects unknown versions with an admin-visible
error. v2 is the secret-free bundle config (no `apiKey` in the browser) plus the
token-exchange and capabilities endpoints; the plugin still accepts `v1`
instances by falling back to the legacy long-lived flow. The contract schemas
live in the cinatra repo under `contracts/wp-drupal-assistant/`.

> Requires the matching Cinatra instance change (cinatra#220) for the
> token-exchange (`/api/agents/{slug}/token`) and capabilities
> (`/api/agents/{slug}/capabilities`) endpoints. Until that lands and deploys,
> the assistant degrades gracefully against an un-upgraded instance.

## Development

This repo is the source of truth for the plugin. Cinatra developers consume it
as a local clone for the dev docker stack. See
<https://docs.cinatra.ai/guides/developer/wp-drupal-plugin-development/> for the
multi-repo workflow, the contract-version bump checklist, and dirty-tree
recovery.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
