// Standalone behavior tests for the vendored widget: the unconditional mount +
// the S5 (cinatra#1221) parent↔iframe embed bridge at PROTOCOL 2 (cinatra#2674).
//
// Runs under plain `node tests/test-widget-negotiation.mjs` — no jsdom, no
// bundler, no WordPress. Exit code 0 = all pass, 1 = a failure. Mirrors the
// spirit of tests/test-token-broker.php (a dependency-free behavior harness).
//
// WHAT PROTOCOL 2 CHANGED HERE, AND WHY MOST OF THIS FILE IS AN ABSENCE PROOF.
// This suite used to DRIVE the hosted-PKCE sign-in: click "Sign in with Cinatra",
// deliver the popup callback, watch the `cwu_` come back through the plugin's own
// broker, then assert the BOOTSTRAP carried `cit_` + `cwu_` into the frame. Every
// one of those assertions described the defect cinatra#2674 removes — the website
// being a party to the person's sign-in and a holder of their bearer.
//
// So they are FLIPPED, not deleted. The same drives now assert:
//   - the widget makes NO network request, ever (no broker, no relay, no mint);
//   - it opens NO window (the sign-in popup belongs to the frame);
//   - the ONE inbound message is `cinatra.embed.context` at version literal 2,
//     carrying PUBLIC SELECTORS and NO credential field of any kind;
//   - a protocol-1 READY cannot negotiate — no downgrade path back to a
//     credential-bearing bootstrap;
//   - a refused send happens exactly ONCE (no retry storm).
// The credential-EGRESS guarantee (nothing credential-shaped can leave, at any
// depth, with a positive control) is pinned separately in
// tests/test-no-credential-egress.mjs.
//
// Still covered, unchanged in substance: unconditional mount, origin +
// source-window binding, one context per frame, the resize clamp, apply_intent
// permission/XOR/LRU discipline with no egress and no reload (#1214), and the
// §12b port transport.

import fs from "node:fs";
import path from "node:path";
import vm from "node:vm";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const WIDGET_SRC = fs.readFileSync(
  path.join(__dirname, "..", "assets", "cinatra-widget.js"),
  "utf8",
);

const INSTANCE_ORIGIN = "https://instance.example";
const ID_PATTERN = /^[A-Za-z0-9_-]{22,128}$/;

let failures = 0;
function check(label, cond) {
  if (cond) {
    console.log(`  PASS  ${label}`);
  } else {
    console.log(`  FAIL  ${label}`);
    failures++;
  }
}

// ---------------------------------------------------------------------------
// Minimal DOM/window shim. Records attachShadow, the data-cinatra-mounted marker,
// the mounted iframe (sandbox attr + src + contentWindow), the frame window's
// postMessage sink (context capture), any window.open attempt (which must never
// happen), any fetch (which must never happen), and the WordPress wp.data
// invalidation sink (apply refresh).
// ---------------------------------------------------------------------------
function makeEnv(fetchImpl, sharedRoot, captured, configOverrides, windowOverrides) {
  let attachShadowCount = 0;
  const messageListeners = []; // window 'message' listeners (the bridge only)
  const openedWindows = [];    // MUST stay empty: the frame owns the sign-in popup

  function makeStubEl(isRoot, tag) {
    const el = {
      _tag: tag || "div",
      style: {},
      dataset: {},
      shadowRoot: null,
      classList: { add() {}, remove() {}, contains() { return false; } },
      attributes: {},
      children: [],
      parentNode: null,
      _clickHandlers: [],
      _loadHandlers: [],
      set className(v) {
        this._className = v;
        if (captured) { captured.byClass[v] = this; }
      },
      get className() { return this._className; },
      set textContent(v) { this._textContent = v; },
      get textContent() { return this._textContent; },
      setAttribute(k, v) {
        this.attributes[k] = v;
        if (captured && this._tag === "iframe" && k === "src") { captured.iframeSrc = v; }
      },
      getAttribute(k) { return this.attributes[k]; },
      appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
      removeChild(c) {
        const i = this.children.indexOf(c);
        if (i !== -1) this.children.splice(i, 1);
        c.parentNode = null;
        return c;
      },
      addEventListener(type, handler) {
        if (type === "click") {
          this._clickHandlers.push(handler);
          if (captured) { captured.clickHandlers.push(handler); }
        } else if (type === "load") {
          this._loadHandlers.push(handler);
        }
      },
      removeEventListener() {},
      querySelector() { return null; },
      attachShadow() {
        attachShadowCount++;
        const sh = makeStubEl(false);
        if (isRoot) { this.shadowRoot = sh; }
        return sh;
      },
      focus() {},
      getBoundingClientRect() { return { left: 0, top: 0, width: 0, height: 0 }; },
    };
    if ((tag || "div") === "iframe") {
      // The frame window: the bridge captures it as `frameWindow` and posts the
      // CONTEXT message to it (addressed to the Cinatra origin). Record every post.
      el.contentWindow = {
        postMessage(msg, targetOrigin) {
          if (captured) { captured.windowPosts.push({ msg, targetOrigin }); }
        },
      };
      if (captured) { captured.iframeEl = el; }
    }
    return el;
  }

  const rootEl = sharedRoot || makeStubEl(true);

  const documentStub = {
    getElementById(id) { return id === "cinatra-root" ? rootEl : null; },
    createElement(tag) { return makeStubEl(false, tag); },
    createElementNS() { return makeStubEl(false, "svg"); },
    querySelector() { return null; },
    addEventListener() {},
    head: makeStubEl(),
    body: makeStubEl(),
    readyState: "complete",
  };

  const sandbox = {
    window: {
      CinatraConfig: Object.assign(
        {
          cinatraUrl: INSTANCE_ORIGIN,
          instanceId: "i1",
          siteId: "site_123",
          mcpAdapterActive: true,
        },
        configOverrides || {},
      ),
      innerWidth: 1280,
      innerHeight: 800,
      location: { href: "https://site.example/wp-admin/", reload() {} },
      typenow: "post",
      addEventListener(type, handler) { if (type === "message") { messageListeners.push(handler); } },
      removeEventListener(type, handler) {
        if (type === "message") {
          const i = messageListeners.indexOf(handler);
          if (i !== -1) messageListeners.splice(i, 1);
        }
      },
      // Present but forbidden: the widget must never call this. Recorded so the
      // "no popup on the CMS origin" assertion has something to be false about.
      open(url) { const w = { url, closed: false, close() { this.closed = true; } }; openedWindows.push(w); return w; },
      crypto: {
        getRandomValues(arr) { for (let i = 0; i < arr.length; i++) { arr[i] = (i * 7 + 3) & 0xff; } return arr; },
      },
    },
    document: documentStub,
    console,
    fetch: fetchImpl,
    setTimeout: () => { return 0; },
    clearTimeout: () => {},
    setInterval: () => { return 0; },
    clearInterval: () => {},
    btoa: (s) => Buffer.from(s, "binary").toString("base64"),
    TextEncoder,
    Object, Array, JSON, Promise, Date, Math, String, Number, Uint8Array, isFinite,
    URL,
  };
  Object.assign(sandbox.window, windowOverrides || {});
  sandbox.crypto = sandbox.window.crypto;
  sandbox.window.document = documentStub;
  sandbox.globalThis = sandbox;
  return {
    sandbox, rootEl,
    attachShadowCount: () => attachShadowCount,
    messageListeners, openedWindows,
  };
}

async function flush(n) { for (let i = 0; i < (n || 20); i++) { await Promise.resolve(); } }

function newCaptured() {
  return { clickHandlers: [], byClass: {}, iframeEl: null, iframeSrc: null, windowPosts: [], invalidations: [] };
}

// Boot the IIFE and settle the microtask queue. The shell mounts synchronously;
// `fetchImpl` is a spy so a test can assert the shell issued NO request at all.
async function boot(fetchImpl, sharedRoot) {
  const captured = newCaptured();
  const env = makeEnv(fetchImpl, sharedRoot, captured);
  vm.runInNewContext(WIDGET_SRC, env.sandbox, { filename: "cinatra-widget.js" });
  await flush();
  return {
    env, captured,
    mounted: env.rootEl.dataset.cinatraMounted === "true",
    attachShadow: env.attachShadowCount() > 0,
    attachShadowCount: env.attachShadowCount(),
  };
}

// The WordPress editor data layer: canUser (edit permission), getCurrentPostId +
// getEditedPostAttribute (canonical resource), invalidateResolution (the in-place
// draft refresh sink). The apply path must route through THIS, never egress.
function installWpDataLayer(env, captured) {
  env.sandbox.window.wp = {
    data: {
      select(store) {
        if (store === "core") { return { canUser() { return true; } }; }
        if (store === "core/editor") {
          return { getCurrentPostId() { return 5; }, getEditedPostAttribute() { return "draft"; } };
        }
        return {};
      },
      dispatch(store) {
        if (store === "core") {
          return { invalidateResolution(sel, args) { captured.invalidations.push({ sel, args }); } };
        }
        return {};
      },
    },
  };
}

// Boot a fresh widget, OPEN the panel (a plain user gesture — the frame mounts
// lazily on first open, so a third-party iframe never loads on an admin page the
// person did not ask it to), and return everything a bridge drive needs. There is
// no login prefix any more: the frame decides whether the person is signed in.
async function bootOpenedBridge(configOverrides, windowOverrides) {
  const fetched = [];
  const fetchImpl = (url) => { fetched.push(String(url)); return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}), text: () => Promise.resolve("") }); };
  const captured = newCaptured();
  const env = makeEnv(fetchImpl, undefined, captured, configOverrides, windowOverrides);
  installWpDataLayer(env, captured);
  vm.runInNewContext(WIDGET_SRC, env.sandbox, { filename: "cinatra-widget.js" });
  await flush();
  const preOpenIframe = captured.iframeEl;
  const circle = captured.byClass["cw-circle"];
  for (const h of (circle ? circle._clickHandlers : [])) { try { h({}); } catch (_) {} }
  await flush();
  const iframe = captured.iframeEl;
  const frameWin = iframe && iframe.contentWindow;
  const deliverToWindow = (ev) => { for (const l of env.messageListeners) { try { l(ev); } catch (_) {} } };
  return { env, captured, fetched, preOpenIframe, iframe, frameWin, deliverToWindow };
}

// A synchronous MessagePort double for the §12b transport: records posts (a port
// send carries NO targetOrigin), supports onmessage-assignment (a real port
// implicitly starts on onmessage set) + addEventListener/start/close, and lets a
// test simulate the iframe posting an uplink to the parent over the entangled port.
function makePortStub() {
  const port = {
    posts: [],
    onmessage: null,
    closed: false,
    _listeners: [],
    postMessage(msg) { port.posts.push(msg); },
    addEventListener(t, fn) { if (t === "message") port._listeners.push(fn); },
    removeEventListener(t, fn) { if (t === "message") { const i = port._listeners.indexOf(fn); if (i !== -1) port._listeners.splice(i, 1); } },
    start() {},
    close() { port.closed = true; },
    _fromIframe(data) {
      const ev = { data };
      if (typeof port.onmessage === "function") port.onmessage(ev);
      for (const l of port._listeners.slice()) l(ev);
    },
  };
  return port;
}

const readyMsg = (nonce, seq) => ({
  type: "cinatra.embed.ready",
  protocolVersion: 2,
  nonce,
  seq: seq || 0,
});

async function main() {
  console.log("widget unconditional mount + §12 embed bridge (protocol 2)");

  // -------------------------------------------------------------------------
  // UNCONDITIONAL MOUNT. The shell runs no pre-flight (the AG-UI capability
  // handshake is the iframe's, against the unified broker) and no sign-in
  // (cinatra#2674, the frame's). So it mounts on boot and asks the network for
  // nothing whatsoever.
  // -------------------------------------------------------------------------
  {
    const fetches = [];
    const r = await boot((url) => { fetches.push(String(url)); return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) }); });
    check("boot -> MOUNTS unconditionally (attachShadow)", r.mounted && r.attachShadow);
    check(
      "boot -> makes NO network request at all (no capabilities, no token broker, no sign-in relay)",
      fetches.length === 0,
    );
    check("boot -> mounts NO iframe before the panel is opened", !r.captured.iframeEl);
    check("boot -> opens NO window (the sign-in popup belongs to the frame)", r.env.openedWindows.length === 0);
    check(
      "boot -> installs exactly ONE window message listener (the bridge; no auth-popup listener)",
      r.env.messageListeners.length === 0,
    );
  }
  {
    // Nothing to abort on: even a fetch implementation that rejects every call
    // cannot affect a shell that never calls it.
    const r = await boot(() => Promise.reject(new Error("network down")));
    check("every network call rejects -> STILL MOUNTS (nothing is fetched)", r.mounted && r.attachShadow);
  }
  {
    const first = await boot(() => Promise.resolve({ ok: true, json: () => Promise.resolve({}) }));
    const second = await boot(() => Promise.resolve({ ok: true, json: () => Promise.resolve({}) }), first.env.rootEl);
    check(
      "duplicate include -> mounts exactly once (attachShadow called once total)",
      first.mounted && first.env.rootEl.dataset.cinatraMounted === "true" &&
        (first.attachShadowCount + second.attachShadowCount) === 1,
    );
  }

  // -------------------------------------------------------------------------
  // §12 BRIDGE — one end-to-end drive over the WINDOW transport.
  // -------------------------------------------------------------------------
  {
    const drive = await bootOpenedBridge();
    const { env, captured, fetched, preOpenIframe, iframe, frameWin, deliverToWindow } = drive;

    // (1) The frame mounts on the first OPEN, not at boot, and it is sandboxed,
    //     framed at /embed/assistant with the public disambiguators only.
    const src = captured.iframeSrc || "";
    const sandboxAttr = iframe ? iframe.getAttribute("sandbox") : "";
    const sandboxTokens = String(sandboxAttr || "").split(/\s+/).filter(Boolean);
    const srcOk = src.indexOf(INSTANCE_ORIGIN + "/embed/assistant") === 0 &&
      src.indexOf("instanceId=i1") !== -1 && src.indexOf("assistant=wordpress") !== -1;
    const noTokenInUrl = src.indexOf("cit_") === -1 && src.indexOf("cwu_") === -1 &&
      src.toLowerCase().indexOf("token") === -1;
    check(
      "open -> iframe framed at /embed/assistant (public disambiguators only, nothing token-shaped)",
      !preOpenIframe && !!iframe && srcOk && noTokenInUrl,
    );
    // The sandbox is asserted as an EXACT token set, in both directions. The two
    // popup grants are REQUIRED at protocol 2 — the frame calls window.open() for
    // the hosted sign-in, and without them nobody can ever sign in — while every
    // escalation the frame does not need stays DENIED. Asserting the set rather
    // than a substring means both a silent removal and a silent widening are red.
    const REQUIRED_SANDBOX = [
      "allow-scripts",
      "allow-same-origin",
      "allow-popups",                   // the frame opens the hosted sign-in
      "allow-popups-to-escape-sandbox", // ...as an ORDINARY top-level Cinatra window
    ];
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
    check(
      "open -> the sandbox grants EXACTLY the four tokens the frame-owned sign-in needs",
      sandboxTokens.length === REQUIRED_SANDBOX.length &&
        REQUIRED_SANDBOX.every((t) => sandboxTokens.includes(t)),
    );
    check(
      "open -> the sandbox grants NO other escalation (no top-nav/forms/modals/downloads)",
      FORBIDDEN_SANDBOX.every((t) => !sandboxTokens.includes(t)),
    );
    check("open -> still no network request and still no window opened", fetched.length === 0 && env.openedWindows.length === 0);

    // (2) READY from a SPOOFED origin / SPOOFED source-window is IGNORED.
    deliverToWindow({ origin: "https://evil.example", source: frameWin, data: readyMsg("nonce0123456789abcdef012") });
    deliverToWindow({ origin: INSTANCE_ORIGIN, source: { not: "the frame" }, data: readyMsg("nonce0123456789abcdef012") });
    await flush();
    check("bridge: READY from wrong origin OR wrong source-window is IGNORED (nothing posted)", captured.windowPosts.length === 0);

    // (3) A PROTOCOL-1 READY cannot negotiate. This is the migration rule made
    //     testable: there is no downgrade path from protocol 2 back to the
    //     credential-bearing bootstrap, so an unmigrated frame simply gets
    //     nothing rather than a v1 exchange.
    deliverToWindow({
      origin: INSTANCE_ORIGIN,
      source: frameWin,
      data: { type: "cinatra.embed.ready", protocolVersion: 1, nonce: "v1Nonce0123456789abcdef0", seq: 0 },
    });
    await flush();
    check("bridge: a PROTOCOL-1 READY is REFUSED (no downgrade to the retired bootstrap)", captured.windowPosts.length === 0);

    // (4) A well-formed READY -> ONE CONTEXT message to the EXACT Cinatra origin
    //     (never "*"), echoing the nonce, seq 0, carrying PUBLIC SELECTORS and NO
    //     credential field.
    const nonce = "nonce0123456789abcdef012";
    deliverToWindow({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsg(nonce) });
    await flush(30);
    const posts = captured.windowPosts;
    const post = posts[0];
    const cmsg = post && post.msg;
    const envelopeOk = posts.length === 1 && !!cmsg &&
      cmsg.type === "cinatra.embed.context" &&
      cmsg.protocolVersion === 2 &&
      post.targetOrigin === INSTANCE_ORIGIN && post.targetOrigin !== "*" &&
      ID_PATTERN.test(cmsg.correlationId) &&
      cmsg.nonceEcho === nonce &&
      cmsg.seq === 0;
    const selectorsOk = !!cmsg &&
      cmsg.site && cmsg.site.siteId === "site_123" &&
      cmsg.session && cmsg.session.assistant === "wordpress" && ID_PATTERN.test(cmsg.session.threadId) &&
      cmsg.cms && cmsg.cms.instanceId === "i1" && cmsg.cms.resourceId === "5" &&
      cmsg.cms.resourceType === "post" && cmsg.cms.status === "draft";
    check("bridge: READY -> ONE CONTEXT to the exact origin (nonce echo, seq 0, protocol 2)", envelopeOk);
    check("bridge: the CONTEXT carries the public selectors (site, session, cms)", selectorsOk);

    // (5) THE ABSENCE PROOF. The message that used to carry `auth.citToken` +
    //     `auth.cwuToken` carries no credential field at all — asserted on the
    //     WHOLE serialized envelope, so a credential smuggled under any key name
    //     or nesting fails here too.
    const serialized = JSON.stringify(cmsg || {});
    check(
      "bridge: the CONTEXT carries NO credential field (no auth block, no cit_/cwu_/cnx_ anywhere)",
      !!cmsg && cmsg.auth === undefined &&
        serialized.indexOf("citToken") === -1 && serialized.indexOf("cwuToken") === -1 &&
        !/cwu_|cit_|cnx_/i.test(serialized),
    );
    // Nothing about the CMS page's own fields may be token-shaped either — the
    // only keys present are the closed protocol-2 selector set.
    const topKeys = Object.keys(cmsg || {}).sort().join(",");
    check(
      "bridge: the CONTEXT top-level key set is exactly the protocol-2 envelope",
      topKeys === "cms,correlationId,nonceEcho,protocolVersion,seq,session,site,type",
    );

    const correlationId = cmsg && cmsg.correlationId;

    // (6) One context per frame: a SECOND READY does not re-send.
    deliverToWindow({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsg("secondNonce0123456789abc") });
    await flush();
    check("bridge: one context per frame (a second READY is ignored)", captured.windowPosts.length === 1);

    // (7) resize: an in-range height ABOVE the panel cap is CLAMPED (not trusted);
    //     a height OVER the schema max is REJECTED. maxPanelHeight() ==
    //     innerHeight(800) - 120 == 680; RESIZE_MAX_HEIGHT == 20000.
    const cwWidget = captured.byClass["cw-widget"];
    deliverToWindow({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.resize", protocolVersion: 2, correlationId, seq: 1, height: 5000 } });
    await flush();
    const clampedH = cwWidget && parseInt(String(cwWidget.style.height || "0"), 10);
    check("bridge: in-range resize height above the cap is CLAMPED (<= 680px)", typeof clampedH === "number" && clampedH > 0 && clampedH <= 680);
    deliverToWindow({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.resize", protocolVersion: 2, correlationId, seq: 2, height: 999999 } });
    await flush();
    const afterOverMax = cwWidget && parseInt(String(cwWidget.style.height || "0"), 10);
    check("bridge: over-schema-max resize height (>20000) is REJECTED (height unchanged)", afterOverMax === clampedH);

    // (8) apply_intent (untrusted selector) -> ONE in-place draft refresh via the
    //     WP data layer; a DUPLICATE id is deduped (LRU); a WRONG correlationId is
    //     ignored; and NO content-egress fetch is made.
    const fetchCountBeforeApply = fetched.length;
    const applyMsg = (seq, id) => ({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.apply_intent", protocolVersion: 2, correlationId, seq, viewType: "content_change_proposal", proposalId: id } });
    deliverToWindow(applyMsg(3, "prop-A"));
    await flush();
    deliverToWindow(applyMsg(4, "prop-A"));      // duplicate id -> LRU dedup
    await flush();
    deliverToWindow({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.apply_intent", protocolVersion: 2, correlationId: "WRONGcorrelationId012345", seq: 5, viewType: "content_change_proposal", proposalId: "prop-B" } });
    await flush();
    const oneRefresh = captured.invalidations.length === 1 &&
      captured.invalidations[0].sel === "getEntityRecord";
    check("bridge: apply_intent -> ONE in-place draft refresh (dup id + wrong correlationId ignored)", oneRefresh);
    check("bridge: apply_intent does NOT egress (no fetch on apply — #1214)", fetched.length === fetchCountBeforeApply);

    // (9) a DIFFERENT proposal id refreshes again (proves it was dedup, not a
    //     one-shot latch).
    deliverToWindow(applyMsg(6, "prop-C"));
    await flush();
    check("bridge: a new proposal id refreshes again (dedup, not a one-shot latch)", captured.invalidations.length === 2);

    // (10) presence-XOR: an apply carrying BOTH selector keys (one empty) is
    //      REJECTED (matches the core presence-XOR schema), so no refresh fires.
    const beforeBoth = captured.invalidations.length;
    deliverToWindow({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.apply_intent", protocolVersion: 2, correlationId, seq: 7, viewType: "content_change_proposal", proposalId: "", changeSetId: "cs-1" } });
    await flush();
    check("bridge: apply carrying BOTH selector keys is rejected (presence-XOR, no refresh)", captured.invalidations.length === beforeBoth);

    // (11) The whole drive stayed offline and popup-free.
    check("bridge: the whole drive issued NO network request and opened NO window", fetched.length === 0 && env.openedWindows.length === 0);
  }

  // -------------------------------------------------------------------------
  // NO SITE HANDLE CONFIGURED. `site.siteId` is an OPTIONAL disambiguator (a site
  // connected before the handle was persisted has none), so the message is sent
  // WITHOUT the block rather than with an empty one — an empty string would fail
  // the frame's strict schema and take the whole session down over a field the
  // frame does not need.
  // -------------------------------------------------------------------------
  {
    const { captured, frameWin, deliverToWindow } = await bootOpenedBridge({ siteId: "" });
    deliverToWindow({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsg("noSiteNonce0123456789ab") });
    await flush(30);
    const cmsg = captured.windowPosts[0] && captured.windowPosts[0].msg;
    check(
      "no site handle -> the CONTEXT omits `site` entirely (never an empty siteId)",
      !!cmsg && cmsg.site === undefined && cmsg.cms.instanceId === "i1",
    );
  }

  // -------------------------------------------------------------------------
  // NO INSTANCE ID CONFIGURED. `cms.instanceId` is REQUIRED and non-empty in the
  // protocol-2 schema and must agree with the frame's `?instanceId`. With none
  // configured the frame is not mounted at all: framing a surface that would
  // reject its own context is worse than not framing it, and the launcher chrome
  // still tells the site owner the plugin is there. Repeated opens do not repeat
  // the attempt.
  // -------------------------------------------------------------------------
  {
    const { captured, env, iframe } = await bootOpenedBridge({ instanceId: "" });
    const circle = captured.byClass["cw-circle"];
    for (let i = 0; i < 4; i++) {
      for (const h of (circle ? circle._clickHandlers : [])) { try { h({}); } catch (_) {} }
      await flush();
    }
    check(
      "no instance id -> the frame is NOT mounted and NOTHING is posted, across repeated opens",
      !iframe && !captured.iframeEl && captured.windowPosts.length === 0 && env.messageListeners.length === 0,
    );
  }

  // -------------------------------------------------------------------------
  // THE INBOUND CREDENTIAL GUARD (codex round 0, finding 2). The frame is the one
  // party that HOLDS a bearer, so an uplink is a place a frame bug could put one
  // — and `a11y.liveRegion` is written straight into the admin page's live
  // region. An inbound envelope carrying a credential shape is dropped whole, on
  // BOTH transports, before any field of it is read.
  // -------------------------------------------------------------------------
  {
    const { captured, frameWin, deliverToWindow } = await bootOpenedBridge();
    deliverToWindow({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsg("inboundNonce012345678ab") });
    await flush(30);
    const correlationId = captured.windowPosts[0].msg.correlationId;
    const a11yEl = captured.byClass["cw-a11y-live"];

    // A clean a11y uplink IS mirrored — the positive control for the two drops
    // below, so "nothing happened" cannot be mistaken for a broken path.
    deliverToWindow({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.a11y", protocolVersion: 2, correlationId, seq: 1, liveRegion: "The assistant is thinking.", politeness: "polite" } });
    await flush();
    check("inbound: a clean a11y uplink IS mirrored into the live region (positive control)",
      a11yEl && a11yEl.textContent === "The assistant is thinking.");

    // A bearer in the live-region string is DROPPED — the DOM keeps the previous
    // text, so the credential never lands on the CMS page.
    deliverToWindow({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.a11y", protocolVersion: 2, correlationId, seq: 2, liveRegion: "Sign-in failed for cwu_abcdef — retry", politeness: "polite" } });
    await flush();
    check("inbound: an a11y uplink carrying a bearer is DROPPED (nothing reaches the CMS DOM)",
      a11yEl && a11yEl.textContent === "The assistant is thinking." &&
        String(a11yEl.textContent).indexOf("cwu_") === -1);

    // Nested and key-side too, and a lookalike still passes.
    deliverToWindow({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.a11y", protocolVersion: 2, correlationId, seq: 3, liveRegion: "Citation added.", politeness: "polite", } });
    await flush();
    check("inbound: a lookalike (`Citation added.`) is NOT dropped",
      a11yEl && a11yEl.textContent === "Citation added.");
  }
  {
    // The same guard on the PORT transport, where uplinks skip the origin recheck.
    const { captured, frameWin, deliverToWindow } = await bootOpenedBridge();
    const port = makePortStub();
    deliverToWindow({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsg("portGuardNonce01234567"), ports: [port] });
    await flush(30);
    const correlationId = port.posts[0].correlationId;
    const cwWidget = captured.byClass["cw-widget"];
    port._fromIframe({ type: "cinatra.embed.resize", protocolVersion: 2, correlationId, seq: 1, height: 5000 });
    await flush();
    const clean = cwWidget && parseInt(String(cwWidget.style.height || "0"), 10);
    // A poisoned uplink on the port must change nothing — including the seq gate,
    // which never sees it.
    port._fromIframe({ type: "cinatra.embed.resize", protocolVersion: 2, correlationId, seq: 2, height: 300, note: "cit_leaked" });
    await flush();
    const after = cwWidget && parseInt(String(cwWidget.style.height || "0"), 10);
    check("inbound: a PORT uplink carrying a bearer is DROPPED (the clean one before it still applied)",
      clean === 680 && after === 680);
  }

  // -------------------------------------------------------------------------
  // PROTOCOL-2 SELECTOR BOUNDS (codex round 0, finding 4). The frame's schema is
  // strict, so one over-long optional field would reject the whole message and
  // strand the session in "waiting for host". An out-of-bounds OPTIONAL selector
  // is omitted; an out-of-bounds REQUIRED instance id means no frame at all.
  // -------------------------------------------------------------------------
  {
    // An over-long OPTIONAL selector in every optional slot at once: the site
    // handle, the post type (max 200) and the post status (max 64). All three are
    // dropped and the message still goes, carrying the selectors that are valid.
    const drive2 = await bootOpenedBridge(
      { siteId: "s".repeat(201) },
      { typenow: "t".repeat(201) },
    );
    // EVERY optional slot at once, resourceId included — a bound that no test
    // poisons is a bound that can be deleted without turning anything red.
    drive2.env.sandbox.window.wp.data.select = (store) => {
      if (store === "core") return { canUser: () => true };
      if (store === "core/editor") {
        return {
          getCurrentPostId: () => "r".repeat(201),      // cms.resourceId max 200
          getEditedPostAttribute: () => "s".repeat(65), // cms.status max 64
        };
      }
      return {};
    };
    drive2.deliverToWindow({ origin: INSTANCE_ORIGIN, source: drive2.frameWin, data: readyMsg("boundsNonce0123456789a") });
    await flush(30);
    const msg = drive2.captured.windowPosts[0] && drive2.captured.windowPosts[0].msg;
    check(
      "bounds: every over-long OPTIONAL selector is OMITTED, and the message still sends",
      !!msg && msg.site === undefined && msg.cms.resourceId === undefined &&
        msg.cms.resourceType === undefined && msg.cms.status === undefined &&
        msg.cms.instanceId === "i1",
    );
    // Omitted, never truncated: a truncated id is a different id.
    check(
      "bounds: nothing is truncated into a different selector value",
      !!msg && !/tttttttttt|rrrrrrrrrr|ssssssssss/.test(JSON.stringify(msg)),
    );
    // And each bound is exact rather than approximate: a value AT the bound still
    // travels, so the check is a bound and not a blanket refusal of long values.
    const drive2b = await bootOpenedBridge({ siteId: "s".repeat(200) }, { typenow: "t".repeat(200) });
    drive2b.env.sandbox.window.wp.data.select = (store) => {
      if (store === "core") return { canUser: () => true };
      if (store === "core/editor") {
        return { getCurrentPostId: () => "r".repeat(200), getEditedPostAttribute: () => "s".repeat(64) };
      }
      return {};
    };
    drive2b.deliverToWindow({ origin: INSTANCE_ORIGIN, source: drive2b.frameWin, data: readyMsg("atBoundNonce012345678a") });
    await flush(30);
    const atBound = drive2b.captured.windowPosts[0] && drive2b.captured.windowPosts[0].msg;
    check(
      "bounds: a selector EXACTLY at its bound still travels (a bound, not a blanket refusal)",
      !!atBound && atBound.site.siteId.length === 200 &&
        atBound.cms.resourceId.length === 200 && atBound.cms.resourceType.length === 200 &&
        atBound.cms.status.length === 64,
    );
  }
  {
    const drive3 = await bootOpenedBridge({ instanceId: "i".repeat(201) });
    check("bounds: an over-long instance id means NO frame at all (nothing to strand)",
      !drive3.iframe && drive3.captured.windowPosts.length === 0);
  }

  // -------------------------------------------------------------------------
  // §12b PORT-BOUND TRANSPORT. When the iframe transfers a MessageChannel endpoint
  // on READY (event.ports[0]), the parent RETAINS it and sends the CONTEXT message
  // over THAT PORT — never a window post. Steady-state uplinks then ride the same
  // port; a window-delivered uplink is IGNORED (the transport cannot be
  // split/downgraded).
  // -------------------------------------------------------------------------
  {
    const { captured, frameWin, deliverToWindow } = await bootOpenedBridge();
    const nonce = "portNonce0123456789abcd";
    const port = makePortStub();
    deliverToWindow({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsg(nonce), ports: [port] });
    await flush(30);

    // (1) The context rode the PORT (no targetOrigin), NOT the window.
    const cmsg = port.posts[0];
    const portContextOk =
      port.posts.length === 1 &&
      captured.windowPosts.length === 0 &&
      !!cmsg &&
      cmsg.type === "cinatra.embed.context" &&
      cmsg.protocolVersion === 2 &&
      ID_PATTERN.test(cmsg.correlationId) &&
      cmsg.nonceEcho === nonce &&
      cmsg.seq === 0 &&
      cmsg.auth === undefined &&
      !/cwu_|cit_|cnx_/i.test(JSON.stringify(cmsg)) &&
      cmsg.session && cmsg.session.assistant === "wordpress";
    check("§12b: transferred port -> CONTEXT rides the PORT, credential-free, NO window post", portContextOk);

    const correlationId = cmsg && cmsg.correlationId;
    const cwWidget = captured.byClass["cw-widget"];

    // (2) A PORT-delivered uplink (resize) is handled -> the panel is clamped.
    port._fromIframe({ type: "cinatra.embed.resize", protocolVersion: 2, correlationId, seq: 1, height: 5000 });
    await flush();
    const clampedH = cwWidget && parseInt(String(cwWidget.style.height || "0"), 10);
    check("§12b: a PORT-delivered uplink is handled (resize clamped to 680px)", clampedH === 680);

    // (3) A WINDOW-delivered uplink in PORT mode is IGNORED (no transport split):
    //     height 100 would clamp to MIN_PANEL_HEIGHT (260) if wrongly processed;
    //     it stays 680 because the window path is inert once a port is bound.
    deliverToWindow({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.resize", protocolVersion: 2, correlationId, seq: 2, height: 100 } });
    await flush();
    const afterWindowUplink = cwWidget && parseInt(String(cwWidget.style.height || "0"), 10);
    check("§12b: a WINDOW-delivered uplink in PORT mode is IGNORED (height unchanged)", afterWindowUplink === 680);

    // (4) One context per frame holds on the port transport: a second READY (even
    //     with a fresh port) does not re-send.
    deliverToWindow({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsg("portNonce2ndabcdefghij0"), ports: [makePortStub()] });
    await flush();
    check("§12b: one context per frame holds on the port transport (second READY ignored)", port.posts.length === 1 && captured.windowPosts.length === 0);
  }

  console.log(failures === 0 ? "\nALL PASS" : `\n${failures} FAILURE(S)`);
  process.exit(failures === 0 ? 0 : 1);
}

main();
