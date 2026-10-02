// AI SDR management page — campaigns + lead-scoring rules.
// Renders from the #sdr-seed payload and does CRUD via the JSON endpoints;
// a successful mutation reloads so the server stays the source of truth.

export default function initSdr() {
    const root = document.getElementById('sdr-root');
    const seedEl = document.getElementById('sdr-seed');
    if (!root || !seedEl) return;

    let seed;
    try { seed = JSON.parse(seedEl.textContent || '{}'); } catch (_) { return; }
    const flows   = seed.flows   || [];
    const teams   = seed.teams   || [];
    const signals = seed.signals || [];
    const urls    = seed.urls    || {};

    const $ = (s, c = document) => c.querySelector(s);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    const csrf = () => {
        const m = document.querySelector('meta[name="csrf-token"]');
        if (m) return m.getAttribute('content');
        const c = document.cookie.split('; ').find((r) => r.startsWith('XSRF-TOKEN='));
        return c ? decodeURIComponent(c.split('=')[1]) : '';
    };
    const humanize = (s) => String(s).replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());

    async function send(url, method, body) {
        const r = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: body ? JSON.stringify(body) : undefined,
        });
        if (!r.ok) {
            let msg = 'Save failed';
            try { const j = await r.json(); msg = j.message || (j.errors && Object.values(j.errors)[0][0]) || msg; } catch (_) {}
            throw new Error(msg);
        }
        return r.json().catch(() => ({}));
    }

    // ── Render campaigns ──────────────────────────────────────────────
    const flowName = (id) => (flows.find((f) => f.id === id) || {}).name || '—';
    const teamName = (id) => (teams.find((t) => t.id === id) || {}).name || '—';

    function renderCampaigns() {
        const wrap = $('#sdr-campaigns');
        const empty = $('#sdr-campaigns-empty');
        const list = seed.campaigns || [];
        empty.hidden = list.length > 0;
        wrap.innerHTML = list.map((c) => {
            const s = c.stats || {};
            const pill = (n, label, cls) => `<div class="text-center"><div class="text-[15px] font-semibold ${cls}">${n || 0}</div><div class="text-[9.5px] font-mono uppercase tracking-wide text-ink-500">${label}</div></div>`;
            return `<div class="border border-paper-200 rounded-2xl bg-paper-0 p-4">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="font-semibold text-[14px]">${esc(c.name)}</div>
                        <div class="text-[11px] text-ink-500 mt-0.5">${esc(flowName(c.flow_id))} · ${c.route_score != null ? 'route @ ' + c.route_score : 'no route'}</div>
                    </div>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold ${c.is_active ? 'bg-wa-mint text-wa-deep' : 'bg-paper-100 text-ink-500'}">
                        <span class="w-1.5 h-1.5 rounded-full ${c.is_active ? 'bg-wa-green' : 'bg-ink-300'}"></span>${c.is_active ? 'Active' : 'Off'}
                    </span>
                </div>
                <div class="grid grid-cols-4 gap-1 mt-3 py-2.5 border-y border-paper-200">
                    ${pill(s.active, 'Active', 'text-wa-deep')}
                    ${pill(s.routed, 'Routed', 'text-ink-700')}
                    ${pill(s.converted, 'Won', 'text-wa-green')}
                    ${pill(s.stopped, 'Stopped', 'text-ink-500')}
                </div>
                <div class="flex items-center justify-end gap-3 mt-2.5 text-[11.5px] font-semibold">
                    <button data-edit-camp="${c.id}" class="text-wa-deep hover:underline">Edit</button>
                    <button data-del-camp="${c.id}" class="text-accent-coral hover:underline">Delete</button>
                </div>
            </div>`;
        }).join('');
    }

    function renderRules() {
        const body = $('#sdr-rules');
        const empty = $('#sdr-rules-empty');
        const list = seed.rules || [];
        empty.hidden = list.length > 0;
        body.innerHTML = list.map((r) => `<tr class="border-b border-paper-100 last:border-0">
            <td class="px-4 py-2.5 font-medium">${esc(r.name)}</td>
            <td class="px-4 py-2.5"><span class="font-mono text-[11px] text-ink-600">${esc(humanize(r.signal))}</span></td>
            <td class="px-4 py-2.5 text-right font-semibold ${r.points < 0 ? 'text-accent-coral' : 'text-wa-deep'}">${r.points > 0 ? '+' : ''}${r.points}</td>
            <td class="px-4 py-2.5 text-right text-ink-500">${r.fired_count || 0}</td>
            <td class="px-4 py-2.5 text-right">${r.is_active ? '<span class="text-wa-green">●</span>' : '<span class="text-ink-300">○</span>'}</td>
            <td class="px-4 py-2.5 text-right whitespace-nowrap">
                <button data-edit-rule="${r.id}" class="text-wa-deep hover:underline text-[11.5px] font-semibold mr-3">Edit</button>
                <button data-del-rule="${r.id}" class="text-accent-coral hover:underline text-[11.5px] font-semibold">Delete</button>
            </td>
        </tr>`).join('');
    }

    // ── Populate selects ──────────────────────────────────────────────
    $('[data-camp-flow]').innerHTML = '<option value="">— none —</option>' + flows.map((f) => `<option value="${f.id}">${esc(f.name)}</option>`).join('');
    $('[data-camp-team]').innerHTML = '<option value="">— none —</option>' + teams.map((t) => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
    $('[data-rule-signal]').innerHTML = signals.map((s) => `<option value="${s}">${esc(humanize(s))}</option>`).join('');

    // ── Campaign modal ────────────────────────────────────────────────
    const campModal = $('#sdr-camp-modal');
    const openCamp = (c) => {
        $('[data-camp-title]').textContent = c ? 'Edit campaign' : 'New campaign';
        $('[data-camp-id]').value = c ? c.id : '';
        $('[data-camp-name]').value = c ? c.name : '';
        $('[data-camp-flow]').value = c && c.flow_id ? c.flow_id : '';
        $('[data-camp-min]').value = c ? (c.enroll_min_score ?? 0) : 0;
        $('[data-camp-route]').value = c && c.route_score != null ? c.route_score : '';
        $('[data-camp-team]').value = c && c.route_team_id ? c.route_team_id : '';
        $('[data-camp-active]').checked = c ? !!c.is_active : true;
        $('[data-camp-reply]').checked = c ? !!c.stop_on_reply : true;
        $('[data-camp-convert]').checked = c ? !!c.stop_on_convert : true;
        campModal.hidden = false; campModal.classList.remove('hidden');
    };
    const closeCamp = () => { campModal.hidden = true; campModal.classList.add('hidden'); };

    root.querySelector('[data-sdr-new-campaign]').addEventListener('click', () => openCamp(null));
    campModal.querySelector('[data-camp-close]').addEventListener('click', closeCamp);
    campModal.querySelector('[data-camp-cancel]').addEventListener('click', closeCamp);
    campModal.querySelector('[data-camp-save]').addEventListener('click', async () => {
        const id = $('[data-camp-id]').value;
        const routeVal = $('[data-camp-route]').value;
        const payload = {
            name: $('[data-camp-name]').value.trim(),
            is_active: $('[data-camp-active]').checked,
            flow_id: $('[data-camp-flow]').value || null,
            enroll_min_score: parseInt($('[data-camp-min]').value, 10) || 0,
            route_score: routeVal === '' ? null : (parseInt(routeVal, 10) || 0),
            route_team_id: $('[data-camp-team]').value || null,
            stop_on_reply: $('[data-camp-reply]').checked,
            stop_on_convert: $('[data-camp-convert]').checked,
        };
        if (!payload.name) { $('[data-camp-name]').focus(); return; }
        try {
            await send(id ? `${urls.campaign}/${id}` : urls.campaign, id ? 'PUT' : 'POST', payload);
            location.reload();
        } catch (e) { alert(e.message); }
    });

    // ── Rule modal ────────────────────────────────────────────────────
    const ruleModal = $('#sdr-rule-modal');
    const syncKw = () => { $('[data-rule-kw-wrap]').hidden = $('[data-rule-signal]').value !== 'keyword_match'; };
    $('[data-rule-signal]').addEventListener('change', syncKw);
    const openRule = (r) => {
        $('[data-rule-title]').textContent = r ? 'Edit rule' : 'New rule';
        $('[data-rule-id]').value = r ? r.id : '';
        $('[data-rule-name]').value = r ? r.name : '';
        $('[data-rule-signal]').value = r ? r.signal : (signals[0] || '');
        $('[data-rule-points]').value = r ? r.points : 10;
        $('[data-rule-active]').checked = r ? !!r.is_active : true;
        let kw = '';
        if (r && Array.isArray(r.conditions) && r.conditions[0] && r.conditions[0][0] === 'text') kw = r.conditions[0][2] || '';
        $('[data-rule-kw]').value = kw;
        syncKw();
        ruleModal.hidden = false; ruleModal.classList.remove('hidden');
    };
    const closeRule = () => { ruleModal.hidden = true; ruleModal.classList.add('hidden'); };

    root.querySelector('[data-sdr-new-rule]').addEventListener('click', () => openRule(null));
    ruleModal.querySelector('[data-rule-close]').addEventListener('click', closeRule);
    ruleModal.querySelector('[data-rule-cancel]').addEventListener('click', closeRule);
    ruleModal.querySelector('[data-rule-save]').addEventListener('click', async () => {
        const id = $('[data-rule-id]').value;
        const signal = $('[data-rule-signal]').value;
        const kw = $('[data-rule-kw]').value.trim();
        const payload = {
            name: $('[data-rule-name]').value.trim(),
            signal,
            points: parseInt($('[data-rule-points]').value, 10) || 0,
            is_active: $('[data-rule-active]').checked,
            conditions: (signal === 'keyword_match' && kw) ? [['text', 'contains', kw]] : null,
        };
        if (!payload.name) { $('[data-rule-name]').focus(); return; }
        try {
            await send(id ? `${urls.rule}/${id}` : urls.rule, id ? 'PUT' : 'POST', payload);
            location.reload();
        } catch (e) { alert(e.message); }
    });

    // ── Delegated edit/delete ─────────────────────────────────────────
    document.addEventListener('click', async (e) => {
        const ec = e.target.closest('[data-edit-camp]');
        const dc = e.target.closest('[data-del-camp]');
        const er = e.target.closest('[data-edit-rule]');
        const dr = e.target.closest('[data-del-rule]');
        if (ec) { openCamp((seed.campaigns || []).find((c) => c.id == ec.dataset.editCamp)); }
        else if (er) { openRule((seed.rules || []).find((r) => r.id == er.dataset.editRule)); }
        else if (dc && confirm('Delete this campaign? Enrolled leads stop being nurtured.')) {
            try { await send(`${urls.campaign}/${dc.dataset.delCamp}`, 'DELETE'); location.reload(); } catch (x) { alert(x.message); }
        } else if (dr && confirm('Delete this scoring rule?')) {
            try { await send(`${urls.rule}/${dr.dataset.delRule}`, 'DELETE'); location.reload(); } catch (x) { alert(x.message); }
        }
    });

    renderCampaigns();
    renderRules();
}
