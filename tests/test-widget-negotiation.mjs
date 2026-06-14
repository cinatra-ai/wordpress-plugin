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
function makeEnv(fetchImpl, sharedRoot) {
  let attachShadowCount = 0;

  function makeStubEl(isRoot) {
    const el = {
      style: {},
      dataset: {},
      shadowRoot: null,
      classList: { add() {}, remove() {}, contains() { return false; } },
      attributes: {},
      children: [],
      setAttribute(k, v) { this.attributes[k] = v; },
      getAttribute(k) { return this.attributes[k]; },
      appendChild(c) { this.children.push(c); return c; },
      addEventListener() {},
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
        instanceId: "i1",
      },
      sessionStorage: storage,
      innerWidth: 1280,
      innerHeight: 800,
      location: { href: "https://site.example/wp-admin/", reload() {} },
      addEventListener() {},
    },
    document: documentStub,
    console,
    fetch: fetchImpl,
    setTimeout: (fn) => { return 0; }, // negotiation timer never needs to fire here
    clearTimeout: () => {},
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
    TextDecoder: class { decode() { return ""; } },
  };
  sandbox.window.document = documentStub;
  sandbox.globalThis = sandbox;
  return { sandbox, rootEl, attachShadowCount: () => attachShadowCount };
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
