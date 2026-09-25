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
