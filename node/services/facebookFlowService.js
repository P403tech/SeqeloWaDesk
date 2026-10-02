// services/facebookFlowService.js
// ================================
// Facebook Messenger flow engine — the Node counterpart of flowService.js and
// a near-verbatim clone of instagramFlowService.js with ONLY the send layer
// swapped to the Facebook Messenger (Page) Send API.
//
// WHY THIS EXISTS
// ---------------
// Facebook Messenger flows used to run in PHP inside Meta's webhook request.
// That works, but a webhook handler cannot sleep, so a "Wait 5 min" node had to
// park the session in the DB and rely on a later request to sweep it — meaning
// the wait fired late on a quiet Page. Baileys flows never had that problem
// because Node is a long-lived process and can simply `await` a timer.
//
// This module gives Messenger the SAME model as WhatsApp/Instagram: Laravel
// hands the flow off, Node walks it in the background, real awaits for delays,
// and the customer's next message resumes a parked node.
//
// PURELY ADDITIVE. Nothing here is imported by flowService.js or the Baileys
// client manager — the WhatsApp path is untouched. Mirrors the precedent set by
// instagramFlowService.js, which added Instagram the same way.
//
// DIVISION OF LABOUR
//   Node    → walks the graph, times the delays, calls the Send API to send
//   Laravel → business logic that needs DB/keys (AI reply, catalog carousel,
//             lead capture) and all message logging, reached over
//             /api/facebook/flow-node and /api/facebook/flow-log.
import axios from "axios";

const FB_SESSIONS = new Map(); // `${pageId}_${psid}` → session

const nodeHeaders = () => ({
  "X-Node-Token": process.env.NODE_WEBHOOK_TOKEN || "",
  Accept: "application/json",
});

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sessionKeyFor = (pageId, psid) => `${pageId}_${psid}`;

// ---------------------------------------------------------------------------
// Facebook Messenger Send API. Deliberately self-contained — these are the
// message shapes a Messenger flow needs. Unlike Instagram, Facebook supports
// the button template and the 'file' (document) attachment type.
// ---------------------------------------------------------------------------
async function graphSend(auth, message) {
  const base = String(auth?.base || "").replace(/\/+$/, "");
  const pageId = String(auth?.pageId || auth?.page_id || "");
  const token = String(auth?.token || "");
  if (!base || !pageId || !token) throw new Error("facebook auth incomplete (base/pageId/token)");

  // FULL request logging — the exact JSON body sent to Meta (token redacted).
  const kind = message?.message?.attachment?.payload?.template_type
    ? `template:${message.message.attachment.payload.template_type}`
    : message?.message?.attachment?.type
      ? `attachment:${message.message.attachment.type}`
      : message?.message?.quick_replies
        ? "quick_replies"
        : "text";
  console.log(`[FB-SEND→] host=${base} page=${pageId} kind=${kind} body=${JSON.stringify(message)}`);

  // appsecret_proof — REQUIRED when the FB app has "Require App Secret" on, else
  // Graph rejects with #100 "API calls from the server require an appsecret_proof
  // argument". Laravel (FbFlowBridge) computes it (HMAC of the token with the app
  // secret) and passes it in auth; attach it whenever present.
  const proof = String(auth?.appsecret_proof || "");
  const r = await axios.post(
    `${base}/${pageId}/messages`,
    message,
    {
      params: { access_token: token, ...(proof ? { appsecret_proof: proof } : {}) },
      timeout: 30000,
      validateStatus: () => true,
    }
  );
  if (r.status >= 400 || r.data?.error) {
    const e = r.data?.error || { message: `HTTP ${r.status}` };
    console.error(`[FB-SEND✗] kind=${kind} HTTP ${r.status} error=${JSON.stringify(r.data?.error || r.data)}`);
    throw new Error(`${e.code || r.status}/${e.error_subcode || 0}: ${e.message || "graph error"}`);
  }
  console.log(`[FB-SEND✓] kind=${kind} HTTP ${r.status} resp=${JSON.stringify(r.data)}`);
  return r.data;
}

const sendText = (auth, psid, text) =>
  graphSend(auth, {
    recipient: { id: psid },
    messaging_type: "RESPONSE",
    message: { text: String(text || "") },
  });

// Facebook supports 'image', 'video', 'audio' AND 'file' (documents) — pass the
// type straight through so all four keep working.
const sendAttachment = (auth, psid, type, url) =>
  graphSend(auth, {
    recipient: { id: psid },
    messaging_type: "RESPONSE",
    message: { attachment: { type, payload: { url: String(url), is_reusable: true } } },
  });

// Meta caps quick replies at 13 and titles at 20 chars; over either and the
// whole send is rejected, so clamp rather than fail.
const sendQuickReplies = (auth, psid, text, options) =>
  graphSend(auth, {
    recipient: { id: psid },
    messaging_type: "RESPONSE",
    message: {
      text: String(text || ""),
      quick_replies: options.slice(0, 13).map((o, i) => ({
        content_type: "text",
        title: String(o.title ?? o).slice(0, 20),
        payload: String(o.payload ?? `OPT_${i}`),
      })),
    },
  });

// Facebook DOES support the Messenger "button" template (unlike Instagram). It
// renders text with up to 3 persistent, tappable buttons. If an imageUrl is
// supplied we upgrade to a single-element "generic" card so the image sits above
// the buttons; otherwise the plain button template is the primary shape.
const sendButtons = (auth, psid, text, buttons, imageUrl) => {
  const mapped = (buttons || []).slice(0, 3).map((b, i) => (
    String(b.type) === "web_url"
      ? { type: "web_url", url: String(b.url || ""), title: String(b.title || "").slice(0, 20) }
      : { type: "postback", title: String(b.title || b).slice(0, 20), payload: String(b.payload ?? b.title ?? `OPT_${i}`) }
  ));
  if (imageUrl) {
    // Generic card variant — image_url + the same buttons on one element.
    return graphSend(auth, {
      recipient: { id: psid },
      messaging_type: "RESPONSE",
      message: {
        attachment: {
          type: "template",
          payload: {
            template_type: "generic",
            elements: [{
              title: String(text || "Choose one").slice(0, 80) || "Choose one",
              image_url: String(imageUrl),
              buttons: mapped,
            }],
          },
        },
      },
    });
  }
  // Primary: button template.
  return graphSend(auth, {
    recipient: { id: psid },
    messaging_type: "RESPONSE",
    message: {
      attachment: {
        type: "template",
        payload: {
          template_type: "button",
          text: String(text || "Choose one").slice(0, 640) || "Choose one",
          buttons: mapped,
        },
      },
    },
  });
};

// The shared "buttons" node renders ≤3 options as a persistent card. On Facebook
// the generic template with one element does this cleanly (image optional).
const sendGenericButtons = (auth, psid, text, buttons, imageUrl) => {
  const el = {
    title: String(text || "Choose one").slice(0, 80) || "Choose one",
    buttons: buttons.slice(0, 3).map((b, i) => (
      String(b.type) === "web_url"
        ? { type: "web_url", url: String(b.url || ""), title: String(b.title || "").slice(0, 20) }
        : { type: "postback", title: String(b.title || b).slice(0, 20), payload: String(b.payload ?? b.title ?? `OPT_${i}`) }
    )),
  };
  if (imageUrl) el.image_url = String(imageUrl);
  return graphSend(auth, {
    recipient: { id: psid },
    messaging_type: "RESPONSE",
    message: { attachment: { type: "template", payload: { template_type: "generic", elements: [el] } } },
  });
};

// ---------------------------------------------------------------------------
// Laravel bridges — business logic and logging stay on the PHP side so there is
// exactly ONE implementation of AI / catalog / lead capture in the codebase.
// ---------------------------------------------------------------------------
async function logToLaravel(appDomain, payload) {
  try {
    await axios.post(`${appDomain}/api/facebook/flow-log`, payload,
      { headers: nodeHeaders(), timeout: 15000 });
  } catch (e) {
    // Never let a logging failure break the conversation.
    console.warn(`[FB-FLOW-NODE] flow-log failed: ${e?.message}`);
  }
}

async function askLaravel(appDomain, payload) {
  const r = await axios.post(`${appDomain}/api/facebook/flow-node`, payload,
    { headers: nodeHeaders(), timeout: 60000, validateStatus: () => true });
  if (r.status >= 400) throw new Error(`flow-node HTTP ${r.status}: ${JSON.stringify(r.data)}`);
  return r.data || {};
}

// ---------------------------------------------------------------------------
// Graph helpers
// ---------------------------------------------------------------------------
const nodesOf = (flow) => (flow?.flowNodes || flow?.nodes || []);
const edgesOf = (flow) => (flow?.flowEdges || flow?.edges || []);

function indexNodes(flow) {
  const map = new Map();
  for (const n of nodesOf(flow)) if (n?.id) map.set(String(n.id), n);
  return map;
}

/** Follow the edge leaving nodeId on `port`, falling back to any out edge. */
function nextNode(flow, nodeId, port = "out") {
  let any = null;
  for (const e of edgesOf(flow)) {
    if (String(e?.source) !== String(nodeId)) continue;
    if (any === null) any = String(e?.target || "");
    if (String(e?.sourceHandle || "out") === port) return String(e?.target || "");
  }
  return port === "out" ? any : null;
}

/**
 * ALL nodes wired to nodeId on `port` (in edge order) — the fan-out counterpart
 * of nextNode. A Trigger (or any node) connected to several nodes returns every
 * one, so the walker can fire them all instead of only the first edge.
 */
function nextTargets(flow, nodeId, port = "out") {
  const out = [];
  for (const e of edgesOf(flow)) {
    if (String(e?.source) !== String(nodeId)) continue;
    if (String(e?.sourceHandle || "out") === port) out.push(String(e?.target || ""));
  }
  // A plain node whose edges carry no explicit handle still flows on "out".
  if (out.length === 0 && port === "out") {
    for (const e of edgesOf(flow)) {
      if (String(e?.source) === String(nodeId)) out.push(String(e?.target || ""));
    }
  }
  return out.filter(Boolean);
}

function entryNode(flow) {
  for (const n of nodesOf(flow)) if (String(n?.type) === "trigger") return n;
  return null;
}

const subst = (s, vars) =>
  String(s ?? "").replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (_, k) => String(vars?.[k] ?? ""));

/** Wait node {amount, unit} → ms. Unknown unit falls back to minutes. */
export function delayMsOf(d) {
  const amount = Number(d?.amount ?? d?.delay ?? d?.value ?? 0);
  if (!(amount > 0)) return 0;
  const unit = String(d?.unit || "min").toLowerCase();
  const mult = unit.startsWith("s") ? 1000
    : unit.startsWith("h") ? 3_600_000
      : unit.startsWith("d") ? 86_400_000
        : 60_000;
  return Math.round(amount * mult);
}

/** Chat `buttons` options are plain strings; the Send API wants objects. */
const chatOptionsToQuickReplies = (d) =>
  (d?.options || [])
    .map((o, i) => ({ title: String(typeof o === "object" ? (o.title ?? o.label ?? "") : o).trim(), payload: `OPT_${i}` }))
    .filter((o) => o.title !== "")
    .slice(0, 13);

// Evaluate a condition node. The builder saves one or more condition ROWS under
// `d.conditions[]`, joined by `d.operators[]` ("and"/"or"). The whole expression
// decides the yes/no port. (Older/flat nodes may put a single
// {variable,operator,value} on `d` itself — still supported.)
//
// This MUST mirror `_flowEvalCondition` / `evaluateFlowConditions` in
// flowService.js (the WhatsApp engine) and `checkCond` in the builder's Test
// preview, or the port that fires live disagrees with what the author sees.
//
// The previous version read `d.variable/operator/value` DIRECTLY off the node —
// but those live inside `d.conditions[0]`, so all three were undefined: the
// operator fell back to "contains" and the value to "", and the default branch
// `right === "" ? true` made EVERY condition return true. That sent every input
// down the first "yes" port (e.g. always "we have price"), never reaching the
// later branches.
export function evalCondition(d, vars) {
  const rows    = Array.isArray(d?.conditions) && d.conditions.length ? d.conditions : [d];
  const joiners = Array.isArray(d?.operators) ? d.operators : [];

  // A bare name ("text") is a lookup in vars; a "{{text}}" template is
  // substituted. Blank names resolve to nothing (comparisons then fall back to
  // the inbound message below).
  const resolveVar = (name) => {
    const raw = String(name ?? "").trim();
    if (raw === "") return "";
    if (raw.includes("{{")) return subst(raw, vars);
    return String(vars?.[raw] ?? "");
  };

  const evalOne = (c) => {
    const op = String(c?.operator ?? c?.op ?? "equals").toLowerCase().trim().replace(/\s+/g, "_");
    const resolvedRaw = resolveVar(c?.variable ?? c?.left);

    // Presence tests read the variable ITSELF (no message fallback).
    if (op === "exists" || op === "is_set")         return String(resolvedRaw).trim() !== "";
    if (op === "not_exists" || op === "is_not_set") return String(resolvedRaw).trim() === "";

    // Comparisons fall back to the customer's last message when the named
    // variable is blank — same historical behaviour as the WhatsApp engine.
    let userRaw = resolvedRaw;
    if (!userRaw) userRaw = String(vars?.text ?? vars?.user_message ?? "");
    const checkRaw = c?.value ?? c?.right ?? "";
    const u = String(userRaw).toLowerCase().trim();
    const v = String(checkRaw).toLowerCase().trim();
    switch (op) {
      case "equals": case "=": case "==":  return u === v;
      case "not_equals": case "!=":        return u !== v;
      case "contains":                     return u.includes(v);
      case "not_contains":                 return !u.includes(v);
      case "gt": case "greater_than":      return parseFloat(userRaw) > parseFloat(checkRaw);
      case "lt": case "less_than":         return parseFloat(userRaw) < parseFloat(checkRaw);
      case "is_empty":                     return u === "";
      case "is_not_empty":                 return u !== "";
      case "starts_with":                  return u.startsWith(v);
      case "ends_with":                    return u.endsWith(v);
      default:
        console.warn(`[FB-COND] unknown operator "${c?.operator}" → FALSE`);
        return false;
    }
  };

  let result = evalOne(rows[0]);
  for (let i = 1; i < rows.length; i++) {
    const join = String(joiners[i - 1] || "AND").toUpperCase();
    const next = evalOne(rows[i]);
    result = join === "OR" ? (result || next) : (result && next);
  }
  return result;
}

// ---------------------------------------------------------------------------
// The walker
// ---------------------------------------------------------------------------
/**
 * Walk from `startId` until the flow ends or parks on a node awaiting the
 * customer. Delays are REAL awaits — this runs detached from any HTTP request,
 * which is the whole reason Messenger flows moved into Node.
 */
async function walk(ctx, startId, opts = {}) {
  const nodes = indexNodes(ctx.flow);
  const visited = new Set();
  const state = { parked: false, steps: 0 };
  console.log(`[FB-WALK] start flow=${ctx.flowId} psid=${ctx.psid} from=${startId} fan-out nodes=${nodes.size} vars=${JSON.stringify(ctx.vars || {})}`);
  if (opts.fromPort) {
    // Resume: the parked node already ran — fan out from ITS targets on the
    // chosen port, marking it visited so it can't run again.
    visited.add(String(startId));
    for (const t of nextTargets(ctx.flow, startId, opts.fromPort)) {
      await walkNode(ctx, nodes, t, visited, state);
    }
  } else {
    await walkNode(ctx, nodes, startId, visited, state);
  }
  // End the flow only when nothing in this delivery parked waiting for input.
  if (!state.parked) clearSession(ctx.pageId, ctx.psid);
}

/**
 * Run ONE node, then fan out to EVERY node wired to its active port — so a
 * Trigger (or any node) connected to several nodes fires ALL of them, one after
 * another, instead of only the first edge. `visited` makes each node run at most
 * once per delivery (guards merges/loops); the FIRST input node (buttons/ask)
 * reached parks the chat while the other send branches still fire.
 */
async function walkNode(ctx, nodes, id, visited, state) {
  if (!id) return;
  if (state.steps++ > 300) { console.warn(`[FB-WALK] step guard — possible loop flow=${ctx.flowId}`); return; }
  const key = String(id);
  if (visited.has(key)) return;
  visited.add(key);
  const node = nodes.get(key);
  if (!node) { console.warn(`[FB-WALK] node id="${id}" NOT FOUND — flow=${ctx.flowId}`); return; }

  const { auth, flow, psid, appDomain, pageId, flowId, workspaceId } = ctx;
    const type = String(node.type || "");
    const d = node.data || {};
    let port = "out";

    console.log(`[FB-NODE] → type=${type} id=${node.id} flow=${flowId} data=${JSON.stringify(d).slice(0, 300)}`);

    try {
      switch (type) {
        case "trigger": break;   // entry node — no send, just fan out
        // ---- shared nodes (same types the WhatsApp builder uses) ----------
        case "message": {
          const body = subst(d.text, ctx.vars);
          if (body.trim() !== "") {
            const r = await sendText(auth, psid, body);
            await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body, source: "flow", mid: r?.message_id || null });
          }
          break;
        }

        case "media": {
          let url = subst(d.url ?? d.mediaUrl, ctx.vars).trim();
          if (url && !/^https?:\/\//i.test(url) && !url.startsWith("data:")) {
            url = `${String(appDomain).replace(/\/+$/, "")}${url.startsWith("/") ? "" : "/"}${url}`;
          }
          let kind = String(d.kind ?? d.mediaType ?? "image").toLowerCase();
          if (kind === "document") kind = "file"; // Facebook's document attachment type
          if (url && ["image", "video", "audio", "file"].includes(kind)) {
            const r = await sendAttachment(auth, psid, kind, url);
            await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body: `[${kind}]`, source: "flow", mid: r?.message_id || null });
          } else if (url) {
            // Unknown kind — send the link as text rather than dropping the node.
            const r = await sendText(auth, psid, url);
            await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body: url, source: "flow", mid: r?.message_id || null });
          }
          const cap = subst(d.caption, ctx.vars).trim();
          if (cap) {
            const r = await sendText(auth, psid, cap);
            await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body: cap, source: "flow", mid: r?.message_id || null });
          }
          break;
        }

        case "buttons": {
          const body = subst(d.prompt ?? d.text, ctx.vars);
          const opts = chatOptionsToQuickReplies(d);
          // ≤3 options → persistent generic-template buttons. >3 → quick-reply
          // chips (generic/button templates cap at 3).
          const mode = (opts.length > 0 && opts.length <= 3) ? "generic-buttons" : "quick-replies";
          const r = mode === "generic-buttons"
            ? await sendGenericButtons(auth, psid, body, opts)
            : await sendQuickReplies(auth, psid, body, opts);
          console.log(`[FB-FLOW-NODE] buttons node=${node.id} mode=${mode} opts=${opts.length} resp=${JSON.stringify(r).slice(0, 200)}`);
          // Mirror the buttons into the inbox so the operator sees the same
          // tappable card, not just "What next?" as plain text.
          await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body, source: "flow", mid: r?.message_id || null, buttons: opts.map((o) => ({ title: String(o.title ?? o) })) });
          if (!state.parked) { park(ctx, node.id); state.parked = true; }
          return; // wait for the tap
        }

        case "ask": {
          const q = subst(d.prompt ?? d.question ?? d.text, ctx.vars).trim();
          if (q) {
            const r = await sendText(auth, psid, q);
            await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body: q, source: "flow", mid: r?.message_id || null });
          }
          if (!state.parked) { park(ctx, node.id); state.parked = true; }
          return; // wait for the answer
        }

        case "delay": {
          // THE POINT OF THIS MODULE. Node is long-lived, so a wait is just a
          // timer — no DB parking, no sweep, no dependence on later traffic.
          const ms = delayMsOf(d);
          if (ms > 0) {
            console.log(`[FB-FLOW-NODE] delay node=${node.id} ${ms}ms flow=${flowId}`);
            await sleep(ms);
          }
          break;
        }

        case "condition":
          port = evalCondition(d, ctx.vars) ? "yes" : "no";
          break;

        case "webhook": {
          // Laravel owns this: it already has the SSRF guard (scheme + public-IP
          // check) that must apply to an operator-supplied URL.
          const out = await askLaravel(appDomain, {
            action: "webhook", node: d, vars: ctx.vars, workspaceId,
          });
          if (out?.vars) Object.assign(ctx.vars, out.vars);
          break;
        }

        // ---- nodes whose logic lives in Laravel (AI keys, catalog, CRM) ----
        case "ai":
        case "fb_ai": {
          const out = await askLaravel(appDomain, {
            action: "ai", node: d, vars: ctx.vars, workspaceId, pageId, psid,
          });
          const reply = String(out?.reply || "");
          if (reply) {
            const r = await sendText(auth, psid, reply);
            await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body: reply, source: "ai", mid: r?.message_id || null });
          }
          const saveKey = String(d.save || "").trim();
          if (saveKey) ctx.vars[saveKey] = reply;
          break;
        }

        case "fb_gallery":
        case "fb_products":
        case "fb_lead":
        case "fb_reply_comment": {
          // Catalog carousel / lead+deal creation / public comment reply all
          // need DB access — hand back to Laravel, which already implements
          // each one and logs its own outbound message.
          const out = await askLaravel(appDomain, {
            action: type, node: d, vars: ctx.vars, workspaceId, pageId, psid,
            commentId: ctx.vars.comment_id || "",
          });
          if (out?.vars) Object.assign(ctx.vars, out.vars);
          break;
        }

        case "fb_send_dm": {   // legacy node, still runs on older flows
          const body = subst(d.text, ctx.vars);
          if (body.trim() !== "") {
            const r = await sendText(auth, psid, body);
            await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body, source: "flow", mid: r?.message_id || null });
          }
          break;
        }

        case "fb_quick": {     // legacy node
          const body = subst(d.text, ctx.vars);
          const r = await sendQuickReplies(auth, psid, body, (d.options || []));
          await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body, source: "flow", mid: r?.message_id || null });
          if (!state.parked) { park(ctx, node.id); state.parked = true; }
          return;
        }

        case "fb_ask": {       // legacy node
          const q = subst(d.question, ctx.vars).trim();
          if (q) {
            const r = await sendText(auth, psid, q);
            await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body: q, source: "flow", mid: r?.message_id || null });
          }
          if (!state.parked) { park(ctx, node.id); state.parked = true; }
          return;
        }

        case "fb_buttons": {
          const body = subst(d.text, ctx.vars);
          const btns = d.buttons || [];
          const img = subst(d.imageUrl ?? d.image_url, ctx.vars).trim();
          const r = await sendButtons(auth, psid, body, btns, img || undefined);
          await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body, source: "flow", mid: r?.message_id || null, buttons: btns.map((b) => ({ title: String(b.title || ""), url: String(b.url || "") })) });
          if (!state.parked) { park(ctx, node.id); state.parked = true; }
          return;
        }

        case "fb_to_whatsapp": {
          // Cross-channel handoff: move this Messenger conversation to WhatsApp.
          const mode = (d.mode === "direct") ? "direct" : "deeplink";
          if (mode === "deeplink") {
            // Send a wa.me click-to-chat link. The USER taps it and messages
            // your WhatsApp — fully Meta-compliant. A matching keyword-trigger
            // WhatsApp flow then auto-starts from the pre-filled text.
            const waNum = String(subst(d.waNumber, ctx.vars) || "").replace(/\D+/g, "");
            const prefill = subst(d.prefillText, ctx.vars) || "";
            if (waNum) {
              const link = `https://wa.me/${waNum}${prefill ? `?text=${encodeURIComponent(prefill)}` : ""}`;
              const intro = subst(d.introText, ctx.vars).trim();
              const body = intro ? `${intro}\n${link}` : link;
              const r = await sendText(auth, psid, body);
              await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body, source: "flow", mid: r?.message_id || null });
            } else {
              console.warn(`[FB-FLOW-NODE] fb_to_whatsapp deeplink node=${node.id} has no waNumber — skipping`);
            }
          } else {
            // Direct: hand the captured WhatsApp number to Laravel, which
            // resolves the workspace's WA device and STARTS the target flow.
            // (Meta requires the number to have opted in / be in a 24h window.)
            const intro = subst(d.introText, ctx.vars).trim();
            if (intro) {
              const r = await sendText(auth, psid, intro);
              await logToLaravel(appDomain, { pageId, psid, workspaceId, direction: "out", body: intro, source: "flow", mid: r?.message_id || null });
            }
            const out = await askLaravel(appDomain, {
              action: "fb_to_whatsapp", node: d, vars: ctx.vars, workspaceId, pageId, psid,
            });
            if (out?.vars) Object.assign(ctx.vars, out.vars);
          }
          break;
        }

        case "end":
          // End THIS branch only. The session is cleared at the top of walk()
          // once every branch settles and none parked — clearing it here would
          // wipe a sibling branch's parked input node.
          console.log(`[FB-FLOW-NODE] end flow=${flowId} psid=${psid}`);
          return;

        default:
          // A node this engine doesn't implement must be LOUD, never a silent
          // skip — silent skips are exactly how the old PHP runner hid bugs.
          console.warn(`[FB-FLOW-NODE] node type "${type}" has no executor — skipped (flow=${flowId} node=${node.id})`);
      }
    } catch (e) {
      console.error(`[FB-FLOW-NODE] node ${node.id} (${type}) failed: ${e?.message}`);
      // ALSO report to Laravel so the failure lands in laravel.log — the Node
      // console (Passenger on cPanel) is hard to read. This is how a failed
      // Facebook SEND ([FB-SEND✗] — e.g. a Messenger 24h-policy / token error)
      // becomes visible without SSH/pm2. Fire-and-forget; never break the walk.
      try {
        await logToLaravel(appDomain, {
          event: "flow_error", source: "flow",
          pageId, psid, workspaceId, flowId,
          node_id: node.id, node_type: type,
          error: String(e?.message || e).slice(0, 300),
        });
      } catch (_) { /* logging must never strand the customer */ }
      // Keep walking: one bad node shouldn't strand the customer mid-conversation.
    }

    // Fan out to every node wired to this node's active port (in edge order) —
    // one node connected to several nodes fires them all, not just the first.
    for (const t of nextTargets(flow, node.id, port)) {
      await walkNode(ctx, nodes, t, visited, state);
    }
}

// ---------------------------------------------------------------------------
// Session state — in memory, like Baileys' activeFlowSessions.
// ---------------------------------------------------------------------------
function park(ctx, nodeId) {
  const key = sessionKeyFor(ctx.pageId, ctx.psid);
  FB_SESSIONS.set(key, {
    pageId: ctx.pageId, psid: ctx.psid, workspaceId: ctx.workspaceId,
    flowId: ctx.flowId, flow: ctx.flow, auth: ctx.auth, appDomain: ctx.appDomain,
    nodeId: String(nodeId), vars: ctx.vars, parkedAt: Date.now(),
  });
  console.log(`[FB-FLOW-NODE] parked at node=${nodeId} key=${key}`);
}

function clearSession(pageId, psid) {
  FB_SESSIONS.delete(sessionKeyFor(pageId, psid));
}

export const hasSession = (pageId, psid) => FB_SESSIONS.has(sessionKeyFor(pageId, psid));

/** Drop sessions parked longer than `maxAgeMs` (default 24h — Meta's window). */
export function pruneSessions(maxAgeMs = 86_400_000) {
  const cutoff = Date.now() - maxAgeMs;
  let n = 0;
  for (const [k, s] of FB_SESSIONS) if (s.parkedAt < cutoff) { FB_SESSIONS.delete(k); n++; }
  return n;
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------
/** Fresh run from the Trigger node. Fire-and-forget — never await in a webhook. */
export async function runFlow({ auth, flow, psid, text, commentId, flowId, pageId, workspaceId, appDomain, vars }) {
  clearSession(pageId, psid);
  const start = entryNode(flow);
  if (!start) { console.warn(`[FB-FLOW-NODE] flow ${flowId} has no trigger node`); return false; }

  const ctx = {
    auth, flow, psid, flowId, pageId, workspaceId, appDomain,
    vars: { text: String(text || ""), psid: String(psid), page_id: String(pageId || ""), comment_id: String(commentId || ""), ...(vars || {}) },
  };
  console.log(`[FB-FLOW-NODE] START flow=${flowId} page=${pageId} psid=${psid}`);
  await walk(ctx, start.id);   // walk() fans out from the trigger's connections
  return true;
}

/**
 * Resume a parked flow from the customer's reply.
 * @returns true if a session was found and consumed.
 */
export async function resumeFlow({ pageId, psid, text }) {
  const key = sessionKeyFor(pageId, psid);
  const sess = FB_SESSIONS.get(key);
  if (!sess) { console.log(`[FB-RESUME] no session key=${key}`); return false; }

  const nodes = indexNodes(sess.flow);
  const parked = nodes.get(String(sess.nodeId));
  if (!parked) { console.warn(`[FB-RESUME] parked node "${sess.nodeId}" missing — dropping session`); FB_SESSIONS.delete(key); return false; }

  const d = parked.data || {};
  const type = String(parked.type || "");
  const t = String(text || "").toLowerCase().trim();
  let port = "out";

  console.log(`[FB-RESUME] key=${key} parkedNode=${sess.nodeId} type=${type} reply="${t.slice(0, 40)}"`);

  // Ask nodes: the reply IS the answer.
  if (type === "ask" || type === "fb_ask") {
    const saveKey = String(d.var || d.save || "").trim();
    if (saveKey) sess.vars[saveKey] = String(text || "");
  }

  // Expected-answer branching on the shared `ask` node (p0..pN + else).
  if (type === "ask") {
    const expected = (d.options || []).map((o) => String(o).trim()).filter(Boolean);
    if (expected.length) {
      port = "else";
      for (let i = 0; i < expected.length; i++) {
        if (t === expected[i].toLowerCase()) { port = `p${i}`; break; }
      }
    }
  }

  // Quick-reply / button taps arrive as the PAYLOAD, not the visible title —
  // match payload first, then fall back to a typed-out label.
  if (type === "buttons" || type === "fb_quick" || type === "fb_buttons") {
    const opts = type === "buttons"
      ? chatOptionsToQuickReplies(d)
      : (type === "fb_quick" ? (d.options || []) : (d.buttons || []))
        .map((o, i) => ({ title: String(o.title || ""), payload: String(o.payload || `OPT_${i}`) }));
    let idx = null;
    for (let i = 0; i < opts.length; i++) {
      if (t === String(opts[i].payload).toLowerCase() || t === String(opts[i].title).toLowerCase().trim()) { idx = i; break; }
    }
    console.log(`[FB-RESUME] button match reply="${t}" opts=${JSON.stringify(opts)} → idx=${idx}`);
    if (idx === null) return false;   // not a tap — let normal handling take it
    port = `p${idx}`;
    const saveKey = String(d.var || "").trim();
    if (saveKey) sess.vars[saveKey] = String(text || "");
  }

  FB_SESSIONS.delete(key);   // consumed
  sess.vars.text = String(text || "");
  console.log(`[FB-FLOW-NODE] RESUME flow=${sess.flowId} from=${sess.nodeId} port=${port}`);
  await walk({ ...sess, vars: sess.vars }, sess.nodeId, { fromPort: port });   // fan out from the parked node's chosen port
  return true;
}

export default { runFlow, resumeFlow, hasSession, pruneSessions, delayMsOf };
