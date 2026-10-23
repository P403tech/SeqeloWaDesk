// WhatsApp Tools page — wires the WABA Cloud API advanced features to the
// WabaToolsController endpoints (/whatsapp-tools/api/*).
export default function () {
  const root = document.querySelector('[data-waba-tools]');
  if (!root) return;

  const BASE = '/whatsapp-tools/api';
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const $ = (s) => root.querySelector(s);
  const $$ = (s) => [...root.querySelectorAll(s)];

  const toastEl = document.getElementById('toast');
  const toast = (msg, kind = 'info') => {
    if (!toastEl) { console.log('[' + kind + ']', msg); return; }
    toastEl.textContent = msg; toastEl.className = 'toast toast-' + kind + ' show';
    clearTimeout(toastEl._t); toastEl._t = setTimeout(() => toastEl.classList.remove('show'), 3500);
  };
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  async function api(path, method = 'GET', body) {
    const r = await fetch(BASE + path, {
      method, headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
    });
    const j = await r.json().catch(() => ({}));
    if (!r.ok || j.ok === false) throw new Error(j.error || ('HTTP ' + r.status));
    return j;
  }
  const guard = async (fn, okMsg) => { try { const r = await fn(); if (okMsg) toast(okMsg, 'success'); return r; } catch (e) { toast(e.message, 'error'); } };

  // ── Read receipts / typing ──
  $('[data-save-receipts]')?.addEventListener('click', () => guard(() => api('/receipts', 'POST', {
    read_receipts: $('[data-read-receipts]').checked, typing_indicator: $('[data-typing]').checked,
  }), 'Saved.'));

  // ── Business profile ──
  $('[data-profile-load]')?.addEventListener('click', () => guard(async () => {
    const r = await api('/profile'); const p = r.profile || {};
    const set = (f, v) => { const el = $('[data-f="' + f + '"]'); if (el) el.value = v || ''; };
    set('about', p.about); set('description', p.description); set('email', p.email);
    set('vertical', p.vertical); set('address', p.address);
    set('web0', (p.websites || [])[0]); set('web1', (p.websites || [])[1]);
    toast('Loaded.', 'success');
  }));
  $('[data-profile-save]')?.addEventListener('click', () => {
    const v = (f) => ($('[data-f="' + f + '"]')?.value || '').trim();
    const websites = [v('web0'), v('web1')].filter(Boolean);
    guard(() => api('/profile', 'POST', {
      about: v('about'), description: v('description'), email: v('email'),
      vertical: v('vertical'), address: v('address'), websites,
    }), 'Profile saved.');
  });

  // ── Conversational automation ──
  const commandsEl = $('[data-commands]');
  function addCommandRow(name = '', desc = '') {
    const row = document.createElement('div');
    row.className = 'flex items-center gap-2';
    row.innerHTML =
      '<input data-cmd-name maxlength="32" placeholder="menu" value="' + esc(name) + '" class="w-32 rounded-lg border border-paper-200 px-2.5 py-1.5 text-[12px] font-mono focus:outline-none focus:border-wa-deep">' +
      '<input data-cmd-desc maxlength="256" placeholder="Show the menu" value="' + esc(desc) + '" class="flex-1 rounded-lg border border-paper-200 px-2.5 py-1.5 text-[12px] focus:outline-none focus:border-wa-deep">' +
      '<button type="button" data-cmd-remove class="w-7 h-7 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 8h8"/></svg></button>';
    commandsEl.appendChild(row);
  }
  $('[data-add-command]')?.addEventListener('click', () => addCommandRow());
  commandsEl?.addEventListener('click', (e) => { if (e.target.closest('[data-cmd-remove]')) e.target.closest('div').remove(); });

  $('[data-automation-load]')?.addEventListener('click', () => guard(async () => {
    const r = await api('/automation');
    $('[data-welcome]').checked = !!r.welcome;
    (r.prompts || []).forEach((p, i) => { const el = $('[data-ib="' + i + '"]'); if (el) el.value = p || ''; });
    commandsEl.innerHTML = '';
    (r.commands || []).forEach((c) => addCommandRow(c.command_name, c.command_description));
    toast('Loaded.', 'success');
  }));
  $('[data-automation-save]')?.addEventListener('click', () => {
    const prompts = $$('[data-ib]').map((el) => el.value.trim()).filter(Boolean);
    const commands = $$('[data-commands] > div').map((row) => ({
      command_name: row.querySelector('[data-cmd-name]').value.trim(),
      command_description: row.querySelector('[data-cmd-desc]').value.trim(),
    })).filter((c) => c.command_name);
    guard(() => api('/automation', 'POST', { welcome: $('[data-welcome]').checked, prompts, commands }), 'Saved.');
  });

  // ── QR codes ──
  function renderQr(codes) {
    const el = $('[data-qr-list]');
    if (!codes.length) { el.innerHTML = '<div class="text-[11.5px] text-ink-500">No QR codes yet.</div>'; return; }
    el.innerHTML = codes.map((c) => (
      '<div class="flex items-center justify-between gap-3 border border-paper-200 rounded-xl px-3 py-2">' +
        '<div class="min-w-0"><div class="text-[12.5px] font-medium truncate">' + esc(c.prefilled_message || '(no message)') + '</div>' +
          '<a href="' + esc(c.deep_link_url) + '" target="_blank" rel="noopener" class="text-[11px] font-mono text-wa-deep hover:underline break-all">' + esc(c.deep_link_url) + '</a></div>' +
        (c.qr_image_url ? '<a href="' + esc(c.qr_image_url) + '" target="_blank" rel="noopener" class="shrink-0 text-[11px] text-wa-deep font-semibold hover:underline">Image</a>' : '') +
        '<button type="button" data-qr-del="' + esc(c.code) + '" class="shrink-0 text-[11px] text-accent-coral font-semibold hover:underline">Delete</button>' +
      '</div>'
    )).join('');
  }
  const loadQr = () => guard(async () => renderQr((await api('/qr')).codes || []));
  $('[data-qr-load]')?.addEventListener('click', loadQr);
  $('[data-qr-create]')?.addEventListener('click', () => {
    const msg = $('[data-qr-msg]').value.trim();
    if (!msg) { toast('Enter a prefilled message.', 'error'); return; }
    guard(async () => { await api('/qr', 'POST', { prefilled_message: msg, format: $('[data-qr-fmt]').value }); $('[data-qr-msg]').value = ''; await loadQr(); }, 'QR created.');
  });
  $('[data-qr-list]')?.addEventListener('click', (e) => {
    const b = e.target.closest('[data-qr-del]'); if (!b) return;
    guard(async () => { await api('/qr/delete', 'POST', { code: b.dataset.qrDel }); await loadQr(); }, 'Deleted.');
  });

  // ── Two-step PIN ──
  $('[data-pin-save]')?.addEventListener('click', () => {
    const pin = ($('[data-pin]').value || '').trim();
    if (!/^\d{6}$/.test(pin)) { toast('PIN must be exactly 6 digits.', 'error'); return; }
    guard(async () => { await api('/pin', 'POST', { pin }); $('[data-pin]').value = ''; }, 'PIN updated.');
  });

  // ── Analytics ──
  $('[data-an-load]')?.addEventListener('click', () => guard(async () => {
    const r = await api('/analytics?days=' + $('[data-an-days]').value);
    $('[data-an-sent]').textContent = (r.sent || 0).toLocaleString();
    $('[data-an-delivered]').textContent = (r.delivered || 0).toLocaleString();
  }));

  // Prime the sections that are cheap to load (only when connected — the
  // sections are absent on the "connect a number" notice).
  if ($('[data-qr-list]')) loadQr();
}
