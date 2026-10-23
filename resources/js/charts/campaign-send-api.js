// "Send marketing templates through" choice on the campaign builder (create +
// edit). Shown only when the picked sender is a WABA number (`waba:<id>`), and
// asks the server — which asks Meta — whether that number is onboarded for the
// Marketing Messages API, so the operator knows what will actually happen.
export function initSendApi() {
    const box = document.querySelector('[data-send-api]');
    const sel = document.getElementById('device');
    if (!box || !sel) return;
    const statusEl = box.querySelector('[data-send-api-status]');
    const url = box.dataset.statusUrl;
    const lookups = {};

    const render = (status) => {
        if (!statusEl) return;
        const ok = status === 'ONBOARDED';
        statusEl.textContent = ok ? box.dataset.msgOnboarded
            : status === 'ELIGIBLE' ? box.dataset.msgEligible
            : box.dataset.msgOther;
        statusEl.classList.toggle('text-wa-deep', ok);
        statusEl.classList.toggle('text-ink-500', !ok);
    };

    const sync = async () => {
        const [engine, id] = String(sel.value || '').split(':');
        const isWaba = engine === 'waba' && !!id;
        box.classList.toggle('hidden', !isWaba);
        if (!isWaba || !url) return;
        if (!lookups[id]) {
            if (statusEl) statusEl.textContent = box.dataset.msgChecking;
            lookups[id] = fetch(`${url}?account=${encodeURIComponent(id)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            }).then((r) => r.json()).then((j) => j.status || 'UNKNOWN').catch(() => 'UNKNOWN');
        }
        const status = await lookups[id];
        // The operator may have switched sender while Meta answered.
        if (String(sel.value || '').split(':')[1] === id) render(status);
    };

    sel.addEventListener('change', sync);
    sync();
}
