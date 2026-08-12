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
// The assistant conversation lives in a Cinatra-served `/embed/assistant` iframe
// that the widget frames as the SOLE session owner. The vanilla AG-UI renderer +
// SSE stream loop that used to live in the widget are DELETED: the widget NO
// LONGER streams and holds NO `Authorization: Bearer` fetch.
//
// The OLD gate asserted the OPPOSITE of that trust boundary — it REQUIRED a
// `Authorization: Bearer <getStreamToken()>` stream fetch (INV3). That invariant
// became a LIABILITY and was REPLACED, in lockstep with the widget rewrite, by
// the §12 trust-boundary invariants below.
//
// PROTOCOL 2 (cinatra#2674) — WHY THE SET FLIPPED AGAIN, AND HARDER
// -----------------------------------------------------------------
// Two of the invariants this gate ENFORCED are now the exact regressions it must
// FORBID. At protocol 1 the widget minted a `cit_` through a same-origin broker,
// redeemed a `cwu_` through the hosted-PKCE relays, and composed both into a
// credential-bearing BOOTSTRAP; INV2 REQUIRED that mint and INV3f REQUIRED that
// credential pair. The website is no longer a party to the person's sign-in, so:
//
//   * INV2 FLIPPED — from "the cit_ broker mint is present" to "NO token mint,
//     NO broker endpoint, NO sign-in relay call exists anywhere in the widget".
//   * INV3f FLIPPED — from "BOOTSTRAP carries citToken + cwuToken at protocol 1"
//     to "the inbound message is the selector-only CONTEXT at protocol 2, and no
//     credential FIELD or credential-shaped VALUE may appear".
//   * INV6 FLIPPED — from "a login gate is present" to "there is no login gate,
//     no popup, no PKCE and no bearer state on the CMS origin at all".
//   * INV7 is NEW — the outbound credential-shaped value guard must exist AND be
//     the choke point every parent->iframe send passes through.
//
// A flipped invariant is not a weakened one: each replacement forbids exactly the
// thing its predecessor required, so the gate cannot go quiet across the cutover.
//
// UNIFIED-BROKER CUTOVER (cinatra#2029; the "AC2" follow-up slice). The retired
// shell capability pre-flight — CLIENT_CONTRACT_VERSIONS + negotiateCapabilities
// against the bespoke `GET /api/agents/{slug}/capabilities` — has NOW landed its
// removal everywhere: that route was DELETED by cinatra#1991 (no migration window)
// and the AG-UI handshake moved CLIENT-SIDE into the /embed/assistant iframe
// (against the unified broker `GET /api/assistants/chat/capabilities`). So INV4
// FLIPPED from "REQUIRE the pre-flight is present" to "BAN the deleted route +
// BAN the retired pre-flight from creeping back in" (no dual-pathing).
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
// INVARIANT 2 (FLIPPED by cinatra#2674) — NO CREDENTIAL ACQUISITION AT ALL.
//
// This gate used to REQUIRE the same-origin `cit_` broker mint. It now forbids
// it, and everything adjacent to it: the widget must read no broker endpoint,
// call no token mint, and reach no sign-in relay. A bearer that is never fetched
// cannot be composed, logged, stored or leaked, which is the whole property this
// slice buys — so the ban is on ACQUISITION, not merely on transmission.
//
// The bearer prefixes are banned as literals too. The widget legitimately names
// them ONCE, in the credential-shaped-value guard's prefix list (INV7), which is
// why the ban is written as "no prefix outside that array" rather than "no prefix".
// ---------------------------------------------------------------------------
assert(
  "2a — no token-broker endpoint is read (config.tokenEndpoint is GONE)",
  !/config\.tokenEndpoint/.test(code),
  "config.tokenEndpoint reappeared — the widget must obtain no credential; the frame gets its own",
);
assert(
  "2b — no token mint in the widget (getStreamToken / getCachedCitToken are GONE)",
  !/\bgetStreamToken\b/.test(code) && !/\bgetCachedCitToken\b/.test(code),
  "a token mint/cache helper reappeared — the widget must mint and hold no credential",
);
assert(
  "2c — no hosted-PKCE sign-in relay is called (authInitEndpoint / authTokenEndpoint are GONE)",
  !/authInitEndpoint/.test(code) && !/authTokenEndpoint/.test(code) &&
    !/\/widget-auth\b/.test(code),
  "a widget-auth relay endpoint reappeared — those routes answer 410 Gone and the ceremony belongs to the frame",
);
assert(
  "2d — no PKCE ceremony on the CMS origin (no code verifier / challenge / redeem)",
  !/codeVerifier/.test(code) && !/codeChallenge/.test(code) && !/\bredeemCode\b/.test(code),
  "PKCE machinery reappeared in the widget — the frame starts and redeems its own transaction",
);
assert(
  "2e — no sign-in popup is opened from the CMS page (window.open is GONE)",
  !/\bwindow\s*\.\s*open\s*\(/.test(code),
  "the widget opens a window — the hosted sign-in popup is opened BY THE FRAME, top-level on the Cinatra origin",
);
// The ONLY sanctioned appearance of a bearer prefix is the guard's prefix list.
const CREDENTIAL_PREFIX_ARRAY_RE =
  /CREDENTIAL_VALUE_PREFIXES\s*=\s*\[[^\]]*\]/;
const codeOutsidePrefixList = code.replace(CREDENTIAL_PREFIX_ARRAY_RE, "");
const strayPrefix = codeOutsidePrefixList.match(/\b(?:cwu_|cit_)/i);
assert(
  "2f — no cwu_/cit_ bearer literal outside the credential-guard prefix list",
  strayPrefix === null,
  strayPrefix
    ? `a bearer prefix literal (${strayPrefix[0]}) appears in executable code outside CREDENTIAL_VALUE_PREFIXES — the widget must not handle, shape-check or name a bearer anywhere else`
    : undefined,
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

// 3a — sandbox attribute with the exact minimal grant for PROTOCOL 2, and no
// escalation beyond it.
//
// THE TWO POPUP TOKENS ARE NOW REQUIRED, AND THAT IS A CHANGE THIS GATE MUST
// STATE PLAINLY (cinatra#2674). It used to FORBID them. At protocol 1 the parent
// ran the sign-in, so the frame never opened a window and a popup grant would
// have been pure attack surface. At protocol 2 the frame opens the hosted
// sign-in itself: without `allow-popups` its `window.open` returns null and
// NOBODY CAN SIGN IN, and without `allow-popups-to-escape-sandbox` the window it
// opens inherits this sandbox — no forms, no top-level navigation — and the
// ceremony cannot complete. Requiring them is what makes the frame-owned sign-in
// possible at all; everything the frame does NOT need stays forbidden below.
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
const REQUIRED_SANDBOX = [
  "allow-scripts",
  "allow-same-origin",
  "allow-popups", // the frame opens the hosted sign-in
  "allow-popups-to-escape-sandbox", // ...as an ORDINARY top-level Cinatra window
];
const missingRequired = REQUIRED_SANDBOX.filter((t) => !sandboxTokens.includes(t));
assert(
  "iframe sandbox grants exactly what the frame-owned sign-in needs (scripts, same-origin, popups, popup escape)",
  missingRequired.length === 0,
  missingRequired.length
    ? `sandbox='${sandboxVal ?? ""}' is missing ${missingRequired.join(", ")} — without the popup grants the frame's window.open is blocked and nobody can sign in`
    : undefined,
);
const FORBIDDEN_SANDBOX = [
  "allow-top-navigation",
  "allow-top-navigation-by-user-activation",
  "allow-top-navigation-to-custom-protocols",
  "allow-forms",
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
  "iframe sandbox grants NO escalation beyond that (no top-nav/forms/modals/downloads)",
  grantedForbidden.length === 0,
  grantedForbidden.length
    ? `sandbox grants forbidden flag(s): ${grantedForbidden.join(", ")}`
    : undefined,
);
// Exact set, not a superset: a token nobody argued for is drift, and the whole
// point of this block is that each grant has a stated reason.
const unexpectedSandbox = sandboxTokens.filter(
  (t) => !REQUIRED_SANDBOX.includes(t),
);
assert(
  "iframe sandbox is EXACTLY the four justified tokens (no unexplained extras)",
  unexpectedSandbox.length === 0,
  unexpectedSandbox.length
    ? `sandbox grants unexplained token(s): ${unexpectedSandbox.join(", ")}`
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

// 3c — outbound transport discipline (§12b). There are TWO sanctioned transports
// for parent->iframe messages:
//   * the §12b PORT transport — the CONTEXT message (and any later parent->iframe
//     traffic) rides the RETAINED MessagePort the iframe transferred in READY:
//     `bridgePort.postMessage(<msg>)`, carrying NO targetOrigin (the
//     origin-targeted READY transfer that delivered the port IS the binding);
//   * the WINDOW transport — a window post that MUST be addressed to the resolved
//     Cinatra origin: `<win>.postMessage(<msg>, cinatraOrigin)`, NEVER "*".
// We (i) ban a "*" literal anywhere in any postMessage arg list; (ii) require at
// least one post (the context is delivered); (iii) require EVERY post to be one
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
  "at least one postMessage to the frame exists (the CONTEXT message is delivered)",
  postMessageCalls.length > 0,
  "no postMessage call found — the bridge never gives the frame its context",
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

// 3c-2 (§12b) — the CONTEXT message rides a DOCUMENT-BOUND MessagePort. The
// iframe transfers ONE MessageChannel endpoint in READY; the parent RETAINS it
// (`event.ports`) and sends over it (`bridgePort.postMessage`). At protocol 2
// this is DEFENCE IN DEPTH rather than a credential wall — there is no credential
// to misdeliver — but the property it buys still holds: a same-origin replacement
// of the frame's browsing context is a fresh realm that never inherits the
// entangled endpoint, so it cannot take over an established session's channel.
// Two structural markers so a rewrite that silently drops the port transport is
// loud.
assert(
  "§12b: the parent retains the port the iframe transferred in READY (event.ports)",
  /event\s*\.\s*ports\b/.test(code),
  "no `event.ports` read — the iframe transfers a MessagePort in READY that the parent must retain",
);
assert(
  "§12b: the CONTEXT message is sent over the retained port (bridgePort.postMessage)",
  /bridgePort\s*\.\s*postMessage\s*\(/.test(code),
  "no `bridgePort.postMessage(` — the parent must send over the retained transferred port, not a window post",
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

// 3e — the widget must hold NO Authorization: Bearer fetch header. Every
// Bearer-authenticated request moved into the iframe, and since cinatra#2674
// there is no token on this side to put in one.
const BEARER_HEADER_RE =
  /(?:^|[,{])\s*["']?authorization["']?\s*:\s*["']Bearer\b/gim;
const bearerMatches = [...code.matchAll(BEARER_HEADER_RE)];
assert(
  "widget holds NO Authorization: Bearer fetch header (streaming moved into the iframe)",
  bearerMatches.length === 0,
  bearerMatches.length
    ? "an `Authorization: Bearer` header is still present — the widget authenticates nothing; the frame holds the credential"
    : undefined,
);

// ---------------------------------------------------------------------------
// INVARIANT 3f (FLIPPED by cinatra#2674) — the §12 bridge speaks PROTOCOL 2, and
// the retired credential carrier cannot come back. Structural markers so a
// rename/version drift from the core `bridge-protocol.ts` is loud.
//
// The version literal is load-bearing: pinned at 2, a protocol-1 parent cannot
// negotiate with a protocol-2 frame, so there is no silent fallback path to
// parent credential delivery. A gate that accepted either literal would hand that
// fallback back.
// ---------------------------------------------------------------------------
assert(
  "3f — bridge references the ready + CONTEXT message types",
  /cinatra\.embed\.ready/.test(code) && /cinatra\.embed\.context/.test(code),
  "the 'cinatra.embed.ready' / 'cinatra.embed.context' message types are missing",
);
assert(
  "3f — bridge pins EMBED_PROTOCOL_VERSION = 2 (and NOT 1)",
  /EMBED_PROTOCOL_VERSION\s*=\s*2\b/.test(code) &&
    !/EMBED_PROTOCOL_VERSION\s*=\s*1\b/.test(code),
  "EMBED_PROTOCOL_VERSION is not pinned to 2 — a protocol-1 parent must not be able to negotiate",
);
assert(
  "3f — the retired credential-bearing BOOTSTRAP envelope is GONE",
  !/cinatra\.embed\.bootstrap/.test(code) && !/\bbuildBootstrap\b/.test(code) &&
    !/\bsendBootstrap\b/.test(code),
  "the retired 'cinatra.embed.bootstrap' envelope reappeared — the inbound message is the selector-only context",
);
assert(
  "3f — the inbound message carries NO credential field (no auth/citToken/cwuToken)",
  !/citToken/.test(code) && !/cwuToken/.test(code) && !/\bauth\s*:/.test(code),
  "a credential field reappeared on the bridge — the context schema is strict and has no `auth`",
);
// The selectors the context message IS allowed to carry, so a rewrite that
// silently stops naming the site/agent is loud too.
assert(
  "3f — the context carries the public selectors (session.assistant, cms.instanceId, optional site.siteId)",
  /assistant\s*:\s*EMBED_ASSISTANT/.test(code) &&
    /instanceId\s*:\s*config\.instanceId/.test(code) &&
    /siteId\s*:\s*siteId/.test(code) &&
    /boundedSelector\s*\(\s*config\.siteId/.test(code),
  "the context message does not carry the assistant + instanceId + siteId selectors",
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
  "no `nonceEcho:` in the context message — the parent must echo the frame's READY nonce",
);
assert(
  "bridge enforces one-context-per-frame (a guarded `contextSent` flag)",
  /\bcontextSent\b/.test(code) && /if\s*\(\s*contextSent\b/.test(code),
  "no `if (contextSent …` one-context guard found",
);
// NO RETRY STORM. The latch is set BEFORE the send and is never cleared on
// failure, so a refused send (a credential-shaped value, a portless fail-closed,
// a missing instance id) is attempted exactly ONCE per frame. The old ordering
// existed to close an async credential-release gap; the ordering survives for a
// different reason — there is no credential left to release, but a re-entrant or
// retried send would be a storm against a frame that will refuse it identically.
assert(
  "the one-context latch is set BEFORE the send (exactly one attempt per frame, no retry storm)",
  /contextSent\s*=\s*true\s*;\s*\n?\s*if\s*\(\s*!\s*sendToFrame\s*\(\s*buildEmbedContext/.test(code),
  "the READY handler does not set `contextSent = true` immediately before the single `sendToFrame(buildEmbedContext(…))` call",
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
// INVARIANT 3f-3 — NOTHING TOKEN-SHAPED IN THE FRAME URL. The embed src carries
// ONLY the non-secret disambiguators (instanceId, assistant). There is no token
// on this side to put there any more, and the ban stays so a future rewrite
// cannot reintroduce one via history/referrer/logs.
// ---------------------------------------------------------------------------
const embedSrcBuild = code.match(
  /config\.cinatraUrl\s*\+\s*['"]\/embed\/assistant[\s\S]{0,400}?;/,
);
assert(
  "embed iframe src carries NO token (there is none, and none may appear)",
  !!embedSrcBuild && !/token|cit_|cwu_/i.test(embedSrcBuild[0]),
  embedSrcBuild
    ? "the /embed/assistant src builder references a token — the frame URL carries public disambiguators only"
    : "could not locate the /embed/assistant src builder",
);

// ---------------------------------------------------------------------------
// INVARIANT 3g — NON-DISCLOSURE, kept as a floor. The widget holds no credential
// at all now, so these are belt-and-braces against a rewrite that reintroduces
// one and then leaks it:
//   * No web storage at all in the widget (nothing is persisted — the iframe owns
//     history and session), so nothing can land in storage.
//   * No credential-ish identifier is passed to console.* (no log disclosure).
// ---------------------------------------------------------------------------
assert(
  "no web storage in the widget (localStorage/sessionStorage) — tokens cannot be persisted",
  !/\b(?:local|session)Storage\b/.test(code),
  "the widget references localStorage/sessionStorage — the iframe owns persistence; anything credential-ish in storage is XSS-exfiltratable",
);
// Identifier names only — a fixed refusal MESSAGE that contains the WORD
// "credential" is not a disclosure, and banning the word would ban saying so.
const TOKEN_LOG_RE =
  /console\s*\.\s*\w+\s*\([^)]*\b(?:citToken|cwuToken|userToken|cachedToken|getStreamToken|credentialRef)\b/;
assert(
  "no token value is passed to console.* (no log disclosure)",
  !TOKEN_LOG_RE.test(code),
  "a credential identifier appears inside a console.* call",
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
// INVARIANT 4 (FLIPPED by the unified-broker cutover, cinatra#2029) — the shell
// no longer runs its own capability pre-flight. The AG-UI capability/contract
// handshake moved CLIENT-SIDE into the /embed/assistant iframe (unified broker
// `GET /api/assistants/chat/capabilities`); the bespoke `GET /api/agents/{slug}/
// capabilities` it used was DELETED by cinatra#1991 (no migration window). So this
// invariant now BANS, in EXECUTABLE code (comments stripped, so the header may
// still name the retired paths to explain their absence):
//   4a  any reference to the deleted `/capabilities` route (the shell must not
//       fetch it — a live proof was the widget never mounting on a 404);
//   4b  the retired pre-flight machinery (CLIENT_CONTRACT_VERSIONS /
//       negotiateCapabilities) creeping back in — re-adding either is a dual-path
//       regression (the #1991 ruling left NO migration window).
// The unchanged cit_ broker mint (INV2) and the /embed/assistant framing (INV3b)
// are what remain of the credential + wire path.
// ---------------------------------------------------------------------------
assert(
  "4a — no reference to the DELETED /api/agents/{slug}/capabilities route in executable widget code",
  !/\/capabilities\b/.test(code) && !/\/api\/agents\//.test(code),
  "the widget references the retired `/capabilities` (or `/api/agents/…`) negotiation route — it was deleted by cinatra#1991; the iframe negotiates against the unified broker now",
);
assert(
  "4b — the retired shell pre-flight is GONE (no CLIENT_CONTRACT_VERSIONS)",
  !/\bCLIENT_CONTRACT_VERSIONS\b/.test(code),
  "CLIENT_CONTRACT_VERSIONS reappeared — the shell no longer negotiates a client-side contract version (the iframe owns the AG-UI handshake); re-adding it is a dual-path regression",
);
assert(
  "4b — the retired shell pre-flight is GONE (no negotiateCapabilities)",
  !/\bnegotiateCapabilities\b/.test(code),
  "negotiateCapabilities() reappeared — the shell mounts unconditionally now (login-gated); the capability handshake runs inside the /embed/assistant iframe",
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
// INVARIANT 6 (FLIPPED by cinatra#2674) — NO LOGIN GATE ON THE CMS ORIGIN.
//
// #410 put a login gate in this shell: a panel mode, a per-user token in module
// scope, a sign-in button, an auth error line. Every one of those existed because
// the SITE held the credential and had to know whether it had one. It does not,
// and it must not: sign-in is decided and drawn by the frame, on the Cinatra
// origin, and a CMS page that renders auth state is a CMS page that is a party to
// the sign-in. So the #410 marker set is now BANNED, and this is the invariant
// that would go red if someone "helpfully" restored the old panel.
// ---------------------------------------------------------------------------
const LOGIN_GATE_RE = /\bpanelMode\b|\bloginRequired\b|widget-auth|\buserToken\b|\bstartLogin\b|\bforceReLogin\b/;
const loginGateMatch = code.match(LOGIN_GATE_RE);
assert(
  "no login gate / auth state on the CMS origin (the frame owns sign-in)",
  loginGateMatch === null,
  loginGateMatch
    ? `login-gate machinery (${loginGateMatch[0]}) is present — the widget must render no auth UI and hold no auth state; the frame signs the person in`
    : undefined,
);

// ---------------------------------------------------------------------------
// INVARIANT 7 (NEW, cinatra#2674) — THE OUTBOUND CREDENTIAL-SHAPED VALUE GUARD.
//
// Removing the credential FIELD does not remove the possibility of a credential
// VALUE: every field the parent still sends is a string it composes from CMS
// state. So the widget must carry the recursive guard (mirroring
// `containsCredentialShapedValue` in the core bridge-protocol), and — the part
// that actually matters — every parent->iframe send must pass THROUGH it.
//
// Two markers, because either alone is hollow: a guard nothing calls proves
// nothing, and a choke point with no guard in it proves nothing either. The
// runtime proof (a poisoned selector is refused, with a positive control) is
// tests/test-no-credential-egress.mjs; this is the cheap structural tripwire.
// ---------------------------------------------------------------------------
assert(
  "7a — the recursive credential-shaped value guard exists (containsCredentialShapedValue)",
  /function\s+containsCredentialShapedValue\b/.test(code) &&
    /CREDENTIAL_VALUE_PREFIXES\s*=\s*\[/.test(code),
  "no containsCredentialShapedValue() over a CREDENTIAL_VALUE_PREFIXES list — the outbound guard is missing",
);
// The guard must run in the SINGLE send helper, and both transports must be
// reachable only through it: a `bridgePort.postMessage` or a frame window post
// that bypasses `sendToFrame` would be an ungated egress path.
// Brace-matched extraction, not a regex slice: the body contains nested blocks,
// and a lazy `…}` slice would stop at the FIRST inner closing brace and silently
// "prove" the ordering on a truncated fragment.
function functionBody(name) {
  const at = code.search(new RegExp(`function\\s+${name}\\s*\\(`));
  if (at === -1) return "";
  const open = code.indexOf("{", at);
  if (open === -1) return "";
  let depth = 0;
  for (let i = open; i < code.length; i++) {
    if (code[i] === "{") depth++;
    else if (code[i] === "}") {
      depth--;
      if (depth === 0) return code.slice(at, i + 1);
    }
  }
  return "";
}
const sendHelperBody = functionBody("sendToFrame");
// 7c — the guard runs INBOUND as well. "No credential crosses this boundary" is
// a claim about both directions, and the frame is the party that actually holds
// a bearer: an uplink is where one could arrive, and `a11y.liveRegion` lands in
// the CMS page's live region. Both inbound listeners must drop a
// credential-shaped envelope before any field of it is read.
const INBOUND_GUARD_RE = /if\s*\(\s*!\s*inboundIsClean\s*\(\s*d\s*\)\s*\)\s*return\s*;/g;
const inboundGuardHits = (code.match(INBOUND_GUARD_RE) || []).length;
assert(
  "7c — both inbound transports drop a credential-shaped envelope (window + port)",
  /function\s+inboundIsClean\b/.test(code) &&
    /containsCredentialShapedValue\s*\(\s*d\s*\)/.test(code) &&
    inboundGuardHits >= 2,
  `the inbound credential guard is missing or applied on only ${inboundGuardHits} of the two transports (onBridgeMessage + onPortMessage)`,
);

// 7d — the protocol-2 selector bounds are enforced, so one over-long optional
// field cannot reject the whole strict envelope and strand the session.
assert(
  "7d — selectors are bound-checked against the protocol-2 maxima (boundedSelector/SELECTOR_MAX)",
  /SELECTOR_MAX\s*=\s*\{/.test(code) &&
    /function\s+boundedSelector\b/.test(code) &&
    /boundedSelector\s*\(\s*config\.instanceId/.test(code) &&
    /boundedSelector\s*\(\s*config\.siteId/.test(code),
  "no SELECTOR_MAX/boundedSelector bound-checking of the instance id and site handle",
);

assert(
  "7b — every parent->iframe send passes through the guard (sendToFrame refuses first)",
  !!sendHelperBody &&
    /if\s*\(\s*containsCredentialShapedValue\s*\(\s*message\s*\)\s*\)/.test(sendHelperBody) &&
    sendHelperBody.indexOf("containsCredentialShapedValue") <
      sendHelperBody.indexOf("postMessage"),
  "sendToFrame() does not refuse a credential-shaped payload BEFORE any postMessage — the guard must be the choke point, not an unused helper",
);

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
