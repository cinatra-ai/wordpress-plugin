# Cinatra — WordPress plugin

Embeds the [Cinatra](https://cinatra.ai) AI assistant in the WordPress admin so
editors can draft and revise content with an in-context chat assistant. The
plugin talks to your Cinatra instance over HTTP only — it bundles no Cinatra
code (Apache-2.0 ↔ GPL-2.0-or-later HTTP boundary).

## What it does

- Adds a floating assistant button in the WordPress admin that opens a chat panel.
- Loads the assistant bundle from `{your-cinatra-url}/api/wordpress/bundle.js`.
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

The plugin sends `contractVersion: "v1"` in its bootstrap. Cinatra validates it
and rejects unknown versions with an admin-visible error. The contract schemas
live in the cinatra repo under `contracts/wp-drupal-assistant/`.

## Development

This repo is the source of truth for the plugin. Cinatra developers consume it
as a local clone for the dev docker stack. See the cinatra repo:
`docs/developer/wp-drupal-plugin-development.md` for the multi-repo workflow,
the contract-version bump checklist, and dirty-tree recovery.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
