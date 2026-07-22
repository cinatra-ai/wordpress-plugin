// Standalone behavior tests for the vendored widget: capability negotiation +
// the S5 (cinatra#1221) parent↔iframe embed bridge.
//
// Runs under plain `node tests/test-widget-negotiation.mjs` — no jsdom, no
// bundler, no WordPress. Exit code 0 = all pass, 1 = a failure. Mirrors the
// spirit of tests/test-token-broker.php (a dependency-free behavior harness).
//
// ARCHITECTURE (S5 cinatra#1221): the assistant conversation moved INTO a
// Cinatra-served `/embed/assistant` iframe. The widget no longer streams; it
// negotiates + logs in, then frames the embed page as the SOLE session owner and
// speaks the §12 parent-side bridge. So this harness covers, in one place:
//
//   NEGOTIATION (HARD PREREQUISITE — cinatra#220):
//     - /capabilities failure (HTTP not-ok / network / malformed)  -> NO MOUNT
//     - supportsTokenExchange !== true / missing tokenPath          -> NO MOUNT
//     - no mutually-supported contractVersion                       -> NO MOUNT
//     - healthy v2 instance (control)                               -> MOUNTS
//     - duplicate include                                           -> mounts once
//
//   REQUIRED-LOGIN GATE (cinatra#410): mounts in LOGIN mode; no iframe, no token,
//     no bootstrap until the hosted-PKCE handshake yields an opaque cwu_ token.
//
//   §12 BRIDGE (cinatra#1221): the iframe is sandboxed and framed at
//     /embed/assistant WITHOUT tokens in its URL; on READY the parent mints cit_
//     ONCE and posts a single BOOTSTRAP to the EXACT Cinatra origin (never "*")
//     carrying cit_/cwu_; origin + source-window binding rejects a spoofed READY;
//     single bootstrap per frame; resize is CLAMPED; apply_intent is permission-
//     checked, LRU-deduped, and routes through an IN-PLACE draft refresh (no
//     egress, no reload — #1214).

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
const TOKEN_ENDPOINT = "https://site.example/wp-json/cinatra/v1/token";
const AUTH_INIT = "https://site.example/wp-json/cinatra/v1/widget-auth/init";
const AUTH_TOKEN = "https://site.example/wp-json/cinatra/v1/widget-auth/token";
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
// the login handshake surfaces, and — new for the bridge — the mounted iframe
// (sandbox attr + src + contentWindow), the frame window's postMessage sink
// (BOOTSTRAP capture), and the WordPress wp.data invalidation sink (apply refresh).
// ---------------------------------------------------------------------------
function makeEnv(fetchImpl, sharedRoot, captured) {
  let attachShadowCount = 0;
  const messageListeners = [];   // window 'message' listeners (auth popup + bridge)
  const openedPopups = [];

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
      set placeholder(v) {
        this._placeholder = v;
        if (captured) { captured.textarea = this; }
      },
      get placeholder() { return this._placeholder; },
      set className(v) {
        this._className = v;
        if (captured) { captured.byClass[v] = this; }
      },
      get className() { return this._className; },
      set textContent(v) {
        this._textContent = v;
        if (captured && v === "Sign in with Cinatra") { captured.loginBtnEl = this; }
      },
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
      // BOOTSTRAP to it (addressed to the Cinatra origin). Record every post.
      el.contentWindow = {
        postMessage(msg, targetOrigin) {
          if (captured) { captured.bootstrapPosts.push({ msg, targetOrigin }); }
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
      CinatraConfig: {
        cinatraUrl: INSTANCE_ORIGIN,
        tokenEndpoint: TOKEN_ENDPOINT,
        authInitEndpoint: AUTH_INIT,
        authTokenEndpoint: AUTH_TOKEN,
        nonce: "test-nonce",
        instanceId: "i1",
      },
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
      open(url) { const popup = { url, closed: false, close() { this.closed = true; } }; openedPopups.push(popup); return popup; },
      crypto: {
        getRandomValues(arr) { for (let i = 0; i < arr.length; i++) { arr[i] = (i * 7 + 3) & 0xff; } return arr; },
        subtle: { async digest() { return new ArrayBuffer(32); } },
      },
    },
    document: documentStub,
    console,
    fetch: fetchImpl,
    setTimeout: (fn) => { return 0; },
    clearTimeout: () => {},
    setInterval: () => { return 0; },
    clearInterval: () => {},
    btoa: (s) => Buffer.from(s, "binary").toString("base64"),
    TextEncoder,
    AbortController: class {
      constructor() { this.signal = {}; }
      abort() {}
    },
    Object, Array, JSON, Promise, Date, Math, String, Number, Uint8Array, isFinite,
    URL,
    TextDecoder: class { decode() { return ""; } },
  };
  sandbox.crypto = sandbox.window.crypto;
  sandbox.window.document = documentStub;
  sandbox.globalThis = sandbox;
  return {
    sandbox, rootEl,
    attachShadowCount: () => attachShadowCount,
    messageListeners, openedPopups,
  };
}

function jsonResponse(status, body) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
    headers: { get() { return null; } },
  });
}

async function flush(n) { for (let i = 0; i < (n || 20); i++) { await Promise.resolve(); } }

// Boot the IIFE with a /capabilities behavior and settle the negotiation chain.
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

function newCaptured() {
  return { clickHandlers: [], textarea: null, loginBtnEl: null, byClass: {}, iframeEl: null, iframeSrc: null, bootstrapPosts: [], invalidations: [] };
}

const HEALTHY = {
  agentSlug: "wordpress-content-editor",
  contractVersion: "v2",
  supportedContractVersions: ["v1", "v2"],
  capabilities: {
    supportsTokenExchange: true,
    tokenPath: "/api/agents/wordpress-content-editor/token",
  },
};

async function main() {
  console.log("widget negotiation + §12 embed bridge");

  // -------------------------------------------------------------------------
  // NEGOTIATION (hard prerequisite).
  // -------------------------------------------------------------------------
  {
    const r = await boot(() => jsonResponse(200, HEALTHY));
    check("healthy v2 instance -> MOUNTS (control)", r.mounted && r.attachShadow);
  }
  {
    const r = await boot(() => jsonResponse(500, { error: "boom" }));
    check("/capabilities 5xx -> UNAVAILABLE (no mount, no attachShadow)", !r.mounted && !r.attachShadow);
  }
  {
    const r = await boot(() => jsonResponse(404, { error: "Unknown agent" }));
    check("/capabilities 404 -> UNAVAILABLE (no mount)", !r.mounted && !r.attachShadow);
  }
  {
    const r = await boot(() => Promise.reject(new Error("network down")));
    check("/capabilities network error -> UNAVAILABLE (no mount)", !r.mounted && !r.attachShadow);
  }
  {
    const r = await boot(() => Promise.resolve({
      ok: true, status: 200,
      json: () => Promise.reject(new SyntaxError("bad json")),
      text: () => Promise.resolve("<html>not json"),
      headers: { get() { return null; } },
    }));
    check("malformed JSON -> UNAVAILABLE (no mount)", !r.mounted && !r.attachShadow);
  }
  {
    const body = JSON.parse(JSON.stringify(HEALTHY));
    body.capabilities.supportsTokenExchange = false;
    const r = await boot(() => jsonResponse(200, body));
    check("supportsTokenExchange:false -> INCOMPATIBLE (no mount)", !r.mounted && !r.attachShadow);
  }
  {
    const body = JSON.parse(JSON.stringify(HEALTHY));
    delete body.capabilities.tokenPath;
    const r = await boot(() => jsonResponse(200, body));
    check("missing tokenPath -> INCOMPATIBLE (no mount)", !r.mounted && !r.attachShadow);
  }
  {
    const body = JSON.parse(JSON.stringify(HEALTHY));
    body.supportedContractVersions = ["v0", "v9"];
    const r = await boot(() => jsonResponse(200, body));
    check("no mutual contractVersion -> UNAVAILABLE (no mount)", !r.mounted && !r.attachShadow);
  }
  {
    const first = await boot(() => jsonResponse(200, HEALTHY));
    const second = await boot(() => jsonResponse(200, HEALTHY), first.env.rootEl);
    check(
      "duplicate include -> mounts exactly once (attachShadow called once total)",
      first.mounted && first.env.rootEl.dataset.cinatraMounted === "true" &&
        (first.attachShadowCount + second.attachShadowCount) === 1,
    );
  }

  // -------------------------------------------------------------------------
  // LOGIN GATE + §12 BRIDGE — one end-to-end drive.
  // -------------------------------------------------------------------------
  {
    let initState = null;
    const fetched = [];
    const fetchImpl = (url, opts) => {
      const u = String(url);
      const body = (opts && opts.body) ? JSON.parse(opts.body) : null;
      fetched.push({ url: u, method: (opts && opts.method) || "GET", headers: (opts && opts.headers) || {}, body });
      if (u.indexOf("/capabilities") !== -1) { return jsonResponse(200, HEALTHY); }
      if (u === AUTH_INIT) {
        initState = body && body.state;
        return jsonResponse(200, { txnId: "txn1", authorizeUrl: INSTANCE_ORIGIN + "/widget-auth?txn=txn1", instanceId: "i1" });
      }
      if (u === AUTH_TOKEN) { return jsonResponse(200, { token: "cwu_user-tok", tokenType: "Bearer", expiresIn: 900 }); }
      if (u === TOKEN_ENDPOINT) { return jsonResponse(200, { token: "cit_site-tok", expiresIn: 300 }); }
      return jsonResponse(200, {});
    };

    const captured = newCaptured();
    const env = makeEnv(fetchImpl, undefined, captured);
    // WordPress editor data layer: canUser (edit permission), getCurrentPostId +
    // getEditedPostAttribute (canonical resource), invalidateResolution (in-place
    // draft refresh sink). The apply path must route through THIS, never egress.
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
    vm.runInNewContext(WIDGET_SRC, env.sandbox, { filename: "cinatra-widget.js" });
    await flush();
    const mounted = env.rootEl.dataset.cinatraMounted === "true";

    // (1) Pre-login: the iframe is NOT mounted and no cit_ token is minted (the
    //     login gate holds — the conversation surface never appears token-less).
    const preLoginNoFrame = mounted && !captured.iframeEl;
    const preLoginNoToken = !fetched.some((f) => f.url === TOKEN_ENDPOINT);
    check("login gate: pre-login has NO iframe and NO cit_ mint (token-less conversation blocked)", preLoginNoFrame && preLoginNoToken);

    // (2) Drive the hosted-PKCE login: click sign-in, deliver the popup callback.
    let loggedIn = false;
    if (mounted && captured.loginBtnEl && captured.loginBtnEl._clickHandlers.length) {
      for (const h of captured.loginBtnEl._clickHandlers) { try { h({}); } catch (_) {} }
      await flush();
      const popup = env.openedPopups[env.openedPopups.length - 1];
      for (const listener of env.messageListeners) {
        try { listener({ origin: INSTANCE_ORIGIN, source: popup, data: { type: "cinatra-widget-auth", code: "auth-code-1", state: initState } }); } catch (_) {}
      }
      await flush(30);
      loggedIn = true;
    }
    const initPost = fetched.find((f) => f.url === AUTH_INIT && f.method === "POST");
    const tokenPost = fetched.find((f) => f.url === AUTH_TOKEN && f.method === "POST");
    const initNonceOk = !!initPost && initPost.headers["X-WP-Nonce"] === "test-nonce";
    const tokenNonceOk = !!tokenPost && tokenPost.headers["X-WP-Nonce"] === "test-nonce";
    const verifierSent = !!tokenPost && tokenPost.body && typeof tokenPost.body.codeVerifier === "string" && tokenPost.body.codeVerifier.length > 0;
    check(
      "login handshake: init+token relayed to OUR broker (same-origin) with X-WP-Nonce + PKCE verifier",
      loggedIn && initNonceOk && tokenNonceOk && verifierSent,
    );

    // (3) Post-login: the sandboxed iframe is mounted at /embed/assistant, with
    //     the disambiguators but WITHOUT any token in the URL.
    const iframe = captured.iframeEl;
    const src = captured.iframeSrc || "";
    const sandboxAttr = iframe ? iframe.getAttribute("sandbox") : "";
    const sandboxOk = sandboxAttr === "allow-scripts allow-same-origin";
    const srcOk = src.indexOf(INSTANCE_ORIGIN + "/embed/assistant") === 0 &&
      src.indexOf("instanceId=i1") !== -1 && src.indexOf("assistant=wordpress") !== -1;
    const noTokenInUrl = src.indexOf("cit_") === -1 && src.indexOf("cwu_") === -1 && src.toLowerCase().indexOf("token") === -1;
    check("post-login: sandboxed iframe framed at /embed/assistant (disambiguators only, NO token in URL)", !!iframe && sandboxOk && srcOk && noTokenInUrl);

    // (4) READY from a SPOOFED origin / SPOOFED source-window is IGNORED (no
    //     bootstrap, no cit_ mint). Origin + source-window binding.
    const frameWin = iframe && iframe.contentWindow;
    const readyMsg = { type: "cinatra.embed.ready", protocolVersion: 1, nonce: "nonce0123456789abcdef012", seq: 0 };
    function deliverToBridge(ev) { for (const l of env.messageListeners) { try { l(ev); } catch (_) {} } }
    deliverToBridge({ origin: "https://evil.example", source: frameWin, data: readyMsg });
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: { not: "the frame" }, data: readyMsg });
    await flush();
    // cit_ is pre-minted at enterConversation (before the frame mounts), so the
    // security property here is: NO BOOTSTRAP is posted for a spoofed READY.
    check("bridge: READY from wrong origin OR wrong source-window is IGNORED (no bootstrap posted)", captured.bootstrapPosts.length === 0);

    // (5) A well-formed READY from the real frame -> ONE bootstrap posted to the
    //     EXACT Cinatra origin (never "*"), echoing the nonce, seq 0, carrying
    //     cit_/cwu_; cit_ minted exactly once.
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsg });
    await flush(30);
    const posts = captured.bootstrapPosts;
    const post = posts[0];
    const bmsg = post && post.msg;
    const bootstrapOk = posts.length === 1 && !!bmsg &&
      bmsg.type === "cinatra.embed.bootstrap" &&
      bmsg.protocolVersion === 1 &&
      post.targetOrigin === INSTANCE_ORIGIN && post.targetOrigin !== "*" &&
      ID_PATTERN.test(bmsg.correlationId) &&
      bmsg.nonceEcho === readyMsg.nonce &&
      bmsg.seq === 0 &&
      bmsg.auth && bmsg.auth.citToken === "cit_site-tok" && bmsg.auth.cwuToken === "cwu_user-tok" &&
      bmsg.session && bmsg.session.assistant === "wordpress" && ID_PATTERN.test(bmsg.session.threadId) &&
      bmsg.cms && bmsg.cms.instanceId === "i1" && bmsg.cms.resourceId === "5" && bmsg.cms.resourceType === "post";
    const cit_mints = fetched.filter((f) => f.url === TOKEN_ENDPOINT).length;
    check("bridge: READY -> ONE BOOTSTRAP to the exact origin (nonce echo, seq 0, cit_+cwu_), cit_ minted once", bootstrapOk && cit_mints === 1);

    const correlationId = bmsg && bmsg.correlationId;

    // (6) Single bootstrap per frame: a SECOND READY does not re-bootstrap.
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.ready", protocolVersion: 1, nonce: "secondNonce0123456789abc", seq: 0 } });
    await flush();
    check("bridge: single bootstrap per frame (a second READY is ignored)", captured.bootstrapPosts.length === 1);

    // (7) resize: an in-range height ABOVE the panel cap is CLAMPED (not trusted);
    //     a height OVER the schema max is REJECTED. maxPanelHeight() ==
    //     innerHeight(800) - 120 == 680; RESIZE_MAX_HEIGHT == 20000.
    const cwWidget = captured.byClass["cw-widget"];
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.resize", protocolVersion: 1, correlationId, seq: 1, height: 5000 } });
    await flush();
    const clampedH = cwWidget && parseInt(String(cwWidget.style.height || "0"), 10);
    check("bridge: in-range resize height above the cap is CLAMPED (<= 680px)", typeof clampedH === "number" && clampedH > 0 && clampedH <= 680);
    // Over the schema max -> rejected: the height is unchanged from the clamp above.
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.resize", protocolVersion: 1, correlationId, seq: 2, height: 999999 } });
    await flush();
    const afterOverMax = cwWidget && parseInt(String(cwWidget.style.height || "0"), 10);
    check("bridge: over-schema-max resize height (>20000) is REJECTED (height unchanged)", afterOverMax === clampedH);

    // (8) apply_intent (untrusted selector) -> ONE in-place draft refresh via the
    //     WP data layer; a DUPLICATE id is deduped (LRU); a WRONG correlationId is
    //     ignored; and NO content-egress fetch is made.
    const fetchCountBeforeApply = fetched.length;
    const applyMsg = (seq, id) => ({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.apply_intent", protocolVersion: 1, correlationId, seq, viewType: "content_change_proposal", proposalId: id } });
    deliverToBridge(applyMsg(3, "prop-A"));
    await flush();
    deliverToBridge(applyMsg(4, "prop-A"));      // duplicate id -> LRU dedup
    await flush();
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.apply_intent", protocolVersion: 1, correlationId: "WRONGcorrelationId012345", seq: 5, viewType: "content_change_proposal", proposalId: "prop-B" } });
    await flush();
    const oneRefresh = captured.invalidations.length === 1 &&
      captured.invalidations[0].sel === "getEntityRecord";
    const noApplyEgress = fetched.length === fetchCountBeforeApply; // no fetch at all on apply
    check("bridge: apply_intent -> ONE in-place draft refresh (dup id + wrong correlationId ignored)", oneRefresh);
    check("bridge: apply_intent does NOT egress (no fetch on apply — #1214)", noApplyEgress);

    // (9) a DIFFERENT proposal id refreshes again (proves it was dedup, not a
    //     one-shot latch).
    deliverToBridge(applyMsg(6, "prop-C"));
    await flush();
    check("bridge: a new proposal id refreshes again (dedup, not a one-shot latch)", captured.invalidations.length === 2);

    // (10) presence-XOR: an apply carrying BOTH selector keys (one empty) is
    //      REJECTED (matches the core presence-XOR schema), so no refresh fires.
    const beforeBoth = captured.invalidations.length;
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.apply_intent", protocolVersion: 1, correlationId, seq: 7, viewType: "content_change_proposal", proposalId: "", changeSetId: "cs-1" } });
    await flush();
    check("bridge: apply carrying BOTH selector keys is rejected (presence-XOR, no refresh)", captured.invalidations.length === beforeBoth);
  }

  // -------------------------------------------------------------------------
  // SYNCHRONOUS BOOTSTRAP RELEASE (source-level, defense-in-depth). A frame's
  // WindowProxy identity is STABLE across navigations, so no post-await window
  // check can be fully airtight. The design instead removes the async gap: the
  // cit_ token is PRE-MINTED before the frame mounts, and the READY->BOOTSTRAP
  // release reads it from the synchronous cache and posts in the SAME message
  // task (no await) — a same-origin navigation cannot interleave within one
  // synchronous task. This lives in closure-private state (unreachable from the
  // sandbox), so — as the harness already does for the login/mint ordering — we
  // pin the security-relevant STRUCTURE against the widget source.
  {
    const ec = WIDGET_SRC.indexOf("function enterConversation");
    const ecRegion = ec === -1 ? "" : WIDGET_SRC.slice(ec, ec + 900);
    const preMintsBeforeMount =
      /getStreamToken\s*\(\)/.test(ecRegion) &&
      /mountBridgeIframe\s*\(/.test(ecRegion) &&
      ecRegion.indexOf("getStreamToken") < ecRegion.indexOf("mountBridgeIframe");
    const syncRelease =
      /function\s+getCachedCitToken/.test(WIDGET_SRC) &&
      /bootstrapped\s*=\s*true;\s*\n?\s*postToFrame\s*\(\s*buildBootstrap/.test(WIDGET_SRC);
    check("frame-safety: cit_ pre-minted before mount AND READY->BOOTSTRAP released synchronously from cache (no async gap)", preMintsBeforeMount && syncRelease);
  }

  console.log(failures === 0 ? "\nALL PASS" : `\n${failures} FAILURE(S)`);
  process.exit(failures === 0 ? 0 : 1);
}

main();
