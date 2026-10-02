// Admin · Support Bot settings. Repopulates the AI Model dropdown from the
// canonical per-provider catalog (same list /admin/api-keys uses) whenever the
// admin changes the provider, so they pick a valid model instead of typing it.
export default function init() {
  const form = document.querySelector('form[data-sb-models]');
  if (!form) return;

  let catalog = {};
  try { catalog = JSON.parse(form.dataset.sbModels || '{}'); } catch (_) { catalog = {}; }

  const provider = form.querySelector('[data-sb-provider="ai"]');
  const model = form.querySelector('[data-sb-model="ai"]');
  if (!provider || !model) return;

  provider.addEventListener('change', () => {
    const list = catalog[provider.value] || [];
    model.innerHTML = '';
    if (!list.length) {
      model.appendChild(opt('', '— no models listed —', true));
      return;
    }
    list.forEach((m, i) => model.appendChild(opt(m, m, i === 0)));
  });

  function opt(value, label, selected) {
    const o = document.createElement('option');
    o.value = value; o.textContent = label;
    if (selected) o.selected = true;
    return o;
  }
}
