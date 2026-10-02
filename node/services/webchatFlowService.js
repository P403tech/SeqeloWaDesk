// services/webchatFlowService.js
// =============================
// Embedded chat-widget flow engine — the Node counterpart of emailFlowService,
// which it is ported from. The widget is the ONLY channel with no external API
// to call: a "send" is just an outbound row in WaDesk's own inbox, which the
// visitor's widget picks up on its next /history poll. So this engine holds no
// credentials at all and delegates every send back to Laravel
// (/api/widget/flow-send). Node only orchestrates: walk the graph, real `await`
// on Delay, park/resume, and ask PHP for smart nodes (ai/webhook via
// /api/widget/flow-node).
//
// `buttons` render as a numbered option list in the widget bubble (PHP decides
// the exact rendering); the visitor's reply comes back as plain text, which
// resume matches by label or 1-based number.
//
// The quoted-text helpers below are inherited from the email port. Widget text
// never carries a quoted chain or signature, so they are a harmless no-op here
// — kept rather than stripped so the two engines stay diffable against each
// other when the shared graph-walking logic changes. PURELY ADDITIVE.
import axios from "axios";

const WEBCHAT_SESSIONS = new Map(); // `${widgetId}_${conversationId}` → session

const nodeHeaders = (token) => ({ "X-Node-Token": token || process.env.NODE_WEBHOOK_TOKEN || "", Accept: "application/json" });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sessionKeyFor = (widgetId, conversationId) => `${widgetId}_${conversationId}`;

// ── Send layer — everything goes through PHP (server-side creds + inbox mirror)
async function phpSend(ctx, payload) {
  try {
    await axios.post(`${ctx.appDomain}/api/widget/flow-send`, payload, { headers: nodeHeaders(ctx.authToken), timeout: 20000, validateStatus: () => true });
  } catch (e) {
    console.warn(`[WC-FLOW-NODE] flow-send failed: ${e?.message}`);
  }
}
const sendText    = (ctx, text)        => phpSend(ctx, { widgetId: ctx.widgetId, conversationId: ctx.conversationId, kind: "text", text: String(text || "") });
const sendChoices = (ctx, text, opts)  => phpSend(ctx, { widgetId: ctx.widgetId, conversationId: ctx.conversationId, kind: "menu", text: String(text || ""), options: opts.map((o) => ({ title: String(o.title ?? o) })) });
const sendMedia   = (ctx, kind, url, caption) => phpSend(ctx, { widgetId: ctx.widgetId, conversationId: ctx.conversationId, kind: "media", mediaKind: kind, mediaUrl: url, text: caption || "" });

async function askLaravel(ctx, payload) {
  const r = await axios.post(`${ctx.appDomain}/api/widget/flow-node`, payload, { headers: nodeHeaders(ctx.authToken), timeout: 60000, validateStatus: () => true });
  if (r.status >= 400) throw new Error(`flow-node HTTP ${r.status}: ${JSON.stringify(r.data)}`);
  return r.data || {};
}

// ── Graph helpers (identical to the WeChat/LINE/Telegram engines) ────────────
const nodesOf = (flow) => (flow?.flowNodes || flow?.nodes || []);
const edgesOf = (flow) => (flow?.flowEdges || flow?.edges || []);
function indexNodes(flow) { const m = new Map(); for (const n of nodesOf(flow)) if (n?.id) m.set(String(n.id), n); return m; }
function nextNode(flow, nodeId, port = "out") {
  let any = null;
  for (const e of edgesOf(flow)) {
    if (String(e?.source) !== String(nodeId)) continue;
    if (any === null) any = String(e?.target || "");
    if (String(e?.sourceHandle || "out") === port) return String(e?.target || "");
  }
  return port === "out" ? any : null;
}
/** ALL nodes wired to nodeId on `port` (edge order) — fan-out counterpart of nextNode. */
function nextTargets(flow, nodeId, port = "out") {
  const out = [];
  for (const e of edgesOf(flow)) {
    if (String(e?.source) !== String(nodeId)) continue;
    if (String(e?.sourceHandle || "out") === port) out.push(String(e?.target || ""));
  }
  if (out.length === 0 && port === "out") {
    for (const e of edgesOf(flow)) if (String(e?.source) === String(nodeId)) out.push(String(e?.target || ""));
  }
  return out.filter(Boolean);
}
function entryNode(flow) { for (const n of nodesOf(flow)) if (String(n?.type) === "trigger") return n; return null; }
const subst = (s, vars) => String(s ?? "").replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (_, k) => String(vars?.[k] ?? ""));
export function delayMsOf(d) {
  const amount = Number(d?.amount ?? d?.delay ?? d?.value ?? 0);
  if (!(amount > 0)) return 0;
  const unit = String(d?.unit || "min").toLowerCase();
  const mult = unit.startsWith("s") ? 1000 : unit.startsWith("h") ? 3_600_000 : unit.startsWith("d") ? 86_400_000 : 60_000;
  return Math.round(amount * mult);
}
const chatOptions = (d) =>
  (d?.options || [])
    .map((o, i) => ({ title: String(typeof o === "object" ? (o.title ?? o.label ?? "") : o).trim(), payload: `OPT_${i}` }))
    .filter((o) => o.title !== "")
    .slice(0, 10);

// ── Email reply normalization ───────────────────────────────────────────
// Unlike a tapped WeChat/LINE menu, an email reply arrives as the WHOLE body:
// the customer's words, then the quoted chain ("On <date> ... wrote:", "> ..."),
// then a signature. Matching an option label — or saving an answer — against
// that blob never works, so cut at the first quote/signature marker.
const QUOTE_MARKERS = [
  /^\s*>/,                                   // quoted line
  /^\s*on\b.*\bwrote:\s*$/i,                 // Gmail / Apple attribution
  /^\s*-{2,}\s*original message\s*-{2,}/i,   // Outlook
  /^\s*_{5,}\s*$/,                           // Outlook separator rule
  /^\s*from:\s*\S/i,                         // forwarded header block
  /^\s*--\s*$/,                              // signature delimiter
  /^\s*sent from my\b/i,                     // mobile signature
];
export function stripQuoted(text) {
  const keep = [];
  for (const line of String(text || "").replace(/\r\n?/g, "\n").split("\n")) {
    if (QUOTE_MARKERS.some((re) => re.test(line))) break;
    keep.push(line);
  }
  const cut = keep.join("\n").trim();
  // A reply that is nothing BUT quoted text still has to answer an `ask`.
  return cut !== "" ? cut : String(text || "").trim();
}
/** The single line an option / menu reply is expected to be. */
export function replyAnswer(text) {
  const first = stripQuoted(text).split("\n").map((l) => l.trim()).find((l) => l !== "");
  return String(first ?? "").trim();
}
/**
 * Which option a reply picked, or null. "2", "2.", "Support" and the whole
 * rendered line "2. Support" all resolve to the same index.
 */
function matchOption(opts, text) {
  const t = replyAnswer(text);
  if (t === "") return null;
  const num = t.match(/^(\d{1,2})\s*[.)\-:]?$/);
  if (num) {
    const i = parseInt(num[1], 10);
    return i >= 1 && i <= opts.length ? i - 1 : null;
  }
  const lower = t.toLowerCase();
  const bare = t.replace(/^\d{1,2}\s*[.)\-:]\s*/, "").trim().toLowerCase();
  for (let i = 0; i < opts.length; i++) {
    const label = String(opts[i].title).toLowerCase().trim();
    if (label !== "" && (lower === label || bare === label)) return i;
  }
  return null;
}

// ── Condition ────────────────────────────────────────────────────────────
// The builder persists `data.conditions[]` + `data.operators[]` (AND/OR
// joiners) — the same shape flowService reads. Reading the flat
// {variable,value,operator} keys instead made every rule fall through to
// `contains ""` and evaluate TRUE, so the ELSE port was unreachable. The flat
// shape is still honoured for hand-written / imported graphs.
const condIsSet = (v) => {
  if (v === undefined || v === null) return false;
  if (typeof v === "string") return v.trim() !== "";
  if (Array.isArray(v)) return v.length > 0;
  if (typeof v === "object") return Object.keys(v).length > 0;
  return true;
};
function condVar(name, vars) {
  const key = String(name ?? "").trim().replace(/^\{\{\s*/, "").replace(/\s*\}\}$/, "").trim();
  if (key === "") return undefined;
  const bag = vars || {};
  if (Object.prototype.hasOwnProperty.call(bag, key)) return bag[key];
  const hit = Object.keys(bag).find((k) => k.toLowerCase() === key.toLowerCase());
  return hit === undefined ? undefined : bag[hit];
}
function evalOneCondition(c, vars) {
  const op = String(c?.operator ?? c?.op ?? "contains").toLowerCase().trim().replace(/\s+/g, "_");
  const resolved = condVar(c?.variable ?? c?.left, vars);
  if (op === "exists" || op === "is_set") return condIsSet(resolved);
  if (op === "not_exists" || op === "is_not_set") return !condIsSet(resolved);
  // Same fallback the WhatsApp engine uses: a blank variable compares against
  // whatever the customer last wrote.
  const src = condIsSet(resolved) ? resolved : String(vars?.text ?? "");
  const uRaw = typeof src === "object" ? JSON.stringify(src) : String(src);
  const u = uRaw.toLowerCase().trim();
  const checkValue = c?.value ?? c?.right ?? "";
  const v = String(checkValue).toLowerCase().trim();
  switch (op) {
    case "equals": case "=": case "==":   return u === v;
    case "not_equals": case "!=":         return u !== v;
    case "contains":                      return u.includes(v);
    case "not_contains":                  return !u.includes(v);
    case "gt": case "greater_than":       return parseFloat(uRaw) > parseFloat(checkValue);
    case "lt": case "less_than":          return parseFloat(uRaw) < parseFloat(checkValue);
    case "is_empty":                      return u === "";
    case "is_not_empty":                  return u !== "";
    case "starts_with":                   return u.startsWith(v);
    case "ends_with":                     return u.endsWith(v);
    default:
      console.warn(`[WC-FLOW-NODE] condition: unknown operator "${c?.operator}" — treated as FALSE`);
      return false;
  }
}
function evalCondition(d, vars) {
  const rules = Array.isArray(d?.conditions) && d.conditions.length
    ? d.conditions
    : [{ variable: d?.variable ?? d?.left, operator: d?.operator ?? d?.op, value: d?.value ?? d?.right }];
  const joiners = Array.isArray(d?.logicOperators) ? d.logicOperators
                : Array.isArray(d?.operators)      ? d.operators
                : [];
  let met = evalOneCondition(rules[0], vars);
  for (let i = 1; i < rules.length; i++) {
    const j = String(joiners[i - 1] || "AND").toUpperCase();
    const next = evalOneCondition(rules[i], vars);
    met = j === "OR" ? (met || next) : (met && next);
  }
  console.log(`[WC-FLOW-NODE] condition rules=${rules.length} -> ${met ? "TRUE (yes port)" : "FALSE (no port)"}`);
  return met;
}

// ── The walker ───────────────────────────────────────────────────────────────
async function walk(ctx, startId, opts = {}) {
  const nodes = indexNodes(ctx.flow);
  const visited = new Set();
  const state = { parked: false, steps: 0 };
  console.log(`[EM-WALK] start flow=${ctx.flowId} conversation=${ctx.conversationId} from=${startId} fan-out nodes=${nodes.size}`);
  if (opts.fromPort) {
    visited.add(String(startId));
    for (const t of nextTargets(ctx.flow, startId, opts.fromPort)) await walkNode(ctx, nodes, t, visited, state);
  } else {
    await walkNode(ctx, nodes, startId, visited, state);
  }
  if (!state.parked) clearSession(ctx.widgetId, ctx.conversationId);
}

/** Run ONE node then fan out to EVERY node on its active port (see facebookFlowService for rationale). */
async function walkNode(ctx, nodes, id, visited, state) {
  if (!id) return;
  if (state.steps++ > 300) { console.warn(`[EM-WALK] step guard — possible loop flow=${ctx.flowId}`); return; }
  const key = String(id);
  if (visited.has(key)) return;
  visited.add(key);
  const node = nodes.get(key);
  if (!node) { console.warn(`[EM-WALK] node id="${id}" NOT FOUND — flow=${ctx.flowId}`); return; }

  const { flow, conversationId, appDomain, widgetId, flowId, workspaceId } = ctx;
    const type = String(node.type || "");
    const d = node.data || {};
    let port = "out";
    console.log(`[EM-NODE] → type=${type} id=${node.id} flow=${flowId}`);

    try {
      switch (type) {
        case "trigger": break;   // entry node — fan out only
        case "message": {
          const body = subst(d.text, ctx.vars);
          if (body.trim() !== "") await sendText(ctx, body);
          break;
        }
        case "media": {
          let url = subst(d.url ?? d.mediaUrl, ctx.vars).trim();
          if (url && !/^https?:\/\//i.test(url) && !url.startsWith("data:")) {
            url = `${String(appDomain).replace(/\/+$/, "")}${url.startsWith("/") ? "" : "/"}${url}`;
          }
          const kind = String(d.kind ?? d.mediaType ?? "image").toLowerCase();
          const cap = subst(d.caption, ctx.vars).trim();
          if (url) await sendMedia(ctx, kind, url, cap || undefined);
          else if (cap) await sendText(ctx, cap);
          break;
        }
        case "buttons": {
          const body = subst(d.prompt ?? d.text, ctx.vars);
          const opts = chatOptions(d);
          await sendChoices(ctx, body, opts);
          if (!state.parked) { park(ctx, node.id); state.parked = true; }
          return; // wait for the reply
        }
        case "ask": {
          const q = subst(d.prompt ?? d.question ?? d.text, ctx.vars).trim();
          if (q) await sendText(ctx, q);
          if (!state.parked) { park(ctx, node.id); state.parked = true; }
          return; // wait for the answer
        }
        case "delay": {
          const ms = delayMsOf(d);
          if (ms > 0) { console.log(`[WC-FLOW-NODE] delay node=${node.id} ${ms}ms`); await sleep(ms); }
          break;
        }
        case "condition":
          port = evalCondition(d, ctx.vars) ? "yes" : "no";
          break;
        case "webhook": {
          const out = await askLaravel(ctx, { action: "webhook", node: d, vars: ctx.vars, workspaceId });
          if (out?.vars) Object.assign(ctx.vars, out.vars);
          break;
        }
        case "ai":
        case "email_ai": {
          const out = await askLaravel(ctx, { action: "ai", node: d, vars: ctx.vars, workspaceId, widgetId, conversationId });
          const reply = String(out?.reply || "");
          if (reply) await sendText(ctx, reply);
          const saveKey = String(d.save || "").trim();
          if (saveKey) ctx.vars[saveKey] = reply;
          break;
        }
        case "end":
          // End THIS branch only; session cleared at the top of walk().
          console.log(`[WC-FLOW-NODE] end flow=${flowId} conversation=${conversationId}`);
          return;
        default:
          console.warn(`[WC-FLOW-NODE] node type "${type}" has no executor — skipped (flow=${flowId} node=${node.id})`);
      }
    } catch (e) {
      console.error(`[WC-FLOW-NODE] node ${node.id} (${type}) failed: ${e?.message}`);
    }

    // Fan out to every node wired to this node's active port (edge order).
    for (const t of nextTargets(flow, node.id, port)) {
      await walkNode(ctx, nodes, t, visited, state);
    }
}

// ── Session state ────────────────────────────────────────────────────────────
function park(ctx, nodeId) {
  const key = sessionKeyFor(ctx.widgetId, ctx.conversationId);
  WEBCHAT_SESSIONS.set(key, {
    widgetId: ctx.widgetId, conversationId: ctx.conversationId, workspaceId: ctx.workspaceId,
    flowId: ctx.flowId, flow: ctx.flow, appDomain: ctx.appDomain, authToken: ctx.authToken,
    nodeId: String(nodeId), vars: ctx.vars, parkedAt: Date.now(),
  });
  console.log(`[WC-FLOW-NODE] parked at node=${nodeId} key=${key}`);
}
function clearSession(widgetId, conversationId) { WEBCHAT_SESSIONS.delete(sessionKeyFor(widgetId, conversationId)); }
export const hasSession = (widgetId, conversationId) => WEBCHAT_SESSIONS.has(sessionKeyFor(widgetId, conversationId));
export function pruneSessions(maxAgeMs = 86_400_000) {
  const cutoff = Date.now() - maxAgeMs; let n = 0;
  for (const [k, s] of WEBCHAT_SESSIONS) if (s.parkedAt < cutoff) { WEBCHAT_SESSIONS.delete(k); n++; }
  return n;
}

// ── Public API ───────────────────────────────────────────────────────────────
export async function runFlow({ flow, conversationId, text, flowId, widgetId, workspaceId, appDomain, authToken, vars }) {
  clearSession(widgetId, conversationId);
  const start = entryNode(flow);
  if (!start) { console.warn(`[WC-FLOW-NODE] flow ${flowId} has no trigger node`); return false; }
  const ctx = {
    flow, conversationId, flowId, widgetId, workspaceId, appDomain, authToken,
    vars: { text: stripQuoted(text), text_raw: String(text || ""), conversationId: String(conversationId), ...(vars || {}) },
  };
  console.log(`[WC-FLOW-NODE] START flow=${flowId} account=${widgetId} conversation=${conversationId}`);
  await walk(ctx, start.id);   // fan out from the trigger
  return true;
}

/**
 * Would a resume actually TAKE this reply? The controller has to answer PHP
 * before the walk runs (a Delay node is a real await), and `consumed: true`
 * makes PHP skip routing / AI agent / keyword replies entirely — so an honest
 * "no" here is what stops one unmatched reply from silencing the thread until
 * the 24h session prune.
 */
export function canResume(widgetId, conversationId, text) {
  const sess = WEBCHAT_SESSIONS.get(sessionKeyFor(widgetId, conversationId));
  if (!sess) return false;
  const parked = indexNodes(sess.flow).get(String(sess.nodeId));
  if (!parked) return false;
  // `ask` takes ANY reply as its answer; only `buttons` needs a valid pick.
  if (String(parked.type || "") !== "buttons") return true;
  return matchOption(chatOptions(parked.data || {}), text) !== null;
}

export async function resumeFlow({ widgetId, conversationId, text, vars }) {
  const key = sessionKeyFor(widgetId, conversationId);
  const sess = WEBCHAT_SESSIONS.get(key);
  if (!sess) { console.log(`[EM-RESUME] no session key=${key}`); return false; }
  if (vars && typeof vars === "object") Object.assign(sess.vars, vars);
  const nodes = indexNodes(sess.flow);
  const parked = nodes.get(String(sess.nodeId));
  if (!parked) { console.warn(`[EM-RESUME] parked node "${sess.nodeId}" missing — dropping`); WEBCHAT_SESSIONS.delete(key); return false; }

  const d = parked.data || {};
  const type = String(parked.type || "");
  const body = stripQuoted(text);   // multi-line answers keep their shape
  const t = replyAnswer(text);      // the one line an option pick can be
  let port = "out";
  console.log(`[EM-RESUME] key=${key} parkedNode=${sess.nodeId} type=${type} reply="${t.slice(0, 40)}"`);

  if (type === "ask") {
    const saveKey = String(d.var || d.save || "").trim();
    if (saveKey) {
      sess.vars[saveKey] = body;
      sess.vars[`${saveKey}_raw`] = String(text || "");
    }
    const expected = (d.options || []).map((o) => String(o).trim()).filter(Boolean);
    if (expected.length) {
      port = "else";
      const lower = t.toLowerCase();
      for (let i = 0; i < expected.length; i++) if (lower === expected[i].toLowerCase()) { port = `p${i}`; break; }
    }
  }

  if (type === "buttons") {
    const opts = chatOptions(d);
    const idx = matchOption(opts, text);
    console.log(`[EM-RESUME] button match reply="${t}" -> idx=${idx}`);
    if (idx === null) {
      // Not a valid pick. canResume() already told the controller to answer
      // `consumed:false`, so PHP's keyword/AI fallback is handling this
      // message — drop the session instead of parking the thread for 24h.
      WEBCHAT_SESSIONS.delete(key);
      return false;
    }
    port = `p${idx}`;
    const saveKey = String(d.var || "").trim();
    if (saveKey) sess.vars[saveKey] = String(opts[idx].title || body);
  }

  WEBCHAT_SESSIONS.delete(key);
  sess.vars.text = body;
  sess.vars.text_raw = String(text || "");
  console.log(`[WC-FLOW-NODE] RESUME flow=${sess.flowId} from=${sess.nodeId} port=${port}`);
  await walk({ ...sess, vars: sess.vars }, sess.nodeId, { fromPort: port });
  return true;
}

export default { runFlow, resumeFlow, canResume, hasSession, pruneSessions, delayMsOf, stripQuoted, replyAnswer };
