/**
 * Local Shop Manager — same thread, sub-agents underneath.
 * Never claims to be AI. Short WhatsApp voice.
 */
const PRODUCTS = [
  { sku: "A24-RED", name: "A24 phone cover", color: "red", price: 499, stock: true },
  { sku: "A24-BLK", name: "A24 phone cover", color: "black", price: 499, stock: true },
  { sku: "TEE-M", name: "Cotton tee", color: "white", price: 1290, stock: true },
  { sku: "TEE-L", name: "Cotton tee", color: "navy", price: 1290, stock: false },
];

const MENU = `Hi — this is Noon Street Shop. Tap a number or just tell me.

1️⃣ Browse products
2️⃣ Track my order
3️⃣ Confirm COD
4️⃣ Return or exchange
5️⃣ Size & shipping
6️⃣ Stock check
7️⃣ Finish checkout
8️⃣ Payment help
9️⃣ Find our store
🔟 Talk to a person`;

function nowTime() {
  return new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}

function createShopState() {
  return {
    greetSent: false,
    sub: "manager",
    orderId: "",
    sku: "",
    name: "",
    photoHint: "",
    lastIntent: "",
    turns: 0,
    handedOff: false,
  };
}

function looksOrderId(t) {
  return /\b(WD-?\d{3,}|#?\d{4,8})\b/i.test(t);
}

function extractOrderId(t) {
  const m = t.match(/\b(WD-?\d{3,}|\d{4,8})\b/i);
  if (!m) return "";
  const raw = m[1].toUpperCase();
  return raw.startsWith("WD") ? raw.replace("WD", "WD-").replace("WD--", "WD-") : "WD-" + raw;
}

function findProduct(t) {
  const s = t.toLowerCase();
  return PRODUCTS.find((p) =>
    s.includes(p.sku.toLowerCase()) ||
    (s.includes("a24") && s.includes(p.color)) ||
    (s.includes("cover") && s.includes(p.color)) ||
    (s.includes("tee") && (s.includes(p.color) || s.includes("shirt")))
  ) || PRODUCTS.find((p) => s.includes("cover") || s.includes("a24") || s.includes("case"))
    || PRODUCTS.find((p) => s.includes("tee") || s.includes("shirt"));
}

function classify(text, st) {
  const t = text.toLowerCase().trim();
  if (st.handedOff) return "human";
  if (/\b(human|agent|person|manager|complaint|lawyer|stupid bot)\b/.test(t)) return "human";
  if (/^[1-9]$|^10$|^🔟$/.test(t) || /^[1-9][\).]$/.test(t)) return "menu_pick";
  if (/\b(menu|options|help)\b/.test(t) && t.length < 24) return "menu";
  if (/\b(hi|hello|hey|salam|asalam|aoa)\b/.test(t) && t.length < 40) return "hi";
  if (/^(\?+|ok+|h+m+|lol+|haha+|u+|😂+|👍+)$/i.test(t)) return "filler";
  if (/\b(track|where.*order|wismo|status|shipped)\b/.test(t) || (looksOrderId(t) && st.sub === "orders")) return "track";
  if (/\b(cod|cash on delivery)\b/.test(t)) return "cod";
  if (/\b(return|refund|exchange|wrong size)\b/.test(t)) return "returns";
  if (/\b(size|shipping|delivery time|chest)\b/.test(t)) return "size";
  if (/\b(stock|available|in stock)\b/.test(t)) return "stock";
  if (/\b(pay|payment|jazz|easypaisa|card)\b/.test(t)) return "pay";
  if (/\b(store|address|map|location|find us)\b/.test(t)) return "store";
  if (/\b(checkout|cart|buy now)\b/.test(t)) return "checkout";
  if (/\b(price|how much|cover|a24|tee|shirt|product|catalog|red|black|navy)\b/.test(t)) return "catalog";
  if (looksOrderId(t)) return st.sub === "returns" ? "returns" : "track";
  // Stay on a specialist only when the follow-up still belongs to that job.
  if (st.sub === "orders" && (looksOrderId(t) || /\b(address|cancel|courier|packed)\b/.test(t))) return "track";
  if (st.sub === "returns" && /\b(wrong|damaged|size|exchange|refund|pickup|reason|changed mind)\b/.test(t)) return "returns";
  if (st.sub === "cod" && /\b(confirm|address|cancel|cash)\b/.test(t)) return "cod";
  return "chitchat";
}

function menuPick(text) {
  const d = text.replace(/[^\d]/g, "");
  const n = parseInt(d, 10);
  const map = {
    1: "catalog", 2: "track", 3: "cod", 4: "returns", 5: "size",
    6: "stock", 7: "checkout", 8: "pay", 9: "store", 10: "human",
  };
  return map[n] || "menu";
}

function replyFor(intent, text, st, extras) {
  st.turns += 1;
  st.lastIntent = intent;
  const product = findProduct(text);
  if (product) st.sku = product.sku;
  if (looksOrderId(text)) st.orderId = extractOrderId(text);

  const oid = st.orderId || "your order";

  switch (intent) {
    case "hi":
      st.greetSent = true;
      st.sub = "manager";
      return MENU;
    case "menu":
      return MENU;
    case "menu_pick":
      return replyFor(menuPick(text), text, st, extras);
    case "filler":
      if (!st.greetSent) return MENU;
      return "All good. Product, order number, or just say menu.";
    case "catalog": {
      st.sub = "catalog";
      if (product) {
        const stock = product.stock ? "in stock" : "not in stock right now";
        return `${product.name} (${product.color}) — Rs ${product.price}, ${stock}. SKU ${product.sku}. Want me to put you on checkout or check another colour?`;
      }
      if (extras.photo) {
        return "Got the picture. If it’s an A24 cover we have red and black at Rs 499. If it’s a tee, white M is in, navy L is out. Which one?";
      }
      return "We have A24 covers (red/black, Rs 499) and cotton tees (Rs 1290). Tell me colour or send a photo.";
    }
    case "track":
      st.sub = "orders";
      if (!st.orderId) return "What’s the order number? Something like WD-1042 is perfect.";
      return `Checking ${oid} — last we had it packed, courier usually 1–3 days in city. Want the tracking ping, a new address, or cancel?`;
    case "cod":
      st.sub = "cod";
      if (!st.orderId) return "COD — send the order number and I’ll confirm, change address, or cancel.";
      return `COD on ${oid}: Confirm, change address, or cancel? Keep the exact cash ready if we confirm.`;
    case "returns":
      st.sub = "returns";
      if (!st.orderId) return "Return or exchange — what’s the order number, and is it wrong size, damaged, or changed mind?";
      return `Noted for ${oid}. ${text.length > 8 ? "I’ve got the reason." : "Which item and why?"} A teammate will send pickup/address in this chat — I don’t push refunds through myself.`;
    case "size":
      st.sub = "catalog";
      return "Sizes: S 86–91 chest · M 96–101 · L 106–111. City 1–3 days, nationwide 3–7. Want to browse after that?";
    case "stock":
      st.sub = "catalog";
      if (product) {
        return product.stock
          ? `${product.name} ${product.color} is in. I can send you to checkout.`
          : `${product.name} ${product.color} is out. Red A24 cover and white tee are in.`;
      }
      return "Name or SKU and I’ll check. Photo works too.";
    case "checkout":
      st.sub = "catalog";
      return "Checkout link would go here on a real number. For this demo, say the SKU (A24-RED) and I’ll treat it as added.";
    case "pay":
      st.sub = "orders";
      if (!st.orderId) return "Payment — send the order number and I’ll match it. JazzCash / card link is what the team sends.";
      return `Payment for ${oid} — team confirms or sends a pay link. Anything stuck on the bank side they handle, not me.`;
    case "store":
      return "We’re on Noon Street (pin would drop on a live number). Mon–Sat 10–8, Sunday closed.";
    case "human":
      st.handedOff = true;
      st.sub = "human";
      return "Pulling a teammate in. Stay in this chat — they’ll see everything we just said.";
    case "chitchat":
    default: {
      if (extras.photo) {
        st.sub = "catalog";
        return "I looked at the photo. If that’s a phone case, A24 red/black are Rs 499. If not, tell me the product name — I’ll be straight with you.";
      }
      const silly = [
        "Ha, fair. We sell covers and tees though — want one of those or is this about an order?",
        "Noted. I can help with a product, tracking, COD, or a return. What do you actually need?",
        "I’m here. If that was a real question, try me again in one line.",
      ];
      if (/\b(love|marry|joke|weather|cricket|bitcoin|free money)\b/i.test(text)) {
        return "Haha I’m just the shop. Covers, tees, orders, returns — pick one.";
      }
      if (!st.greetSent) {
        st.greetSent = true;
        return MENU;
      }
      return silly[st.turns % silly.length];
    }
  }
}

function localReply(text, st, extras = {}) {
  const intent = classify(text, st);
  return { text: replyFor(intent, text, st, extras), intent, sub: st.sub };
}

const SYSTEM_PROMPT = `You are the owner of "Noon Street Shop" answering on WhatsApp. Never say you are AI, a bot, a language model, or an assistant. Never use markdown headings or numbered corporate lists unless the customer asked for the menu.

Voice: short (1–3 lines), slightly imperfect, warm, like a busy seller. Match the customer's language (English, Urdu, Roman Urdu mix). Use their name only if they gave it.

You sell: A24 phone covers red/black Rs 499 (in stock); cotton tees Rs 1290 (white M in, navy L out). City delivery 1–3 days, nationwide 3–7. COD available. Returns unused 14 days — you capture details then a human completes refunds.

If they send a voice note, keep the reply short enough to speak (~12 seconds). If they type, type.

If they want the full menu, you may send:
1️⃣ Browse products 2️⃣ Track 3️⃣ COD 4️⃣ Return 5️⃣ Size & shipping 6️⃣ Stock 7️⃣ Checkout 8️⃣ Payment 9️⃣ Store 🔟 Person

For off-topic or silly messages: one human beat, then a useful question. Do not lecture.

If you don't know stock/price/refund, say you'll check with the team. If they ask for a human, complaint, or lawyer: say a teammate is joining.

Do not invent tracking numbers. If they give WD-#### treat it as their order. Reply with message text only.`;
