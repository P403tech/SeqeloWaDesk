// AI Agents list — card rows with icon-button actions.
export default function init() {
  const csrf  = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const toast = (m, kind = 'success') => (window.toast ? window.toast(m, kind) : null);
  const confirmDialog = (opts) => {
    if (window.confirmDialog) return window.confirmDialog(opts);
    if (window.confirm(opts.message || 'Are you sure?')) opts.onConfirm?.();
  };

  document.querySelectorAll('[data-delete]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.id;
      const name = btn.dataset.name || 'this agent';
      if (!id) return;
      confirmDialog({
        eyebrow: 'Delete agent',
        title: `Delete "${name}"?`,
        message: 'The agent and its training sources will be removed. Widgets using it will stop replying with AI until you pick a different agent.',
        confirmText: 'Delete agent',
        cancelText: 'Keep',
        tone: 'danger',
        onConfirm: async () => {
          try {
            const res = await fetch(`/ai-training/api/assistant/${id}`, {
              method: 'DELETE',
              headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error('http ' + res.status);
            btn.closest('.ait-row')?.remove();
            toast('Agent deleted.', 'success');
          } catch (e) {
            toast('Delete failed — ' + (e?.message || 'network error'), 'error');
          }
        },
      });
    });
  });

  document.querySelectorAll('[data-pause]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.id;
      const name = btn.dataset.name || 'this agent';
      const paused = (btn.dataset.status || 'active') === 'paused';
      if (!id) return;
      const next = paused ? 'active' : 'paused';
      confirmDialog({
        eyebrow: paused ? 'Resume agent' : 'Pause agent',
        title: paused ? `Resume “${name}”?` : `Pause “${name}”?`,
        message: paused
          ? 'The agent will auto-reply again on its connected channels (WhatsApp, Facebook, Instagram, TikTok).'
          : 'The agent will stop auto-replying. Channels stay connected and humans can still reply in the inbox. This does not pause Flows.',
        confirmText: paused ? 'Resume' : 'Pause',
        cancelText: 'Keep as is',
        tone: paused ? 'default' : 'danger',
        onConfirm: async () => {
          try {
            const res = await fetch(`/ai-training/${id}/status`, {
              method: 'POST',
              headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
              },
              body: JSON.stringify({ status: next }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || json.ok === false) throw new Error('http ' + res.status);
            const status = json.status || next;
            const row = btn.closest('.ait-row');
            if (row) row.dataset.status = status;
            btn.dataset.status = status;
            const live = status === 'active';
            btn.textContent = live ? 'Pause' : 'Resume';
            btn.classList.toggle('border-wa-deep', !live);
            btn.classList.toggle('bg-wa-deep', !live);
            btn.classList.toggle('text-paper-0', !live);
            btn.classList.toggle('hover:bg-wa-teal', !live);
            btn.classList.toggle('border-paper-200', live);
            btn.classList.toggle('bg-paper-0', live);
            btn.classList.toggle('text-ink-800', live);
            btn.classList.toggle('hover:bg-paper-50', live);
            const pill = row?.querySelector('[data-status-pill]');
            if (pill) {
              pill.className = 'inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md font-mono text-[9.5px] uppercase tracking-[0.14em] ' +
                (live ? 'bg-wa-mint text-wa-deep' : 'bg-paper-100 text-ink-600');
              pill.innerHTML = `<span class="w-1.5 h-1.5 rounded-full ${live ? 'bg-wa-green' : 'bg-ink-400'}"></span>${live ? 'Active' : 'Paused'}`;
            }
            toast(live ? 'Agent is live again.' : 'Agent paused — inbox auto-replies stopped.', 'success');
            applyFilters();
          } catch (e) {
            toast('Could not update agent — ' + (e?.message || 'network error'), 'error');
          }
        },
      });
    });
  });

  let statusFilter = 'all';
  const applyFilters = () => {
    const q = (document.getElementById('ait-search')?.value || '').trim().toLowerCase();
    document.querySelectorAll('.ait-row').forEach((row) => {
      const hay = row.dataset.searchHaystack || '';
      const st = row.dataset.status || 'active';
      const matchQ = q === '' || hay.includes(q);
      const matchS = statusFilter === 'all' || st === statusFilter;
      row.classList.toggle('hidden', !(matchQ && matchS));
    });
  };

  const search = document.getElementById('ait-search');
  if (search) search.addEventListener('input', applyFilters);

  document.querySelectorAll('[data-status-tab]').forEach((btn) => {
    btn.addEventListener('click', () => {
      statusFilter = btn.dataset.statusTab || 'all';
      document.querySelectorAll('[data-status-tab]').forEach((b) => {
        const on = b === btn;
        b.classList.toggle('bg-wa-deep', on);
        b.classList.toggle('text-paper-0', on);
        b.classList.toggle('text-ink-600', !on);
        b.classList.toggle('hover:bg-paper-100', !on);
      });
      applyFilters();
    });
  });
}
