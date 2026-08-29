const thread = document.getElementById("thread");
const input = document.getElementById("input");
const sendBtn = document.getElementById("sendBtn");
const micBtn = document.getElementById("micBtn");
const photoBtn = document.getElementById("photoBtn");
const photoInput = document.getElementById("photoInput");
const liveMode = document.getElementById("liveMode");
const keyWrap = document.getElementById("keyWrap");
const apiKey = document.getElementById("apiKey");
const resetBtn = document.getElementById("resetBtn");
const statusLine = document.getElementById("statusLine");
const voiceHint = document.getElementById("voiceHint");

let state = createShopState();
let history = [];
let lastInboundWasVoice = false;
let rec = null;

apiKey.value = localStorage.getItem("shopDemoKey") || "";
liveMode.checked = localStorage.getItem("shopDemoLive") === "1";
keyWrap.classList.toggle("hidden", !liveMode.checked);

liveMode.addEventListener("change", () => {
  keyWrap.classList.toggle("hidden", !liveMode.checked);
  localStorage.setItem("shopDemoLive", liveMode.checked ? "1" : "0");
});
apiKey.addEventListener("change", () => localStorage.setItem("shopDemoKey", apiKey.value.trim()));

function addBubble(role, text, opts = {}) {
  const el = document.createElement("div");
  // Customer (in) = right green; shop (out) = left white.
  el.className = "bubble " + (role === "in" ? "out" : "in");
  if (opts.voice) el.classList.add("voice-tag");
  if (opts.imageUrl) {
    const img = document.createElement("img");
    img.className = "preview";
    img.src = opts.imageUrl;
    el.appendChild(img);
  }
  const body = document.createElement("span");
  body.textContent = text;
  el.appendChild(body);
  const time = document.createElement("span");
  time.className = "time";
  time.textContent = nowTime();
  el.appendChild(time);
  thread.appendChild(el);
  thread.scrollTop = thread.scrollHeight;
}

function speak(text) {
  if (!window.speechSynthesis) return;
  window.speechSynthesis.cancel();
  const u = new SpeechSynthesisUtterance(text.replace(/[1️⃣2️⃣3️⃣4️⃣5️⃣6️⃣7️⃣8️⃣9️⃣🔟*]/g, ""));
  u.rate = 0.95;
  u.pitch = 1;
  speechSynthesis.speak(u);
}

async function liveReply(userText, imageDataUrl) {
  const key = apiKey.value.trim();
  if (!key) throw new Error("Paste an OpenAI key to use Live AI.");
  const messages = [{ role: "system", content: SYSTEM_PROMPT }];
  for (const h of history.slice(-16)) {
    messages.push({ role: h.role === "in" ? "user" : "assistant", content: h.text });
  }
  const userContent = imageDataUrl
    ? [
        { type: "text", text: userText || "I sent a photo." },
        { type: "image_url", image_url: { url: imageDataUrl } },
      ]
    : userText;
  messages.push({ role: "user", content: userContent });

  const res = await fetch("https://api.openai.com/v1/chat/completions", {
    method: "POST",
    headers: {
      Authorization: "Bearer " + key,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      model: imageDataUrl ? "gpt-4o-mini" : "gpt-4o-mini",
      temperature: 0.7,
      max_tokens: 220,
      messages,
    }),
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.error?.message || "OpenAI error");
  return (data.choices?.[0]?.message?.content || "").trim();
}

async function handleUser(text, extras = {}) {
  const cleaned = (text || "").trim();
  if (!cleaned && !extras.imageUrl) return;

  const shown = cleaned || (extras.imageUrl ? "(photo)" : "");
  addBubble("in", shown, { voice: extras.voice, imageUrl: extras.imageUrl });
  history.push({ role: "in", text: shown });
  lastInboundWasVoice = !!extras.voice;
  statusLine.textContent = "typing…";

  let reply;
  try {
    if (liveMode.checked) {
      reply = await liveReply(cleaned || "Customer sent a photo of a product.", extras.imageUrl);
    } else {
      const r = localReply(cleaned || "photo", state, { photo: !!extras.imageUrl });
      reply = r.text;
      statusLine.textContent = "online · " + (r.sub || "Shop Manager");
    }
  } catch (e) {
    reply = "Live AI didn’t run (" + e.message + "). Turn Live off to use the built-in shop brain.";
  }

  await new Promise((r) => setTimeout(r, 280 + Math.min(420, reply.length * 4)));
  addBubble("out", reply, { voice: lastInboundWasVoice });
  history.push({ role: "out", text: reply });
  if (!liveMode.checked) {
    statusLine.textContent = "online · " + (state.sub === "manager" ? "Shop Manager" : state.sub);
  } else {
    statusLine.textContent = "online · Shop Manager (live)";
  }
  if (lastInboundWasVoice) speak(reply);
}

sendBtn.addEventListener("click", () => {
  const t = input.value;
  input.value = "";
  handleUser(t);
});
input.addEventListener("keydown", (e) => {
  if (e.key === "Enter" && !e.shiftKey) {
    e.preventDefault();
    sendBtn.click();
  }
});

photoBtn.addEventListener("click", () => photoInput.click());
photoInput.addEventListener("change", () => {
  const f = photoInput.files?.[0];
  photoInput.value = "";
  if (!f) return;
  const url = URL.createObjectURL(f);
  handleUser("what is this", { imageUrl: url });
});

function startSpeech() {
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SR) {
    addBubble("out", "This browser has no speech recognition. Type instead, or try Chrome.");
    return;
  }
  rec = new SR();
  rec.lang = "en-US";
  rec.interimResults = false;
  rec.onresult = (ev) => {
    const said = ev.results[0][0].transcript;
    handleUser(said, { voice: true });
  };
  rec.onerror = () => {
    voiceHint.classList.add("hidden");
    micBtn.classList.remove("rec");
  };
  rec.onend = () => {
    voiceHint.classList.add("hidden");
    micBtn.classList.remove("rec");
  };
  rec.start();
  micBtn.classList.add("rec");
  voiceHint.classList.remove("hidden");
}

micBtn.addEventListener("click", () => {
  if (micBtn.classList.contains("rec") && rec) {
    rec.stop();
    return;
  }
  startSpeech();
});

resetBtn.addEventListener("click", () => {
  state = createShopState();
  history = [];
  thread.innerHTML = "";
  statusLine.textContent = "online · Shop Manager";
  boot();
});

function boot() {
  addBubble("out", "Message us like a customer. Try hi, a silly question, WD-1042, “red A24 cover”, or the mic.");
}
boot();
