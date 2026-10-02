/**
 * OpenAI Ads — "Build with AI" for the campaign wizard. Loads the model list,
 * then on Generate posts the brief and fills the campaign/ad-group/ad/headline/
 * body fields. The operator can edit everything before submitting.
 */
export default function init() {
    const root = document.querySelector('[data-oaiads-ai]');
    const form = document.querySelector('[data-oaiads-form]');
    if (!root || !form) return;

    const modelSel = root.querySelector('[data-ai-model]');
    const btn      = root.querySelector('[data-ai-generate]');
    const statusEl = root.querySelector('[data-ai-status]');
    const csrf     = form.querySelector('input[name="_token"]')?.value || '';

    const setStatus = (msg, isError = false) => {
        if (!statusEl) return;
        statusEl.textContent = msg || '';
        statusEl.classList.toggle('text-[#A1431F]', isError);
        statusEl.classList.toggle('text-ink-500', !isError);
    };

    const setField = (name, value) => {
        if (value === undefined || value === null || value === '') return;
        const el = form.querySelector(`[name="${name}"]`);
        if (el) el.value = value;
    };

    // Load models.
    fetch(root.dataset.modelsUrl, { headers: { Accept: 'application/json' } })
        .then((r) => r.json())
        .then((d) => {
            const models = (d && d.models) || [];
            if (!models.length) {
                modelSel.innerHTML = '<option value="">No AI models available</option>';
                btn.disabled = true;
                setStatus('No AI provider is enabled yet.', true);
                return;
            }
            modelSel.innerHTML = models
                .map((m) => `<option value="${m.value}" data-provider="${m.provider}">${m.label}</option>`)
                .join('');
        })
        .catch(() => {
            modelSel.innerHTML = '<option value="">Could not load models</option>';
            btn.disabled = true;
        });

    btn.addEventListener('click', () => {
        const opt = modelSel.options[modelSel.selectedIndex];
        const model = modelSel.value;
        const provider = opt ? opt.dataset.provider : '';
        const business = root.querySelector('[data-ai-business]')?.value?.trim() || '';
        if (!model || !provider) { setStatus('Pick an AI model first.', true); return; }
        if (!business) { setStatus('Enter at least a business name.', true); return; }

        btn.disabled = true;
        setStatus('Generating…');

        fetch(root.dataset.generateUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({
                model,
                provider,
                business_name: business,
                product: root.querySelector('[data-ai-product]')?.value?.trim() || '',
                audience: root.querySelector('[data-ai-audience]')?.value?.trim() || '',
                tone: root.querySelector('[data-ai-tone]')?.value?.trim() || '',
                custom_prompt: root.querySelector('[data-ai-prompt]')?.value?.trim() || '',
            }),
        })
            .then((r) => r.json().then((d) => ({ ok: r.ok, d })))
            .then(({ ok, d }) => {
                if (!ok || !d.ok) {
                    setStatus(d.message || 'Generation failed.', true);
                    return;
                }
                const p = d.payload || {};
                setField('name', p.campaign_name);
                setField('adgroup_name', p.adgroup_name);
                setField('ad_name', p.ad_name);
                setField('title', p.headline);
                setField('body', p.body);
                setStatus('Draft filled in below — review and edit, then create.');
            })
            .catch(() => setStatus('Generation failed — try again.', true))
            .finally(() => { btn.disabled = false; });
    });
}
