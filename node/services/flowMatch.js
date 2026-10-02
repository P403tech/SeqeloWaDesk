// Pure flow-matching helpers (no I/O) — kept standalone so they can be unit
// tested without booting the whole flow runtime. Used by flowService.js.

// Split a comma-separated keyword string into a clean, lowercased list.
export function splitKeywords(str) {
  return String(str || "")
    .split(",")
    .map((s) => s.trim().toLowerCase())
    .filter(Boolean);
}

// Node-level keyword jump: return the FIRST node whose jump-trigger keywords
// match the customer's text, else null. `match` = 'exact' (whole message equals
// a keyword) or 'contains' (default — the keyword appears in the message).
export function matchKeywordJump(flowData, text) {
  const msg = String(text || "").trim().toLowerCase();
  if (!msg) return null;
  const nodes = (flowData && flowData.flowNodes) || [];
  for (const n of nodes) {
    const jt = n && n.kwJump;
    if (!jt || !jt.enabled) continue;
    const kws = splitKeywords(jt.keywords);
    if (!kws.length) continue;
    const exact = String(jt.match || "contains") === "exact";
    if (kws.some((kw) => (exact ? msg === kw : msg.includes(kw)))) return n;
  }
  return null;
}
