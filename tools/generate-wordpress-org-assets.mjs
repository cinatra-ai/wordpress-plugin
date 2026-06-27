#!/usr/bin/env node
// Generates the WordPress.org plugin-directory assets under .wordpress-org/.
//
// DERIVED FROM the Cinatra design system (spec wins over artifacts):
//   - brand constants  -> design tokens/brand.json (mustard/navy/cream)
//   - logo geometry     -> design assets/logo/cinatra-mark.svg (fedora paths)
//                          design assets/logo/cinatra-lockup-horizontal.svg
//   - colourway rules   -> design assets/logo/variants.json + specs/brand.html §I
//   - composer logic    -> mirrors design scripts/generate-assets.mjs
//                          (appIconSvg / bannerSvg / conform brand-rule gate)
//
// The four WordPress.org directory dimensions have NO bespoke recipe in the
// design system, so each asset is derived by applying the established brand
// rules to the WordPress.org canvas:
//   icon-128 / icon-256  -> favicon recipe: the mustard fedora on a clean
//        white/paper ground with a hairline border (design variants.json
//        applications.favicon + cinatra src/app/icon.svg). The brand rule is
//        "Never mustard on a coloured chip or on the navy ground", so the
//        directory icon is NOT the navy app-icon ground.
//   banner-772 / banner-1544 -> light banner: paper ground + mustard
//        horizontal lockup + tagline in slate ("mustard on paper", the Primary
//        colourway; fits the WordPress.org light directory chrome).
//
// Screenshots are NOT generated. wp.org screenshots must be real browser
// captures of a live WordPress install; synthetic mock-ups misrepresent the
// product, so none are shipped until a real capture exists (later release).
//
// Run from the wordpress-plugin repo root (no deps to install here — the script
// resolves sharp + opentype.js from the design system checkout's node_modules):
//   DESIGN_REPO=/path/to/your/cinatra-design-checkout node tools/generate-wordpress-org-assets.mjs
// Defaults DESIGN_REPO to a sibling ../design checkout (run `npm install` there once).

import { readFileSync, writeFileSync, mkdirSync, existsSync } from "node:fs";
import { createHash } from "node:crypto";
import { createRequire } from "node:module";
import { join, dirname } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
// This repo (wordpress-plugin) carries no JS deps. The rendering deps
// (sharp, opentype.js) and the brand masters both live in the Cinatra design
// system checkout, so we resolve them from DESIGN_REPO/node_modules at runtime —
// the script is self-contained against a design checkout and needs nothing
// installed here.
const root = process.env.WP_PLUGIN_ROOT || join(here, "..");
const DESIGN = process.env.DESIGN_REPO || join(root, "..", "design");
if (!existsSync(join(DESIGN, "assets/logo/cinatra-lockup-horizontal.svg"))) {
  console.error(
    `Design system checkout not found at ${DESIGN}. Set DESIGN_REPO=/path/to/your/cinatra-design-checkout`
  );
  process.exit(1);
}

// Resolve sharp + opentype.js from the design checkout's node_modules. ESM bare
// imports resolve relative to THIS file (which has no node_modules), so we go
// through createRequire anchored at the design checkout and import the resolved
// absolute path.
const designRequire = createRequire(join(DESIGN, "package.json"));
async function fromDesign(pkg) {
  let resolved;
  try {
    resolved = designRequire.resolve(pkg);
  } catch {
    console.error(
      `Cannot resolve "${pkg}" from ${DESIGN}. Run \`npm install\` in the design checkout first.`
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
function outlined(font, text, size, { letterSpacing = 0 } = {}) {
  const p = font.getPath(text, 0, 0, size, { kerning: true, letterSpacing });
  const b = p.getBoundingBox();
  return { d: p.toPathData(2), w: b.x2 - b.x1, x1: b.x1, y1: b.y1, y2: b.y2 };
}
const r2 = (n) => Math.round(n * 100) / 100;

// ---- brand-rule conformance gate (mirrors design scripts/generate-assets.mjs)
// 1. Mustard is reserved for the mark/wordmark — never caption/tagline text.
// 2. No mustard on the navy ground (design variants.json: "Never mustard on a
//    coloured chip or on the navy ground"). No WordPress.org asset uses a navy
//    ground — the directory icon is the favicon treatment on white/paper.
function conform(svg) {
  const navyGround = new RegExp(`<rect[^>]+fill="${NAVY}"`).test(svg);
  if (navyGround && svg.includes(`fill="${MUSTARD}"`))
    throw new Error("brand-rule violation: mustard on the navy ground");
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
// Favicon recipe (design assets/logo/variants.json applications.favicon, and the
// reference implementation cinatra-ai/cinatra src/app/icon.svg): the mustard
// fedora on a clean WHITE/paper ground with a hairline border. The brand rule is
// "Mustard on paper or surface ... Never mustard on a coloured chip or on the
// navy ground" — so the directory icon is NOT the navy app-icon ground. Square
// canvas (no rounded-corner chip). Favicon geometry on the 512 canvas: the drawn
// fedora occupies 368x192 from native (72,64); scale 1.10, translate (-25.6 80)
// centres it edge-to-edge (tx = (512 - 368*1.10)/2 - 72*1.10 = -25.6;
// ty = (512 - 192*1.10)/2 - 64*1.10 = 80). The hairline is the design line token
// rgba(21,33,58,0.14), inset 8 with a 16-unit stroke for sharp 128/256 downsampling.
const HAIRLINE = "rgba(21,33,58,0.14)";
function iconSvg() {
  return conform(
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">` +
      `<rect x="8" y="8" width="496" height="496" fill="#ffffff" stroke="${HAIRLINE}" stroke-width="16"/>` +
      `<g transform="translate(-25.6 80) scale(1.1)" fill="${MUSTARD}">${FEDORA_PATHS}</g>` +
      `</svg>`
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
  colorway: "favicon (mustard fedora on white/paper, hairline border)",
});
await emit("icon-256x256.png", icon, 256, 256, {
  source: "design assets/logo/cinatra-mark.svg",
  colorway: "favicon (mustard fedora on white/paper, hairline border)",
});
await emit("banner-772x250.png", bannerSvg(772, 250), 772, 250, {
  source: "design assets/logo/cinatra-lockup-horizontal.svg",
  colorway: "mustard on paper + slate tagline",
});
await emit("banner-1544x500.png", bannerSvg(1544, 500), 1544, 500, {
  source: "design assets/logo/cinatra-lockup-horizontal.svg",
  colorway: "mustard on paper + slate tagline",
});

// No screenshots are generated. wp.org screenshots must be real browser captures
// of a live WordPress install; synthetic mock-ups misrepresent the product.
// They will be added in a later release once captured.

const manifest = {
  meta: {
    name: "Cinatra WordPress.org directory asset manifest",
    description:
      "Provenance for the WordPress.org plugin-directory assets in .wordpress-org/. Derived from the Cinatra design system (masters + tokens + brand spec); regenerate with tools/generate-wordpress-org-assets.mjs — never hand-edit.",
    designSource: "Cinatra design system",
    note: "icon + banner derive from the design system masters. The directory icon follows the brand favicon treatment — the mustard fedora on a clean white/paper ground with a hairline border (design assets/logo/variants.json: 'Mustard on paper or surface ... Never mustard on a coloured chip or on the navy ground'). No screenshots are shipped yet; a real browser capture of a live WordPress install will be added in a later release.",
  },
  entries,
};
writeFileSync(join(OUT, "manifest.json"), JSON.stringify(manifest, null, 2) + "\n");
console.log(`\nOK — ${entries.length} WordPress.org assets generated; manifest written.`);
