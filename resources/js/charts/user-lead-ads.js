/**
 * /lead-ads — Meta Lead Ads form settings + lead list.
 *
 * Two jobs:
 *  1. Keep the stage picker honest: only stages that belong to the chosen
 *     pipeline stay visible, and a stale selection is cleared rather than
 *     silently posted (the controller would reject it anyway, but the operator
 *     should see it go).
 *  2. Retry a lead in place. The answers are already stored server-side, so a
 *     retry is a re-route, not a re-fetch — no Graph call, no page reload.
 */
export default function init() {
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    // The user layout always defines window.t (see partials/js-i18n); the guard
    // is only for the English case where no map is pushed.
    const t = (s) => (window.t ? window.t(s) : s);

    // ── Pipeline → stage ──
    const pipeline = document.querySelector('[data-fbl-pipeline]');
    const stage = document.querySelector('[data-fbl-stage]');

    function syncStages() {
        if (!pipeline || !stage) return;
        const want = pipeline.value; // '' = default pipeline, stages unknown until saved
        let clearedSelection = false;

        Array.from(stage.options).forEach((opt) => {
            if (!opt.dataset.pipeline) return; // the "First stage" placeholder
            const show = want !== '' && opt.dataset.pipeline === want;
            opt.hidden = !show;
            opt.disabled = !show;
            if (!show && opt.selected) {
                opt.selected = false;
                clearedSelection = true;
            }
        });

        if (clearedSelection) stage.value = '';
    }

    if (pipeline) {
        pipeline.addEventListener('change', syncStages);
        syncStages();
    }

    // ── Owner picker follows the assignment strategy ──
    // "Share out in turn" rotates through the team, so a fixed owner is only a
    // fallback there — the label says as much rather than hiding the field.
    const strategy = document.querySelector('[data-fbl-strategy]');
    const ownerSel = document.querySelector('select[name="owner_user_id"]');

    function syncOwnerHint() {
        if (!strategy || !ownerSel) return;
        const rotating = strategy.value === 'round_robin';
        const first = ownerSel.options[0];
        if (first && first.value === '') {
            first.textContent = rotating ? t('Whoever is next in turn') : t('Nobody yet');
        }
    }

    if (strategy) {
        strategy.addEventListener('change', syncOwnerHint);
        syncOwnerHint();
    }

    // ── Retry one lead ──
    document.querySelectorAll('[data-fbl-retry]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.fblRetry;
            if (!id) return;

            const original = btn.textContent;
            btn.disabled = true;
            btn.textContent = t('Working…');

            try {
                const r = await fetch(`/lead-ads/${id}/retry`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                const d = await r.json().catch(() => ({}));

                if (r.ok && d.ok) {
                    // The row's contact/deal links now exist, so the honest way
                    // to show the new state is a reload rather than patching a
                    // half-updated row.
                    if (window.toast) window.toast(t('Lead added to your pipeline.'));
                    window.location.reload();
                    return;
                }

                if (window.toast) window.toast(d.error || t('Could not add this lead.'), 'error');
            } catch (e) {
                if (window.toast) window.toast(t('Could not reach the server.'), 'error');
            }

            btn.disabled = false;
            btn.textContent = original;
        });
    });
}
