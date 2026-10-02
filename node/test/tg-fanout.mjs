// Telegram fan-out port check — confirms the shared refactor works on a sibling
// engine. Stubs axios; asserts a Trigger wired to several nodes fires them all.
//   node node/test/tg-fanout.mjs
import axios from "axios";
const sent = [];
axios.post = async (url, body) => {
  if (String(url).includes("/sendMessage")) sent.push(String(body?.text ?? ""));
  return { status: 200, data: { ok: true, result: { message_id: sent.length } } };
};
axios.get = async () => ({ status: 200, data: { ok: true, result: {} } });
const tg = await import("../services/telegramFlowService.js");

let pass = 0, fail = 0;
const ok = (n, c) => { c ? pass++ : fail++; console.log(`${c ? "PASS" : "FAIL"}  ${n}`); };
const AUTH = { base: "https://api.telegram.org", token: "TKN" };
const run = async (flow, text) => { sent.length = 0; await tg.runFlow({ auth: AUTH, flow, chatId: "C1", text, flowId: "F1", botId: "B1", workspaceId: 1, appDomain: "http://x", vars: {} }); };
const N = (id, type, data = {}, isStart = false) => ({ id, type, data, isStart });
const E = (s, t, h = "out") => ({ id: `${s}-${t}`, source: s, target: t, sourceHandle: h });

await run({ flowNodes: [N("t", "trigger", {}, true), N("a", "message", { text: "A" }), N("b", "message", { text: "B" }), N("c", "message", { text: "C" })], flowEdges: [E("t", "a"), E("t", "b"), E("t", "c")] }, "hi");
ok("trigger -> 3 messages sends all three", sent.join(",") === "A,B,C");

const cond = { flowNodes: [N("t", "trigger", {}, true), N("c", "condition", { conditions: [{ variable: "text", operator: "equals", value: "price" }], operators: [] }), N("p", "message", { text: "P" }), N("n", "message", { text: "N" })], flowEdges: [E("t", "c"), E("c", "p", "yes"), E("c", "n", "no")] };
await run(cond, "price"); ok("condition YES only", sent.join(",") === "P");
await run(cond, "x"); ok("condition NO only", sent.join(",") === "N");

await run({ flowNodes: [N("t", "trigger", {}, true), N("a", "message", { text: "1" }), N("b", "message", { text: "2" }), N("e", "end")], flowEdges: [E("t", "a"), E("a", "b"), E("b", "e")] }, "hi");
ok("linear chain still works", sent.join(",") === "1,2");

console.log(`\nTOTAL: ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
