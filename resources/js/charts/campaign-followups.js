// Campaign Follow-ups rule builder — shared by the campaign CREATE wizard and
// the single-page EDIT form. Builds the hidden #followups_json the controller's
// saveFollowups() reads. Rows clone #fu-row-tpl; picker + delay + window note
// adapt to the chosen event/action. No-op when the (plan-gated) markup is absent.
export function initFollowups() {
    const rowsWrap  = document.getElementById('fu-rows');
    const tpl       = document.getElementById('fu-row-tpl');
    const addBtn    = document.getElementById('fu-add');
    const jsonInput = document.getElementById('followups_json');
    if (!rowsWrap || !tpl || !jsonInput) return;

    let opts = { templates: [], flows: [], drips: [], tags: [], existing: [] };
    try { opts = JSON.parse(document.getElementById('fu-options')?.textContent || '{}'); } catch (_) {}

    const DELAYED = ['read_no_reply', 'delivered_no_read', 'sent_no_reply', 'not_delivered'];
    const WINDOW_CLOSED = ['clicked_link', 'read', 'read_no_reply', 'delivered_no_read', 'sent_no_reply', 'not_delivered'];
    // Each action's target picker → [options key, label].
    const REF_SOURCE = {
        send_template: ['templates', 'Template'],
        start_flow:    ['flows', 'Flow'],
        enroll_drip:   ['drips', 'Drip'],
        add_tag:       ['tags', 'Tag'],
        remove_tag:    ['tags', 'Tag'],
        assign_agent:  ['agents', 'Agent'],
    };
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    function fillRefPicker(row) {
        const action = row.querySelector('[data-fu-action]').value;
        const wrap   = row.querySelector('[data-fu-ref-wrap]');
        const sel    = row.querySelector('[data-fu-ref]');
        const label  = row.querySelector('[data-fu-ref-label]');
        const src    = REF_SOURCE[action];
        if (!src) { wrap.hidden = true; sel.innerHTML = ''; return; }
        wrap.hidden = false;
        label.textContent = src[1];
        const list = opts[src[0]] || [];
        const keep = sel.value;
        sel.innerHTML = list.length
            ? list.map((o) => `<option value="${o.id}">${esc(o.name || ('#' + o.id))}</option>`).join('')
            : '<option value="">— none available —</option>';
        if (keep && list.some((o) => String(o.id) === String(keep))) sel.value = keep;
    }

    function syncRowUi(row) {
        const event = row.querySelector('[data-fu-event]').value;
        row.querySelector('[data-fu-delay-wrap]').hidden = DELAYED.indexOf(event) === -1;
        const note = row.querySelector('[data-fu-window-note]');
        if (note) note.classList.toggle('hidden', WINDOW_CLOSED.indexOf(event) === -1);
        fillRefPicker(row);
        serialize();
    }

    function addRow(preset) {
        const node = tpl.content.firstElementChild.cloneNode(true);
        rowsWrap.appendChild(node);
        node.querySelector('[data-fu-event]').addEventListener('change', () => syncRowUi(node));
        node.querySelector('[data-fu-action]').addEventListener('change', () => { fillRefPicker(node); serialize(); });
        node.querySelectorAll('input, select').forEach((el) => el.addEventListener('change', serialize));
        node.querySelector('[data-fu-remove]').addEventListener('click', () => { node.remove(); serialize(); });
        if (preset) {
            node.querySelector('[data-fu-event]').value = preset.trigger_event || 'replied';
            node.querySelector('[data-fu-action]').value = preset.action_type || 'send_template';
            if (preset.delay_minutes != null) {
                const dv = node.querySelector('[data-fu-delay-value]');
                const du = node.querySelector('[data-fu-delay-unit]');
                const m = Number(preset.delay_minutes) || 0;
                if (m >= 1440 && m % 1440 === 0) { du.value = 'day'; dv.value = m / 1440; }
                else if (m >= 60 && m % 60 === 0) { du.value = 'hour'; dv.value = m / 60; }
                else { du.value = 'minute'; dv.value = m; }
            }
        }
        syncRowUi(node);
        if (preset && preset.action_ref_id) node.querySelector('[data-fu-ref]').value = String(preset.action_ref_id);
        return node;
    }

    function serialize() {
        const rules = [];
        rowsWrap.querySelectorAll('[data-fu-row]').forEach((row) => {
            const event  = row.querySelector('[data-fu-event]').value;
            const action = row.querySelector('[data-fu-action]').value;
            const rule   = { trigger_event: event, action_type: action };
            if (DELAYED.indexOf(event) !== -1) {
                rule.delay_value = Number(row.querySelector('[data-fu-delay-value]').value) || 0;
                rule.delay_unit  = row.querySelector('[data-fu-delay-unit]').value;
            }
            if (REF_SOURCE[action]) rule.action_ref_id = Number(row.querySelector('[data-fu-ref]').value) || 0;
            rules.push(rule);
        });
        jsonInput.value = JSON.stringify(rules);
    }

    addBtn?.addEventListener('click', () => addRow());
    (opts.existing || []).forEach((r) => addRow(r));
    serialize();
}
