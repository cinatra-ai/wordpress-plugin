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
| `icon-128x128.png` | 128×128 | Brand favicon treatment: mustard fedora on a clean white/paper ground with a hairline border ("Mustard on paper or surface ... never on the navy ground"). Fedora kept clear of corners so it survives the circular mask WordPress applies. |
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

`screenshot-1.png`, `screenshot-2.png`, and `screenshot-3.png` are **real
captures** of the plugin running in a live wp-admin (RGB PNG, no alpha border).
Their captions live in `readme.txt` under `== Screenshots ==`; the ordinal of
each file matches its caption order there.

1. **`screenshot-1.png`** — the assistant in action: the chat panel open over
   the WordPress post editor, asked to make a headline more engaging and tighten
   the opening paragraph.
2. **`screenshot-2.png`** — account sign-in: the "Sign in with Cinatra" window
   the assistant shows so each person works with their own permissions.
3. **`screenshot-3.png`** — Settings → Cinatra page: the instance-URL field and
   the one-click "Connect with Cinatra" button, with the advanced/manual fields
   below.

Replacing/adding screenshots: file names must be exactly `screenshot-1.png`,
`screenshot-2.png`, etc., the ordinal must match the caption order in
`readme.txt`, and each must be a genuine capture of the running plugin — never a
mockup or placeholder, since WordPress.org serves these verbatim on the
directory listing.
