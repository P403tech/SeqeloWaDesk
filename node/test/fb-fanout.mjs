// Fan-out walk test: a Trigger (or any node) wired to several nodes must fire
// ALL of them, one after another — not just the first edge. Stubs axios at the
// network boundary and asserts what the flow actually sent.
//
//   node node/test/fb-fanout.mjs

import axios from "axios";

const sent = [];   // every /messages payload the flow tried to send
axios.post = async (url, body, _cfg) => {
  const u = String(url);
  if (u.includes("/messages")) {
    let label = "";
    if (body?.message?.text) label = body.message.text;
    else if (body?.message?.attachment?.type) label = `[${body.message.attachment.type}]`;
    if (body?.message?.quick_replies) label += " <qr>";
    sent.push(label.trim());
  }
  return { status: 200, data: { message_id: "mid_" + (sent.length) } };
};
axios.get = async () => ({ status: 200, data: {} });

const fb = await import("../services/facebookFlowService.js");

let pass = 0, fail = 0;
const ok = (n, cond) => { cond ? pass++ : fail++; console.log(`${cond ? "PASS" : "FAIL"}  ${n}`); };

const AUTH = { base: "https://graph.facebook.com/v20.0", pageId: "PAGE1", token: "TKN" };
const run = async (flow, text, psid = "PSID1") => {
  sent.length = 0;
  await fb.runFlow({ auth: AUTH, flow, psid, text, flowId: "F1", pageId: "PAGE1", workspaceId: 1, appDomain: "http://x", vars: {} });
};
const N = (id, type, data = {}, isStart = false) => ({ id, type, data, isStart });
const E = (source, target, sourceHandle = "out") => ({ id: `${source}-${target}`, source, target, sourceHandle });

// 1) Trigger fanned out to THREE message nodes -> all three send, in order.
await run({
  flowNodes: [ N("t", "trigger", {}, true), N("a", "message", { text: "A" }), N("b", "message", { text: "B" }), N("c", "message", { text: "C" }) ],
  flowEdges: [ E("t", "a"), E("t", "b"), E("t", "c") ],
}, "hi");
ok("trigger -> 3 messages sends all three", sent.length === 3 && sent.join(",") === "A,B,C");

// 2) Trigger -> [message X, buttons (parking), message Y] -> both messages send,
//    the menu sends, and the chat parks (waiting for the tap).
await run({
  flowNodes: [ N("t", "trigger", {}, true), N("x", "message", { text: "X" }),
    N("qr", "buttons", { prompt: "Pick", options: ["One", "Two"] }), N("y", "message", { text: "Y" }) ],
  flowEdges: [ E("t", "x"), E("t", "qr"), E("t", "y") ],
});
ok("fan-out with a parking node still sends the message branches", sent.includes("X") && sent.includes("Y"));
ok("the menu itself was sent", sent.some((s) => s.includes("<qr>") || s.includes("template") || s.startsWith("Pick")));
ok("chat parked after fan-out (waiting for the tap)", fb.hasSession("PAGE1", "PSID1") === true);

// 3) Diamond merge: t -> a, t -> b, a -> m, b -> m (shared node) -> m runs ONCE.
await run({
  flowNodes: [ N("t", "trigger", {}, true), N("a", "message", { text: "A" }), N("b", "message", { text: "B" }), N("m", "message", { text: "M" }), N("e", "end") ],
  flowEdges: [ E("t", "a"), E("t", "b"), E("a", "m"), E("b", "m"), E("m", "e") ],
}, "hi");
ok("merge node runs exactly once (no duplicate send)", sent.filter((s) => s === "M").length === 1);

// 4) Condition still fires only the matched port (NOT both branches).
const condFlow = {
  flowNodes: [ N("t", "trigger", {}, true),
    N("c", "condition", { conditions: [{ variable: "text", operator: "equals", value: "price" }], operators: [] }),
    N("p", "message", { text: "PRICE" }), N("n", "message", { text: "OTHER" }) ],
  flowEdges: [ E("t", "c"), E("c", "p", "yes"), E("c", "n", "no") ],
};
await run(condFlow, "price");
ok("condition YES branch only", sent.length === 1 && sent[0] === "PRICE");
await run(condFlow, "banana");
ok("condition NO branch only", sent.length === 1 && sent[0] === "OTHER");

// 5) A single linear chain still works (no regression).
await run({
  flowNodes: [ N("t", "trigger", {}, true), N("a", "message", { text: "1" }), N("b", "message", { text: "2" }), N("e", "end") ],
  flowEdges: [ E("t", "a"), E("a", "b"), E("b", "e") ],
}, "hi");
ok("linear chain 1 -> 2 still sends both in order", sent.join(",") === "1,2");

console.log(`\nTOTAL: ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
