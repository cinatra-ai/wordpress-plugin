#!/usr/bin/env node
// Widget source-of-truth DRIFT / LOCKSTEP gate (cinatra#411, S5 cinatra#1221).
//
// Runs under plain `node tools/widget-parity-check.mjs` — no bundler, no npm
// install, no WordPress/Drupal. Exit 0 = all invariants hold; exit 1 = drift was
// found. Mirrors the dependency-free spirit of the repos' existing standalone
// harnesses (tests/test-widget-negotiation.mjs, tests/test-no-credential-egress.mjs).
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
// THIS FILE AND THE WIDGET ARE BOTH SHIPPED VERBATIM TO BOTH REPOS
// ----------------------------------------------------------------
// cinatra-ai/wordpress-plugin (assets/cinatra-widget.js) and cinatra-ai/drupal-module
// (js/cinatra-widget.js) carry BYTE-IDENTICAL copies of the widget and of this
// gate. It used to be true only of this gate: the widget was authored in the
// WordPress copy and hand-mirrored, and two independent protocol-2 lanes then
// hardened the two copies in DIFFERENT places — a security asymmetry that no gate
// could see, because each copy was checked only against itself. The widget is now
// ONE file carrying the UNION of both lanes' protections, so this gate asserts the
// UNION on whichever copy it is run against, and a lane that hardens one repo
// alone shows up as a byte diff rather than as a quiet gap in the other.
//
// It AUTO-DETECTS the CMS from the widget PATH (WP: assets/, Drupal: js/) rather
// than from the config accessor, because the accessor no longer distinguishes the
// copies: the unified widget reads BOTH brokers and branches at runtime. That is
// itself asserted below — a copy that lost one broker would be a silently
// CMS-specific file again.
//
// ARCHITECTURE — WHY THE INVARIANT SET IS WHAT IT IS
// ---------------------------------------------------
// The assistant conversation lives in a Cinatra-served `/embed/assistant` iframe
// that the widget frames as the SOLE session owner (S5 cinatra#1221). The vanilla
// AG-UI renderer + SSE stream loop that used to live in the widget are gone: the
// widget does not stream and holds no `Authorization: Bearer` fetch.
//
// PROTOCOL 2 (cinatra#2674) FLIPPED THE CREDENTIAL INVARIANTS THEMSELVES.
// At protocol 1 this gate REQUIRED the widget to mint a short-lived `cit_` token
// through a same-origin broker and to carry `citToken`/`cwuToken` in a BOOTSTRAP
// message. That was the correct invariant then and is a LIABILITY now: the site is
// no longer a party to the sign-in at all. The frame mints its own credential on
// the Cinatra origin; the widget sends ONE selector-only CONTEXT message.
//
// So the credential invariants are INVERTED rather than deleted — the difference
// matters, because a deleted invariant is silent about a regression and an
// inverted one is loud. This gate now REQUIRES the absence it used to forbid:
//   * no credential ACQUISITION of any kind — no broker endpoint, no token mint,
//     no sign-in relay, no PKCE, no popup opened from the CMS page (INV2, flipped);
//   * the message type is `cinatra.embed.context` at version literal 2, and the
//     retired `…bootstrap` type / `citToken` / `cwuToken` / `auth:` field cannot
//     come back (INV3f, flipped);
//   * no credential-shaped VALUE may be composed, on the bridge OR in the frame
//     URL, and the guards that enforce it must be present and be the choke points
//     (INV3g / INV7, extended);
//   * the shell runs NO login gate of its own — the frame owns sign-in (INV6,
//     flipped);
//   * the iframe sandbox grants the popup capability the frame-owned sign-in
//     needs, and still nothing else (INV3a, widened by exactly two flags).
//
// The unified-broker cutover invariants (INV4) and the dead-bundle-route ban
// (INV5) are unchanged.

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const SELF_PATH = fileURLToPath(import.meta.url);
const __dirname = path.dirname(SELF_PATH);
const REPO_ROOT = path.resolve(__dirname, "..");

// ---------------------------------------------------------------------------
// Locate this repo's vendored widget copy (WP: assets/, Drupal: js/). The PATH is
// the CMS discriminator now — the file's own content is identical in both repos.
// ---------------------------------------------------------------------------
const CANDIDATES = [
  ["assets/cinatra-widget.js", "WordPress"], // wordpress-plugin
  ["js/cinatra-widget.js", "Drupal"], // drupal-module
];
const found = CANDIDATES.find(([rel]) =>
  fs.existsSync(path.join(REPO_ROOT, rel)),
);
if (!found) {
  console.error(
    `FAIL  no vendored widget found (looked for: ${CANDIDATES.map(([r]) => r).join(", ")})`,
  );
  process.exit(1);
}
const [widgetRel, cms] = found;
const WIDGET_PATH = path.join(REPO_ROOT, widgetRel);
const src = fs.readFileSync(WIDGET_PATH, "utf8");

// `code` = the widget with line- and block-comments stripped, so an invariant
// that must hold in EXECUTABLE code is not satisfied (or violated) by prose in a
// comment. The header comments legitimately MENTION `apiKey`, `Bearer`, `"*"`,
// `/wp-json`, etc. to explain their ABSENCE, so those bans must run against
// `code`, never the raw source.
//
// THIS IS A LEXER, NOT A LINE HEURISTIC, AND THAT MATTERS. Both lanes shipped a
// per-line heuristic that treated `//` as a comment start unless an ODD number of
// quotes preceded it on the line. It was documented as fail-closed — "stripping a
// real token can only turn a REQUIRED token red, never turn a BAN green" — and
// that claim is false. The counterexample:
//
//     const normalized = value.replace(/\//g, "_"), apiKey = secret;
//
// The `//` inside the regex `/\//` is preceded by an even number of quotes, so
// the heuristic truncated the line at it — deleting the executable `apiKey` and
// turning the apiKey BAN falsely green. (The canonical widget contains a line of
// exactly that shape, in `b64url`.) A ban that can be silenced by writing a regex
// is not a ban, so the stripper tracks string, template, regex and comment state
// properly instead.
function stripComments(s) {
  // `/` after one of these keywords starts a REGEX, not a division. Classifying
  // on the previous CHARACTER alone gets `return /re/.test(x)` wrong (the `n` of
  // `return` looks like the end of an identifier), so the trailing word is
  // tracked too.
  const KEYWORD_BEFORE_REGEX = new Set([
    "return", "typeof", "instanceof", "in", "of", "new", "delete", "void",
    "case", "do", "else", "yield", "await", "throw",
  ]);
  let out = "";
  let i = 0;
  let prevSignificant = "";  // last emitted non-space character
  let prevWord = "";         // ...and the identifier it ends, if it ends one
  // Template literals nest: `` `a${ b ? `c` : d }e` `` is template text, then
  // CODE, then template text again. Each entry counts the braces opened inside
  // the CURRENT `${ }` so the matching `}` returns to text rather than being read
  // as code — without this, a comment inside a template expression survives
  // stripping and can turn a ban falsely RED.
  const templates = [];
  let inTemplateText = false;
  // `)` normally ends an expression, so `/` after it is division — except when the
  // `)` closed a CONTROL condition: `if (x) /re/.test(x)` is a regex, and reading
  // it as division makes the lexer swallow the rest of the file. Each `(`
  // remembers whether a control keyword introduced it.
  const CONTROL_BEFORE_PAREN = new Set(["if", "for", "while", "switch", "catch", "with"]);
  const parens = [];
  let lastParenWasControl = false;
  // A keyword is only a keyword as a TOKEN. `obj.if(x)`, `obj.return` and
  // `this.#return` are member names, and treating them as syntax misclassifies
  // what follows — so the character that
  // introduced the trailing word is tracked, and a leading `.` (member access) or
  // `#` (private name) disqualifies it.
  let wordPrefix = "";
  const isKeywordToken = (set) => wordPrefix !== "." && wordPrefix !== "#" && set.has(prevWord);
  const regexAllowed = () => {
    if (prevSignificant === "") return true;
    if (/[A-Za-z0-9_$]/.test(prevSignificant)) return isKeywordToken(KEYWORD_BEFORE_REGEX);
    if (prevSignificant === ")") return lastParenWasControl;
    return prevSignificant !== "]";
  };
  const emitSignificant = (c) => {
    out += c;
    if (/\s/.test(c)) return;
    if (c === "(") parens.push(isKeywordToken(CONTROL_BEFORE_PAREN));
    else if (c === ")") lastParenWasControl = parens.length ? parens.pop() : false;
    if (/[A-Za-z0-9_$]/.test(c)) {
      if (prevWord === "") wordPrefix = prevSignificant;
      prevWord += c;
    } else {
      prevWord = "";
      wordPrefix = "";
    }
    prevSignificant = c;
  };
  while (i < s.length) {
    const c = s[i];
    const next = s[i + 1];
    if (inTemplateText) {
      if (c === "\\") { out += c + (next ?? ""); i += 2; continue; }
      if (c === "`") {
        out += c; i++;
        inTemplateText = false;
        templates.pop();
        prevSignificant = "`"; prevWord = "";
        continue;
      }
      if (c === "$" && next === "{") {
        out += "${"; i += 2;
        inTemplateText = false;
        templates[templates.length - 1].braces = 0;
        prevSignificant = "{"; prevWord = "";
        continue;
      }
      out += c; i++;
      continue;
    }
    if (c === "/" && next === "/") {
      // Line comment: consume to the newline, keep the newline so line-oriented
      // checks below still see the original line structure.
      while (i < s.length && s[i] !== "\n") i++;
      continue;
    }
    if (c === "/" && next === "*") {
      // Block comment: consume to the terminator, preserving newlines.
      i += 2;
      while (i < s.length && !(s[i] === "*" && s[i + 1] === "/")) {
        if (s[i] === "\n") out += "\n";
        i++;
      }
      i += 2;
      continue;
    }
    if (c === '"' || c === "'") {
      const quote = c;
      out += c;
      i++;
      while (i < s.length) {
        if (s[i] === "\\") { out += s[i] + (s[i + 1] ?? ""); i += 2; continue; }
        out += s[i];
        if (s[i] === quote) { i++; break; }
        i++;
      }
      prevSignificant = quote; prevWord = "";
      continue;
    }
    if (c === "`") {
      out += c; i++;
      templates.push({ braces: 0 });
      inTemplateText = true;
      continue;
    }
    if (c === "}" && templates.length) {
      const top = templates[templates.length - 1];
      if (top.braces === 0) {
        out += c; i++;
        inTemplateText = true;
        continue;
      }
      top.braces--;
      emitSignificant(c);
      i++;
      continue;
    }
    if (c === "{" && templates.length) { templates[templates.length - 1].braces++; }
    if (c === "/" && regexAllowed()) {
      // Regex literal: `/` inside a character class is literal, so track `[...]`.
      let inClass = false;
      out += c;
      i++;
      while (i < s.length) {
        if (s[i] === "\\") { out += s[i] + (s[i + 1] ?? ""); i += 2; continue; }
        if (s[i] === "[") inClass = true;
        else if (s[i] === "]") inClass = false;
        else if (s[i] === "/" && !inClass) { out += s[i]; i++; break; }
        else if (s[i] === "\n") break; // unterminated: bail rather than run away
        out += s[i];
        i++;
      }
      prevSignificant = "/"; prevWord = "";
      continue;
    }
    emitSignificant(c);
    i++;
  }
  return out;
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


// Brace-matched extraction of a named function's body. Not a regex slice: the
// bodies below contain nested blocks, and a lazy `…}` slice would stop at the
// FIRST inner closing brace and silently "prove" an ordering on a truncated
// fragment.

// STRIPPING IS NOW SELF-CHECKED, so the whole class of "the stripper ate a token
// and a ban went quiet" is fail-closed rather than argued. Both the raw widget
// and the stripped form must COMPILE: `new Function` parses the body without
// running it, so a stripper that corrupts the program — or a widget that is
// simply broken — turns this gate red before any grep below is trusted.
function compiles(source) {
  try {
    // eslint-disable-next-line no-new-func
    new Function(source);
    return "";
  } catch (e) {
    return String((e && e.message) || e);
  }
}
const rawCompileError = compiles(src);
assert(
  "pre — the shipped widget is a syntactically valid program",
  rawCompileError === "",
  rawCompileError,
);
const strippedCompileError = compiles(code);
assert(
  "pre — comment stripping preserved a syntactically valid program (every grep below runs on real code)",
  strippedCompileError === "",
  `the comment stripper corrupted the source (${strippedCompileError}) — a ban could go quiet on a token it deleted`,
);

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

console.log(`widget-parity-check: ${widgetRel} (${cms} copy of the canonical widget)`);

// ---------------------------------------------------------------------------
// INVARIANT 0 (NEW) — ONE FILE, BOTH CMSes, NO BUILD STEP.
//
// The widget is shipped byte-identical to both repos, so it must read BOTH
// settings brokers and branch at RUNTIME. Losing either accessor would make this
// copy silently CMS-specific — it would still pass every invariant below while
// being dead on the other host, and the drift would be invisible until someone
// installed it. Losing the single `CMS` discriminator (or hardcoding the
// assistant handle) would do the same thing more quietly.
// ---------------------------------------------------------------------------
// Assert the READS and the SELECTION, not a mention of the identifiers. The
// mount's own "no settings broker" diagnostic names both brokers as a fixed
// string, so a loose `/drupalSettings\.cinatra/` test would be satisfied by the
// ERROR MESSAGE while the read itself was gone — the gate would stay green on a
// widget that is dead on Drupal. (Caught by this gate's own negative controls.)
const readsWordPressBroker = /return\s+window\.CinatraConfig\s*;/.test(code);
const readsDrupalBroker = /return\s+window\.drupalSettings\s*&&\s*window\.drupalSettings\.cinatra\s*;/.test(
  code,
);
const bothCanBeSelected =
  /CMS\s*=\s*['"]wordpress['"]\s*;[\s\S]{0,120}?config\s*=\s*wordpressConfig\s*;/.test(code) &&
  /CMS\s*=\s*['"]drupal['"]\s*;[\s\S]{0,120}?config\s*=\s*drupalConfig\s*;/.test(code);
assert(
  "0a — the widget READS both CMS settings brokers, and either can become the one config",
  readsWordPressBroker && readsDrupalBroker && bothCanBeSelected,
  `${readsWordPressBroker ? "" : "the WordPress broker read is gone; "}${readsDrupalBroker ? "" : "the Drupal broker read is gone; "}${bothCanBeSelected ? "" : "one of the two brokers can never be selected; "}the canonical widget is byte-identical in both repos and must work on both hosts`,
);
// Reading the OTHER host's global is reading something else's property. A hostile
// or merely broken getter on it must not be able to throw the whole IIFE away
// before the widget mounts, and USABILITY (a broker that actually carries the
// instance URL) must decide which branch runs — otherwise an empty global left by
// an unrelated plugin selects the wrong host and disables the widget.
// Each guard is looked for INSIDE its own brace-matched body, not within N
// characters of the function's name. A windowed regex would happily find the
// NEXT function's `catch` and call the unguarded one guarded — the same
// reach-into-the-neighbour mistake the stripper made, caught the same way (this
// gate's own negative controls).
const readBrokerBody = functionBody("readBroker");
const brokerUrlBody = functionBody("brokerUrl");
assert(
  "0a-2 — both the global read AND the usability probe are exception-guarded, and usability selects",
  /catch\s*\(/.test(readBrokerBody) &&
    /catch\s*\(/.test(brokerUrlBody) &&
    /brokerUrl\s*\(\s*wordpressConfig\s*\)/.test(code) &&
    /brokerUrl\s*\(\s*drupalConfig\s*\)/.test(code) &&
    /if\s*\(\s*wordpressUrl\s*\)/.test(code),
  "a settings broker is read (or its cinatraUrl probed) outside a guard, or selection is by truthiness rather than by carrying a cinatraUrl — a foreign global on either host could then abort or misdirect the mount",
);
// The other host's global is only touched when this host's is unusable. A guard
// contains a throw; it cannot contain a side effect or a getter that never
// returns, so the cheapest protection is not to look.
assert(
  "0a-3 — the OTHER host's global is read lazily, only when this host's broker is unusable",
  /if\s*\(\s*!\s*wordpressUrl\s*\)\s*\{[\s\S]{0,300}?window\.drupalSettings/.test(code),
  "the Drupal global is read unconditionally — on WordPress the widget must not touch another plugin's global at all when its own broker is usable",
);
// The instance URL is captured ONCE at selection and every later use reads the
// capture. Re-reading a mutable config object at each use is the same class of
// defect as re-reading the instance id was: the origin the widget VALIDATED must
// be the origin it FRAMES.
assert(
  "0a-4 — the instance URL is captured once and reused (never re-read from the broker)",
  /cinatraUrl\s*=\s*wordpressUrl\s*;/.test(code) &&
    /cinatraUrl\s*=\s*drupalUrl\s*;/.test(code) &&
    /new URL\(cinatraUrl\)/.test(code) &&
    !/config\.cinatraUrl/.test(code),
  "the instance URL is re-read from the settings broker after selection — the origin that was validated must be the origin that is framed",
);
assert(
  "0b — a single CMS discriminator drives the seam, and the assistant handle derives from it",
  /var\s+CMS\s*=/.test(code) && /EMBED_ASSISTANT\s*=\s*CMS\b/.test(code),
  "no `var CMS = …` discriminator with `EMBED_ASSISTANT = CMS` — a hardcoded assistant handle would name the wrong agent on the other host",
);
// The Drupal.org "fully local" rule: a module may make no undisclosed third-party
// browser request. WordPress.org has no such rule and the WordPress copy has
// always injected the webfont. One file serves both ONLY while the remote font is
// inside the WordPress branch — an unguarded copy would breach the directory rule
// on Drupal the moment it shipped.
const fontHits = (code.match(/fonts\.googleapis\.com/g) || []).length;
assert(
  "0c — the remote webfont is fetched ONLY inside the WordPress branch (Drupal.org fully-local rule)",
  fontHits === 1 &&
    /if\s*\(\s*CMS\s*===\s*['"]wordpress['"]\s*\)\s*\{[\s\S]{0,400}?fonts\.googleapis\.com/.test(code),
  fontHits === 0
    ? "the WordPress webfont injection is gone entirely"
    : "a fonts.googleapis.com request is composed outside an `if (CMS === 'wordpress')` branch — the Drupal copy must make no third-party browser request",
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
// This gate used to REQUIRE the same-origin `cit_` broker mint. It now forbids it,
// and everything adjacent to it: the widget must read no broker endpoint, call no
// token mint, reach no sign-in relay, run no PKCE and open no window. A bearer
// that is never fetched cannot be composed, logged, stored or leaked, which is the
// whole property this slice buys — so the ban is on ACQUISITION, not merely on
// transmission.
//
// The config keys are named individually so a PARTIAL reintroduction is caught
// too, including the CSRF tokens that only ever existed to authenticate a call to
// a broker this shell no longer makes.
// ---------------------------------------------------------------------------
const BROKER_CONFIG_RE = /config\.(?:tokenEndpoint|authInitEndpoint|authTokenEndpoint|csrfToken|authInitCsrfToken|authTokenCsrfToken)/;
assert(
  "2a — no same-origin credential-broker endpoint is read (tokenEndpoint / authInit / authToken / their CSRF tokens)",
  !BROKER_CONFIG_RE.test(code),
  "the widget reads a broker endpoint from config — the site must not obtain a bearer at all; the frame mints its own",
);
assert(
  "2b — no token mint in the widget (getStreamToken / getCachedCitToken are GONE)",
  !/\bgetStreamToken\b/.test(code) && !/\bgetCachedCitToken\b/.test(code),
  "a token mint/cache helper reappeared — the widget must mint and hold no credential",
);
assert(
  "2c — no hosted-PKCE sign-in relay is called and no widget-auth path is composed",
  !/authInitEndpoint/.test(code) && !/authTokenEndpoint/.test(code) &&
    !/\/widget-auth\b/.test(code) && !/['"]\/(?:api\/)?widget-auth/.test(code),
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
const CREDENTIAL_PREFIX_ARRAY_RE = /CREDENTIAL_VALUE_PREFIXES\s*=\s*\[[^\]]*\]/;
const guardListMatch = code.match(CREDENTIAL_PREFIX_ARRAY_RE);
const codeOutsideGuard = guardListMatch ? code.replace(guardListMatch[0], "") : code;
const strayPrefix =
  codeOutsideGuard.match(/['"`]c(?:wu|it|nx)_/i) || codeOutsideGuard.match(/\bc(?:wu|it|nx)_/i);
assert(
  "2f — no cwu_/cit_/cnx_ bearer literal outside the credential-guard prefix list",
  strayPrefix === null,
  strayPrefix
    ? `a bearer prefix literal (${strayPrefix[0]}) appears in executable code outside CREDENTIAL_VALUE_PREFIXES — the widget must neither mint, match nor build a credential anywhere else`
    : undefined,
);

// ---------------------------------------------------------------------------
// INVARIANT 3 (REPLACES the old Bearer-stream invariant) — the §12 sandboxed
// iframe trust boundary. The widget no longer streams; it frames the Cinatra
// `/embed/assistant` surface and speaks the parent↔iframe bridge. Five checks:
//   3a  a sandboxed iframe is created with EXACTLY the four flags the protocol
//       needs — allow-scripts, allow-same-origin, and the two popup flags the
//       frame-owned sign-in requires — and NO other escalation (no top-nav, no
//       forms, no modals, no downloads, no pointer-lock).
//   3b  its src targets the Cinatra `/embed/assistant` route built from
//       config.cinatraUrl and carries the instanceId + assistant disambiguators.
//   3c  every postMessage uses an EXPLICIT targetOrigin — NEVER "*".
//   3d  inbound frame messages are gated on BOTH `event.origin === <cinatra
//       origin>` AND `event.source === <frame window>` (source-window binding).
//   3e  the widget holds NO `Authorization: Bearer` fetch header anywhere (the
//       stream moved into the iframe; the OLD required-Bearer invariant is now a
//       banned-Bearer invariant — the crux of the trust-boundary flip).
// ---------------------------------------------------------------------------

// 3a — sandbox attribute with the exact grant the protocol needs, and no more.
//
// THE POPUP FLAGS ARE REQUIRED, NOT MERELY TOLERATED (cinatra#2674). At protocol 1
// both were correctly forbidden: the shell ran the sign-in, so a frame that could
// open a window was pure escalation. At protocol 2 the FRAME runs the sign-in, and
// it deliberately runs it in a TOP-LEVEL Cinatra window — the one place a Cinatra
// session cookie is first-party, which is why it behaves identically in browsers
// that block third-party cookies. A sandboxed frame cannot open a window at all
// without `allow-popups`, and a window opened without
// `allow-popups-to-escape-sandbox` inherits the frame's restrictions, so the
// hosted sign-in could neither submit its form nor follow its own redirect home.
// Withholding either flag does not harden anything; it makes the assistant
// unusable. The escape applies to the OPENED WINDOW, never to the frame — the
// frame still cannot navigate, submit or modal the host page, which is what the
// remaining bans below enforce.
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
  // The frame-owned sign-in (cinatra#2674): without these the ceremony cannot
  // open, or cannot complete once open.
  "allow-popups",
  "allow-popups-to-escape-sandbox",
];
// SET EQUALITY, not "the required ones are present plus a denylist". A denylist
// can only ever name the escalations someone thought of; the sandbox vocabulary
// grows, and a future `allow-…` would sail through a partial ban. The grant is a
// closed set: exactly these four, no more, no fewer.
const missingRequired = REQUIRED_SANDBOX.filter((t) => !sandboxTokens.includes(t));
const unexpectedGranted = sandboxTokens.filter((t) => !REQUIRED_SANDBOX.includes(t));
assert(
  "iframe sandbox grants EXACTLY the four flags the protocol needs, and nothing else",
  missingRequired.length === 0 && unexpectedGranted.length === 0,
  missingRequired.length || unexpectedGranted.length
    ? `sandbox='${sandboxVal ?? ""}'${
        missingRequired.length ? ` is missing ${missingRequired.join(", ")} (the frame-owned sign-in cannot complete without the popup grant)` : ""
      }${
        unexpectedGranted.length ? ` grants unexpected flag(s): ${unexpectedGranted.join(", ")}` : ""
      }`
    : undefined,
);
// The named escalations are called out separately as well: set equality already
// forbids them, but a named failure says WHICH capability was handed to the frame
// rather than "an unexpected token", and that is the sentence a reviewer needs.
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
const grantedForbidden = sandboxTokens.filter((t) => FORBIDDEN_SANDBOX.includes(t));
assert(
  "iframe sandbox grants NO escalation over the host page (no top-nav/forms/modals/downloads/pointer-lock)",
  grantedForbidden.length === 0,
  grantedForbidden.length
    ? `sandbox grants forbidden flag(s): ${grantedForbidden.join(", ")}`
    : undefined,
);

// 3b — the iframe src is the Cinatra /embed/assistant route with the
// disambiguators, built from config.cinatraUrl (NOT a hardcoded origin).
assert(
  "iframe src targets the Cinatra /embed/assistant route from the captured instance URL",
  /\bcinatraUrl\s*\+\s*['"]\/embed\/assistant/.test(code),
  "no `cinatraUrl + '/embed/assistant'` iframe src construction found (the URL must come from the CMS settings broker, not a hardcoded origin)",
);
assert(
  "iframe src carries the instanceId + assistant disambiguators",
  /instanceId=/.test(code) && /assistant=/.test(code),
  "the embed src does not carry both instanceId= and assistant= query params",
);

// 3c — targetOrigin discipline: NO wildcard, AND every WINDOW post is addressed
// to the resolved Cinatra origin variable (an explicit non-"*" literal or a
// different origin would be a leak). §12b adds a second, HARDENED transport: a
// send on the retained MessageChannel endpoint (`activePort`) carries NO
// targetOrigin — the origin-targeted READY transfer that delivered the port IS the
// binding — so it is exempt from the origin-arg requirement, but NOT from the "*"
// ban. We (i) ban a "*" arg on ANY post, (ii) require at least one post (the
// CONTEXT message is delivered over some transport), and (iii) require that EVERY
// WINDOW `.postMessage(` targets `cinatraOrigin` (no post to any other/computed
// origin). A rewrite that posts a window message elsewhere fails here.
// Ban a "*" literal ANYWHERE in a postMessage argument list (not just immediately
// before `)`), so a `postMessage(msg, cinatraOrigin || "*")` short-circuit
// fallback is caught too — for BOTH transports.
const WILDCARD_POSTMESSAGE_RE = /\.postMessage\s*\([^;)]*?(['"])\*\1/;
assert(
  'no postMessage uses a wildcard "*" targetOrigin (anywhere in the args)',
  !WILDCARD_POSTMESSAGE_RE.test(code),
  'a postMessage with a "*" argument was found — every post must be addressed to the exact Cinatra origin',
);
// Match EVERY postMessage call with its RECEIVER token (`\S*` = the contiguous
// non-space run before `.postMessage`, so it never crosses spaces/newlines) and
// its arg list. Capturing broadly is deliberate: a window send via ANY receiver
// spelling — `frameWindow.postMessage`, `getWindow().postMessage`,
// `(frameWindow).postMessage` — is still checked and cannot evade the origin
// requirement (a narrower `\w+`-only receiver form would let a non-identifier
// receiver slip a wrong-origin post past this gate).
const postMessageCalls = [...code.matchAll(/(\S*)\.postMessage\s*\(([^;)]*?)\)/g)];
assert(
  "at least one postMessage to the frame exists (the CONTEXT message is delivered)",
  postMessageCalls.length > 0,
  "no postMessage call found — the bridge never gives the frame its context",
);
// A send is COMPLIANT iff it is EITHER a WINDOW send whose arg list ENDS with
// `, cinatraOrigin` and nothing appended (this rejects a computed/short-circuit
// target such as `cinatraOrigin || "*"` or a ternary), OR the origin-less send on
// the retained port endpoint `activePort` — the §12b document-bound transport, the
// ONLY sanctioned origin-less sink. Anything else — a window send to another/
// computed origin, or an origin-less send on a NON-port receiver — is a leak/
// regression and fails here. ("*" is separately banned above for BOTH transports.)
const nonCompliantPosts = postMessageCalls.filter((m) => {
  const receiver = m[1];
  const args = m[2].trim();
  const windowToCinatraOrigin = /,\s*cinatraOrigin$/.test(args);
  const retainedPortSend =
    /(?:^|[^\w$])activePort$/.test(receiver) && !args.includes(",");
  return !(windowToCinatraOrigin || retainedPortSend);
});
assert(
  "every postMessage is EITHER a window send to EXACTLY `cinatraOrigin` OR the origin-less retained-port send (activePort)",
  nonCompliantPosts.length === 0,
  "a postMessage is neither addressed to exactly `cinatraOrigin` nor the sanctioned origin-less `activePort` send — an outbound send must not leak to another/computed origin",
);

// 3d — inbound gate MUST be the REJECT form (a `!==` early-return), not merely a
// mention of the identifiers: `if (event.origin === cinatraOrigin) return` would
// invert the gate yet pass a loose "mentions both sides" check. Require the `!==`
// reject spelling for BOTH the origin and the source-window binding.
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

// 3e — THE FLIP: the widget must hold NO Authorization: Bearer fetch header. Every
// Bearer-authenticated request moved into the iframe, and since cinatra#2674 there
// is no token on this side to put in one.
const BEARER_HEADER_RE =
  /(?:^|[,{])\s*["']?authorization["']?\s*:\s*["']Bearer\b/gim;
const bearerMatches = [...code.matchAll(BEARER_HEADER_RE)];
assert(
  "widget holds NO Authorization: Bearer fetch header (streaming moved into the iframe)",
  bearerMatches.length === 0,
  bearerMatches.length
    ? "an `Authorization: Bearer` header is still present — the widget authenticates nothing; the frame holds the only credential"
    : undefined,
);

// ---------------------------------------------------------------------------
// INVARIANT 3f (FLIPPED by cinatra#2674) — the §12 bridge speaks PROTOCOL 2, and
// the retired credential carrier cannot come back. Structural markers so a
// rename/version drift from the core `bridge-protocol.ts` is loud.
//
// The version literal is load-bearing: pinned at 2, a protocol-1 parent cannot
// negotiate with a protocol-2 frame, so there is no silent fallback path to parent
// credential delivery. A gate that accepted either literal would hand that
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
// silently stops naming the site/agent is loud too. `cms.instanceId` must come
// from the NORMALIZED context, never re-read from raw config: re-reading would
// reintroduce the untyped value the normalization just removed, and a numeric id
// fails the frame's strict schema silently.
assert(
  "3f — the context carries the public selectors (session.assistant, cms.instanceId, optional site.siteId)",
  /assistant\s*:\s*EMBED_ASSISTANT/.test(code) &&
    /instanceId\s*:\s*ctx\.instanceId/.test(code) &&
    /siteId\s*:\s*siteId/.test(code) &&
    /boundedSelector\s*\(\s*ctx\.siteId/.test(code),
  "the context message does not carry the assistant + instanceId + siteId selectors, built from the normalized context",
);

// ---------------------------------------------------------------------------
// INVARIANT 3f-2 — the bridge's runtime trust controls each leave a structural
// marker so a rewrite that DROPS one is loud (the grep can't prove the runtime
// behavior, but a missing marker means the control is almost certainly gone):
//   * nonce echo (parent echoes the frame's READY nonce),
//   * one context per frame DOCUMENT (a guarded latch), set BEFORE the send,
//   * a same-nonce REPLAY is ignored,
//   * a replacement document starts a NEW EPOCH, and the reset runs AFTER every
//     validation,
//   * uplink correlationId binding (drop a message whose correlationId differs),
//   * a monotonic inbound seq gate (drop a non-increasing seq).
// ---------------------------------------------------------------------------
assert(
  "bridge echoes the frame nonce (nonceEcho)",
  /nonceEcho\s*:/.test(code),
  "no `nonceEcho:` in the context message — the parent must echo the frame's READY nonce",
);
assert(
  "bridge guards re-entry with a `contextSent` latch",
  /\bcontextSent\b/.test(code) && /if\s*\(\s*contextSent\b/.test(code),
  "no `contextSent` latch found",
);
// NO RETRY STORM. The latch is set BEFORE the send and is never cleared on
// failure, so a refused send (a credential-shaped value, a portless fail-closed)
// is attempted exactly ONCE per frame document. A frame that RELOADS is a new
// document and gets its own single attempt through the epoch reset below — a new
// document is not a retry.
assert(
  "the one-context latch is set BEFORE the send (exactly one attempt per document, no retry storm)",
  /contextSent\s*=\s*true\s*;\s*if\s*\(\s*!\s*sendToFrame\s*\(\s*buildEmbedContext/.test(code),
  "the READY handler does not set `contextSent = true` immediately before the single `sendToFrame(buildEmbedContext(…))` call",
);
assert(
  "a REPLAYED READY (same nonce) is ignored",
  /contextSent\s*&&\s*d\.nonce\s*===\s*frameNonce/.test(code),
  "no same-nonce replay guard — a replayed READY must not draw a second context message",
);
// A frame reload replaces the DOCUMENT under the same element and announces itself
// with a fresh nonce. Without an epoch reset the parent would ignore it forever
// (the widget hangs at "waiting for the host") and leak the retained port.
assert(
  "a replacement document starts a new epoch (resetBridgeEpoch)",
  /function\s+resetBridgeEpoch\b/.test(code) &&
    /if\s*\(\s*contextSent\s*\)\s*\{\s*resetBridgeEpoch\s*\(\s*\)\s*;\s*\}/.test(code),
  "no resetBridgeEpoch() epoch reset — a reloaded frame would never be served again and its port would leak",
);
// ORDER, and it is asserted POSITIVELY on both anchors. An index comparison alone
// fails open when the FIRST anchor is missing (indexOf returns -1, which is less
// than any real index), so the transport check disappearing would have "proved"
// the ordering. Both must be present, and then ordered.
const transportCheckAt = code.indexOf("if (!transferredPort && requirePort) return;");
const epochResetAt = code.indexOf("if (contextSent) { resetBridgeEpoch(); }");
assert(
  "the epoch reset happens AFTER every READY validation (a refused READY costs the session nothing)",
  transportCheckAt !== -1 && epochResetAt !== -1 && transportCheckAt < epochResetAt,
  transportCheckAt === -1
    ? "the `!transferredPort && requirePort` fail-closed check is missing, so the ordering cannot hold"
    : epochResetAt === -1
      ? "the `if (contextSent) { resetBridgeEpoch(); }` reset is missing"
      : "resetBridgeEpoch() runs before the READY is fully validated — a malformed READY could tear down an established session",
);
// The parent cannot VERIFY that a new nonce means a real reload — an iframe can
// simply mint fresh nonces without navigating. Unbounded, that turns the
// reload-recovery path into unbounded parent work driven by the frame, which is
// the retry-storm property W1 bought, lost through the back door.
assert(
  "the number of epochs is BOUNDED (a frame cannot manufacture unlimited reloads)",
  /MAX_BRIDGE_EPOCHS\s*=\s*\d+/.test(code) &&
    /contextSent\s*&&\s*bridgeEpochs\s*>=\s*MAX_BRIDGE_EPOCHS\s*\)\s*return/.test(code) &&
    /function\s+resetBridgeEpoch\b[\s\S]{0,200}?bridgeEpochs\+\+/.test(code),
  "no MAX_BRIDGE_EPOCHS ceiling on the epoch reset — a frame that keeps minting fresh nonces without navigating would be served a fresh context every time",
);
assert(
  "the epoch reset closes the retired port rather than leaking it",
  /function\s+resetBridgeEpoch\b[\s\S]{0,600}?activePort\s*\.\s*close\s*\(/.test(code),
  "resetBridgeEpoch() does not close the previous MessagePort — a reloaded frame would leak the entangled endpoint",
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
// The seq gate is a scarce, one-way resource: a message the parent will drop
// anyway must not be able to spend from it, or an unknown uplink carrying a high
// sequence number would silence every legitimate uplink after it.
assert(
  "the CLOSED uplink type set is checked BEFORE the seq gate commits",
  /function\s+isKnownUplinkType\b/.test(code) &&
    functionBody("validUplinkEnvelope").indexOf("isKnownUplinkType") <
      functionBody("validUplinkEnvelope").indexOf("acceptInboundSeq"),
  "the uplink type is not checked before `acceptInboundSeq` — a message that will be dropped could first consume a sequence number",
);

// ---------------------------------------------------------------------------
// INVARIANT 3f-3 — NOTHING CREDENTIAL-SHAPED IN THE FRAME URL. The embed src
// carries ONLY the non-secret disambiguators (instanceId, assistant); a token in
// the URL would leak it via history/referrer/logs. Assert the `/embed/assistant`
// src builder contains no credential identifier.
// ---------------------------------------------------------------------------
const embedSrcBuild = code.match(
  /\bcinatraUrl\s*\+\s*['"]\/embed\/assistant[\s\S]{0,400}?;/,
);
assert(
  "embed iframe src carries NO credential (there is none in this page to put there)",
  !!embedSrcBuild && !/token|cit_|cwu_|cnx_/i.test(embedSrcBuild[0]),
  embedSrcBuild
    ? "the /embed/assistant src builder references a credential — nothing of the sort may be in the frame URL"
    : "could not locate the /embed/assistant src builder",
);

// ---------------------------------------------------------------------------
// INVARIANT 3f-4 — §12b DOCUMENT-BOUND MESSAGEPORT TRANSPORT (cinatra#1965/#1970).
// The iframe transfers ONE MessageChannel endpoint in the (origin+source-gated)
// READY; the parent RETAINS it, sends the selector-only CONTEXT message over it,
// and services uplinks on it. At protocol 2 this is no longer a credential wall —
// there is no credential on this bridge to misdeliver — so it is kept for the
// narrower property it still provides at no cost: the retained endpoint belongs to
// the realm that ran the handshake, so a same-origin replacement document cannot
// take over an established session's uplink channel. The origin-pinned WINDOW
// transport remains for a frame whose READY carries no port; `requirePort` refuses
// that. Structural markers so a regression that drops the port transport (or its
// refusal) is loud.
// ---------------------------------------------------------------------------
assert(
  "bridge takes the transferred port from the origin-gated READY (event.ports)",
  /event\.ports\b/.test(code),
  "no `event.ports` read — the parent must take the transferred MessagePort the frame sent on READY",
);
assert(
  "bridge sends the CONTEXT message over the retained port (activePort.postMessage, no targetOrigin)",
  /activePort\s*\.\s*postMessage\s*\(/.test(code),
  "no `activePort.postMessage(` — in port mode the context message must ride the retained document-bound port, not a window",
);
assert(
  "bridge services uplinks on the retained port (activePort message listener)",
  /activePort\s*\.\s*addEventListener\s*\(\s*['"]message['"]/.test(code),
  "no `activePort.addEventListener('message', …)` — steady-state uplinks must ride the retained port in port mode",
);
assert(
  "bridge refuses the unbound channel under requirePort (a no-port READY sends NOTHING)",
  /config\.requirePort\b/.test(code) &&
    /!\s*transferredPort\s*&&\s*requirePort/.test(code),
  "no `config.requirePort` toggle + `!transferredPort && requirePort` fail-closed guard — a downgrade could be forced by stripping the transferred port",
);
// The protocol transfers EXACTLY ONE endpoint. A READY carrying more is not a
// frame speaking this protocol, so it is refused rather than silently reduced to
// its first port — reducing it would be a guess about which channel is real.
assert(
  "a READY transferring MORE than one port is refused outright",
  /ports\s*&&\s*ports\.length\s*>\s*1\s*\)\s*return/.test(code),
  "no `ports.length > 1` refusal — a READY carrying several transferred ports must not be silently reduced to its first",
);

// ---------------------------------------------------------------------------
// INVARIANT 3g / 7 (REWRITTEN by cinatra#2674) — NO CREDENTIAL, AND NO CREDENTIAL
// SHAPE. Protocol 1's version of this invariant was "the tokens the widget holds
// are relayed only into the BOOTSTRAP". The widget holds no token now, so the
// invariant is stronger and simpler: nothing credential-shaped may be composed on
// EITHER outbound path (the bridge and the frame URL), nothing credential-shaped
// may be read on either INBOUND transport, and the guards that enforce it must be
// the choke points rather than unused helpers.
//
// Two markers per direction, because either alone is hollow: a guard nothing calls
// proves nothing, and a choke point with no guard in it proves nothing either. The
// runtime proof (a poisoned selector is refused, with a positive control) is the
// credential-egress harness; this is the cheap structural tripwire.
// ---------------------------------------------------------------------------
assert(
  "7a — the recursive credential-shaped value guard exists (containsCredentialShapedValue)",
  /function\s+containsCredentialShapedValue\b/.test(code) &&
    /CREDENTIAL_VALUE_PREFIXES\s*=\s*\[/.test(code),
  "no containsCredentialShapedValue() over a CREDENTIAL_VALUE_PREFIXES list — the outbound guard is missing",
);
// The guard's bounds must fail CLOSED on the sending side: a structure too deep to
// finish walking, or a container this walk cannot enumerate, is refused rather
// than waved through. A receiver-style `return false` on "I could not tell" would
// make the guard defeatable by nesting, which is exactly the hole it closes.
// Depth bounds how DEEP the walk goes; it says nothing about how WIDE. Inbound
// this guard is the FIRST thing that touches an envelope the frame sent, so an
// unbounded walk over a sparse array with an enormous `length` would be a stall
// the frame can trigger. Total work must be bounded too, and exhausting the
// budget is another unknown answer — so it fails CLOSED like the rest.
assert(
  "7a-3 — the credential walk is bounded in TOTAL WORK, not only in depth, and exhaustion fails closed",
  /CREDENTIAL_SCAN_MAX_NODES\s*=\s*\d+/.test(code) &&
    // The budget is spent at the ENTRY of every visit (so each node costs one)
    // AND consulted inside the key loop (so a wide object stops early). One
    // without the other is not a bound: the loop check alone never decrements,
    // and the entry check alone lets a single huge enumeration run first.
    /if\s*\(\s*b\.left\s*<=\s*0\s*\)\s*\{\s*return true;\s*\}\s*b\.left--;/.test(code) &&
    (code.match(/if\s*\(\s*b\.left\s*<=\s*0\s*\)\s*\{\s*return true;/g) || []).length >= 2 &&
    /containsCredentialShapedValue\s*\(\s*value\[i\]\s*,\s*d\s*\+\s*1\s*,\s*b\s*\)/.test(code) &&
    /containsCredentialShapedValue\s*\(\s*value\[key\]\s*,\s*d\s*\+\s*1\s*,\s*b\s*\)/.test(code) &&
    !/Object\.keys\s*\(\s*value\s*\)/.test(code) &&
    // ...and the budget is spent BEFORE the ownership test, so a prototype
    // carrying a vast number of enumerable properties cannot run the loop free.
    /for\s*\(\s*var key in value\s*\)\s*\{\s*if\s*\(\s*b\.left\s*<=\s*0\s*\)\s*\{\s*return true;\s*\}\s*b\.left--;\s*if\s*\(\s*!\s*Object\.prototype\.hasOwnProperty/.test(code),
  "the credential walk has no node budget, does not thread it through recursion, or materializes every key with Object.keys() before the budget can stop it — a wide/sparse structure would spin it, and the frame chooses that structure",
);
assert(
  "7a-2 — the credential guard fails CLOSED at its depth bound and on non-plain containers",
  /if\s*\(\s*d\s*>=\s*8\s*\)\s*\{\s*return true;/.test(code) &&
    /\[object Object\]['"]\s*\)\s*\{\s*return true;/.test(code),
  "the guard returns false on an unknown answer — on the SENDING side an unknown answer must mean refusal, or the guard is defeatable by nesting",
);
// 7b — the guard must run in the SINGLE send helper, and both transports must be
// reachable only through it: an `activePort.postMessage` or a frame window post
// that bypassed `sendToFrame` would be an ungated egress path.
const sendHelperBody = functionBody("sendToFrame");
assert(
  "7b — every parent->iframe send passes through the guard (sendToFrame refuses first)",
  !!sendHelperBody &&
    /if\s*\(\s*containsCredentialShapedValue\s*\(\s*message\s*\)\s*\)/.test(sendHelperBody) &&
    sendHelperBody.indexOf("containsCredentialShapedValue") <
      sendHelperBody.indexOf("postMessage"),
  "sendToFrame() does not refuse a credential-shaped payload BEFORE any postMessage — the guard must be the choke point, not an unused helper",
);
// 7c — the guard runs INBOUND as well. "No credential crosses this boundary" is a
// claim about both directions, and the frame is the party that actually holds a
// bearer: an uplink is where one could arrive, and `a11y.liveRegion` lands in the
// CMS page's live region. Both inbound listeners must drop a credential-shaped
// envelope before any field of it is read.
const INBOUND_GUARD_RE = /if\s*\(\s*!\s*inboundIsClean\s*\(\s*d\s*\)\s*\)\s*return\s*;/g;
const inboundGuardHits = (code.match(INBOUND_GUARD_RE) || []).length;
assert(
  "7c — both inbound transports drop a credential-shaped envelope (window + port)",
  /function\s+inboundIsClean\b/.test(code) &&
    /containsCredentialShapedValue\s*\(\s*d\s*\)/.test(code) &&
    inboundGuardHits >= 2,
  `the inbound credential guard is missing or applied on only ${inboundGuardHits} of the two transports (onBridgeMessage + onPortMessage)`,
);
// …and it must run BEFORE the envelope's shape is inspected. A `typeof d.type`
// test is itself a read of the envelope, so a guard placed after it does not
// deliver the "before any field is read" boundary it claims.
const guardBeforeShapeCheck = ["onPortMessage", "onBridgeMessage"].every((fn) => {
  const body = functionBody(fn);
  const guardAt = body.indexOf("inboundIsClean");
  const shapeAt = body.indexOf("typeof d.type");
  return guardAt !== -1 && shapeAt !== -1 && guardAt < shapeAt;
});
assert(
  "7c-2 — the inbound guard runs BEFORE any field of the envelope is read (both transports)",
  guardBeforeShapeCheck,
  "an inbound listener inspects `d.type` before the credential guard — the guard must be the first thing that touches the envelope, on both transports",
);
// 7d — the protocol-2 selector bounds are enforced, so one over-long optional
// field cannot reject the whole strict envelope and strand the session; and every
// selector is normalized to a STRING first, so a numeric id cannot fail the
// frame's strict type schema and no non-plain container can reach a composed
// message at all.
assert(
  "7d — selectors are normalized to strings and bound-checked against the protocol-2 maxima",
  /SELECTOR_MAX\s*=\s*\{/.test(code) &&
    /function\s+boundedSelector\b/.test(code) &&
    /function\s+asSelector\b/.test(code) &&
    /boundedSelector\s*\(\s*ctx\.instanceId/.test(code) &&
    /boundedSelector\s*\(\s*ctx\.siteId/.test(code),
  "no asSelector/SELECTOR_MAX/boundedSelector normalization + bound-checking of the instance id and site handle",
);
// The REQUIRED selector is bound-checked at BOTH points it is read. The mount
// checks the value it FRAMES; the composer reads the CMS config again one task
// later, and an id that became empty or over-long in between would go into a
// `.strict()` envelope the frame rejects WHOLE — stranding the session in
// "waiting for host". So the composer refuses to build the message, and the send
// helper refuses to post a refusal.
assert(
  "7d-2 — the REQUIRED instance id is re-bounded at compose time, and a refusal is a non-send",
  /function\s+buildEmbedContext\b[\s\S]{0,900}?boundedSelector\s*\(\s*ctx\.instanceId[\s\S]{0,200}?if\s*\(\s*!\s*instanceId\s*\)\s*\{\s*return null;/.test(code) &&
    /function\s+sendToFrame\b[\s\S]{0,300}?if\s*\(\s*!\s*message\s*\|\|\s*typeof message\s*!==\s*['"]object['"]\s*\)/.test(code),
  "buildEmbedContext() does not re-bound the required instance id (or sendToFrame would post the null refusal) — a config change between mount and READY would send an envelope the frame rejects whole",
);
// 7e — THE FRAME URL IS THE OTHER OUTBOUND PAYLOAD: it is composed from config and
// then LEAVES THE PAGE as an HTTP request, where it also lands in history, in an
// access log and in a referrer. The bridge guard cannot reach it, so the mount
// runs the same check — and it runs it on the RAW COMPONENTS, because
// percent-encoding destroys the token boundary the guard matches on (`"x cnx_…"`
// becomes `"x%20cnx_…"`, preceded by a digit) and a composed-string-only scan is
// therefore bypassable.
assert(
  "7e — the iframe src is credential-scanned on its RAW components AND composed form",
  /function\s+mountBridgeIframe\b[\s\S]{0,2400}?containsCredentialShapedValue\s*\(\s*rawParts\s*\)\s*\|\|\s*containsCredentialShapedValue\s*\(\s*src\s*\)/.test(code),
  "mountBridgeIframe() does not scan the RAW url components before encoding — percent-encoding destroys the token boundary the guard matches on, so a composed-string-only scan is bypassable",
);
// 7f — PROTOCOL 2's promise rests on the frame being a different ORIGIN from the
// page. A same-origin instance does not weaken it, it removes it: site script
// could read straight into the frame's realm. The widget must refuse rather than
// claim a protection it does not have.
assert(
  "7f — the widget REFUSES to mount when the instance is on the page's own origin",
  /cinatraOrigin\s*===\s*pageOrigin/.test(code) &&
    /window\.location\s*&&\s*window\.location\.origin/.test(code),
  "no same-origin refusal — protocol 2's credential guarantee does not exist when the frame shares the page's origin, and the widget must not pretend otherwise",
);
// 7g — WEB STORAGE: A BAN THAT BECAME A CHANNEL (cinatra#2683).
//
// This used to be "no web storage at all", and that was the right invariant while
// the widget's conversation could not outlive a page load anyway: storage is the
// one place a credential could come to REST rather than merely pass through, and a
// shell with nothing to remember should be forbidden to remember.
//
// S8f gave it exactly one thing to remember. `threadId: correlationId` ended the
// person's conversation at every reload — the frame asked to resume a thread that
// had never existed — so the widget now persists the THREAD ID, keyed by (site,
// user). A thread id is public context: it names which conversation to resume, and
// the instance authorizes it against the frame's own signed-in reader before
// serving a message.
//
// So the ban is narrowed rather than dropped, and the narrowing is what is
// asserted: `sessionStorage` stays forbidden outright; `localStorage` may be
// touched in exactly ONE accessor; and the write path is a CHOKE POINT that
// accepts a thread id by shape and runs it through the SAME credential guard every
// outbound message passes. A credential still cannot be persisted — now by
// construction rather than by absence.
assert(
  "7g — sessionStorage is still forbidden outright",
  !/\bsessionStorage\b/.test(code),
  "the widget references sessionStorage — only the (site,user)-keyed thread id may be persisted, and it lives in localStorage",
);
const localStorageHits = (code.match(/\blocalStorage\b/g) || []).length;
assert(
  "7g — localStorage is reached through EXACTLY ONE accessor (threadStore)",
  localStorageHits === 1 &&
    /function\s+threadStore\s*\(\s*\)\s*\{[\s\S]{0,400}?window\.localStorage/.test(code),
  `the widget touches localStorage in ${localStorageHits} place(s) — persistence must stay behind the single threadStore() accessor, so there is one place to be right about what may be written`,
);
assert(
  "7g — the storage WRITE is shape-checked and credential-guarded",
  /function\s+writeStoredThreadId\s*\([\s\S]{0,700}?ID_PATTERN\.test\(\s*id\s*\)[\s\S]{0,300}?containsCredentialShapedValue\s*\(\s*id\s*\)[\s\S]{0,400}?setItem/.test(
    code,
  ),
  "writeStoredThreadId() does not bound the value by ID_PATTERN and run it through containsCredentialShapedValue before setItem — storage is where a credential would come to REST, so the one write must be a choke point",
);
assert(
  "7g — the storage READ bounds what it returns by ID_PATTERN",
  /function\s+readStoredThreadId\s*\([\s\S]{0,900}?ID_PATTERN\.test\(\s*entry\.id\s*\)/.test(code),
  "readStoredThreadId() returns a stored value without bounding it by ID_PATTERN — a hand-edited entry would be posted into the frame's strict schema and take the session down",
);
assert(
  "7g — the persisted thread id is keyed by the CMS USER, and no user means no persistence",
  /function\s+threadStoreKey\s*\(\s*\)\s*\{[\s\S]{0,300}?cmsUserKey\(\)[\s\S]{0,120}?if\s*\(\s*!user\s*\)\s*return\s+null;/.test(
    code,
  ),
  "threadStoreKey() does not require a CMS user — one browser profile can be two people, and resuming the first person's thread as the second leaves the second refused on every turn",
);
// The key's PARTITION, component by component. Each one separates conversations
// that must not be shared: the instance (two Cinatra deployments behind one CMS),
// the instance id (two instances of the same deployment), the assistant (the two
// CMS personas), and the person. A key that lost any of them would silently merge
// two people's or two deployments' conversations, so each is asserted by name
// rather than by "the key looks composed".
assert(
  "7g — the storage key partitions by (instance origin, instance id, assistant, user)",
  /THREAD_STORE_PREFIX\s*\+\s*'\|'\s*\+\s*cinatraOrigin\s*\+\s*'\|'\s*\+\s*instanceId\s*\+[\s\S]{0,80}?EMBED_ASSISTANT\s*\+\s*'\|'\s*\+\s*user/.test(
    code,
  ),
  "the thread-storage key does not carry all four partition components — dropping one merges conversations that belong to different deployments, instances, personas or people",
);
assert(
  "7g — anonymous (uid 0) is NOT a person, so it gets no persistence",
  /function\s+cmsUserKey\s*\(\s*\)[\s\S]{0,900}?key\s*===\s*'0'\s*\)\s*return\s+'';/.test(code),
  "cmsUserKey() accepts uid 0 — both CMSes spell 'anonymous' that way, so every signed-out visitor at a browser would share one bucket",
);
// THE WRITE IS THE ONLY WRITE. The single-accessor rule above keeps `localStorage`
// itself in one place; this keeps the SETTER in one place, so "a credential cannot
// be persisted" is a property of the code rather than of the current callers: a
// second `setItem` anywhere would bypass the shape bound and the credential guard.
const setItemHits = (code.match(/\.setItem\s*\(/g) || []).length;
assert(
  "7g — there is EXACTLY ONE storage write in the widget, inside writeStoredThreadId",
  setItemHits === 1 &&
    /function\s+writeStoredThreadId\s*\([\s\S]{0,900}?\.setItem\s*\(/.test(code),
  `the widget calls setItem in ${setItemHits} place(s) — a second writer would bypass the shape bound and the credential guard that make persistence safe`,
);
assert(
  "7g — the remembered thread EXPIRES from when it started (the age is bounded both ways)",
  /THREAD_STORE_MAX_AGE_MS/.test(code) &&
    /age\s*<\s*0\s*\|\|\s*age\s*>\s*THREAD_STORE_MAX_AGE_MS/.test(code),
  "readStoredThreadId() does not bound the entry's age in both directions — an entry that cannot be used (a thread this reader does not own) must age out, and a future-dated one must not outlive every bound",
);
// …AND USE DOES NOT RESTART THAT CLOCK. Bounding the age is worth nothing if the
// entry is rewritten every time it is read: the seven days would reset on each
// page load and an unusable thread would follow the machine forever. The
// structural form of "use does not write" is that the stored branch RETURNS
// before the write — asserted as that ordering, so a rewrite-on-read cannot be
// added without the gate seeing it.
assert(
  "7g — resolving a REMEMBERED thread returns it WITHOUT writing (use does not restart the clock)",
  /function\s+resolveThreadId\s*\(\s*\)\s*\{[\s\S]{0,400}?if\s*\(\s*stored\s*\)\s*return\s+stored\.id;[\s\S]{0,300}?writeStoredThreadId\(/.test(
    code,
  ),
  "resolveThreadId() writes on the remembered path — refreshing the entry on every use resets its expiry, so an entry that cannot be used would never age out",
);
assert(
  "7g — the bridge correlationId is still minted per document, never the persisted id",
  /correlationId\s*=\s*mintCorrelationId\(\)/.test(code) &&
    !/correlationId\s*=\s*(?:resolveThreadId|readStoredThreadId)\(/.test(code),
  "the correlationId is a per-document security nonce every uplink must echo — persisting it would make a replay across documents possible",
);
// EVERY console.* ARGUMENT IS A FIXED STRING LITERAL. The older form of this check
// listed credential identifiers and banned those; that only ever caught the names
// it happened to know, and it misfired on a fixed diagnostic that merely says the
// WORD "credential". This shell has no diagnostic that needs a runtime value, so
// the checkable rule is the stronger one: a console call that starts with anything
// but a quote is passing a value, and a value is the thing that could be a
// credential.
const CONSOLE_NON_LITERAL_RE = /console\s*\.\s*\w+\s*\(\s*(?!['"`])/;
assert(
  "no console.* call passes a runtime value (every argument is a fixed string literal)",
  !CONSOLE_NON_LITERAL_RE.test(code),
  "a console.* call takes a non-literal first argument — this shell logs fixed diagnostics only, so a value there could be anything, credentials included",
);
const consoleLines = code
  .split("\n")
  .filter((line) => /console\s*\.\s*\w+\s*\(/.test(line));
const CREDENTIAL_IDENTIFIER_RE =
  /\b(?:citToken|cwuToken|userToken|cachedToken|getStreamToken|credentialRef|activePort|frameNonce)\b/;
assert(
  "no credential-adjacent identifier appears on a console.* line",
  !consoleLines.some((line) => CREDENTIAL_IDENTIFIER_RE.test(line)),
  "a credential-adjacent identifier appears on a console.* line",
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
// No dynamic egress: the widget must never fetch a URL taken from a bridge message
// (the closed uplink schema carries no URL; a `fetch(d.<x>)` / `fetch(msg…)` would
// be an exfiltration/SSRF-style regression).
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
  "negotiateCapabilities() reappeared — the shell mounts unconditionally now; the capability handshake runs inside the /embed/assistant iframe",
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
// INVARIANT 6 (FLIPPED by cinatra#2674) — THE SHELL RUNS NO LOGIN GATE.
//
// #410 put a required-login window in the CMS-origin shell: a panel mode, a
// per-user token in module scope, a sign-in button, an auth error line. Every one
// of those existed because the SITE held the credential and had to know whether it
// had one. It does not, and it must not: sign-in is decided and drawn by the
// frame, on the Cinatra origin, and a CMS page that renders auth state is a CMS
// page that is a party to the sign-in. So the #410 marker set is now BANNED, in
// executable code (the header may still name them to explain their absence), and
// this is the invariant that would go red if someone "helpfully" restored the old
// panel.
// ---------------------------------------------------------------------------
const SHELL_LOGIN_STATE_RE = /\b(?:panelMode|loginRequired|userToken|userTokenValid|startLogin|forceReLogin|redeemCode|codeVerifier|codeChallenge)\b|widget-auth/;
const shellLoginMatch = code.match(SHELL_LOGIN_STATE_RE);
assert(
  "the shell runs NO login gate of its own (sign-in belongs to the frame)",
  shellLoginMatch === null,
  shellLoginMatch
    ? `the shell still carries login state (\`${shellLoginMatch[0]}\`) — the per-user ceremony moved into the frame and must not be mirrored here`
    : undefined,
);
assert(
  "the shell does not listen for the hosted sign-in result",
  !/cinatra-widget-auth/.test(code),
  "the widget still listens for the hosted auth postMessage — the hosted return step posts to the CINATRA origin, so this page could not receive it and must not try",
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
