// SPDX-License-Identifier: Apache-2.0
//
// Cinatra WordPress assistant widget — CANONICAL, locally-served widget (cinatra#411).
//
// This file is the CANONICAL source-of-truth widget for WordPress and is
// AUTHORED FIRST; the Drupal copy (cinatra-ai/drupal-module/js/cinatra-widget.js)
// is hand-mirrored from it.
//
// ARCHITECTURE (S5 / cinatra#1221; PROTOCOL 2 by cinatra#2674): the assistant
// conversation is NOT rendered by this file. The Cinatra instance serves the
// AG-UI surface at `/embed/assistant` and THIS widget mounts it in a sandboxed
// <iframe> as the SOLE session owner. This shell keeps only the host-side
// concerns that MUST live on the CMS origin: the launcher/panel chrome and the
// parent half of the §12/§12b parent↔iframe bridge.
//
// WHAT PROTOCOL 2 CHANGED, AND WHY IT IS THE WHOLE POINT (cinatra#2674).
// At protocol 1 this file was a party to the person's sign-in: it ran the hosted
// PKCE handshake through same-origin PHP relays, received the `cwu_` per-user
// bearer back, minted a `cit_` site transport token, and composed BOTH into a
// postMessage BOOTSTRAP. That made the website a HOLDER of a credential that
// belongs to the person and to Cinatra.
//
// That is over. This shell now:
//   * initiates NO sign-in and redeems NO code — the frame runs the whole
//     ceremony on the Cinatra origin, in a top-level Cinatra popup it opens
//     itself, and the credential never leaves the frame;
//   * composes and receives NO bearer — `cwu_` and `cit_` do not appear in
//     plugin code at all, and the retired token-broker relays are DELETED (the
//     instance answers the old `/api/widget-auth/{init,token}` pair 410 Gone);
//   * posts ONE inbound message, `cinatra.embed.context`, carrying PUBLIC,
//     UNTRUSTED SELECTORS only (which site, which agent, which CMS resource is
//     on screen) at protocol version literal 2.
// The long-lived `cnx_` connect-site credential is UNCHANGED and stays exactly
// what it was: a backend-only setup/integration credential, used server-to-server
// from PHP, never in the browser and never on this bridge.
//
// NO CREDENTIAL FIELD, AND NO CREDENTIAL VALUE. Dropping `auth` removes the
// credential FIELD. Every remaining field is still a string this file chooses, so
// a `cwu_…` could in principle ride inside `cms.resourceId`. So every outbound
// bridge payload is recursively scanned for a value carrying one of Cinatra's
// bearer prefixes and the send is REFUSED when one is found — mirroring
// `containsCredentialShapedValue` in cinatra src/lib/embed/bridge-protocol.ts. A
// message that never leaves is strictly better than one the frame rejects on
// arrival.
//
// TRUST BOUNDARY (§4/§6/§12/§12b):
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
//   * The credential guard runs in BOTH directions: a payload carrying a
//     bearer-shaped value is refused on the way out and dropped on the way in.
//   * PORT-BOUND TRANSPORT (§12b): the iframe creates a MessageChannel and
//     transfers ONE endpoint in the origin/source-gated READY. The parent RETAINS
//     that endpoint and sends the CONTEXT message (plus any later parent→iframe
//     traffic) over it. At protocol 2 this is no longer a credential wall — there
//     is no credential to misdeliver — but it is kept because the property still
//     holds and costs nothing: a same-origin replacement of the frame is a fresh
//     realm that never inherits the entangled endpoint, so it cannot silently
//     take over an established session's channel.
//   * The WINDOW transport remains for a frame that transfers no port; when used
//     it still posts to an EXPLICIT targetOrigin (the Cinatra instance origin),
//     NEVER "*".
//   * Inbound frame messages are accepted ONLY when `event.origin === cinatraOrigin`
//     AND `event.source === iframe.contentWindow` (origin + source-window binding).
//     Steady-state uplinks in PORT mode ride the entangled port (their provenance
//     is the origin-targeted transfer that delivered it — a NARROWING).
//   * READY→CONTEXT: the parent mints a CSPRNG correlationId (≥128-bit), echoes
//     the frame nonce, sends seq=0, and ONE context per frame (a new session is a
//     reload). A refused send is NOT retried — there is no retry storm here.
//   * Two INDEPENDENT monotonic seq counters (one per direction) per correlationId.
//   * apply_intent carries an UNTRUSTED SELECTOR only: the parent re-checks the
//     current user may edit, uses its OWN canonical resource, dedups against a
//     bounded LRU, and does an in-place draft refresh — NO direct /wp-json egress
//     (#1214: field-apply happens server-side via the CMS MCP integration).
//   * resize height is CLAMPED to the panel cap (clamp, never trust the value).
//
// Security-critical invariants (no apiKey and no bearer of any shape in the
// browser; no token-broker call; no sign-in ceremony on the CMS origin; sandbox
// iframe; explicit targetOrigin; source-window binding; the credential-shaped
// value guard on every send; no apply-time egress) are gated by
// tools/widget-parity-check.mjs in CI.
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
// The Cinatra WordPress plugin as a whole is licensed GPL-2.0-or-later; this
// vendored file is incorporated under the Apache-2.0 grant above, which is
// compatible with redistribution under the GPL.
// ---------------------------------------------------------------------------
(function () {
  // ---------------------------------------------------------------------------
  // Config guard.
  // The browser holds NO credential of any kind — no long-lived key, no broker
  // endpoint, no bearer. All it needs is the instance URL to frame, plus the
  // public selectors it will hand the frame.
  // ---------------------------------------------------------------------------
  var config = window.CinatraConfig || {};
  if (!config.cinatraUrl) {
    console.warn('[cinatra] Missing CinatraConfig (cinatraUrl)');
    return;
  }
  var rootEl = document.getElementById('cinatra-root');
  if (!rootEl) { console.warn('[cinatra] #cinatra-root not found'); return; }
  if (rootEl.dataset.cinatraMounted === 'true') return;
  // NOTE: data-cinatra-mounted is set at the END of synchronous mount construction
  // (see mountWidget()). The shell no longer pre-flight-negotiates, so it mounts
  // unconditionally at boot; a throw mid-mount still leaves the fallback chrome
  // visible (the marker that hides it is set LAST).

  // §4: the `?assistant` value — the agent identifier this parent may name. It is
  // a SELECTOR, never an assertion: the instance re-derives the authoritative
  // agent from its own closed host-side table and denies on any mismatch, so
  // naming another site's agent yields a refusal, not that agent. MUST equal the
  // embed page's `session.assistant` agreement check.
  var EMBED_ASSISTANT = 'wordpress';

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
  // The protocol-2 SELECTOR BOUNDS, mirrored from the core context schema
  // (cinatra#2674; codex round 0, finding 4).
  //
  // The frame's schema is `.strict()`, so ONE over-long display field rejects the
  // WHOLE message and the session never starts — the frame just sits in its
  // neutral "waiting for host" state forever, with nothing to tell the site owner
  // why. An OPTIONAL selector that would exceed its bound is therefore OMITTED
  // rather than sent: it is a disambiguator, and losing a disambiguator costs far
  // less than losing the session. It is never TRUNCATED — a truncated id is a
  // different id, and a selector that quietly names something else is worse than
  // one that is absent.
  // ---------------------------------------------------------------------------
  var SELECTOR_MAX = {
    siteId: 200,        // site.siteId
    instanceId: 200,    // cms.instanceId (REQUIRED, min 1)
    resourceId: 200,    // cms.resourceId
    resourceType: 200,  // cms.resourceType
    status: 64,         // cms.status
  };
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
  // — and what tests/test-no-credential-egress.mjs pins with synthetic sentinels —
  // is that nothing this plugin puts on the bridge can be shaped like one of our
  // bearers, at any depth, in a value or a key.
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
    if (typeof value !== 'string') return false;
    return CREDENTIAL_TOKEN_RE.test(value);
  }
  // Recursively true when ANY string anywhere is credential-shaped — object
  // values, array members and object KEYS alike (a key is as visible to a logger
  // as a value). Depth-bounded, like the core guard.
  function containsCredentialShapedValue(value, depth) {
    var d = depth || 0;
    if (d > 8) return false;
    if (isCredentialShapedValue(value)) return true;
    if (value === null || typeof value !== 'object') return false;
    if (Object.prototype.toString.call(value) === '[object Array]') {
      for (var i = 0; i < value.length; i++) {
        if (containsCredentialShapedValue(value[i], d + 1)) return true;
      }
      return false;
    }
    for (var key in value) {
      if (!Object.prototype.hasOwnProperty.call(value, key)) continue;
      if (isCredentialShapedValue(key)) return true;
      if (containsCredentialShapedValue(value[key], d + 1)) return true;
    }
    return false;
  }

  // The Cinatra instance origin — the ONLY origin the bridge posts to and the
  // ONLY origin/source it accepts uplinks from. Resolved ONCE, strictly.
  var cinatraOrigin = null;
  try { cinatraOrigin = new URL(config.cinatraUrl).origin; } catch (_) { cinatraOrigin = null; }
  if (!cinatraOrigin) {
    console.warn('[cinatra] cinatraUrl is not a valid origin; widget not mounted');
    return;
  }

  // ---------------------------------------------------------------------------
  // mountWidget() — builds the Shadow DOM + wires the launcher and the
  // parent-side bridge. Called UNCONDITIONALLY at boot: the capability/contract
  // handshake runs CLIENT-SIDE inside the /embed/assistant iframe against the
  // unified broker surface (see the header), so there is no shell pre-flight to
  // gate the mount.
  //
  // THERE IS NO LOGIN GATE HERE ANY MORE (cinatra#2674). Sign-in is not this
  // shell's business: the frame decides whether the person is signed in and, if
  // not, shows its own sign-in inside the frame and runs the whole ceremony on the
  // Cinatra origin. So the panel has exactly one body — the frame — and the frame
  // is mounted lazily on the first open (a user gesture), never on every admin
  // page load.
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

  // Inject Archivo font into document head (fonts must be in document scope to work inside shadow DOM).
  var FONT_URL = 'https://fonts.googleapis.com/css2?family=Archivo:ital,wght@0,400;0,500;0,600;1,800&display=swap';
  if (!document.querySelector('link[href="' + FONT_URL + '"]')) {
    var fontLink = document.createElement('link');
    fontLink.rel = 'stylesheet';
    fontLink.href = FONT_URL;
    document.head.appendChild(fontLink);
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
  // ---------------------------------------------------------------------------
  var isOpen = false;

  // ---------------------------------------------------------------------------
  // Content editor context — the PUBLIC selectors for the `cms` block of the
  // CONTEXT message, and the parent's OWN canonical resource for apply_intent.
  // Nothing here is authority and nothing here is secret: the instance re-derives
  // the authoritative site, org, origin, agent and canonical instance from its own
  // rows and denies on any mismatch.
  // ---------------------------------------------------------------------------
  function buildContentContext() {
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
      instanceId: config.instanceId || '',
      postId:     String(postId),
      postType:   typeof window.typenow !== 'undefined' ? window.typenow : '',
      postStatus: postStatus,
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
  // message and services the closed set of iframe→parent uplinks. Every
  // trust-boundary control is enforced here: origin + source-window binding,
  // schema/protocolVersion/nonce agreement, dual monotonic seq, one context per
  // frame, the credential-shaped value refusal on every send, apply_intent
  // untrusted-selector permission checks + bounded LRU dedup, and the resize clamp.
  //
  // TRANSPORT (§12b): the iframe transfers one MessageChannel endpoint in READY;
  // the parent RETAINS it and sends the CONTEXT message over that port. Steady-
  // state uplinks then ride the same entangled port. The WINDOW transport remains
  // for a frame that transfers no port (BRIDGE_REQUIRE_PORT === false) and is
  // origin-pinned when used. At protocol 2 the port is DEFENCE IN DEPTH, not the
  // credential wall — the credential wall is that no credential exists here at all.
  // ---------------------------------------------------------------------------
  var iframeEl = null;          // the mounted embed iframe (null until first open)
  var frameWindow = null;       // iframeEl.contentWindow captured at load
  var frameNonce = null;        // the READY nonce the frame minted (echoed in context)
  var correlationId = null;     // parent-minted CSPRNG id, echoed by every uplink
  var contextSent = false;      // one CONTEXT per frame (a new session is a reload)
  var inboundSeqLast = null;    // iframe->parent monotonic gate (READY seeds it)
  var outboundSeqLast = null;   // parent->iframe monotonic counter (context = 0)
  var appliedLru = [];          // §6f bounded seen apply-id LRU for this correlationId
  var bridgePort = null;        // §12b the MessagePort the iframe transferred in READY (null in window mode)
  var activeTransport = null;   // 'port' | 'window' — set once at context release
  var frameRefused = false;     // one-shot: the frame cannot be framed (see mountBridgeIframe)

  // §12b: refuse a portless (window-only) send. FALSE, mirroring the core
  // `selectParentContextTransport({ requirePort })` switch, because at protocol 2
  // the port protects an uplink channel rather than a credential — a frame that
  // transfers no port gets the origin-pinned window transport, and the worst a
  // misdelivered CONTEXT can do is tell a replacement document which post is on
  // screen. Flip to true (and delete the window branch) once every embed instance
  // transfers a port.
  var BRIDGE_REQUIRE_PORT = false;

  // A CSPRNG base64url correlationId carrying >=128 bits (24 base64url chars ==
  // 144 bits), satisfying ID_PATTERN (§6b).
  function mintCorrelationId() {
    return randB64url(18);
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
  // BRIDGE_REQUIRE_PORT is false. cinatraOrigin is resolved once and is a real
  // origin (never "*"/empty — the mount aborts otherwise), so the "never '*'"
  // invariant holds structurally.
  function postToFrame(message) {
    if (!frameWindow) return false;
    frameWindow.postMessage(message, cinatraOrigin);
    return true;
  }

  // §12b PARENT-side transport selection — mirrors selectParentContextTransport
  // in cinatra src/lib/embed/bridge-protocol.ts. Given the ports the iframe
  // transferred on the ALREADY-GATED READY (origin + source-window checked by
  // onBridgeMessage before this runs):
  //   * a transferred port present  -> PORT mode (the message rides ONLY the
  //     entangled port; a same-origin replacement of the iframe cannot receive it);
  //   * no port AND requirePort     -> FAIL CLOSED ('none'): the caller sends
  //     NOTHING, so the window transport cannot be selected merely by stripping
  //     the transferred port;
  //   * no port AND window allowed  -> WINDOW mode (the origin-pinned window
  //     transport, for a frame that transferred no port).
  function selectContextTransport(ports) {
    var port = (ports && ports.length) ? ports[0] : null;
    if (port) { return { mode: 'port', port: port }; }
    if (BRIDGE_REQUIRE_PORT) { return { mode: 'none' }; }
    return { mode: 'window' };
  }

  // Send a parent->iframe message over the transport chosen at READY.
  //
  // THE OUTBOUND GUARD RUNS HERE, ON EVERY PATH (cinatra#2674). Whatever the
  // caller composed, a payload carrying a credential-shaped value AT ANY DEPTH is
  // REFUSED rather than transmitted: the parity gate proves the guard exists, and
  // this function is the single choke point that proves it always runs. Returns
  // whether the message was actually sent, so the caller can fail closed instead
  // of assuming delivery.
  function sendToFrame(message) {
    if (containsCredentialShapedValue(message)) {
      // Deliberately says nothing about WHAT matched: a refusal must not become a
      // place where a credential is echoed into a console.
      console.warn('[cinatra] refusing to post a credential-shaped value to the assistant frame');
      return false;
    }
    if (activeTransport === 'port' && bridgePort) {
      bridgePort.postMessage(message);
      return true;
    }
    return postToFrame(message);
  }

  // §3a READY validator (pre-context; the ONLY message without a correlationId).
  function isValidReady(d) {
    return !!d && d.type === MSG.ready &&
      d.protocolVersion === EMBED_PROTOCOL_VERSION &&
      typeof d.nonce === 'string' && ID_PATTERN.test(d.nonce) &&
      typeof d.seq === 'number';
  }

  // §5 uplink common envelope validator (post-context): protocolVersion, the
  // echoed correlationId, and a monotonic seq for the iframe->parent direction.
  function validUplinkEnvelope(d) {
    if (!d || d.protocolVersion !== EMBED_PROTOCOL_VERSION) return false;
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
  // this server and never enters the browser. Omitted when this site has none
  // (it is a disambiguator, and one that is not needed must not be required).
  //
  // The page URL is deliberately NOT sent: the protocol allows an optional
  // `cms.href` display selector, and the frame does not need one to name the post.
  function buildEmbedContext(nonce) {
    frameNonce = nonce;
    correlationId = mintCorrelationId();
    var ctx = buildContentContext();
    // instanceId is bound-checked at mount (an out-of-bounds one means no frame
    // at all), so it is in range by the time this runs.
    var cms = { instanceId: config.instanceId };
    var resourceId = boundedSelector(ctx.postId, SELECTOR_MAX.resourceId);
    var resourceType = boundedSelector(ctx.postType, SELECTOR_MAX.resourceType);
    var status = boundedSelector(ctx.postStatus, SELECTOR_MAX.status);
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
        threadId: correlationId,           // one thread per framed session
        assistant: EMBED_ASSISTANT,        // == ?assistant (a selector, not authority)
      },
      cms: cms,
    };
    var siteId = boundedSelector(config.siteId, SELECTOR_MAX.siteId);
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

  // Best-effort re-check that the current user may edit the canonical resource
  // (§6f step 1). Uses WordPress's OWN capability oracle; fails CLOSED only on an
  // explicit `false` (the server-side MCP apply is itself permission-checked, so
  // an unresolved/absent oracle is not treated as a hard deny in the client).
  function currentUserMayEdit(ctx) {
    try {
      if (window.wp && window.wp.data && window.wp.data.select && ctx.postType && ctx.postId) {
        var coreSel = window.wp.data.select('core');
        if (coreSel && typeof coreSel.canUser === 'function') {
          // WordPress core-data `canUser` object-entity form (kind/name/id) — the
          // 4-positional `('update','postType',type,id)` form is NOT a valid
          // signature (it would silently return undefined, making the deny branch
          // dead). `canUser` is a TRI-STATE that resolves ASYNCHRONOUSLY: the
          // first synchronous read is `undefined` while it resolves. So deny ONLY
          // on an explicit `false`; on `true` OR still-resolving `undefined`,
          // defer to the SERVER-side write authorization — treating `undefined`
          // as a deny would make the (non-mutating) draft refresh never fire on
          // first use. This client gate only guards a refresh of the user's OWN
          // already-open post; the field WRITE was performed + capability-checked
          // server-side by the CMS MCP integration (#1214).
          var can = coreSel.canUser('update', {
            kind: 'postType',
            name: ctx.postType,
            id: ctx.postId,
          });
          if (can === false) return false;
        }
      }
    } catch (_) {}
    return true;
  }

  // In-place draft refresh via WordPress's OWN data layer — NO widget-constructed
  // /wp-json egress and NO page reload (#1214: the field-apply already happened
  // server-side through the CMS MCP integration; this only refreshes the editor's
  // view of the canonical post so the applied draft shows).
  function refreshCurrentDraft(ctx) {
    try {
      if (window.wp && window.wp.data && window.wp.data.dispatch && ctx.postType && ctx.postId) {
        var coreDispatch = window.wp.data.dispatch('core');
        if (coreDispatch && typeof coreDispatch.invalidateResolution === 'function') {
          coreDispatch.invalidateResolution('getEntityRecord', ['postType', ctx.postType, ctx.postId]);
          return true;
        }
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

  // THE GUARD RUNS INBOUND TOO (cinatra#2674; codex round 0, finding 2).
  //
  // "No credential crosses this boundary" is a claim about BOTH directions, and
  // the inbound half is not hypothetical: the FRAME is the one party that holds a
  // `cwu_`/`cit_`, and an uplink is a place a bug could put one — an
  // `a11y.liveRegion` string, say, which this parent writes straight into the
  // admin page's live region. That would leave a person's bearer sitting in the
  // CMS DOM, which is the exact possession this slice exists to remove.
  //
  // So every inbound envelope is scanned before any field of it is read, on both
  // transports, and a match is DROPPED SILENTLY. Silently on purpose: a warning
  // that named or echoed the offending message would copy the credential into a
  // console and from there into a support paste, turning a containment control
  // into a disclosure.
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
    var d = event.data;
    if (!d || typeof d !== 'object' || typeof d.type !== 'string') return;
    if (!inboundIsClean(d)) return;
    dispatchUplink(d);
  }

  // The single inbound WINDOW bridge listener — origin + source-window bound.
  // Attached when the iframe mounts. It carries the READY (which transfers the
  // port) and, in WINDOW mode only, the post-context uplinks.
  function onBridgeMessage(event) {
    // (§6a) strict origin, BEFORE schema.
    if (event.origin !== cinatraOrigin) return;
    // (§6a-2) source-window binding, BEFORE schema — a sibling frame on the same
    // origin must never drive this bridge. Nullish source never matches.
    if (!frameWindow || event.source !== frameWindow) return;

    var d = event.data;
    if (!d || typeof d !== 'object' || typeof d.type !== 'string') return;
    // Inbound credential guard, before ANY field is read — including READY's, so
    // a nonce carrying a bearer shape never reaches the echo that would put it
    // back on the wire.
    if (!inboundIsClean(d)) return;

    if (d.type === MSG.ready) {
      // §4 READY → CONTEXT, released synchronously in this same message task.
      // A second READY on an established session is IGNORED (one context per
      // frame; a new session is a reload).
      if (contextSent) return;
      if (!isValidReady(d)) return;
      // Seed the iframe->parent monotonic gate with READY's seq (§6c); post-context
      // uplinks must strictly increase from it.
      if (!acceptInboundSeq(d.seq)) return;
      // §12b: choose the transport from the ports the iframe transferred on THIS
      // already-gated READY. A transferred port => bind uplinks to it and send the
      // context over it. No port + requirePort => send NOTHING (fail closed). No
      // port + window allowed => the origin-pinned window transport.
      var transport = selectContextTransport(event.ports);
      if (transport.mode === 'none') { return; }
      if (transport.mode === 'port') {
        bridgePort = transport.port;
        // Steady-state uplinks ride the entangled port; assigning onmessage starts it.
        bridgePort.onmessage = onPortMessage;
        activeTransport = 'port';
      } else {
        activeTransport = 'window';
      }
      // Set the one-context latch BEFORE posting so a re-entrant delivery cannot
      // double-send. In PORT mode the message rides ONLY the retained port; in
      // WINDOW mode event.source === frameWindow was verified above, so it posts
      // to the exact document that sent READY.
      //
      // A REFUSED SEND IS NOT RETRIED, and that is the point. `sendToFrame`
      // refuses a payload carrying a credential-shaped value; retrying would
      // recompose the same selectors from the same page and refuse again, forever.
      // The latch therefore STAYS SET on a refusal: exactly one attempt per frame,
      // no storm. The frame keeps drawing its own neutral pre-context state, and
      // the person is never shown a CMS-side error about it.
      contextSent = true;
      if (!sendToFrame(buildEmbedContext(d.nonce))) {
        console.warn('[cinatra] the assistant frame was not given its context; reload the page to try again');
      }
      return;
    }

    // Post-context uplinks. In PORT mode they ride the entangled port
    // (onPortMessage); a window-delivered uplink is IGNORED so the transport
    // cannot be split/downgraded afterwards.
    if (!contextSent) return;
    if (bridgePort) return;
    dispatchUplink(d);
  }

  // TEST-ONLY render-parity seam carrier (cinatra#1998 (c), epic #1216 S6). The
  // Cinatra render-parity E2E frames THIS widget's `/embed/assistant` iframe and
  // needs the deterministic corpus-render seam params (`parityThread` /
  // `parityTheme`, cinatra#1998 (b)) to ride the iframe src the plugin builds —
  // the harness cannot reach into the plugin's fixed src otherwise. This reads a
  // NAMESPACED signal the test stages on the host admin page — a
  // `window.__cinatraParitySeam` global (STORAGE-FREE: the widget persists
  // nothing — the iframe owns storage — so this trips no web-storage invariant),
  // with a same-named query param as a fallback. PRODUCTION never stages it, so
  // the returned suffix is '' and the src is BYTE-IDENTICAL to before. It is
  // doubly inert in prod: even a forged signal is a no-op because the Cinatra
  // server IGNORES `parityThread` unless its server-only `EMBED_PARITY_SEAM` gate
  // is on (off in prod) — the seam is server-gated, so this can NEVER inject
  // content or bypass auth in production. Carries NO token (only the two
  // non-secret render-parity disambiguators), exactly like instanceId/assistant.
  function embedParitySeamParams() {
    try {
      var thread = '';
      var theme = '';
      // 1) A namespaced global the harness stages (survives host-page URL
      //    rewrites; storage-free so the token non-disclosure invariant holds).
      var g = window.__cinatraParitySeam;
      if (g && typeof g === 'object') {
        thread = typeof g.thread === 'string' ? g.thread : '';
        theme = typeof g.theme === 'string' ? g.theme : '';
      }
      // 2) Fallback: a namespaced query param on the host admin URL.
      if (!thread && window.location && window.location.search && window.URLSearchParams) {
        var q = new URLSearchParams(window.location.search);
        thread = q.get('cinatra_parity_thread') || '';
        theme = q.get('cinatra_parity_theme') || '';
      }
      if (!thread) return '';
      var suffix = '&parityThread=' + encodeURIComponent(thread);
      if (theme === 'github-dark' || theme === 'github-light') {
        suffix += '&parityTheme=' + encodeURIComponent(theme);
      }
      return suffix;
    } catch (_) {
      return '';
    }
  }

  // Build the sandboxed embed iframe and attach the bridge listener. The src is
  // the Cinatra-served `/embed/assistant` route carrying only the NON-SECRET
  // disambiguators (instanceId, assistant). There is no token to keep out of this
  // URL any more — this shell holds none — and none is put there.
  //
  // THE SANDBOX GREW BY EXACTLY TWO TOKENS AT PROTOCOL 2, AND IT HAD TO
  // (cinatra#2674; codex round 0, finding 1). The sign-in is now the FRAME's: it
  // calls `window.open()` on the hosted sign-in URL. A sandboxed frame without
  // `allow-popups` cannot open a window at all — `window.open` returns null, the
  // frame reports `popup_blocked`, and NOBODY CAN EVER SIGN IN. And a popup that
  // merely inherits this sandbox is equally dead: it would carry no `allow-forms`
  // and no top-level navigation, so the hosted sign-in could neither take input
  // nor complete its redirect. Hence BOTH tokens:
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
  // The parity gate REQUIRES these two and still forbids the rest.
  function mountBridgeIframe() {
    if (iframeEl) return;
    // `cms.instanceId` is REQUIRED, non-empty and <= 200 chars in the protocol-2
    // schema, and it must agree with the `?instanceId` this shell puts in the
    // frame URL. Out of range, the frame would be pointed at nothing and would
    // reject the context on arrival, so refuse to frame it at all — once — and say
    // so in one actionable line rather than framing a surface that cannot work.
    // The launcher chrome stays; the site owner fixes the setting and reloads.
    if (!boundedSelector(config.instanceId, SELECTOR_MAX.instanceId)) {
      if (!frameRefused) {
        frameRefused = true;
        console.warn('[cinatra] the agent instance id is missing or out of range; the assistant was not started');
      }
      return;
    }
    var src = config.cinatraUrl + '/embed/assistant' +
      '?instanceId=' + encodeURIComponent(config.instanceId || '') +
      '&assistant=' + encodeURIComponent(EMBED_ASSISTANT) +
      embedParitySeamParams();
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

  // ---------------------------------------------------------------------------
  // Open / collapse — circle↔widget swap
  //
  // The frame is mounted LAZILY, on the first open. That keeps a third-party
  // iframe off every wp-admin page load while still tying the mount to a plain
  // user gesture; from then on the frame persists across open/collapse so the
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
  // Boot: mount UNCONDITIONALLY. The capability/contract handshake runs inside the
  // /embed/assistant iframe (unified broker surface — see the header), so the
  // shell has no pre-flight to gate on, and sign-in is the frame's business rather
  // than a gate here. The always-visible fallback button remains until
  // data-cinatra-mounted is set at the end of synchronous mount construction.
  // ---------------------------------------------------------------------------
  mountWidget();

})();
