// SPDX-License-Identifier: Apache-2.0
//
// Cinatra CMS assistant widget — THE CANONICAL WIDGET (cinatra#411).
//
// ONE FILE, TWO REPOS, BYTE-IDENTICAL. This file is shipped verbatim as
// cinatra-ai/wordpress-plugin `assets/cinatra-widget.js` and as
// cinatra-ai/drupal-module `js/cinatra-widget.js`. It used to be authored in the
// WordPress copy and hand-mirrored into the Drupal one; two independent protocol-2
// lanes then hardened the two copies in DIFFERENT places, which is exactly the
// failure mode a "hand-mirrored canonical file" invites. It is now ONE file
// carrying the UNION of both lanes' protections, and the drift is a CI-visible
// byte diff rather than a security asymmetry nobody notices.
//
// THERE IS NO BUILD STEP. The CMS difference is a RUNTIME SEAM: the file detects
// which CMS settings broker published its config (`window.CinatraConfig` on
// WordPress, `window.drupalSettings.cinatra` on Drupal) and branches at the four
// places where the two hosts genuinely differ — the config broker, the assistant
// handle, the canonical-resource accessor, and the edit-permission oracle plus its
// in-place refresh sink. Every trust-boundary control below is CMS-INDEPENDENT and
// runs identically on both.
//
// ARCHITECTURE (S5 / cinatra#1221; PROTOCOL 2 by cinatra#2674): the assistant
// conversation is NOT rendered by this file. The Cinatra instance serves the AG-UI
// surface at `/embed/assistant` and THIS widget mounts it in a sandboxed <iframe>
// as the SOLE session owner. This shell keeps only the host-side concerns that
// MUST live on the CMS origin: the launcher/panel chrome and the parent half of
// the §12/§12b parent↔iframe bridge.
//
// WHAT PROTOCOL 2 CHANGED, AND WHY IT IS THE WHOLE POINT (cinatra#2674).
// At protocol 1 this file was a party to the person's sign-in: it ran the hosted
// PKCE handshake through same-origin CMS relays, received the `cwu_` per-user
// bearer back, minted a `cit_` site transport token, and composed BOTH into a
// postMessage BOOTSTRAP. That made the website a HOLDER of a credential that
// belongs to the person and to Cinatra.
//
// That is over. This shell now:
//   * initiates NO sign-in and redeems NO code — the frame runs the whole
//     ceremony on the Cinatra origin, in a top-level Cinatra popup it opens
//     itself, and the credential never leaves the frame;
//   * composes and receives NO bearer — `cwu_` and `cit_` do not appear in CMS
//     plugin/module code at all, and the retired token-broker relays are DELETED
//     (the instance answers the old `/api/widget-auth/{init,token}` pair 410 Gone);
//   * posts ONE inbound message, `cinatra.embed.context`, carrying PUBLIC,
//     UNTRUSTED SELECTORS only (which site, which agent, which CMS resource is
//     on screen) at protocol version literal 2.
// The long-lived `cnx_` connect-site credential is UNCHANGED and stays exactly
// what it was: a backend-only setup/integration credential, used server-to-server
// from the CMS backend, never in the browser and never on this bridge.
//
// TRUST BOUNDARY (§4/§6/§12/§12b) — the UNION, control by control:
//   * THE INSTANCE MUST BE A DIFFERENT ORIGIN FROM THIS PAGE, and the widget
//     REFUSES TO MOUNT when it is not. Everything else rests on that: the
//     credential lives in the frame's memory, and "the site cannot read it" is an
//     ORIGIN guarantee, not a code guarantee. On a shared origin the guarantee is
//     not weakened, it is ABSENT — and a widget that ran there would quietly claim
//     a protection it does not have.
//   * The iframe framing `/embed/assistant` is sandboxed with EXACTLY four
//     tokens: `allow-scripts allow-same-origin allow-popups
//     allow-popups-to-escape-sandbox`. The two popup grants are REQUIRED at
//     protocol 2 and are not optional hardening to be tidied away later: the
//     FRAME opens the hosted sign-in with `window.open`, which a sandbox without
//     `allow-popups` blocks outright, and a popup that merely inherited this
//     sandbox would have no forms and no top-level navigation, so the ceremony
//     could not complete. The escape applies to the window the frame opens, NOT
//     to the frame: no top-navigation, no forms, no modals, no downloads and no
//     pointer-lock are granted here. THIS SHELL still opens no window itself.
//   * The credential guard runs in BOTH directions, BEFORE any field of the
//     envelope is read, and its bounds FAIL CLOSED. A payload carrying a
//     bearer-shaped value is refused on the way out (through `sendToFrame`, the
//     single outbound choke point) and dropped on the way in, on both transports.
//     On the sending side an unknown answer must mean refusal, so a structure too
//     deep to finish walking — or a container the walk cannot enumerate — is
//     treated as carrying a credential.
//     STATED, NOT HIDDEN: fail-closed INBOUND buys confidentiality with
//     availability. The uplink set is closed and every uplink in it is flat
//     primitives, so nothing legitimate is dropped today; a future uplink that
//     nested past the depth bound, or carried a non-plain container, WOULD be
//     dropped rather than read. That is the deliberate trade — the alternative is
//     reading an envelope the walk could not clear, and the frame is the one party
//     that actually holds a bearer.
//   * THE FRAME URL IS AN OUTBOUND PAYLOAD TOO, and it is scanned on its RAW
//     COMPONENTS BEFORE ENCODING. The bridge guard cannot reach the src: it leaves
//     as an HTTP request and lands in history, an access log and a referrer. And
//     `encodeURIComponent` destroys the token boundary the scan matches on, so a
//     finished-string-only scan is bypassable.
//   * PORT-BOUND TRANSPORT (§12b): the iframe creates a MessageChannel and
//     transfers ONE endpoint in the origin/source-gated READY. The parent RETAINS
//     that endpoint and sends the CONTEXT message (plus any later parent→iframe
//     traffic) over it. At protocol 2 this is no longer a credential wall — there
//     is no credential to misdeliver — but it is kept because the property still
//     holds and costs nothing: a same-origin replacement of the frame is a fresh
//     realm that never inherits the entangled endpoint, so it cannot silently
//     take over an established session's channel. A READY transferring MORE than
//     one port is refused outright rather than reduced to its first.
//   * The WINDOW transport remains for a frame that transfers no port; when used
//     it still posts to an EXPLICIT targetOrigin (the Cinatra instance origin),
//     NEVER "*". A deployment hardens by setting `requirePort` in its CMS config,
//     which refuses the port-less READY outright.
//   * Inbound frame messages are accepted ONLY when `event.origin === cinatraOrigin`
//     AND `event.source === iframe.contentWindow` (origin + source-window binding).
//     Steady-state uplinks in PORT mode ride the entangled port (their provenance
//     is the origin-targeted transfer that delivered it — a NARROWING).
//   * READY→CONTEXT: the parent mints a CSPRNG correlationId (≥128-bit), echoes
//     the frame nonce, sends seq=0, and ONE context per DOCUMENT. A refused send
//     is NOT retried — the latch is set before the send and never cleared on
//     failure, so there is no retry storm. A frame that RELOADS is a new document
//     and announces itself with a FRESH nonce: that starts a new EPOCH (old port
//     closed, every binding cleared) so a reloaded frame cannot hang forever with
//     a leaked MessagePort. A REPLAY of the nonce already answered is still
//     ignored. The two compose: exactly one attempt per document, and a new
//     document is not a retry. The parent cannot VERIFY a reload, so the number of
//     epochs is BOUNDED — a frame that manufactures fresh nonces without
//     navigating cannot turn the recovery path into unbounded parent work.
//   * The epoch reset runs AFTER every READY validation. A malformed READY must
//     not be able to tear down an established session on its way to being
//     refused — that would turn a rejected message into a denial of service.
//   * Two INDEPENDENT monotonic seq counters (one per direction) per correlationId,
//     and the CLOSED uplink type set is checked BEFORE the seq gate commits: a
//     message that will be dropped anyway must not spend from a scarce, one-way
//     resource.
//   * Every selector is NORMALIZED TO A STRING ONCE, in one place, and then
//     BOUND-CHECKED in UTF-16 code units against the frame's strict schema. An
//     out-of-bounds OPTIONAL selector is OMITTED (never truncated — a truncated id
//     is a different id); an out-of-bounds REQUIRED instance id means no frame at
//     all rather than a frame that would reject its own context.
//   * apply_intent carries an UNTRUSTED SELECTOR only: the parent re-checks the
//     current user may edit, uses its OWN canonical resource, dedups against a
//     bounded LRU, and does an in-place draft refresh — NO direct content-API
//     egress (#1214: field-apply happens server-side via the CMS MCP integration).
//   * resize height is CLAMPED to the panel cap (clamp, never trust the value).
//
// Security-critical invariants (no apiKey and no bearer of any shape in the
// browser; no token-broker call; no sign-in ceremony on the CMS origin; the
// same-origin refusal; the exact four-token sandbox; explicit targetOrigin;
// source-window binding; the credential-shaped value guard on every send and both
// inbound transports; the raw-component URL scan; the epoch reset ordering; the
// selector bounds; no apply-time egress) are gated by tools/widget-parity-check.mjs
// in CI — the SAME gate, also shipped verbatim to both repos.
//
// ---------------------------------------------------------------------------
// NOTICE (Apache License 2.0)
//
//   Cinatra
//   Copyright (c) Cinatra
//
//   This product includes software developed by Cinatra (https://cinatra.ai).
//   Portions of this file are derived from the Cinatra project
//   (cinatra-ai/cinatra), licensed under the Apache License, Version 2.0.
//   You may obtain a copy of the License at:
//
//       http://www.apache.org/licenses/LICENSE-2.0
//
//   Unless required by applicable law or agreed to in writing, software
//   distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
//   WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
//   License for the specific language governing permissions and limitations
//   under the License.
//
// Both host projects (the WordPress plugin and the Drupal module) are licensed
// GPL-2.0-or-later as a whole; this vendored file is incorporated under the
// Apache-2.0 grant above, which is GPL-compatible (under GPLv3, which
// "GPL-2.0-or-later" reaches), so both stay distributable on their own directory.
// ---------------------------------------------------------------------------
(function () {
  // ---------------------------------------------------------------------------
  // CMS SEAM 1 of 4 — THE CONFIG BROKER, and the only CMS detection in the file.
  //
  // The browser holds NO credential of any kind — no long-lived key, no broker
  // endpoint, no bearer. All it needs is the instance URL to frame, plus the
  // public selectors it will hand the frame. Each CMS publishes that through its
  // own settings broker, and only its own: WordPress localizes a
  // `window.CinatraConfig` object, Drupal writes `drupalSettings.cinatra`. A page
  // carries one or the other, never both, so the presence of a broker IS the CMS
  // detection — there is nothing to configure and nothing to build.
  // ---------------------------------------------------------------------------
  // Each read is GUARDED, because one of the two globals belongs to the OTHER
  // host and is therefore something else's property on this page. A stray
  // `drupalSettings` planted by an unrelated WordPress plugin — or a hostile
  // getter on it — must not be able to throw this whole IIFE away before the
  // widget mounts.
  function readBroker(read) {
    try {
      var value = read();
      return value && typeof value === 'object' ? value : null;
    } catch (_) {
      return null;
    }
  }
  // The usability probe is guarded TOO, not just the global read. Reaching a
  // foreign object's property is reaching a foreign
  // getter: `wordpressConfig.cinatraUrl` on a Drupal page runs code somebody else
  // wrote, and an exception there would abort this IIFE and take a perfectly valid
  // Drupal widget down with it.
  function brokerUrl(broker) {
    try {
      var url = broker && broker.cinatraUrl;
      return typeof url === 'string' && url ? url : '';
    } catch (_) {
      return '';
    }
  }
  // USABILITY decides, not precedence. A page carries one broker in practice, but
  // "whichever is truthy first" would let an empty `CinatraConfig` left behind by
  // something else on a Drupal page select the WordPress branch and then refuse to
  // mount for want of a cinatraUrl — the widget disabled by a foreign global. The
  // broker that actually carries the instance URL is the one this page was
  // configured with.
  //
  // And the OTHER host's global is only touched if this one's is unusable: on
  // WordPress — the common case — `drupalSettings` is never read at all. A guard
  // contains a throw; it cannot contain a side effect or a getter that never
  // returns, so the cheapest protection is not to look.
  var wordpressConfig = readBroker(function () { return window.CinatraConfig; });
  var wordpressUrl = brokerUrl(wordpressConfig);
  var drupalConfig = null;
  var drupalUrl = '';
  if (!wordpressUrl) {
    drupalConfig = readBroker(function () { return window.drupalSettings && window.drupalSettings.cinatra; });
    drupalUrl = brokerUrl(drupalConfig);
  }
  var CMS = null;
  var config = null;
  // The instance URL is CAPTURED ONCE, here, and every later use reads this
  // capture rather than the broker again. Re-reading a mutable config object at
  // each use is the same class of defect as re-reading the instance id was: the
  // origin the widget validated must be the origin it frames.
  var cinatraUrl = '';
  if (wordpressUrl) {
    CMS = 'wordpress';
    config = wordpressConfig;
    cinatraUrl = wordpressUrl;
  } else if (drupalUrl) {
    CMS = 'drupal';
    config = drupalConfig;
    cinatraUrl = drupalUrl;
  }
  if (!CMS) {
    console.warn('[cinatra] no CMS settings broker carries a cinatraUrl (CinatraConfig | drupalSettings.cinatra); widget not mounted');
    return;
  }
  var rootEl = document.getElementById('cinatra-root');
  if (!rootEl) { console.warn('[cinatra] #cinatra-root not found'); return; }
  if (rootEl.dataset.cinatraMounted === 'true') return;
  // NOTE: data-cinatra-mounted is set at the END of synchronous mount construction
  // (see mountWidget()). The shell no longer pre-flight-negotiates, so it mounts
  // unconditionally at boot; a throw mid-mount still leaves the fallback chrome
  // visible (the marker that hides it is set LAST).

  // ---------------------------------------------------------------------------
  // CMS SEAM 2 of 4 — the `?assistant` value, which is exactly the CMS name.
  //
  // §4: the agent identifier this parent may name. It is a SELECTOR, never an
  // assertion: the instance re-derives the authoritative agent from its own closed
  // host-side table and denies on any mismatch, so naming another site's agent
  // yields a refusal, not that agent. MUST equal the embed page's
  // `session.assistant` agreement check.
  // ---------------------------------------------------------------------------
  var EMBED_ASSISTANT = CMS;

  // ---------------------------------------------------------------------------
  // §12/§12b bridge protocol constants (the byte-level contract both halves pin).
  // Mirror of cinatra-ai/cinatra src/lib/embed/bridge-protocol.ts — kept in sync
  // by review + the parity gate. There is NO arbitrary-tool channel: the message
  // type set is CLOSED.
  //
  // VERSION 2 (cinatra#2674) is deliberately BREAKING. A protocol-1 parent and a
  // protocol-2 frame cannot negotiate at all, which is what makes "the parent can
  // no longer deliver a credential" TRUE rather than merely intended: there is no
  // silent fallback to the retired credential-bearing bootstrap, and the retired
  // inbound type is not even named here, so it cannot be reached by name.
  // ---------------------------------------------------------------------------
  var EMBED_PROTOCOL_VERSION = 2;
  var MSG = {
    ready: 'cinatra.embed.ready',       // iframe -> parent, pre-context (no correlationId)
    context: 'cinatra.embed.context',   // parent -> iframe, PUBLIC SELECTORS ONLY
    resize: 'cinatra.embed.resize',     // iframe -> parent
    focus: 'cinatra.embed.focus',       // iframe -> parent
    a11y: 'cinatra.embed.a11y',         // iframe -> parent
    applyIntent: 'cinatra.embed.apply_intent', // iframe -> parent
  };
  // A CSPRNG base64url id carrying >=128 bits of entropy is >=22 chars; charset +
  // length are enforced so a merely-short/low-entropy id is rejected (§6b).
  var ID_PATTERN = /^[A-Za-z0-9_-]{22,128}$/;
  var RESIZE_MAX_HEIGHT = 20000;                 // §5/§B9 schema upper bound
  var APPLY_INTENT_VIEW_TYPES = ['content_change_proposal'];
  var APPLY_LRU_MAX = 64;                         // §6f bounded seen-id LRU

  // ---------------------------------------------------------------------------
  // SELECTOR NORMALIZATION, then the protocol-2 SELECTOR BOUNDS. Both halves are
  // needed and they run in this order.
  //
  // NORMALIZE FIRST, ONCE, IN ONE PLACE. The frame's schema is strict about TYPES
  // as well as keys, so a numeric resource id or a boolean status arriving from a
  // hand-edited settings array would make the whole message unparseable on the
  // other side — a silent dead widget rather than a visible error. Normalizing
  // here is also what lets the credential guard reason simply: after this, every
  // selector is a plain string, so no non-plain container can reach a composed
  // message at all.
  //
  // THEN BOUND-CHECK, IN UTF-16 CODE UNITS. The frame's schema is `.strict()`, so
  // ONE over-long display field rejects the WHOLE message and the session never
  // starts — the frame just sits in its neutral "waiting for host" state forever,
  // with nothing to tell the site owner why. An OPTIONAL selector that would
  // exceed its bound is therefore OMITTED rather than sent: it is a disambiguator,
  // and losing a disambiguator costs far less than losing the session. It is never
  // TRUNCATED — a truncated id is a different id, and a selector that quietly
  // names something else is worse than one that is absent. `String.length` in
  // JavaScript already counts UTF-16 code units, which is exactly the unit the
  // core schema bounds, so a valid non-ASCII selector is not blanked by a byte
  // bound.
  // ---------------------------------------------------------------------------
  var SELECTOR_MAX = {
    siteId: 200,        // site.siteId
    instanceId: 200,    // cms.instanceId (REQUIRED, min 1)
    resourceId: 200,    // cms.resourceId
    resourceType: 200,  // cms.resourceType
    status: 64,         // cms.status
  };
  /** A string, normalized from the CMS's own value; '' when it is not a selector. */
  function asSelector(value) {
    if (value === null || value === undefined) { return ''; }
    if (typeof value === 'string') { return value; }
    // A number or boolean is normalized; anything else (an object, an array) is
    // not a selector and is dropped rather than stringified into nonsense.
    if (typeof value === 'number' || typeof value === 'boolean') { return String(value); }
    return '';
  }
  /** The value when it is a non-empty string within its bound, else null. */
  function boundedSelector(value, max) {
    if (typeof value !== 'string') return null;
    if (value.length === 0 || value.length > max) return null;
    return value;
  }

  // ---------------------------------------------------------------------------
  // CREDENTIAL-SHAPED VALUE GUARD (cinatra#2674) — the second half of "no
  // credential crosses this boundary", mirrored from the core
  // `containsCredentialShapedValue`.
  //
  // The context schema has no `auth`, so there is no credential FIELD. Every
  // remaining field is still a string THIS FILE chooses from CMS state, so a
  // bearer could in principle be smuggled into `cms.resourceId` or a site id by a
  // misconfiguration or a compromised option. Every outbound payload is therefore
  // scanned before it is sent and the send is REFUSED on a hit.
  //
  // This is a CONTAINMENT control, not a secret detector: it cannot recognise a
  // credential that carries no prefix, and it is not asked to. What it guarantees
  // — and what the credential-egress harness pins with synthetic sentinels — is
  // that nothing this shell puts on the bridge (or in the frame URL) can be shaped
  // like one of our bearers, at any depth, in a value or a key.
  // ---------------------------------------------------------------------------
  var CREDENTIAL_VALUE_PREFIXES = ['cwu_', 'cit_', 'cnx_'];
  // A bearer prefix ANYWHERE in the string, at a token boundary — a prefix-only
  // test would let `'Error: cwu_…'` and `'https://x/?t=cit_…'` through, and an
  // error string or a URL is exactly how one arrives there by accident. The
  // boundary class keeps it from firing on a word that merely ENDS in the letters
  // (a fictional 'abccwu_'). Case-insensitive: a value that differs from a
  // credential only by case is a credential someone is trying to sneak past.
  var CREDENTIAL_TOKEN_RE = new RegExp(
    '(?:^|[^A-Za-z0-9])(?:' +
      CREDENTIAL_VALUE_PREFIXES.map(function (p) { return p.slice(0, -1); }).join('|') +
      ')_',
    'i'
  );
  function isCredentialShapedValue(value) {
    return typeof value === 'string' && CREDENTIAL_TOKEN_RE.test(value);
  }
  // EVERY UNKNOWN ANSWER IS "YES". This is the SENDER's guard, so its bounds must
  // fail CLOSED: a structure too deep to finish walking, a container this walk
  // cannot enumerate (a Map, a Set, a cyclic graph), or anything else it cannot
  // positively clear is treated as carrying a credential and the message is
  // refused. The alternative — the receiver-side habit of returning false on "I
  // could not tell" — would make the guard defeatable by nesting, which is exactly
  // the hole it exists to close. Nothing this shell legitimately composes nests
  // more than three deep or holds a non-plain object, so failing closed costs a
  // real message nothing.
  //
  // The same fail-closed answer is the SAFE one inbound as well: an envelope the
  // walk cannot clear is DROPPED rather than read, and dropping an unreadable
  // uplink costs a resize hint, while reading one could put a bearer in this
  // page's DOM.
  // THE WALK IS BOUNDED IN TOTAL WORK, not only in depth. Depth 8 bounds how
  // DEEP it goes; it says nothing about how WIDE.
  // Inbound this guard is the FIRST thing that touches an envelope the frame
  // sent, so an origin-and-source-valid message carrying a sparse array with an
  // enormous `length` would spin the walk through every hole before anything
  // rejected the message — a stall in the CMS admin page, reachable by the one
  // party on the other side of this bridge. A node budget makes every possible
  // input cost the same bounded amount, and exhausting it is another unknown
  // answer, so it fails CLOSED like the rest.
  var CREDENTIAL_SCAN_MAX_NODES = 4096;
  function containsCredentialShapedValue(value, depth, budget) {
    var d = depth || 0;
    var b = budget || { left: CREDENTIAL_SCAN_MAX_NODES };
    if (b.left <= 0) { return true; }
    b.left--;
    if (isCredentialShapedValue(value)) { return true; }
    if (value === null || typeof value !== 'object') { return false; }
    if (d >= 8) { return true; }
    var i;
    var tag = Object.prototype.toString.call(value);
    if (tag === '[object Array]') {
      for (i = 0; i < value.length; i++) {
        if (containsCredentialShapedValue(value[i], d + 1, b)) { return true; }
      }
      return false;
    }
    // Only a plain object can be walked exhaustively with Object.keys; anything
    // else (Map, Set, Date, a cross-realm object, a Proxy) may hold values this
    // walk would never see, so it is refused rather than waved through.
    if (tag !== '[object Object]') { return true; }
    // ENUMERATED LAZILY, and the budget is consulted INSIDE the loop.
    // `Object.keys()` would materialize every own key BEFORE the budget could
    // stop anything, so an envelope with a pathological number of properties
    // would cost a full enumeration and allocation before the first check ran —
    // the budget bounding the walk but not the thing that precedes it. `for…in`
    // + hasOwnProperty covers the same key set
    // (own enumerable string keys — symbols and non-enumerables are invisible to
    // both, which is why a non-plain container is refused above) and stops the
    // moment the budget is spent.
    //
    // RESIDUAL, STATED RATHER THAN CLAIMED AWAY:
    // this bounds the work THIS WALK does — comparisons, recursion, allocations
    // of ours. It cannot bound the engine's own preparation of an enumeration for
    // a pathological object, and no JavaScript-level guard can, short of refusing
    // to look at inbound messages at all. What keeps that bounded in practice is
    // that the frame must first serialize such an object through structured
    // clone, so the cost is at worst matched, never amplified.
    for (var key in value) {
      // The budget is spent BEFORE the ownership test, so a prototype carrying a
      // vast number of enumerable properties cannot run this loop for free.
      if (b.left <= 0) { return true; }
      b.left--;
      if (!Object.prototype.hasOwnProperty.call(value, key)) { continue; }
      // A key is as visible to a logger as a value.
      if (isCredentialShapedValue(key)) { return true; }
      if (containsCredentialShapedValue(value[key], d + 1, b)) { return true; }
    }
    return false;
  }

  // The Cinatra instance origin — the ONLY origin the bridge posts to and the
  // ONLY origin/source it accepts uplinks from. Resolved ONCE, strictly.
  var cinatraOrigin = null;
  try { cinatraOrigin = new URL(cinatraUrl).origin; } catch (_) { cinatraOrigin = null; }
  if (!cinatraOrigin) {
    console.warn('[cinatra] cinatraUrl is not a valid origin; widget not mounted');
    return;
  }

  // PROTOCOL 2 REQUIRES A REAL ORIGIN BOUNDARY. The promise this protocol makes is
  // that the SITE cannot come to possess the person's Cinatra credential: the
  // credential is minted by the frame, held in the frame's memory, and never
  // crosses the postMessage boundary. That promise rests entirely on the frame
  // being a DIFFERENT ORIGIN from the page around it. If an instance is deployed
  // on this site's own origin — a reverse proxy serving Cinatra under the CMS host
  // — then site JavaScript can reach straight into the frame's realm and read what
  // it holds, and the guarantee is not weakened, it is simply absent.
  //
  // Under protocol 1 that arrangement cost nothing new, because the site
  // legitimately held the credential anyway. Under protocol 2 it would make the
  // widget quietly claim a protection it does not have, which is worse than not
  // running. So a same-origin instance is REFUSED here: the fallback chrome stays
  // visible and the operator gets a diagnostic naming the reason.
  var pageOrigin = null;
  try { pageOrigin = window.location && window.location.origin ? window.location.origin : null; } catch (_) { pageOrigin = null; }
  if (pageOrigin && cinatraOrigin === pageOrigin) {
    console.warn('[cinatra] the Cinatra instance is on this site\'s own origin; the assistant needs a separate origin to keep each person\'s sign-in private, so it was not mounted');
    return;
  }

  // ---------------------------------------------------------------------------
  // mountWidget() — builds the Shadow DOM + wires the launcher and the
  // parent-side bridge. Called UNCONDITIONALLY at boot: the capability/contract
  // handshake runs CLIENT-SIDE inside the /embed/assistant iframe against the
  // unified broker surface, and (since protocol 2) so does the sign-in. There is
  // no shell pre-flight and no shell login gate left to condition the mount on.
  //
  // THERE IS NO LOGIN GATE HERE ANY MORE (cinatra#2674). Sign-in is not this
  // shell's business: the frame decides whether the person is signed in and, if
  // not, shows its own sign-in inside the frame and runs the whole ceremony on the
  // Cinatra origin. So the panel has exactly one body — the frame — and the frame
  // is mounted LAZILY on the first panel open (a plain user gesture). Framing an
  // authenticated Cinatra surface on every admin page a permitted editor merely
  // LOOKS at would be a request nobody asked for; until the panel is opened this
  // page makes no request to the instance at all.
  // ---------------------------------------------------------------------------
  function mountWidget() {
  // Idempotency guard: a second copy of this IIFE (a double script include) could
  // already have mounted.
  if (rootEl.dataset.cinatraMounted === 'true' || rootEl.shadowRoot) { return; }
  var shadow = rootEl.attachShadow({ mode: 'open' });
  // The data-cinatra-mounted marker (which hides the fallback chrome) is set at
  // the very END of synchronous mount construction. A throw at any point during
  // mount therefore leaves the fallback visible rather than hiding it over a
  // half-built / dead widget.

  // ---------------------------------------------------------------------------
  // CSS
  // Collapsed: single logo circle (position:fixed, bottom-right).
  // Expanded:  .cw-widget (panel), same anchor. The conversation body is the
  //            mounted <iframe>; the textarea/submit live INSIDE the iframe.
  //            Drag the top-left corner (.cw-resize) to resize width + panel height.
  // ---------------------------------------------------------------------------
  var style = document.createElement('style');
  style.textContent = [
    ':host { all: initial; }',

    /* Collapsed logo circle. */
    '.cw-circle {',
    '  position: fixed; bottom: 66px; right: 36px;',
    '  width: 32px; height: 32px; border-radius: 9999px;',
    '  background: #e6ede7; border: 1.5px solid #c79545; cursor: pointer;',
    '  display: flex; align-items: center; justify-content: center;',
    '  box-shadow: 0 4px 16px rgba(0,0,0,0.18);',
    '  transition: background 0.15s; z-index: 10000000;',
    '  touch-action: none;',
    '}',
    '.cw-circle:hover { background: #d8e7db; }',

    /* Expanded widget: position:fixed container. */
    '.cw-widget {',
    '  position: fixed; bottom: 56px; right: 24px;',
    '  z-index: 10000000;',
    '}',

    /* Resize corner: top-left of widget, drag to resize width+height */
    '.cw-resize {',
    '  position: absolute; top: 0; left: 0;',
    '  width: 20px; height: 20px;',
    '  cursor: nwse-resize;',
    '  z-index: 3;',
    '}',

    /* Panel: fills the widget; header on top, the embed iframe below. */
    '.cw-panel {',
    '  position: absolute; top: 0; left: 0; right: 0; bottom: 0;',
    '  box-sizing: border-box;',
    '  background: #f7f7f3; color: #15213a;',
    '  border: 1px solid #15213a14; border-radius: 16px;',
    '  box-shadow: 0 16px 48px rgba(0,0,0,0.2);',
    '  display: flex; flex-direction: column; overflow: hidden;',
    '  z-index: 1;',
    '}',

    /* Panel header */
    '.cw-panel-header {',
    '  padding: 12px 16px; border-bottom: 1px solid #15213a14;',
    '  display: flex; align-items: center; justify-content: space-between;',
    '  background: #eceeea; flex-shrink: 0;',
    '}',
    '.cw-header-left { display: flex; align-items: center; gap: 8px; }',
    '.cw-wordmark { font: italic 800 14px Archivo, system-ui, sans-serif; color: #c79545; letter-spacing: -0.022em; }',
    '.cw-close {',
    '  background: none; border: none; cursor: pointer;',
    '  font-size: 20px; line-height: 1; color: #5a6477;',
    '  padding: 2px 6px; border-radius: 6px;',
    '  display: flex; align-items: center; justify-content: center;',
    '}',
    '.cw-close:hover { background: #f7f7f3; color: #15213a; }',

    /* Conversation body: the sandboxed embed iframe fills the panel body. */
    '.cw-frame-host { flex: 1; min-height: 0; display: flex; }',
    '.cw-frame {',
    '  flex: 1; width: 100%; height: 100%; border: none; background: #f7f7f3;',
    '  display: block;',
    '}',

    /* Visually-hidden aria-live region: the parent mirrors iframe a11y status
       here as textContent (never HTML) so host-page assistive tech is notified. */
    '.cw-a11y-live {',
    '  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;',
    '  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;',
    '}',

    /* There is deliberately NO login chrome here (cinatra#2674). The sign-in
       affordance — and every message about it — belongs to the Cinatra frame,
       which owns the ceremony. This CMS page renders no auth UI, no auth error,
       and no credential input of any kind. */
  ].join('\n');
  shadow.appendChild(style);

  // WEBFONT POLICY — a CMS-directory rule, not a design choice, and the one place
  // the two hosts disagree about a resource rather than an accessor. Drupal.org
  // requires a module to be FULLY LOCAL: an undisclosed third-party browser
  // request to fonts.googleapis.com would breach it, so the Drupal copy has always
  // fallen back to the system-ui stack already declared in the CSS font-family
  // (Archivo, system-ui, sans-serif). WordPress.org has no such rule and the
  // WordPress copy has always injected the font into the document head (fonts must
  // be in document scope to work inside a shadow root). Both remain true here.
  if (CMS === 'wordpress') {
    var FONT_URL = 'https://fonts.googleapis.com/css2?family=Archivo:ital,wght@0,400;0,500;0,600;1,800&display=swap';
    if (!document.querySelector('link[href="' + FONT_URL + '"]')) {
      var fontLink = document.createElement('link');
      fontLink.rel = 'stylesheet';
      fontLink.href = FONT_URL;
      document.head.appendChild(fontLink);
    }
  }

  // ---------------------------------------------------------------------------
  // SVG builders
  // ---------------------------------------------------------------------------
  var SVG_NS = 'http://www.w3.org/2000/svg';
  var LOGO_VIEWBOX = '0 0 512 320';
  var LOGO_BRIM = 'M72 214 C 72 200 96 190 130 188 C 168 186 196 200 256 210 C 316 220 358 214 400 200 C 426 192 440 196 440 208 C 440 222 420 234 388 242 C 340 254 288 256 256 256 C 202 256 132 248 100 238 C 80 232 72 224 72 214 Z';
  var LOGO_CROWN = 'M146 188 C 150 130 176 86 212 72 C 226 66 240 64 252 64 C 262 64 270 70 268 80 L 264 100 C 272 88 288 82 300 82 C 332 82 356 118 362 188 Z';
  var LOGO_COLOR = '#c79545';
  function mkEl(tag, attrs) {
    var el = document.createElementNS(SVG_NS, tag);
    for (var k in attrs) el.setAttribute(k, String(attrs[k]));
    return el;
  }
  function mkSvg(w, h, vb) { return mkEl('svg', { width: w, height: h, viewBox: vb, fill: 'none' }); }

  function makeLogoSvg() {
    var svg = mkSvg(22, 14, LOGO_VIEWBOX);
    svg.setAttribute('fill', 'none');
    svg.appendChild(mkEl('path', { d: LOGO_BRIM, fill: LOGO_COLOR }));
    svg.appendChild(mkEl('path', { d: LOGO_CROWN, fill: LOGO_COLOR }));
    return svg;
  }

  function makeLogoDarkSvg() {
    var svg = mkSvg(22, 14, LOGO_VIEWBOX);
    svg.setAttribute('fill', 'none');
    svg.appendChild(mkEl('path', { d: LOGO_BRIM, fill: LOGO_COLOR }));
    svg.appendChild(mkEl('path', { d: LOGO_CROWN, fill: LOGO_COLOR }));
    return svg;
  }

  // ---------------------------------------------------------------------------
  // DOM: collapsed circle
  // ---------------------------------------------------------------------------
  var circle = document.createElement('button');
  circle.className = 'cw-circle';
  circle.type = 'button';
  circle.appendChild(makeLogoSvg());
  shadow.appendChild(circle);

  // ---------------------------------------------------------------------------
  // Circle drag-to-reposition (session-only, no persistence)
  // ---------------------------------------------------------------------------
  function applyCirclePos(left, top) {
    circle.style.left = left + 'px';
    circle.style.top = top + 'px';
    circle.style.right = 'auto';
    circle.style.bottom = 'auto';
  }
  function clampCirclePos(left, top) {
    left = Math.max(0, Math.min(window.innerWidth - 32, left));
    top = Math.max(0, Math.min(window.innerHeight - 32, top));
    return { left: left, top: top };
  }

  var circleDragging = false;
  var circleDragStartX = 0, circleDragStartY = 0;
  var circleDragStartLeft = 0, circleDragStartTop = 0;
  var circleDragMoved = false;
  var CIRCLE_DRAG_THRESHOLD = 4;

  circle.addEventListener('mousedown', function(e) {
    e.preventDefault();
    var rect = circle.getBoundingClientRect();
    circleDragStartX = e.clientX;
    circleDragStartY = e.clientY;
    circleDragStartLeft = rect.left;
    circleDragStartTop = rect.top;
    circleDragging = true;
    circleDragMoved = false;
  });

  // ---------------------------------------------------------------------------
  // DOM: expanded widget — panel (header + body)
  // ---------------------------------------------------------------------------
  var currentWidth = 580;
  var currentPanelHeight = 460;   // total panel height (header + body)
  var MIN_PANEL_HEIGHT = 260;
  var userResizedPanel = false;   // a manual drag pins the height (disables auto-grow)

  function maxPanelHeight() { return Math.max(MIN_PANEL_HEIGHT, window.innerHeight - 120); }

  function setWidgetSize() {
    cwWidget.style.width = currentWidth + 'px';
    cwWidget.style.height = currentPanelHeight + 'px';
  }

  var cwWidget = document.createElement('div');
  cwWidget.className = 'cw-widget';
  cwWidget.style.display = 'none';
  shadow.appendChild(cwWidget);

  var resizeEl = document.createElement('div');
  resizeEl.className = 'cw-resize';
  cwWidget.appendChild(resizeEl);

  var panel = document.createElement('div');
  panel.className = 'cw-panel';
  cwWidget.appendChild(panel);

  var panelHeader = document.createElement('div');
  panelHeader.className = 'cw-panel-header';
  panel.appendChild(panelHeader);

  var headerLeft = document.createElement('div');
  headerLeft.className = 'cw-header-left';
  headerLeft.appendChild(makeLogoDarkSvg());
  var wordmark = document.createElement('span');
  wordmark.className = 'cw-wordmark';
  wordmark.textContent = 'Cinatra';
  headerLeft.appendChild(wordmark);
  panelHeader.appendChild(headerLeft);

  var closeBtn = document.createElement('button');
  closeBtn.className = 'cw-close';
  closeBtn.type = 'button';
  closeBtn.setAttribute('aria-label', 'Close');
  closeBtn.textContent = '×';
  panelHeader.appendChild(closeBtn);

  // Visually-hidden aria-live region: mirrors iframe a11y uplinks (textContent).
  var a11yLive = document.createElement('div');
  a11yLive.className = 'cw-a11y-live';
  a11yLive.setAttribute('role', 'status');
  a11yLive.setAttribute('aria-live', 'polite');
  panel.appendChild(a11yLive);

  // Conversation body host — the sandboxed embed iframe is mounted here on the
  // first open. It is the panel's ONLY body: whatever the person needs to see
  // before they are signed in (including the sign-in itself) is drawn by the
  // frame, on the Cinatra origin.
  var frameHost = document.createElement('div');
  frameHost.className = 'cw-frame-host';
  panel.appendChild(frameHost);

  // ---------------------------------------------------------------------------
  // State
  //
  // Note what is NOT here since protocol 2 (cinatra#2674): no `userToken`, no
  // PKCE handshake, no popup watcher, no panel mode. This shell holds no
  // credential and no authentication state, so there is none to lose, expire or
  // leak — the frame owns all of it.
  // ---------------------------------------------------------------------------
  var isOpen = false;

  // ---------------------------------------------------------------------------
  // CMS SEAM 3 of 4 — the CANONICAL RESOURCE ACCESSOR.
  //
  // These are the PUBLIC selectors for the `cms` block of the CONTEXT message,
  // and the parent's OWN canonical resource for apply_intent. Nothing here is
  // authority and nothing here is secret: the instance re-derives the
  // authoritative site, org, origin, agent and canonical instance from its own
  // rows and denies on any mismatch.
  //
  // The two hosts answer "what is on screen" in genuinely different ways, and
  // that is the whole seam. WordPress edits in a CLIENT editor store, so the
  // selectors are read live from `wp.data` (with the classic-editor DOM as the
  // fallback). Drupal node edit forms are SERVER-rendered, so the module hands the
  // selectors down through its settings broker. Both are mapped here onto the ONE
  // shared shape the rest of the file uses, and every value is normalized to a
  // string exactly once, right here.
  // ---------------------------------------------------------------------------
  function buildContentContext() {
    if (CMS === 'wordpress') {
      var postId =
        (window.wp && window.wp.data &&
          window.wp.data.select('core/editor') &&
          window.wp.data.select('core/editor').getCurrentPostId &&
          window.wp.data.select('core/editor').getCurrentPostId()) ||
        (document.querySelector('#post_ID') && document.querySelector('#post_ID').value) ||
        '';

      var postStatus =
        (window.wp && window.wp.data &&
          window.wp.data.select('core/editor') &&
          window.wp.data.select('core/editor').getEditedPostAttribute &&
          window.wp.data.select('core/editor').getEditedPostAttribute('status')) ||
        (document.querySelector('#post-status-display') &&
          document.querySelector('#post-status-display').textContent.trim().toLowerCase()) ||
        '';

      return {
        instanceId:   asSelector(config.instanceId),
        siteId:       asSelector(config.siteId),
        resourceId:   asSelector(postId),
        resourceType: asSelector(typeof window.typenow !== 'undefined' ? window.typenow : ''),
        status:       asSelector(postStatus),
      };
    }
    return {
      instanceId:   asSelector(config.instanceId),
      siteId:       asSelector(config.siteId),
      resourceId:   asSelector(config.nodeId),
      resourceType: asSelector(config.nodeBundle),
      status:       asSelector(config.nodeStatus),
    };
  }

  // ---------------------------------------------------------------------------
  // CSPRNG id minting. base64url(no padding) of a random byte array — the ONLY
  // cryptographic thing left in this file now that the sign-in ceremony belongs to
  // the frame. It mints the bridge correlationId; it mints no PKCE verifier,
  // because this shell starts no PKCE transaction.
  // ---------------------------------------------------------------------------
  function b64url(bytes) {
    var s = '';
    for (var i = 0; i < bytes.length; i++) { s += String.fromCharCode(bytes[i]); }
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function randB64url(n) {
    var a = new Uint8Array(n);
    crypto.getRandomValues(a);
    return b64url(a);
  }

  // ---------------------------------------------------------------------------
  // §12/§12b PARENT-SIDE BRIDGE — the host half of the parent↔iframe embed
  // protocol.
  //
  // The iframe (`/embed/assistant`) is the SOLE session owner AND the sole holder
  // of the person's credential. This shell delivers ONE selector-only CONTEXT
  // message per frame document and services the closed set of iframe→parent
  // uplinks. Every trust-boundary control is enforced here: origin + source-window
  // binding, schema/protocolVersion/nonce agreement, dual monotonic seq behind a
  // closed type set, one context per document with an epoch reset on replacement,
  // the credential-shaped value refusal on every send and both inbound transports,
  // apply_intent untrusted-selector permission checks + bounded LRU dedup, and the
  // resize clamp.
  //
  // TRANSPORT (§12b): the iframe transfers one MessageChannel endpoint in READY;
  // the parent RETAINS it and sends the CONTEXT message over that port. Steady-
  // state uplinks then ride the same entangled port. The WINDOW transport remains
  // for a frame that transfers no port (unless the deployment sets `requirePort`)
  // and is origin-pinned when used. At protocol 2 the port is DEFENCE IN DEPTH,
  // not the credential wall — the credential wall is that no credential exists
  // here at all.
  // ---------------------------------------------------------------------------
  var iframeEl = null;          // the mounted embed iframe (null until first open)
  var frameWindow = null;       // iframeEl.contentWindow captured at load
  var frameNonce = null;        // the READY nonce the frame minted (echoed in context)
  var correlationId = null;     // parent-minted CSPRNG id, echoed by every uplink
  var contextSent = false;      // one CONTEXT per frame DOCUMENT (a reload starts an epoch)
  var inboundSeqLast = null;    // iframe->parent monotonic gate (READY seeds it)
  var outboundSeqLast = null;   // parent->iframe monotonic counter (context = 0)
  var appliedLru = [];          // §6f bounded seen apply-id LRU for this correlationId
  var activePort = null;        // §12b the MessagePort the iframe transferred in READY (null in window mode)
  var activeTransport = null;   // 'port' | 'window' — chosen at READY
  var frameRefused = false;     // one-shot: the frame cannot be framed (see mountBridgeIframe)
  var bridgeEpochs = 0;         // retired epochs on this frame (bounded — see MAX_BRIDGE_EPOCHS)
  var MAX_BRIDGE_EPOCHS = 64;   // a real reload needs a handful; this is the ceiling on manufactured ones

  // §12b CHANNEL BINDING: when true, a READY that transfers NO port is REFUSED, so
  // the window transport cannot be selected merely by stripping the transferred
  // port. Defaults FALSE (a frame that transfers no port still works). At protocol
  // 2 this is a channel-binding knob, not a credential control — the message
  // carries no credential either way, and the worst a misdelivered CONTEXT can do
  // is tell a replacement document which resource is on screen. A deployment
  // hardens by setting `requirePort` in its CMS settings broker.
  var requirePort = (config.requirePort === true);

  // A CSPRNG base64url correlationId carrying >=128 bits (24 base64url chars ==
  // 144 bits), satisfying ID_PATTERN (§6b).
  function mintCorrelationId() {
    return randB64url(18);
  }

  // ---------------------------------------------------------------------------
  // THREAD CONTINUITY (cinatra#2683, epic #2564 S8f).
  //
  // The CONTEXT message used to say `threadId: correlationId` — "one thread per
  // framed session". That made the widget's conversation END at every page load:
  // a reload mints a new correlationId, so the frame asked to resume a thread that
  // had never existed, the instance answered 404, and the person's history was
  // gone. The instance's restore path was correct the whole time; the widget could
  // never exercise it, because it never asked twice for the same thread.
  //
  // TWO IDS THAT WERE ONE, AND SHOULD NOT HAVE BEEN. The correlationId is a
  // SECURITY binding — a per-document CSPRNG nonce that every uplink must echo —
  // and it stays exactly that, minted fresh for every document and every epoch.
  // The thread id is PUBLIC CONTEXT: a selector naming which conversation to
  // resume, which the instance authorizes against the frame's own signed-in reader
  // before it serves a single message. Only the second one is remembered here.
  //
  // KEYED BY (SITE, USER), AND IT REFUSES TO GUESS THE USER. `localStorage` is
  // already scoped to the CMS site's origin; the key adds the instance and the
  // assistant, and the CMS USER — because one browser profile can be two people on
  // a shared workstation, and resuming the first person's thread as the second is
  // a broken widget (the instance refuses every turn on a thread the reader does
  // not own). When the host cannot say who is looking, NOTHING is stored and the
  // per-bootstrap behaviour above is what happens — the safe direction, and a
  // silent one only because it is the behaviour that shipped.
  //
  // NOTHING NEW GOES ON THE WIRE. The CONTEXT message carries the same one
  // `session.threadId` field it always did; this only changes WHICH id that is.
  // The stored value is a thread id and nothing else — never a credential, which
  // this shell does not have — and it is bound-checked against the same
  // ID_PATTERN the frame's schema applies, so a corrupt or hand-edited entry mints
  // a fresh thread instead of taking the session down.
  // ---------------------------------------------------------------------------
  var THREAD_STORE_PREFIX = 'cinatra.widget.thread.v1';
  // A remembered conversation lives a WEEK FROM WHEN IT STARTED, and the clock is
  // NOT restarted by use. Refreshing on every read would let one entry live
  // forever, and this bound is doing real work: the instance refuses a thread the
  // reader does not own, so an entry that becomes unusable — the same person at
  // this CMS signing into Cinatra as somebody else — is refused until it expires.
  // Ending that within a week is the difference between a bad day and a widget
  // that is permanently broken on that machine.
  //
  // THE HONEST LIMIT. Recovering FASTER than the expiry needs the frame to tell
  // the parent "that thread is not mine", and there is no such uplink in protocol
  // 2. Adding one is a protocol change, so it is not smuggled in here; it is
  // written down as the remedy if that case is ever seen.
  var THREAD_STORE_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

  /**
   * WHO is looking, as the CMS already tells this page — `userSettings.uid` in
   * wp-admin, `drupalSettings.user.uid` in Drupal. Best effort by design: this is
   * a STORAGE KEY component, never an authority and never sent anywhere, and a
   * host that publishes neither gets no persistence at all rather than a shared
   * bucket. Returns '' when there is no answer.
   */
  function cmsUserKey() {
    var uid;
    try {
      if (CMS === 'wordpress') {
        var us = window.userSettings;
        uid = us && us.uid;
      } else {
        var ds = window.drupalSettings;
        uid = ds && ds.user && ds.user.uid;
      }
    } catch (_) {
      return '';
    }
    if (uid === undefined || uid === null) return '';
    var key = String(uid).trim();
    // ZERO IS NOT A PERSON. Both CMSes spell "anonymous" as user 0, and treating
    // it as an identity would make one shared bucket for every signed-out visitor
    // at that browser — the exact thing keying by user exists to prevent.
    if (!key || key === '0') return '';
    return key;
  }

  /** The (site, user) key, or null when there is no user to key on. */
  function threadStoreKey() {
    var user = cmsUserKey();
    if (!user) return null;
    var instanceId = asSelector(config.instanceId);
    if (!instanceId) return null;
    return THREAD_STORE_PREFIX + '|' + cinatraOrigin + '|' + instanceId +
      '|' + EMBED_ASSISTANT + '|' + user;
  }

  /** `localStorage`, or null. Access ITSELF can throw (disabled storage, a
   *  private window), so this is the only place that touches it. */
  function threadStore() {
    try {
      var s = window.localStorage;
      return (s && typeof s.getItem === 'function' && typeof s.setItem === 'function') ? s : null;
    } catch (_) {
      return null;
    }
  }

  function readStoredThreadId(key) {
    var store = threadStore();
    if (!store) return null;
    var raw;
    try { raw = store.getItem(key); } catch (_) { return null; }
    if (typeof raw !== 'string' || !raw) return null;
    var entry;
    try { entry = JSON.parse(raw); } catch (_) { return null; }
    if (!entry || typeof entry !== 'object') return null;
    if (typeof entry.id !== 'string' || !ID_PATTERN.test(entry.id)) return null;
    if (typeof entry.at !== 'number' || !isFinite(entry.at)) return null;
    var age = Date.now() - entry.at;
    // A FUTURE-DATED entry (a hand-edit, or a clock that moved) is refused rather
    // than trusted — otherwise it would outlive every bound this function has.
    if (age < 0 || age > THREAD_STORE_MAX_AGE_MS) return null;
    return { id: entry.id, at: entry.at };
  }

  function writeStoredThreadId(key, id) {
    // THE SAME CHOKE POINT THE BRIDGE USES, ON THE WAY INTO STORAGE. Storage is
    // the one place a credential could come to REST rather than merely pass
    // through, which is why the gate forbade it outright before this slice. The
    // ban is now a channel: exactly one value may be written, it must be a
    // thread id by shape, and it is run through the SAME credential guard every
    // outbound message is. A value that is neither is not written at all.
    if (typeof id !== 'string' || !ID_PATTERN.test(id)) return;
    if (containsCredentialShapedValue(id)) return;
    var store = threadStore();
    if (!store) return;
    // A full or refusing quota must never take the widget down: the conversation
    // still works, it just stops being remembered.
    // `at` is WHEN THE CONVERSATION STARTED. There is exactly one write — the
    // mint — so use never restarts the clock; see THREAD_STORE_MAX_AGE_MS for why
    // that matters more than "the thread I use daily never expires".
    try { store.setItem(key, JSON.stringify({ id: id, at: Date.now() })); } catch (_) {}
  }

  /**
   * The thread this document should continue, minting one the first time.
   *
   * Every path returns an ID_PATTERN-valid id, so the CONTEXT message is
   * composable whatever storage does — including the no-storage and no-user
   * paths, which return a freshly minted id exactly as before this change.
   */
  function resolveThreadId() {
    var key = threadStoreKey();
    if (!key) return mintCorrelationId();
    var stored = readStoredThreadId(key);
    if (stored) return stored.id;
    var id = mintCorrelationId();
    writeStoredThreadId(key, id);
    return id;
  }

  // §6c per-direction monotonic gate: a seq must be a nonnegative integer and, on
  // any direction after the first accepted value, strictly increase.
  function acceptInboundSeq(seq) {
    if (typeof seq !== 'number' || !isFinite(seq) || Math.floor(seq) !== seq || seq < 0) return false;
    if (inboundSeqLast !== null && seq <= inboundSeqLast) return false;
    inboundSeqLast = seq;
    return true;
  }
  function nextOutboundSeq() {
    var next = (outboundSeqLast === null ? -1 : outboundSeqLast) + 1;
    outboundSeqLast = next;
    return next;
  }

  // WINDOW transport (§12b): ALWAYS an explicit origin, NEVER "*" (§6a outbound).
  // Posts to the frame window. Used only when the iframe transferred no port and
  // `requirePort` is off. cinatraOrigin is resolved once and is a real origin
  // (never "*"/empty — the mount aborts otherwise), so the "never '*'" invariant
  // holds structurally.
  function postToFrame(message) {
    if (!frameWindow) return false;
    frameWindow.postMessage(message, cinatraOrigin);
    return true;
  }

  // Send a parent->iframe message over the transport chosen at READY.
  //
  // THE OUTBOUND GUARD RUNS HERE, ON EVERY PATH (cinatra#2674). Whatever the
  // caller composed, a payload carrying a credential-shaped value AT ANY DEPTH is
  // REFUSED rather than transmitted: the parity gate proves the guard exists, and
  // this function is the single choke point that proves it always runs. A message
  // that never leaves is strictly better than one the frame rejects on arrival.
  // Returns whether the message was actually sent, so the caller can fail closed
  // instead of assuming delivery.
  function sendToFrame(message) {
    // A composer that REFUSED to build a message returns null (see
    // buildEmbedContext). Posting that null would be an empty envelope on the
    // wire; the refusal must be a non-send.
    if (!message || typeof message !== 'object') { return false; }
    if (containsCredentialShapedValue(message)) {
      // Deliberately says nothing about WHAT matched: a refusal must not become a
      // place where a credential is echoed into a console.
      console.warn('[cinatra] refusing to post a credential-shaped value to the assistant frame');
      return false;
    }
    if (activeTransport === 'port' && activePort) {
      activePort.postMessage(message);
      return true;
    }
    return postToFrame(message);
  }

  // §3a READY validator (pre-context; the ONLY message without a correlationId).
  // The protocolVersion literal is pinned: a protocol-1 frame cannot negotiate.
  function isValidReady(d) {
    return !!d && d.type === MSG.ready &&
      d.protocolVersion === EMBED_PROTOCOL_VERSION &&
      typeof d.nonce === 'string' && ID_PATTERN.test(d.nonce) &&
      // A non-negative integer, proven HERE rather than left to the seq gate:
      // the epoch reset below runs after this check and clears the gate, so this
      // is the last place a malformed seq can be caught before it matters.
      typeof d.seq === 'number' && isFinite(d.seq) &&
      Math.floor(d.seq) === d.seq && d.seq >= 0;
  }

  // The CLOSED uplink type set. Checked BEFORE the seq gate commits, so a message
  // the parent will drop anyway cannot first consume a sequence number. The gate
  // is a scarce, one-way resource: nothing that is not going to be dispatched may
  // spend from it.
  function isKnownUplinkType(type) {
    return type === MSG.resize || type === MSG.focus ||
      type === MSG.a11y || type === MSG.applyIntent;
  }

  // §5 uplink common envelope validator (post-context): the closed type set,
  // protocolVersion, the echoed correlationId, and — last, because accepting it
  // MUTATES the gate — a monotonic seq for the iframe->parent direction.
  function validUplinkEnvelope(d) {
    if (!d || d.protocolVersion !== EMBED_PROTOCOL_VERSION) return false;
    if (!isKnownUplinkType(d.type)) return false;
    if (typeof d.correlationId !== 'string' || d.correlationId !== correlationId) return false;
    if (!acceptInboundSeq(d.seq)) return false;
    return true;
  }

  // §4: build the ONE inbound CONTEXT message — mint the correlationId, echo the
  // frame nonce, seq=0, and carry PUBLIC SELECTORS ONLY. There is no `auth` block
  // and there is nothing to await: this shell holds no credential, so the message
  // is composed and released synchronously in the same task as the READY that
  // triggered it.
  //
  // EVERY FIELD IS A SELECTOR, NOT AN ASSERTION. `site.siteId`, `cms.instanceId`
  // and `session.assistant` name things the INSTANCE already knows about; it
  // re-derives the authoritative site, org, origin, agent and canonical instance
  // from its own rows and denies on any mismatch. `site.siteId` is the connect-site
  // handle issued at Connect — a public id whose paired `cnx_` credential stays on
  // the CMS server and never enters the browser. It is OMITTED when this site has
  // none (a disambiguator that is not needed must not be invented, and an empty
  // string would fail the frame's strict schema and take the session down over a
  // field the frame does not need).
  //
  // The page URL is deliberately NOT sent: the protocol allows an optional
  // `cms.href` display selector, and the frame does not need one to name the
  // resource.
  //
  // THE NONCE IS RECORDED BEFORE THE COMPOSE CAN REFUSE, and that order is
  // deliberate; the alternative is considered and rejected below.
  // Mutating only after a successful compose would leave `frameNonce` holding
  // whatever it held before — null on the first document and null again after an
  // epoch reset — while the latch is already set, so the refused document's own
  // retry (a replay of the very nonce just refused) would no longer look like a
  // replay, would fall through to the epoch branch, and would spend an epoch every
  // time. Recording it first makes a replay free and leaves
  // only a genuinely new nonce costing an epoch. On refusal the state is: latched,
  // nonce recorded, a fresh correlationId minted that NEVER LEFT THE PARENT (so no
  // uplink can satisfy the correlation binding), no outbound seq consumed, and the
  // frame left in its own neutral pre-context state.
  function buildEmbedContext(nonce) {
    frameNonce = nonce;
    correlationId = mintCorrelationId();
    var ctx = buildContentContext();
    // The cms block is built ONLY from the normalized context — never re-read
    // from raw config, which would reintroduce the untyped value the
    // normalization just removed.
    //
    // THE REQUIRED SELECTOR IS RE-BOUNDED HERE, not merely at mount. The mount
    // bound-checked the value it FRAMED; this reads the CMS
    // config again, one task later, and a page script that changed it in between
    // would put an empty or over-long id into a `.strict()` envelope — which the
    // frame rejects WHOLE, stranding the session in "waiting for host" with
    // nothing to say why. That is precisely the failure the bound exists to
    // prevent, so an unbounded required id means NO MESSAGE rather than a message
    // that cannot be accepted.
    var instanceId = boundedSelector(ctx.instanceId, SELECTOR_MAX.instanceId);
    if (!instanceId) { return null; }
    // Resolved AFTER the refusal above, so a message that will not be sent does
    // not start (or touch) a remembered conversation.
    var threadId = resolveThreadId();
    var cms = { instanceId: instanceId };
    var resourceId = boundedSelector(ctx.resourceId, SELECTOR_MAX.resourceId);
    var resourceType = boundedSelector(ctx.resourceType, SELECTOR_MAX.resourceType);
    var status = boundedSelector(ctx.status, SELECTOR_MAX.status);
    if (resourceId) { cms.resourceId = resourceId; }
    if (resourceType) { cms.resourceType = resourceType; }
    if (status) { cms.status = status; }
    var message = {
      type: MSG.context,
      protocolVersion: EMBED_PROTOCOL_VERSION,
      correlationId: correlationId,
      nonceEcho: frameNonce,
      seq: nextOutboundSeq(),              // parent->iframe counter starts at 0
      session: {
        // The conversation this document CONTINUES (cinatra#2683) — remembered
        // per (site, user), not minted per bootstrap. A public selector: the
        // instance authorizes it against the frame's own signed-in reader.
        threadId: threadId,
        assistant: EMBED_ASSISTANT,        // == ?assistant (a selector, not authority)
      },
      cms: cms,
    };
    var siteId = boundedSelector(ctx.siteId, SELECTOR_MAX.siteId);
    if (siteId) { message.site = { siteId: siteId }; }
    return message;
  }

  // §5/§B9 resize: CLAMP the reported content height to the panel cap (clamp,
  // never reject a merely-tall height; NaN/negative/over-max are schema-dropped).
  function handleResize(height) {
    if (typeof height !== 'number' || !isFinite(height) ||
        Math.floor(height) !== height || height < 0 || height > RESIZE_MAX_HEIGHT) {
      return; // schema-reject
    }
    if (userResizedPanel) return; // a manual drag pins the height
    var HEADER_H = 46;
    var clamped = Math.max(MIN_PANEL_HEIGHT, Math.min(height + HEADER_H, maxPanelHeight()));
    currentPanelHeight = clamped;
    setWidgetSize();
  }

  // §5 focus: advisory. Bring the panel forward / keep it open on a focus request.
  function handleFocus(focus) {
    if (typeof focus !== 'boolean') return;
    if (focus && !isOpen) { openWidget(); }
  }

  // §5 a11y: mirror the assistant status into the parent aria-live region as
  // textContent — NEVER HTML (no markup injection from frame content).
  function handleA11y(liveRegion, politeness) {
    if (typeof liveRegion !== 'string' || liveRegion.length > 2000) return;
    if (politeness !== 'polite' && politeness !== 'assertive') return;
    a11yLive.setAttribute('aria-live', politeness);
    a11yLive.textContent = liveRegion;
  }

  // ---------------------------------------------------------------------------
  // CMS SEAM 4 of 4 — the EDIT-PERMISSION ORACLE and the IN-PLACE REFRESH SINK.
  //
  // Best-effort re-check that the current user may edit the canonical resource
  // (§6f step 1), then a refresh of the CMS's OWN view of it. The RULE is shared
  // and CMS-independent: deny ONLY on an explicit `false`, and defer to the
  // SERVER-side authorization on anything unresolved — the field WRITE was already
  // performed AND permission-checked server-side by the CMS MCP integration
  // (#1214), and this client gate only guards a refresh of the person's OWN
  // already-open resource. Treating "unknown" as a deny would make the
  // (non-mutating) refresh never fire on first use.
  //
  // Only the ORACLE differs. WordPress has a synchronous-ish client capability
  // oracle in core-data `canUser`; Drupal exposes no equivalent, so an explicit
  // per-node deny may be advertised through the settings broker instead.
  // ---------------------------------------------------------------------------
  function currentUserMayEdit(ctx) {
    try {
      if (CMS === 'wordpress') {
        if (window.wp && window.wp.data && window.wp.data.select && ctx.resourceType && ctx.resourceId) {
          var coreSel = window.wp.data.select('core');
          if (coreSel && typeof coreSel.canUser === 'function') {
            // WordPress core-data `canUser` object-entity form (kind/name/id) — the
            // 4-positional `('update','postType',type,id)` form is NOT a valid
            // signature (it would silently return undefined, making the deny branch
            // dead). `canUser` is a TRI-STATE that resolves ASYNCHRONOUSLY: the
            // first synchronous read is `undefined` while it resolves. So deny ONLY
            // on an explicit `false`; on `true` OR still-resolving `undefined`,
            // defer to the SERVER-side write authorization.
            var can = coreSel.canUser('update', {
              kind: 'postType',
              name: ctx.resourceType,
              id: ctx.resourceId,
            });
            if (can === false) return false;
          }
        }
        return true;
      }
      if (config.currentUserMayEditNode === false) { return false; }
    } catch (_) {}
    return true;
  }

  // In-place draft refresh — NO widget-constructed content-API egress and NO page
  // reload (#1214: the field-apply already happened server-side through the CMS
  // MCP integration; this only refreshes the editor's view of the canonical
  // resource so the applied draft shows).
  //
  // WordPress has a client entity store, so the refresh is an invalidation in the
  // CMS's OWN data layer. Drupal node edit forms are server-rendered and have no
  // such store, so the refresh is a same-document CustomEvent the module's
  // (optional) edit-form integration listens for; if nothing is listening it is a
  // harmless no-op. The event carries ONLY the non-secret resource disambiguators
  // — never a token, never content — and never triggers a reload or a request.
  function refreshCurrentDraft(ctx) {
    try {
      if (CMS === 'wordpress') {
        if (window.wp && window.wp.data && window.wp.data.dispatch && ctx.resourceType && ctx.resourceId) {
          var coreDispatch = window.wp.data.dispatch('core');
          if (coreDispatch && typeof coreDispatch.invalidateResolution === 'function') {
            coreDispatch.invalidateResolution('getEntityRecord', ['postType', ctx.resourceType, ctx.resourceId]);
            return true;
          }
        }
        return false;
      }
      if (typeof CustomEvent === 'function' &&
          document && typeof document.dispatchEvent === 'function') {
        document.dispatchEvent(new CustomEvent('cinatra:content-applied', {
          detail: {
            instanceId:   ctx.instanceId || '',
            resourceId:   ctx.resourceId || '',
            resourceType: ctx.resourceType || '',
          },
        }));
        return true;
      }
    } catch (_) {}
    return false;
  }

  // §5/§6e/§6f apply_intent: the payload carries an UNTRUSTED SELECTOR only (one of
  // proposalId/changeSetId) + a fixed viewType. NO content, NO tool call. The
  // parent (1) re-checks edit permission, (2) uses its OWN canonical resource,
  // (3) the correlationId binding already proves the signal belongs to this
  // established thread/instance, (4) dedups against a bounded LRU, THEN does the
  // in-place draft refresh. The selector id is used ONLY as the LRU key — never as
  // a fetch selector, never egressed (#1214).
  function handleApplyIntent(d) {
    if (APPLY_INTENT_VIEW_TYPES.indexOf(d.viewType) === -1) return;
    // Exactly one selector must be PRESENT — matching the core presence-XOR schema
    // (`(proposalId != null) !== (changeSetId != null)`). A message carrying BOTH
    // keys (even if one is an empty string) or NEITHER is rejected; presence, not
    // value validity, decides the XOR so an empty-but-present field can't slip a
    // both-present message through.
    var proposalPresent = d.proposalId !== undefined && d.proposalId !== null;
    var changeSetPresent = d.changeSetId !== undefined && d.changeSetId !== null;
    if (proposalPresent === changeSetPresent) return;
    var selectorId = proposalPresent ? d.proposalId : d.changeSetId;
    // The present selector must still be a sane bounded non-empty string.
    if (typeof selectorId !== 'string' || selectorId.length === 0 || selectorId.length > 200) return;

    var ctx = buildContentContext();               // the parent's OWN canonical resource
    if (!currentUserMayEdit(ctx)) return;          // fail closed on explicit deny

    // Bounded LRU dedup per correlationId (a re-emitted apply must be idempotent).
    var lruKey = (proposalPresent ? 'p:' : 'c:') + selectorId;
    if (appliedLru.indexOf(lruKey) !== -1) return;
    appliedLru.push(lruKey);
    if (appliedLru.length > APPLY_LRU_MAX) { appliedLru.shift(); }

    refreshCurrentDraft(ctx);
    a11yLive.setAttribute('aria-live', 'polite');
    a11yLive.textContent = 'The assistant applied changes to this content.';
  }

  // Post-context uplink dispatch (§5): require an established correlationId + a
  // monotonic seq for the iframe->parent direction, then route the closed set.
  // Shared by BOTH transports so a port-delivered and a window-delivered uplink
  // pass the identical envelope gate and dispatch.
  function dispatchUplink(d) {
    if (!contextSent) return;
    if (!validUplinkEnvelope(d)) return;
    if (d.type === MSG.resize) { handleResize(d.height); return; }
    if (d.type === MSG.focus) { handleFocus(d.focus); return; }
    if (d.type === MSG.a11y) { handleA11y(d.liveRegion, d.politeness); return; }
    if (d.type === MSG.applyIntent) { handleApplyIntent(d); return; }
    // Unknown type: dropped (the set is closed).
  }

  // THE GUARD RUNS INBOUND TOO (cinatra#2674).
  //
  // "No credential crosses this boundary" is a claim about BOTH directions, and
  // the inbound half is not hypothetical: the FRAME is the one party that holds a
  // `cwu_`/`cit_`, and an uplink is a place a bug could put one — an
  // `a11y.liveRegion` string, say, which this parent writes straight into the CMS
  // page's live region. That would leave a person's bearer sitting in the CMS DOM,
  // which is the exact possession this slice exists to remove.
  //
  // So every inbound envelope is scanned before any field of it is read, on both
  // transports, and BEFORE the seq gate can be spent on it. A match is DROPPED
  // SILENTLY. Silently on purpose: a warning that named or echoed the offending
  // message would copy the credential into a console and from there into a support
  // paste, turning a containment control into a disclosure.
  function inboundIsClean(d) {
    return !containsCredentialShapedValue(d);
  }

  // §12b PORT path — steady-state uplinks over the entangled port. NO origin/
  // source recheck: the port was transferred to us on the origin+source-gated
  // READY and is document-bound (a same-origin replacement frame is a fresh realm
  // that never inherits the entangled endpoint), so its provenance IS the origin
  // guarantee — a NARROWING, not a loosening. READY never arrives on the port (it
  // is the window message that CARRIED this port), so only uplinks are handled.
  function onPortMessage(event) {
    if (!contextSent || activeTransport !== 'port') return;
    var d = event.data;
    // BEFORE the shape check, not after it. "Before any field is read" has to be
    // literal or it is not a boundary: reading `d.type` first is still a read of
    // the envelope, and the guard's whole job is to be the first thing that
    // touches it.
    if (!inboundIsClean(d)) return;
    if (!d || typeof d !== 'object' || typeof d.type !== 'string') return;
    dispatchUplink(d);
  }

  // The single inbound WINDOW bridge listener — origin + source-window bound.
  // Attached when the iframe mounts. It carries the READY (which transfers the
  // §12b port) and, in WINDOW mode only, the post-context uplinks.
  function onBridgeMessage(event) {
    // (§6a) strict origin, BEFORE schema.
    if (event.origin !== cinatraOrigin) return;
    // (§6a-2) source-window binding, BEFORE schema — a sibling frame on the same
    // origin must never drive this bridge. Nullish source never matches.
    if (!frameWindow || event.source !== frameWindow) return;

    var d = event.data;
    // Inbound credential guard, before ANY field is read — including READY's, so
    // a nonce carrying a bearer shape never reaches the echo that would put it
    // back on the wire. It runs BEFORE the shape check for the same reason: a
    // `typeof d.type` test is itself a read of the envelope, and "before any
    // field is read" has to be literal to be a boundary.
    if (!inboundIsClean(d)) return;
    if (!d || typeof d !== 'object' || typeof d.type !== 'string') return;

    if (d.type === MSG.ready) {
      // §4 READY → CONTEXT, released synchronously in this same message task —
      // not because a credential could be misdelivered any more (there is none),
      // but because a message with no await in front of it has no interleaving to
      // reason about at all.
      if (!isValidReady(d)) return;
      // A READY on an already-served frame that REPLAYS the nonce we answered is
      // ignored outright — that is the property the single-context latch was
      // really protecting, and the one an attacker could try.
      if (contextSent && d.nonce === frameNonce) return;
      // §12b select the transport from the port the frame transferred on this
      // (origin+source-gated) READY. A transferred port -> PORT MODE. NO port
      // under `requirePort` -> FAIL CLOSED doing NOTHING AT ALL (no state
      // mutation, no send), so the window transport cannot be selected by
      // stripping the transferred port. NO port without `requirePort` -> WINDOW
      // MODE: the origin-pinned window transport.
      //
      // The protocol transfers EXACTLY ONE endpoint. A READY carrying more is not
      // a frame speaking this protocol, so it is refused rather than silently
      // reduced to its first port.
      var ports = event.ports;
      if (ports && ports.length > 1) return;
      var transferredPort = (ports && ports.length === 1) ? ports[0] : null;
      if (!transferredPort && requirePort) return;
      // THE EPOCH COUNT IS BOUNDED. The epoch reset below
      // treats a NEW nonce as a replacement document, and the parent cannot
      // verify that claim: an iframe that simply keeps minting fresh nonces
      // without ever navigating would be handed a fresh context every time,
      // turning the reload-recovery path into unbounded parent work driven by the
      // frame. The recovery a real reload needs is a handful of epochs over a page
      // lifetime, so a generous ceiling closes the manufactured-reload case at a
      // cost a genuine frame is very unlikely to meet. STATED PRECISELY, because
      // "costs a genuine frame nothing" would be too strong: the ceiling counts
      // REAL replacement documents too, so a frame that legitimately reloaded 64
      // times on one long-lived CMS page would find the 65th ignored. That is a
      // bounded availability trade, and the remedy is the page reload that was the
      // only remedy before the epoch reset existed at all. A same-nonce replay
      // costs nothing — it is refused before it reaches here.
      if (contextSent && bridgeEpochs >= MAX_BRIDGE_EPOCHS) return;
      // EVERY CHECK ON THIS READY HAS NOW PASSED, so it is safe to retire the
      // previous epoch. The order matters and is the whole point: a malformed
      // READY (bad version, bad nonce, bad seq, two ports, no port under
      // requirePort) must NOT be able to tear down an established session on its
      // way to being refused — that would turn a rejected message into a denial
      // of service. `isValidReady` has already proven `seq` is a non-negative
      // integer, and the reset clears the gate, so the acceptance below cannot
      // fail after it.
      if (contextSent) { resetBridgeEpoch(); }
      // Seed the iframe->parent monotonic gate with READY's seq (§6c); post-
      // context uplinks must strictly increase from it (whichever transport they
      // then ride).
      if (!acceptInboundSeq(d.seq)) return;
      // Bind the chosen transport. In PORT mode the context rides ONLY the
      // retained port; in WINDOW mode event.source === frameWindow was verified
      // above, so it posts to the exact document that sent READY.
      if (transferredPort) {
        activePort = transferredPort;
        activeTransport = 'port';
        activePort.addEventListener('message', onPortMessage);
        activePort.start();
      } else {
        activeTransport = 'window';
      }
      // Set the one-context latch BEFORE posting so a re-entrant delivery cannot
      // double-send.
      //
      // A REFUSED SEND IS NOT RETRIED, and that is the point. `sendToFrame`
      // refuses a payload carrying a credential-shaped value; retrying would
      // recompose the same selectors from the same page and refuse again, forever.
      // The latch therefore STAYS SET on a refusal: exactly one attempt per frame
      // DOCUMENT, no storm. The frame keeps drawing its own neutral pre-context
      // state, and the person is never shown a CMS-side error about it. A frame
      // that RELOADS is a different document and gets its own single attempt
      // through the epoch reset above — a new document is not a retry.
      contextSent = true;
      if (!sendToFrame(buildEmbedContext(d.nonce))) {
        console.warn('[cinatra] the assistant frame was not given its context; reload the page to try again');
      }
      return;
    }

    // Post-context uplinks. In PORT mode they ride the entangled port
    // (onPortMessage); a window-delivered uplink is IGNORED so the transport
    // cannot be split/downgraded afterwards — and it is dropped here WITHOUT
    // touching the seq gate.
    if (!contextSent || activeTransport !== 'window') return;
    dispatchUplink(d);
  }

  // TEST-ONLY render-parity seam carrier (cinatra#1998 (c), epic #1216 S6). The
  // Cinatra render-parity E2E frames THIS widget's `/embed/assistant` iframe and
  // needs the deterministic corpus-render seam params (`parityThread` /
  // `parityTheme`, cinatra#1998 (b)) to ride the iframe src the CMS builds — the
  // harness cannot reach into the fixed src otherwise. This reads a NAMESPACED
  // signal the test stages on the host admin page — a `window.__cinatraParitySeam`
  // global (STORAGE-FREE: the widget persists nothing — the iframe owns storage —
  // so this trips no web-storage invariant), with a same-named query param as a
  // fallback. PRODUCTION never stages it, so nothing is appended and the src is
  // BYTE-IDENTICAL to before. It is doubly inert in prod: even a forged signal is
  // a no-op because the Cinatra server IGNORES `parityThread` unless its
  // server-only `EMBED_PARITY_SEAM` gate is on (off in prod) — the seam is
  // server-gated, so this can NEVER inject content or bypass auth in production.
  // Carries NO credential (only the two non-secret render-parity disambiguators),
  // exactly like instanceId/assistant.
  //
  // Returns the RAW values, not an encoded suffix: percent-encoding a value
  // destroys the token boundary the credential guard looks for (`"x cnx_…"`
  // becomes `"x%20cnx_…"`, whose preceding character is a digit), so every
  // component must be scanned BEFORE it is encoded. The caller composes and
  // encodes; this function only reads.
  function embedParitySeamValues() {
    var seam = { thread: '', theme: '' };
    try {
      // 1) A namespaced global the harness stages (survives host-page URL
      //    rewrites; storage-free so the non-disclosure invariant holds).
      var g = window.__cinatraParitySeam;
      if (g && typeof g === 'object') {
        seam.thread = typeof g.thread === 'string' ? g.thread : '';
        seam.theme = typeof g.theme === 'string' ? g.theme : '';
      }
      // 2) Fallback: a namespaced query param on the host admin URL.
      if (!seam.thread && window.location && window.location.search && window.URLSearchParams) {
        var q = new URLSearchParams(window.location.search);
        seam.thread = q.get('cinatra_parity_thread') || '';
        seam.theme = q.get('cinatra_parity_theme') || '';
      }
      if (seam.theme !== 'github-dark' && seam.theme !== 'github-light') {
        seam.theme = '';
      }
    } catch (_) {
      return { thread: '', theme: '' };
    }
    return seam;
  }

  // Build the sandboxed embed iframe and attach the bridge listener. The src is
  // the Cinatra-served `/embed/assistant` route carrying only the NON-SECRET
  // disambiguators (instanceId, assistant). There is no credential to keep out of
  // this URL any more — this shell holds none — and none is put there.
  //
  // THE SANDBOX GREW BY EXACTLY TWO TOKENS AT PROTOCOL 2, AND IT HAD TO
  // (cinatra#2674). The sign-in is now the FRAME's: it calls `window.open()` on
  // the hosted sign-in URL. A sandboxed frame without `allow-popups` cannot open a
  // window at all — `window.open` returns null, the frame reports `popup_blocked`,
  // and NOBODY CAN EVER SIGN IN. And a popup that merely inherits this sandbox is
  // equally dead: it would carry no `allow-forms` and no top-level navigation, so
  // the hosted sign-in could neither take input nor complete its redirect. Hence
  // BOTH tokens:
  //   * allow-popups                     — the frame may open the window;
  //   * allow-popups-to-escape-sandbox   — that window is an ORDINARY top-level
  //     Cinatra document, which is the entire point: its session cookie is
  //     FIRST-PARTY there, so the ceremony works in browsers that block
  //     third-party cookies outright.
  //
  // WHAT THIS DOES NOT GRANT, and why the widening is narrow. The frame itself
  // still gets NO top-navigation, NO forms, NO modals, NO downloads and NO
  // pointer-lock. The escape applies to the window the frame opens, not to the
  // frame. And the marginal capability is small: this document is Cinatra's own,
  // served from the configured instance origin, and it ALREADY holds
  // `allow-same-origin` + `allow-scripts` — it can already run its own code
  // against its own origin. What it gains is the ability to open a top-level
  // window, which browsers render with a visible address bar, on a user gesture.
  // The parity gate requires these two as a SET EQUALITY, so both a silent removal
  // (which would break sign-in) and a silent widening are red.
  function mountBridgeIframe() {
    if (iframeEl || frameRefused) return;
    var ctx = buildContentContext();
    // `cms.instanceId` is REQUIRED, non-empty and <= 200 UTF-16 code units in the
    // protocol-2 schema, and it must agree with the `?instanceId` this shell puts
    // in the frame URL. Out of range, the frame would be pointed at nothing and
    // would reject the context on arrival, so refuse to frame it at all — once —
    // and say so in one actionable line rather than framing a surface that cannot
    // work. The launcher chrome stays; the site owner fixes the setting and
    // reloads.
    var instanceId = boundedSelector(ctx.instanceId, SELECTOR_MAX.instanceId);
    if (!instanceId) {
      frameRefused = true;
      console.warn('[cinatra] the agent instance id is missing or out of range; the assistant was not started');
      return;
    }
    // THE FRAME URL IS AN OUTBOUND PAYLOAD TOO. The bridge guard covers the
    // postMessage; it cannot cover this, because the src is composed from config
    // and from the render-parity seam and then LEAVES THE PAGE AS AN HTTP REQUEST
    // — where it also lands in history, in an access log and in a referrer.
    //
    // THE SCAN RUNS ON THE RAW COMPONENTS, BEFORE ENCODING. Scanning only the
    // finished URL is not enough: encodeURIComponent destroys the very token
    // boundary the guard matches on, so `"x cnx_…"` arrives as `"x%20cnx_…"` —
    // preceded by a digit — and slips past. The composed string is scanned as
    // well, but the raw components are the check that counts.
    var seam = embedParitySeamValues();
    var rawParts = [
      cinatraUrl,
      instanceId,
      EMBED_ASSISTANT,
      seam.thread,
      seam.theme,
    ];
    var src = cinatraUrl + '/embed/assistant' +
      '?instanceId=' + encodeURIComponent(instanceId) +
      '&assistant=' + encodeURIComponent(EMBED_ASSISTANT) +
      (seam.thread ? '&parityThread=' + encodeURIComponent(seam.thread) : '') +
      (seam.thread && seam.theme ? '&parityTheme=' + encodeURIComponent(seam.theme) : '');
    // A URL that fails either check means the frame is NOT MOUNTED at all, rather
    // than a request going out with a bearer in the query string. The fallback
    // chrome stays visible, which is the honest outcome: something is
    // misconfigured badly enough that the assistant must not start.
    if (containsCredentialShapedValue(rawParts) || containsCredentialShapedValue(src)) {
      frameRefused = true;
      console.warn('[cinatra] refusing to frame the assistant: the embed URL carries a credential-shaped value');
      return;
    }
    iframeEl = document.createElement('iframe');
    iframeEl.className = 'cw-frame';
    iframeEl.setAttribute('title', 'Cinatra assistant');
    iframeEl.setAttribute(
      'sandbox',
      'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox'
    );
    iframeEl.setAttribute('referrerpolicy', 'no-referrer');
    iframeEl.setAttribute('allow', '');
    // Keep the captured frame window current across loads (source-window binding +
    // outbound posts target exactly this frame).
    iframeEl.addEventListener('load', function () {
      frameWindow = iframeEl.contentWindow;
    });
    frameHost.appendChild(iframeEl);
    // contentWindow is available synchronously once appended; set it now so a READY
    // that races the load event is still source-bound.
    frameWindow = iframeEl.contentWindow;
    window.addEventListener('message', onBridgeMessage);
    iframeEl.setAttribute('src', src);
  }

  // NOTE: the frame teardown that used to live here is gone with the thing that
  // called it. Under protocol 1 the shell had to destroy the frame whenever the
  // per-user token expired or the person had to sign in again, because a frame
  // without a fresh credential from the parent was a dead frame. The frame's
  // session is now its own: it re-authenticates in place, and the parent has no
  // authentication event to react to. One mounted frame per page, for the life of
  // the page.
  //
  // …but the frame's DOCUMENT can still be replaced under that one element — the
  // frame reloads itself, and at protocol 2 a reload runs the ceremony again. The
  // replacement document announces itself with a FRESH READY carrying a FRESH
  // nonce, and under a plain single-context latch the parent would ignore it
  // forever: the widget would sit at "waiting for the host" until the whole page
  // was reloaded, and the old entangled port would leak.
  //
  // So a READY whose nonce differs from the one already served starts a NEW EPOCH:
  // the previous port is closed, every per-document binding is cleared, and the
  // new document gets its own correlationId and context message. A REPLAY of the
  // same nonce is still ignored — that is the property the latch was really
  // protecting, and it is the one an attacker could try. Re-serving a context
  // message is safe in a way re-serving a bootstrap never was: it carries no
  // credential, and the frame burns its own single-use nonce gate, so a second
  // context on the SAME document is refused at the far end anyway.
  function resetBridgeEpoch() {
    bridgeEpochs++;
    if (activePort) {
      try { activePort.removeEventListener('message', onPortMessage); } catch (_) {}
      try { activePort.close(); } catch (_) {}
    }
    activePort = null;
    activeTransport = null;
    frameNonce = null;
    correlationId = null;
    contextSent = false;
    inboundSeqLast = null;
    outboundSeqLast = null;
    appliedLru = [];
  }

  // ---------------------------------------------------------------------------
  // Open / collapse — circle↔widget swap
  //
  // The frame is mounted LAZILY, on the first open. That keeps a third-party
  // iframe off every admin page load while still tying the mount to a plain user
  // gesture; from then on the frame persists across open/collapse so the
  // conversation (and the person's frame-held session) survives closing the panel.
  // ---------------------------------------------------------------------------
  function openWidget() {
    isOpen = true;
    circle.style.zIndex = '9999990';
    setWidgetSize();
    cwWidget.style.display = 'block';
    mountBridgeIframe();
  }

  function collapseWidget() {
    isOpen = false;
    cwWidget.style.display = 'none';
    circle.style.zIndex = '';
  }

  // ---------------------------------------------------------------------------
  // Event wiring
  // ---------------------------------------------------------------------------
  circle.addEventListener('click', function(e) {
    if (circleDragMoved) { circleDragMoved = false; e.stopPropagation(); return; }
    if (isOpen) { collapseWidget(); } else { openWidget(); }
  });
  closeBtn.addEventListener('click', function() { collapseWidget(); });

  // NOTE (cinatra#2674): there is deliberately NO second window 'message'
  // listener here. Protocol 1 kept one for the hosted sign-in popup, because the
  // popup posted the authorization code back to THIS page and this page redeemed
  // it. The frame now opens that popup itself and the return step posts to
  // `window.location.origin` — the frame's own origin — so a CMS page listening
  // for it receives nothing, whatever it claims. The only inbound listener this
  // shell installs is `onBridgeMessage`, bound to the frame.

  document.addEventListener('click', function(e) {
    if (!isOpen) return;
    var path = e.composedPath ? e.composedPath() : [];
    for (var p = 0; p < path.length; p++) { if (path[p] === rootEl) return; }
    collapseWidget();
  });

  // ---------------------------------------------------------------------------
  // Resize: drag top-left corner to adjust width (left) and panel height (up). A
  // manual drag pins the height (disables the iframe-driven auto-grow).
  // ---------------------------------------------------------------------------
  var resizeDragging = false;
  var resizeStartX = 0, resizeStartY = 0;
  var resizeStartWidth = 0, resizeStartPanelH = 0;

  resizeEl.addEventListener('mousedown', function(e) {
    e.preventDefault();
    e.stopPropagation();
    resizeDragging = true;
    resizeStartX = e.clientX;
    resizeStartY = e.clientY;
    resizeStartWidth = currentWidth;
    resizeStartPanelH = currentPanelHeight;
  });

  document.addEventListener('mousemove', function(e) {
    if (circleDragging) {
      var dx = e.clientX - circleDragStartX;
      var dy = e.clientY - circleDragStartY;
      if (!circleDragMoved && (Math.abs(dx) >= CIRCLE_DRAG_THRESHOLD || Math.abs(dy) >= CIRCLE_DRAG_THRESHOLD)) {
        circleDragMoved = true;
        circle.style.cursor = 'grabbing';
      }
      if (circleDragMoved) {
        var newLeft = circleDragStartLeft + dx;
        var newTop = circleDragStartTop + dy;
        var clamped = clampCirclePos(newLeft, newTop);
        applyCirclePos(clamped.left, clamped.top);
      }
    }
    if (!resizeDragging) return;
    var dw = resizeStartX - e.clientX; // drag left = wider
    var dh = resizeStartY - e.clientY; // drag up = taller
    userResizedPanel = true;
    currentWidth = Math.max(320, Math.min(window.innerWidth - 48, resizeStartWidth + dw));
    currentPanelHeight = Math.max(MIN_PANEL_HEIGHT, Math.min(maxPanelHeight(), resizeStartPanelH + dh));
    setWidgetSize();
  });

  document.addEventListener('mouseup', function() {
    if (circleDragging) {
      circleDragging = false;
      circle.style.cursor = '';
    }
    resizeDragging = false;
  });

  // Synchronous mount construction is complete: mark mounted (this hides the
  // fallback chrome). Set LAST so any throw above leaves the fallback visible.
  rootEl.dataset.cinatraMounted = 'true';

  } // end mountWidget()

  // ---------------------------------------------------------------------------
  // Boot: mount UNCONDITIONALLY. Both handshakes this shell used to run are gone —
  // the AG-UI capability negotiation moved into the /embed/assistant iframe, and
  // so did the sign-in (cinatra#2674) — so there is nothing left to gate the mount
  // on. The always-visible fallback button remains until data-cinatra-mounted is
  // set at the end of synchronous mount construction, and the frame itself is not
  // mounted until the panel is first opened.
  // ---------------------------------------------------------------------------
  mountWidget();

})();
