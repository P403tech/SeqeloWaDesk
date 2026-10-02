// controllers/webchatFlowController.js
// ===================================
// Embedded chat-widget inbound → Node flow engine. Ported from
// emailFlowController: Laravel takes the visitor's message, hands it here, we
// decide SYNCHRONOUSLY whether a flow consumes it, answer immediately, and run
// the flow detached (Delay = a real await). Sends are delegated back to PHP,
// which writes the outbound row the visitor's widget polls for.
//
// Answering `consumed: true` makes PHP skip its keyword rules AND the AI
// assistant, so a message a parked node cannot take MUST come back
// `consumed: false` or the visitor gets no answer at all.
//
// Key = the chatbot_widgets row id + the WaDesk conversation id.
import { runFlow, resumeFlow, canResume, hasSession, pruneSessions } from "../services/webchatFlowService.js";

/**
 * POST /api/webchat-flow/inbound
 *   auth{base,token}?, widgetId, conversationId, workspaceId, text, flow?, flowId?, vars?
 * Auth: X-Node-Token. Response: { ok, consumed, mode }
 */
export const webchatInbound = async (req, res) => {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  if (!expected || (req.headers["x-node-token"] || "") !== expected) {
    return res.status(401).send({ ok: false, error: "unauthorized" });
  }

  const auth           = req.body?.auth || {};
  const widgetId      = Number(req.body?.widgetId || req.body?.widgetRowId || 0);
  const conversationId = String(req.body?.conversationId ?? req.body?.wdConvId ?? "");
  const workspaceId    = Number(req.body?.workspaceId || 0);
  const text           = String(req.body?.text || "");
  const flow           = req.body?.flow || null;
  const flowId         = req.body?.flowId ? String(req.body.flowId) : "";
  const vars           = req.body?.vars || {};
  const appDomain      = String(auth.base || req.body?.appDomain || process.env.APP_URL || "").replace(/\/+$/, "");
  const authToken      = String(auth.token || "");

  if (!widgetId || !conversationId) {
    return res.status(400).send({ ok: false, error: "widgetId and conversationId required" });
  }

  const hasContent = text.trim() !== "";
  const canStart   = !!(flow && (flow.flowNodes || flow.nodes));

  const restart  = canStart && !hasSession(widgetId, conversationId);
  // canResume() must decide BEFORE the 202 below: answering `consumed: true`
  // makes PHP skip routing / AI / keyword replies, so a reply the parked node
  // cannot take has to come back `consumed: false` or the customer gets
  // nothing at all.
  const isResume = !restart && hasSession(widgetId, conversationId) && hasContent
                   && canResume(widgetId, conversationId, text);

  console.log(`[WC-FLOW-NODE] IN widget=${widgetId} conversation=${conversationId} text="${text.slice(0, 50)}" restart=${restart} isResume=${isResume} canStart=${canStart}`);

  if (!restart && !isResume) {
    return res.status(200).send({ ok: true, consumed: false, mode: "none" });
  }

  res.status(202).send({ ok: true, consumed: true, mode: restart ? "start" : "resume" });

  try { pruneSessions(); } catch (_) {}

  (async () => {
    try {
      if (restart) {
        await runFlow({ flow, conversationId, text, flowId, widgetId, workspaceId, appDomain, authToken, vars });
        return;
      }
      const done = await resumeFlow({ widgetId, conversationId, text, vars });
      if (!done) console.log(`[WC-FLOW-NODE] resume declined (no matching branch) widget=${widgetId} conversation=${conversationId}`);
    } catch (e) {
      console.error(`[WC-FLOW-NODE] handler crashed widget=${widgetId} conversation=${conversationId}: ${e?.message}`);
    }
  })();
};

/** GET /api/webchat-flow/health */
export const webchatFlowHealth = (req, res) => {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  if (!expected || (req.headers["x-node-token"] || "") !== expected) {
    return res.status(401).send({ ok: false });
  }
  return res.status(200).send({ ok: true, service: "webchat-flow" });
};

export default { webchatInbound, webchatFlowHealth };
