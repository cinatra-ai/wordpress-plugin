#!/usr/bin/env node
// Widget source-of-truth DRIFT / LOCKSTEP gate (cinatra#411, S5 cinatra#1221).
//
// Runs under plain `node tools/widget-parity-check.mjs` — no bundler, no npm
// install, no WordPress/Drupal. Exit 0 = all invariants hold; exit 1 = drift was
// found. Mirrors the dependency-free spirit of the repo's existing standalone
// harnesses (tests/test-widget-negotiation.mjs, tests/test-token-broker.php).
//
// WHAT THIS IS (and is NOT)
// -------------------------
// This is a lightweight, NO-DEPENDENCY DRIFT GUARD — a structural/grep-level
// invariant check, NOT a parser-based security firewall and NOT a substitute for
// review. It cheaply catches the regressions that matter most on the canonical
// widget. A determined obfuscation could still evade a regex (e.g. computed
// property access) — the real defense is review + the runtime bridge/negotiation
// tests; this gate is the cheap tripwire that makes the COMMON drift loud in CI.
//
// ARCHITECTURE (S5 cinatra#1221) — WHY THE INVARIANT SET CHANGED
// --------------------------------------------------------------
// The assistant conversation now lives in a Cinatra-served `/embed/assistant`
// iframe that the widget frames as the SOLE session owner. The vanilla AG-UI
// renderer + SSE stream loop that used to live in the widget are DELETED: the
// widget NO LONGER streams and holds NO `Authorization: Bearer` fetch. It relays
// the short-lived cit_/cwu_ tokens ONLY into a single postMessage BOOTSTRAP.
//
// The OLD gate asserted the OPPOSITE of the new trust boundary — it REQUIRED a
// `Authorization: Bearer <getStreamToken()>` stream fetch (INV3). That invariant
// is now a LIABILITY: keeping it would force a Bearer stream back into the browser
// or fail every widget PR. It is REPLACED, in lockstep with the widget rewrite, by
// the §12 trust-boundary invariants below. KEPT unchanged: no-apiKey (INV1), the
// cit_ broker mint (INV2, now feeding BOOTSTRAP not a header), the shared
// contract-version set (INV4), the dead-bundle-route ban (INV5), and the
// login-gate marker (INV6). KEPT because AC2 has not landed everywhere yet:
// CLIENT_CONTRACT_VERSIONS + negotiateCapabilities.
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
// comment. The header comments legitimately MENTION `apiKey`, `Bearer`, `"*"`,
// `/wp-json`, etc. to explain their ABSENCE, so those bans must run against
// `code`, never the raw source.
function stripComments(s) {
  return s
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .split("\n")
    .map((line) => {
      // Strip a // comment, but not inside a string. Cheap heuristic: only treat
      // `//` as a comment start when it is NOT preceded by an odd number of
      // quotes on the line up to that point. Stripping a real token can only
      // surface as a missing-required-token FAIL (fail-closed), never a false
      // pass; the URL `//` inside `https://` is even-quoted so it is preserved.
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
// INVARIANT 1 (UNCHANGED) — no long-lived apiKey token appears anywhere in
// EXECUTABLE widget code. Banning the whole `apiKey` identifier closes the
// fallback-contamination hole. Comments may MENTION apiKey to explain its
// ABSENCE; that is why we check `code` (comments stripped).
// ---------------------------------------------------------------------------
const apiKeyMatch = code.match(/\bapiKey\b/);
assert(
  "no long-lived apiKey token in executable widget code (any `apiKey` reference)",
  apiKeyMatch === null,
  apiKeyMatch
    ? "the identifier `apiKey` appears in executable code — the long-lived key must never reach the browser"
    : undefined,
);

// ---------------------------------------------------------------------------
// INVARIANT 2 (KEPT; sink changed) — the same-origin cit_ token broker is used.
// The widget reads config.tokenEndpoint AND mints via getStreamToken(). Under
// the new architecture the minted cit_ token feeds the BOOTSTRAP message (§4),
// NOT a Bearer stream header — but the broker mint itself is unchanged and still
// the ONLY sanctioned credential source.
// ---------------------------------------------------------------------------
assert(
  "same-origin token broker referenced (config.tokenEndpoint read)",
  /config\.tokenEndpoint/.test(code),
  "config.tokenEndpoint is not read — the broker is the only sanctioned credential source",
);
assert(
  "cit_ broker mint present (getStreamToken)",
  /function\s+getStreamToken\b/.test(code) && /getStreamToken\s*\(/.test(code),
  "getStreamToken() mint not found",
);

// ---------------------------------------------------------------------------
// INVARIANT 3 (REPLACES the old Bearer-stream invariant) — the §12 sandboxed
// iframe trust boundary. The widget no longer streams; it frames the Cinatra
// `/embed/assistant` surface and speaks the parent↔iframe bridge. Five checks:
//   3a  a sandboxed iframe is created (allow-scripts + allow-same-origin ONLY;
//       NO escalation flags: top-nav / forms / popups / modals / downloads /
//       pointer-lock / popups-to-escape-sandbox).
//   3b  its src targets the Cinatra `/embed/assistant` route built from
//       config.cinatraUrl and carries the instanceId + assistant disambiguators.
//   3c  every postMessage uses an EXPLICIT targetOrigin — NEVER "*".
//   3d  inbound frame messages are gated on BOTH `event.origin === <cinatra
//       origin>` AND `event.source === <frame window>` (source-window binding).
//   3e  the widget holds NO `Authorization: Bearer` fetch header anywhere (the
//       stream moved into the iframe; the OLD required-Bearer invariant is now a
//       banned-Bearer invariant — the crux of the trust-boundary flip).
// ---------------------------------------------------------------------------

// 3a — sandbox attribute with the exact minimal grant, no escalation flags.
const sandboxMatch = code.match(
  /setAttribute\(\s*['"]sandbox['"]\s*,\s*['"]([^'"]*)['"]\s*\)/,
);
const sandboxVal = sandboxMatch ? sandboxMatch[1] : null;
assert(
  "embed iframe is created with a sandbox attribute",
  sandboxVal !== null,
  "no `setAttribute('sandbox', '…')` on the embed iframe",
);
const sandboxTokens = sandboxVal ? sandboxVal.trim().split(/\s+/) : [];
assert(
  "iframe sandbox grants allow-scripts and allow-same-origin",
  sandboxTokens.includes("allow-scripts") &&
    sandboxTokens.includes("allow-same-origin"),
  `sandbox='${sandboxVal ?? ""}' is missing allow-scripts / allow-same-origin`,
);
const FORBIDDEN_SANDBOX = [
  "allow-top-navigation",
  "allow-top-navigation-by-user-activation",
  "allow-top-navigation-to-custom-protocols",
  "allow-forms",
  "allow-popups",
  "allow-popups-to-escape-sandbox",
  "allow-modals",
  "allow-downloads",
  "allow-pointer-lock",
  "allow-presentation",
  "allow-orientation-lock",
];
const grantedForbidden = sandboxTokens.filter((t) =>
  FORBIDDEN_SANDBOX.includes(t),
);
assert(
  "iframe sandbox grants NO escalation flags (no top-nav/forms/popups/modals/downloads)",
  grantedForbidden.length === 0,
  grantedForbidden.length
    ? `sandbox grants forbidden flag(s): ${grantedForbidden.join(", ")}`
    : undefined,
);

// 3b — the iframe src is the Cinatra /embed/assistant route with the
// disambiguators, built from config.cinatraUrl (NOT a hardcoded origin).
assert(
  "iframe src targets the Cinatra /embed/assistant route from config.cinatraUrl",
  /config\.cinatraUrl\s*\+\s*['"]\/embed\/assistant/.test(code),
  "no `config.cinatraUrl + '/embed/assistant'` iframe src construction found",
);
assert(
  "iframe src carries the instanceId + assistant disambiguators",
  /instanceId=/.test(code) && /assistant=/.test(code),
  "the embed src does not carry both instanceId= and assistant= query params",
);

// 3c — outbound transport discipline (§12b). There are now TWO sanctioned
// transports for parent->iframe messages:
//   * the §12b PORT transport — the token-bearing bootstrap (and any later
//     parent->iframe traffic) rides the RETAINED MessagePort the iframe
//     transferred in READY: `bridgePort.postMessage(<msg>)`, carrying NO
//     targetOrigin (the origin-targeted READY transfer that delivered the port IS
//     the binding);
//   * the LEGACY WINDOW transport (negotiated transition) — a window post that
//     MUST be addressed to the resolved Cinatra origin: `<win>.postMessage(<msg>,
//     cinatraOrigin)`, NEVER "*".
// We (i) ban a "*" literal anywhere in any postMessage arg list; (ii) require at
// least one post (the bootstrap is delivered); (iii) require EVERY post to be one
// of the two sanctioned forms. A post to any other/computed origin, or a BARE
// window post that drops the targetOrigin, fails here.
// Ban a "*" literal ANYWHERE in a postMessage argument list (not just immediately
// before `)`), so a `postMessage(msg, cinatraOrigin || "*")` short-circuit is
// caught too.
const WILDCARD_POSTMESSAGE_RE = /\.postMessage\s*\([^;)]*?(['"])\*\1/;
assert(
  'no postMessage uses a wildcard "*" targetOrigin (anywhere in the args)',
  !WILDCARD_POSTMESSAGE_RE.test(code),
  'a postMessage with a "*" argument was found — every window post must be addressed to the exact Cinatra origin, and a port post carries no target',
);
// Capture BOTH the receiver and the arg list of every call so a port post
// (`bridgePort`, no targetOrigin) is told apart from a window post (targetOrigin
// required). `[^;)]*?` stops at the first `)`; the widget's args are plain
// identifiers so this is exact.
const POSTMESSAGE_CALL_RE = /([A-Za-z_$][\w$.]*)\.postMessage\s*\(([^;)]*?)\)/g;
const postMessageCalls = [...code.matchAll(POSTMESSAGE_CALL_RE)].map((m) => ({
  receiver: m[1],
  args: m[2].trim(),
}));
assert(
  "at least one postMessage to the frame exists (BOOTSTRAP is delivered)",
  postMessageCalls.length > 0,
  "no postMessage call found — the bridge never bootstraps the frame",
);
// A window post's arg list ENDS with `, cinatraOrigin` and nothing appended (this
// rejects a computed/short-circuit target such as `cinatraOrigin || "*"`). A port
// post is a SINGLE argument (no top-level comma) on the retained `bridgePort`.
const isWindowPostToCinatraOrigin = (c) => /,\s*cinatraOrigin\s*$/.test(c.args);
const isRetainedPortPost = (c) =>
  c.receiver === "bridgePort" && !/,/.test(c.args);
const everyPostSanctioned = postMessageCalls.every(
  (c) => isWindowPostToCinatraOrigin(c) || isRetainedPortPost(c),
);
assert(
  "every postMessage is a window post to EXACTLY `cinatraOrigin` OR a targetless post on the retained bridgePort (§12b)",
  everyPostSanctioned,
  "a postMessage is neither an origin-pinned `<win>.postMessage(<msg>, cinatraOrigin)` nor a `bridgePort.postMessage(<msg>)` — no computed/short-circuit origin, and no bare window post that drops the targetOrigin",
);

// 3c-2 (§12b) — the token-bearing bootstrap rides a DOCUMENT-BOUND MessagePort.
// The iframe transfers ONE MessageChannel endpoint in the token-free READY; the
// parent RETAINS it (`event.ports`) and sends the bootstrap over it
// (`bridgePort.postMessage`) — never via a window post — so a same-origin
// replacement of the frame's browsing context (a fresh realm that never inherits
// the entangled endpoint) can never receive the tokens. Two structural markers so
// a rewrite that drops the port transport (and silently falls back to a
// window-only bootstrap) is loud.
assert(
  "§12b: the parent retains the port the iframe transferred in READY (event.ports)",
  /event\s*\.\s*ports\b/.test(code),
  "no `event.ports` read — the iframe transfers a MessagePort in READY that the parent must retain",
);
assert(
  "§12b: the token-bearing bootstrap is sent over the retained port (bridgePort.postMessage)",
  /bridgePort\s*\.\s*postMessage\s*\(/.test(code),
  "no `bridgePort.postMessage(` — the parent must send the bootstrap over the retained transferred port, not a window post",
);

// 3d — inbound gate MUST be the REJECT form (a `!==` early-return), not merely a
// mention of the identifiers: `if (event.origin === cinatraOrigin) return` would
// invert the gate yet pass a loose "mentions both sides" check. Require the
// `!==` reject spelling for BOTH the origin and the source-window binding.
assert(
  "inbound bridge REJECTS on event.origin !== the Cinatra origin (reject-form gate)",
  /event\.origin\s*!==\s*cinatraOrigin/.test(code),
  "no `event.origin !== cinatraOrigin` reject-form gate on the inbound bridge",
);
assert(
  "inbound bridge REJECTS on event.source !== the frame window (source-window binding)",
  /event\.source\s*!==\s*frameWindow/.test(code),
  "no `event.source !== frameWindow` reject-form binding on the inbound bridge",
);

// 3e — THE FLIP: the widget must hold NO Authorization: Bearer fetch header. The
// stream (and thus every Bearer-authenticated request) moved into the iframe;
// tokens travel ONLY via the postMessage BOOTSTRAP now.
const BEARER_HEADER_RE =
  /(?:^|[,{])\s*["']?authorization["']?\s*:\s*["']Bearer\b/gim;
const bearerMatches = [...code.matchAll(BEARER_HEADER_RE)];
assert(
  "widget holds NO Authorization: Bearer fetch header (streaming moved into the iframe)",
  bearerMatches.length === 0,
  bearerMatches.length
    ? "an `Authorization: Bearer` header is still present — the widget must not direct-stream-auth; tokens go via BOOTSTRAP"
    : undefined,
);

// ---------------------------------------------------------------------------
// INVARIANT 3f — the §12 bridge speaks the pinned protocol. Structural markers
// so a rename/version drift from the core `bridge-protocol.ts` is loud.
// ---------------------------------------------------------------------------
assert(
  "bridge references the ready + bootstrap message types",
  /cinatra\.embed\.ready/.test(code) && /cinatra\.embed\.bootstrap/.test(code),
  "the 'cinatra.embed.ready' / 'cinatra.embed.bootstrap' message types are missing",
);
assert(
  "bridge pins EMBED_PROTOCOL_VERSION = 1",
  /EMBED_PROTOCOL_VERSION\s*=\s*1\b/.test(code),
  "EMBED_PROTOCOL_VERSION is not pinned to 1",
);
// The BOOTSTRAP is the ONLY credential carrier: it relays both tokens.
assert(
  "BOOTSTRAP carries the cit_ + cwu_ tokens (citToken/cwuToken in auth)",
  /citToken\s*:/.test(code) && /cwuToken\s*:/.test(code),
  "the bootstrap auth object does not carry both citToken and cwuToken",
);

// ---------------------------------------------------------------------------
// INVARIANT 3f-2 — the bridge's runtime trust controls each leave a structural
// marker so a rewrite that DROPS one is loud (the grep can't prove the runtime
// behavior, but a missing marker means the control is almost certainly gone):
//   * nonce echo (parent echoes the frame's READY nonce),
//   * single bootstrap per frame (a guarded flag),
//   * per-frame binding of the async bootstrap (frame generation),
//   * uplink correlationId binding (drop a message whose correlationId differs),
//   * a monotonic inbound seq gate (drop a non-increasing seq).
// ---------------------------------------------------------------------------
assert(
  "bridge echoes the frame nonce (nonceEcho)",
  /nonceEcho\s*:/.test(code),
  "no `nonceEcho:` in the bootstrap — the parent must echo the frame's READY nonce",
);
assert(
  "bridge enforces single-bootstrap-per-frame (a guarded `bootstrapped` flag)",
  /\bbootstrapped\b/.test(code) && /if\s*\(\s*bootstrapped\b/.test(code),
  "no `if (bootstrapped …` single-bootstrap guard found",
);
// The cit_ token is PRE-MINTED before the frame mounts so the READY->BOOTSTRAP
// release is SYNCHRONOUS (no await between receiving READY and posting the
// bootstrap). A same-origin frame navigation cannot interleave within one
// synchronous task, so credentials can never reach a document that navigated in
// mid-release. Two markers: (a) the pre-mint precedes the mount; (b) the release
// reads the token from the synchronous cache, never an inline `await`.
const enterConvMatch = code.match(
  /function\s+enterConversation\b[\s\S]{0,600}?\n\s{0,4}\}/,
);
const enterConvBody = enterConvMatch ? enterConvMatch[0] : "";
assert(
  "cit_ is PRE-MINTED before the frame mounts (getStreamToken precedes mountBridgeIframe)",
  /getStreamToken\s*\(/.test(enterConvBody) &&
    /mountBridgeIframe\s*\(/.test(enterConvBody) &&
    enterConvBody.indexOf("getStreamToken") < enterConvBody.indexOf("mountBridgeIframe"),
  "enterConversation does not pre-mint cit_ (getStreamToken) BEFORE mounting the frame — the bootstrap release would not be synchronous",
);
assert(
  "BOOTSTRAP is released SYNCHRONOUSLY from the pre-minted cache (getCachedCitToken)",
  /getCachedCitToken\s*\(/.test(code) &&
    /bootstrapped\s*=\s*true\s*;\s*sendBootstrap\s*\(\s*buildBootstrap/.test(code),
  "the READY handler does not release the bootstrap synchronously from getCachedCitToken() — an async mint-then-post reopens the navigation-release gap",
);
assert(
  "bridge binds uplinks to the minted correlationId (drop on mismatch)",
  /\.correlationId\s*!==\s*correlationId/.test(code),
  "no `<msg>.correlationId !== correlationId` binding on uplinks",
);
assert(
  "bridge enforces a monotonic inbound seq gate (drop non-increasing seq)",
  /seq\s*<=\s*inboundSeqLast/.test(code),
  "no `seq <= inboundSeqLast` monotonic drop found",
);

// ---------------------------------------------------------------------------
// INVARIANT 3f-3 — TOKENS NOT IN THE FRAME URL. The embed src carries ONLY the
// non-secret disambiguators (instanceId, assistant); a token in the URL would
// leak it via history/referrer/logs. Assert the `/embed/assistant` src builder
// contains no token identifier.
// ---------------------------------------------------------------------------
const embedSrcBuild = code.match(
  /config\.cinatraUrl\s*\+\s*['"]\/embed\/assistant[\s\S]{0,400}?;/,
);
assert(
  "embed iframe src carries NO token (tokens travel ONLY via BOOTSTRAP)",
  !!embedSrcBuild && !/token|cit_|cwu_/i.test(embedSrcBuild[0]),
  embedSrcBuild
    ? "the /embed/assistant src builder references a token — tokens must never be in the frame URL"
    : "could not locate the /embed/assistant src builder",
);

// ---------------------------------------------------------------------------
// INVARIANT 3g — TOKEN NON-DISCLOSURE. The cit_/cwu_ tokens are relayed ONLY into
// the BOOTSTRAP message: never persisted, never logged, never in a URL.
//   * No web storage at all in the widget (nothing is persisted now — history
//     moved into the iframe), so a token can never land in storage.
//   * No token variable is passed to console.* (no log/telemetry disclosure).
// ---------------------------------------------------------------------------
assert(
  "no web storage in the widget (localStorage/sessionStorage) — tokens cannot be persisted",
  !/\b(?:local|session)Storage\b/.test(code),
  "the widget references localStorage/sessionStorage — the iframe owns persistence; a token in storage is XSS-exfiltratable",
);
const TOKEN_LOG_RE =
  /console\s*\.\s*\w+\s*\([^)]*\b(?:citToken|cwuToken|userToken|cachedToken|getStreamToken)\b/;
assert(
  "no token value is passed to console.* (no log disclosure)",
  !TOKEN_LOG_RE.test(code),
  "a token identifier appears inside a console.* call",
);

// ---------------------------------------------------------------------------
// INVARIANT 3h — #1214 NO-DIRECT-EGRESS on apply. Field-apply happens server-side
// via the CMS MCP integration; on apply_intent the parent does an IN-PLACE draft
// refresh through the CMS's OWN data layer — it never constructs a /wp-json (or
// /wp/v2 / JSON:API) content fetch and never reloads the page. So:
//   * no literal `/wp-json` or `/wp/v2/` or `/jsonapi/` request string in the
//     widget (broker endpoints arrive as opaque config.* values, not literals);
//   * no `window.location.reload` (the old post-apply reload is gone).
// ---------------------------------------------------------------------------
assert(
  "no direct WP/Drupal content-egress URL literal in the widget (#1214)",
  !/\/wp-json\b/.test(code) && !/\/wp\/v2\//.test(code) && !/\/jsonapi\b/.test(code),
  "a /wp-json | /wp/v2/ | /jsonapi content-egress literal is present — apply must not direct-egress; the CMS MCP integration applies fields",
);
assert(
  "no page reload on apply (#1214 in-place draft refresh, not a reload)",
  !/location\s*\.\s*reload\s*\(/.test(code),
  "window.location.reload() is present — apply must do an in-place draft refresh, not a reload",
);

// ---------------------------------------------------------------------------
// INVARIANT 3i — apply_intent selector discipline + resize clamp. The apply path
// must (a) route through the in-place refresh, (b) re-check edit permission, (c)
// enforce presence-XOR of the selector, (d) use the parent's OWN canonical
// resource (buildContentContext) — never the message id — as the refresh target,
// (e) bound-dedup via an LRU, and (f) never dynamically egress a message-supplied
// URL. Structural markers so removing any of these is loud.
// ---------------------------------------------------------------------------
assert(
  "apply_intent is handled and routes through an in-place draft refresh",
  /cinatra\.embed\.apply_intent/.test(code) &&
    /refreshCurrentDraft\s*\(/.test(code),
  "the apply_intent handler / refreshCurrentDraft() in-place refresh is missing",
);
assert(
  "apply_intent re-checks edit permission (currentUserMayEdit)",
  /currentUserMayEdit\s*\(/.test(code),
  "no currentUserMayEdit() permission re-check in the apply path",
);
assert(
  "apply_intent enforces selector presence-XOR (proposalPresent === changeSetPresent -> drop)",
  /proposalPresent\s*===\s*changeSetPresent/.test(code),
  "no presence-XOR guard — a message carrying both/neither selector could slip through",
);
assert(
  "apply refresh targets the parent's OWN canonical resource (buildContentContext)",
  /buildContentContext\s*\(/.test(code),
  "the apply path does not derive the resource from buildContentContext() (the message id must never be the selector)",
);
assert(
  "apply_intent bounded LRU dedup (appliedLru)",
  /appliedLru/.test(code) && /appliedLru\.indexOf/.test(code),
  "no bounded-LRU dedup (appliedLru) in the apply path",
);
// No dynamic egress: the widget must never fetch a URL taken from a bridge
// message (the closed uplink schema carries no URL; a `fetch(d.<x>)` /
// `fetch(msg…)` would be an exfiltration/SSRF-style regression).
const DYNAMIC_EGRESS_RE = /fetch\s*\(\s*(?:d|msg|message|event|payload)\b/;
assert(
  "no dynamic egress of a bridge-message-supplied URL (no fetch(d.…)/fetch(msg…))",
  !DYNAMIC_EGRESS_RE.test(code),
  "a fetch() takes its URL from a bridge message — the uplink schema carries no URL and none may be egressed",
);
assert(
  "resize height is clamped (Math.min against a panel cap), not trusted",
  /Math\.min\s*\([^)]*maxPanelHeight\s*\(\)/.test(code) ||
    /Math\.min\s*\([^)]*RESIZE_MAX_HEIGHT/.test(code),
  "no Math.min clamp of the resize height against the panel cap found",
);

// ---------------------------------------------------------------------------
// INVARIANT 4 (UNCHANGED) — the shared contract-version marker matches the
// cross-CMS set. Assert the SET, not the order (the two copies order the array
// differently by design). Bump EXPECTED_CONTRACT_VERSIONS in lockstep when the
// wire contract gains a version.
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
  "CLIENT_CONTRACT_VERSIONS is declared (negotiation path KEPT until AC2)",
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
// The negotiation entry point itself must remain (mount HARD PREREQUISITE).
assert(
  "negotiateCapabilities() is present (mount hard-prerequisite, KEPT until AC2)",
  /function\s+negotiateCapabilities\b/.test(code),
  "negotiateCapabilities() was removed — negotiation is kept until AC2 lands everywhere",
);

// ---------------------------------------------------------------------------
// INVARIANT 5 (UNCHANGED) — the dead host bundle route must not creep back in.
// The widget is checked here; the admin/embed/schema/js tree is scanned below.
// ---------------------------------------------------------------------------
const DEAD_ROUTE_RE = /\/api\/(?:wordpress|drupal)\/bundle\.js/;
assert(
  "widget does not reference the dead host bundle route (/api/{wordpress,drupal}/bundle.js)",
  !DEAD_ROUTE_RE.test(code),
  "the widget references a retired bundle.js route",
);

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
  // Gitignored build-output staging (bin/build-wporg.sh regenerates it from the
  // current source). It is not tracked and never present in a fresh CI checkout;
  // a stale local copy must not fail the source-of-truth scan.
  "build",
]);
// Two files legitimately contain the dead-route TOKEN and must be exempt from the
// raw-bytes scan: the widget itself (its executable code was already checked,
// comments stripped) and this parity script (it contains DEAD_ROUTE_RE literally).
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
// INVARIANT 6 (UNCHANGED, now HARD) — login-required panel gate (#410). The gate
// is present in BOTH copies, so it is enforced unconditionally. This is a
// STATELESS source check (it sees only the current source), so durable protection
// is the flag being true here.
// ---------------------------------------------------------------------------
const LOGIN_GATE_REQUIRED = true; // #410 landed: the login gate is required.
const LOGIN_GATE_RE = /panelMode|loginRequired|widget-auth|userToken/;
const hasLoginGate = LOGIN_GATE_RE.test(code);
if (LOGIN_GATE_REQUIRED || hasLoginGate) {
  assert(
    "login-required panel gate present (#410 marker; mirror across both CMSs)",
    hasLoginGate,
    "the #410 login gate marker is required/present-here but missing",
  );
} else {
  console.log(
    "  INFO  login-required panel gate (#410) not present (unexpected once #410 landed).",
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
