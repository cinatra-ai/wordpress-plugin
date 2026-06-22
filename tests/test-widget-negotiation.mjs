// Standalone behavior tests for the vendored widget's capability negotiation.
//
// Runs under plain `node tests/test-widget-negotiation.mjs` — no jsdom, no
// bundler, no WordPress. Exit code 0 = all pass, 1 = a failure. Mirrors the
// spirit of tests/test-token-broker.php (a dependency-free behavior harness).
//
// Covers the "drop the old-instance fallback, keep negotiation" contract
// (cinatra#220): /capabilities is a HARD PREREQUISITE. The widget mounts ONLY
// when negotiation succeeds + validates; on ANY failure it never attaches its
// Shadow DOM and never sets data-cinatra-mounted (so the always-visible
// fallback button stays as the unavailable/incompatible chrome).
//
//   - /capabilities failure (HTTP not-ok / network)  -> UNAVAILABLE (no mount)
//   - missing required field (no streamPath)          -> INCOMPATIBLE (no mount)
//   - no mutually-supported contractVersion           -> UNAVAILABLE (no mount)
//   - healthy v2 instance (control)                   -> MOUNTS

import fs from "node:fs";
import path from "node:path";
import vm from "node:vm";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const WIDGET_SRC = fs.readFileSync(
  path.join(__dirname, "..", "assets", "cinatra-widget.js"),
  "utf8",
);

let failures = 0;
function check(label, cond) {
  if (cond) {
    console.log(`  PASS  ${label}`);
  } else {
    console.log(`  FAIL  ${label}`);
    failures++;
  }
}

// Minimal DOM/window shim that records whether attachShadow ran and whether
// data-cinatra-mounted was set. Just enough for the IIFE to boot through
// negotiation; once attachShadow returns a stub the rest of mountWidget runs.
function makeEnv(fetchImpl, sharedRoot, captured) {
  let attachShadowCount = 0;
  // Required-login (cinatra#410): the login handshake needs Web Crypto, btoa,
  // window.open, and a captured window 'message' listener. The send-happy-path
  // test drives a real login through these before streaming.
  const messageListeners = [];
  const openedPopups = [];

  function makeStubEl(isRoot) {
    const el = {
      style: {},
      dataset: {},
      shadowRoot: null,
      classList: { add() {}, remove() {}, contains() { return false; } },
      attributes: {},
      children: [],
      // `_pendingClick` lets the optional `captured` harness drive a real send:
      // any click handler registered on this element is remembered and exposed
      // via captured.clickHandlers (see createElement below).
      _clickHandlers: [],
      set placeholder(v) {
        this._placeholder = v;
        // The widget's prompt textarea is the only element with a placeholder;
        // remember it so a test can set .value and trigger a send.
        if (captured) { captured.textarea = this; }
      },
      get placeholder() { return this._placeholder; },
      // Identify the login button by its label so a test can fire ONLY its click
      // handler (the send-happy-path drives login then send distinctly).
      set textContent(v) {
        this._textContent = v;
        if (captured && v === "Sign in with Cinatra") { captured.loginBtnEl = this; }
      },
      get textContent() { return this._textContent; },
      setAttribute(k, v) { this.attributes[k] = v; },
      getAttribute(k) { return this.attributes[k]; },
      appendChild(c) { this.children.push(c); return c; },
      addEventListener(type, handler) {
        if (type === "click") {
          this._clickHandlers.push(handler);
          if (captured) { captured.clickHandlers.push(handler); }
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
    return el;
  }

  const rootEl = sharedRoot || makeStubEl(true);

  const documentStub = {
    getElementById(id) { return id === "cinatra-root" ? rootEl : null; },
    createElement() { return makeStubEl(); },
    createElementNS() { return makeStubEl(); },
    querySelector() { return null; },
    addEventListener() {},
    head: makeStubEl(),
    body: makeStubEl(),
    readyState: "complete",
  };

  const storage = (() => {
    const m = new Map();
    return {
      getItem(k) { return m.has(k) ? m.get(k) : null; },
      setItem(k, v) { m.set(k, String(v)); },
      removeItem(k) { m.delete(k); },
    };
  })();

  const sandbox = {
    window: {
      CinatraConfig: {
        cinatraUrl: "https://instance.example",
        tokenEndpoint: "https://site.example/wp-json/cinatra/v1/token",
        // Required-login broker relays (cinatra#410).
        authInitEndpoint: "https://site.example/wp-json/cinatra/v1/widget-auth/init",
        authTokenEndpoint: "https://site.example/wp-json/cinatra/v1/widget-auth/token",
        nonce: "test-nonce",
        instanceId: "i1",
      },
      sessionStorage: storage,
      innerWidth: 1280,
      innerHeight: 800,
      location: { href: "https://site.example/wp-admin/", reload() {} },
      // Capture 'message' listeners so a test can deliver a hosted-auth postMessage.
      addEventListener(type, handler) { if (type === "message") { messageListeners.push(handler); } },
      removeEventListener() {},
      // window.open returns a fake popup the widget tracks; record it.
      open(url) { const popup = { url, closed: false, close() { this.closed = true; } }; openedPopups.push(popup); return popup; },
      // crypto.subtle.digest yields a deterministic 32-byte buffer (the challenge
      // value is opaque to this test — only the handshake flow matters).
      crypto: {
        getRandomValues(arr) { for (let i = 0; i < arr.length; i++) { arr[i] = (i * 7 + 3) & 0xff; } return arr; },
        subtle: { async digest() { return new ArrayBuffer(32); } },
      },
    },
    document: documentStub,
    console,
    fetch: fetchImpl,
    setTimeout: (fn) => { return 0; }, // negotiation timer never needs to fire here
    clearTimeout: () => {},
    setInterval: () => { return 0; },  // popup-close watch never needs to fire here
    clearInterval: () => {},
    btoa: (s) => Buffer.from(s, "binary").toString("base64"),
    TextEncoder,
    AbortController: class {
      constructor() { this.signal = {}; }
      abort() {}
    },
    Object,
    Array,
    JSON,
    Promise,
    Date,
    Math,
    String,
    Uint8Array,
    URL, // WHATWG URL — used by the widget's same-origin streamPath resolution.
    TextDecoder: class { decode() { return ""; } },
  };
  // The widget references bare `crypto` / `btoa` (not window.*) in places; mirror.
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

// Run the IIFE in a fresh sandbox with the given /capabilities fetch behavior,
// then wait a microtask tick for the negotiation promise chain to settle.
async function boot(fetchImpl, sharedRoot) {
  const env = makeEnv(fetchImpl, sharedRoot);
  vm.runInNewContext(WIDGET_SRC, env.sandbox, { filename: "cinatra-widget.js" });
  // Flush the promise microtask queue so negotiate().then(...) runs.
  for (let i = 0; i < 20; i++) { await Promise.resolve(); }
  return {
    env,
    mounted: env.rootEl.dataset.cinatraMounted === "true",
    attachShadow: env.attachShadowCount() > 0,
    attachShadowCount: env.attachShadowCount(),
  };
}

const HEALTHY = {
  agentSlug: "wordpress-content-editor",
  contractVersion: "v2",
  supportedContractVersions: ["v1", "v2"],
  minContractVersion: "v1",
  maxContractVersion: "v2",
  capabilities: {
    supportsChangesFrame: true,
    supportsMarkdown: true,
    supportsTokenExchange: true,
    sseFrames: ["text", "changes", "error", "done"],
    streamPath: "/api/agents/wordpress-content-editor/stream",
    tokenPath: "/api/agents/wordpress-content-editor/token",
  },
};

async function main() {
  console.log("widget capability negotiation (hard prerequisite)");

  // Control: a healthy v2 instance mounts.
  {
    const r = await boot(() => jsonResponse(200, HEALTHY));
    check("healthy v2 instance -> MOUNTS (control)", r.mounted && r.attachShadow);
  }

  // /capabilities failure (HTTP 500) -> unavailable, no mount.
  {
    const r = await boot(() => jsonResponse(500, { error: "boom" }));
    check("/capabilities 5xx -> UNAVAILABLE (no mount, no attachShadow)", !r.mounted && !r.attachShadow);
  }

  // /capabilities 404 (older instance / unknown) -> unavailable, no mount.
  {
    const r = await boot(() => jsonResponse(404, { error: "Unknown agent" }));
    check("/capabilities 404 -> UNAVAILABLE (no mount)", !r.mounted && !r.attachShadow);
  }

  // Network error -> unavailable, no mount.
  {
    const r = await boot(() => Promise.reject(new Error("network down")));
    check("/capabilities network error -> UNAVAILABLE (no mount)", !r.mounted && !r.attachShadow);
  }

  // Missing required field (no streamPath) -> incompatible, no mount.
  {
    const body = JSON.parse(JSON.stringify(HEALTHY));
    delete body.capabilities.streamPath;
    const r = await boot(() => jsonResponse(200, body));
    check("missing required field (streamPath) -> INCOMPATIBLE (no mount)", !r.mounted && !r.attachShadow);
  }

  // SECURITY regression: an otherwise-healthy /capabilities whose streamPath is
  // OFF-ORIGIN must NEVER mount. /capabilities is auth-free, so a hostile/
  // compromised instance must not be able to steer the Bearer stream token to a
  // foreign origin. Each off-origin form -> negotiate false -> NO mount (the
  // marker stays unset AND attachShadow is never called -> fallback chrome).
  {
    const offOrigin = [
      "@evil.example/stream",          // userinfo trick
      "//evil.example/stream",         // protocol-relative
      "https://evil.example/stream",   // absolute foreign URL
      "/\\evil.example/stream",        // backslash form
      "http://instance.example.evil/stream", // host-suffix lookalike
    ];
    for (const sp of offOrigin) {
      const body = JSON.parse(JSON.stringify(HEALTHY));
      body.capabilities.streamPath = sp;
      const r = await boot(() => jsonResponse(200, body));
      check(
        `off-origin streamPath ${JSON.stringify(sp)} -> NO MOUNT (no attachShadow)`,
        !r.mounted && !r.attachShadow,
      );
    }
  }

  // SECURITY regression (defense-in-depth): DOT-SEGMENT streamPath forms PASS
  // the raw-input charAt check (charAt(0)==='/', charAt(1)==='.') but WHATWG
  // normalization collapses them to a protocol-relative authority in the
  // RESOLVED pathname ("//evil.example/stream"). The resolved ORIGIN is still
  // the configured instance (the authority only re-materializes when the stored
  // pathname is resolved AGAIN at the fetch site), so the origin assertion alone
  // would let these through — the widget would MOUNT and getStreamToken() would
  // mint a short-lived token BEFORE the fetch-site re-assertion throws. The
  // negotiation guard must reject the RESOLVED pathname too, so these forms =>
  // negotiate() false => NO mount (no attachShadow) AND no token mint (the
  // same-origin token broker endpoint is never POSTed).
  {
    const dotSegmentForms = [
      "/..//evil.example/stream",      // dot-dot then protocol-relative authority
      "/.//evil.example/stream",       // single-dot then protocol-relative authority
      "/%2e%2e//evil.example/stream",  // percent-encoded dot-dot variant
    ];
    const TOKEN_ENDPOINT = "https://site.example/wp-json/cinatra/v1/token";
    for (const sp of dotSegmentForms) {
      const body = JSON.parse(JSON.stringify(HEALTHY));
      body.capabilities.streamPath = sp;
      // Fetch tracker: record every URL the widget fetches. /capabilities must
      // be hit (negotiation), but the token endpoint must NEVER be POSTed.
      const fetched = [];
      const fetchImpl = (url, opts) => {
        fetched.push(String(url));
        return jsonResponse(200, body);
      };
      const r = await boot(fetchImpl);
      const tokenMinted = fetched.some((u) => u === TOKEN_ENDPOINT);
      check(
        `dot-segment streamPath ${JSON.stringify(sp)} -> NO MOUNT (no attachShadow)`,
        !r.mounted && !r.attachShadow,
      );
      check(
        `dot-segment streamPath ${JSON.stringify(sp)} -> NO token mint (token endpoint never POSTed)`,
        !tokenMinted,
      );
    }
  }

  // Control for the no-token-mint assertion: a HEALTHY same-origin instance
  // mounts, and the token endpoint is still NOT minted at NEGOTIATION time
  // (tokens are minted lazily on the first user message, never at mount). This
  // pins the "no token before a real stream" property the dot-segment cases rely
  // on as their negative space.
  {
    const TOKEN_ENDPOINT = "https://site.example/wp-json/cinatra/v1/token";
    const fetched = [];
    const fetchImpl = (url, opts) => {
      fetched.push(String(url));
      return jsonResponse(200, HEALTHY);
    };
    const r = await boot(fetchImpl);
    const tokenMinted = fetched.some((u) => u === TOKEN_ENDPOINT);
    check(
      "healthy same-origin -> MOUNTS and no token minted at negotiation (control)",
      r.mounted && r.attachShadow && !tokenMinted,
    );
  }

  // SEND-SITE ORDERING (defense-in-depth, source-level): in sendMessage() the
  // rebuilt stream URL's origin MUST be re-asserted === base origin BEFORE
  // getStreamToken() is called, so a malformed/smuggled negotiated.streamPath
  // can never even cause a short-lived token to be minted (the throw is the last
  // line of defense). negotiated.streamPath is closure-private and unreachable
  // from this sandbox, so we pin the security-relevant ORDER directly against
  // the widget source: within sendMessage(), the off-origin `throw` appears
  // before the `getStreamToken()` call. (The behavioral happy-path send through
  // this reordered code is exercised by the next case.)
  {
    const sendIdx = WIDGET_SRC.indexOf("async function sendMessage");
    const region = sendIdx === -1 ? "" : WIDGET_SRC.slice(sendIdx);
    const throwIdx = region.indexOf("Refusing to stream to an off-origin endpoint");
    const mintIdx = region.indexOf("await getStreamToken()");
    check(
      "send-site: off-origin re-assertion precedes getStreamToken() (no mint on bad path)",
      sendIdx !== -1 && throwIdx !== -1 && mintIdx !== -1 && throwIdx < mintIdx,
    );
  }

  // REQUIRED-LOGIN GATE (cinatra#410, behavioral): a healthy same-origin instance
  // mounts in LOGIN mode (no per-user token), so a user message must NOT stream
  // until login completes. This drives the full hosted-PKCE handshake (init via
  // the broker -> popup -> postMessage -> token via the broker -> opaque cwu_)
  // and then a real send, asserting:
  //   * before login: a send attempt does NOT POST the stream (token-less stream
  //     can never slip through);
  //   * the login init + token relays are POSTed same-origin to OUR broker (the
  //     cnx_ never leaves the server) with the X-WP-Nonce CSRF header;
  //   * after login: the stream POST carries BOTH the cit_ Bearer AND the
  //     X-Cinatra-Widget-User-Token: cwu_ dual token (#408).
  {
    const TOKEN_ENDPOINT = "https://site.example/wp-json/cinatra/v1/token";
    const AUTH_INIT = "https://site.example/wp-json/cinatra/v1/widget-auth/init";
    const AUTH_TOKEN = "https://site.example/wp-json/cinatra/v1/widget-auth/token";
    const STREAM_URL = "https://instance.example/api/agents/wordpress-content-editor/stream";
    const INSTANCE_ORIGIN = "https://instance.example";
    const fetched = [];
    let initState = null;   // capture the `state` the widget sent on init.
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
      if (u === TOKEN_ENDPOINT) { return jsonResponse(200, { token: "stream-tok", expiresIn: 300 }); }
      // The stream response: ok + a reader that immediately reports done.
      return Promise.resolve({
        ok: true,
        status: 200,
        body: { getReader() { return { read() { return Promise.resolve({ done: true }); } }; } },
        text: () => Promise.resolve(""),
        headers: { get() { return null; } },
      });
    };

    const captured = { clickHandlers: [], textarea: null, loginBtnEl: null };
    const env = makeEnv(fetchImpl, undefined, captured);
    vm.runInNewContext(WIDGET_SRC, env.sandbox, { filename: "cinatra-widget.js" });
    for (let i = 0; i < 20; i++) { await Promise.resolve(); }
    const mounted = env.rootEl.dataset.cinatraMounted === "true";

    // (1) Pre-login: a send attempt must NOT reach the stream (login gate).
    let preLoginStreamed = false;
    if (mounted && captured.textarea) {
      captured.textarea.value = "hello";
      // Fire the submit handler directly (the doSubmit -> sendMessage path).
      for (const h of captured.clickHandlers) { try { h({ stopPropagation() {} }); } catch (_) {} }
      for (let i = 0; i < 20; i++) { await Promise.resolve(); }
      preLoginStreamed = fetched.some((f) => f.url === STREAM_URL && f.method === "POST");
    }
    check(
      "login gate: pre-login send does NOT POST the stream (token-less stream blocked)",
      mounted && !preLoginStreamed,
    );

    // (2) Drive the login handshake: click the login button, then deliver the
    //     hosted popup's postMessage (origin = instance origin, matching state).
    let loggedIn = false;
    if (mounted && captured.loginBtnEl && captured.loginBtnEl._clickHandlers.length) {
      for (const h of captured.loginBtnEl._clickHandlers) { try { h({}); } catch (_) {} }
      for (let i = 0; i < 20; i++) { await Promise.resolve(); }
      // Deliver the success postMessage from the popup window.
      const popup = env.openedPopups[env.openedPopups.length - 1];
      for (const listener of env.messageListeners) {
        try { listener({ origin: INSTANCE_ORIGIN, source: popup, data: { type: "cinatra-widget-auth", code: "auth-code-1", state: initState } }); } catch (_) {}
      }
      for (let i = 0; i < 30; i++) { await Promise.resolve(); }
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

    // (3) Post-login: a real send streams with BOTH the cit_ Bearer and the
    //     dual cwu_ user token. Token minted exactly once.
    let sent = false;
    if (loggedIn && captured.textarea) {
      captured.textarea.value = "hello again";
      for (const h of captured.clickHandlers) { try { h({ stopPropagation() {} }); } catch (_) {} }
      for (let i = 0; i < 30; i++) { await Promise.resolve(); }
      sent = true;
    }
    const streamPost = fetched.find((f) => f.url === STREAM_URL && f.method === "POST");
    const bearerOk = !!streamPost && /^Bearer stream-tok$/.test(String(streamPost.headers.Authorization || ""));
    const dualTokenOk = !!streamPost && streamPost.headers["X-Cinatra-Widget-User-Token"] === "cwu_user-tok";
    const tokenMints = fetched.filter((f) => f.url === TOKEN_ENDPOINT).length;
    check(
      "send happy path: post-login stream POST carries cit_ Bearer + cwu_ dual token; cit_ minted once",
      mounted && sent && bearerOk && dualTokenOk && tokenMints === 1,
    );
  }

  // supportsTokenExchange !== true -> incompatible, no mount (legacy path is gone).
  {
    const body = JSON.parse(JSON.stringify(HEALTHY));
    body.capabilities.supportsTokenExchange = false;
    const r = await boot(() => jsonResponse(200, body));
    check("supportsTokenExchange:false -> INCOMPATIBLE (no mount)", !r.mounted && !r.attachShadow);
  }

  // No mutually-supported contract version -> unavailable, no mount.
  {
    const body = JSON.parse(JSON.stringify(HEALTHY));
    body.supportedContractVersions = ["v0", "v9"];
    const r = await boot(() => jsonResponse(200, body));
    check("no mutual contractVersion -> UNAVAILABLE (no mount)", !r.mounted && !r.attachShadow);
  }

  // Malformed JSON body -> unavailable, no mount.
  {
    const r = await boot(() => Promise.resolve({
      ok: true,
      status: 200,
      json: () => Promise.reject(new SyntaxError("bad json")),
      text: () => Promise.resolve("<html>not json"),
      headers: { get() { return null; } },
    }));
    check("malformed JSON -> UNAVAILABLE (no mount)", !r.mounted && !r.attachShadow);
  }

  // Duplicate include against the SAME root -> mounts exactly once (the second
  // copy sees the marker / existing shadowRoot and bails).
  {
    const first = await boot(() => jsonResponse(200, HEALTHY));
    const second = await boot(() => jsonResponse(200, HEALTHY), first.env.rootEl);
    check(
      "duplicate include -> mounts exactly once (attachShadow called once total)",
      first.mounted && first.env.rootEl.dataset.cinatraMounted === "true" &&
        (first.attachShadowCount + second.attachShadowCount) === 1,
    );
  }

  console.log(failures === 0 ? "\nALL PASS" : `\n${failures} FAILURE(S)`);
  process.exit(failures === 0 ? 0 : 1);
}

main();
