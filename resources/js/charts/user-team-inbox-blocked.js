// Blocked Contacts management page — list, block a number, unblock (bulk).
// Talks to /team-inbox/api/blocked-contacts (list) + /block + /unblock. The
// unblock/block endpoints return meta_block_warning when Meta (WABA) didn't
// apply the platform-level block — surfaced as a toast.
export default function () {
  const root = document.querySelector('[data-rows]');
  if (!root) return;

  const BASE = '/team-inbox/api/blocked-contacts';
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  const $ = (s) => document.querySelector(s);
  const toastEl = document.getElementById('toast');
  const toast = (msg, kind = 'info') => {
    if (!toastEl) { console.log('[' + kind + ']', msg); return; }
    toastEl.textContent = msg;
    toastEl.className = 'toast toast-' + kind + ' show';
    clearTimeout(toastEl._t);
    toastEl._t = setTimeout(() => toastEl.classList.remove('show'), 3500);
  };
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  async function api(path, method = 'GET', body) {
    const r = await fetch(BASE + path, {
      method,
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
    });
    const j = await r.json().catch(() => ({}));
    if (!r.ok || j.ok === false) throw new Error(j.error || ('HTTP ' + r.status));
    return j;
  }

  const rowsEl   = $('[data-rows]');
  const emptyEl  = $('[data-empty]');
  const countEl  = $('[data-blocked-count]');
  const selAll   = $('[data-select-all]');
  const unblkBtn = $('[data-unblock-selected]');

  function selectedIds() {
    return [...rowsEl.querySelectorAll('input[data-id]:checked')].map((c) => parseInt(c.dataset.id, 10));
  }
  function syncBulkBtn() {
    const n = selectedIds().length;
    unblkBtn.disabled = n === 0;
    unblkBtn.textContent = n > 0 ? ('Unblock selected (' + n + ')') : 'Unblock selected';
  }

  function render(contacts) {
    const chGlyph = { whatsapp: 'WhatsApp', instagram: 'Instagram', facebook: 'Facebook', telegram: 'Telegram' };
    countEl.textContent = contacts.length + (contacts.length === 1 ? ' contact' : ' contacts');
    if (!contacts.length) {
      rowsEl.innerHTML = '';
      emptyEl.hidden = false;
      if (selAll) selAll.checked = false;
      syncBulkBtn();
      return;
    }
    emptyEl.hidden = true;
    rowsEl.innerHTML = contacts.map((c) => (
      '<tr>' +
        '<td class="px-4 py-3"><input type="checkbox" data-id="' + c.id + '" class="rounded border-paper-200 accent-wa-deep"></td>' +
        '<td class="px-2 py-3"><div class="font-medium truncate max-w-[220px]">' + esc(c.name) + '</div>' +
          '<div class="font-mono text-[11px] text-ink-500">' + esc(c.phone) + '</div></td>' +
        '<td class="px-2 py-3 text-[11.5px] text-ink-700">' + esc(chGlyph[c.channel] || c.channel) + '</td>' +
        '<td class="px-2 py-3 text-[11px] text-ink-500">' + esc(c.blocked_at || '—') + '</td>' +
        '<td class="px-4 py-3 text-right"><button type="button" data-unblock-one="' + c.id + '" ' +
          'class="text-[11.5px] text-wa-deep font-semibold hover:underline">Unblock</button></td>' +
      '</tr>'
    )).join('');
    if (selAll) selAll.checked = false;
    syncBulkBtn();
  }

  async function load() {
    countEl.textContent = 'Loading…';
    try { render((await api('')).contacts || []); }
    catch (e) { toast('Could not load: ' + e.message, 'error'); countEl.textContent = '—'; }
  }

  async function unblock(ids) {
    if (!ids.length) return;
    try {
      const res = await api('/unblock', 'POST', { ids });
      toast(ids.length === 1 ? 'Contact unblocked.' : (ids.length + ' contacts unblocked.'), 'success');
      if (res.meta_block_warning) toast("Unblocked here, but WhatsApp (Meta) didn't apply it: " + res.meta_block_warning, 'error');
      await load();
    } catch (e) { toast('Unblock failed: ' + e.message, 'error'); }
  }

  // Block a number
  $('[data-block-btn]')?.addEventListener('click', async () => {
    const input = $('[data-block-phone]');
    const phone = (input.value || '').trim();
    if (!phone) { toast('Enter a phone number.', 'error'); return; }
    try {
      const res = await api('/block', 'POST', { phone });
      toast('Number blocked.', 'success');
      if (res.meta_block_warning) toast("Blocked here, but WhatsApp (Meta) didn't apply it: " + res.meta_block_warning, 'error');
      input.value = '';
      await load();
    } catch (e) { toast('Block failed: ' + e.message, 'error'); }
  });

  // Row + bulk interactions (delegated)
  rowsEl.addEventListener('click', (e) => {
    const b = e.target.closest('[data-unblock-one]');
    if (b) { unblock([parseInt(b.dataset.unblockOne, 10)]); }
  });
  rowsEl.addEventListener('change', (e) => { if (e.target.matches('input[data-id]')) syncBulkBtn(); });
  selAll?.addEventListener('change', () => {
    rowsEl.querySelectorAll('input[data-id]').forEach((c) => { c.checked = selAll.checked; });
    syncBulkBtn();
  });
  unblkBtn?.addEventListener('click', () => unblock(selectedIds()));
  $('[data-refresh]')?.addEventListener('click', load);

  load();
}
