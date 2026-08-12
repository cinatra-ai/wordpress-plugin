#!/usr/bin/env node
// Direct probes of the gate's comment/regex/division stripper against codex's
// counterexamples (see tools/widget-parity-check.mjs's own header for the
// `apiKey` hidden-behind-a-regex-literal defect this stripper replaced a
// per-line heuristic to close).
//
// Runs under plain `node tools/lexer-probe.mjs` — no bundler, no npm install.
// THIS FILE IS SHIPPED BYTE-IDENTICAL TO BOTH REPOS, alongside the gate it
// exercises: cinatra-ai/wordpress-plugin and cinatra-ai/drupal-module. It reads
// `stripComments` straight out of its neighboring tools/widget-parity-check.mjs
// (same directory in both repos) rather than re-implementing the lexer, so a
// change to the real stripper is probed as written, not as remembered.
//
// Exit 0 = every probe's expected shape held AND the stripped output still
// parses as a function body. Exit 1 = a probe failed.
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const gate = fs.readFileSync(path.join(__dirname, "widget-parity-check.mjs"), "utf8");
const body = gate.slice(gate.indexOf("function stripComments(s) {"), gate.indexOf("const code = stripComments(src);"));
const stripComments = new Function(body + "\nreturn stripComments;")();
const cases = [
  ["r2: regex hides a banned token", 'const normalized = value.replace(/\\//g, "_"), apiKey = secret;', (o) => /\bapiKey\b/.test(o)],
  ["r3: comment inside a template expression", 'const x = `${1 /* apiKey */}`;', (o) => !/apiKey/.test(o)],
  ["r3: regex after `return`", 'function f(x){ return /[/]/.test(x); }', (o) => o.includes("return /[/]/.test(x)")],
  ["r4: regex after a control-condition `)`", 'function f(x){ if (x) /[/*]/.test(x); }', (o) => o.includes("if (x) /[/*]/.test(x)")],
  ["division after a call `)` is still division", 'var q = f(1) / 2; var apiKey2 = 1;', (o) => /apiKey2/.test(o)],
  ["line comment still stripped", 'var a = 1; // apiKey\nvar b = 2;', (o) => !/apiKey/.test(o) && /var b = 2/.test(o)],
  ["block comment still stripped", 'var a = 1; /* apiKey */ var b = 2;', (o) => !/apiKey/.test(o) && /var b = 2/.test(o)],
  ["url in a string survives", "var u = 'https://x.example/a'; var c = 3;", (o) => o.includes("https://x.example/a") && /var c = 3/.test(o)],
  ["r5: a property NAMED like a control keyword is not syntax", 'const q = obj.if(x) / value /* apiKey */ / divisor;', (o) => !/apiKey/.test(o)],
  ["r5: a property named `return` is not syntax", 'const r = obj.return / 2 /* apiKey */ / 3;', (o) => !/apiKey/.test(o)],
  ["r5: a real `if` condition still allows a regex after it", 'function f(x){ if (x) /[/*]/.test(x); }', (o) => o.includes("if (x) /[/*]/.test(x)")],
  ["r6: a PRIVATE member named like a keyword is not syntax", 'class C { #return = 1; m(){ return this.#return / 2 /* apiKey */ / 3; } }', (o) => !/apiKey/.test(o)],
  ["r6: a real `return` still allows a regex after it", 'function f(x){ return /[/]/.test(x); }', (o) => o.includes("return /[/]/.test(x)")],
];
let bad = 0;
for (const [label, src, ok] of cases) {
  const out = stripComments(src);
  let compiles = true;
  try { new Function(out); } catch (_) { compiles = false; }
  const pass = ok(out) && compiles;
  if (!pass) bad++;
  console.log(`  ${pass ? "PASS" : "FAIL"}  ${label}${pass ? "" : `\n        in : ${src}\n        out: ${out}`}`);
}
console.log(bad === 0 ? "\nALL LEXER PROBES PASS" : `\n${bad} LEXER PROBE FAILURE(S)`);
process.exit(bad === 0 ? 0 : 1);
