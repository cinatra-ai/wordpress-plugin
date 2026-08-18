// NO CREDENTIAL LEAVES PLUGIN CODE (cinatra#2674, epic #2564 S8e).
//
// Runs under plain `node tests/test-no-credential-egress.mjs` — no jsdom, no
// bundler, no WordPress. Exit 0 = the guarantee holds; exit 1 = it does not.
//
// WHAT IS BEING PINNED, EXACTLY
// -----------------------------
// Protocol 2 removed the credential FIELD from the parent->iframe bridge. That
// alone is not the guarantee worth having: every field the parent still sends is
// a string it composes from CMS state, so a bearer could ride inside
// `cms.resourceId`, `cms.resourceType`, `site.siteId` or a thread id. This suite
// pins the stronger property:
//
//   NO OUTBOUND PAYLOAD COMPOSED BY PLUGIN CODE CONTAINS A CREDENTIAL-SHAPED
//   VALUE — at any depth, in a value or a key — and when the CMS state would
//   produce one, the message is REFUSED rather than sent.
//
// SCOPE, STATED HONESTLY. "Outbound" here means everything the plugin hands the
// BROWSER or puts on the bridge. It is NOT a claim that the plugin never sends a
// credential anywhere: the `cnx_` connect-site credential is still presented
// server-to-server from PHP, in an `Authorization` header, on the Connect
// exchange, the site-inventory handshake and publish webhooks. That is the
// setup/integration credential and it is unchanged by design — it never enters
// the browser, and PHP's side of this guarantee (nothing credential-shaped in the
// localized config the browser receives) is pinned in tests/test-token-broker.php.
//
// POSITIVE CONTROL. A test that proves an ABSENCE is worthless until it has
// proven it can hear a presence. So the detector is exercised against synthetic
// sentinels — through the same capture sink, in the same shapes — and the run
// FAILS if the detector stays quiet on them. Silence is only trusted afterwards.

import fs from "node:fs";
import path from "node:path";
import vm from "node:vm";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.join(__dirname, "..");
const WIDGET_SRC = fs.readFileSync(
  path.join(REPO_ROOT, "assets", "cinatra-widget.js"),
  "utf8",
);

const INSTANCE_ORIGIN = "https://instance.example";

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
// THE ORACLE — an INDEPENDENT reimplementation of the credential-shape matcher.
//
// Deliberately not the widget's own `containsCredentialShapedValue`: a guard that
// grades its own output proves only that it is self-consistent. This is written
// from the contract in cinatra src/lib/embed/bridge-protocol.ts — the three
// bearer prefixes, at a token boundary, anywhere in the string, case-insensitive,
// recursing through arrays, object values AND object keys.
// ---------------------------------------------------------------------------
const CREDENTIAL_TOKEN_RE = /(?:^|[^A-Za-z0-9])(?:cwu|cit|cnx)_/i;
function isCredentialShaped(value) {
  return typeof value === "string" && CREDENTIAL_TOKEN_RE.test(value);
}
function findCredentialShaped(value, trail = "$", depth = 0) {
  if (depth > 12) return null;
  if (isCredentialShaped(value)) return { at: trail, value };
  if (value === null || typeof value !== "object") return null;
  if (Array.isArray(value)) {
    for (let i = 0; i < value.length; i++) {
      const hit = findCredentialShaped(value[i], `${trail}[${i}]`, depth + 1);
      if (hit) return hit;
    }
    return null;
  }
  for (const key of Object.keys(value)) {
    if (isCredentialShaped(key)) return { at: `${trail}.<key>`, value: key };
    const hit = findCredentialShaped(value[key], `${trail}.${key}`, depth + 1);
    if (hit) return hit;
  }
  return null;
}

// ---------------------------------------------------------------------------
// Minimal DOM/window shim, reduced to what this suite needs: it captures EVERY
// parent->iframe payload on BOTH transports, plus every fetch body, so the
// detector sees the complete outbound surface rather than one chosen path.
// ---------------------------------------------------------------------------
function makePortStub(sink) {
  return {
    posts: [],
    onmessage: null,
    postMessage(msg) { this.posts.push(msg); sink.push({ via: "port", payload: msg }); },
    addEventListener() {},
    removeEventListener() {},
    start() {},
    close() {},
  };
}

function makeEnv(sink, opts) {
  const options = opts || {};
  const messageListeners = [];
  const byClass = {};
  let iframeEl = null;

  function makeStubEl(isRoot, tag) {
    const el = {
      _tag: tag || "div",
      style: {}, dataset: {}, shadowRoot: null,
      classList: { add() {}, remove() {}, contains() { return false; } },
      attributes: {}, children: [], parentNode: null,
      _clickHandlers: [],
      set className(v) { this._className = v; byClass[v] = this; },
      get className() { return this._className; },
      set textContent(v) { this._textContent = v; },
      get textContent() { return this._textContent; },
      setAttribute(k, v) { this.attributes[k] = v; },
      getAttribute(k) { return this.attributes[k]; },
      appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
      removeChild(c) {
        const i = this.children.indexOf(c);
        if (i !== -1) this.children.splice(i, 1);
        c.parentNode = null; return c;
      },
      addEventListener(type, handler) { if (type === "click") this._clickHandlers.push(handler); },
      removeEventListener() {},
      querySelector() { return null; },
      attachShadow() { const sh = makeStubEl(false); if (isRoot) this.shadowRoot = sh; return sh; },
      focus() {},
      getBoundingClientRect() { return { left: 0, top: 0, width: 0, height: 0 }; },
    };
    if ((tag || "div") === "iframe") {
      el.contentWindow = {
        postMessage(msg, targetOrigin) { sink.push({ via: "window", payload: msg, targetOrigin }); },
      };
      iframeEl = el;
    }
    return el;
  }

  const rootEl = makeStubEl(true);
  const documentStub = {
    getElementById(id) { return id === "cinatra-root" ? rootEl : null; },
    createElement(tag) { return makeStubEl(false, tag); },
    createElementNS() { return makeStubEl(false, "svg"); },
    querySelector() { return null; },
    addEventListener() {},
    head: makeStubEl(), body: makeStubEl(), readyState: "complete",
  };

  const sandbox = {
    window: {
      CinatraConfig: Object.assign(
        { cinatraUrl: INSTANCE_ORIGIN, instanceId: "i1", siteId: "site_123" },
        options.config || {},
      ),
      innerWidth: 1280, innerHeight: 800,
      location: { href: "https://site.example/wp-admin/", reload() {} },
      typenow: options.typenow !== undefined ? options.typenow : "post",
      addEventListener(type, handler) { if (type === "message") messageListeners.push(handler); },
      removeEventListener(type, handler) {
        if (type === "message") {
          const i = messageListeners.indexOf(handler);
          if (i !== -1) messageListeners.splice(i, 1);
        }
      },
      open() { throw new Error("the widget must never open a window"); },
      crypto: { getRandomValues(a) { for (let i = 0; i < a.length; i++) a[i] = (i * 7 + 3) & 0xff; return a; } },
      wp: {
        data: {
          select(store) {
            if (store === "core") return { canUser() { return true; } };
            if (store === "core/editor") {
              return {
                getCurrentPostId() { return options.postId !== undefined ? options.postId : 5; },
                getEditedPostAttribute() { return options.status !== undefined ? options.status : "draft"; },
              };
            }
            return {};
          },
          dispatch() { return {}; },
        },
      },
    },
    document: documentStub,
    console: { warn() {}, error() {}, log() {} },
    // Any fetch body is captured too: the guarantee is about the whole outbound
    // surface, and a widget that started POSTing somewhere would be caught here
    // rather than by the fact that it currently POSTs nowhere.
    fetch: (url, init) => {
      sink.push({ via: "fetch", payload: { url: String(url), body: init && init.body, headers: init && init.headers } });
      return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}), text: () => Promise.resolve("") });
    },
    setTimeout: () => 0, clearTimeout: () => {},
    setInterval: () => 0, clearInterval: () => {},
    btoa: (s) => Buffer.from(s, "binary").toString("base64"),
    TextEncoder,
    Object, Array, JSON, Promise, Date, Math, String, Number, Uint8Array, isFinite, URL,
  };
  sandbox.crypto = sandbox.window.crypto;
  sandbox.window.document = documentStub;
  sandbox.globalThis = sandbox;
  return { sandbox, byClass, messageListeners, iframe: () => iframeEl };
}

async function flush(n) { for (let i = 0; i < (n || 25); i++) await Promise.resolve(); }

// Boot the widget, open the panel, deliver a well-formed READY, and return every
// payload the plugin emitted. `transport: "port"` transfers a MessagePort so the
// port path is exercised with the same assertions as the window path.
async function drive(opts) {
  const options = opts || {};
  const sink = [];
  const env = makeEnv(sink, options);
  vm.runInNewContext(WIDGET_SRC, env.sandbox, { filename: "cinatra-widget.js" });
  await flush();
  const circle = env.byClass["cw-circle"];
  for (const h of (circle ? circle._clickHandlers : [])) { try { h({}); } catch (_) {} }
  await flush();
  const iframe = env.iframe();
  const frameWin = iframe && iframe.contentWindow;
  const ready = { type: "cinatra.embed.ready", protocolVersion: 2, nonce: "egressNonce0123456789ab", seq: 0 };
  const ev = { origin: INSTANCE_ORIGIN, source: frameWin, data: ready };
  if (options.transport === "port") { ev.ports = [makePortStub(sink)]; }
  for (const l of env.messageListeners) { try { l(ev); } catch (_) {} }
  await flush(30);
  return { sink, env };
}

async function main() {
  console.log("no credential leaves plugin code (protocol 2, cinatra#2674)");

  // -------------------------------------------------------------------------
  // POSITIVE CONTROL — run FIRST. Everything below is an absence claim, and an
  // absence claim from a detector that cannot detect is a lie told confidently.
  // Each sentinel is a shape a real leak would take.
  // -------------------------------------------------------------------------
  {
    const sentinels = [
      ["a bare user bearer", "cwu_the_user_bearer"],
      ["a site transport token", "cit_the_site_transport"],
      ["a connect-site credential", "cnx_site_123_SECRET"],
      ["one embedded in an error string", "Error: cwu_expired — retry"],
      ["one embedded in a URL", "https://x.example/cb?t=cit_abc"],
      ["one in a query pair", "auth=cwu_abc"],
      ["upper/whitespace evasion", "  CWU_ABC  "],
      ["mixed-case evasion", "Cit_x"],
    ];
    const missed = sentinels.filter(([, v]) => !isCredentialShaped(v));
    check(
      `positive control: the detector fires on all ${sentinels.length} sentinel shapes`,
      missed.length === 0,
    );
    // Nested exactly as a real envelope would nest it — including as a KEY, which
    // is as visible to a logger as a value.
    const nested = { type: "cinatra.embed.context", cms: { instanceId: "i1", resourceId: "cwu_smuggled" } };
    const inKey = { session: { assistant: "wordpress" }, extra: { cit_leak: 1 } };
    const inArray = { a: ["ok", ["deeper", "cnx_leak"]] };
    check(
      "positive control: the detector fires on a value nested in the envelope, on a KEY, and inside arrays",
      !!findCredentialShaped(nested) && !!findCredentialShaped(inKey) && !!findCredentialShaped(inArray),
    );
    // And it must NOT fire on lookalikes, or a clean envelope would be
    // unsendable and the guard would be a denial-of-service instead of a control.
    const lookalikes = ["abccwu_x", "specit_x", "citation", "concise", "", "post", "draft", "site_123"];
    check(
      "positive control: the detector does NOT fire on lookalikes (abccwu_, citation, site_123, …)",
      lookalikes.every((v) => !isCredentialShaped(v)),
    );
    // Prove the CAPTURE PATH records what it should: a sentinel pushed through
    // the same sink the widget writes to must be found by the same scan the
    // absence assertions use. A silent sink would pass every test below.
    const probe = [];
    probe.push({ via: "window", payload: { cms: { resourceId: "cwu_sentinel" } } });
    check(
      "positive control: a sentinel pushed through the capture sink IS found by the scan",
      probe.some((e) => findCredentialShaped(e.payload)),
    );
  }

  // -------------------------------------------------------------------------
  // THE HAPPY PATH — an ordinary post being edited. Everything the plugin emits
  // is scanned, on both transports.
  // -------------------------------------------------------------------------
  for (const transport of ["window", "port"]) {
    const { sink } = await drive({ transport });
    check(`[${transport}] the plugin emitted exactly one payload (the CONTEXT message)`, sink.length === 1);
    const hit = sink.map((e) => findCredentialShaped(e.payload)).find(Boolean);
    check(
      `[${transport}] NO outbound payload contains a credential-shaped value, at any depth`,
      !hit,
    );
    const fetches = sink.filter((e) => e.via === "fetch");
    check(`[${transport}] the plugin made no request at all (nothing to leak into)`, fetches.length === 0);
  }

  // -------------------------------------------------------------------------
  // THE POISONED SELECTORS — the case the guard exists for. Each of these is a
  // field the CMS supplies and the plugin forwards as a public selector. When one
  // carries a bearer shape, the message must be REFUSED, not sent-and-rejected:
  // a message that never leaves cannot be read by anything.
  // -------------------------------------------------------------------------
  // Each poison is paired with a BENIGN TWIN in the same field. Without the twin,
  // "nothing was sent" would also be satisfied by a drive that was simply broken;
  // with it, the refusal is attributable to the poisoned value and nothing else.
  const poisons = [
    ["a bearer in the post type", { typenow: "cwu_leaked_user_token" }, { typenow: "post" }],
    ["a bearer in the post id", { postId: "cit_leaked_site_token" }, { postId: "5" }],
    ["a bearer in the post status", { status: "cnx_leaked_site_credential" }, { status: "draft" }],
    ["a bearer in the connect-site handle", { config: { siteId: "cwu_not_a_site_id" } }, { config: { siteId: "site_123" } }],
    ["a bearer in the agent instance id", { config: { instanceId: "cit_not_an_instance" } }, { config: { instanceId: "i1" } }],
    ["a bearer inside an error-shaped status", { status: "Error: cwu_expired" }, { status: "Error: session expired" }],
    ["a bearer inside a URL-shaped post type", { typenow: "https://x.example/?t=cit_abc" }, { typenow: "https://x.example/?t=abc" }],
  ];
  for (const [label, poisoned, benign] of poisons) {
    for (const transport of ["window", "port"]) {
      const { sink } = await drive(Object.assign({ transport }, poisoned));
      const { sink: twin } = await drive(Object.assign({ transport }, benign));
      const leaked = sink.map((e) => findCredentialShaped(e.payload)).find(Boolean);
      check(
        `[${transport}] ${label} is REFUSED (sent=${sink.length}) while its benign twin sends (sent=${twin.length})`,
        sink.length === 0 && !leaked && twin.length === 1,
      );
    }
  }

  // -------------------------------------------------------------------------
  // FAIL-CLOSED, NOT FAIL-SILENT-FOREVER. The refusal is scoped to the poisoned
  // frame: a clean page still sends. Otherwise a single bad post would look
  // identical to a broken widget.
  // -------------------------------------------------------------------------
  {
    const { sink } = await drive({ typenow: "post", postId: "cwu_bad" });
    const { sink: cleanSink } = await drive({ typenow: "post", postId: "5" });
    check(
      "the refusal is per-message, not a permanent latch (a clean page still sends)",
      sink.length === 0 && cleanSink.length === 1,
    );
  }

  // -------------------------------------------------------------------------
  // LOOKALIKES STILL SEND. The guard over-matches deliberately (a CMS id that
  // genuinely began `cnx_` would be refused), but it must not over-match a value
  // that merely CONTAINS those letters — that would make ordinary posts
  // unusable, and a control that breaks the product gets removed.
  // -------------------------------------------------------------------------
  {
    const { sink } = await drive({ typenow: "citation", postId: "42", status: "concise-draft" });
    check(
      "lookalike selectors (`citation`, `concise-draft`) are NOT refused — the message is sent",
      sink.length === 1 && !findCredentialShaped(sink[0].payload),
    );
  }

  // -------------------------------------------------------------------------
  // STATIC ARM — the retired credential path is gone from EVERY shipped browser
  // asset, not just the widget. The parity gate covers the widget alone; the
  // fallback and settings scripts run on the same admin pages and are equally
  // capable of fetching a bearer.
  // -------------------------------------------------------------------------
  {
    const assetsDir = path.join(REPO_ROOT, "assets");
    const assets = fs.readdirSync(assetsDir).filter((f) => f.endsWith(".js"));
    check("static: there is at least one shipped browser asset to scan", assets.length > 0);
    // Comments stripped: the widget header legitimately NAMES the retired routes
    // to explain their absence, and banning the prose would ban the explanation.
    const stripComments = (s) =>
      s.replace(/\/\*[\s\S]*?\*\//g, "")
        .split("\n")
        .map((line) => {
          const idx = line.indexOf("//");
          if (idx === -1) return line;
          const before = line.slice(0, idx);
          return (before.match(/['"`]/g) || []).length % 2 === 0 ? before : line;
        })
        .join("\n");
    const RETIRED = [
      ["the token broker", /cinatra\/v1\/token|tokenEndpoint|getStreamToken/],
      ["the sign-in relays", /widget-auth|authInitEndpoint|authTokenEndpoint/],
      ["the credential bootstrap", /citToken|cwuToken|cinatra\.embed\.bootstrap/],
    ];
    const offenders = [];
    for (const file of assets) {
      const code = stripComments(fs.readFileSync(path.join(assetsDir, file), "utf8"));
      for (const [what, re] of RETIRED) {
        if (re.test(code)) offenders.push(`${file}: ${what}`);
      }
    }
    check(
      `static: no shipped browser asset references the retired credential path (${assets.join(", ")})`,
      offenders.length === 0,
    );
    if (offenders.length) { for (const o of offenders) console.log(`        ${o}`); }
  }

  console.log(failures === 0 ? "\nALL PASS" : `\n${failures} FAILURE(S)`);
  process.exit(failures === 0 ? 0 : 1);
}

main();
