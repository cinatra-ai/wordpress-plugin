#!/usr/bin/env node
// Widget source-of-truth DRIFT / LOCKSTEP gate (cinatra#411).
//
// Runs under plain `node tools/widget-parity-check.mjs` — no bundler, no npm
// install, no WordPress/Drupal. Exit 0 = all invariants hold; exit 1 = drift was
// found. Mirrors the dependency-free spirit of the repo's existing standalone
// harnesses (tests/test-widget-negotiation.mjs, tests/test-token-broker.php) and
// cinatra's `vendor:skills --check`.
//
// WHAT THIS IS (and is NOT)
// -------------------------
// This is a lightweight, NO-DEPENDENCY DRIFT GUARD — a structural/grep-level
// invariant check, NOT a parser-based security firewall and NOT a substitute for
// review. It cheaply catches the regressions that matter most on the canonical
// widget: a long-lived apiKey creeping back into the browser, the token broker
// being bypassed, the contract-version set diverging across CMSs, and the dead
// host bundle route being re-advertised. A determined obfuscation could still
// evade a regex (e.g. computed property access) — the real defense is review +
// the runtime capability/negotiation tests; this gate is the cheap tripwire that
// makes the COMMON drift loud in CI.
//
// WHY IT EXISTS
// -------------
// The shipped, canonical CMS assistant widget is the VENDORED IIFE in each
// plugin/module repo:
//     cinatra-ai/wordpress-plugin/assets/cinatra-widget.js   (authored first)
//     cinatra-ai/drupal-module/js/cinatra-widget.js          (hand-mirrored)
// There is NO generator; the two are kept in lockstep by review (see the
// contract: cinatra docs/widget-source-of-truth.md). Cross-repo byte parity is
// impossible in a single-repo CI, so this gate asserts — on THIS repo's OWN
// copy — the invariants that must never drift, plus a shared contract-version
// marker. Note: a per-repo gate cannot, by itself, FORCE the other repo to
// mirror a change; that lockstep is a review discipline (see INVARIANT 6).
//
// This SAME file is shipped verbatim to both repos (it auto-detects the WP vs
// Drupal config accessor + widget path). Keeping it identical is itself part of
// the parity discipline.

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const SELF_PATH = fileURLToPath(import.meta.url);
const __dirname = path.dirname(SELF_PATH);
const REPO_ROOT = path.resolve(__dirname, "..");

// ---------------------------------------------------------------------------
// Locate this repo's vendored widget copy (WP: assets/, Drupal: js/).
// ---------------------------------------------------------------------------
const CANDIDATES = [
  "assets/cinatra-widget.js", // wordpress-plugin
  "js/cinatra-widget.js", // drupal-module
];
const widgetRel = CANDIDATES.find((rel) =>
  fs.existsSync(path.join(REPO_ROOT, rel)),
);
if (!widgetRel) {
  console.error(
    `FAIL  no vendored widget found (looked for: ${CANDIDATES.join(", ")})`,
  );
  process.exit(1);
}
const WIDGET_PATH = path.join(REPO_ROOT, widgetRel);
const src = fs.readFileSync(WIDGET_PATH, "utf8");

// `code` = the widget with line- and block-comments stripped, so an invariant
// that must hold in EXECUTABLE code is not satisfied (or violated) by prose in a
// comment. The token broker comments legitimately MENTION `apiKey` ("the browser
// holds no apiKey"), so the apiKey-absence check must run against `code`, never
// the raw source.
function stripComments(s) {
  // Remove /* ... */ blocks first, then // ... line comments. The widget is a
  // single template-literal-free IIFE string returned from the route, but here
  // it is plain JS source; this conservative strip is sufficient for the
  // substring invariants below (it does not need to be a full JS parser).
  return s
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .split("\n")
    .map((line) => {
      // Strip a // comment, but not inside a string. Cheap heuristic: only treat
      // `//` as a comment start when it is NOT preceded by a quote on the line up
      // to that point. The widget never embeds `//` inside a string literal
      // except in URLs, which are not relevant to these invariants — and even if
      // one slips through, stripping it cannot create a FALSE PASS (it can only
      // hide a token, which would surface as a missing-required-token FAIL, i.e.
      // fail-closed).
      const idx = line.indexOf("//");
      if (idx === -1) return line;
      const before = line.slice(0, idx);
      const quotes = (before.match(/['"`]/g) || []).length;
      return quotes % 2 === 0 ? before : line;
    })
    .join("\n");
}
const code = stripComments(src);

let failures = 0;
const fails = [];
function assert(label, cond, detail) {
  if (cond) {
    console.log(`  PASS  ${label}`);
  } else {
    console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ""}`);
    failures++;
    fails.push(label);
  }
}

// ---------------------------------------------------------------------------
// Detect which CMS this copy is (drives the config-accessor assertion).
// ---------------------------------------------------------------------------
const isWordPress = /window\.CinatraConfig/.test(code);
const isDrupal = /window\.drupalSettings\s*&&\s*window\.drupalSettings\.cinatra|drupalSettings\.cinatra/.test(
  code,
);
const cms = isWordPress ? "WordPress" : isDrupal ? "Drupal" : "UNKNOWN";
console.log(`widget-parity-check: ${widgetRel} (${cms})`);

assert(
  "config accessor is a recognized CMS broker (CinatraConfig | drupalSettings.cinatra)",
  isWordPress || isDrupal,
  "neither window.CinatraConfig nor drupalSettings.cinatra found",
);

// ---------------------------------------------------------------------------
// INVARIANT 1 — no long-lived apiKey token appears anywhere in EXECUTABLE widget
// code. Banning the whole `apiKey` identifier (not just `config.apiKey`) closes
// the fallback-contamination hole: a `token = token || config.apiKey` or a
// `config["apiKey"]` / `apiKey` local would otherwise slip past a narrow
// `config.apiKey` check while still feeding a Bearer header. Comments may MENTION
// apiKey to explain its ABSENCE ("the browser holds no apiKey"); that is why we
// check `code` (comments stripped), not the raw source.
// ---------------------------------------------------------------------------
const apiKeyMatch = code.match(/\bapiKey\b/);
assert(
  "no long-lived apiKey token in executable widget code (any `apiKey` reference, not just config.apiKey)",
  apiKeyMatch === null,
  apiKeyMatch
    ? "the identifier `apiKey` appears in executable code — the long-lived key must never reach the browser (no config.apiKey, no apiKey fallback, no apiKey local)"
    : undefined,
);

// ---------------------------------------------------------------------------
// INVARIANT 2 — the same-origin token broker is used.
// The widget reads config.tokenEndpoint AND mints via a getStreamToken()-style
// broker fetch.
// ---------------------------------------------------------------------------
assert(
  "same-origin token broker referenced (config.tokenEndpoint read)",
  /config\.tokenEndpoint/.test(code),
  "config.tokenEndpoint is not read — the broker is the only sanctioned credential source",
);
assert(
  "broker mint present (getStreamToken)",
  /function\s+getStreamToken\b/.test(code) && /getStreamToken\s*\(/.test(code),
  "getStreamToken() mint not found",
);

// ---------------------------------------------------------------------------
// INVARIANT 3 — the stream is Bearer-authenticated with the SHORT-LIVED token
// (the value returned by getStreamToken), NOT a config-derived credential.
//
// PRIMARY DEFENSE is INVARIANT 1: with NO `apiKey` token allowed anywhere in
// executable code, a Bearer header CANNOT be built from an apiKey regardless of
// how the header is spelled (quotes/case/template-literal/headers.set). INV3
// below is the positive complement: it confirms a Bearer stream header EXISTS
// and that the var it uses is the getStreamToken() mint sink. We accept several
// header spellings so a stylistic rewrite does not silently drop the positive
// assertion (which would otherwise fail-closed and mask a real regression).
// ---------------------------------------------------------------------------
// Tolerate single/double quotes, optional-quote key, any case, `Bearer ` + var.
// The key is anchored to an object-literal boundary (`{`/`,`/line start) so a
// substring like `XAuthorization:` cannot match, and the `i` flag makes the
// case-insensitivity real (not just `Authorization`/`authorization`).
const BEARER_SINK_RE =
  /(?:^|[,{])\s*["']?authorization["']?\s*:\s*["']Bearer ["']\s*\+\s*([A-Za-z_$][\w$]*)/gim;
const bearerMatches = [...code.matchAll(BEARER_SINK_RE)];
assert(
  "at least one Bearer-authenticated stream fetch exists",
  bearerMatches.length > 0,
  "no `Authorization: Bearer ` + <var> header found (a stylistic rewrite may have changed the header shape — update BEARER_SINK_RE)",
);
// The short-lived token is assigned from getStreamToken() — capture the var name
// it is bound to so the assertion is sink-aware, not just a name allowlist.
// Accept BOTH binding forms the two copies use:
//   WordPress:  `var token = await getStreamToken();`        (declaration)
//   Drupal:     `var streamToken; ... streamToken = await getStreamToken();`
//               (declaration then bare assignment)
const tokenVarMatch = code.match(
  /(?:(?:var|let|const)\s+)?([A-Za-z_$][\w$]*)\s*=\s*await\s+getStreamToken\s*\(/,
);
const tokenVar = tokenVarMatch ? tokenVarMatch[1] : null;
assert(
  "a variable is bound to `await getStreamToken()` (short-lived token mint sink)",
  !!tokenVar,
  "no `<var> = await getStreamToken()` assignment found",
);
// cinatra#1221 S5 — the run-bound RESUME token is a SECOND sanctioned short-lived
// Bearer credential. The unified chat turn (POST /api/assistants/chat) delivers
// it on the `X-Cinatra-Chat-Resume-Token` RESPONSE header, and the widget
// re-presents it on the AG-UI resume/tail GET when the primary stream drops.
// Capture the var it is bound to so this remains a SINK-AWARE allowance (not a
// blanket name allowlist): the binding MUST come from
// `<resp>.headers.get('X-Cinatra-Chat-Resume-Token')`, which proves the value is
// a server-issued short-lived token and NOT a config-derived credential — so the
// "no long-lived / config credential in a Bearer header" invariant still holds
// for EVERY Bearer sink. If the widget carries no resume path, resumeVar is null
// and the only allowed sink stays the getStreamToken() value.
const resumeVarMatch = code.match(
  /([A-Za-z_$][\w$]*)\s*=\s*[A-Za-z_$][\w$.]*\.headers\.get\(\s*['"]X-Cinatra-Chat-Resume-Token['"]\s*\)/,
);
const resumeVar = resumeVarMatch ? resumeVarMatch[1] : null;
// The sanctioned Bearer-sink SET: the cit_ mint value ∪ (when present) the
// resume-header token. A Bearer header using ANY other var fails closed.
const allowedBearerVars = new Set([tokenVar, resumeVar].filter(Boolean));
const everyBearerIsShortLivedToken = bearerMatches.every(
  (m) => allowedBearerVars.has(m[1]),
);
assert(
  "every Bearer header uses a sanctioned short-lived token (getStreamToken() cit_, or the X-Cinatra-Chat-Resume-Token response-header token) — no config-derived credential",
  everyBearerIsShortLivedToken,
  allowedBearerVars.size
    ? `a Bearer header uses a var outside the sanctioned sink set {${[...allowedBearerVars].join(
        ", ",
      )}}: ${bearerMatches.map((m) => m[1]).join(", ")}`
    : "no sanctioned short-lived-token sink to compare against",
);

// ---------------------------------------------------------------------------
// INVARIANT 4 — the shared contract-version marker matches the cross-CMS set.
// The two copies legitimately ORDER the array differently (their negotiation
// loops differ by design: WP first-match-wins newest-first, Drupal last-match
// -wins oldest-first), so we assert the SET, not the order.
// EXPECTED_CONTRACT_VERSIONS is the single shared marker both repos must carry;
// bump it in lockstep when the wire contract gains a version.
// ---------------------------------------------------------------------------
const EXPECTED_CONTRACT_VERSIONS = ["v1", "v2"];
const cvMatch = code.match(/CLIENT_CONTRACT_VERSIONS\s*=\s*\[([^\]]*)\]/);
let declaredVersions = [];
if (cvMatch) {
  declaredVersions = [...cvMatch[1].matchAll(/'([^']+)'|"([^"]+)"/g)].map(
    (m) => m[1] || m[2],
  );
}
const sortedSet = (a) => [...new Set(a)].sort();
assert(
  "CLIENT_CONTRACT_VERSIONS is declared",
  cvMatch !== null,
  "no CLIENT_CONTRACT_VERSIONS array found",
);
assert(
  `contract-version SET matches the shared marker {${EXPECTED_CONTRACT_VERSIONS.join(
    ", ",
  )}} (order may differ by design)`,
  JSON.stringify(sortedSet(declaredVersions)) ===
    JSON.stringify(sortedSet(EXPECTED_CONTRACT_VERSIONS)),
  `declared {${declaredVersions.join(", ")}} != expected {${EXPECTED_CONTRACT_VERSIONS.join(
    ", ",
  )}}`,
);

// ---------------------------------------------------------------------------
// INVARIANT 5 — the dead host bundle route must not creep back in.
// Neither the widget NOR any shipped admin/embed/schema string may reference
// /api/wordpress/bundle.js or /api/drupal/bundle.js. The widget is checked here;
// the admin/embed/schema files are checked below across the shipped tree.
// (The retired routes live in cinatra-ai/cinatra and are scheduled for removal;
// see cinatra docs/widget-source-of-truth.md.)
// ---------------------------------------------------------------------------
const DEAD_ROUTE_RE = /\/api\/(?:wordpress|drupal)\/bundle\.js/;
assert(
  "widget does not reference the dead host bundle route (/api/{wordpress,drupal}/bundle.js)",
  !DEAD_ROUTE_RE.test(code),
  "the widget references a retired bundle.js route",
);

// Scan the shipped/admin/embed/schema surfaces (PHP, .module, .info.yml, JSON,
// templates, AND shipped .js — an admin/embed JS file could otherwise advertise
// the dead route past this gate) for a dead-route reference. Test files and dev
// docs are EXEMPT: they legitimately ASSERT the route is NOT used (e.g.
// responseNotContains '/api/drupal/bundle.js') or narrate the vendoring history.
// README.md is also exempt (repo doc, not shipped admin/embed config) but should
// still describe the canonical model — enforced by review, not this grep.
const SHIPPED_EXTS = new Set([
  ".php",
  ".module",
  ".inc",
  ".yml",
  ".yaml",
  ".json",
  ".twig",
  ".html",
  ".js",
]);
const EXEMPT_DIR_PARTS = new Set([
  "tests",
  "test",
  "vendor",
  "node_modules",
  ".git",
  "docs",
]);
// Two files legitimately contain the dead-route TOKEN and must be exempt from
// the raw-bytes scan:
//   - the widget itself: its header comment NAMES the retired route to explain
//     it is NOT re-vendored from it. Its EXECUTABLE code was already checked
//     (comments stripped) above; a raw-bytes match here would be the comment.
//   - this parity script: it contains DEAD_ROUTE_RE as a literal.
const DEAD_ROUTE_SCAN_EXEMPT = new Set([
  path.resolve(WIDGET_PATH),
  path.resolve(SELF_PATH),
]);
function walk(dir, out) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.name.startsWith(".")) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (EXEMPT_DIR_PARTS.has(entry.name)) continue;
      walk(full, out);
    } else if (
      SHIPPED_EXTS.has(path.extname(entry.name)) &&
      !DEAD_ROUTE_SCAN_EXEMPT.has(path.resolve(full))
    ) {
      out.push(full);
    }
  }
  return out;
}
const shippedFiles = walk(REPO_ROOT, []);
const offenders = [];
for (const f of shippedFiles) {
  const content = fs.readFileSync(f, "utf8");
  if (DEAD_ROUTE_RE.test(content)) {
    offenders.push(path.relative(REPO_ROOT, f));
  }
}
assert(
  "no shipped admin/embed/schema/js string advertises the dead bundle.js route",
  offenders.length === 0,
  offenders.length
    ? `dead-route reference in: ${offenders.join(", ")}`
    : undefined,
);

// ---------------------------------------------------------------------------
// INVARIANT 6 — login-required panel gate (#410). NET-NEW; not present yet.
//
// HONEST SCOPE: this gate runs PER-REPO on THIS repo's OWN copy, so it CANNOT by
// itself force the other repo to mirror a change — if WordPress adds the login
// marker, the WordPress gate enforces it but the Drupal gate stays green with no
// marker (it INFO-warns). Cross-repo mirroring is a REVIEW discipline (this gate
// does not, and cannot single-repo, guarantee it).
//
// HARD ENFORCEMENT begins ONLY after LOGIN_GATE_REQUIRED is flipped to true. This
// is a STATELESS source check: while the flag is false it cannot detect that a
// marker was REMOVED in a later commit (it only sees the current source). So
// durable "the login gate can't be dropped" protection requires the flag flip,
// NOT merely a marker once being present.
//
// When #410 lands, flip LOGIN_GATE_REQUIRED to true IN BOTH repos in the same
// lockstep change (ideally tied to a contract-version bump), so a MISSING marker
// becomes a hard FAIL everywhere. Until then absence is the correct shared state
// and only INFO-warns.
// ---------------------------------------------------------------------------
const LOGIN_GATE_REQUIRED = false; // flip to true in BOTH repos when #410 lands.
const LOGIN_GATE_RE = /panelMode|loginRequired|widget-auth|userToken/;
const hasLoginGate = LOGIN_GATE_RE.test(code);
if (LOGIN_GATE_REQUIRED || hasLoginGate) {
  // Required globally (post-#410 flag flip), OR present in this run: assert it.
  assert(
    "login-required panel gate present (#410 marker; mirror across both CMSs)",
    hasLoginGate,
    "the #410 login gate is required/present-here but its marker is missing",
  );
} else {
  console.log(
    "  INFO  login-required panel gate (#410) not yet present — expected until #410 lands. NOTE: cross-repo mirroring is a review discipline; flip LOGIN_GATE_REQUIRED in BOTH repos when #410 ships.",
  );
}

// ---------------------------------------------------------------------------
console.log("");
if (failures > 0) {
  console.error(
    `widget-parity-check: ${failures} FAIL(s) — security-critical widget drift:`,
  );
  for (const f of fails) console.error(`  - ${f}`);
  console.error(
    "See the source-of-truth contract: cinatra docs/widget-source-of-truth.md",
  );
  process.exit(1);
}
console.log("widget-parity-check: all invariants hold.");
process.exit(0);
