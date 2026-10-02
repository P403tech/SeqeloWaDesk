// WhatsApp (flowService.js) fan-out test — a Trigger/any node wired to several
// nodes must fire ALL of them, not just the first edge. Stubs the Baileys sock;
// asserts what the flow sent. No network (Message/End need no Laravel).
//   node node/test/wa-fanout.mjs

import { executeFlowNode } from '../services/flowService.js';

const sent = [];
const sock = {
  user: { id: '999:1@s.whatsapp.net', name: 'FanoutTest' },
  ws: { readyState: 1 },
  sendMessage: async (_jid, payload) => {
    if (payload && typeof payload.text === 'string') sent.push(payload.text);
    return { key: { id: 'T_' + Date.now() } };
  },
};
const appLocals = { activeFlowSessions: {}, clients: {}, appDomainName: 'http://127.0.0.1:1' };

const KEY = '999_888';
function seed(flowData) {
  appLocals.activeFlowSessions[KEY] = {
    sessionId: 't', flowId: 999, flowData, currentNodeId: null,
    userVariables: { user_message: 'hi', name: 'X' }, messageHistory: [],
    status: 'active', startedAt: new Date().toISOString(),
  };
}
const msg = (id, text) => ({ id, type: 'message', flowNodeType: 'Message', flowReplies: [{ flowReplyType: 'Text', data: text }] });
const trig = (id) => ({ id, type: 'trigger' });               // no flowNodeType → default → moveToNextNode
const end = (id) => ({ id, type: 'end', flowNodeType: 'End' });
const edge = (s, t) => ({ sourceNodeId: `${s}_1`, targetNodeId: `${t}_1` });

let pass = 0, fail = 0;
const ok = (n, c) => { c ? pass++ : fail++; console.log(`${c ? 'PASS' : 'FAIL'}  ${n}${c ? '' : `  (sent=${JSON.stringify(sent)})`}`); };
async function run(flowData) {
  sent.length = 0;
  seed(flowData);
  await executeFlowNode(flowData.flowNodes[0], '888', '999', sock, appLocals, KEY);
}

// 1) Trigger fanned out to THREE message nodes → all three send.
await run({ workspace_id: 1, flowNodes: [trig('t'), msg('a', 'A'), msg('b', 'B'), msg('c', 'C')],
  flowEdges: [edge('t', 'a'), edge('t', 'b'), edge('t', 'c')] });
ok('trigger → 3 messages sends all three', sent.length === 3 && sent.sort().join(',') === 'A,B,C');

// 2) Fan-out where ONE branch ends the flow → siblings STILL send (guard works).
await run({ workspace_id: 1, flowNodes: [trig('t'), msg('a', 'A'), msg('b', 'B'), msg('c', 'C'), end('e')],
  flowEdges: [edge('t', 'a'), edge('t', 'b'), edge('t', 'c'), edge('a', 'e')] });
ok('fan-out with an End branch still sends all siblings', sent.length === 3 && sent.sort().join(',') === 'A,B,C');

// 3) A message chained to another (linear) still sends both — no regression.
await run({ workspace_id: 1, flowNodes: [trig('t'), msg('a', '1'), msg('b', '2')],
  flowEdges: [edge('t', 'a'), edge('a', 'b')] });
ok('linear chain 1 → 2 still sends both', sent.length === 2 && sent.sort().join(',') === '1,2');

// 4) Single out-edge (the classic case) still works.
await run({ workspace_id: 1, flowNodes: [trig('t'), msg('a', 'solo')], flowEdges: [edge('t', 'a')] });
ok('single branch still sends', sent.join(',') === 'solo');

console.log(`\nTOTAL: ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
