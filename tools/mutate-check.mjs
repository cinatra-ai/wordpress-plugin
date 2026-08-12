#!/usr/bin/env node
// Negative controls for the widget source-of-truth parity gate (cinatra#411,
// cinatra#2674): each mutation removes exactly one protection from the
// canonical widget and the gate must go RED for it. An assertion that stays
// green under its own mutation is hollow — this is what keeps the gate itself
// honest as the widget evolves, the way a mutation-tested detector should.
//
// Runs under plain `node tools/mutate-check.mjs` — no bundler, no npm install,
// no WordPress/Drupal. THIS FILE IS SHIPPED BYTE-IDENTICAL TO BOTH REPOS,
// alongside the gate it exercises: cinatra-ai/wordpress-plugin and
// cinatra-ai/drupal-module. It auto-detects the vendored widget the same way
// tools/widget-parity-check.mjs does (WP: assets/, Drupal: js/) because the
// widget's own content no longer tells the two copies apart — it is the UNION
// of both lanes' protections, read identically by whichever gate copy runs.
//
// Each mutation temporarily overwrites the repo's OWN vendored widget file and
// restores the original from memory in a `finally`, so an interrupted run
// cannot leave a mutated copy behind.
//
// Exit 0 = every mutation was caught (gate went red). Exit 1 = a mutation left
// the gate green (HOLLOW) or matched nothing in the current widget (INERT —
// the mutation has drifted from the source it targets; fix the mutation, not
// the gate).
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { execFileSync } from "node:child_process";

const SELF_PATH = fileURLToPath(import.meta.url);
const __dirname = path.dirname(SELF_PATH);
const REPO_ROOT = path.resolve(__dirname, "..");

// Same discriminator tools/widget-parity-check.mjs uses to locate the vendored
// widget — see that file for why the PATH, not the content, is the CMS tell.
const CANDIDATES = [
  ["assets/cinatra-widget.js", "WordPress"], // wordpress-plugin
  ["js/cinatra-widget.js", "Drupal"], // drupal-module
];
const found = CANDIDATES.find(([rel]) => fs.existsSync(path.join(REPO_ROOT, rel)));
if (!found) {
  console.error(
    `FAIL  no vendored widget found (looked for: ${CANDIDATES.map(([r]) => r).join(", ")})`,
  );
  process.exit(1);
}
const [widgetRel] = found;
const WIDGET = path.join(REPO_ROOT, widgetRel);
const GATE = path.join(__dirname, "widget-parity-check.mjs");
const original = fs.readFileSync(WIDGET, "utf8");

const MUTATIONS = [
  ["0a  drop the Drupal broker read (the diagnostic string still names it)",
    (s) => s.replace("return window.drupalSettings && window.drupalSettings.cinatra;", "return null;")],
  ["0a  drop the WordPress broker read",
    (s) => s.replace("return window.CinatraConfig;", "return null;")],
  ["0a  make the Drupal broker unselectable",
    (s) => s.replace("    config = drupalConfig;", "    config = wordpressConfig;")],
  ["0a-2 select the broker by truthiness instead of by carrying a cinatraUrl",
    (s) => s.replace("  if (wordpressUrl) {\n    CMS = 'wordpress';", "  if (wordpressConfig) {\n    CMS = 'wordpress';")],
  ["0a-2 read the other host's global unguarded (a hostile getter aborts the mount)",
    (s) => s.replace("  function readBroker(read) {\n    try {\n      var value = read();\n      return value && typeof value === 'object' ? value : null;\n    } catch (_) {\n      return null;\n    }\n  }",
                     "  function readBroker(read) {\n    var value = read();\n    return value && typeof value === 'object' ? value : null;\n  }")],
  ["3f-2 remove the MAX_BRIDGE_EPOCHS ceiling (manufactured reloads become unbounded)",
    (s) => s.replace("      if (contextSent && bridgeEpochs >= MAX_BRIDGE_EPOCHS) return;\n", "")],
  ["3f-2 stop counting epochs (the ceiling can never be reached)",
    (s) => s.replace("    bridgeEpochs++;\n", "")],
  ["7c-2 put the inbound guard AFTER the shape check (a field is read first)",
    (s) => s.replace("    if (!inboundIsClean(d)) return;\n    if (!d || typeof d !== 'object' || typeof d.type !== 'string') return;\n    dispatchUplink(d);",
                     "    if (!d || typeof d !== 'object' || typeof d.type !== 'string') return;\n    if (!inboundIsClean(d)) return;\n    dispatchUplink(d);")],
  ["7d-2 stop re-bounding the required instance id at compose time",
    (s) => s.replace("    if (!instanceId) { return null; }\n", "")],
  ["7d-2 let sendToFrame post a null refusal",
    (s) => s.replace("    if (!message || typeof message !== 'object') { return false; }\n", "")],
  ["INV1 hide a banned token behind a regex literal (codex's counterexample to the old line heuristic)",
    (s) => s.replace("  var isOpen = false;",
                     "  var normalized = ''.replace(/\\//g, \"_\"), apiKey = 'secret';\n  var isOpen = false;")],
  ["7a-3 remove the credential walk's node budget (a wide structure spins it)",
    (s) => s.replace("    if (b.left <= 0) { return true; }\n    b.left--;\n", "")],
  ["7a-3 stop threading the budget through recursion (each branch gets a fresh one)",
    (s) => s.replace("containsCredentialShapedValue(value[i], d + 1, b)", "containsCredentialShapedValue(value[i], d + 1)")],
  ["7a-3 materialize every own key with Object.keys before the budget can stop it",
    (s) => s.replace(/    for \(var key in value\) \{\n(?:.*\n)*?      if \(b\.left <= 0\) \{ return true; \}\n      b\.left--;\n      if \(!Object\.prototype\.hasOwnProperty\.call\(value, key\)\) \{ continue; \}\n/,
                     "    var keys = Object.keys(value);\n    for (var ki = 0; ki < keys.length; ki++) {\n      var key = keys[ki];\n      if (b.left <= 0) { return true; }\n      b.left--;\n")],
  ["7a-3 spend the budget only AFTER the ownership test (an inherited-key loop runs free)",
    (s) => s.replace("      if (b.left <= 0) { return true; }\n      b.left--;\n      if (!Object.prototype.hasOwnProperty.call(value, key)) { continue; }",
                     "      if (!Object.prototype.hasOwnProperty.call(value, key)) { continue; }\n      if (b.left <= 0) { return true; }\n      b.left--;")],
  ["0a-2 probe the foreign broker's cinatraUrl outside the guard",
    (s) => s.replace("  function brokerUrl(broker) {\n    try {\n      var url = broker && broker.cinatraUrl;\n      return typeof url === 'string' && url ? url : '';\n    } catch (_) {\n      return '';\n    }\n  }",
                     "  function brokerUrl(broker) {\n    var url = broker && broker.cinatraUrl;\n    return typeof url === 'string' && url ? url : '';\n  }")],
  ["0a-3 read the other host's global eagerly",
    (s) => s.replace("  if (!wordpressUrl) {\n    drupalConfig = readBroker(function () { return window.drupalSettings && window.drupalSettings.cinatra; });\n    drupalUrl = brokerUrl(drupalConfig);\n  }",
                     "  drupalConfig = readBroker(function () { return window.drupalSettings && window.drupalSettings.cinatra; });\n  drupalUrl = brokerUrl(drupalConfig);")],
  ["0a-4 re-read the instance URL from the broker when building the src",
    (s) => s.replace("    var src = cinatraUrl + '/embed/assistant' +", "    var src = config.cinatraUrl + '/embed/assistant' +")],
  ["0b  hardcode the assistant handle",
    (s) => s.replace("var EMBED_ASSISTANT = CMS;", "var EMBED_ASSISTANT = 'wordpress';")],
  ["0c  ungate the remote webfont",
    (s) => s.replace("  if (CMS === 'wordpress') {\n    var FONT_URL", "  if (true) {\n    var FONT_URL")],
  ["3a  drop allow-popups from the sandbox",
    (s) => s.replace("'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox'",
                     "'allow-scripts allow-same-origin allow-popups-to-escape-sandbox'")],
  ["3a  widen the sandbox by one flag",
    (s) => s.replace("'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox'",
                     "'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-forms'")],
  ["3f-2 remove the same-nonce replay guard",
    (s) => s.replace("      if (contextSent && d.nonce === frameNonce) return;\n", "")],
  ["3f-2 remove the epoch reset",
    (s) => s.replace("      if (contextSent) { resetBridgeEpoch(); }\n", "")],
  ["3f-2 move the epoch reset BEFORE the transport checks (DoS on a malformed READY)",
    (s) => s.replace("      var ports = event.ports;", "      if (contextSent) { resetBridgeEpoch(); }\n      var ports = event.ports;")
             .replace("      if (!transferredPort && requirePort) return;\n      if (contextSent) { resetBridgeEpoch(); }\n",
                      "      if (!transferredPort && requirePort) return;\n")],
  ["3f-2 remove the requirePort fail-closed check (the ordering anchor)",
    (s) => s.replace("      if (!transferredPort && requirePort) return;\n", "")],
  ["3f-2 let the epoch reset leak the retired port",
    (s) => s.replace("      try { activePort.close(); } catch (_) {}\n", "")],
  ["3f-2 spend the seq gate before the closed type set",
    (s) => s.replace("    if (!isKnownUplinkType(d.type)) return false;\n    if (typeof d.correlationId !== 'string' || d.correlationId !== correlationId) return false;\n    if (!acceptInboundSeq(d.seq)) return false;",
                     "    if (typeof d.correlationId !== 'string' || d.correlationId !== correlationId) return false;\n    if (!acceptInboundSeq(d.seq)) return false;\n    if (!isKnownUplinkType(d.type)) return false;")],
  ["3f-2 drop the no-retry-storm latch ordering",
    (s) => s.replace("      contextSent = true;\n      if (!sendToFrame(buildEmbedContext(d.nonce))) {",
                     "      if (!sendToFrame(buildEmbedContext(d.nonce))) {\n        contextSent = true;")],
  ["3f-4 silently reduce a multi-port READY to its first port",
    (s) => s.replace("      if (ports && ports.length > 1) return;\n", "")],
  ["7a-2 make the credential guard fail OPEN at its depth bound",
    (s) => s.replace("    if (d >= 8) { return true; }", "    if (d >= 8) { return false; }")],
  ["7a-2 make the credential guard fail OPEN on a non-plain container",
    (s) => s.replace("    if (tag !== '[object Object]') { return true; }", "    if (tag !== '[object Object]') { return false; }")],
  ["7b  bypass the outbound choke point (sendToFrame stops consulting the guard)",
    (s) => s.replace(/    if \(containsCredentialShapedValue\(message\)\) \{[\s\S]*?\n    \}\n(?=    if \(activeTransport === 'port')/, "")],
  ["7c  drop the inbound guard on the port transport",
    (s) => s.replace(/(function onPortMessage\(event\) \{[\s\S]*?)    if \(!inboundIsClean\(d\)\) return;\n/, "$1")],
  ["7d  drop the selector normalization",
    (s) => s.replace("  function asSelector(value) {", "  function asSelectorRenamed(value) {")],
  ["7e  scan only the ENCODED url (the encoding bypass)",
    (s) => s.replace("if (containsCredentialShapedValue(rawParts) || containsCredentialShapedValue(src)) {",
                     "if (containsCredentialShapedValue(src)) {")],
  ["7f  drop the same-origin refusal",
    (s) => s.replace("  if (pageOrigin && cinatraOrigin === pageOrigin) {", "  if (false) {")],
];

let hollow = 0;
try {
  for (const [label, mutate] of MUTATIONS) {
    const mutated = mutate(original);
    if (mutated === original) {
      console.log(`  INERT   ${label}  (the mutation matched nothing — fix the mutation, not the gate)`);
      hollow++;
      continue;
    }
    fs.writeFileSync(WIDGET, mutated);
    let red = false;
    try {
      execFileSync("node", [GATE], { cwd: REPO_ROOT, stdio: "pipe" });
    } catch (_) {
      red = true;
    }
    console.log(`  ${red ? "CAUGHT " : "HOLLOW "} ${label}`);
    if (!red) hollow++;
  }
} finally {
  fs.writeFileSync(WIDGET, original);
}
console.log(hollow === 0
  ? `\nALL ${MUTATIONS.length} MUTATIONS CAUGHT`
  : `\n${hollow} MUTATION(S) NOT CAUGHT`);
process.exit(hollow === 0 ? 0 : 1);
