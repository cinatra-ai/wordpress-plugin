# WordPress.org directory assets

These are the assets WordPress.org serves on the plugin's directory listing
(https://wordpress.org/plugins/cinatra/). They live outside the shipped plugin
zip — the `.wordpress-org/` directory is read by the WordPress.org plugin SVN
deploy, not bundled into the plugin.

All artwork is **derived from the `cinatra-ai/design` repo** (the brand source of
truth: `assets/logo/` masters, `tokens/brand.json`, `specs/brand.html`,
`assets/logo/variants.json`). Spec wins over artifacts: every asset applies the
established brand rules — never an ad-hoc recolour or layout.

## Files

| File | Dimensions | Treatment (per brand spec) |
| --- | --- | --- |
| `icon-128x128.png` | 128×128 | App-icon recipe: mustard fedora on a navy rounded square (the sanctioned icon-ground exception). Fedora kept clear of corners so it survives the circular mask WordPress applies. |
| `icon-256x256.png` | 256×256 | Same as the 128 icon, rendered at 2×. |
| `banner-772x250.png` | 772×250 | Light banner: paper (cream) ground + mustard horizontal lockup + tagline "The open source AI workspace" in slate. "Mustard on paper" is the Primary colourway. |
| `banner-1544x500.png` | 1544×500 | Same as the 772 banner, rendered at 2× (retina). |
| `manifest.json` | — | Provenance for each generated file (source master, colourway, tokens version, sha256). |

## Regenerating

The assets are deterministic. Regenerate from the design repo — never hand-edit
the PNGs:

```sh
# from the wordpress-plugin repo root, with a cinatra-ai/design checkout available
DESIGN_REPO=/path/to/cinatra-ai/design node tools/generate-wordpress-org-assets.mjs
```

The generator (`tools/generate-wordpress-org-assets.mjs`) reuses the design
repo's logo masters, brand tokens, pinned fonts, and the same `conform()`
brand-rule gate as `cinatra-ai/design` `scripts/generate-assets.mjs`, so a
brand-rule violation fails the build instead of shipping.

## Screenshots

`screenshot-1.png … screenshot-3.png` are **committed** — captured from the live
widget UI rendered in a real wp-admin (headless Chromium against a running
Cinatra dev instance, with the widget mounted via successful capabilities
negotiation). The captions in `readme.txt` under `== Screenshots ==` are kept in
ordinal sync. Captured shots:

1. **`screenshot-1.png`** — Settings → Cinatra page: instance-URL field +
   one-click "Connect with Cinatra"; manual/advanced fields below. (2560×1920)
2. **`screenshot-2.png`** — the floating Cinatra assistant button at the
   bottom-right of wp-admin (the mounted widget launcher). (2560×1920)
3. **`screenshot-3.png`** — the assistant chat panel open over the post list,
   showing the Cinatra-branded header and the "Ask Cinatra…" composer. (2560×1920)

Follow-up (not yet captured): a `screenshot-4.png` of the Settings page after a
fresh connection is approved, showing connected-instance status. Deferred — the
verify instance is already connected, so a clean "just connected" status view
needs a from-scratch connect flow.

Capture spec (used for the committed shots):
- PNG, RGB, no alpha border; 1× WordPress.org accepts 1280×960 or larger and
  scales down. Capture at a 2× device pixel ratio for crispness, then export the
  full-width screenshots at a consistent width.
- Light wp-admin colour scheme; default theme; no personal data on screen.
- File names must be exactly `screenshot-1.png`, `screenshot-2.png`, etc., and
  the ordinal must match the caption order in `readme.txt`.

No fake or empty screenshot images are committed: shipping a placeholder PNG
would be served verbatim on the directory listing. The captions ship now; the
real rasters follow from the E2E.
