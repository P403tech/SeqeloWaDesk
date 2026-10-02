/**
 * Drip campaign step builder.
 *
 * Steps are a dynamic list, so they can't be plain Blade inputs. This renders
 * rows from the form's data-steps payload, keeps the numbering and the
 * "what this means" hint in sync, and serialises everything into
 * steps[i][field] names right before submit — which is exactly the shape
 * DripCampaignsController::syncSteps() reads.
 */

export default function () {
    const form = document.getElementById('drip-form');
    if (!form) return;

    const list     = document.getElementById('drip-steps');
    const empty    = document.getElementById('drip-empty');
    const tpl      = document.getElementById('drip-step-template');
    const addBtn   = document.getElementById('drip-add-step');

    const t = (s) => (window.t ? window.t(s) : s);

    /**
     * Renumber, and restate each wait in plain language. A builder that only
     * shows "1 / day" leaves the operator guessing whether that is one day
     * after enrolment or after the previous message — it is the latter.
     */
    const refresh = () => {
        const rows = [...list.querySelectorAll('[data-step]')];

        rows.forEach((row, i) => {
            row.querySelector('[data-step-number]').textContent = i + 1;

            const amount = parseInt(row.querySelector('[data-delay-amount]').value, 10) || 0;
            const unit   = row.querySelector('[data-delay-unit]').value;
            const hint   = row.querySelector('[data-delay-hint]');

            if (amount <= 0) {
                hint.textContent = i === 0 ? t('— sent as soon as they are enrolled') : t('— sent right after the previous step');
            } else {
                const noun = amount === 1 ? unit : `${unit}s`;
                hint.textContent = i === 0
                    ? `${t('after enrolment')} (${amount} ${noun})`
                    : `${t('after step')} ${i} (${amount} ${noun})`;
            }
        });

        empty.classList.toggle('hidden', rows.length > 0);
    };

    // How many positional {{1}},{{2}}… slots each template declares.
    let paramCounts = {};
    try { paramCounts = JSON.parse(form.dataset.paramCounts || '{}') || {}; } catch (e) { /* none */ }

    // What a slot can be filled with. Kept deliberately small — these are the
    // contact fields DripRunner::contactField() knows how to resolve, so the
    // picker can never offer something the sender would send as empty.
    const FIELDS = [
        ['name',       'Full name'],
        ['first_name', 'First name'],
        ['mobile',     'Mobile'],
        ['email',      'Email'],
    ];

    /**
     * Show exactly as many pickers as the chosen template needs.
     *
     * Meta rejects a send whose parameter count differs from the approved
     * template, and a drip failure surfaces days later — so the count comes
     * from the template itself, never from a guess.
     */
    const renderVarMap = (row, saved = []) => {
        const box = row.querySelector('[data-varmap]');
        const note = row.querySelector('[data-template-note]');
        const id  = row.querySelector('[data-template]').value;
        const n   = paramCounts[id] || 0;

        if (note) note.hidden = !id;

        if (!id || n < 1) {
            box.classList.add('hidden');
            box.innerHTML = '';
            return;
        }

        const options = (sel) => FIELDS
            .map(([v, l]) => `<option value="${v}" ${v === sel ? 'selected' : ''}>${t(l)}</option>`)
            .join('');

        let slots = '';
        for (let i = 0; i < n; i++) {
            slots += `<label class="block">
                <span class="font-mono text-[9.5px] uppercase text-ink-500 tracking-wide">${t('Slot')} ${i + 1}</span>
                <select data-var class="mt-1 w-full px-2 py-1.5 border border-paper-200 rounded-lg bg-paper-0 text-[12px] focus:outline-none focus:border-wa-deep">
                    ${options(saved[i] || 'name')}
                </select>
            </label>`;
        }

        box.innerHTML = `<div class="rounded-xl border border-paper-200 bg-paper-50/60 p-3">
            <div class="font-mono text-[9.5px] uppercase text-ink-500 tracking-wide mb-2">${t('Fill the template variables')}</div>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">${slots}</div>
        </div>`;
        box.classList.remove('hidden');
    };

    const addStep = (data = {}) => {
        const node = tpl.content.firstElementChild.cloneNode(true);

        node.querySelector('[data-delay-amount]').value = data.delay_amount ?? 0;
        node.querySelector('[data-delay-unit]').value   = data.delay_unit ?? 'hour';
        node.querySelector('[data-body]').value         = data.body ?? '';
        if (data.template_id) node.querySelector('[data-template]').value = data.template_id;

        renderVarMap(node, data.var_map || []);
        node.querySelector('[data-template]').addEventListener('change', () => renderVarMap(node));

        // Test send. Uses the SAVED step, so unsaved edits are not what gets
        // tested — saying so plainly beats sending the old copy silently.
        node.querySelector('[data-test-step]')?.addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            const url = form.dataset.testUrl;

            if (!url) {
                alert(t('Save the campaign first, then test a step.'));
                return;
            }

            const rows = [...list.querySelectorAll('[data-step]')];
            const position = rows.indexOf(node) + 1;

            const to = prompt(t('Send step') + ' ' + position + ' ' + t('to which number? (with country code)'));
            if (!to) return;

            const original = btn.innerHTML;
            btn.disabled = true;
            btn.textContent = t('Sending…');

            try {
                const r = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ position, to }),
                });
                const d = await r.json();
                alert(d.message || (d.ok ? t('Test sent.') : t('Send failed.')));
            } catch (e) {
                alert(t('Test request failed.'));
            }

            btn.disabled = false;
            btn.innerHTML = original;
        });

        node.querySelector('[data-remove-step]').addEventListener('click', () => {
            node.remove();
            refresh();
        });
        node.querySelector('[data-delay-amount]').addEventListener('input', refresh);
        node.querySelector('[data-delay-unit]').addEventListener('change', refresh);

        list.appendChild(node);
        refresh();
    };

    addBtn?.addEventListener('click', () => addStep());

    // Rehydrate saved steps.
    let saved = [];
    try { saved = JSON.parse(form.dataset.steps || '[]') || []; } catch (e) { /* start empty */ }
    saved.forEach(addStep);
    refresh();

    /**
     * Serialise on submit. Hidden inputs are built fresh each time so a step
     * removed after a failed validation pass can't leave a stale index behind.
     */
    form.addEventListener('submit', () => {
        form.querySelectorAll('[data-step-input]').forEach((el) => el.remove());

        [...list.querySelectorAll('[data-step]')].forEach((row, i) => {
            const put = (field, value) => {
                const input = document.createElement('input');
                input.type  = 'hidden';
                input.name  = `steps[${i}][${field}]`;
                input.value = value ?? '';
                input.setAttribute('data-step-input', '');
                form.appendChild(input);
            };

            put('delay_amount', row.querySelector('[data-delay-amount]').value);
            put('delay_unit',   row.querySelector('[data-delay-unit]').value);
            put('body',         row.querySelector('[data-body]').value);
            put('template_id',  row.querySelector('[data-template]').value);

            // One var_map[] entry per slot, in slot order — that order IS the
            // positional mapping, so it must follow the DOM exactly.
            [...row.querySelectorAll('[data-var]')].forEach((sel) => {
                const input = document.createElement('input');
                input.type  = 'hidden';
                input.name  = `steps[${i}][var_map][]`;
                input.value = sel.value;
                input.setAttribute('data-step-input', '');
                form.appendChild(input);
            });
        });
    });

    // The tag/group box only means something for those two triggers.
    const trigger = document.getElementById('drip-trigger');
    const valueBox = document.getElementById('drip-trigger-value-wrap');
    const syncTrigger = () => {
        const needsValue = ['tag_added', 'group_added'].includes(trigger.value);
        valueBox.classList.toggle('hidden', !needsValue);
    };
    trigger?.addEventListener('change', syncTrigger);
    if (trigger) syncTrigger();

    // The goal's value box only means something for a tag goal — booking and
    // ordering are events, not named things.
    const goal = document.getElementById('drip-goal');
    const goalValue = document.getElementById('drip-goal-value');
    const syncGoal = () => {
        if (goalValue) goalValue.classList.toggle('hidden', goal.value !== 'tag_added');
    };
    goal?.addEventListener('change', syncGoal);
    if (goal) syncGoal();
}
