// Client Support Bot — floating help widget. Mounted globally by app.js when
// #support-bot-widget is present (admin-enabled). One ask box; the server-side
// ladder (docs -> AI -> web -> contact) decides the answer. Rendering ports the
// chatdoc reference (structured sections: bold headings, bullet lines, "From:"
// labels, sources, contact card). Themed with WaDesk's own CSS variables so it
// matches the app palette exactly.
export default function init() {
  const root = document.getElementById('support-bot-widget');
  if (!root || root.dataset.mounted) return;
  root.dataset.mounted = '1';

  let cfg = {};
  try { cfg = JSON.parse(root.dataset.config || '{}'); } catch (_) { cfg = {}; }
  const askUrl = root.dataset.askUrl;
  const historyUrl = root.dataset.historyUrl;
  const sessionsUrl = root.dataset.sessionsUrl;
  const rateUrl = root.dataset.rateUrl;
  const clearUrl = root.dataset.clearUrl;
  const articlesUrl = root.dataset.articlesUrl;
  const csrf = root.dataset.csrf || '';
  const accent = cfg.theme_color || '#128C7E';
  const side = cfg.position === 'left' ? 'left' : 'right';
  const t = (s) => (window.wadeskI18n ? window.wadeskI18n(s) : s);
  let sessionId = getSession(); // the ACTIVE chat; New chat rotates it

  injectStyles(accent, side);

  const launcher = el('button', 'sbw-launcher', { type: 'button', 'aria-label': t('Help & Support') });
  launcher.innerHTML = iconChat();
  root.appendChild(launcher);

  const panel = el('div', 'sbw-panel', { role: 'dialog', 'aria-label': cfg.title || 'Help & Support' });
  panel.innerHTML = `
    <div class="sbw-head">
      <div class="sbw-title">${esc(cfg.title || 'Help & Support')}</div>
      <div class="sbw-head-actions">
        <button type="button" class="sbw-history" aria-label="${esc(t('History'))}" title="${esc(t('History'))}">${iconHistory()}</button>
        <button type="button" class="sbw-new" aria-label="${esc(t('New chat'))}" title="${esc(t('New chat'))}">${iconNew()}</button>
        <button type="button" class="sbw-close" aria-label="${esc(t('Close'))}">${iconClose()}</button>
      </div>
    </div>
    <div class="sbw-body" role="log" aria-live="polite"></div>
    <form class="sbw-form">
      <input class="sbw-input" type="text" autocomplete="off" placeholder="${esc(t('Ask a question…'))}" maxlength="2000" aria-label="${esc(t('Your question'))}" />
      <button type="submit" class="sbw-send" aria-label="${esc(t('Send'))}">${iconSend()}</button>
    </form>
    <div class="sbw-foot"><a href="#" class="sbw-articles">${esc(t('Browse help articles'))}</a></div>`;
  root.appendChild(panel);

  const body = panel.querySelector('.sbw-body');
  const form = panel.querySelector('.sbw-form');
  const input = panel.querySelector('.sbw-input');
  let greeted = false;
  let busy = false;
  let restored = false; // previous conversation loaded from the server yet?
  const history = []; // [{role:'user'|'bot', text}] — sent so the bot converses in context

  launcher.addEventListener('click', open);
  panel.querySelector('.sbw-close').addEventListener('click', close);
  panel.querySelector('.sbw-new').addEventListener('click', newChat);
  panel.querySelector('.sbw-history').addEventListener('click', showHistory);
  panel.querySelector('.sbw-articles').addEventListener('click', (e) => { e.preventDefault(); loadArticles(); });
  form.addEventListener('submit', (e) => { e.preventDefault(); send(); });

  // Start a fresh conversation: rotate to a NEW chat id so the current one is
  // kept intact as a past chat in History, then clear the panel and greet.
  function newChat() {
    if (busy) return;
    sessionId = newSession();
    body.innerHTML = '';
    history.length = 0;
    restored = true;
    greeted = true;
    addBot(cfg.greeting || t('Hi! How can I help?'));
    input.focus();
  }

  function open() {
    panel.classList.add('sbw-open');
    launcher.classList.add('sbw-hidden');
    // Reload the ACTIVE chat once so it survives logout / refresh — greet only
    // if there was nothing to restore.
    if (!restored) { restored = true; loadSession(sessionId, false); }
    else if (!greeted) { addBot(cfg.greeting || t('Hi! How can I help?')); greeted = true; }
    setTimeout(() => input.focus(), 50);
  }

  // History button — show a LIST of the user's previous chats to pick from.
  async function showHistory() {
    if (busy) return;
    if (!sessionsUrl) return;
    body.innerHTML = '';
    typing(true);
    try {
      const res = await fetch(sessionsUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const json = await res.json().catch(() => ({}));
      typing(false);
      renderSessionList((json && json.sessions) || []);
    } catch (_) {
      typing(false);
      addBot(t('Could not load your chat history.'));
    }
  }

  function renderSessionList(sessions) {
    const wrap = addBubble('bot', 'sbw-rich');
    wrap.appendChild(el('div', 'sbw-h', {}, t('Your previous chats')));
    if (!sessions.length) { wrap.appendChild(el('div', 'sbw-p', {}, t('No previous chats yet.'))); scroll(); return; }
    const list = el('div', 'sbw-sess-list');
    sessions.forEach((s) => {
      const item = el('button', 'sbw-sess', { type: 'button' });
      item.appendChild(el('span', 'sbw-sess-title', {}, s.title || t('Conversation')));
      const meta = [s.when, s.count ? (s.count + ' ' + t('messages')) : ''].filter(Boolean).join(' · ');
      if (meta) item.appendChild(el('span', 'sbw-sess-meta', {}, meta));
      item.addEventListener('click', () => openSession(s.session_id));
      list.appendChild(item);
    });
    wrap.appendChild(list);
    scroll();
  }

  // Open a chosen past chat: make it the active thread so the user can keep
  // talking on it, then replay its turns.
  function openSession(sid) {
    if (!sid || busy) return;
    sessionId = sid;
    try { localStorage.setItem('sbw_session', sid); } catch (_) {}
    body.innerHTML = '';
    history.length = 0;
    loadSession(sid, true);
  }

  // Fetch + replay one chat's turns. `force` re-greets even if greeted before.
  async function loadSession(sid, force) {
    const greet = () => { addBot(cfg.greeting || t('Hi! How can I help?')); greeted = true; };
    if (!historyUrl) { if (!greeted || force) greet(); return; }
    typing(true);
    try {
      const url = sid ? historyUrl + (historyUrl.includes('?') ? '&' : '?') + 'session=' + encodeURIComponent(sid) : historyUrl;
      const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const json = await res.json().catch(() => ({}));
      typing(false);
      const turns = (json && json.turns) || [];
      if (!turns.length) { if (!greeted || force) greet(); return; }
      turns.forEach((turn) => {
        if (turn.role === 'user') { addUser(turn.text); history.push({ role: 'user', text: turn.text }); return; }
        addAnswer(turn.text, null);
        if (turn.engine) addTier(turn.engine);
        if (turn.log_id) addRating(turn.log_id, turn.rating || 0);
        history.push({ role: 'bot', text: String(turn.text).slice(0, 2000) });
      });
      greeted = true;
      scroll();
    } catch (_) {
      typing(false);
      if (!greeted || force) greet();
    }
  }
  function newSession() {
    const s = 's-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    try { localStorage.setItem('sbw_session', s); } catch (_) {}
    return s;
  }
  function close() { panel.classList.remove('sbw-open'); launcher.classList.remove('sbw-hidden'); }

  async function send() {
    const q = input.value.trim();
    if (!q || busy) return;
    input.value = '';
    const prior = history.slice(-8); // turns BEFORE this question
    addUser(q);
    history.push({ role: 'user', text: q });
    busy = true; typing(true);
    try {
      const res = await fetch(askUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        credentials: 'same-origin',
        body: JSON.stringify({ question: q, session_id: sessionId, history: prior }),
      });
      const json = await res.json().catch(() => ({}));
      typing(false);
      if (!res.ok || json.ok === false) { addBot(t('Sorry, something went wrong. Please try again.')); return; }
      if (json.matched && json.answer) {
        addAnswer(json.answer, json.sections);
        addSources(json.sources);
        if (json.engine) addTier(json.engine);
      } else if (json.contact && (json.contact.email || json.contact.url)) {
        addContact(json.answer, json.contact);
      } else {
        addBot(json.answer || t("I couldn't find an answer to that."));
      }
      if (json.log_id) addRating(json.log_id, 0); // let the user rate this answer
      if (json.answer) history.push({ role: 'bot', text: String(json.answer).slice(0, 2000) });
    } catch (_) {
      typing(false);
      addBot(t('Network error. Please try again.'));
    } finally {
      busy = false; input.focus();
    }
  }

  async function loadArticles() {
    typing(true);
    try {
      const res = await fetch(articlesUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const json = await res.json().catch(() => ({}));
      typing(false);
      const list = (json && json.articles) || [];
      if (!list.length) { addBot(t('No help articles are available yet.')); return; }
      const bubble = addBubble('bot', 'sbw-rich');
      bubble.appendChild(el('div', 'sbw-h', {}, t('Help articles')));
      const ul = el('ul', 'sbw-list');
      list.forEach((a) => {
        const li = document.createElement('li');
        const b = el('button', 'sbw-article', { type: 'button' }, a.label);
        b.addEventListener('click', () => { input.value = a.label; send(); });
        li.appendChild(b);
        ul.appendChild(li);
      });
      bubble.appendChild(ul);
      scroll();
    } catch (_) { typing(false); addBot(t('Could not load help articles.')); }
  }

  // ---- structured rendering (chatdoc port) ----
  function addAnswer(text, sections) {
    // AI / web answers arrive as a MARKDOWN STRING (no sections) — render it so
    // **bold**, numbered steps and bullets format instead of showing raw "**".
    if (!sections || !sections.length) {
      const b = addBubble('bot', 'sbw-rich');
      renderMarkdown(b, text);
      scroll();
      return;
    }
    const bubble = addBubble('bot', 'sbw-rich');
    sections.forEach((section) => {
      if (section.related && section.source) bubble.appendChild(el('div', 'sbw-from', {}, t('From') + ': ' + section.source));
      if (section.heading) { const h = el('div', 'sbw-h'); inline(h, section.heading); bubble.appendChild(h); }
      let bullets = null;
      (section.lines || []).forEach((line) => {
        if (isBullet(line)) {
          if (!bullets) { bullets = el('ul', 'sbw-list'); bubble.appendChild(bullets); }
          const li = document.createElement('li'); inline(li, line); bullets.appendChild(li);
          return;
        }
        bullets = null;
        const p = el('div', 'sbw-p'); inline(p, line); bubble.appendChild(p);
      });
    });
    scroll();
  }
  function isBullet(line) { return !/[.!?:]$/.test(String(line).trim()); }
  function inline(target, text) {
    // **bold** first, then `code`; everything else is plain text.
    String(text).split(/\*\*(.+?)\*\*/g).forEach((part, i) => {
      if (part === '') return;
      if (i % 2 === 1) { target.appendChild(el('strong', null, {}, part)); return; }
      part.split(/`([^`]+)`/g).forEach((seg, j) => {
        if (seg === '') return;
        if (j % 2 === 1) target.appendChild(el('code', 'sbw-code', {}, seg));
        else target.appendChild(document.createTextNode(seg));
      });
    });
  }
  // Render a markdown STRING (AI/web answers): headings, numbered steps,
  // bullets, bold, paragraphs. Keeps line breaks meaningful.
  function renderMarkdown(bubble, md) {
    let list = null, listType = null;
    String(md).replace(/\r/g, '').split('\n').forEach((raw) => {
      const line = raw.trim();
      if (line === '') { list = null; listType = null; return; }
      let m;
      if ((m = line.match(/^#{1,6}\s+(.+)$/))) { list = null; listType = null; const h = el('div', 'sbw-h'); inline(h, m[1]); bubble.appendChild(h); return; }
      if ((m = line.match(/^(\d+)[.)]\s+(.+)$/))) {
        if (listType !== 'ol') { list = el('ol', 'sbw-ol'); bubble.appendChild(list); listType = 'ol'; }
        const li = document.createElement('li'); inline(li, m[2]); list.appendChild(li); return;
      }
      if ((m = line.match(/^[-*•]\s+(.+)$/))) {
        if (listType !== 'ul') { list = el('ul', 'sbw-list'); bubble.appendChild(list); listType = 'ul'; }
        const li = document.createElement('li'); inline(li, m[1]); list.appendChild(li); return;
      }
      list = null; listType = null;
      const p = el('div', 'sbw-p'); inline(p, line); bubble.appendChild(p);
    });
  }
  function addSources(sources) {
    if (!sources || !sources.length) return;
    const s = sources[0];
    const label = s.heading ? (s.title + ' — ' + s.heading) : s.title;
    const wrap = el('div', 'sbw-sources');
    wrap.appendChild(el('span', 'sbw-src-label', {}, t('Source') + ': '));
    wrap.appendChild(document.createTextNode(label));
    body.appendChild(wrap); scroll();
  }
  function addTier(engine) {
    const map = { docs: t('From help docs'), ai: t('AI answer'), web: t('From the web') };
    if (!map[engine]) return;
    body.appendChild(el('div', 'sbw-tier sbw-tier-' + engine, {}, map[engine])); scroll();
  }
  // Thumbs up/down under a bot answer, tied to its log id. Clicking a button
  // again clears the rating. Reflects any rating already stored server-side.
  function addRating(logId, current) {
    if (!rateUrl || !logId) return;
    const row = el('div', 'sbw-rate');
    row.appendChild(el('span', 'sbw-rate-q', {}, t('Was this helpful?')));
    const up = el('button', 'sbw-rate-btn', { type: 'button', 'aria-label': t('Helpful') }, '👍');
    const down = el('button', 'sbw-rate-btn', { type: 'button', 'aria-label': t('Not helpful') }, '👎');
    let state = current || 0;
    const paint = () => {
      up.classList.toggle('sbw-rate-on', state === 1);
      down.classList.toggle('sbw-rate-on', state === -1);
    };
    const rate = async (value) => {
      const next = state === value ? 0 : value; // second click on the same choice clears it
      state = next; paint();
      try {
        await fetch(rateUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
          credentials: 'same-origin',
          body: JSON.stringify({ log_id: logId, rating: next }),
        });
      } catch (_) { /* rating is best-effort */ }
    };
    up.addEventListener('click', () => rate(1));
    down.addEventListener('click', () => rate(-1));
    row.appendChild(up); row.appendChild(down);
    paint();
    body.appendChild(row); scroll();
  }
  function addContact(message, contact) {
    const bubble = addBubble('bot', 'sbw-contact');
    bubble.appendChild(el('div', null, {}, message || t("I couldn't find that. Please reach out to us.")));
    const list = el('div', 'sbw-contact-list');
    if (contact.email) list.appendChild(rowLink(t('Email'), contact.email, 'mailto:' + contact.email));
    if (contact.url) list.appendChild(rowLink(t('Support'), contact.url, contact.url));
    if (list.childNodes.length) bubble.appendChild(list);
    scroll();
  }
  function rowLink(label, text, href) {
    const r = el('div', 'sbw-contact-row');
    r.appendChild(el('span', 'sbw-c-label', {}, label + ': '));
    const a = el('a', null, { href, target: '_blank', rel: 'noopener' }, text);
    r.appendChild(a); return r;
  }

  // ---- dom helpers ----
  function addBubble(who, cls) {
    const wrap = el('div', 'sbw-msg sbw-' + who);
    const bubble = el('div', 'sbw-bubble ' + (cls || ''));
    wrap.appendChild(bubble); body.appendChild(wrap); scroll();
    return bubble;
  }
  function addUser(text) { addBubble('user').textContent = text; }
  function addBot(text) { addBubble('bot').textContent = text; }
  function typing(on) {
    const ex = body.querySelector('.sbw-typing');
    if (on) { if (ex) return; const w = el('div', 'sbw-msg sbw-bot sbw-typing'); const b = el('div', 'sbw-bubble'); b.innerHTML = '<span class="sbw-dot"></span><span class="sbw-dot"></span><span class="sbw-dot"></span>'; w.appendChild(b); body.appendChild(w); scroll(); }
    else if (ex) ex.remove();
  }
  function scroll() { body.scrollTop = body.scrollHeight; }
  function getSession() {
    try { let s = localStorage.getItem('sbw_session'); if (!s) { s = 's-' + Math.random().toString(36).slice(2) + Date.now().toString(36); localStorage.setItem('sbw_session', s); } return s; }
    catch (_) { return 's-' + Date.now().toString(36); }
  }
}

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function el(tag, cls, attrs, text) {
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (attrs) Object.keys(attrs).forEach((k) => e.setAttribute(k, attrs[k]));
  if (text != null) e.textContent = text;
  return e;
}
function iconChat() { return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-8.5 8.5 8.5 8.5 0 0 1-3.8-.9L3 21l1.9-5.7A8.5 8.5 0 1 1 21 11.5z"/></svg>'; }
function iconClose() { return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>'; }
function iconNew() { return '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>'; }
function iconHistory() { return '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l3 2"/></svg>'; }
function iconSend() { return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>'; }

function injectStyles(accent, side) {
  if (document.getElementById('sbw-styles')) return;
  // Palette from WaDesk's own CSS variables (present on :root in-app) so the
  // widget tracks the active theme; hex fallbacks for safety. Accent = admin colour.
  const css = `
  #support-bot-widget{position:fixed;bottom:20px;${side}:20px;z-index:2147482000;font-family:inherit}
  .sbw-launcher{position:fixed;bottom:20px;${side}:20px;width:56px;height:56px;border-radius:50%;border:none;cursor:pointer;background:${accent};color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 20px rgba(11,31,28,.22);transition:transform .15s}
  .sbw-launcher:hover{transform:scale(1.06)}
  .sbw-hidden{display:none}
  .sbw-panel{position:fixed;bottom:20px;${side}:20px;width:374px;max-width:calc(100vw - 32px);height:560px;max-height:calc(100vh - 40px);background:var(--color-paper-0,#fff);border:1px solid var(--color-paper-200,#e5dfd0);border-radius:16px;box-shadow:0 16px 48px rgba(11,31,28,.24);display:none;flex-direction:column;overflow:hidden}
  .sbw-panel.sbw-open{display:flex;animation:sbwup .18s ease}
  @keyframes sbwup{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
  .sbw-head{background:${accent};color:#fff;padding:15px 17px;display:flex;align-items:center;justify-content:space-between}
  .sbw-title{font-weight:600;font-size:15px}
  .sbw-head-actions{display:flex;align-items:center;gap:2px}
  .sbw-history,.sbw-new,.sbw-close{background:none;border:none;color:#fff;cursor:pointer;padding:5px;display:flex;align-items:center;justify-content:center;border-radius:8px;opacity:.9}
  .sbw-history:hover,.sbw-new:hover,.sbw-close:hover{opacity:1;background:rgba(255,255,255,.15)}
  .sbw-body{flex:1;overflow-y:auto;padding:15px;background:var(--color-paper-50,#f5f3ec);display:flex;flex-direction:column;gap:10px}
  .sbw-msg{display:flex}.sbw-msg.sbw-user{justify-content:flex-end}
  .sbw-bubble{max-width:84%;padding:10px 13px;border-radius:14px;font-size:13.5px;line-height:1.55;white-space:pre-wrap;word-wrap:break-word}
  .sbw-bot .sbw-bubble{background:var(--color-paper-0,#fff);color:var(--color-ink-900,#0b1f1c);border:1px solid var(--color-paper-200,#e5dfd0);border-bottom-left-radius:4px}
  .sbw-user .sbw-bubble{background:${accent};color:#fff;border-bottom-right-radius:4px}
  .sbw-rich{white-space:normal}
  .sbw-rich strong{font-weight:600}
  .sbw-h{font-weight:600;margin:11px 0 4px;color:var(--color-ink-900,#0b1f1c)}
  .sbw-h:first-child{margin-top:0}
  .sbw-p{margin:0 0 6px}.sbw-rich>.sbw-p:last-child{margin-bottom:0}
  .sbw-list,.sbw-ol{margin:4px 0 8px;padding-left:20px}.sbw-list li,.sbw-ol li{margin:3px 0}
  .sbw-ol{list-style:decimal}.sbw-list{list-style:disc}
  .sbw-code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;background:var(--color-paper-100,#efebe0);padding:1px 5px;border-radius:5px}
  .sbw-article{background:none;border:none;padding:0;color:${accent};font-weight:600;font-size:13px;cursor:pointer;text-align:left}
  .sbw-from{margin:12px 0 2px;padding-top:9px;border-top:1px solid var(--color-paper-200,#e5dfd0);font-size:11px;font-weight:600;color:var(--color-ink-500,#6b807c);text-transform:uppercase;letter-spacing:.04em}
  .sbw-from + .sbw-h{margin-top:2px}
  .sbw-sources{font-size:11px;color:var(--color-ink-500,#6b807c);padding:0 4px;margin-top:-4px}
  .sbw-src-label{font-weight:600}
  .sbw-tier{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--color-ink-500,#6b807c);padding:0 4px;margin-top:-6px}
  .sbw-tier-web{color:#b45309}.sbw-tier-ai{color:#6d28d9}
  .sbw-rate{display:flex;align-items:center;gap:6px;padding:0 4px;margin-top:-2px}
  .sbw-rate-q{font-size:11px;color:var(--color-ink-500,#6b807c)}
  .sbw-rate-btn{border:1px solid var(--color-paper-200,#e5dfd0);background:var(--color-paper-0,#fff);border-radius:8px;padding:2px 7px;font-size:13px;line-height:1;cursor:pointer;opacity:.7;transition:opacity .12s,border-color .12s}
  .sbw-rate-btn:hover{opacity:1}
  .sbw-rate-on{opacity:1;border-color:${accent};background:var(--color-paper-100,#efebe0)}
  .sbw-sess-list{display:flex;flex-direction:column;gap:7px;margin-top:8px}
  .sbw-sess{display:flex;flex-direction:column;gap:2px;text-align:left;width:100%;border:1px solid var(--color-paper-200,#e5dfd0);background:var(--color-paper-0,#fff);border-radius:10px;padding:9px 11px;cursor:pointer;transition:border-color .12s,background .12s}
  .sbw-sess:hover{border-color:${accent};background:var(--color-paper-50,#f5f3ec)}
  .sbw-sess-title{font-size:13px;font-weight:600;color:var(--color-ink-900,#0b1f1c);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .sbw-sess-meta{font-size:11px;color:var(--color-ink-500,#6b807c)}
  .sbw-contact-list{margin-top:10px;padding-top:9px;border-top:1px solid var(--color-paper-200,#e5dfd0);display:flex;flex-direction:column;gap:5px}
  .sbw-contact-row{font-size:12.5px}.sbw-c-label{font-weight:600;color:var(--color-ink-700,#1f4540)}
  .sbw-contact-row a{color:${accent};text-decoration:none;word-break:break-word}.sbw-contact-row a:hover{text-decoration:underline}
  .sbw-form{display:flex;gap:8px;padding:11px;border-top:1px solid var(--color-paper-200,#e5dfd0);background:var(--color-paper-0,#fff)}
  .sbw-input{flex:1;border:1px solid var(--color-paper-200,#d1d5db);border-radius:20px;padding:9px 14px;font-size:13.5px;outline:none;background:var(--color-paper-0,#fff);color:var(--color-ink-900,#0b1f1c)}
  .sbw-input:focus{border-color:${accent}}
  .sbw-send{width:38px;height:38px;border-radius:50%;border:none;background:${accent};color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0}
  .sbw-send:disabled{opacity:.6}
  .sbw-foot{padding:0 11px 11px;background:var(--color-paper-0,#fff);text-align:center}
  .sbw-articles{font-size:11.5px;color:var(--color-ink-500,#6b807c);text-decoration:underline}
  .sbw-typing .sbw-bubble{display:flex;gap:4px;align-items:center}
  .sbw-dot{width:6px;height:6px;border-radius:50%;background:var(--color-ink-400,#9aa8a4);display:inline-block;animation:sbwblink 1.2s infinite both}
  .sbw-dot:nth-child(2){animation-delay:.2s}.sbw-dot:nth-child(3){animation-delay:.4s}
  @keyframes sbwblink{0%,80%,100%{opacity:.3}40%{opacity:1}}`;
  const style = document.createElement('style');
  style.id = 'sbw-styles';
  style.textContent = css;
  document.head.appendChild(style);
}
