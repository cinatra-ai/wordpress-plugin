// SPDX-License-Identifier: Apache-2.0
//
// Cinatra WordPress assistant widget — CANONICAL, locally-served widget (cinatra#411).
//
// This file is the CANONICAL source-of-truth widget for WordPress and is
// AUTHORED FIRST; the Drupal copy (cinatra-ai/drupal-module/js/cinatra-widget.js)
// is hand-mirrored from it.
//
// ARCHITECTURE (S5 / cinatra#1221): the assistant conversation is NO LONGER
// rendered by this file. The Cinatra instance serves the AG-UI surface at
// `/embed/assistant` and THIS widget mounts it in a sandboxed <iframe> as the
// SOLE session owner. This shell keeps only the host-side concerns that MUST live
// on the CMS origin: the launcher/panel chrome, the required-login PKCE handshake
// (cwu_ per-user token) + the short-lived cit_ site-token broker mint, capability
// negotiation, and the parent half of the §12 parent↔iframe postMessage bridge.
//
// The vanilla AG-UI renderer (markdown/diff-card/history/SSE-stream loop) that
// previously lived here is DELETED: the iframe owns the turn (textarea + submit +
// streaming render are INSIDE the iframe). This shell never streams, never holds
// an `Authorization: Bearer` fetch, and relays the cit_/cwu_ tokens ONLY into the
// single BOOTSTRAP postMessage — never to storage, a URL, a log, or an uplink.
//
// TRUST BOUNDARY (§4/§6/§12):
//   * The iframe is `sandbox="allow-scripts allow-same-origin"` (no top-nav, no
//     forms, no popups, no downloads, no modals) framing `/embed/assistant`.
//   * Every postMessage to the frame uses an EXPLICIT targetOrigin (the Cinatra
//     instance origin), NEVER "*".
//   * Inbound frame messages are accepted ONLY when `event.origin === cinatraOrigin`
//     AND `event.source === iframe.contentWindow` (origin + source-window binding).
//   * READY→BOOTSTRAP: the parent mints a CSPRNG correlationId (≥128-bit), echoes
//     the frame nonce, sends seq=0, and one bootstrap per frame (re-auth = reload).
//   * Two INDEPENDENT monotonic seq counters (one per direction) per correlationId.
//   * apply_intent carries an UNTRUSTED SELECTOR only: the parent re-checks the
//     current user may edit, uses its OWN canonical resource, dedups against a
//     bounded LRU, and does an in-place draft refresh — NO direct /wp-json egress
//     (#1214: field-apply happens server-side via the CMS MCP integration).
//   * resize height is CLAMPED to the panel cap (clamp, never trust the value).
//
// Security-critical invariants (no apiKey in the browser; tokenEndpoint broker;
// sandbox iframe; explicit targetOrigin; source-window binding; token-in-bootstrap
// only; no apply-time egress; contract-version set) are gated by
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
  // Bootstrap config guard.
  // The browser holds NO long-lived key. It needs the instance URL and a
  // same-origin broker endpoint that mints short-lived tokens.
  // ---------------------------------------------------------------------------
  var config = window.CinatraConfig || {};
  if (!config.cinatraUrl || !config.tokenEndpoint) {
    console.warn('[cinatra] Missing CinatraConfig (cinatraUrl / tokenEndpoint)');
    return;
  }
  var rootEl = document.getElementById('cinatra-root');
  if (!rootEl) { console.warn('[cinatra] #cinatra-root not found'); return; }
  if (rootEl.dataset.cinatraMounted === 'true') return;
  // NOTE: data-cinatra-mounted is set ONLY after capability negotiation succeeds
  // (see boot() at the bottom). Setting it earlier would hide the fallback chrome
  // even when the instance is unavailable/incompatible.

  var AGENT_SLUG = 'wordpress-content-editor';
  // Required-login (cinatra#410): the per-user auth handshake (#407 hosted PKCE)
  // names this site as the `client`. The CMS differentiator (vs the Drupal mirror)
  // is this one constant + the CMS config accessor + the broker CSRF idiom.
  var AUTH_CLIENT = 'wordpress';
  // §4: the `?assistant` value; == the cit_-bound kind. MUST equal the embed
  // page's `session.assistant` agreement check.
  var EMBED_ASSISTANT = 'wordpress';
  // Contract versions this vendored widget understands, newest first.
  var CLIENT_CONTRACT_VERSIONS = ['v2', 'v1'];

  // ---------------------------------------------------------------------------
  // §12 bridge protocol constants (the byte-level contract both halves pin).
  // Mirror of cinatra-ai/cinatra src/lib/embed/bridge-protocol.ts — kept in sync
  // by review + the parity gate. There is NO arbitrary-tool channel: the message
  // type set is CLOSED.
  // ---------------------------------------------------------------------------
  var EMBED_PROTOCOL_VERSION = 1;
  var MSG = {
    ready: 'cinatra.embed.ready',       // iframe -> parent, pre-bootstrap (no correlationId)
    bootstrap: 'cinatra.embed.bootstrap', // parent -> iframe, the ONLY credential carrier
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

  // The Cinatra instance origin — the ONLY origin the bridge posts BOOTSTRAP to
  // and the ONLY origin/source it accepts uplinks from. Resolved ONCE, strictly.
  var cinatraOrigin = null;
  try { cinatraOrigin = new URL(config.cinatraUrl).origin; } catch (_) { cinatraOrigin = null; }
  if (!cinatraOrigin) {
    console.warn('[cinatra] cinatraUrl is not a valid origin; widget not mounted');
    return;
  }

  // Negotiated state — populated ONLY by a SUCCESSFUL negotiateCapabilities().
  // The iframe renders the turn now, so the shell needs only the mutually-agreed
  // contractVersion (passed to the cit_ broker mint). No optimistic defaults: if
  // negotiation fails the widget never mounts.
  var negotiated = {
    contractVersion: null,
  };

  // ---------------------------------------------------------------------------
  // Bounded-timeout fetch helper. AbortSignal.timeout() is not universal, so we
  // drive an AbortController ourselves; the timer is always cleared.
  // ---------------------------------------------------------------------------
  function fetchWithTimeout(url, opts, timeoutMs) {
    var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var timer = null;
    var options = opts || {};
    if (controller) {
      options = Object.assign({}, options, { signal: controller.signal });
      timer = setTimeout(function () { try { controller.abort(); } catch (_) {} }, timeoutMs);
    }
    var p;
    try {
      p = fetch(url, options);
    } catch (err) {
      if (timer) clearTimeout(timer);
      return Promise.reject(err);
    }
    return p.then(
      function (resp) { if (timer) clearTimeout(timer); return resp; },
      function (err) { if (timer) clearTimeout(timer); throw err; }
    );
  }

  // ---------------------------------------------------------------------------
  // Capability + contract-version negotiation (HARD PREREQUISITE — AC2 interlock).
  //
  // The /capabilities endpoint is auth-free and returns only static contract
  // metadata. It MUST succeed and validate before the widget mounts. Any failure
  // (HTTP not-ok / network / timeout / non-JSON / missing capabilities / no
  // mutually-supported contract version / supportsTokenExchange !== true / missing
  // tokenPath) returns false and the caller aborts the mount. There are NO
  // optimistic defaults and NO legacy long-lived fallback.
  //
  // The iframe owns streaming now, so the shell no longer resolves/validates a
  // `streamPath` for its own fetch (it makes no stream fetch). Negotiation still
  // gates the mount and yields the contractVersion the cit_ broker mint needs. The
  // negotiation/capabilities path is KEPT until AC2 (the follow-up slice) lands
  // everywhere; widgets still negotiate until then.
  // ---------------------------------------------------------------------------
  function pickContractVersion(serverVersions) {
    if (!Array.isArray(serverVersions)) { return null; }
    for (var ci = 0; ci < CLIENT_CONTRACT_VERSIONS.length; ci++) {
      if (serverVersions.indexOf(CLIENT_CONTRACT_VERSIONS[ci]) !== -1) {
        return CLIENT_CONTRACT_VERSIONS[ci];
      }
    }
    return null;
  }

  function negotiateCapabilities() {
    return fetchWithTimeout(
      config.cinatraUrl + '/api/agents/' + AGENT_SLUG + '/capabilities',
      { method: 'GET', cache: 'no-store' },
      5000
    ).then(function (resp) {
      if (!resp || !resp.ok) { return false; }
      return resp.json().then(function (data) {
        if (!data || typeof data !== 'object') { return false; }
        var caps = data.capabilities;
        if (!caps || typeof caps !== 'object') { return false; }
        // Required: a mutually-supported contract version.
        var version = pickContractVersion(data.supportedContractVersions);
        if (!version) { return false; }
        // Required: the broker token-exchange path is the ONLY client credential
        // model. An instance that cannot mint short-lived tokens is incompatible —
        // there is no long-lived key in the browser to fall back to.
        if (caps.supportsTokenExchange !== true) { return false; }
        if (typeof caps.tokenPath !== 'string' || !caps.tokenPath) { return false; }
        negotiated.contractVersion = version;
        return true;
      }).catch(function () { return false; });
    }).catch(function () {
      // Network error, timeout (abort), or transport failure.
      return false;
    });
  }

  // ---------------------------------------------------------------------------
  // mountWidget() — builds the Shadow DOM + wires the launcher, login, and the
  // parent-side bridge. Called ONLY after negotiateCapabilities() resolved true.
  // ---------------------------------------------------------------------------
  function mountWidget() {
  // Re-check after the async negotiation gap: a second copy of this IIFE could
  // have mounted while we awaited /capabilities.
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

    /* Panel: fills the widget; header on top, body (login | iframe) below. */
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

    /* Required-login window (cinatra#410). Shown in place of the iframe until a
       valid per-user token is held. NO email/password inputs and NO sign-up — the
       only affordance is the button that opens the hosted /widget-auth popup. */
    '.cw-login {',
    '  flex: 1; min-height: 0; display: flex; flex-direction: column;',
    '  align-items: center; justify-content: center;',
    '  padding: 32px 28px; text-align: center; gap: 16px; box-sizing: border-box;',
    '}',
    '.cw-login-mark { display: flex; align-items: center; justify-content: center; }',
    '.cw-login-title { font: 600 18px/1.3 system-ui, -apple-system, sans-serif; color: #15213a; letter-spacing: -0.01em; margin: 0; }',
    '.cw-login-sub { font: 14px/1.55 system-ui, -apple-system, sans-serif; color: #5a6477; max-width: 280px; margin: 0; }',
    '.cw-login-btn {',
    '  width: 100%; max-width: 260px; padding: 10px 16px; margin-top: 4px;',
    '  background: #15213a; color: #ffffff; border: none; border-radius: 10px;',
    '  font: 600 14px system-ui, -apple-system, sans-serif; cursor: pointer;',
    '  transition: background 0.15s;',
    '}',
    '.cw-login-btn:hover { background: #1d2c4d; }',
    '.cw-login-btn:disabled { opacity: 0.55; cursor: default; }',
    '.cw-login-err { font: 13px/1.5 system-ui, -apple-system, sans-serif; color: #b42318; min-height: 18px; max-width: 280px; }',
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

  // ---------------------------------------------------------------------------
  // Required-login window (cinatra#410). Shown in login mode; hidden in
  // conversation mode (replaced by the iframe). NO credential inputs, NO sign-up
  // — its only affordance opens the Cinatra-hosted /widget-auth login popup, so
  // raw credentials never touch this CMS-origin DOM.
  // ---------------------------------------------------------------------------
  var loginEl = document.createElement('div');
  loginEl.className = 'cw-login';

  var loginMark = document.createElement('div');
  loginMark.className = 'cw-login-mark';
  var loginLogo = mkSvg(40, 25, LOGO_VIEWBOX);
  loginLogo.setAttribute('fill', 'none');
  loginLogo.appendChild(mkEl('path', { d: LOGO_BRIM, fill: LOGO_COLOR }));
  loginLogo.appendChild(mkEl('path', { d: LOGO_CROWN, fill: LOGO_COLOR }));
  loginMark.appendChild(loginLogo);
  loginEl.appendChild(loginMark);

  var loginTitle = document.createElement('p');
  loginTitle.className = 'cw-login-title';
  loginTitle.textContent = 'Sign in to continue';
  loginEl.appendChild(loginTitle);

  var loginSub = document.createElement('p');
  loginSub.className = 'cw-login-sub';
  loginSub.textContent = 'Sign in with your Cinatra account to use the assistant on this site.';
  loginEl.appendChild(loginSub);

  var loginBtn = document.createElement('button');
  loginBtn.className = 'cw-login-btn';
  loginBtn.type = 'button';
  loginBtn.textContent = 'Sign in with Cinatra';
  loginEl.appendChild(loginBtn);

  var loginErr = document.createElement('div');
  loginErr.className = 'cw-login-err';
  loginErr.setAttribute('role', 'alert');
  loginErr.setAttribute('aria-live', 'polite');
  loginEl.appendChild(loginErr);

  panel.appendChild(loginEl);

  // Conversation body host — the sandboxed embed iframe is mounted here on login.
  var frameHost = document.createElement('div');
  frameHost.className = 'cw-frame-host';
  frameHost.style.display = 'none';
  panel.appendChild(frameHost);

  // ---------------------------------------------------------------------------
  // State
  // ---------------------------------------------------------------------------
  var isOpen = false;

  // Required-login state (cinatra#410). On a fresh mount there is never a valid
  // per-user token, so the panel starts in 'login' mode. `userToken` is held in
  // module scope (in-memory ONLY — never sessionStorage; a bearer in storage
  // outlives the tab and is XSS-exfiltratable). `pkce` holds the in-flight
  // handshake (verifier/state/popup) and is single-use.
  var panelMode = 'login';   // 'login' | 'conversation'
  var userToken = null;      // { token, expiresAtMs }
  var pkce = null;           // { codeVerifier, state, popup } during a handshake
  var popupTimer = null;     // setInterval handle while watching for popup close

  // ---------------------------------------------------------------------------
  // Content editor context (repurposed for the `cms` BOOTSTRAP context and as the
  // parent's OWN canonical resource for apply_intent — NOT a stream input).
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
  // Short-lived cit_ token exchange via the same-origin WordPress REST broker.
  // The browser never holds the long-lived integration key; the broker (PHP)
  // holds it and performs the server-to-server exchange. The minted cit_ token is
  // relayed ONLY into the BOOTSTRAP message (§4) — never a Bearer header, never a
  // URL, never storage. Cached in-memory and reused until ~10s before expiry.
  // ---------------------------------------------------------------------------
  var cachedToken = null;        // { token, expiresAtMs }
  async function getStreamToken() {
    var now = Date.now();
    if (cachedToken && cachedToken.expiresAtMs - 10000 > now) {
      return cachedToken.token;
    }
    var headers = { 'Content-Type': 'application/json' };
    if (config.nonce) headers['X-WP-Nonce'] = config.nonce;
    var resp = await fetch(config.tokenEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
      body: JSON.stringify({ contractVersion: negotiated.contractVersion }),
    });
    if (!resp.ok) {
      var detail = 'HTTP ' + resp.status;
      try {
        var errRaw = await resp.text();
        var errParsed = null;
        try { errParsed = JSON.parse(errRaw); } catch (_) {}
        if (errParsed && (errParsed.message || errParsed.error)) {
          detail = errParsed.message || errParsed.error;
        } else if (errRaw) {
          detail += ': ' + errRaw.slice(0, 200);
        }
      } catch (_) {}
      throw new Error('Could not obtain a Cinatra session token (' + detail + ').');
    }
    var body = await resp.json();
    if (!body || typeof body.token !== 'string' || body.token.indexOf('cit_') !== 0) {
      throw new Error('Cinatra session-token response was malformed.');
    }
    var ttlMs = (typeof body.expiresIn === 'number' ? body.expiresIn : 300) * 1000;
    cachedToken = { token: body.token, expiresAtMs: now + ttlMs };
    return body.token;
  }

  // ---------------------------------------------------------------------------
  // Required-login (cinatra#410): the per-user PKCE handshake against the hosted
  // /widget-auth surface (#407) + the login-window mode toggle. The browser never
  // holds the long-lived cnx_ key: both init and token redemptions go through the
  // same-origin PHP broker, which presents cnx_ server-to-server. The opaque cwu_
  // user token is short-lived (15-min TTL, no refresh) and held in memory only.
  // ---------------------------------------------------------------------------

  // base64url(no padding) of a byte array.
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
  async function sha256b64url(str) {
    var data = new TextEncoder().encode(str);
    var dig = await crypto.subtle.digest('SHA-256', data);
    return b64url(new Uint8Array(dig));
  }

  // CSRF/auth headers for the same-origin broker POSTs (WP REST convention: the
  // wp_rest nonce in X-WP-Nonce; the broker permission_callback + nonce check are
  // the cross-site-POST defense).
  function brokerHeaders() {
    var h = { 'Content-Type': 'application/json' };
    if (config.nonce) { h['X-WP-Nonce'] = config.nonce; }
    return h;
  }

  // A per-user token is valid when present and at least 5s from expiry (skew).
  function userTokenValid() {
    return !!(userToken && userToken.token && (userToken.expiresAtMs - 5000) > Date.now());
  }

  // Reflect panelMode into the DOM. The header stays in both modes; the iframe is
  // display:none in login mode so the conversation is never shown without a token.
  function applyPanelMode() {
    var login = panelMode === 'login';
    loginEl.style.display = login ? 'flex' : 'none';
    frameHost.style.display = login ? 'none' : 'flex';
  }

  // Tear down any in-flight handshake state. Does NOT close the popup — the caller
  // decides that (success closes it).
  function clearHandshake() {
    if (popupTimer) { try { clearInterval(popupTimer); } catch (_) {} popupTimer = null; }
    pkce = null;
  }

  // Drop the user token and return to the login window, tearing down the iframe
  // session (single bootstrap per frame; re-auth = a fresh frame). Used on expiry.
  function forceReLogin(message) {
    userToken = null;
    clearHandshake();
    teardownBridge();
    panelMode = 'login';
    applyPanelMode();
    loginErr.textContent = message || 'Your session expired. Please sign in again.';
    loginBtn.disabled = false;
  }

  // Poll for a manually-closed/blocked popup so we can re-enable the button and
  // drop the dangling handshake (the postMessage may never arrive).
  function watchPopupClosed() {
    if (popupTimer) { try { clearInterval(popupTimer); } catch (_) {} popupTimer = null; }
    var ticks = 0;
    popupTimer = setInterval(function () {
      ticks++;
      var closed = false;
      try { closed = !pkce || !pkce.popup || pkce.popup.closed; } catch (_) { closed = false; }
      if (closed || ticks > 600) {
        try { clearInterval(popupTimer); } catch (_) {}
        popupTimer = null;
        if (pkce) {
          pkce = null;
          loginBtn.disabled = false;
        }
      }
    }, 500);
  }

  // Start the login handshake: generate PKCE, init via the broker, open the hosted
  // login popup. The auth-popup message listener does the redeem when it posts back.
  async function startLogin() {
    if (pkce) { return; }                  // reject concurrent handshakes
    loginErr.textContent = '';
    loginBtn.disabled = true;
    try {
      if (typeof window.crypto === 'undefined' || !window.crypto || !crypto.subtle || typeof btoa === 'undefined') {
        throw new Error('Secure sign-in is not available in this browser.');
      }
      var verifier = randB64url(48);
      var challenge = await sha256b64url(verifier);
      var state = randB64url(24);

      var initResp = await fetch(config.authInitEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: brokerHeaders(),
        body: JSON.stringify({
          client: AUTH_CLIENT,
          agentSlug: AGENT_SLUG,
          codeChallenge: challenge,
          codeChallengeMethod: 'S256',
          state: state,
          instanceId: config.instanceId || undefined,
        }),
      });
      if (!initResp.ok) { throw new Error('Could not start sign-in.'); }
      var initBody = await initResp.json();
      if (!initBody || !initBody.authorizeUrl) { throw new Error('Could not start sign-in.'); }

      // SECURITY: the popup destination MUST be same-origin with the configured
      // instance — defense against a compromised broker steering the login popup
      // off-instance. Resolve and re-assert the origin here.
      var authUrl = new URL(initBody.authorizeUrl, cinatraOrigin + '/');
      if (authUrl.origin !== cinatraOrigin) { throw new Error('Refusing off-origin sign-in.'); }

      pkce = { codeVerifier: verifier, state: state, popup: null };

      var popup = window.open(authUrl.href, 'cinatra-login',
        'width=460,height=640,menubar=no,toolbar=no,location=yes,status=no');
      if (!popup) {
        pkce = null;
        throw new Error('Pop-up blocked. Allow pop-ups for this site and try again.');
      }
      pkce.popup = popup;
      watchPopupClosed();
    } catch (err) {
      clearHandshake();
      loginErr.textContent = (err && err.message) ? err.message : 'Sign-in failed.';
      loginBtn.disabled = false;
    }
  }

  // Redeem the authorization code for the opaque cwu_ user token via the broker,
  // then swap to conversation mode (which mounts the iframe). Single-use: the
  // in-flight pkce is consumed immediately.
  async function redeemCode(code) {
    var local = pkce;
    clearHandshake();                      // consume handshake + stop popup watch
    loginBtn.disabled = true;
    try {
      var resp = await fetch(config.authTokenEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: brokerHeaders(),
        body: JSON.stringify({
          grantType: 'authorization_code',
          client: AUTH_CLIENT,
          agentSlug: AGENT_SLUG,
          code: code,
          codeVerifier: local.codeVerifier,
        }),
      });
      if (!resp.ok) { throw new Error('Sign-in could not be completed.'); }
      var body = await resp.json();
      // Validate the token shape before trusting it: opaque Bearer cwu_ token + TTL.
      if (!body || typeof body.token !== 'string' || body.token.indexOf('cwu_') !== 0) {
        throw new Error('Sign-in could not be completed.');
      }
      if (body.tokenType && String(body.tokenType).toLowerCase() !== 'bearer') {
        throw new Error('Sign-in could not be completed.');
      }
      var ttlSec = (typeof body.expiresIn === 'number' && body.expiresIn > 0) ? body.expiresIn : 900;
      if (ttlSec > 900) { ttlSec = 900; }   // clamp to the backend max (15-min TTL)
      userToken = { token: body.token, expiresAtMs: Date.now() + (ttlSec * 1000) };
      try { if (local.popup && !local.popup.closed) { local.popup.close(); } } catch (_) {}
      loginErr.textContent = '';
      enterConversation();
    } catch (err) {
      try { if (local && local.popup && !local.popup.closed) { local.popup.close(); } } catch (_) {}
      loginErr.textContent = (err && err.message) ? err.message : 'Sign-in failed.';
    } finally {
      loginBtn.disabled = false;
    }
  }

  // ---------------------------------------------------------------------------
  // §12 PARENT-SIDE BRIDGE — the host half of the parent↔iframe embed protocol.
  //
  // The iframe (`/embed/assistant`) is the SOLE session owner. This shell mints
  // the credentials, delivers them ONCE via BOOTSTRAP, and services the closed
  // set of iframe→parent uplinks. Every trust-boundary control is enforced here:
  // origin + source-window binding, schema/protocolVersion/nonce agreement, dual
  // monotonic seq, single-bootstrap-per-frame, apply_intent untrusted-selector
  // permission checks + bounded LRU dedup, and the resize clamp.
  // ---------------------------------------------------------------------------
  var iframeEl = null;          // the mounted embed iframe (null until conversation)
  var frameWindow = null;       // iframeEl.contentWindow captured at load
  var frameNonce = null;        // the READY nonce the frame minted (echoed in bootstrap)
  var correlationId = null;     // parent-minted CSPRNG id, echoed by every uplink
  var bootstrapped = false;     // single bootstrap per frame
  var inboundSeqLast = null;    // iframe->parent monotonic gate (READY seeds it)
  var outboundSeqLast = null;   // parent->iframe monotonic counter (bootstrap = 0)
  var appliedLru = [];          // §6f bounded seen apply-id LRU for this correlationId

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

  // ALWAYS an explicit origin, NEVER "*" (§6a outbound). Posts to the frame window.
  function postToFrame(message) {
    if (!frameWindow) return;
    frameWindow.postMessage(message, cinatraOrigin);
  }

  // §3a READY validator (pre-bootstrap; the ONLY message without a correlationId).
  function isValidReady(d) {
    return !!d && d.type === MSG.ready &&
      d.protocolVersion === EMBED_PROTOCOL_VERSION &&
      typeof d.nonce === 'string' && ID_PATTERN.test(d.nonce) &&
      typeof d.seq === 'number';
  }

  // §5 uplink common envelope validator (post-bootstrap): protocolVersion, the
  // echoed correlationId, and a monotonic seq for the iframe->parent direction.
  function validUplinkEnvelope(d) {
    if (!d || d.protocolVersion !== EMBED_PROTOCOL_VERSION) return false;
    if (typeof d.correlationId !== 'string' || d.correlationId !== correlationId) return false;
    if (!acceptInboundSeq(d.seq)) return false;
    return true;
  }

  // Synchronous read of the pre-minted cit_ site token from the in-memory cache
  // (mirrors getStreamToken's freshness check). Returns null if it is absent or
  // within ~10s of expiry. The cit_ token is pre-minted in enterConversation()
  // BEFORE the frame mounts, so this read is warm when READY arrives.
  function getCachedCitToken() {
    var now = Date.now();
    if (cachedToken && cachedToken.token && cachedToken.expiresAtMs - 10000 > now) {
      return cachedToken.token;
    }
    return null;
  }

  // §4: build the ONE BOOTSTRAP (the only credential carrier) — mint the
  // correlationId, echo the frame nonce, seq=0, relay cit_/cwu_. Pure builder: no
  // await, no I/O, so the caller can release it SYNCHRONOUSLY in the same task as
  // the READY message (see onBridgeMessage) — a same-origin navigation cannot
  // interleave within one synchronous task, so credentials can never reach a
  // document that navigated in mid-release.
  function buildBootstrap(nonce, citToken) {
    frameNonce = nonce;
    correlationId = mintCorrelationId();
    var ctx = buildContentContext();
    var cms = { instanceId: config.instanceId || '' };
    if (ctx.postId) { cms.resourceId = ctx.postId; }
    if (ctx.postType) { cms.resourceType = ctx.postType; }
    if (ctx.postStatus) { cms.status = ctx.postStatus; }
    return {
      type: MSG.bootstrap,
      protocolVersion: EMBED_PROTOCOL_VERSION,
      correlationId: correlationId,
      nonceEcho: frameNonce,
      seq: nextOutboundSeq(),              // parent->iframe counter starts at 0
      auth: {
        citToken: citToken,                // cit_ site transport token
        cwuToken: userToken.token,         // cwu_ per-user token
      },
      session: {
        threadId: correlationId,           // one thread per bootstrapped frame
        assistant: EMBED_ASSISTANT,        // == ?assistant, == cit_ bound kind
      },
      cms: cms,
    };
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
  // bootstrapped thread/instance, (4) dedups against a bounded LRU, THEN does the
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

  // The single inbound bridge listener — origin + source-window bound. Attached
  // when the iframe mounts and detached on teardown.
  function onBridgeMessage(event) {
    // (§6a) strict origin, BEFORE schema.
    if (event.origin !== cinatraOrigin) return;
    // (§6a-2) source-window binding, BEFORE schema — a sibling frame on the same
    // origin must never drive this bridge. Nullish source never matches.
    if (!frameWindow || event.source !== frameWindow) return;

    var d = event.data;
    if (!d || typeof d !== 'object' || typeof d.type !== 'string') return;

    if (d.type === MSG.ready) {
      // §4 READY → BOOTSTRAP, released SYNCHRONOUSLY in this same message task.
      // A second READY on a mounted (bootstrapped) session is IGNORED (single
      // bootstrap per frame; re-auth = reload the frame).
      if (bootstrapped) return;
      if (!isValidReady(d)) return;
      if (!userTokenValid()) { forceReLogin(); return; }
      // The cit_ token was PRE-MINTED in enterConversation() before this frame
      // mounted, so it is read from cache SYNCHRONOUSLY here — there is NO await
      // between receiving READY and posting the bootstrap. A same-origin
      // navigation of the frame cannot interleave within one synchronous task, so
      // credentials can never be released to a replacement document. If the cache
      // is unexpectedly cold (the pre-mint failed/expired) we reload via re-login
      // rather than mint-and-post across an await (which would reopen that gap).
      var citToken = getCachedCitToken();
      if (!citToken) { forceReLogin('Your session expired. Please sign in again.'); return; }
      // Seed the iframe->parent monotonic gate with READY's seq (§6c); post-
      // bootstrap uplinks must strictly increase from it.
      if (!acceptInboundSeq(d.seq)) return;
      // Set the single-bootstrap latch BEFORE posting so a re-entrant delivery
      // cannot double-bootstrap. event.source === frameWindow was already verified
      // above, so this posts to the exact document that sent READY.
      bootstrapped = true;
      postToFrame(buildBootstrap(d.nonce, citToken));
      return;
    }

    // All other messages are post-bootstrap uplinks: require an established
    // correlationId + a monotonic seq for the iframe->parent direction.
    if (!bootstrapped) return;
    if (!validUplinkEnvelope(d)) return;

    if (d.type === MSG.resize) { handleResize(d.height); return; }
    if (d.type === MSG.focus) { handleFocus(d.focus); return; }
    if (d.type === MSG.a11y) { handleA11y(d.liveRegion, d.politeness); return; }
    if (d.type === MSG.applyIntent) { handleApplyIntent(d); return; }
    // Unknown type: dropped (the set is closed).
  }

  // Build the sandboxed embed iframe and attach the bridge listener. The src is
  // the Cinatra-served `/embed/assistant` route carrying only the NON-SECRET
  // disambiguators (instanceId, assistant). Tokens are NEVER in the URL — they
  // arrive only via BOOTSTRAP. The sandbox grants scripts + same-origin (the frame
  // needs its own origin's storage/streaming) but NOT top-navigation, forms,
  // popups, modals, downloads, or pointer-lock.
  function mountBridgeIframe() {
    if (iframeEl) return;
    var src = config.cinatraUrl + '/embed/assistant' +
      '?instanceId=' + encodeURIComponent(config.instanceId || '') +
      '&assistant=' + encodeURIComponent(EMBED_ASSISTANT);
    iframeEl = document.createElement('iframe');
    iframeEl.className = 'cw-frame';
    iframeEl.setAttribute('title', 'Cinatra assistant');
    iframeEl.setAttribute('sandbox', 'allow-scripts allow-same-origin');
    iframeEl.setAttribute('referrerpolicy', 'no-referrer');
    iframeEl.setAttribute('allow', '');
    // Keep the captured frame window current across loads (source-window binding +
    // outbound posts target exactly this frame). The bootstrap release does not
    // depend on this — it is synchronous with READY and posts to the verified
    // event.source — so a same-WindowProxy navigation cannot receive credentials.
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

  // Tear down the frame + bridge state (used on close-to-login / re-auth). The
  // NEXT conversation entry mounts a FRESH frame (single bootstrap per frame).
  function teardownBridge() {
    try { window.removeEventListener('message', onBridgeMessage); } catch (_) {}
    if (iframeEl && iframeEl.parentNode) {
      try { iframeEl.parentNode.removeChild(iframeEl); } catch (_) {}
    }
    iframeEl = null;
    frameWindow = null;
    frameNonce = null;
    correlationId = null;
    bootstrapped = false;
    inboundSeqLast = null;
    outboundSeqLast = null;
    appliedLru = [];
  }

  // Enter conversation mode: a valid cwu_ token is held. PRE-MINT the short-lived
  // cit_ site token BEFORE mounting the frame, so the READY→BOOTSTRAP release is
  // fully SYNCHRONOUS (getCachedCitToken reads it without an await) and no async
  // gap exists for a frame navigation to interleave. Only after the pre-mint
  // succeeds do we mount the frame (which then posts READY).
  function enterConversation() {
    if (!userTokenValid()) { forceReLogin(); return; }
    panelMode = 'conversation';
    applyPanelMode();
    getStreamToken().then(function () {
      // The user may have backed out / re-logged during the mint.
      if (panelMode !== 'conversation' || iframeEl) { return; }
      if (!userTokenValid()) { forceReLogin(); return; }
      mountBridgeIframe();
    }).catch(function (err) {
      forceReLogin((err && err.message) ? err.message : 'Could not start the assistant.');
    });
  }

  // ---------------------------------------------------------------------------
  // Open / collapse — circle↔widget swap
  // ---------------------------------------------------------------------------
  function openWidget() {
    isOpen = true;
    circle.style.zIndex = '9999990';
    setWidgetSize();
    cwWidget.style.display = 'block';
    // If a long-idle widget is reopened in conversation mode but the per-user
    // token has since expired, drop back to login rather than show a dead frame.
    if (panelMode === 'conversation' && !userTokenValid()) { forceReLogin(); }
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
  loginBtn.addEventListener('click', function() { startLogin(); });

  // ---------------------------------------------------------------------------
  // Required-login (cinatra#410): the hosted /widget-auth popup postMessage
  // listener. Bound three ways — the message must come from the configured
  // instance ORIGIN, carry the exact type, and its `state` must match the
  // in-flight PKCE tuple; we additionally bind to the popup window
  // (ev.source === pkce.popup) so no other same-origin window can complete it.
  // This is a SEPARATE listener from the bridge (which is bound to the iframe
  // window): the popup and the frame are distinct source windows.
  // ---------------------------------------------------------------------------
  window.addEventListener('message', function (ev) {
    if (ev.origin !== cinatraOrigin) { return; }
    var d = ev.data;
    if (!d || d.type !== 'cinatra-widget-auth' || typeof d.code !== 'string') { return; }
    if (!pkce) { return; }                              // no handshake in flight
    if (!pkce.popup || ev.source !== pkce.popup) { return; } // source binding (fail-closed)
    if (d.state !== pkce.state) { return; }             // CSRF/state binding
    redeemCode(d.code);                                 // single-use; pkce consumed inside
  });

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

  // Required-login (cinatra#410): render in login mode first (the iframe is not
  // mounted until a valid per-user token is held), so the conversation is never
  // shown pre-login.
  applyPanelMode();

  } // end mountWidget()

  // ---------------------------------------------------------------------------
  // Boot: capabilities is a HARD PREREQUISITE. Negotiate FIRST; mount ONLY on
  // success. On any failure we never attachShadow and never set
  // data-cinatra-mounted, so the always-visible fallback button remains as the
  // "instance unavailable / incompatible" chrome.
  // ---------------------------------------------------------------------------
  negotiateCapabilities().then(function (ok) {
    if (ok) { mountWidget(); }
    else {
      console.warn('[cinatra] capability negotiation failed — instance unavailable or incompatible; widget not mounted');
    }
  }).catch(function () {
    console.warn('[cinatra] capability negotiation error — instance unavailable; widget not mounted');
  });

})();
