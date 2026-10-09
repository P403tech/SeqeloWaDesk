// Smart-agent builder — Identity → Persona → Brain → Safety → Knowledge → Channels
// Knowledge is training only. Channels decide where the same knowledge may speak.
export default function init() {
  const root = document.getElementById('ait-builder');
  if (!root) return;

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  let defaults = {};
  try {
    const raw = document.getElementById('ait-builder-defaults')?.textContent
      || root.getAttribute('data-defaults')
      || '{}';
    defaults = JSON.parse(raw);
  } catch (e) {
    defaults = {};
  }
  const state = { ...defaults };
  const PROVIDER_META = {
    openai:    { label: 'OpenAI',    dot: '#10A37F' },
    anthropic: { label: 'Anthropic', dot: '#D97757' },
    gemini:    { label: 'Google',    dot: '#4285F4' },
    mistral:   { label: 'Mistral',   dot: '#FA520F' },
    muse:      { label: 'Muse',      dot: '#0081FB' },
  };
  let modelCatalog = [];
  let pickerProvider = state.ai_provider || '';

  const toast = (m, kind = 'success') => (window.toast ? window.toast(m, kind) : null);
  const confirmDialog = (opts) => {
    if (window.confirmDialog) return window.confirmDialog(opts);
    if (window.confirm(opts.message || 'Are you sure?')) opts.onConfirm?.();
  };

  const TOTAL_STEPS = 6;
  let current  = 1;
  let furthest = state.id ? TOTAL_STEPS : 1;

  async function api(path, opts = {}) {
    const url = (typeof window.appUrl === 'function' && path.startsWith('/')) ? window.appUrl(path) : path;
    const res = await fetch(url, {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
        ...(opts.body && !(opts.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
      },
      body: opts.body instanceof FormData ? opts.body : (opts.body ? JSON.stringify(opts.body) : undefined),
    });
    const json = await res.json().catch(() => ({}));
    return { ok: res.ok && json.ok !== false, json, status: res.status };
  }
  function apiError(json, fallback) {
    if (json?.error && typeof json.error === 'string') return json.error;
    if (json?.message && typeof json.message === 'string') return json.message;
    const bag = json?.errors;
    if (bag && typeof bag === 'object') {
      const first = Object.values(bag).flat().find(Boolean);
      if (first) return String(first);
    }
    return fallback;
  }

  // Turn a failed api() response into the REAL reason, not a generic line.
  // Laravel validation (422) → { message, errors: { field: [msg] } }; CSRF
  // (419), plan/other framework failures → { message }; this controller's own
  // guards → { error }. The old handler read only { error }, so every 422/419
  // fell back to a useless catch-all with no field and no reason shown.
  function errorText(json, status) {
    if (json && json.errors && typeof json.errors === 'object') {
      const first = Object.values(json.errors)[0];
      if (first) return Array.isArray(first) ? first[0] : String(first);
    }
    if (status === 419) return 'Your session expired — refresh the page and try again.';
    return (json && (json.error || json.message)) || 'Save failed — check the fields above.';
  }
  function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  // ---------------------------- form binding ----------------------------

  root.querySelectorAll('[data-field]').forEach((el) => {
    const key = el.dataset.field;
    const v = state[key];
    if (el.type === 'checkbox') el.checked = !!v;
    else if (el.type === 'radio') el.checked = String(el.value) === String(v ?? '');
    else el.value = v ?? '';
    el.addEventListener('input',  () => readInto(el));
    el.addEventListener('change', () => readInto(el));
  });
  function readInto(el) {
    const key = el.dataset.field;
    if (el.type === 'checkbox') state[key] = el.checked;
    else if (el.type === 'radio') { if (el.checked) state[key] = el.value; }
    else state[key] = el.value;
    if (String(key || '').startsWith('channel_')) paintChannelControl();
  }

  if (!state.channel_control || typeof state.channel_control !== 'object') state.channel_control = {};
  function ctrlRow(ch) {
    const r = state.channel_control[ch] || {};
    return {
      mode: r.mode === 'specific' ? 'specific' : 'full',
      dms: r.dms !== false,
      comments: !!r.comments,
      stories: !!r.stories,
      orders: !!r.orders,
      keyword: !!r.keyword,
      keyword_text: r.keyword_text || '',
    };
  }
  function paintChannelControl() {
    ['whatsapp', 'facebook', 'instagram', 'tiktok'].forEach((ch) => {
      const on = !!state['channel_' + ch];
      root.querySelector(`[data-control-wrap="${ch}"]`)?.classList.toggle('hidden', !on);
      const row = ctrlRow(ch);
      root.querySelector(`[data-control-specific="${ch}"]`)?.classList.toggle('hidden', row.mode !== 'specific');
      root.querySelectorAll(`[data-control^="${ch}."]`).forEach((el) => {
        const key = el.dataset.control.split('.')[1];
        if (el.type === 'radio') el.checked = el.value === row.mode;
        else if (el.type === 'checkbox') el.checked = !!row[key];
        else el.value = row.keyword_text || '';
      });
    });
  }
  function writeControl(el) {
    const [ch, key] = (el.dataset.control || '').split('.');
    if (!ch || !key) return;
    state.channel_control[ch] = ctrlRow(ch);
    if (el.type === 'radio' && el.checked) state.channel_control[ch].mode = el.value;
    else if (el.type === 'checkbox') state.channel_control[ch][key] = el.checked;
    else state.channel_control[ch][key] = el.value;
    paintChannelControl();
  }
  function readChannelControl() {
    const out = {};
    ['whatsapp', 'facebook', 'instagram', 'tiktok'].forEach((ch) => { out[ch] = ctrlRow(ch); });
    return out;
  }
  root.querySelectorAll('[data-control]').forEach((el) => {
    el.addEventListener('change', () => writeControl(el));
    el.addEventListener('input', () => writeControl(el));
  });
  paintChannelControl();

  const providerEl = root.querySelector('[data-field="ai_provider"]');
  const modelEl = root.querySelector('[data-field="ai_model"]');
  const modelSelect = document.getElementById('ait-model-select');
  const providerPills = document.getElementById('ait-provider-pills');

  function setHiddenModel(provider, model) {
    pickerProvider = provider || pickerProvider;
    if (providerEl) providerEl.value = provider || '';
    if (modelEl) modelEl.value = model || '';
    state.ai_provider = provider || '';
    state.ai_model = model || '';
  }

  function modelsForProvider(prov) {
    return modelCatalog.filter((m) => m.provider === prov);
  }

  function paintModelPicker() {
    const empty = document.getElementById('ait-model-empty');
    const picker = document.getElementById('ait-model-picker');
    if (!modelCatalog.length) {
      empty?.classList.remove('hidden');
      picker?.classList.add('hidden');
      return;
    }
    empty?.classList.add('hidden');
    picker?.classList.remove('hidden');
    const tabs = [];
    for (const m of modelCatalog) {
      if (!tabs.some((t) => t.provider === m.provider)) {
        const meta = PROVIDER_META[m.provider] || {};
        tabs.push({
          provider: m.provider,
          label: meta.label || m.label.split(' · ')[0] || m.provider,
          dot: meta.dot || '#888',
        });
      }
    }
    if (!tabs.some((t) => t.provider === pickerProvider)) {
      pickerProvider = tabs[0]?.provider || '';
    }
    if (providerPills) {
      providerPills.innerHTML = tabs.map((t) => {
        const on = t.provider === pickerProvider;
        const cls = on
          ? 'border-wa-deep bg-wa-mint/30 text-ink-900'
          : 'border-paper-200 bg-paper-0 text-ink-700 hover:bg-paper-50';
        return `<button type="button" data-pick-provider="${t.provider}" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-[11.5px] font-semibold transition ${cls}"><span class="w-2 h-2 rounded-full" style="background:${t.dot}"></span>${t.label}</button>`;
      }).join('');
    }
    const list = modelsForProvider(pickerProvider);
    if (state.ai_model && !list.some((m) => m.value === state.ai_model) && state.ai_provider === pickerProvider) {
      list.unshift({ value: state.ai_model, label: state.ai_model + ' (saved)', provider: pickerProvider });
    }
    if (modelSelect) {
      modelSelect.innerHTML = list.map((m) => {
        const short = (m.label.split(' · ')[1] || m.label);
        return `<option value="${escapeHtml(m.value)}" ${m.value === state.ai_model ? 'selected' : ''}>${escapeHtml(short)}</option>`;
      }).join('');
    }
    const chosen = list.find((m) => m.value === state.ai_model) || list[0];
    if (chosen) setHiddenModel(chosen.provider, chosen.value);
    if (modelSelect && chosen) modelSelect.value = chosen.value;
  }

  providerPills?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-pick-provider]');
    if (!btn) return;
    pickerProvider = btn.getAttribute('data-pick-provider') || '';
    const first = modelsForProvider(pickerProvider)[0];
    if (first) setHiddenModel(first.provider, first.value);
    paintModelPicker();
  });
  modelSelect?.addEventListener('change', () => {
    const val = modelSelect.value;
    const row = modelCatalog.find((m) => m.value === val && m.provider === pickerProvider)
      || modelCatalog.find((m) => m.value === val);
    setHiddenModel(row?.provider || pickerProvider, val);
  });

  async function loadModelCatalog() {
    const { ok, json } = await api('/flows/api/ai-models');
    if (ok && Array.isArray(json.models)) modelCatalog = json.models;
    if (state.ai_model && !modelCatalog.some((m) => m.value === state.ai_model)) {
      modelCatalog.unshift({
        value: state.ai_model,
        label: (state.ai_provider || 'saved') + ' · ' + state.ai_model,
        provider: state.ai_provider || 'openai',
      });
    }
    paintModelPicker();
  }

  // --------------------------- step nav ---------------------------

  function paintStepper() {
    root.querySelectorAll('.step-node').forEach((node) => {
      const n = parseInt(node.dataset.n, 10);
      const dot = node.querySelector('.dot');
      const lab = node.querySelector('.lab');
      const bar = node.querySelector('.bar');
      if (!dot || !lab) return;
      dot.className = 'dot w-7 h-7 rounded-full grid place-items-center text-[11px] font-semibold font-mono shrink-0 transition border-[1.5px]';
      lab.className = 'lab text-[11.5px] whitespace-nowrap';
      if (n < current) {
        dot.classList.add('bg-wa-deep', 'border-wa-deep', 'text-paper-0');
        dot.innerHTML = '<svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M3 8l3 3 7-8"/></svg>';
        lab.classList.add('font-semibold', 'text-ink-900');
      } else if (n === current) {
        dot.classList.add('bg-paper-0', 'border-wa-deep', 'text-wa-deep', 'ring-4', 'ring-wa-deep/10');
        dot.textContent = String(n);
        lab.classList.add('font-semibold', 'text-wa-deep');
      } else {
        dot.classList.add('bg-paper-0', 'border-paper-200', 'text-ink-500');
        dot.textContent = String(n);
        lab.classList.add('font-medium', 'text-ink-500');
      }
      if (bar) {
        bar.classList.remove('bg-paper-200', 'bg-wa-deep');
        bar.classList.add(n < current ? 'bg-wa-deep' : 'bg-paper-200');
      }
    });
  }

  function showStep(n) {
    if (n < 1 || n > TOTAL_STEPS) return;
    current = n;
    if (n > furthest) furthest = n;
    root.querySelectorAll('.step-pane').forEach((p) => p.classList.add('hidden'));
    const pane = root.querySelector(`.step-pane[data-step="${n}"]`);
    if (pane) pane.classList.remove('hidden');
    document.getElementById('ait-cur').textContent = String(n);
    document.getElementById('ait-prev').disabled = (n === 1);
    // Use inline style — Tailwind's `hidden` loses to `inline-flex` on
    // the button (same display category, source-order specificity).
    document.getElementById('ait-next').style.display   = (n === TOTAL_STEPS) ? 'none' : '';
    document.getElementById('ait-finish').style.display = (n === TOTAL_STEPS) ? '' : 'none';
    paintStepper();
  }

  root.querySelectorAll('.step-node').forEach((node) => {
    node.addEventListener('click', () => {
      const target = parseInt(node.dataset.n, 10);
      if (target >= 5 && !state.id) {
        toast('Save the agent first — Knowledge and Channels need a saved row.', 'info');
        return;
      }
      // Free to jump backward / to an already-cleared step; forward
      // jumps must pass the current step's required fields.
      if (target <= current || target <= furthest) showStep(target);
      else if (gateForward(current)) showStep(target);
    });
  });
  document.getElementById('ait-prev').addEventListener('click', () => { if (current > 1) showStep(current - 1); });
  document.getElementById('ait-next').addEventListener('click', async () => {
    if (!gateForward(current)) return;
    // Save before unlocking Knowledge (step 5) and Channels (step 6).
    if (current === 4) {
      const ok = await saveAssistant({ silent: true });
      if (!ok) return;
      await loadSources();
    }
    showStep(current + 1);
  });

  // ── Per-step validation gate ─────────────────────────────────────
  // Block forward navigation (Next button OR a step-node jump) until
  // the current step's required fields are filled. Backward moves are
  // always allowed. validateStep returns {ok, msg, el} so the caller
  // can toast + highlight the offending field. Fields are bound by
  // [data-field] (not id), so we resolve elements that way.
  const field = (k) => root.querySelector(`[data-field="${k}"]`);
  function flashInvalid(el) {
    if (!el) return;
    el.classList.add('ring-2', 'ring-accent-coral/60', 'border-accent-coral');
    try { el.focus({ preventScroll: false }); } catch (_) {}
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => el.classList.remove('ring-2', 'ring-accent-coral/60', 'border-accent-coral'), 2600);
  }

  function validateStep(n) {
    if (n === 1) {
      if (!String(state.name || '').trim()) {
        return { ok: false, msg: 'Name your assistant.', el: field('name') };
      }
    }
    if (n === 2) {
      if (!String(state.greeting || '').trim()) {
        return { ok: false, msg: 'Write an opening line.', el: field('greeting') };
      }
    }
    if (n === 3) {
      if (!String(state.ai_model || '').trim()) {
        return { ok: false, msg: 'Pick a model.', el: field('ai_model') };
      }
      const mt = parseInt(state.reply_max_tokens, 10);
      if (Number.isNaN(mt) || mt < 50 || mt > 4000) {
        return { ok: false, msg: 'Reply length must be 50–4000 tokens.', el: field('reply_max_tokens') };
      }
      const t = parseFloat(state.temperature);
      if (Number.isNaN(t) || t < 0 || t > 2) {
        return { ok: false, msg: 'Creativity must be between 0 and 2.', el: field('temperature') };
      }
    }
    // Step 4 safety, step 5 knowledge, and step 6 channels are optional.
    return { ok: true };
  }

  // Guard a forward move; returns true if it's allowed to proceed.
  function gateForward(from) {
    const v = validateStep(from);
    if (!v.ok) { toast(v.msg, 'error'); flashInvalid(v.el); }
    return v.ok;
  }

  // ------------------------------- save -------------------------------

  async function saveAssistant({ silent = false } = {}) {
    // Walk every step's validator before posting.
    for (let n = 1; n <= 4; n++) {
      const v = validateStep(n);
      if (!v.ok) { showStep(n); toast(v.msg, 'error'); flashInvalid(v.el); return false; }
    }
    const body = {
      id: state.id,
      name: state.name,
      status: state.status,
      greeting: state.greeting,
      system_prompt: state.system_prompt,
      tone: state.tone,
      language: state.language,
      ai_provider: state.ai_provider,
      ai_model: state.ai_model,
      reply_max_tokens: parseInt(state.reply_max_tokens, 10) || 400,
      temperature: parseFloat(state.temperature) || 0.7,
      fallback_message: state.fallback_message,
      handoff_enabled: !!state.handoff_enabled,
      handoff_keyword: state.handoff_keyword,
      handoff_message: state.handoff_message,
      business_brief: state.business_brief,
      channel_whatsapp: !!state.channel_whatsapp,
      channel_facebook: !!state.channel_facebook,
      channel_instagram: !!state.channel_instagram,
      channel_tiktok: !!state.channel_tiktok,
      shopify_tools: !!state.shopify_tools,
      channel_control: readChannelControl(),
    };
    const { ok, json, status } = await api('/ai-training/api/assistant', { method: 'POST', body });
    if (!ok) { toast(errorText(json, status), 'error'); return false; }
    state.id = json.id;
    document.getElementById('ait-state-pill').textContent = 'Saved';
    if (!silent) toast('Agent saved.', 'success');
    return true;
  }

  document.getElementById('ait-save')?.addEventListener('click', () => saveAssistant());

  document.getElementById('ait-test-send')?.addEventListener('click', async () => {
    const input = document.getElementById('ait-test-input');
    const out = document.getElementById('ait-test-out');
    const status = document.getElementById('ait-test-status');
    const message = String(input?.value || '').trim();
    if (!message) { toast('Type a test message first.', 'error'); return; }
    if (status) status.textContent = 'Sending…';
    if (out) { out.classList.add('hidden'); out.textContent = ''; }
    const { ok, json, status: http } = await api('/ai-training/api/test', {
      method: 'POST',
      body: {
        id: state.id || null,
        message,
        name: state.name,
        system_prompt: state.system_prompt,
        tone: state.tone,
        language: state.language,
        ai_provider: state.ai_provider,
        ai_model: state.ai_model,
        reply_max_tokens: parseInt(state.reply_max_tokens, 10) || 400,
        temperature: parseFloat(state.temperature) || 0.7,
        handoff_enabled: !!state.handoff_enabled,
        handoff_keyword: state.handoff_keyword,
        handoff_message: state.handoff_message,
        business_brief: state.business_brief,
      },
    });
    if (status) status.textContent = '';
    if (!ok) {
      const msg = errorText(json, http);
      if (out) { out.textContent = msg; out.classList.remove('hidden'); }
      toast(msg, 'error');
      return;
    }
    if (out) { out.textContent = json.reply || ''; out.classList.remove('hidden'); }
  });
  document.getElementById('ait-finish')?.addEventListener('click', async () => {
    const ok = await saveAssistant();
    if (ok) window.location.href = window.appUrl('/ai-training');
  });

  function agentReturnUrl() {
    const path = state.id
      ? `/ai-training/${state.id}/edit?step=6`
      : '/ai-training/create?step=6';
    return window.location.origin + window.appUrl(path);
  }

  async function prepareConnect() {
    if (!String(state.name || '').trim()) {
      toast('Name the agent first so we can save before connecting.', 'error');
      showStep(1);
      return false;
    }
    const ok = await saveAssistant({ silent: true });
    if (!ok) return false;
    history.replaceState({}, '', window.appUrl(`/ai-training/${state.id}/edit?step=6`));
    return true;
  }

  root.querySelectorAll('[data-channel-connect]').forEach((el) => {
    el.addEventListener('click', async (e) => {
      const kind = el.dataset.channelConnect;
      if (kind === 'whatsapp') {
        e.preventDefault();
        if (!(await prepareConnect())) return;
        window.location.href = window.appUrl('/devices');
        return;
      }
      if (kind === 'instagram') {
        e.preventDefault();
        if (!(await prepareConnect())) return;
        const ret = encodeURIComponent(agentReturnUrl());
        const { ok, json } = await api('/devices/instagram/connect-start?return=' + ret, {
          method: 'POST',
          body: { return: agentReturnUrl() },
        });
        if (!ok || !json.url) {
          toast(json.error || 'Could not start Instagram connect.', 'error');
          return;
        }
        window.location.href = json.url;
        return;
      }
      e.preventDefault();
      if (!(await prepareConnect())) return;
      const href = el.getAttribute('href') || '';
      const join = href.includes('?') ? '&' : '?';
      window.location.href = href + join + 'return=' + encodeURIComponent(agentReturnUrl());
    });
  });

  root.querySelectorAll('[data-channel-form]').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!(await prepareConnect())) return;
      const hidden = form.querySelector('input[name="return"]');
      if (hidden) hidden.value = agentReturnUrl();
      form.submit();
    });
  });

  // ----------------------------- sources -----------------------------

  async function loadSources() {
    if (!state.id) return;
    const { ok, json } = await api(`/ai-training/api/sources?assistant_id=${state.id}`);
    if (!ok) return;
    const rows = document.getElementById('ait-source-rows');
    if (!rows) return;
    if (!json.sources?.length) {
      rows.innerHTML = `<div class="px-3 py-10 text-center text-[12px] text-ink-500">No knowledge yet. Pick a kind above to add the first entry.</div>`;
      return;
    }
    const failedN = json.sources.filter((s) => s.status === 'failed').length;
    const partialN = json.sources.filter((s) => s.status === 'partial').length;
    const readyN = json.sources.filter((s) => s.status === 'ready').length;
    const summary = `<div class="px-3 py-2 text-[12px] border-b border-paper-200 bg-paper-50/60 text-ink-600">${readyN} indexed${failedN ? ` · <span class="text-accent-coral">${failedN} failed</span>` : ''}${partialN ? ` · ${partialN} truncated` : ''}. Failed rows are not used in replies.</div>`;
    rows.innerHTML = summary + json.sources.map((s) => `
      <div class="px-3 py-2 grid grid-cols-[80px_1fr_120px_70px] items-center gap-3 border-b border-paper-200 hover:bg-paper-50">
        <div class="text-[11px] font-mono uppercase tracking-[0.14em] text-ink-500">${s.kind}</div>
        <div class="min-w-0">
          <div class="text-[13px] text-ink-900 truncate">${escapeHtml(s.label)}</div>
          ${s.url ? `<div class="text-[11px] text-ink-500 font-mono truncate">${escapeHtml(s.url)}</div>` : ''}
        </div>
        <div>
          <span class="inline-flex items-center gap-1 text-[10.5px] font-mono uppercase tracking-[0.14em] px-1.5 py-0.5 rounded ${statusClasses(s.status)}">
            <span class="w-1.5 h-1.5 rounded-full ${statusDotClass(s.status)}"></span>${statusLabel(s.status)}
          </span>
          ${s.error ? `<div class="text-[10px] text-accent-coral mt-0.5 truncate" title="${escapeHtml(s.error)}">${escapeHtml(s.error)}</div>` : ''}
        </div>
        <div class="text-right">
          <button type="button" data-delete-source="${s.id}" class="w-7 h-7 rounded-full hover:bg-accent-coral/15 text-accent-coral inline-flex items-center justify-center" title="Remove">
            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 4h10M6 4V2.5h4V4M5 4l1 9h4l1-9"/></svg>
          </button>
        </div>
      </div>
    `).join('');
    rows.querySelectorAll('[data-delete-source]').forEach((b) => {
      b.addEventListener('click', () => {
        const id = b.dataset.deleteSource;
        confirmDialog({
          eyebrow: 'Remove knowledge',
          title: 'Remove this knowledge entry?',
          message: 'The agent will stop using it for new replies. Older conversations stay unchanged.',
          confirmText: 'Remove',
          cancelText: 'Keep',
          tone: 'danger',
          onConfirm: async () => {
            const { ok } = await api(`/ai-training/api/source/${id}`, { method: 'DELETE' });
            if (ok) { toast('Removed.', 'success'); loadSources(); }
            else { toast('Remove failed.', 'error'); }
          },
        });
      });
    });
  }

  const statusClasses = (s) =>
    s === 'ready'  ? 'bg-wa-mint text-wa-deep' :
    s === 'failed' ? 'bg-accent-coral/10 text-accent-coral' :
    s === 'partial' ? 'bg-paper-100 text-ink-700' :
                     'bg-paper-100 text-ink-500';
  const statusDotClass = (s) =>
    s === 'ready'  ? 'bg-wa-green' :
    s === 'failed' ? 'bg-accent-coral' :
    s === 'partial' ? 'bg-ink-500' :
                     'bg-paper-200';
  const statusLabel = (s) =>
    s === 'ready' ? 'Indexed' :
    s === 'failed' ? 'Failed' :
    s === 'partial' ? 'Truncated' :
    (s || 'Pending');

  const addPanel = document.getElementById('ait-source-add');

  function openAddPanel(kind) {
    if (!addPanel) return;
    let html = '';
    if (kind === 'url') {
      html = `
        <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">Fetch a public URL</div>
        <input data-src-label placeholder="Label (e.g. Pricing page)" class="w-full bg-paper-0 border border-paper-200 rounded-md px-2.5 py-1.5 text-[12.5px]">
        <input data-src-url placeholder="https://yoursite.com/pricing" class="w-full bg-paper-0 border border-paper-200 rounded-md px-2.5 py-1.5 text-[12.5px] font-mono">
        <div class="flex gap-2">
          <button type="button" data-src-cancel class="px-3 py-1.5 rounded-md border border-paper-200 text-[12px] font-semibold text-ink-700">Cancel</button>
          <button type="button" data-src-go class="px-3 py-1.5 rounded-md bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">Fetch &amp; train</button>
        </div>`;
    } else if (kind === 'text') {
      html = `
        <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">Paste plain text</div>
        <input data-src-label placeholder="Label (e.g. Refund policy summary)" class="w-full bg-paper-0 border border-paper-200 rounded-md px-2.5 py-1.5 text-[12.5px]">
        <textarea data-src-content rows="6" placeholder="Paste any text the agent should know..." class="w-full bg-paper-0 border border-paper-200 rounded-md px-2.5 py-1.5 text-[12.5px]"></textarea>
        <div class="flex gap-2">
          <button type="button" data-src-cancel class="px-3 py-1.5 rounded-md border border-paper-200 text-[12px] font-semibold text-ink-700">Cancel</button>
          <button type="button" data-src-go class="px-3 py-1.5 rounded-md bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">Save text</button>
        </div>`;
    } else if (kind === 'qa') {
      html = `
        <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">Single Q&amp;A</div>
        <input data-src-label placeholder="Label (e.g. Refund timing)" class="w-full bg-paper-0 border border-paper-200 rounded-md px-2.5 py-1.5 text-[12.5px]">
        <input data-src-question placeholder="Question · How long do refunds take?" class="w-full bg-paper-0 border border-paper-200 rounded-md px-2.5 py-1.5 text-[12.5px]">
        <textarea data-src-answer rows="3" placeholder="Answer · Refunds settle within 3-5 business days." class="w-full bg-paper-0 border border-paper-200 rounded-md px-2.5 py-1.5 text-[12.5px]"></textarea>
        <div class="flex gap-2">
          <button type="button" data-src-cancel class="px-3 py-1.5 rounded-md border border-paper-200 text-[12px] font-semibold text-ink-700">Cancel</button>
          <button type="button" data-src-go class="px-3 py-1.5 rounded-md bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">Save pair</button>
        </div>`;
    } else if (kind === 'catalog') {
      html = `
        <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">Add your product catalog</div>
        <input data-src-label value="Product catalog" placeholder="Label" class="w-full bg-paper-0 border border-paper-200 rounded-md px-2.5 py-1.5 text-[12.5px]">
        <p class="text-[11px] text-ink-500">The agent will read your live products — names, prices and stock — and stay up to date automatically. No file to upload.</p>
        <div class="flex gap-2">
          <button type="button" data-src-cancel class="px-3 py-1.5 rounded-md border border-paper-200 text-[12px] font-semibold text-ink-700">Cancel</button>
          <button type="button" data-src-go class="px-3 py-1.5 rounded-md bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">Add catalog</button>
        </div>`;
    } else if (kind === 'file') {
      html = `
        <div class="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-500">Upload knowledge file</div>
        <input data-src-label placeholder="Label (e.g. Product handbook)" class="w-full bg-paper-0 border border-paper-200 rounded-md px-2.5 py-1.5 text-[12.5px]">
        <label class="flex flex-col gap-1.5 cursor-pointer">
          <span class="text-[12px] font-semibold text-ink-800">Choose a file</span>
          <input data-src-file type="file" name="file" accept=".xlsx,.xlsm,.csv,.pdf,.docx,.txt,.md,.markdown,.html,.htm,.log,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/csv,text/plain,text/html" class="block w-full text-[12.5px]">
        </label>
        <p data-src-filename class="text-[12px] text-ink-700 hidden"></p>
        <p class="text-[11px] text-ink-500">Excel (.xlsx), CSV, PDF, DOCX, TXT — up to 10 MB. Old .xls must be saved as .xlsx. The agent reads the extracted text.</p>
        <p data-src-error class="hidden text-[12px] text-accent-coral"></p>
        <div class="flex gap-2">
          <button type="button" data-src-cancel class="px-3 py-1.5 rounded-md border border-paper-200 text-[12px] font-semibold text-ink-700">Cancel</button>
          <button type="button" data-src-go class="px-3 py-1.5 rounded-md bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">Upload</button>
        </div>`;
    }
    addPanel.innerHTML = html;
    addPanel.classList.remove('hidden');
    addPanel.dataset.kind = kind;
    addPanel.querySelector('[data-src-cancel]')?.addEventListener('click', () => {
      addPanel.classList.add('hidden'); addPanel.innerHTML = '';
    });
    addPanel.querySelector('[data-src-go]')?.addEventListener('click', () => submitAdd(kind));
    if (kind === 'file') {
      const fileEl = addPanel.querySelector('[data-src-file]');
      const nameEl = addPanel.querySelector('[data-src-filename]');
      fileEl?.addEventListener('change', () => {
        const f = fileEl.files?.[0];
        if (nameEl) {
          nameEl.textContent = f ? `Selected: ${f.name}` : '';
          nameEl.classList.toggle('hidden', !f);
        }
        addPanel.querySelector('[data-src-error]')?.classList.add('hidden');
      });
      setTimeout(() => fileEl?.click(), 50);
    }
  }

  function showAddError(msg) {
    const el = addPanel?.querySelector('[data-src-error]');
    if (el) {
      el.textContent = msg;
      el.classList.remove('hidden');
    }
    toast(msg, 'error');
  }

  async function submitAdd(kind) {
    if (!state.id) { toast('Save the agent first (Save draft), then upload knowledge.', 'error'); return; }
    const get = (sel) => addPanel.querySelector(sel)?.value ?? '';
    const label = get('[data-src-label]');
    if (!label.trim()) { showAddError('Add a label so you can find this entry later.'); return; }

    if (kind === 'file') {
      const fileEl = addPanel.querySelector('[data-src-file]');
      const file = fileEl?.files?.[0];
      if (!file) { showAddError('Choose a file first — the chooser still says “No file chosen”.'); return; }
      const go = addPanel.querySelector('[data-src-go]');
      if (go) { go.disabled = true; go.textContent = 'Uploading…'; }
      const fd = new FormData();
      fd.append('file', file);
      fd.append('label', label);
      fd.append('assistant_id', String(state.id));
      const { ok, json, status } = await api('/ai-training/api/source/file', { method: 'POST', body: fd });
      if (go) { go.disabled = false; go.textContent = 'Upload'; }
      if (!ok) {
        showAddError(apiError(json, status === 413 ? 'File is too large (max 10 MB).' : 'Upload failed.'));
        return;
      }
      if (json.status === 'partial') {
        toast(json.error || 'File indexed, but it was truncated.', 'error');
        addPanel.classList.add('hidden');
        addPanel.innerHTML = '';
        loadSources();
        return;
      }
    } else {
      const body = { assistant_id: state.id, kind, label };
      if (kind === 'url')  body.url = get('[data-src-url]');
      if (kind === 'text') body.content = get('[data-src-content]');
      if (kind === 'qa')   { body.question = get('[data-src-question]'); body.answer = get('[data-src-answer]'); }
      const { ok, json } = await api('/ai-training/api/source', { method: 'POST', body });
      if (!ok) { toast(apiError(json, 'Add failed.'), 'error'); return; }
      if (json.status === 'failed') {
        toast(json.error || 'Fetch failed — the row is saved so you can retry or delete it.', 'error');
        addPanel.classList.add('hidden');
        addPanel.innerHTML = '';
        loadSources();
        return;
      }
    }
    addPanel.classList.add('hidden');
    addPanel.innerHTML = '';
    toast('Knowledge added.', 'success');
    loadSources();
  }

  root.querySelectorAll('[data-add-source]').forEach((b) => {
    b.addEventListener('click', () => openAddPanel(b.dataset.addSource));
  });

  // ------------------------------- boot -------------------------------

  const bootStep = parseInt(new URLSearchParams(location.search).get('step') || '0', 10);
  showStep(bootStep >= 1 && bootStep <= TOTAL_STEPS ? bootStep : 1);
  if (state.id) loadSources();
  loadModelCatalog();
}
