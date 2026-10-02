// Facebook / Telegram / TikTok condition-node regression test.
//
//   node node/test/fb-condition.mjs
//
// Locks down the "every input hits the first YES port" bug: evalCondition read
// operator/variable/value flat off the node instead of from data.conditions[],
// so a keyword flow with price/pricing/cost chained conditions replied
// "we have price" to EVERY message. Pure evaluator — no network, no socket.
//
// Imports the REAL exported evalCondition from all three services and drives the
// ACTUAL exported flow JSON through them.

import fs from "node:fs";
import { evalCondition as fbEval } from "../services/facebookFlowService.js";
import { evalCondition as tgEval } from "../services/telegramFlowService.js";
import { evalCondition as ttEval } from "../services/tiktokFlowService.js";

let pass = 0, fail = 0;
const ok = (name, got, want) => {
  const good = JSON.stringify(got) === JSON.stringify(want);
  good ? pass++ : fail++;
  console.log(`${good ? "PASS" : "FAIL"}  ${name}${good ? "" : `  → got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`}`);
};

// ── 1) Drive the ACTUAL exported flow through the real evaluator ────────────
const FLOW_PATH = "C:/Users/91978/Downloads/new-testing.wadesk-flow.json";
if (fs.existsSync(FLOW_PATH)) {
  const flow = JSON.parse(fs.readFileSync(FLOW_PATH, "utf8")).flow_data;
  const nodes = new Map(flow.flowNodes.map((n) => [String(n.id), n]));
  const edgeOf = (src, handle) =>
    (flow.flowEdges.find((e) => e.source === src && e.sourceHandle === handle) || {}).target || null;

  // Mirror the real walker: message → emit text; condition → evalCondition yes/no;
  // end → stop. Start after the trigger's "out" edge.
  const route = (text) => {
    let cur = edgeOf(flow.flowNodes.find((n) => n.isStart).id, "out");
    let guard = 0, out = null;
    while (cur && guard++ < 50) {
      const node = nodes.get(String(cur));
      if (!node) break;
      const t = String(node.type);
      if (t === "message") { out = node.data.text; break; }        // first reply wins
      if (t === "end") break;
      if (t === "condition") {
        const port = fbEval(node.data, { text }) ? "yes" : "no";
        cur = edgeOf(node.id, port);
        continue;
      }
      cur = edgeOf(node.id, "out");
    }
    return out;
  };

  console.log("── real exported flow (price / pricing / cost) ──");
  ok('"price"   → we have price',   route("price"),   "we have price");
  ok('"pricing" → we have pricing', route("pricing"), "we have pricing");
  ok('"cost"    → This is cost',    route("cost"),    "This is cost");
  ok('"Price" (case)  → we have price', route("Price"), "we have price");
  ok('"hello"   → fallback',        route("hello"),   "Kindly wait for the response");
} else {
  console.log(`(skipped exported-flow test — ${FLOW_PATH} not found)`);
}

// ── 2) Full operator matrix on the real evaluator ───────────────────────────
const one = (variable, operator, value) => ({ conditions: [{ variable, operator, value }], operators: [] });
console.log("── operator matrix (facebook evaluator) ──");
ok("equals hit",        fbEval(one("text", "equals", "hi"), { text: "hi" }), true);
ok("equals miss",       fbEval(one("text", "equals", "hi"), { text: "hey" }), false);
ok("not_equals",        fbEval(one("text", "not_equals", "hi"), { text: "hey" }), true);
ok("contains hit",      fbEval(one("text", "contains", "pric"), { text: "pricing" }), true);
ok("contains miss",     fbEval(one("text", "contains", "xyz"), { text: "pricing" }), false);
ok("not_contains",      fbEval(one("text", "not_contains", "xyz"), { text: "pricing" }), true);
ok("gt true",           fbEval(one("qty", "gt", "2"), { qty: "5" }), true);
ok("gt false",          fbEval(one("qty", "gt", "9"), { qty: "5" }), false);
ok("lt true",           fbEval(one("qty", "lt", "9"), { qty: "5" }), true);
ok("exists true",       fbEval(one("email", "exists", ""), { email: "a@b.c" }), true);
ok("exists false",      fbEval(one("email", "exists", ""), { email: "" }), false);
ok("starts_with",       fbEval(one("text", "starts_with", "buy"), { text: "buy now" }), true);
ok("ends_with",         fbEval(one("text", "ends_with", "now"), { text: "buy now" }), true);
ok("unknown op → false", fbEval(one("text", "wat", "x"), { text: "x" }), false);
ok("blank var falls back to text", fbEval(one("nope", "equals", "hi"), { text: "hi" }), true);

// ── 3) AND / OR combining ───────────────────────────────────────────────────
const both = { conditions: [{ variable: "text", operator: "contains", value: "buy" }, { variable: "qty", operator: "gt", value: "2" }], operators: ["and"] };
const either = { conditions: [{ variable: "text", operator: "equals", value: "hi" }, { variable: "text", operator: "equals", value: "hey" }], operators: ["or"] };
console.log("── AND / OR ──");
ok("AND both true",  fbEval(both, { text: "i buy", qty: "5" }), true);
ok("AND one false",  fbEval(both, { text: "i buy", qty: "1" }), false);
ok("OR first",       fbEval(either, { text: "hi" }), true);
ok("OR second",      fbEval(either, { text: "hey" }), true);
ok("OR neither",     fbEval(either, { text: "yo" }), false);

// ── 4) Telegram + TikTok evaluators behave identically ──────────────────────
console.log("── parity: telegram + tiktok match facebook ──");
const cases = [one("text", "equals", "price"), one("text", "contains", "pric"), both, either];
const inputs = [{ text: "price" }, { text: "pricing", qty: "5" }, { text: "i buy", qty: "5" }, { text: "hey" }];
let parity = true;
cases.forEach((c, i) => {
  const f = fbEval(c, inputs[i]);
  if (tgEval(c, inputs[i]) !== f || ttEval(c, inputs[i]) !== f) parity = false;
});
ok("telegram & tiktok identical to facebook", parity, true);

console.log(`\nTOTAL: ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
