// Fan-out traversal check for the remaining channel engines. Each engine logs
// `[XX-NODE] → type=... id=<id>` when it visits a node, so we capture console
// output and assert a Trigger wired to 3 message nodes VISITS all three (fan-out)
// — independent of each engine's send payload shape. axios stubbed = no network.
//   node node/test/all-fanout.mjs
import axios from "axios";
axios.post = async () => ({ status: 200, data: { ok: true, result: { message_id: 1 }, message_id: 1, data: [{ id: 1 }], messages: [{ id: 1 }] } });
axios.get = async () => ({ status: 200, data: { ok: true } });

const N = (id, type, data = {}, isStart = false) => ({ id, type, data, isStart });
const E = (s, t, h = "out") => ({ id: `${s}-${t}`, source: s, target: t, sourceHandle: h });
const FANOUT = { flowNodes: [N("t", "trigger", {}, true), N("na", "message", { text: "A" }), N("nb", "message", { text: "B" }), N("nc", "message", { text: "C" })], flowEdges: [E("t", "na"), E("t", "nb"), E("t", "nc")] };
const AUTH = { base: "https://x", token: "T", accessToken: "T", access_token: "T", pageId: "P", pageAccessToken: "T", channelSecret: "T", secret: "T", appId: "1", appSecret: "s" };

const engines = [
  ["tiktok",    (f) => ({ auth: AUTH, flow: f, convId: "C", text: "hi", flowId: "F", accountId: "A", workspaceId: 1, appDomain: "http://x", vars: {} })],
  ["line",      (f) => ({ auth: AUTH, flow: f, userId: "U", text: "hi", flowId: "F", channelId: "CH", workspaceId: 1, appDomain: "http://x", vars: {} })],
  ["wechat",    (f) => ({ flow: f, openid: "O", text: "hi", flowId: "F", channelId: "CH", workspaceId: 1, appDomain: "http://x", vars: {} })],
  ["viber",     (f) => ({ auth: AUTH, flow: f, userId: "U", text: "hi", flowId: "F", channelId: "CH", workspaceId: 1, appDomain: "http://x", vars: {} })],
  ["instagram", (f) => ({ auth: AUTH, flow: f, igsid: "IG", text: "hi", commentId: "", flowId: "F", accountId: "A", workspaceId: 1, appDomain: "http://x", vars: {} })],
  ["webchat",   (f) => ({ flow: f, conversationId: "CV", text: "hi", flowId: "F", widgetId: "W", workspaceId: 1, appDomain: "http://x", authToken: "x", vars: {} })],
  ["email",     (f) => ({ flow: f, conversationId: "CV", text: "hi", flowId: "F", accountId: "A", workspaceId: 1, appDomain: "http://x", authToken: "x", vars: {} })],
];

let pass = 0, fail = 0;
for (const [name, args] of engines) {
  const mod = await import(`../services/${name}FlowService.js`);
  const lines = [];
  const orig = console.log, origWarn = console.warn, origErr = console.error;
  console.log = (...a) => lines.push(a.join(" "));
  console.warn = () => {}; console.error = () => {};
  try { await mod.runFlow(args(FANOUT)); } catch (e) { lines.push("THREW " + e.message); }
  console.log = orig; console.warn = origWarn; console.error = origErr;
  const visited = (id) => lines.some((l) => l.includes("type=message") && l.includes(`id=${id}`));
  const all3 = visited("na") && visited("nb") && visited("nc");
  all3 ? pass++ : fail++;
  console.log(`${all3 ? "PASS" : "FAIL"}  ${name}: trigger → 3 messages all visited${all3 ? "" : `  (na=${visited("na")} nb=${visited("nb")} nc=${visited("nc")})`}`);
}
console.log(`\nTOTAL: ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
