// n8n connector page — copy buttons, select-all for the event grid, and a
// one-shot "Send test event" that fires a test payload at the saved n8n URL.
// Save + disconnect are normal form posts (disconnect uses the global
// data-confirm-form delegation), so no JS is needed for them here.
export default function init() {
    const toast = (m, kind = 'success') => (window.toast ? window.toast(m, kind) : null);
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // Generic copy buttons: data-copy="<element-id>".
    document.querySelectorAll('[data-copy]').forEach((btn) => {
        if (btn.__copyWired) return;
        btn.__copyWired = true;
        btn.addEventListener('click', async () => {
            const target = document.getElementById(btn.dataset.copy);
            if (!target) return;
            const value = (target.value || target.textContent || '').trim();
            if (!value) return;
            try {
                await navigator.clipboard.writeText(value);
                toast('Copied to clipboard.', 'success');
            } catch {
                toast('Clipboard blocked — select and copy manually.', 'info');
            }
        });
    });

    // Select-all mirrors the event checkboxes both ways.
    const selectAll = document.getElementById('n8n-select-all');
    const events = Array.from(document.querySelectorAll('.n8n-event'));
    const syncSelectAll = () => {
        if (!selectAll) return;
        selectAll.checked = events.length > 0 && events.every((c) => c.checked);
        selectAll.indeterminate = !selectAll.checked && events.some((c) => c.checked);
    };
    if (selectAll) {
        selectAll.addEventListener('change', () => {
            events.forEach((c) => (c.checked = selectAll.checked));
        });
        events.forEach((c) => c.addEventListener('change', syncSelectAll));
        syncSelectAll();
    }

    // Send test event.
    const testBtn = document.getElementById('n8n-test');
    const result = document.getElementById('n8n-test-result');
    if (testBtn && !testBtn.__wired) {
        testBtn.__wired = true;
        testBtn.addEventListener('click', async () => {
            const original = testBtn.innerHTML;
            testBtn.disabled = true;
            testBtn.textContent = 'Sending...';
            if (result) result.textContent = '';
            try {
                const res = await fetch('/n8n/test', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf(),
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) {
                    const msg = data.message || 'Test failed.';
                    if (result) result.textContent = msg;
                    toast(msg, 'error');
                } else if (data.isOk) {
                    const msg = `Delivered — HTTP ${data.statusCode} in ${data.latencyMs}ms`;
                    if (result) {
                        result.textContent = msg;
                        result.className = 'text-[11.5px] font-mono text-wa-deep';
                    }
                    toast('Test event delivered to n8n.', 'success');
                } else {
                    const msg = `n8n responded HTTP ${data.statusCode ?? '—'} (${data.latencyMs ?? 0}ms)`;
                    if (result) {
                        result.textContent = msg;
                        result.className = 'text-[11.5px] font-mono text-accent-amber';
                    }
                    toast('n8n did not accept the test event — check the workflow is active.', 'info');
                }
            } catch (e) {
                if (result) result.textContent = 'Network error.';
                toast('Could not reach the server.', 'error');
            } finally {
                testBtn.disabled = false;
                testBtn.innerHTML = original;
            }
        });
    }

    // How-to-use guide modal.
    const help = document.getElementById('n8n-help');
    const openHelp = document.getElementById('n8n-help-open');
    if (help && openHelp) {
        const show = () => help.classList.remove('hidden');
        const hide = () => help.classList.add('hidden');
        openHelp.addEventListener('click', show);
        help.querySelectorAll('[data-n8n-help-close]').forEach((el) => el.addEventListener('click', hide));
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !help.classList.contains('hidden')) hide();
        });
    }
}
