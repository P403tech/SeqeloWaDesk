// controllers/telegramFlowController.js
// =====================================
// Telegram (Bot API) inbound → Node flow engine. Same shape as
// facebookFlowController / tiktokFlowController: Laravel verifies the webhook,
// hands the message here, we decide SYNCHRONOUSLY whether a flow consumes it,
// answer immediately, and run the flow detached (Delay = real await).
//
// PURELY ADDITIVE — imported by nothing else. The recipient key is the Telegram
// chat id.
import { runFlow, resumeFlow, hasSession, pruneSessions } from "../services/telegramFlowService.js";

/**
 * POST /api/telegram-flow/inbound
 *   botId, workspaceId, chatId, text, auth({base, token}), flow?, flowId?, vars?
 * Auth: X-Node-Token. Response: { ok, consumed, mode }
 */
export const telegramInbound = async (req, res) => {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  if (!expected || (req.headers["x-node-token"] || "") !== expected) {
    return res.status(401).send({ ok: false, error: "unauthorized" });
  }

  const botId       = Number(req.body?.botId || 0);
  const workspaceId = Number(req.body?.workspaceId || 0);
  const chatId      = String(req.body?.chatId || "");
  const text        = String(req.body?.text || "");
  const auth        = req.body?.auth || null;
  const flow        = req.body?.flow || null;
  const flowId      = req.body?.flowId ? String(req.body.flowId) : "";
  const vars        = req.body?.vars || {};
  const appDomain   = String(req.body?.appDomain || process.env.APP_URL || "").replace(/\/+$/, "");

  if (!botId || !chatId) {
    return res.status(400).send({ ok: false, error: "botId and chatId required" });
  }

  const hasContent = text.trim() !== "";
  const isResume   = hasSession(botId, chatId) && hasContent;
  const canStart   = !!(flow && (flow.flowNodes || flow.nodes));

  console.log(`[TG-FLOW-NODE] IN bot=${botId} chat=${chatId} text="${text.slice(0, 50)}" isResume=${isResume} canStart=${canStart}`);

  if (!isResume && !canStart) {
    return res.status(200).send({ ok: true, consumed: false, mode: "none" });
  }

  // Answer BEFORE running — a Wait node must never hold the request open.
  res.status(202).send({ ok: true, consumed: true, mode: isResume ? "resume" : "start" });

  try { pruneSessions(); } catch (_) {}

  (async () => {
    try {
      if (isResume) {
        const done = await resumeFlow({ botId, chatId, text, vars });
        if (!done) console.log(`[TG-FLOW-NODE] resume declined (no matching branch) bot=${botId} chat=${chatId}`);
        return;
      }
      await runFlow({ auth, flow, chatId, text, flowId, botId, workspaceId, appDomain, vars });
    } catch (e) {
      console.error(`[TG-FLOW-NODE] handler crashed bot=${botId} chat=${chatId}: ${e?.message}`);
    }
  })();
};

/** GET /api/telegram-flow/health */
export const telegramFlowHealth = (req, res) => {
  const expected = process.env.NODE_WEBHOOK_TOKEN || "";
  if (!expected || (req.headers["x-node-token"] || "") !== expected) {
    return res.status(401).send({ ok: false });
  }
  return res.status(200).send({ ok: true, service: "telegram-flow" });
};

export default { telegramInbound, telegramFlowHealth };
