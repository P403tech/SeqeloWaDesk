// Admin › Settings › Feature toggles — tabbed groups.
// Each group is a tab; clicking one shows its panel and hides the rest. All
// panels remain in the DOM (inside the form) so a Save still submits every
// toggle regardless of which tab is open.
export default function init() {
    const root = document.querySelector('[data-feat-tabs]');
    if (!root) return;

    const tabs = Array.from(root.querySelectorAll('[data-feat-tab]'));
    const panels = Array.from(root.querySelectorAll('[data-feat-panel]'));
    if (!tabs.length || !panels.length) return;

    function activate(id) {
        tabs.forEach((t) => {
            const on = t.dataset.featTab === id;
            t.classList.toggle('border-wa-deep', on);
            t.classList.toggle('text-wa-deep', on);
            t.classList.toggle('border-transparent', !on);
            t.classList.toggle('text-ink-600', !on);
        });
        panels.forEach((p) => {
            p.classList.toggle('hidden', p.dataset.featPanel !== id);
        });
    }

    tabs.forEach((t) => t.addEventListener('click', () => activate(t.dataset.featTab)));
    activate(tabs[0].dataset.featTab);
}
