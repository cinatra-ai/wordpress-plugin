#!/usr/bin/env node
// Generates the WordPress.org plugin-directory assets under .wordpress-org/.
//
// DERIVED FROM the cinatra-ai/design repo (spec wins over artifacts):
//   - brand constants  -> design tokens/brand.json (mustard/navy/cream)
//   - logo geometry     -> design assets/logo/cinatra-mark.svg (fedora paths)
//                          design assets/logo/cinatra-lockup-horizontal.svg
//   - colourway rules   -> design assets/logo/variants.json + specs/brand.html §I
//   - composer logic    -> mirrors design scripts/generate-assets.mjs
//                          (appIconSvg / bannerSvg / conform brand-rule gate)
//
// The four WordPress.org directory dimensions have NO bespoke recipe in the
// design repo, so each asset is derived by applying the established brand
// rules to the WordPress.org canvas:
//   icon-128 / icon-256  -> app-icon recipe: mustard fedora on a navy rounded
//        square (the sanctioned icon-ground exception; WordPress masks the
//        directory icon to a circle, so the fedora is kept clear of corners).
//   banner-772 / banner-1544 -> light banner: paper ground + mustard
//        horizontal lockup + tagline in slate ("mustard on paper", the Primary
//        colourway; fits the WordPress.org light directory chrome).
//
// Screenshots (screenshot-N.png) ARE generated here as representative,
// non-misleading mockups of the shipping plugin UI (Settings page copy + the
// floating button's exact fedora glyph and theme colours). wp.org screenshots
// are unvalidated marketing images staged into SVN /assets/; a real browser
// capture of a live WordPress install can supersede these post-launch.
//
// Run from the wordpress-plugin repo root (no deps to install here — the script
// resolves sharp + opentype.js from the design repo's node_modules):
//   DESIGN_REPO=/path/to/cinatra-ai/design node tools/generate-wordpress-org-assets.mjs
// Defaults DESIGN_REPO to a sibling ../design checkout (run `npm install` there once).

import { readFileSync, writeFileSync, mkdirSync, existsSync } from "node:fs";
import { createHash } from "node:crypto";
import { createRequire } from "node:module";
import { join, dirname } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
// This repo (wordpress-plugin) carries no JS deps. The rendering deps
// (sharp, opentype.js) and the brand masters both live in the cinatra-ai/design
// repo, so we resolve them from DESIGN_REPO/node_modules at runtime — the script
// is self-contained against a design checkout and needs nothing installed here.
const root = process.env.WP_PLUGIN_ROOT || join(here, "..");
const DESIGN = process.env.DESIGN_REPO || join(root, "..", "design");
if (!existsSync(join(DESIGN, "assets/logo/cinatra-lockup-horizontal.svg"))) {
  console.error(
    `Design repo not found at ${DESIGN}. Set DESIGN_REPO=/path/to/cinatra-ai/design`
  );
  process.exit(1);
}

// Resolve sharp + opentype.js from the design repo's node_modules. ESM bare
// imports resolve relative to THIS file (which has no node_modules), so we go
// through createRequire anchored at the design repo and import the resolved
// absolute path.
const designRequire = createRequire(join(DESIGN, "package.json"));
async function fromDesign(pkg) {
  let resolved;
  try {
    resolved = designRequire.resolve(pkg);
  } catch {
    console.error(
      `Cannot resolve "${pkg}" from ${DESIGN}. Run \`npm install\` in the design repo first.`
    );
    process.exit(1);
  }
  return (await import(pathToFileURL(resolved).href)).default;
}
const sharp = await fromDesign("sharp");
const opentype = await fromDesign("opentype.js");

// ---- brand constants (tokens/brand.json color + variants.json colourways) ---
const tokens = JSON.parse(readFileSync(join(DESIGN, "tokens/brand.json"), "utf8"));
const TOKENS_VERSION = tokens.meta.version;
const MUSTARD = tokens.color.mustard.value; // #c79545
const NAVY = tokens.color.navy.value; // #15213a
const CREAM = tokens.color.cream.value; // #f1f1ed
const PAPER = CREAM; // paper ground == cream (per brand tokens)
const MUTED = "#5a6477"; // slate caption colour (matches design generate-assets.mjs)

// ---- fedora paths (canonical mark geometry, 72 64 368 192 ink box) ----------
const markSvg = readFileSync(join(DESIGN, "assets/logo/cinatra-mark.svg"), "utf8");
const FEDORA_PATHS = markSvg
  .replace(/<!--[\s\S]*?-->\n/, "")
  .replace(/<svg[^>]*>/, "")
  .replace("</svg>", "")
  .trim();

// The exact two-path fedora glyph baked into the plugin's floating button
// (#cw-fallback-btn in cinatra.php, viewBox "0 0 512 320"). Kept verbatim so
// the screenshot button is pixel-faithful to what ships; `currentColor` is
// replaced by the consuming <g fill=...>.
const BUTTON_FEDORA =
  `<path d="M72 214 C 72 200 96 190 130 188 C 168 186 196 200 256 210 C 316 220 358 214 400 200 C 426 192 440 196 440 208 C 440 222 420 234 388 242 C 340 254 288 256 256 256 C 202 256 132 248 100 238 C 80 232 72 224 72 214 Z"/>` +
  `<path d="M146 188 C 150 130 176 86 212 72 C 226 66 240 64 252 64 C 262 64 270 70 268 80 L 264 100 C 272 88 288 82 300 82 C 332 82 356 118 362 188 Z"/>`;

// ---- lockup master ----------------------------------------------------------
const lockSrc = readFileSync(
  join(DESIGN, "assets/logo/cinatra-lockup-horizontal.svg"),
  "utf8"
);
const lockVbMatch = lockSrc.match(/viewBox="([\d.\- ]+)"/);
if (!lockVbMatch)
  throw new Error(
    `No viewBox in ${join(DESIGN, "assets/logo/cinatra-lockup-horizontal.svg")}`
  );
const lockVb = lockVbMatch[1].trim().split(/\s+/).map(Number);
if (lockVb.length !== 4 || lockVb.some(Number.isNaN))
  throw new Error(
    `Malformed viewBox in ${join(DESIGN, "assets/logo/cinatra-lockup-horizontal.svg")}`
  );
const LOCK = { w: lockVb[2], h: lockVb[3] };
const lockContent = lockSrc
  .replace(/<!--[\s\S]*?-->\n/, "")
  .replace(/<svg[^>]*>/, "")
  .replace("</svg>", "");

// ---- fonts (caption / tagline -> outlined JetBrains Mono bold) --------------
const toAB = (b) => b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength);
const mono = opentype.parse(
  toAB(readFileSync(join(DESIGN, "scripts/.fontcache/JetBrainsMono-700.ttf")))
);
// Archivo Italic 800 — the wordmark face (matches the widget's `.cw-wordmark`
// font: italic 800 Archivo). Used only for the "Cinatra" wordmark in shot-3.
const archivo = opentype.parse(
  toAB(readFileSync(join(DESIGN, "scripts/.fontcache/Archivo-Italic-800.ttf")))
);
function outlined(font, text, size, { letterSpacing = 0 } = {}) {
  const p = font.getPath(text, 0, 0, size, { kerning: true, letterSpacing });
  const b = p.getBoundingBox();
  return { d: p.toPathData(2), w: b.x2 - b.x1, x1: b.x1, y1: b.y1, y2: b.y2 };
}
const r2 = (n) => Math.round(n * 100) / 100;

// ---- brand-rule conformance gate (mirrors design scripts/generate-assets.mjs)
// 1. Mustard is reserved for the mark/wordmark — never caption/tagline text.
// 2. No mustard on the navy ground — except sanctioned icon grounds.
function conform(svg, { iconGround = false } = {}) {
  const navyGround = new RegExp(`<rect[^>]+fill="${NAVY}"`).test(svg);
  if (navyGround && svg.includes(`fill="${MUSTARD}"`) && !iconGround)
    throw new Error(
      "brand-rule violation: mustard on the navy ground outside an icon ground"
    );
  return svg;
}
function caption(text, size, fill, letterSpacing = 0.18) {
  if (fill === MUSTARD)
    throw new Error(
      "brand-rule violation: mustard is reserved for the mark/wordmark — captions use cream or slate"
    );
  const o = outlined(mono, text.toUpperCase(), size, { letterSpacing });
  return {
    ...o,
    path: (x, y) =>
      `<path fill="${fill}" transform="translate(${r2(x)} ${r2(y)})" d="${o.d}"/>`,
  };
}

// ---- icon (WordPress.org directory icon) ------------------------------------
// App-icon recipe: mustard fedora on a navy rounded square. ~60% fedora width
// (scale 0.835, matching the design app icon) with a corner-safe margin so the
// fedora survives the circular mask WordPress applies in some surfaces. The
// fedora ink box is "72 64 368 192" -> centre at (256,160) in the 512 canvas.
function iconSvg() {
  return conform(
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">` +
      `<rect width="512" height="512" rx="72" fill="${NAVY}"/>` +
      `<g transform="translate(256 256) scale(0.835) translate(-256 -160)" fill="${MUSTARD}">${FEDORA_PATHS}</g>` +
      `</svg>`,
    { iconGround: true }
  );
}

// ---- banner (WordPress.org directory banner) --------------------------------
// Light banner: paper ground + mustard horizontal lockup + tagline in slate.
// Mirrors design bannerSvg(...,"light"): lockup height capped, tagline tracked.
function placeLockup(fill, x, y, h) {
  const s = h / LOCK.h;
  return `<g transform="translate(${x} ${y}) scale(${r2(s)})" fill="${fill}">${lockContent}</g>`;
}
function bannerSvg(W, H) {
  const tagline = tokens.voice.tagline; // "The open source AI workspace"
  const lockH = Math.min(H * 0.34, 120);
  const lockW = LOCK.w * (lockH / LOCK.h);
  const x = (W - lockW) / 2;
  const y = (H - lockH) / 2 - H * 0.06;
  const cap = caption(tagline, Math.max(16, H * 0.052), MUTED, 0.22);
  const tag = cap.path((W - cap.w) / 2, y + lockH + H * 0.14);
  return conform(
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}">` +
      `<rect width="${W}" height="${H}" fill="${PAPER}"/>` +
      `${placeLockup(MUSTARD, r2(x), r2(y), lockH)}${tag}` +
      `</svg>`
  );
}

// ---- screenshots (WordPress.org directory screenshot-N.png) -----------------
// Representative, NON-MISLEADING mockups of the plugin UI that actually ships,
// rendered with the same sharp+SVG pipeline as the other directory assets.
// They are derived 1:1 from real plugin surfaces:
//   screenshot-1 — Settings -> Cinatra admin page (copy verbatim from
//       cinatra_render_settings_page() in cinatra.php: the "Connect to Cinatra"
//       card + the "Advanced / manual configuration" form-table fields).
//   screenshot-2 — the floating assistant button in wp-admin (the exact fedora
//       SVG, theme colours CINATRA_THEME_* and bottom-right placement from
//       assets/cinatra-fallback.css + the #cw-fallback-btn markup).
//   screenshot-3 — the chat panel open (brand chrome; header "Cinatra AI
//       Assistant" matching the button's title/aria-label).
// wp.org screenshots are marketing images with no automated validation and are
// staged into SVN /assets/ (never the shipped zip); a real browser capture can
// supersede these post-launch. The 1280x800 canvas is the wp.org-recommended
// 4:2.5-ish listing ratio (scaled-down retina-friendly).
const SHOT_W = 1280;
const SHOT_H = 800;
const INK = "#1d2327"; // wp-admin text (Inter/system); near-black
const WP_BG = "#f0f0f1"; // wp-admin content background
const WP_CARD = "#ffffff";
const WP_BORDER = "#c3c4c7";
const WP_DESC = "#646970"; // wp-admin .description grey
const WP_BLUE = "#2271b1"; // wp-admin primary button
const ACCENT_SOFT = "#e6ede7"; // CINATRA_THEME_ACCENT_SOFT (button ground)
const LOGO_COLOR = "#7a2e3a"; // CINATRA_THEME_LOGO_COLOR (fedora ink)
// Live widget palette (from assets/cinatra-widget.js CSS) — used so shot-3
// mirrors the SHIPPING chat panel, not a generic chat UI.
const W_PANEL = "#f7f7f3"; // .cw-panel cream ground
const W_HEADER = "#eceeea"; // .cw-panel-header light ground
const W_INK = "#15213a"; // navy text + .cw-msg-user pill
const W_SLATE = "#5a6477"; // .cw-close / muted
const W_MUSTARD = "#c79545"; // .cw-wordmark

// Outlined-path text so rendering does not depend on a system font being
// present in librsvg (mirrors the caption() approach). `mono` (JetBrains Mono
// 700) is the only embedded face; it reads cleanly for UI labels at these sizes.
// `y` is the visual TOP of the glyph box (translate compensates by -y1).
function text(str, x, y, size, fill, { letterSpacing = 0, font = mono } = {}) {
  const o = outlined(font, str, size, { letterSpacing });
  return `<path fill="${fill}" transform="translate(${r2(x)} ${r2(y - o.y1)})" d="${o.d}"/>`;
}

// wp-admin frame: left admin sidebar + top bar + content area. Shared chrome so
// each screenshot is recognisably a real wp-admin screen.
function wpFrame(innerSvg, { activeLabel = "Settings" } = {}) {
  const sidebarW = 160;
  const topH = 32;
  const menu = ["Dashboard", "Posts", "Media", "Pages", "Appearance", "Plugins", "Settings"];
  let nav = "";
  menu.forEach((m, i) => {
    const y = topH + 14 + i * 34;
    const active = m === activeLabel;
    if (active) nav += `<rect x="0" y="${y - 14}" width="${sidebarW}" height="34" fill="#2271b1"/>`;
    nav += text(m, 16, y + 5, 14, active ? "#ffffff" : "#c3c4c7");
  });
  return (
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${SHOT_W} ${SHOT_H}">` +
    `<rect width="${SHOT_W}" height="${SHOT_H}" fill="${WP_BG}"/>` +
    // top admin bar
    `<rect x="0" y="0" width="${SHOT_W}" height="${topH}" fill="#1d2327"/>` +
    text("WordPress", 14, 21, 13, "#f0f0f1") +
    text("howdy, admin", SHOT_W - 150, 21, 13, "#a7aaad") +
    // left sidebar
    `<rect x="0" y="${topH}" width="${sidebarW}" height="${SHOT_H - topH}" fill="#1d2327"/>` +
    nav +
    // content area
    `<g transform="translate(${sidebarW} ${topH})">${innerSvg}</g>` +
    `</svg>`
  );
}

// screenshot-1 — Settings -> Cinatra admin page (verbatim shipping copy).
function shotSettings() {
  const cw = SHOT_W - 160; // content width
  let s = "";
  let y = 40;
  s += text("Cinatra Settings", 40, y, 30, INK);
  y += 44;
  // "Connect to Cinatra" card
  const cardX = 40;
  const cardW = cw - 80;
  const cardY = y;
  const cardH = 250;
  s += `<rect x="${cardX}" y="${cardY}" width="${cardW}" height="${cardH}" rx="2" fill="${WP_CARD}" stroke="${WP_BORDER}"/>`;
  let cy = cardY + 36;
  s += text("Connect to Cinatra", cardX + 24, cy, 20, INK);
  cy += 30;
  s += text("Enter your Cinatra instance URL and click Connect. You will be sent to", cardX + 24, cy, 13, WP_DESC);
  cy += 20;
  s += text("Cinatra to approve the connection; the credential is provisioned", cardX + 24, cy, 13, WP_DESC);
  cy += 20;
  s += text("automatically and stored on this server. You never copy or paste a key.", cardX + 24, cy, 13, WP_DESC);
  cy += 34;
  s += text("Cinatra instance URL", cardX + 24, cy, 13, INK);
  cy += 22;
  s += `<rect x="${cardX + 24}" y="${cy}" width="320" height="32" rx="3" fill="#fff" stroke="#8c8f94"/>`;
  s += text("https://app.cinatra.ai", cardX + 34, cy + 21, 13, WP_DESC);
  cy += 50;
  s += `<rect x="${cardX + 24}" y="${cy}" width="180" height="34" rx="3" fill="${WP_BLUE}"/>`;
  s += text("Connect with Cinatra", cardX + 40, cy + 22, 13, "#ffffff");
  y = cardY + cardH + 36;
  // Advanced / manual configuration form-table
  s += text("Advanced / manual configuration", 40, y, 20, INK);
  y += 26;
  s += text("Most sites should use Connect above. These fields set or override the connection manually.", 40, y, 13, WP_DESC);
  y += 32;
  const rows = [
    ["Cinatra URL", "https://app.cinatra.ai"],
    ["API Key", "(stored — leave blank to keep)"],
    ["Agent Instance ID", "wp-prod"],
    ["Webhook Secret", "(stored — leave blank to keep)"],
  ];
  rows.forEach(([label, val]) => {
    s += text(label, 40, y + 21, 14, INK);
    s += `<rect x="240" y="${y + 2}" width="300" height="30" rx="3" fill="#fff" stroke="#8c8f94"/>`;
    s += text(val, 250, y + 21, 13, WP_DESC);
    y += 46;
  });
  s += `<rect x="40" y="${y + 4}" width="130" height="34" rx="3" fill="${WP_BLUE}"/>`;
  s += text("Save Changes", 56, y + 26, 13, "#ffffff");
  return wpFrame(s, { activeLabel: "Settings" });
}

// The exact floating-button fedora + chrome (CINATRA_THEME_* colours, fixed
// bottom-right, 32px circle with a logo-coloured ring) used by both shots 2/3.
function fedoraButton(cx, cy) {
  // The button SVG viewBox is 0 0 512 320; place + scale into a 22x14 glyph
  // centred in a 32px circle, matching #cw-fallback-btn.
  const r = 16;
  const glyphW = 22;
  const glyphH = 14;
  const gx = cx - glyphW / 2;
  const gy = cy - glyphH / 2;
  const sx = glyphW / 512;
  const sy = glyphH / 320;
  return (
    `<circle cx="${cx}" cy="${cy}" r="${r}" fill="${ACCENT_SOFT}" stroke="${LOGO_COLOR}" stroke-width="1.5"/>` +
    `<g transform="translate(${r2(gx)} ${r2(gy)}) scale(${r2(sx)} ${r2(sy)})" fill="${LOGO_COLOR}">${BUTTON_FEDORA}</g>`
  );
}

// A neutral page surface (the editor) behind the floating button, so shots 2/3
// read as a real site page rather than a bare canvas.
function pageSurface(s = "") {
  let g = `<rect x="0" y="0" width="${SHOT_W - 160}" height="${SHOT_H - 32}" fill="${WP_BG}"/>`;
  g += text("Edit Post", 40, 50, 28, INK);
  g += `<rect x="40" y="78" width="${SHOT_W - 160 - 360}" height="36" rx="3" fill="#fff" stroke="${WP_BORDER}"/>`;
  g += text("Add title", 52, 102, 16, WP_DESC);
  g += `<rect x="40" y="130" width="${SHOT_W - 160 - 360}" height="${SHOT_H - 32 - 170}" rx="3" fill="#fff" stroke="${WP_BORDER}"/>`;
  g += text("Start writing or type / to choose a block", 56, 168, 14, WP_DESC);
  return g + s;
}

// screenshot-2 — floating assistant button in wp-admin (bottom-right).
function shotButton() {
  // Button placement mirrors fallback CSS: bottom:66px right:36px (from the
  // viewport edge; the content area starts at x=160,y=32).
  const cx = SHOT_W - 160 - 36 - 16;
  const cy = SHOT_H - 32 - 66 - 16;
  let s = pageSurface(fedoraButton(cx, cy));
  // a small callout label pointing at the button (clearly a screenshot caption)
  s += text('Floating "Cinatra AI Assistant" button', cx - 360, cy + 6, 14, W_INK);
  return wpFrame(s, { activeLabel: "Posts" });
}

// A small fedora glyph in an arbitrary fill (the widget header logo is the same
// brim+crown paths as the button, rendered at 22x14 in LOGO_COLOR).
function fedoraGlyph(x, y, fill, w = 22, h = 14) {
  const sx = w / 512;
  const sy = h / 320;
  return `<g transform="translate(${r2(x)} ${r2(y)}) scale(${r2(sx)} ${r2(sy)})" fill="${fill}">${BUTTON_FEDORA}</g>`;
}

// screenshot-3 — the chat panel open over the editor. Mirrors the SHIPPING
// widget DOM/CSS (assets/cinatra-widget.js): cream .cw-panel, light .cw-panel-
// header with the fedora mark + italic-Archivo mustard "Cinatra" wordmark + a
// slate × close, plain-navy assistant text (no bubble), a navy .cw-msg-user
// pill with white text, and the white .cw-pill composer carrying the real
// "Ask Cinatra…" placeholder.
function shotPanel() {
  const cx = SHOT_W - 160 - 36 - 16;
  const cy = SHOT_H - 32 - 66 - 16;
  let s = pageSurface("");
  const pw = 360;
  const ph = 460;
  const px = SHOT_W - 160 - 24 - pw;
  const py = cy - 16 - ph;
  // panel: cream ground, navy-alpha hairline, 16px radius, soft shadow
  s +=
    `<rect x="${px}" y="${py}" width="${pw}" height="${ph}" rx="16" fill="${W_PANEL}"` +
    ` stroke="${W_INK}" stroke-opacity="0.08"/>`;
  // header: light ground (top corners rounded), fedora mark + wordmark + close
  const hh = 48;
  s += `<path d="M${px} ${py + 16} a16 16 0 0 1 16 -16 h${pw - 32} a16 16 0 0 1 16 16 v${hh - 16} h-${pw} z" fill="${W_HEADER}"/>`;
  s += `<line x1="${px}" y1="${py + hh}" x2="${px + pw}" y2="${py + hh}" stroke="${W_INK}" stroke-opacity="0.08"/>`;
  // The widget header logo (makeLogoDarkSvg) fills the fedora with the mustard
  // LOGO_COLOR (#c79545) — distinct from the PHP fallback button's #7a2e3a.
  s += fedoraGlyph(px + 16, py + 17, W_MUSTARD);
  s += text("Cinatra", px + 46, py + 16, 16, W_MUSTARD, { font: archivo, letterSpacing: -0.022 });
  s += text("×", px + pw - 30, py + 14, 20, W_SLATE);
  // assistant message — plain navy text on cream (no bubble), left-aligned
  let my = py + hh + 20;
  s += text("Hi — I'm the Cinatra assistant. Ask me", px + 16, my, 13, W_INK);
  s += text("anything about your site or content.", px + 16, my + 19, 13, W_INK);
  // user message — navy pill, white text, right-aligned (radius 18)
  my += 60;
  const uw = 196;
  s += `<rect x="${px + pw - 16 - uw}" y="${my}" width="${uw}" height="34" rx="17" fill="${W_INK}"/>`;
  s += text("Summarise this draft post", px + pw - 16 - uw + 14, my + 10, 12, "#ffffff");
  // assistant reply — plain navy text
  my += 52;
  s += text("This draft introduces the Cinatra", px + 16, my, 13, W_INK);
  s += text("assistant and walks a reader through", px + 16, my + 19, 13, W_INK);
  s += text("connecting their instance.", px + 16, my + 38, 13, W_INK);
  // composer pill (.cw-pill): white, navy-alpha border, "Ask Cinatra…"
  const pillH = 44;
  const pillY = py + ph - pillH;
  s += `<path d="M${px} ${pillY} h${pw} v${pillH - 16} a16 16 0 0 1 -16 16 h-${pw - 32} a16 16 0 0 1 -16 -16 z" fill="#ffffff"/>`;
  s += `<line x1="${px}" y1="${pillY}" x2="${px + pw}" y2="${pillY}" stroke="${W_INK}" stroke-opacity="0.08"/>`;
  s += text("Ask Cinatra…", px + 16, pillY + 15, 14, W_SLATE);
  // the floating button stays visible while the panel is open
  s += fedoraButton(cx, cy);
  return wpFrame(s, { activeLabel: "Posts" });
}

// ---- emit + provenance ------------------------------------------------------
const OUT = join(root, ".wordpress-org");
mkdirSync(OUT, { recursive: true });
const entries = [];
async function png(svg, w, h) {
  return sharp(Buffer.from(svg), { density: 300 }).resize(w, h).png().toBuffer();
}
async function emit(name, svg, w, h, meta) {
  const data = await png(svg, w, h);
  writeFileSync(join(OUT, name), data);
  entries.push({
    path: `.wordpress-org/${name}`,
    ...meta,
    dimensions: `${w}x${h}`,
    tokensVersion: TOKENS_VERSION,
    sha256: createHash("sha256").update(data).digest("hex"),
    generatedBy: "node tools/generate-wordpress-org-assets.mjs",
  });
  console.log("wrote", name, `${w}x${h}`, `${(data.length / 1024).toFixed(1)}KB`);
}

const icon = iconSvg();
await emit("icon-128x128.png", icon, 128, 128, {
  source: "design assets/logo/cinatra-mark.svg",
  colorway: "app icon (mustard fedora on navy)",
});
await emit("icon-256x256.png", icon, 256, 256, {
  source: "design assets/logo/cinatra-mark.svg",
  colorway: "app icon (mustard fedora on navy)",
});
await emit("banner-772x250.png", bannerSvg(772, 250), 772, 250, {
  source: "design assets/logo/cinatra-lockup-horizontal.svg",
  colorway: "mustard on paper + slate tagline",
});
await emit("banner-1544x500.png", bannerSvg(1544, 500), 1544, 500, {
  source: "design assets/logo/cinatra-lockup-horizontal.svg",
  colorway: "mustard on paper + slate tagline",
});

// Screenshots (representative mockups of the shipping plugin UI). Order MUST
// match the numbered captions in readme.txt `== Screenshots ==`.
await emit("screenshot-1.png", shotSettings(), SHOT_W, SHOT_H, {
  source: "plugin UI: cinatra_render_settings_page() in cinatra.php",
  colorway: "wp-admin chrome (Settings -> Cinatra)",
});
await emit("screenshot-2.png", shotButton(), SHOT_W, SHOT_H, {
  source: "plugin UI: #cw-fallback-btn (assets/cinatra-fallback.css + cinatra.php)",
  colorway: "wp-admin chrome + floating assistant button",
});
await emit("screenshot-3.png", shotPanel(), SHOT_W, SHOT_H, {
  source: "plugin UI: assistant chat panel (assets/cinatra-widget.js .cw-* DOM/CSS)",
  colorway: "wp-admin chrome + chat panel open (cream panel, mustard wordmark)",
});

const manifest = {
  meta: {
    name: "Cinatra WordPress.org directory asset manifest",
    description:
      "Provenance for the WordPress.org plugin-directory assets in .wordpress-org/. Derived from the cinatra-ai/design repo (masters + tokens + brand spec); regenerate with tools/generate-wordpress-org-assets.mjs — never hand-edit.",
    designRepo: "cinatra-ai/design",
    note: "icon/banner derive from the design repo masters; screenshot-N.png are representative mockups of the shipping plugin UI (Settings page copy + the floating button's exact fedora glyph and theme colours), rendered by the same sharp+SVG pipeline. A real browser capture of a live WordPress install can supersede these post-launch.",
  },
  entries,
};
writeFileSync(join(OUT, "manifest.json"), JSON.stringify(manifest, null, 2) + "\n");
console.log(`\nOK — ${entries.length} WordPress.org assets generated; manifest written.`);
