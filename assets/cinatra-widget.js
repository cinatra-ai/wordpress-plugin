// SPDX-License-Identifier: Apache-2.0
//
// Cinatra WordPress assistant widget — vendored, locally-served bundle.
//
// This file is a human-readable vendored copy of the Cinatra widget IIFE that
// the Cinatra instance previously served from `/api/wordpress/bundle.js`
// (cinatra-ai/cinatra: src/app/api/wordpress/bundle.js/route.ts). It is shipped
// locally so the plugin never remote-loads executable code into wp-admin and so
// the long-lived integration key never reaches the browser (see wp#4 / cinatra#220).
//
// Modifications from the upstream bundle:
//   * The long-lived `apiKey` is removed from the browser. The widget exchanges
//     a short-lived, origin/audience/scope-bound token via the same-origin
//     WordPress REST broker (`CinatraConfig.tokenEndpoint`) and streams with it.
//   * Capability + contract-version negotiation against the instance
//     `/capabilities` endpoint at boot, with graceful fallback.
//   * Brand-token / logo `${...}` interpolations resolved to literal values
//     (the canonical source is cinatra-ai/cinatra: src/lib/cinatra-brand.ts).
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
  // Bootstrap
  // ---------------------------------------------------------------------------
  var config = window.CinatraConfig || {};
  // v2 contract: the browser holds NO long-lived key. It needs the instance URL
  // and a same-origin broker endpoint that mints short-lived stream tokens.
  if (!config.cinatraUrl || !config.tokenEndpoint) {
    console.warn('[cinatra] Missing CinatraConfig (cinatraUrl / tokenEndpoint)');
    return;
  }
  var rootEl = document.getElementById('cinatra-root');
  if (!rootEl) { console.warn('[cinatra] #cinatra-root not found'); return; }
  if (rootEl.dataset.cinatraMounted === 'true') return;
  rootEl.dataset.cinatraMounted = 'true';

  var AGENT_SLUG = 'wordpress-content-editor';
  // Contract versions this vendored widget understands, newest first.
  var CLIENT_CONTRACT_VERSIONS = ['v2', 'v1'];

  // Negotiated state (resolved by negotiateCapabilities() at boot).
  var negotiated = {
    contractVersion: config.contractVersion || 'v2',
    supportsTokenExchange: true,
    supportsChangesFrame: true,
    streamPath: '/api/agents/' + AGENT_SLUG + '/stream',
  };

  // ---------------------------------------------------------------------------
  // Shadow DOM
  // ---------------------------------------------------------------------------
  var shadow = rootEl.attachShadow({ mode: 'open' });

  // ---------------------------------------------------------------------------
  // CSS
  // Collapsed: single logo circle (position:fixed, bottom-right).
  // Expanded:  .cw-widget flex-column (panel on top, pill on bottom), same anchor.
  //            Drag the top-left corner (.cw-resize) to resize width + panel height.
  // ---------------------------------------------------------------------------
  var style = document.createElement('style');
  style.textContent = [
    ':host { all: initial; }',

    /* Collapsed logo circle — same size/position as the submit button inside the pill. */
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

    /* Expanded widget: position:fixed container, panel+pill both absolutely placed. */
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

    /* Response panel: absolute top of widget, fixed height set by JS */
    '.cw-panel {',
    '  position: absolute; top: 0; left: 0; right: 0;',
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

    /* Messages */
    '.cw-messages {',
    '  flex: 1; overflow-y: auto; padding: 16px;',
    '  display: flex; flex-direction: column; gap: 12px;',
    '}',
    '.cw-msg { line-height: 1.6; max-width: 88%; }',
    '.cw-msg-user {',
    '  align-self: flex-end; background: #15213a; color: #ffffff;',
    '  padding: 8px 14px; border-radius: 18px;',
    '  font: 14px system-ui, sans-serif; white-space: pre-wrap;',
    '}',
    '.cw-msg-assistant {',
    '  align-self: flex-start; color: #15213a;',
    '  font: 14px/1.6 system-ui, sans-serif;',
    '}',
    '.cw-msg-assistant p { margin: 4px 0; }',
    '.cw-msg-assistant p:first-child { margin-top: 0; }',
    '.cw-msg-assistant p:last-child { margin-bottom: 0; }',
    '.cw-msg-assistant h1,.cw-msg-assistant h2,.cw-msg-assistant h3 { font-weight:600; margin:12px 0 4px; line-height:1.3; }',
    '.cw-msg-assistant h1 { font-size:1.2em; }',
    '.cw-msg-assistant h2 { font-size:1.08em; }',
    '.cw-msg-assistant h3 { font-size:1em; }',
    '.cw-msg-assistant ul,.cw-msg-assistant ol { margin:6px 0; padding-left:20px; }',
    '.cw-msg-assistant li { margin:2px 0; }',
    '.cw-msg-assistant code { background:#f7f7f3; padding:1px 5px; border-radius:4px; font-family:ui-monospace,monospace; font-size:0.88em; }',
    '.cw-msg-assistant pre { background:#f7f7f3; padding:12px; border-radius:8px; overflow-x:auto; margin:8px 0; }',
    '.cw-msg-assistant pre code { background:none; padding:0; font-size:0.88em; }',
    '.cw-msg-assistant strong { font-weight:600; }',
    '.cw-msg-assistant em { font-style:italic; }',
    '.cw-msg-assistant a { color:#364e81; text-decoration:underline; }',
    '.cw-msg-assistant blockquote { border-left:3px solid #15213a14; padding-left:12px; margin:6px 0; color:#5a6477; }',
    '.cw-msg-assistant table { border-collapse:collapse; margin:8px 0; font-size:0.9em; }',
    '.cw-msg-assistant th,.cw-msg-assistant td { border:1px solid #15213a14; padding:6px 10px; text-align:left; }',
    '.cw-msg-assistant th { background:#f7f7f3; font-weight:600; }',
    '.cw-msg-assistant hr { border:none; border-top:1px solid #15213a14; margin:8px 0; }',
    /* Spacer at the bottom of the panel. */
    '.cw-messages-spacer { flex-shrink: 0; pointer-events: none; }',

    '.cw-thinking { display:flex; align-items:center; gap:8px; color:#5a6477; font-size:13px; }',
    '.cw-thinking-dot { position:relative; display:inline-flex; width:8px; height:8px; flex-shrink:0; }',
    '.cw-thinking-dot::before {',
    '  content:""; position:absolute; inset:0; border-radius:9999px;',
    '  background:#5a6477; opacity:0.75;',
    '  animation: cw-ping 1s cubic-bezier(0,0,0.2,1) infinite;',
    '}',
    '.cw-thinking-dot::after {',
    '  content:""; position:relative; display:inline-block;',
    '  width:8px; height:8px; border-radius:9999px; background:#5a6477;',
    '}',
    '.cw-thinking-label { font-weight:500; color:#5a6477; }',
    '@keyframes cw-ping {',
    '  75%, 100% { transform: scale(2); opacity: 0; }',
    '}',

    /* Prompt box: absolute bottom of widget, grows upward over the panel. */
    '.cw-pill {',
    '  position: absolute; bottom: 0; left: 0; right: 0;',
    '  background: #ffffff; border: 1px solid #15213a14; border-top: none;',
    '  border-radius: 0 0 16px 16px;',
    '  padding: 10px 12px;',
    '  display: flex; align-items: flex-end; gap: 10px;',
    '  box-sizing: border-box;',
    '  box-shadow: 0 4px 16px rgba(0,0,0,0.1);',
    '  z-index: 2;',
    '}',

    /* Flyout toggle (+). */
    '.cw-flyout-btn {',
    '  background: none; border: none; cursor: pointer; flex-shrink: 0;',
    '  color: #5a6477; font-size: 20px; line-height: 1;',
    '  font-family: system-ui, sans-serif; font-weight: 300;',
    '  padding: 0 2px; margin-bottom: 6px; display: flex; align-items: center;',
    '}',
    '.cw-flyout-btn:hover { color: #364e81; }',
    '.cw-flyout-btn:active { color: #2d416c; }',

    /* Textarea. */
    '.cw-textarea {',
    '  flex: 1; border: none; outline: none; resize: none;',
    '  font: 15px/1.5 system-ui, -apple-system, sans-serif;',
    '  background: transparent; color: #15213a;',
    '  min-height: 24px; overflow-y: hidden;',
    '  padding: 3px 0 0 0; margin: 0 0 4px;',
    '}',
    '.cw-textarea::placeholder { color: #5a6477; }',

    /* Submit circle inside the pill (bottom-right) */
    '.cw-submit {',
    '  width: 32px; height: 32px; border-radius: 9999px;',
    '  background: #364e81; border: none; cursor: pointer;',
    '  display: flex; align-items: center; justify-content: center;',
    '  flex-shrink: 0; transition: background 0.15s;',
    '}',
    '.cw-submit:hover { background: #2d416c; }',
    '.cw-submit:disabled { opacity: 0.4; cursor: not-allowed; }',

    /* Flyout menu */
    '.cw-flyout-menu {',
    '  position: absolute; bottom: calc(100% + 10px); left: 0;',
    '  background: #ffffff; border: 1px solid #15213a14;',
    '  border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.15);',
    '  padding: 6px; min-width: 180px; z-index: 10;',
    '}',
    '.cw-flyout-item {',
    '  display: block; width: 100%; box-sizing: border-box;',
    '  padding: 8px 12px; border: none; background: none;',
    '  text-align: left; font: 13px system-ui, sans-serif;',
    '  cursor: pointer; border-radius: 8px; color: #15213a; text-decoration: none;',
    '}',
    '.cw-flyout-item:hover { background: #e8e8e3; }',

    /* Deprecation notice banner (surfaced when the instance reports the legacy path). */
    '.cw-deprecation {',
    '  margin: 8px 16px 0; padding: 8px 12px; border-radius: 8px;',
    '  background: #fdf3da; border: 1px solid #e7c878; color: #6b531a;',
    '  font: 12px/1.45 system-ui, sans-serif;',
    '}',

    /* Diff card */
    '.cw-diff-card { align-self: flex-start; flex-shrink: 0; max-width: 88%; margin-top: 4px; margin-bottom: 4px; border: 1px solid #15213a14; border-radius: 10px; overflow: hidden; font: 13px system-ui, sans-serif; }',
    '.cw-diff-card-header { padding: 6px 12px; background: #f7f7f3; border-bottom: 1px solid #15213a14; font-weight: 600; color: #5a6477; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; }',
    '.cw-diff-row { display: flex; gap: 8px; padding: 6px 12px; align-items: baseline; min-width: 0; }',
    '.cw-diff-row + .cw-diff-row { border-top: 1px solid #15213a14; }',
    '.cw-diff-field { font-weight: 600; color: #15213a; white-space: nowrap; flex-shrink: 0; }',
    '.cw-diff-values { display: flex; flex-direction: column; gap: 2px; min-width: 0; overflow: hidden; }',
    '.cw-diff-before { color: #5a6477; text-decoration: line-through; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }',
    '.cw-diff-after { color: #364e81; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; }',
    '.cw-diff-footer { padding: 5px 12px; background: #e8e8e3; color: #5a6477; font-size: 11px; border-top: 1px solid #15213a14; }',
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

  function makeArrowSvg() {
    var svg = mkSvg(14, 14, '0 0 24 24');
    svg.appendChild(mkEl('path', { d: 'M12 19V5', stroke: 'white', 'stroke-width': '2.5', 'stroke-linecap': 'round' }));
    svg.appendChild(mkEl('path', { d: 'M5 12l7-7 7 7', stroke: 'white', 'stroke-width': '2.5', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }));
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
  // Circle drag-to-reposition: position helpers (session-only, no persistence)
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
  // DOM: expanded widget — panel + pill stacked
  // ---------------------------------------------------------------------------
  var currentWidth = 580;
  var currentPanelHeight = 440;
  var PILL_MIN_H = 46;
  var PILL_GAP = 16;

  function setWidgetSize() {
    cwWidget.style.width = currentWidth + 'px';
    cwWidget.style.height = (currentPanelHeight + PILL_GAP + PILL_MIN_H) + 'px';
    panel.style.height = (currentPanelHeight + PILL_GAP + Math.floor(PILL_MIN_H / 2)) + 'px';
    spacerEl.style.height = (PILL_GAP + Math.floor(PILL_MIN_H / 2)) + 'px';
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

  // Deprecation notice — shown only when the instance reports the legacy path
  // is in use (Deprecation/Sunset response headers). Hidden by default.
  var deprecationEl = document.createElement('div');
  deprecationEl.className = 'cw-deprecation';
  deprecationEl.style.display = 'none';
  panel.appendChild(deprecationEl);

  var messagesEl = document.createElement('div');
  messagesEl.className = 'cw-messages';
  messagesEl.setAttribute('role', 'log');
  messagesEl.setAttribute('aria-live', 'polite');
  panel.appendChild(messagesEl);

  var spacerEl = document.createElement('div');
  spacerEl.className = 'cw-messages-spacer';
  panel.appendChild(spacerEl);

  var pill = document.createElement('div');
  pill.className = 'cw-pill';
  cwWidget.appendChild(pill);

  var flyoutMenu = document.createElement('div');
  flyoutMenu.className = 'cw-flyout-menu';
  flyoutMenu.style.display = 'none';
  pill.appendChild(flyoutMenu);

  var clearItem = document.createElement('button');
  clearItem.className = 'cw-flyout-item';
  clearItem.type = 'button';
  clearItem.textContent = 'Clear conversation';
  flyoutMenu.appendChild(clearItem);

  var settingsItem = document.createElement('a');
  settingsItem.className = 'cw-flyout-item';
  settingsItem.href = (config.wpAdminUrl || '') + 'options-general.php?page=cinatra';
  settingsItem.textContent = 'Widget administration';
  settingsItem.target = '_blank';
  settingsItem.rel = 'noopener noreferrer';
  flyoutMenu.appendChild(settingsItem);

  var flyoutBtn = document.createElement('button');
  flyoutBtn.className = 'cw-flyout-btn';
  flyoutBtn.type = 'button';
  flyoutBtn.setAttribute('aria-label', 'Options');
  flyoutBtn.textContent = '+';
  pill.appendChild(flyoutBtn);

  var textarea = document.createElement('textarea');
  textarea.className = 'cw-textarea';
  textarea.placeholder = 'Ask Cinatra…';
  textarea.rows = 1;
  pill.appendChild(textarea);

  var submitBtn = document.createElement('button');
  submitBtn.className = 'cw-submit';
  submitBtn.type = 'button';
  submitBtn.disabled = true;
  submitBtn.appendChild(makeArrowSvg());
  pill.appendChild(submitBtn);

  // ---------------------------------------------------------------------------
  // State
  // ---------------------------------------------------------------------------
  var isOpen = false;
  var isFlyoutOpen = false;
  var isStreaming = false;
  var hadChanges = false;
  var diffCardEl = null;
  var pendingDiff = null;
  var deprecationShown = false;

  function trunc(s, n) { return s && s.length > n ? s.slice(0, n) + '…' : (s || ''); }

  function renderDiffCard(fields) {
    var card = document.createElement('div');
    card.className = 'cw-diff-card';
    var hdr = document.createElement('div');
    hdr.className = 'cw-diff-card-header';
    hdr.textContent = fields.length > 0 ? 'Changes applied' : 'Content updated';
    card.appendChild(hdr);
    if (fields.length === 0) {
      var note = document.createElement('div');
      note.className = 'cw-diff-row';
      note.style.cssText = 'color:#5a6477;font-size:12px;font-style:italic;';
      note.textContent = 'Field-level diff not available for rich content — reload will apply changes.';
      card.appendChild(note);
    }
    for (var fi = 0; fi < fields.length; fi++) {
      var f = fields[fi];
      var row = document.createElement('div');
      row.className = 'cw-diff-row';
      var fieldEl = document.createElement('span');
      fieldEl.className = 'cw-diff-field';
      fieldEl.textContent = (f.field || '') + ':';
      var vals = document.createElement('div');
      vals.className = 'cw-diff-values';
      if (f.before) {
        var bef = document.createElement('span');
        bef.className = 'cw-diff-before';
        bef.textContent = trunc(String(f.before), 80);
        vals.appendChild(bef);
      }
      var aft = document.createElement('span');
      aft.className = 'cw-diff-after';
      aft.textContent = trunc(String(f.after || '(removed)'), 80);
      vals.appendChild(aft);
      row.appendChild(fieldEl);
      row.appendChild(vals);
      card.appendChild(row);
    }
    return card;
  }

  function showDeprecationNotice() {
    if (deprecationShown) return;
    deprecationShown = true;
    deprecationEl.textContent =
      'Your Cinatra instance accepted a deprecated long-lived integration key directly. ' +
      'Ask your administrator to update Cinatra so the assistant uses short-lived tokens.';
    deprecationEl.style.display = 'block';
  }

  // ---------------------------------------------------------------------------
  // History
  // ---------------------------------------------------------------------------
  var _postIdForKey = (window.wp && window.wp.data && window.wp.data.select('core/editor') && window.wp.data.select('core/editor').getCurrentPostId && window.wp.data.select('core/editor').getCurrentPostId()) || (document.querySelector('#post_ID') && document.querySelector('#post_ID').value) || '';
  var HISTORY_KEY = 'cinatra_history_' + (config.instanceId || 'default') + '_' + (_postIdForKey || 'unknown');
  var history = [];
  try { var raw = window.sessionStorage.getItem(HISTORY_KEY); if (raw) history = JSON.parse(raw) || []; } catch (_) {}
  function saveHistory() { try { window.sessionStorage.setItem(HISTORY_KEY, JSON.stringify(history)); } catch (_) {} }

  // ---------------------------------------------------------------------------
  // Markdown — inline renderer (XSS-safe: all user text goes through esc()).
  // ---------------------------------------------------------------------------
  function renderMd(text) {
    if (!text) return '';
    function esc(s) {
      return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function inlineRender(s) {
      s = esc(s);
      s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');
      s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
      s = s.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
      s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
      return s;
    }
    var lines = text.split('\n');
    var html = '';
    var inCode = false, codeLines = [];
    var listType = null, listItems = [];
    function flushList() {
      if (!listItems.length) return;
      var tag = listType === 'ol' ? 'ol' : 'ul';
      html += '<' + tag + '>' + listItems.map(function(li) { return '<li>' + inlineRender(li) + '</li>'; }).join('') + '</' + tag + '>';
      listItems = []; listType = null;
    }
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      if (!inCode && /^```/.test(line)) { flushList(); inCode = true; codeLines = []; continue; }
      if (inCode) {
        if (/^```/.test(line)) { html += '<pre><code>' + esc(codeLines.join('\n')) + '</code></pre>'; inCode = false; codeLines = []; }
        else codeLines.push(line);
        continue;
      }
      var olM = line.match(/^(\d+)\.\s+(.*)/);
      var ulM = !olM && line.match(/^[-*]\s+(.*)/);
      if (olM) { if (listType !== 'ol') flushList(); listType = 'ol'; listItems.push(olM[2]); }
      else if (ulM) { if (listType !== 'ul') flushList(); listType = 'ul'; listItems.push(ulM[1]); }
      else {
        flushList();
        var hM = line.match(/^(#{1,3})\s+(.*)/);
        if (hM) { var lvl = Math.min(hM[1].length + 1, 4); html += '<h' + lvl + '>' + inlineRender(hM[2]) + '</h' + lvl + '>'; }
        else if (line.trim() === '') html += '';
        else html += '<p>' + inlineRender(line) + '</p>';
      }
    }
    if (inCode) html += '<pre><code>' + esc(codeLines.join('\n')) + '</code></pre>';
    flushList();
    return html;
  }

  // ---------------------------------------------------------------------------
  // Render message bubble
  // ---------------------------------------------------------------------------
  function renderMessage(role, content, asMarkdown) {
    var el = document.createElement('div');
    el.className = 'cw-msg cw-msg-' + role;
    if (asMarkdown) el.innerHTML = renderMd(content);
    else el.textContent = content;
    messagesEl.appendChild(el);
    messagesEl.scrollTop = messagesEl.scrollHeight;
    return el;
  }

  for (var i = 0; i < history.length; i++) {
    var h = history[i];
    renderMessage(h.role, h.content, h.role === 'assistant');
    if (h.diff && Array.isArray(h.diff)) {
      messagesEl.appendChild(renderDiffCard(h.diff));
    }
  }

  // ---------------------------------------------------------------------------
  // Open / collapse — circle↔widget swap
  // ---------------------------------------------------------------------------
  function openWidget() {
    isOpen = true;
    circle.style.zIndex = '9999990';
    setWidgetSize();
    cwWidget.style.display = 'block';
    textarea.focus();
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function collapseWidget() {
    isOpen = false;
    closeFlyout();
    cwWidget.style.display = 'none';
    circle.style.zIndex = '';
  }

  function closeFlyout() {
    isFlyoutOpen = false;
    flyoutMenu.style.display = 'none';
  }

  // ---------------------------------------------------------------------------
  // Auto-resize textarea
  // ---------------------------------------------------------------------------
  function resizeTextarea() {
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 'px';
    var pillH = pill.offsetHeight || PILL_MIN_H;
    var deltaH = Math.max(0, pillH - PILL_MIN_H);
    var spacerH = PILL_GAP + Math.floor(PILL_MIN_H / 2);
    panel.style.height = (currentPanelHeight + spacerH - deltaH) + 'px';
  }
  textarea.addEventListener('input', resizeTextarea);
  textarea.addEventListener('input', function () {
    if (!isStreaming) submitBtn.disabled = textarea.value.trim() === '';
  });

  // ---------------------------------------------------------------------------
  // Submit
  // ---------------------------------------------------------------------------
  function doSubmit() {
    var text = textarea.value.trim();
    if (text && !isStreaming) {
      textarea.value = '';
      resizeTextarea();
      sendMessage(text);
    }
  }

  circle.addEventListener('click', function(e) {
    if (circleDragMoved) { circleDragMoved = false; e.stopPropagation(); return; }
    if (isOpen) { collapseWidget(); } else { openWidget(); }
  });
  closeBtn.addEventListener('click', function() { collapseWidget(); });
  submitBtn.addEventListener('click', function() { doSubmit(); });

  textarea.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey && !isStreaming) {
      e.preventDefault();
      doSubmit();
    }
  });

  document.addEventListener('click', function(e) {
    if (!isOpen) return;
    var path = e.composedPath ? e.composedPath() : [];
    for (var p = 0; p < path.length; p++) { if (path[p] === rootEl) return; }
    collapseWidget();
  });

  flyoutBtn.addEventListener('click', function(e) {
    e.stopPropagation();
    isFlyoutOpen = !isFlyoutOpen;
    flyoutMenu.style.display = isFlyoutOpen ? 'block' : 'none';
  });

  clearItem.addEventListener('click', function() {
    history = []; saveHistory(); messagesEl.innerHTML = ''; closeFlyout();
  });

  // ---------------------------------------------------------------------------
  // Resize: drag top-left corner to adjust width (left) and panel height (up)
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
    currentWidth = Math.max(320, Math.min(window.innerWidth - 48, resizeStartWidth + dw));
    currentPanelHeight = Math.max(200, Math.min(window.innerHeight - 200, resizeStartPanelH + dh));
    setWidgetSize();
  });

  document.addEventListener('mouseup', function() {
    if (circleDragging) {
      circleDragging = false;
      circle.style.cursor = '';
    }
    resizeDragging = false;
  });

  // ---------------------------------------------------------------------------
  // Content editor context
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
  // Capability + contract-version negotiation (once at boot).
  // The /capabilities endpoint is auth-free and returns only static contract
  // metadata. A 404 (older instance) falls back to v1 + the long-lived flow.
  // ---------------------------------------------------------------------------
  function pickContractVersion(serverVersions) {
    for (var ci = 0; ci < CLIENT_CONTRACT_VERSIONS.length; ci++) {
      if (serverVersions.indexOf(CLIENT_CONTRACT_VERSIONS[ci]) !== -1) {
        return CLIENT_CONTRACT_VERSIONS[ci];
      }
    }
    return 'v1';
  }

  async function negotiateCapabilities() {
    try {
      var resp = await fetch(config.cinatraUrl + '/api/agents/' + AGENT_SLUG + '/capabilities', {
        method: 'GET',
        cache: 'no-store',
        signal: (typeof AbortSignal !== 'undefined' && AbortSignal.timeout) ? AbortSignal.timeout(5000) : undefined,
      });
      if (resp.status === 404) {
        // Older instance with no capabilities endpoint.
        negotiated.supportsTokenExchange = false;
        negotiated.contractVersion = 'v1';
        return;
      }
      if (!resp.ok) return; // keep optimistic defaults
      var data = await resp.json();
      var caps = (data && data.capabilities) || {};
      var serverVersions = (data && data.supportedContractVersions) || ['v1'];
      negotiated.contractVersion = pickContractVersion(serverVersions);
      negotiated.supportsTokenExchange = caps.supportsTokenExchange !== false;
      negotiated.supportsChangesFrame = caps.supportsChangesFrame !== false;
      if (typeof caps.streamPath === 'string' && caps.streamPath) {
        negotiated.streamPath = caps.streamPath;
      }
    } catch (_) {
      // Network error — keep optimistic defaults; the broker call will surface
      // any real failure with an actionable message.
    }
  }
  var capabilitiesReady = negotiateCapabilities();

  // ---------------------------------------------------------------------------
  // Short-lived token exchange via the same-origin WordPress REST broker.
  // The browser never holds the long-lived integration key; the broker (PHP)
  // holds it and performs the server-to-server exchange with the instance.
  // Tokens are cached in-memory and reused across turns until ~10s before expiry.
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
    if (!body || !body.token) {
      throw new Error('Cinatra session-token response was malformed.');
    }
    var ttlMs = (typeof body.expiresIn === 'number' ? body.expiresIn : 300) * 1000;
    cachedToken = { token: body.token, expiresAtMs: now + ttlMs };
    return body.token;
  }

  // ---------------------------------------------------------------------------
  // SSE streaming chat
  // ---------------------------------------------------------------------------
  async function sendMessage(userText) {
    hadChanges = false;
    diffCardEl = null;
    pendingDiff = null;
    history.push({ role: 'user', content: userText });
    renderMessage('user', userText, false);
    saveHistory();

    var assistantEl = document.createElement('div');
    assistantEl.className = 'cw-msg cw-msg-assistant cw-thinking';
    assistantEl.innerHTML = '<span class="cw-thinking-dot"></span><span class="cw-thinking-label">Thinking…</span>';
    messagesEl.appendChild(assistantEl);
    messagesEl.scrollTop = messagesEl.scrollHeight;

    var assistantText = '';
    isStreaming = true;
    submitBtn.disabled = true;

    try {
      // Ensure capability negotiation has resolved, then mint a short-lived token.
      try { await capabilitiesReady; } catch (_) {}

      // Un-upgraded instance: no token-exchange endpoint. The browser never holds
      // the long-lived key (that is the whole point of the broker), so we cannot
      // silently fall back to it client-side. Surface an actionable message
      // instead of firing a broker call that is guaranteed to fail.
      if (!negotiated.supportsTokenExchange) {
        showDeprecationNotice();
        assistantEl.classList.remove('cw-thinking');
        assistantEl.textContent =
          'This assistant needs a newer Cinatra instance (short-lived token support). ' +
          'Ask your administrator to update Cinatra, then reload this page.';
        return;
      }

      var token = await getStreamToken();

      var response = await fetch(config.cinatraUrl + negotiated.streamPath, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({
          contractVersion: negotiated.contractVersion,
          messages: history.map(function(m) { return { role: m.role, content: m.content }; }),
          context: buildContentContext(),
        }),
      });

      // Surface the instance's deprecation signal (long-lived key used somewhere
      // in the chain) so an admin can act. Frame format itself is unchanged.
      if (response.headers && response.headers.get && response.headers.get('Deprecation')) {
        showDeprecationNotice();
      }

      if (!response.ok || !response.body) {
        var errText = 'Error ' + response.status;
        try {
          var raw = await response.text();
          var parsed = null;
          try { parsed = JSON.parse(raw); } catch (_) {}
          if (parsed && parsed.error && parsed.error.message) { errText = parsed.error.message; }
          else { errText += ': ' + raw.slice(0, 200); }
        } catch (_) {}
        assistantEl.classList.remove('cw-thinking');
        assistantEl.textContent = errText;
        return;
      }

      var reader = response.body.getReader();
      var decoder = new TextDecoder();
      var buffer = '';
      var streamingStarted = false;

      while (true) {
        var chunk = await reader.read();
        if (chunk.done) break;
        buffer += decoder.decode(chunk.value, { stream: true });
        var records = buffer.split('\n\n');
        buffer = records.pop() || '';
        for (var r = 0; r < records.length; r++) {
          var lines = records[r].split('\n');
          var eventName = '', dataStr = '';
          for (var j = 0; j < lines.length; j++) {
            if (lines[j].indexOf('event: ') === 0) eventName = lines[j].slice(7).trim();
            else if (lines[j].indexOf('data: ') === 0) dataStr = lines[j].slice(6);
          }
          if (!eventName || !dataStr) continue;
          var data; try { data = JSON.parse(dataStr); } catch (_) { continue; }
          if (eventName === 'text' && data && typeof data.content === 'string') {
            if (!streamingStarted) { streamingStarted = true; assistantEl.classList.remove('cw-thinking'); }
            assistantText += data.content;
            assistantEl.innerHTML = renderMd(assistantText);
            messagesEl.scrollTop = messagesEl.scrollHeight;
          } else if (eventName === 'error' && data && data.message) {
            assistantEl.classList.remove('cw-thinking');
            assistantEl.textContent = 'Error: ' + data.message;
          } else if (eventName === 'changes' && data && Array.isArray(data.fields)) {
            if (!negotiated.supportsChangesFrame) { continue; }
            hadChanges = true;
            pendingDiff = data.fields;
            try {
              var wpKey = 'cinatra-wp-diff-' + (data.postId || '');
              window.sessionStorage.setItem(wpKey, JSON.stringify(data.fields));
            } catch (_) {}
            diffCardEl = renderDiffCard(data.fields);
            messagesEl.appendChild(diffCardEl);
            messagesEl.scrollTop = messagesEl.scrollHeight;
          } else if (eventName === 'done') {
            if (assistantText) assistantEl.innerHTML = renderMd(assistantText);
            if (data && data.fallback) { assistantText = ''; }
            if (hadChanges && !(data && data.fallback)) {
              if (diffCardEl) {
                var footer = document.createElement('div');
                footer.className = 'cw-diff-footer';
                footer.textContent = 'Reloading to apply changes…';
                diffCardEl.appendChild(footer);
                messagesEl.scrollTop = messagesEl.scrollHeight;
              }
              try { window.sessionStorage.setItem('cinatra-reopen', '1'); } catch (_) {}
              setTimeout(function() { window.location.reload(); }, 1500);
            }
          }
        }
      }

      if (assistantText) { history.push({ role: 'assistant', content: assistantText, diff: pendingDiff || undefined }); saveHistory(); }
      else if (!streamingStarted) { assistantEl.classList.remove('cw-thinking'); assistantEl.textContent = '(no response)'; }
    } catch (err) {
      assistantEl.classList.remove('cw-thinking');
      assistantEl.textContent = (err && err.message) ? err.message : 'Network error';
    } finally {
      isStreaming = false;
      submitBtn.disabled = textarea.value.trim() === '';
    }
  }

  // Reopen widget after an auto-reload triggered by a content edit.
  try {
    if (window.sessionStorage.getItem('cinatra-reopen') === '1') {
      window.sessionStorage.removeItem('cinatra-reopen');
      openWidget();
    }
  } catch (_) {}

})();
